<?php
/**
 * Paginated full resync.
 *
 * Enqueues rather than syncing inline. A site with 50,000 posts cannot be synced
 * in one request, and a backfill that dies halfway through a direct sync leaves
 * no record of where it got to. Going through the queue means the existing
 * batching, backoff and dead-letter machinery applies to the backfill too, for
 * free.
 *
 * Progress lives in an option so the run survives a PHP timeout and resumes on
 * the next invocation.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

use WPSupabaseSync\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Backfill {

	public const PROGRESS_OPTION = 'wpsb_backfill_progress';

	/**
	 * Posts examined per batch.
	 *
	 * Independent of the sync batch size: this is a WP_Query page size, bounded
	 * by memory rather than by HTTP payload.
	 */
	private const PAGE_SIZE = 200;

	public function __construct(
		private readonly Settings $settings,
		private readonly Queue $queue
	) {}

	public function reset(): void {
		delete_option( self::PROGRESS_OPTION );
	}

	/**
	 * Current progress, or null when no backfill is in flight.
	 *
	 * @return array<string, mixed>|null
	 */
	public function progress(): ?array {
		$progress = get_option( self::PROGRESS_OPTION );

		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Enqueue one page of posts.
	 *
	 * @param string $post_type Restrict to one post type, or empty for all synced types.
	 * @param bool   $force     Clear stored hashes so unchanged posts still sync.
	 * @return array{page: int, enqueued: int, total: int, done: bool}
	 */
	public function run_batch( string $post_type = '', bool $force = false ): array {
		$progress = $this->progress() ?? array(
			'page'       => 0,
			'enqueued'   => 0,
			'post_type'  => $post_type,
			'started_at' => gmdate( 'c' ),
		);

		$page       = (int) $progress['page'] + 1;
		$post_types = '' !== $post_type
			? array( $post_type )
			: $this->settings->synced_post_types();

		if ( empty( $post_types ) ) {
			$this->reset();

			return array(
				'page'     => $page,
				'enqueued' => 0,
				'total'    => (int) $progress['enqueued'],
				'done'     => true,
			);
		}

		/*
		 * `fields => ids` and `no_found_rows` keep this cheap: the mapper reloads
		 * each post at sync time anyway, so hydrating full post objects here
		 * would be wasted work on top of wasted memory.
		 */
		$query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		// Stop the object cache growing without bound across many pages.
		wp_suspend_cache_addition( true );

		foreach ( $ids as $post_id ) {
			if ( $force ) {
				PostMapper::clear_hash( $post_id );
			}

			$this->queue->enqueue( $post_id, Queue::ACTION_UPSERT );
		}

		wp_suspend_cache_addition( false );

		$enqueued = count( $ids );
		$total    = (int) $progress['enqueued'] + $enqueued;
		$done     = $enqueued < self::PAGE_SIZE;

		if ( $done ) {
			$this->reset();
		} else {
			update_option(
				self::PROGRESS_OPTION,
				array_merge(
					$progress,
					array(
						'page'     => $page,
						'enqueued' => $total,
					)
				),
				false
			);
		}

		return array(
			'page'     => $page,
			'enqueued' => $enqueued,
			'total'    => $total,
			'done'     => $done,
		);
	}

	/**
	 * Count of publishable posts across the synced post types.
	 *
	 * Used by the admin screens to show what a backfill would cover.
	 */
	public function eligible_count(): int {
		$total = 0;

		foreach ( $this->settings->synced_post_types() as $post_type ) {
			$counts = wp_count_posts( $post_type );

			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		return $total;
	}
}
