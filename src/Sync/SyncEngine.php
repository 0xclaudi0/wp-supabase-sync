<?php
/**
 * Decides what the mirror should contain, and makes it so.
 *
 * Two entry points: sync_post() for one object right now, and process_queue()
 * for a batch off the queue. Both funnel into the same decision — mirror it, or
 * remove it — so the inline path and the queued path cannot disagree about what
 * the correct end state is.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Client\Response;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\ErrorTranslator;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class SyncEngine {

	/**
	 * Option holding a short record of the last queue run, for the status
	 * screens and the `cron` diagnostic.
	 */
	public const LAST_RUN_OPTION = 'wpsb_last_run';

	/**
	 * The only status that gets mirrored.
	 *
	 * See SPEC §5.2. The mirror holds exactly what the public frontend may read.
	 * Keeping a draft row with `status = 'draft'` would leave unpublished content
	 * sitting in the table, one bad policy away from being readable. Deleting is
	 * the safer default.
	 */
	private const MIRRORED_STATUSES = array( 'publish' );

	public function __construct(
		private readonly Settings $settings,
		private readonly SupabaseClient $client,
		private readonly PostMapper $mapper,
		private readonly Queue $queue,
		private readonly Logger $logger
	) {}

	/**
	 * Whether a post belongs in the mirror.
	 */
	public function should_mirror( \WP_Post $post ): bool {
		return in_array( $post->post_type, $this->settings->synced_post_types(), true )
			&& in_array( $post->post_status, self::MIRRORED_STATUSES, true );
	}

	/**
	 * Sync one post immediately, bypassing the queue.
	 *
	 * @param bool $force Ignore the stored content hash.
	 */
	public function sync_post( int $post_id, bool $force = false ): SyncResult {
		if ( ! $this->settings->sync_enabled() ) {
			return SyncResult::disabled();
		}

		$post = get_post( $post_id );

		// A post that is gone, or no longer qualifies, must not be in the mirror.
		if ( ! $post instanceof \WP_Post || ! $this->should_mirror( $post ) ) {
			return $this->delete_posts( array( $post_id ) );
		}

		$row  = $this->mapper->map( $post );
		$hash = (string) $row['content_hash'];

		if ( ! $force && PostMapper::stored_hash( $post_id ) === $hash ) {
			return SyncResult::unchanged();
		}

		try {
			$response = $this->client->upsert( array( $row ) );
		} catch ( ApiException $e ) {
			return SyncResult::failed( Queue::ACTION_UPSERT, $e->getMessage() );
		}

		if ( ! $response->is_success() ) {
			return SyncResult::failed( Queue::ACTION_UPSERT, $this->explain( $response ) );
		}

		PostMapper::store_hash( $post_id, $hash );

		return SyncResult::upserted();
	}

	/**
	 * Remove posts from the mirror.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	public function delete_posts( array $post_ids ): SyncResult {
		if ( ! $this->settings->sync_enabled() ) {
			return SyncResult::disabled();
		}

		try {
			$response = $this->client->delete_by_wp_ids( $post_ids );
		} catch ( ApiException $e ) {
			return SyncResult::failed( Queue::ACTION_DELETE, $e->getMessage() );
		}

		if ( ! $response->is_success() ) {
			return SyncResult::failed( Queue::ACTION_DELETE, $this->explain( $response ) );
		}

		foreach ( $post_ids as $post_id ) {
			PostMapper::clear_hash( (int) $post_id );
		}

		return SyncResult::deleted();
	}

	/**
	 * Claim a batch and process it.
	 *
	 * Grouped by action so a batch of 50 changes costs two HTTP requests rather
	 * than 50. That grouping is also why a failure marks the whole group failed:
	 * PostgREST answers per request, not per row, so the engine cannot tell which
	 * row of a rejected batch was at fault. The retry then re-sends them, and if
	 * one row is genuinely poisonous it eventually dead-letters with the rest —
	 * which the diagnostics surface rather than hiding.
	 *
	 * @return array{claimed: int, succeeded: int, failed: int, skipped: int}
	 */
	public function process_queue(): array {
		$empty = array(
			'claimed'   => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		if ( ! $this->settings->sync_enabled() ) {
			return $empty;
		}

		$rows = $this->queue->claim( $this->settings->batch_size() );

		if ( empty( $rows ) ) {
			return $empty;
		}

		$token = (string) $rows[0]['claim_token'];
		$stats = $empty;

		$stats['claimed'] = count( $rows );

		$upserts = array();
		$deletes = array();

		foreach ( $rows as $row ) {
			if ( Queue::ACTION_DELETE === $row['action'] ) {
				$deletes[] = $row;

				continue;
			}

			$upserts[] = $row;
		}

		$outcome = $this->process_upserts( $upserts, $token );

		$stats['succeeded'] += $outcome['succeeded'];
		$stats['failed']    += $outcome['failed'];
		$stats['skipped']   += $outcome['skipped'];

		$outcome = $this->process_deletes( $deletes, $token );

		$stats['succeeded'] += $outcome['succeeded'];
		$stats['failed']    += $outcome['failed'];

		$this->record_run( $stats );

		return $stats;
	}

	/**
	 * Map, skip the unchanged, and send whatever is left as one request.
	 *
	 * @param array<int, array<string, mixed>> $rows Claimed upsert rows.
	 * @return array{succeeded: int, failed: int, skipped: int}
	 */
	private function process_upserts( array $rows, string $token ): array {
		$result = array(
			'succeeded' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$payload       = array();
		$hashes        = array();
		$queue_ids     = array();
		$unchanged_ids = array();
		$vanished_ids  = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['object_id'];
			$post    = get_post( $post_id );

			// Queued as an upsert, but by the time we got here it no longer
			// qualifies. Turn it into a delete rather than dropping it: the
			// mirror may still hold the old row.
			if ( ! $post instanceof \WP_Post || ! $this->should_mirror( $post ) ) {
				$vanished_ids[ (int) $row['id'] ] = $post_id;

				continue;
			}

			$mapped = $this->mapper->map( $post );
			$hash   = (string) $mapped['content_hash'];

			if ( PostMapper::stored_hash( $post_id ) === $hash ) {
				$unchanged_ids[] = (int) $row['id'];

				continue;
			}

			$payload[]          = $mapped;
			$hashes[ $post_id ] = $hash;
			$queue_ids[]        = (int) $row['id'];
		}//end foreach

		// Nothing changed: complete the rows without spending a request.
		if ( ! empty( $unchanged_ids ) ) {
			$this->queue->complete( $unchanged_ids, $token );

			$result['skipped'] += count( $unchanged_ids );
		}

		if ( ! empty( $vanished_ids ) ) {
			$deleted = $this->delete_posts( array_values( $vanished_ids ) );

			if ( $deleted->success ) {
				$this->queue->complete( array_keys( $vanished_ids ), $token );

				$result['succeeded'] += count( $vanished_ids );
			} else {
				$this->fail( array_keys( $vanished_ids ), $token, $deleted->message );

				$result['failed'] += count( $vanished_ids );
			}
		}

		if ( empty( $payload ) ) {
			return $result;
		}

		try {
			$response = $this->client->upsert( $payload );
		} catch ( ApiException $e ) {
			$this->queue->fail( $queue_ids, $token, $e->getMessage(), true );

			$result['failed'] += count( $queue_ids );

			return $result;
		}

		if ( ! $response->is_success() ) {
			$this->fail( $queue_ids, $token, $this->explain( $response ), $response );

			$result['failed'] += count( $queue_ids );

			return $result;
		}

		foreach ( $hashes as $post_id => $hash ) {
			PostMapper::store_hash( (int) $post_id, $hash );
		}

		$this->queue->complete( $queue_ids, $token );

		$result['succeeded'] += count( $queue_ids );

		return $result;
	}

	/**
	 * Remove a whole batch of posts in one request.
	 *
	 * @param array<int, array<string, mixed>> $rows Claimed delete rows.
	 * @return array{succeeded: int, failed: int}
	 */
	private function process_deletes( array $rows, string $token ): array {
		$result = array(
			'succeeded' => 0,
			'failed'    => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$queue_ids = array();
		$post_ids  = array();

		foreach ( $rows as $row ) {
			$queue_ids[] = (int) $row['id'];
			$post_ids[]  = (int) $row['object_id'];
		}

		try {
			$response = $this->client->delete_by_wp_ids( $post_ids );
		} catch ( ApiException $e ) {
			$this->queue->fail( $queue_ids, $token, $e->getMessage(), true );

			$result['failed'] += count( $queue_ids );

			return $result;
		}

		if ( ! $response->is_success() ) {
			$this->fail( $queue_ids, $token, $this->explain( $response ), $response );

			$result['failed'] += count( $queue_ids );

			return $result;
		}

		foreach ( $post_ids as $post_id ) {
			PostMapper::clear_hash( $post_id );
		}

		$this->queue->complete( $queue_ids, $token );

		$result['succeeded'] += count( $queue_ids );

		return $result;
	}

	/**
	 * Fail queue rows, deciding whether a retry could ever help.
	 *
	 * @param int[]         $queue_ids Queue row IDs.
	 * @param Response|null $response  The response, when there was one.
	 */
	private function fail( array $queue_ids, string $token, string $message, ?Response $response = null ): void {
		$permanent = null !== $response && ! ErrorTranslator::is_retryable( $response );

		$this->queue->fail( $queue_ids, $token, $message, $permanent );

		$this->logger->error(
			'sync',
			$permanent
				? __( 'Batch failed permanently and was dead-lettered.', 'wp-supabase-sync' )
				: __( 'Batch failed and will be retried.', 'wp-supabase-sync' ),
			array(
				'queue_ids' => $queue_ids,
				'error'     => $message,
			)
		);
	}

	/**
	 * Turn a failed response into an explanation worth storing.
	 */
	private function explain( Response $response ): string {
		return ErrorTranslator::translate( $response )->to_sentence();
	}

	/**
	 * Remember the last run, which the cron and status checks read.
	 *
	 * @param array{claimed: int, succeeded: int, failed: int, skipped: int} $stats Run stats.
	 */
	private function record_run( array $stats ): void {
		update_option(
			self::LAST_RUN_OPTION,
			array_merge( $stats, array( 'at' => gmdate( 'c' ) ) ),
			false
		);
	}

	/**
	 * The recorded stats from the last queue run, if there has been one.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_run(): ?array {
		$run = get_option( self::LAST_RUN_OPTION );

		return is_array( $run ) ? $run : null;
	}

	public function last_run_description(): string {
		$run = $this->last_run();

		if ( null === $run || empty( $run['at'] ) ) {
			return __( 'never', 'wp-supabase-sync' );
		}

		$timestamp = strtotime( (string) $run['at'] );

		if ( false === $timestamp ) {
			return __( 'never', 'wp-supabase-sync' );
		}

		return sprintf(
			/* translators: 1: how long ago, 2: succeeded count, 3: failed count. */
			__( '%1$s ago (%2$d succeeded, %3$d failed)', 'wp-supabase-sync' ),
			human_time_diff( $timestamp, time() ),
			(int) ( $run['succeeded'] ?? 0 ),
			(int) ( $run['failed'] ?? 0 )
		);
	}
}
