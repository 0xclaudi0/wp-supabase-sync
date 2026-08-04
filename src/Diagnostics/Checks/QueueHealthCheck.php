<?php
/**
 * Check 12: is the queue draining, or piling up?
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;

defined( 'ABSPATH' ) || exit;

final class QueueHealthCheck implements DiagnosticCheck {

	/**
	 * A pending item older than this suggests the queue is not draining.
	 */
	private const STALE_AFTER = 3600;

	/**
	 * Depth above which a backlog is worth mentioning on its own.
	 */
	private const DEEP_QUEUE = 1000;

	public function id(): string {
		return 'queue_health';
	}

	public function label(): string {
		return __( 'Queue health', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array();
	}

	public function run( Context $context ): Check {
		$stats = $context->queue->stats();

		$summary = sprintf(
			/* translators: 1: pending count, 2: ready count, 3: dead count. */
			__( '%1$d pending (%2$d ready now), %3$d dead-lettered.', 'wp-supabase-sync' ),
			$stats['pending'],
			$stats['ready'],
			$stats['dead']
		);

		// Dead-lettered rows are the loudest signal: something was retried eight
		// times, or failed in a way retries cannot fix, and then gave up.
		if ( $stats['dead'] > 0 ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: dead count, 2: queue summary. */
					__( '%1$d item(s) have been dead-lettered and will not be retried automatically. %2$s', 'wp-supabase-sync' ),
					$stats['dead'],
					$summary
				),
				__( 'Look at why: `wp supabase queue list --dead` shows the stored error for each. Fix the cause, then `wp supabase queue retry --all`.', 'wp-supabase-sync' )
			);
		}

		if ( null !== $stats['oldest'] ) {
			$oldest = strtotime( $stats['oldest'] . ' UTC' );

			if ( false !== $oldest && ( time() - $oldest ) > self::STALE_AFTER ) {
				return Check::warn(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: age of oldest item, 2: queue summary. */
						__( 'The oldest queued item has been waiting %1$s. %2$s', 'wp-supabase-sync' ),
						human_time_diff( $oldest, time() ),
						$summary
					),
					__( 'Either processing is not running — see the scheduled processing check — or items are failing and backing off. The logs page shows the errors.', 'wp-supabase-sync' )
				);
			}
		}

		if ( $stats['pending'] > self::DEEP_QUEUE ) {
			return Check::warn(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: queue summary. */
					__( 'The queue is deep but moving. %s', 'wp-supabase-sync' ),
					$summary
				),
				__( 'Normal after a backfill. If it is not draining fast enough, raise the batch size or run `wp supabase sync --all` from a real cron job rather than relying on WP-Cron.', 'wp-supabase-sync' )
			);
		}

		if ( 0 === $stats['pending'] ) {
			return Check::pass( $this->id(), $this->label(), __( 'The queue is empty; everything has been synced.', 'wp-supabase-sync' ) );
		}

		return Check::pass( $this->id(), $this->label(), $summary );
	}
}
