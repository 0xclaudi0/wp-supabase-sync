<?php
/**
 * WordPress hooks that feed the queue.
 *
 * Every handler bails early and cheaply. These run on ordinary editorial
 * actions, so the cost of an irrelevant event has to be close to zero.
 *
 * `transition_post_status` is the primary trigger rather than `save_post`,
 * because it supplies both the old and the new status — and the old status is
 * what decides whether an edit is an upsert or a removal.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

use WPSupabaseSync\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Hooks {

	public function __construct(
		private readonly Settings $settings,
		private readonly Queue $queue,
		private readonly SyncEngine $engine
	) {}

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ) );
		add_action( 'untrashed_post', array( $this, 'on_untrashed_post' ) );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 4 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Primary trigger. Decides upsert versus delete from the status change.
	 *
	 * @param string   $new_status Status being moved to.
	 * @param string   $old_status Status being moved from.
	 * @param \WP_Post $post       The post.
	 */
	public function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		// An auto-draft is a post WordPress created for an editor that may never
		// be saved. It is not content and must never reach the mirror.
		if ( 'auto-draft' === $new_status ) {
			return;
		}

		if ( ! $this->is_relevant( $post ) ) {
			return;
		}

		if ( in_array( $new_status, array( 'publish' ), true ) ) {
			$this->queue->enqueue( (int) $post->ID, Queue::ACTION_UPSERT );

			return;
		}

		/*
		 * Anything leaving publish is removed from the mirror, per SPEC §5.2.
		 * Only enqueue a delete when it was previously published — otherwise a
		 * draft being saved repeatedly would queue pointless deletes for a row
		 * that was never there.
		 */
		if ( 'publish' === $old_status ) {
			$this->queue->enqueue( (int) $post->ID, Queue::ACTION_DELETE );
		}
	}

	/**
	 * Enqueue a delete before the post row disappears.
	 *
	 * This has to be `before_delete_post`: by `deleted_post` the post type is no
	 * longer readable, so the relevance guard could not run.
	 *
	 * @param int           $post_id Post being deleted.
	 * @param \WP_Post|null $post    The post, on WP 5.5+.
	 */
	public function on_before_delete_post( int $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_relevant( $post ) ) {
			return;
		}

		$this->queue->enqueue( $post_id, Queue::ACTION_DELETE );
	}

	public function on_trashed_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_relevant( $post ) ) {
			return;
		}

		$this->queue->enqueue( $post_id, Queue::ACTION_DELETE );
	}

	/**
	 * Restoring from trash: mirror it again if it lands on a synced status.
	 */
	public function on_untrashed_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_relevant( $post ) ) {
			return;
		}

		$this->queue->enqueue(
			$post_id,
			$this->engine->should_mirror( $post ) ? Queue::ACTION_UPSERT : Queue::ACTION_DELETE
		);
	}

	/**
	 * Taxonomy changed, so the mirrored `terms` array is stale.
	 *
	 * @param int    $object_id Post ID.
	 * @param array  $terms     Terms set. Unused.
	 * @param array  $tt_ids    Term taxonomy IDs. Unused.
	 * @param string $taxonomy  Taxonomy name.
	 */
	public function on_set_object_terms( int $object_id, $terms, $tt_ids, $taxonomy ): void {
		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_relevant( $post ) ) {
			return;
		}

		// Private taxonomies are not mapped, so a change to one cannot affect the
		// mirrored row.
		$taxonomy_object = get_taxonomy( (string) $taxonomy );

		if ( ! $taxonomy_object || ! $taxonomy_object->public ) {
			return;
		}

		if ( ! $this->engine->should_mirror( $post ) ) {
			return;
		}

		$this->queue->enqueue( $object_id, Queue::ACTION_UPSERT );
	}

	/**
	 * Meta changed — but only re-sync for keys on the allowlist.
	 *
	 * Without the allowlist check this would fire on every `_edit_lock`,
	 * `_edit_last` and page-builder cache write, which is to say constantly.
	 *
	 * @param int|array $meta_id  Meta ID(s). Unused.
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public function on_post_meta_change( $meta_id, $post_id, $meta_key ): void {
		$post_id  = (int) $post_id;
		$meta_key = (string) $meta_key;

		// Our own hash meta must never trigger a sync: storing the hash after a
		// successful sync would immediately queue another one.
		if ( PostMapper::HASH_META_KEY === $meta_key ) {
			return;
		}

		if ( ! in_array( $meta_key, $this->settings->synced_meta_keys(), true ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_relevant( $post ) ) {
			return;
		}

		if ( ! $this->engine->should_mirror( $post ) ) {
			return;
		}

		$this->queue->enqueue( $post_id, Queue::ACTION_UPSERT );
	}

	/**
	 * The guards from SPEC §5.1 — the classic WordPress hook bugs.
	 *
	 * Revisions and autosaves are separate post rows that shadow the real one;
	 * syncing them would mirror an editor's in-progress keystrokes and, worse,
	 * write them under the revision's own ID.
	 */
	private function is_relevant( \WP_Post $post ): bool {
		$post_id = (int) $post->ID;

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return false;
		}

		return in_array( $post->post_type, $this->settings->synced_post_types(), true );
	}
}
