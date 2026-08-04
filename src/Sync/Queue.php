<?php
/**
 * The pending-changes queue.
 *
 * One row per object, not one per event. The unique key on
 * (object_type, object_id) means a newer event **replaces** the pending one, so
 * a bulk edit of 500 posts produces 500 rows rather than the several thousand
 * hook firings that generated them.
 *
 * All datetimes here are UTC. MySQL's NOW() returns session-local time, which
 * would silently mis-compare against the UTC values WordPress writes, so every
 * comparison uses UTC_TIMESTAMP() and every PHP-side value uses gmdate().
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

use WPSupabaseSync\Installer;

defined( 'ABSPATH' ) || exit;

final class Queue {

	public const ACTION_UPSERT = 'upsert';
	public const ACTION_DELETE = 'delete';

	public const STATUS_PENDING = 'pending';
	public const STATUS_DEAD    = 'dead';

	public const OBJECT_POST = 'post';

	/**
	 * Attempts before a row is dead-lettered.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * A claimed row older than this is assumed abandoned by a crashed worker and
	 * is returned to the pool.
	 */
	private const RECLAIM_AFTER_SECONDS = 600;

	/**
	 * Longest a retry is ever deferred, regardless of attempt count.
	 */
	private const MAX_BACKOFF_SECONDS = 3600;

	/**
	 * Queue a change, replacing any pending change for the same object.
	 *
	 * @param string $action One of the ACTION_ constants.
	 */
	public function enqueue( int $object_id, string $action, string $object_type = self::OBJECT_POST ): void {
		global $wpdb;

		$table = Installer::queue_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		/*
		 * ON DUPLICATE KEY UPDATE is what makes this idempotent and coalescing.
		 * The reset of attempts, last_error and claim_token matters: a fresh
		 * event is new work, so it should not inherit the backoff or the
		 * dead-letter status of whatever failed before it, and clearing the
		 * token releases it from any in-flight batch (see complete()).
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i
					( object_type, object_id, action, status, attempts, last_error, available_at, claimed_at, claim_token, created_at )
				VALUES ( %s, %d, %s, %s, 0, NULL, %s, NULL, '', %s )
				ON DUPLICATE KEY UPDATE
					action = VALUES(action),
					status = VALUES(status),
					attempts = 0,
					last_error = NULL,
					available_at = VALUES(available_at),
					claimed_at = NULL,
					claim_token = ''",
				$table,
				$object_type,
				$object_id,
				$action,
				self::STATUS_PENDING,
				$now,
				$now
			)
		);
	}

	/**
	 * Claim up to $limit rows for processing.
	 *
	 * Two statements, not one: the UPDATE stamps a token that is unique to this
	 * call, then the SELECT reads back exactly the rows this call won. Selecting
	 * on `claimed_at` alone would be a race — two overlapping cron runs can stamp
	 * the same second and each would then read the other's rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function claim( int $limit ): array {
		global $wpdb;

		$this->reclaim_abandoned();

		$table = Installer::queue_table();
		$limit = max( 1, $limit );
		$token = $this->new_token();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET claimed_at = UTC_TIMESTAMP(), claim_token = %s
				WHERE status = %s
					AND claimed_at IS NULL
					AND available_at <= UTC_TIMESTAMP()
				ORDER BY available_at ASC, id ASC
				LIMIT %d',
				$table,
				$token,
				self::STATUS_PENDING,
				$limit
			)
		);

		if ( ! $claimed ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE claim_token = %s ORDER BY id ASC', $table, $token ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove finished rows.
	 *
	 * Scoped by claim token so a row that was re-queued while this batch was in
	 * flight survives. Without that scope, an edit made during a slow sync would
	 * be silently discarded — the row would be deleted as "done" even though it
	 * now described newer, unsent work.
	 *
	 * @param int[] $ids Queue row IDs.
	 * @return int Rows removed.
	 */
	public function complete( array $ids, string $claim_token ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Installer::queue_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		/*
		 * Two suppressions, both about how this IN () list is built:
		 *
		 * - InterpolatedNotPrepared sees {$placeholders}, which is not user data:
		 *   it is a string of literal %d markers, one per element of $ids, and
		 *   every $id is cast with intval above.
		 * - ReplacementsWrongNumber counts one argument because the replacements
		 *   are passed as a single array. That is documented $wpdb->prepare()
		 *   behaviour — an array in the second position is the full argument list.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE claim_token = %s AND id IN ( {$placeholders} )",
				array_merge( array( $table, $claim_token ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Record a failure and schedule the retry, or dead-letter the row.
	 *
	 * @param int[]  $ids       Queue row IDs.
	 * @param string $error     Already-translated, already-redacted message.
	 * @param bool   $permanent True when retrying cannot possibly help.
	 */
	public function fail( array $ids, string $claim_token, string $error, bool $permanent = false ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$table = Installer::queue_table();

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT attempts FROM %i WHERE id = %d AND claim_token = %s', $table, $id, $claim_token ),
				ARRAY_A
			);

			if ( null === $row ) {
				// Re-queued mid-flight, so this failure describes stale work.
				continue;
			}

			$attempts = (int) $row['attempts'] + 1;
			$dead     = $permanent || $attempts >= self::MAX_ATTEMPTS;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'attempts'     => $attempts,
					'last_error'   => $error,
					'status'       => $dead ? self::STATUS_DEAD : self::STATUS_PENDING,
					'available_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff_seconds( $attempts ) ),
					'claimed_at'   => null,
					'claim_token'  => '',
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}//end foreach
	}

	/**
	 * Exponential backoff, capped.
	 *
	 * 2^attempts minutes: 2, 4, 8, 16, 32, 64… capped at one hour. Retrying a
	 * struggling database every minute forever is how a small outage becomes a
	 * large one.
	 */
	public static function backoff_seconds( int $attempts ): int {
		$attempts = max( 1, $attempts );

		// Cap the exponent before computing the power: 2^100 overflows to INF and
		// INF cast to int is undefined behaviour territory.
		if ( $attempts > 20 ) {
			return self::MAX_BACKOFF_SECONDS;
		}

		return (int) min( ( 2 ** $attempts ) * MINUTE_IN_SECONDS, self::MAX_BACKOFF_SECONDS );
	}

	/**
	 * Return rows abandoned by a crashed worker to the pool.
	 *
	 * @return int Rows reclaimed.
	 */
	public function reclaim_abandoned(): int {
		global $wpdb;

		$table  = Installer::queue_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECLAIM_AFTER_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				SET claimed_at = NULL, claim_token = ''
				WHERE claimed_at IS NOT NULL AND claimed_at < %s",
				$table,
				$cutoff
			)
		);

		return is_int( $reclaimed ) ? $reclaimed : 0;
	}

	/**
	 * Counts and ages for the status screens.
	 *
	 * @return array{pending: int, ready: int, claimed: int, dead: int, oldest: ?string}
	 */
	public function stats(): array {
		global $wpdb;

		$table = Installer::queue_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					SUM( status = %s ) AS pending,
					SUM( status = %s AND claimed_at IS NULL AND available_at <= UTC_TIMESTAMP() ) AS ready,
					SUM( claimed_at IS NOT NULL ) AS claimed,
					SUM( status = %s ) AS dead,
					MIN( CASE WHEN status = %s THEN created_at END ) AS oldest
				FROM %i',
				self::STATUS_PENDING,
				self::STATUS_PENDING,
				self::STATUS_DEAD,
				self::STATUS_PENDING,
				$table
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'pending' => (int) ( $row['pending'] ?? 0 ),
			'ready'   => (int) ( $row['ready'] ?? 0 ),
			'claimed' => (int) ( $row['claimed'] ?? 0 ),
			'dead'    => (int) ( $row['dead'] ?? 0 ),
			'oldest'  => isset( $row['oldest'] ) && null !== $row['oldest'] ? (string) $row['oldest'] : null,
		);
	}

	/**
	 * Rows for the logs screen and `wp supabase queue list`.
	 *
	 * @param string $status Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $status = '', int $limit = 200 ): array {
		global $wpdb;

		$table = Installer::queue_table();
		$limit = max( 1, min( $limit, 1000 ) );

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d', $table, $status, $limit ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT %d', $table, $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Make one row eligible immediately.
	 *
	 * @return int Rows changed.
	 */
	public function retry( int $id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Installer::queue_table(),
			array(
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'last_error'   => null,
				'available_at' => gmdate( 'Y-m-d H:i:s' ),
				'claimed_at'   => null,
				'claim_token'  => '',
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Make every dead-lettered row eligible immediately.
	 *
	 * @return int Rows changed.
	 */
	public function retry_all(): int {
		global $wpdb;

		$table = Installer::queue_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				SET status = %s, attempts = 0, last_error = NULL, available_at = %s, claimed_at = NULL, claim_token = ''
				WHERE status = %s",
				$table,
				self::STATUS_PENDING,
				$now,
				self::STATUS_DEAD
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Empty the queue.
	 *
	 * @return int Rows removed.
	 */
	public function clear(): int {
		global $wpdb;

		$table = Installer::queue_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	public function depth(): int {
		return $this->stats()['pending'];
	}

	/**
	 * A token unique to one claim() call.
	 */
	private function new_token(): string {
		return substr( md5( uniqid( 'wpsb', true ) ), 0, 32 );
	}
}
