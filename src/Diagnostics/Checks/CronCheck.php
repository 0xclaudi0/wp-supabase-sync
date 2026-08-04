<?php
/**
 * Check 11: is the queue processor actually scheduled and running?
 *
 * WP-Cron only fires when someone visits the site. On a low-traffic site the
 * queue can sit untouched for hours while everything else looks healthy, which
 * is a confusing way to discover your content is stale.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Installer;

defined( 'ABSPATH' ) || exit;

final class CronCheck implements DiagnosticCheck {

	/**
	 * A run older than this, with work waiting, means cron is not firing.
	 */
	private const STALE_AFTER = 900;

	public function id(): string {
		return 'cron';
	}

	public function label(): string {
		return __( 'Scheduled processing', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		// Entirely local. Worth reporting even when Supabase is unreachable.
		return array();
	}

	public function run( Context $context ): Check {
		$next = wp_next_scheduled( Installer::PROCESS_QUEUE_HOOK );

		if ( ! is_int( $next ) ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				__( 'The queue processing event is not scheduled, so nothing will be synced automatically.', 'wp-supabase-sync' ),
				__( 'Deactivate and reactivate the plugin to reschedule it. If it does not stick, another plugin or a wp-config setting may be clearing scheduled events.', 'wp-supabase-sync' )
			);
		}

		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$last_run = $context->engine->last_run();
		$stats    = $context->queue->stats();

		/*
		 * human_time_diff() returns an unsigned difference, so a timestamp in the
		 * past reads exactly like one in the future. Saying "next run due in 3
		 * hours" about an event that was due 3 hours *ago* is worse than saying
		 * nothing: it reports a healthy schedule while syncing has quietly
		 * stopped. Check the direction before wording it.
		 */
		$overdue_by = time() - $next;

		$scheduled_in = $overdue_by > 0
			? sprintf(
				/* translators: %s: human-readable time difference. */
				__( 'The next run is overdue by %s.', 'wp-supabase-sync' ),
				human_time_diff( $next, time() )
			)
			: sprintf(
				/* translators: %s: human-readable time difference. */
				__( 'Next run due in %s.', 'wp-supabase-sync' ),
				human_time_diff( time(), $next )
			);

		if ( $disabled ) {
			return Check::warn(
				$this->id(),
				$this->label(),
				__( 'The event is scheduled, but DISABLE_WP_CRON is set in wp-config.php, so WordPress will not run it on its own.', 'wp-supabase-sync' ),
				__( 'That is a perfectly good setup as long as something external triggers it. Add a real cron job hitting wp-cron.php, or better, one running: wp supabase sync --all', 'wp-supabase-sync' )
			);
		}

		$never_run   = null === $last_run || empty( $last_run['at'] );
		$has_backlog = $stats['ready'] > 0;

		/*
		 * Well past due means WP-Cron is not firing at all. Worth reporting even
		 * with an empty queue: there is nothing to lose right now, but the next
		 * time someone publishes, their content sits unsynced until a visitor
		 * happens to load a page. Better to hear it before that than after.
		 */
		if ( $overdue_by > self::STALE_AFTER ) {
			$detail = sprintf(
				/* translators: 1: how long overdue, 2: number of items waiting. */
				__( 'The scheduled event was due %1$s ago and has not run, so WP-Cron is not firing. %2$d item(s) are waiting.', 'wp-supabase-sync' ),
				human_time_diff( $next, time() ),
				$stats['ready']
			);

			$fix = __( 'WP-Cron only runs when someone visits the site, so a quiet site stops syncing. Add a real cron job — every minute, running `wp supabase sync --all` — and set DISABLE_WP_CRON to true in wp-config.php so the two do not overlap.', 'wp-supabase-sync' );

			// Content is actually stale only if something is waiting.
			return $has_backlog
				? Check::fail( $this->id(), $this->label(), $detail, $fix )
				: Check::warn( $this->id(), $this->label(), $detail, $fix );
		}

		if ( $never_run ) {
			// Never having run is only a problem if there is work waiting.
			if ( $has_backlog ) {
				return Check::warn(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: number of queued items, 2: next run description. */
						__( 'The processor has never run, and %1$d item(s) are waiting. %2$s', 'wp-supabase-sync' ),
						$stats['ready'],
						$scheduled_in
					),
					__( 'WP-Cron only fires on a page view, so visit the site, or run `wp supabase sync --all` to drain the queue now. On a low-traffic site, a real cron job hitting wp-cron.php is far more reliable.', 'wp-supabase-sync' )
				);
			}

			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: next run description. */
					__( 'Scheduled and the queue is empty, so there has been nothing to do. %s', 'wp-supabase-sync' ),
					$scheduled_in
				)
			);
		}//end if

		$last_timestamp = strtotime( (string) $last_run['at'] );
		$age            = false === $last_timestamp ? PHP_INT_MAX : time() - $last_timestamp;

		if ( $age > self::STALE_AFTER && $has_backlog ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: how long ago, 2: number of queued items. */
					__( 'The processor last ran %1$s ago and %2$d item(s) are still waiting, so scheduled processing has stalled.', 'wp-supabase-sync' ),
					human_time_diff( (int) $last_timestamp, time() ),
					$stats['ready']
				),
				__( 'WP-Cron depends on site traffic. Set up a real cron job — every minute, running `wp supabase sync --all`, or hitting wp-cron.php — and set DISABLE_WP_CRON to true so the two do not overlap.', 'wp-supabase-sync' )
			);
		}

		return Check::pass(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: last run description, 2: next run description. */
				__( 'Last run %1$s. %2$s', 'wp-supabase-sync' ),
				$context->engine->last_run_description(),
				$scheduled_in
			)
		);
	}
}
