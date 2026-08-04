<?php
/**
 * Writes to the plugin's log table.
 *
 * Every message and every context value passes through the Redactor on the way
 * in, not on the way out. Redacting at write time means a key cannot be sitting
 * in the database waiting for some future screen, export or support bundle to
 * forget to redact it.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Support;

use WPSupabaseSync\Installer;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * Context is stored as JSON in a longtext column. This cap stops one
	 * pathological response body from writing a megabyte per row.
	 */
	private const MAX_CONTEXT_BYTES = 8000;

	/**
	 * Record something that went normally.
	 *
	 * @param string               $channel Subsystem, e.g. client, sync, diagnostics.
	 * @param array<string, mixed> $context Structured detail.
	 */
	public function info( string $channel, string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $channel, $message, $context );
	}

	/**
	 * Record something that worked but deserves attention.
	 *
	 * @param array<string, mixed> $context Structured detail.
	 */
	public function warning( string $channel, string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $channel, $message, $context );
	}

	/**
	 * Record a failure.
	 *
	 * @param array<string, mixed> $context Structured detail.
	 */
	public function error( string $channel, string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $channel, $message, $context );
	}

	/**
	 * Write one row, redacting the message and context on the way in.
	 *
	 * @param array<string, mixed> $context     Structured detail.
	 * @param string               $object_type Related object type, e.g. post.
	 * @param int|null             $object_id   Related object id.
	 */
	public function log(
		string $level,
		string $channel,
		string $message,
		array $context = array(),
		string $object_type = '',
		?int $object_id = null
	): void {
		global $wpdb;

		$encoded = '';

		if ( ! empty( $context ) ) {
			$json = wp_json_encode( Redactor::redact_deep( $context ) );

			if ( is_string( $json ) ) {
				$encoded = strlen( $json ) > self::MAX_CONTEXT_BYTES
					? substr( $json, 0, self::MAX_CONTEXT_BYTES )
					: $json;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Installer::log_table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'level'       => $level,
				'channel'     => $channel,
				'message'     => Redactor::redact( $message ),
				'context'     => '' === $encoded ? null : $encoded,
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Most recent rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( $limit, 500 ) );
		$table = Installer::log_table();

		// %i is WordPress's identifier placeholder, added in 6.2. It lets the
		// table name go through prepare() and be escaped properly, rather than
		// being interpolated and justified in a comment.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @return int Rows removed.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;

		$table  = Installer::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff )
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
