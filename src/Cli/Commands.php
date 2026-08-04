<?php
/**
 * WP-CLI commands.
 *
 * Registered only when WP_CLI is defined. `doctor` exits non-zero on any failing
 * check, which makes it usable from CI or a monitoring cron rather than only by
 * a human reading output.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Cli;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\DiagnosticsRunner;
use WPSupabaseSync\Plugin;
use WPSupabaseSync\Sync\Backfill;
use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Sync\Schema;

use function WPSupabaseSync\plugin;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'supabase', self::class );
	}

	/**
	 * Run the diagnostics and report.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp supabase doctor
	 *     wp supabase doctor --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function doctor( array $args, array $assoc_args ): void {
		$checks = $this->plugin()->diagnostics()->run();
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( array_map( static fn( Check $c ): array => $c->to_array(), $checks ) ) );
		} else {
			$rows = array();

			foreach ( $checks as $check ) {
				$rows[] = array(
					'status' => strtoupper( $check->status ),
					'check'  => $check->label,
					'detail' => $check->detail,
				);
			}

			\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check', 'detail' ) );

			foreach ( $checks as $check ) {
				if ( '' !== $check->fix && Check::STATUS_PASS !== $check->status && Check::STATUS_SKIP !== $check->status ) {
					\WP_CLI::line( '' );
					\WP_CLI::line( \WP_CLI::colorize( "%y{$check->label} — how to fix:%n" ) );
					\WP_CLI::line( $check->fix );
				}
			}
		}//end if

		$failed = array_filter( $checks, static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status );

		if ( ! empty( $failed ) ) {
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success( 'All checks passed.' );
	}

	/**
	 * Show queue depth, last sync and dead-letter count.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$queue    = $this->plugin()->queue();
		$settings = $this->plugin()->settings();
		$stats    = $queue->stats();

		$rows = array(
			array(
				'key'   => 'sync_enabled',
				'value' => $settings->sync_enabled() ? 'yes' : 'no',
			),
			array(
				'key'   => 'site_id',
				'value' => $settings->site_id(),
			),
			array(
				'key'   => 'table',
				'value' => $settings->table_name(),
			),
			array(
				'key'   => 'queue_pending',
				'value' => (string) $stats['pending'],
			),
			array(
				'key'   => 'queue_ready_now',
				'value' => (string) $stats['ready'],
			),
			array(
				'key'   => 'queue_claimed',
				'value' => (string) $stats['claimed'],
			),
			array(
				'key'   => 'dead_letter',
				'value' => (string) $stats['dead'],
			),
			array(
				'key'   => 'oldest_pending',
				'value' => $stats['oldest'] ?? '—',
			),
			array(
				'key'   => 'last_run',
				'value' => $this->plugin()->engine()->last_run_description(),
			),
			array(
				'key'   => 'next_cron',
				'value' => $this->next_cron_description(),
			),
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			$out = array();

			foreach ( $rows as $row ) {
				$out[ $row['key'] ] = $row['value'];
			}

			\WP_CLI::line( (string) wp_json_encode( $out ) );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Sync one post, or everything.
	 *
	 * ## OPTIONS
	 *
	 * [<post_id>]
	 * : A single post to sync. Omit when using --all.
	 *
	 * [--all]
	 * : Enqueue every post of every synced post type.
	 *
	 * [--post-type=<post_type>]
	 * : Restrict --all to one post type.
	 *
	 * [--force]
	 * : Ignore the content hash and sync even if nothing changed.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing to Supabase.
	 *
	 * ## EXAMPLES
	 *
	 *     wp supabase sync 42
	 *     wp supabase sync --all --post-type=post
	 *     wp supabase sync --all --dry-run
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$force   = isset( $assoc_args['force'] );
		$dry_run = isset( $assoc_args['dry-run'] );
		$all     = isset( $assoc_args['all'] );

		if ( ! $all && empty( $args ) ) {
			\WP_CLI::error( 'Pass a post ID, or --all.' );
		}

		if ( $all ) {
			$this->sync_all( $assoc_args, $force, $dry_run );

			return;
		}

		$post_id = (int) $args[0];
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( sprintf( 'No post with ID %d.', $post_id ) );
		}

		if ( $dry_run ) {
			$row = $this->plugin()->mapper()->map( $post );

			\WP_CLI::line( (string) wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			\WP_CLI::success( 'Dry run: nothing was sent.' );

			return;
		}

		try {
			$result = $this->plugin()->engine()->sync_post( $post_id, $force );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		if ( $result->skipped ) {
			\WP_CLI::success( sprintf( 'Post %d unchanged; nothing sent. Use --force to sync anyway.', $post_id ) );

			return;
		}

		if ( ! $result->success ) {
			\WP_CLI::error( sprintf( 'Post %d failed: %s', $post_id, $result->message ) );
		}

		\WP_CLI::success( sprintf( 'Post %d %s.', $post_id, $result->action ) );
	}

	/**
	 * Backfill everything, then drain the queue until it stops moving.
	 *
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function sync_all( array $assoc_args, bool $force, bool $dry_run ): void {
		$post_type = isset( $assoc_args['post-type'] ) ? (string) $assoc_args['post-type'] : '';
		$backfill  = $this->plugin()->backfill();

		$backfill->reset();

		$total = 0;

		do {
			$batch  = $backfill->run_batch( $post_type, $force );
			$total += $batch['enqueued'];

			if ( $batch['enqueued'] > 0 ) {
				\WP_CLI::log( sprintf( 'Enqueued %d (page %d).', $batch['enqueued'], $batch['page'] ) );
			}
		} while ( ! $batch['done'] );

		\WP_CLI::success( sprintf( 'Enqueued %d posts.', $total ) );

		if ( $dry_run ) {
			\WP_CLI::log( 'Dry run: queue populated but not processed. Run `wp supabase queue clear` to discard.' );

			return;
		}

		$processed = 0;

		while ( true ) {
			$result = $this->plugin()->engine()->process_queue();

			if ( 0 === $result['claimed'] ) {
				break;
			}

			$processed += $result['succeeded'];

			\WP_CLI::log(
				sprintf(
					'Batch: %d claimed, %d succeeded, %d failed.',
					$result['claimed'],
					$result['succeeded'],
					$result['failed']
				)
			);

			if ( 0 === $result['succeeded'] && $result['failed'] > 0 ) {
				\WP_CLI::warning( 'A whole batch failed; stopping so the backoff can apply.' );
				break;
			}
		}//end while

		\WP_CLI::success( sprintf( 'Synced %d posts.', $processed ) );
	}

	/**
	 * Inspect and manage the queue.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of list, retry, clear.
	 *
	 * [--dead]
	 * : With list, show only dead-lettered rows.
	 *
	 * [--all]
	 * : With retry, retry every dead-lettered row.
	 *
	 * [--id=<id>]
	 * : With retry, retry one row.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp supabase queue list
	 *     wp supabase queue list --dead
	 *     wp supabase queue retry --all
	 *     wp supabase queue clear
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function queue( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		$queue  = $this->plugin()->queue();

		switch ( $action ) {
			case 'list':
				$rows = $queue->all( isset( $assoc_args['dead'] ) ? Queue::STATUS_DEAD : '' );

				if ( empty( $rows ) ) {
					\WP_CLI::success( 'Queue is empty.' );

					return;
				}

				\WP_CLI\Utils\format_items(
					(string) ( $assoc_args['format'] ?? 'table' ),
					$rows,
					array( 'id', 'object_type', 'object_id', 'action', 'status', 'attempts', 'available_at', 'claimed_at', 'last_error' )
				);
				break;

			case 'retry':
				if ( isset( $assoc_args['id'] ) ) {
					$count = $queue->retry( (int) $assoc_args['id'] );
				} elseif ( isset( $assoc_args['all'] ) ) {
					$count = $queue->retry_all();
				} else {
					\WP_CLI::error( 'Pass --all or --id=<id>.' );
				}

				\WP_CLI::success( sprintf( 'Reset %d queue row(s) for immediate retry.', $count ) );
				break;

			case 'clear':
				$count = $queue->clear();

				\WP_CLI::success( sprintf( 'Removed %d queue row(s).', $count ) );
				break;

			default:
				\WP_CLI::error( 'Unknown action. Use list, retry or clear.' );
		}//end switch
	}

	/**
	 * Print the migration SQL for the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--print]
	 * : Print the SQL. The default and only mode; the plugin never runs DDL itself.
	 *
	 * ## EXAMPLES
	 *
	 *     wp supabase schema --print
	 *     wp supabase schema --print > supabase/migrations/0001_wp_content.sql
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both; this command reads neither.
	public function schema( array $args, array $assoc_args ): void {
		// Deliberately never applied from here. Running DDL with the service key
		// on the user's behalf would be a surprising amount of authority for a
		// WordPress plugin to take, and `supabase db push` already does it well.
		\WP_CLI::line( ( new Schema( $this->plugin()->settings() ) )->migration() );
	}

	private function next_cron_description(): string {
		$next = wp_next_scheduled( \WPSupabaseSync\Installer::PROCESS_QUEUE_HOOK );

		if ( ! is_int( $next ) ) {
			return 'not scheduled';
		}

		return sprintf(
			'%s (in %s)',
			gmdate( 'Y-m-d H:i:s', $next ) . ' UTC',
			human_time_diff( time(), $next )
		);
	}

	private function plugin(): Plugin {
		return plugin();
	}
}
