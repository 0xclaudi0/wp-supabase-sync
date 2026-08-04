<?php
/**
 * Table definitions, activation and deactivation.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync;

use WPSupabaseSync\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * Bumped whenever a table definition below changes, which triggers dbDelta
	 * on the next admin request rather than waiting for a reactivation.
	 */
	public const SCHEMA_VERSION = 2;

	public const VERSION_OPTION = 'wpsb_schema_version';

	/**
	 * Cron hook that drains the queue.
	 *
	 * Declared here so deactivation and uninstall can clean it up. Nothing
	 * schedules it yet — the queue processor arrives in M4.
	 */
	public const PROCESS_QUEUE_HOOK = 'wpsb_process_queue';

	public const CRON_SCHEDULE = 'wpsb_minute';

	private const TABLE_QUEUE = 'wpsb_queue';
	private const TABLE_LOG   = 'wpsb_log';

	public static function activate(): void {
		self::install();

		if ( ! wp_next_scheduled( self::PROCESS_QUEUE_HOOK ) ) {
			/*
			 * The custom schedule is registered on the cron_schedules filter,
			 * which has already run by the time an activation hook fires on some
			 * request orderings. Passing the schedule name still works: WP-Cron
			 * resolves the interval when the event is due, not when it is added.
			 */
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::PROCESS_QUEUE_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::PROCESS_QUEUE_HOOK );
	}

	/**
	 * Run dbDelta when the stored schema version is behind the code.
	 *
	 * Safe to call on every request: the option check is one autoloaded read and
	 * dbDelta only runs when the version actually moved.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $definition ) {
			dbDelta( $definition );
		}

		// Seed defaults so the settings page and Settings accessors see a real
		// array on a fresh install rather than falling back field by field.
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	public static function queue_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_QUEUE;
	}

	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_LOG;
	}

	/**
	 * All CREATE TABLE statements, in the format dbDelta expects.
	 *
	 * Note that dbDelta is fussy: one field per line, two spaces after PRIMARY KEY, and
	 * every KEY needs a name. Do not reformat casually.
	 *
	 * @return string[]
	 */
	public static function definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$queue           = self::queue_table();
		$log             = self::log_table();

		$sql = array();

		/*
		 * One row per pending change, not one row per event.
		 *
		 * The UNIQUE KEY on (object_type, object_id) is the whole point: a bulk
		 * edit of 500 posts fires transition_post_status, set_object_terms and
		 * several meta hooks per post, and without coalescing that is thousands
		 * of rows describing the same handful of outcomes. With it, a newer
		 * event replaces the pending one via ON DUPLICATE KEY UPDATE.
		 *
		 * available_at gates retry backoff; claimed_at exists so a worker that
		 * dies mid-batch does not strand its rows forever — anything claimed
		 * longer than the reclaim window is picked up again.
		 */
		$sql[] = "CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(32) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL,
			action varchar(16) NOT NULL DEFAULT 'upsert',
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			available_at datetime NOT NULL,
			claimed_at datetime NULL DEFAULT NULL,
			claim_token varchar(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object (object_type, object_id),
			KEY claimable (status, claimed_at, available_at),
			KEY status (status),
			KEY claim_token (claim_token)
		) {$charset_collate};";

		/*
		 * The log is what the diagnostics and logs screens read, and what a user
		 * will paste into a support thread. Two consequences: every string
		 * written here goes through Redactor first, and `context` holds JSON
		 * rather than a pre-formatted sentence so the display layer can decide
		 * how much to show.
		 */
		$sql[] = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(16) NOT NULL DEFAULT 'info',
			channel varchar(32) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level_created (level, created_at),
			KEY channel (channel)
		) {$charset_collate};";

		return $sql;
	}

	/**
	 * Remove every table, option and scheduled event this plugin created.
	 *
	 * Called only from uninstall.php, and only when the user opted in.
	 */
	public static function drop_everything(): void {
		global $wpdb;

		wp_clear_scheduled_hook( self::PROCESS_QUEUE_HOOK );

		foreach ( array( self::queue_table(), self::log_table() ) as $table ) {
			// %i is the identifier placeholder added in WordPress 6.2, which this
			// plugin's minimum of 6.4 guarantees. It escapes the table name inside
			// prepare() rather than interpolating it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Settings::OPTION );
		delete_option( self::VERSION_OPTION );
		delete_option( \WPSupabaseSync\Sync\SyncEngine::LAST_RUN_OPTION );
		delete_option( \WPSupabaseSync\Sync\Backfill::PROGRESS_OPTION );

		// The per-post content hashes are ours too, and leaving thousands of
		// orphaned meta rows behind after an opted-in uninstall is not tidy.
		delete_post_meta_by_key( \WPSupabaseSync\Sync\PostMapper::HASH_META_KEY );
	}
}
