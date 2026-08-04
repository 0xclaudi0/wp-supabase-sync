<?php
/**
 * M2–M4 verification: mapper, sync lifecycle, queue, backoff, backfill.
 *
 * Runs against the real local Supabase stack and asserts on what actually landed
 * in the mirror table, not on what the plugin reported.
 *
 *   tests/wp.sh sync-verify
 *
 * Exits non-zero if any check fails.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Tests;

use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Sync\PostMapper;
use WPSupabaseSync\Sync\Queue;

use function WPSupabaseSync\plugin;

defined( 'ABSPATH' ) || exit;

const SERVICE_JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU';
const ANON_JWT    = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0';
const BASE_URL    = 'http://host.docker.internal:54321';
const SITE_ID     = 'sync-verify';

$failures = 0;
$checks   = 0;

$check = static function ( string $label, bool $passed, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;

	if ( ! $passed ) {
		++$failures;
	}

	printf( "%s %s\n", $passed ? '  PASS' : '  FAIL', $label );

	if ( '' !== $detail ) {
		printf( "       %s\n", $detail );
	}
};

$heading = static function ( string $text ): void {
	printf( "\n== %s\n", $text );
};

/* -------------------------------------------------------------------------
 * Setup: a known configuration and a clean slice of the mirror table.
 * ---------------------------------------------------------------------- */

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => BASE_URL,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => SITE_ID,
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'synced_meta_keys'  => "subtitle\nreading_time",
			'batch_size'        => 50,
			'sync_enabled'      => '1',
		)
	)
);

$plugin   = plugin();
$settings = new Settings();
$client   = new SupabaseClient( $settings, $plugin->logger() );
$mapper   = new PostMapper( $settings );
$queue    = $plugin->queue();

// Rebuild the engine so it holds the settings we just wrote.
$engine = new \WPSupabaseSync\Sync\SyncEngine( $settings, $client, $mapper, $queue, $plugin->logger() );

$queue->clear();

/**
 * Delete every mirror row for this test's site_id.
 */
$wipe = static function () use ( $client ): void {
	$client->request(
		'DELETE',
		'wp_content',
		array(
			'query'   => array( 'site_id' => 'eq.' . SITE_ID ),
			'headers' => array( 'Prefer' => 'return=minimal' ),
		)
	);
};

/**
 * Fetch one mirror row by post ID.
 *
 * @return array<string, mixed>|null
 */
$fetch = static function ( int $post_id ) use ( $client ): ?array {
	$response = $client->fetch_by_wp_ids( array( $post_id ) );
	$rows     = is_array( $response->json ) ? $response->json : array();

	return $rows[0] ?? null;
};

/**
 * Count mirror rows for this site.
 */
$count = static function () use ( $client ): int {
	$response = $client->select(
		array(
			'site_id' => 'eq.' . SITE_ID,
			'select'  => 'wp_id',
			'limit'   => 2000,
		)
	);

	return is_array( $response->json ) ? count( $response->json ) : 0;
};

$wipe();

$check( 'the mirror starts empty for this site_id', 0 === $count() );

/* -------------------------------------------------------------------------
 * 1. PostMapper.
 * ---------------------------------------------------------------------- */

$heading( 'PostMapper' );

$category_id = wp_create_category( 'Verify Category' );

// Pretty permalinks, so `url` is a real URL rather than ?p=123. A default
// container has no permalink structure at all, which would make the URL
// assertion below vacuous.
global $wp_rewrite;

update_option( 'permalink_structure', '/%postname%/' );
$wp_rewrite->init();
$wp_rewrite->flush_rules();

// An explicit author: wp_insert_post leaves post_author at 0 when there is no
// current user, as in WP-CLI, and the mapper correctly maps that to null.
$author_id = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 1 );

$post_id = wp_insert_post(
	array(
		'post_title'    => 'Mapper Test Post',
		'post_content'  => "Hello <strong>world</strong>.\n\n[caption]shortcode[/caption]",
		'post_excerpt'  => 'A hand-written excerpt.',
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_name'     => 'mapper-test-post',
		'post_author'   => $author_id,
		'post_category' => array( $category_id ),
	)
);

update_post_meta( $post_id, 'subtitle', 'The Subtitle' );
update_post_meta( $post_id, 'secret_internal_key', 'must-not-be-mirrored' );

$post = get_post( $post_id );
$row  = $mapper->map( $post );

$check( 'site_id comes from settings', SITE_ID === $row['site_id'], (string) $row['site_id'] );
$check( 'wp_id is the post ID', $post_id === $row['wp_id'] );
$check( 'post_type mapped', 'post' === $row['post_type'] );
$check( 'status mapped', 'publish' === $row['status'] );
$check( 'slug mapped', 'mapper-test-post' === $row['slug'], (string) $row['slug'] );
$check( 'title is plain text', 'Mapper Test Post' === $row['title'], (string) $row['title'] );
$check( 'excerpt uses the hand-written one', 'A hand-written excerpt.' === $row['excerpt'], (string) $row['excerpt'] );
$check( 'content_html is rendered, not raw', str_contains( (string) $row['content_html'], '<strong>world</strong>' ) );
$check( 'author_name resolved', '' !== (string) $row['author_name'], (string) $row['author_name'] );
$check( 'url is the pretty permalink', str_contains( (string) $row['url'], 'mapper-test-post' ), (string) $row['url'] );
$authorless     = new \WP_Post( (object) array_merge( $post->to_array(), array( 'post_author' => 0 ) ) );
$authorless_row = $mapper->map( $authorless );
$check(
	'a post with no author maps author_name to null rather than an empty string',
	null === $authorless_row['author_name'],
	var_export( $authorless_row['author_name'], true )
);
$check( 'published_at is ISO 8601', 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T/', (string) $row['published_at'] ), (string) $row['published_at'] );
$check( 'allowlisted meta is included', 'The Subtitle' === ( $row['meta']['subtitle'] ?? null ) );
$check( 'non-allowlisted meta is excluded', ! array_key_exists( 'secret_internal_key', $row['meta'] ) );
$check( 'terms include the category', ! empty( array_filter( $row['terms'], static fn( $t ): bool => 'Verify Category' === $t['name'] ) ), (string) wp_json_encode( $row['terms'] ) );
$check( 'content_hash is a sha1', 1 === preg_match( '/^[0-9a-f]{40}$/', (string) $row['content_hash'] ) );

// Hash stability: same input, same hash; and key order must not matter.
$again = $mapper->map( get_post( $post_id ) );
$check( 'hash is stable across identical maps', $row['content_hash'] === $again['content_hash'] );

$shuffled = array_reverse( $row, true );
$check( 'hash ignores key order', PostMapper::hash( $row ) === PostMapper::hash( $shuffled ) );

$with_synced_at = array_merge( $row, array( 'synced_at' => gmdate( 'c' ) ) );
$check( 'hash ignores synced_at', PostMapper::hash( $row ) === PostMapper::hash( $with_synced_at ) );

$check(
	'a timestamp of 0000-00-00 becomes null rather than breaking Postgres',
	null === ( new \ReflectionMethod( $mapper, 'timestamp' ) )->invoke( $mapper, '0000-00-00 00:00:00' )
);

/* -------------------------------------------------------------------------
 * 2. Sync lifecycle: publish, edit, unpublish, delete.
 * ---------------------------------------------------------------------- */

$heading( 'Sync lifecycle (§9.2)' );

$result = $engine->sync_post( $post_id );
$check( 'publish: sync reports success', $result->success, $result->action . ' ' . $result->message );

$mirrored = $fetch( $post_id );
$check( 'publish: the row landed in Supabase', null !== $mirrored );
$check( 'publish: title matches', 'Mapper Test Post' === ( $mirrored['title'] ?? null ) );
$check( 'publish: status is publish', 'publish' === ( $mirrored['status'] ?? null ) );
$check( 'publish: terms survived the round trip', is_array( $mirrored['terms'] ?? null ) && count( $mirrored['terms'] ) > 0 );
$check( 'publish: meta survived the round trip', 'The Subtitle' === ( $mirrored['meta']['subtitle'] ?? null ) );
$check( 'publish: hash was stored in post meta', '' !== PostMapper::stored_hash( $post_id ) );

$first_hash = (string) $mirrored['content_hash'];

// A second sync with nothing changed must not send a request.
$result = $engine->sync_post( $post_id );
$check( 'no-op: an unchanged post is skipped', $result->skipped, $result->message );

// --force must bypass the hash.
$result = $engine->sync_post( $post_id, true );
$check( 'no-op: --force syncs anyway', $result->success && ! $result->skipped );

// Edit.
wp_update_post(
	array(
		'ID'         => $post_id,
		'post_title' => 'Mapper Test Post (edited)',
	)
);

$result   = $engine->sync_post( $post_id );
$mirrored = $fetch( $post_id );

$check( 'edit: sync succeeded', $result->success, $result->message );
$check( 'edit: title updated in the mirror', 'Mapper Test Post (edited)' === ( $mirrored['title'] ?? null ) );
$check( 'edit: content_hash changed', ( $mirrored['content_hash'] ?? '' ) !== $first_hash );
$check( 'edit: still exactly one row (upsert, not insert)', 1 === $count() );

// Unpublish → the row must be removed, not left behind as a draft.
wp_update_post(
	array(
		'ID'          => $post_id,
		'post_status' => 'draft',
	)
);

$result = $engine->sync_post( $post_id );
$check( 'unpublish: sync reports a delete', $result->success && 'delete' === $result->action, $result->action );
$check( 'unpublish: the row is gone from Supabase', null === $fetch( $post_id ) );
$check( 'unpublish: the stored hash was cleared', '' === PostMapper::stored_hash( $post_id ) );

// Republish, then hard delete.
wp_update_post(
	array(
		'ID'          => $post_id,
		'post_status' => 'publish',
	)
);
$engine->sync_post( $post_id );
$check( 'republish: the row is back', null !== $fetch( $post_id ) );

wp_delete_post( $post_id, true );
$engine->delete_posts( array( $post_id ) );
$check( 'delete: the row is gone', null === $fetch( $post_id ) );

/* -------------------------------------------------------------------------
 * 3. Hooks feed the queue, and coalesce.
 * ---------------------------------------------------------------------- */

$heading( 'Hooks and coalescing' );

$queue->clear();

$hook_post = wp_insert_post(
	array(
		'post_title'   => 'Hook Test',
		'post_content' => 'Body',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

$check( 'publishing a post enqueues one row', 1 === $queue->depth(), (string) $queue->depth() );

// Many events on the same post must stay one row. This is the property that
// keeps a bulk edit from producing thousands of queue rows.
for ( $i = 0; $i < 15; $i++ ) {
	wp_update_post(
		array(
			'ID'         => $hook_post,
			'post_title' => 'Hook Test ' . $i,
		)
	);
	wp_set_post_categories( $hook_post, array( $category_id ) );
	update_post_meta( $hook_post, 'subtitle', 'v' . $i );
}

$check(
	'15 edits plus term and meta changes still coalesce to one queue row',
	1 === $queue->depth(),
	$queue->depth() . ' rows'
);

// A revision must never be queued in its own right.
$revision_id = wp_save_post_revision( $hook_post );
$check( 'saving a revision does not add a queue row', 1 === $queue->depth(), (string) $queue->depth() );

// Meta outside the allowlist must not trigger anything.
$queue->clear();
update_post_meta( $hook_post, 'not_allowlisted', 'x' );
$check( 'non-allowlisted meta does not enqueue', 0 === $queue->depth(), (string) $queue->depth() );

// Our own hash meta must not cause a sync loop.
update_post_meta( $hook_post, PostMapper::HASH_META_KEY, 'deadbeef' );
$check( 'writing the internal hash meta does not enqueue', 0 === $queue->depth(), (string) $queue->depth() );

// Unpublishing enqueues a delete.
wp_update_post(
	array(
		'ID'          => $hook_post,
		'post_status' => 'draft',
	)
);
$rows = $queue->all();
$check(
	'unpublishing enqueues a delete',
	1 === count( $rows ) && Queue::ACTION_DELETE === $rows[0]['action'],
	(string) wp_json_encode( array_map( static fn( $r ) => $r['action'], $rows ) )
);

// Repeatedly saving a draft that was never published should not queue deletes.
$queue->clear();
$draft_id = wp_insert_post(
	array(
		'post_title'  => 'Never Published',
		'post_status' => 'draft',
		'post_type'   => 'post',
	)
);
wp_update_post(
	array(
		'ID'         => $draft_id,
		'post_title' => 'Never Published, edited',
	)
);
$check( 'a draft that was never published never enters the queue', 0 === $queue->depth(), (string) $queue->depth() );

/* -------------------------------------------------------------------------
 * 4. Batch processing through the queue.
 * ---------------------------------------------------------------------- */

$heading( 'Queue processing' );

$wipe();
$queue->clear();

$batch_ids = array();

for ( $i = 0; $i < 12; $i++ ) {
	$batch_ids[] = wp_insert_post(
		array(
			'post_title'   => 'Batch Post ' . $i,
			'post_content' => 'Content ' . $i,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}

$check( '12 published posts produce 12 queue rows', 12 === $queue->depth(), (string) $queue->depth() );

$stats = $engine->process_queue();

$check( 'the batch processed successfully', 12 === $stats['succeeded'], (string) wp_json_encode( $stats ) );
$check( 'the queue drained', 0 === $queue->depth(), (string) $queue->depth() );
$check( 'all 12 rows are in Supabase', 12 === $count(), (string) $count() );

// Re-queue the same posts unchanged: they should be skipped without a request.
foreach ( $batch_ids as $id ) {
	$queue->enqueue( (int) $id, Queue::ACTION_UPSERT );
}

$stats = $engine->process_queue();
$check( 'unchanged posts are skipped rather than re-sent', 12 === $stats['skipped'], (string) wp_json_encode( $stats ) );

// A queued upsert for a post that no longer qualifies becomes a delete.
wp_update_post(
	array(
		'ID'          => $batch_ids[0],
		'post_status' => 'draft',
	)
);
$queue->clear();
$queue->enqueue( (int) $batch_ids[0], Queue::ACTION_UPSERT );
$engine->process_queue();

$check(
	'a queued upsert for a now-unpublished post removes the mirror row',
	null === $fetch( (int) $batch_ids[0] )
);
$check( 'the rest of the rows are untouched', 11 === $count(), (string) $count() );

/* -------------------------------------------------------------------------
 * 5. Backoff, retry and dead-letter.
 * ---------------------------------------------------------------------- */

$heading( 'Backoff and dead-letter' );

$check( 'backoff attempt 1 is 2 minutes', 120 === Queue::backoff_seconds( 1 ), (string) Queue::backoff_seconds( 1 ) );
$check( 'backoff attempt 2 is 4 minutes', 240 === Queue::backoff_seconds( 2 ) );
$check( 'backoff attempt 5 is 32 minutes', 1920 === Queue::backoff_seconds( 5 ) );
$check( 'backoff caps at 1 hour', 3600 === Queue::backoff_seconds( 8 ), (string) Queue::backoff_seconds( 8 ) );
$check( 'backoff cannot overflow at absurd attempt counts', 3600 === Queue::backoff_seconds( 999 ) );

// A transport failure must be retried, growing the delay each time.
$broken_settings = new Settings();
update_option(
	Settings::OPTION,
	array_merge( (array) get_option( Settings::OPTION ), array( 'project_url' => 'http://127.0.0.1:1' ) )
);
$broken_settings->refresh();
$broken_client = new SupabaseClient( $broken_settings, $plugin->logger() );
$broken_engine = new \WPSupabaseSync\Sync\SyncEngine( $broken_settings, $broken_client, new PostMapper( $broken_settings ), $queue, $plugin->logger() );

$queue->clear();
$retry_post = (int) $batch_ids[1];
PostMapper::clear_hash( $retry_post );
$queue->enqueue( $retry_post, Queue::ACTION_UPSERT );

global $wpdb;
$queue_table = \WPSupabaseSync\Installer::queue_table();
$attempts    = array();

for ( $i = 1; $i <= Queue::MAX_ATTEMPTS; $i++ ) {
	$broken_engine->process_queue();

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, status, available_at FROM `{$queue_table}` WHERE object_id = %d", $retry_post ), ARRAY_A );

	if ( null === $row ) {
		break;
	}

	$attempts[] = (int) $row['attempts'];

	// Simulate the backoff window elapsing so the next claim can pick it up.
	if ( Queue::STATUS_DEAD !== $row['status'] ) {
		$wpdb->update( $queue_table, array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'object_id' => $retry_post ), array( '%s' ), array( '%d' ) );
	}
}

$final = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, status, last_error FROM `{$queue_table}` WHERE object_id = %d", $retry_post ), ARRAY_A );

$check( 'attempts increment on each failure', array( 1, 2, 3, 4, 5, 6, 7, 8 ) === $attempts, implode( ',', $attempts ) );
$check( 'the row is dead-lettered after 8 attempts', Queue::STATUS_DEAD === ( $final['status'] ?? '' ), (string) ( $final['status'] ?? 'gone' ) );
$check( 'the stored error is a translated explanation, not a raw dump', str_contains( (string) ( $final['last_error'] ?? '' ), 'could not reach Supabase' ), (string) ( $final['last_error'] ?? '' ) );
$check( 'dead rows are no longer claimable', 0 === count( $queue->claim( 10 ) ) );
$check( 'stats report the dead-letter', 1 === $queue->stats()['dead'] );
$check( 'retry --all makes it claimable again', 1 === $queue->retry_all() );
$check( 'after retry it is pending with attempts reset', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM `{$queue_table}` WHERE object_id = %d", $retry_post ) ) );

// A permanent failure must not burn eight retries.
$queue->clear();
update_option(
	Settings::OPTION,
	array_merge(
		(array) get_option( Settings::OPTION ),
		array(
			'project_url'      => BASE_URL,
			'service_role_key' => 'not-a-jwt',
		)
	)
);
$bad_key_settings = new Settings();
$bad_key_engine   = new \WPSupabaseSync\Sync\SyncEngine(
	$bad_key_settings,
	new SupabaseClient( $bad_key_settings, $plugin->logger() ),
	new PostMapper( $bad_key_settings ),
	$queue,
	$plugin->logger()
);

PostMapper::clear_hash( $retry_post );
$queue->enqueue( $retry_post, Queue::ACTION_UPSERT );
$bad_key_engine->process_queue();

$permanent = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, status, last_error FROM `{$queue_table}` WHERE object_id = %d", $retry_post ), ARRAY_A );

$check(
	'a rejected key dead-letters on the first attempt instead of retrying',
	Queue::STATUS_DEAD === ( $permanent['status'] ?? '' ) && 1 === (int) ( $permanent['attempts'] ?? 0 ),
	sprintf( 'status=%s attempts=%s', (string) ( $permanent['status'] ?? '' ), (string) ( $permanent['attempts'] ?? '' ) )
);
$check(
	'the dead-letter error names the key as the cause',
	str_contains( strtolower( (string) ( $permanent['last_error'] ?? '' ) ), 'key' ),
	(string) ( $permanent['last_error'] ?? '' )
);
$check( 'no key in the stored error', ! str_contains( (string) ( $permanent['last_error'] ?? '' ), SERVICE_JWT ) );

/* -------------------------------------------------------------------------
 * 6. Backfill at scale.
 * ---------------------------------------------------------------------- */

$heading( 'Backfill (100 posts)' );

// Restore a working configuration.
update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => BASE_URL,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => SITE_ID,
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'synced_meta_keys'  => "subtitle\nreading_time",
			'batch_size'        => 50,
			'sync_enabled'      => '1',
		)
	)
);

$good_settings = new Settings();
$good_client   = new SupabaseClient( $good_settings, $plugin->logger() );
$good_engine   = new \WPSupabaseSync\Sync\SyncEngine( $good_settings, $good_client, new PostMapper( $good_settings ), $queue, $plugin->logger() );
$backfill      = new \WPSupabaseSync\Sync\Backfill( $good_settings, $queue );

$wipe();
$queue->clear();

$bulk_ids = array();

for ( $i = 0; $i < 100; $i++ ) {
	$bulk_ids[] = wp_insert_post(
		array(
			'post_title'   => 'Bulk ' . $i,
			'post_content' => 'Bulk content ' . $i,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $author_id,
		)
	);
}

/*
 * Creating 100 posts fires several hooks per post — and more than one of them
 * enqueues. wp_insert_post assigns the default category, which fires
 * set_object_terms in addition to transition_post_status, so each post is
 * enqueued twice over. Coalescing is what makes that a non-event, and this
 * assertion is really a test of the unique key on (object_type, object_id).
 */
$check(
	'200+ hook firings across 100 posts coalesce to exactly 100 queue rows',
	100 === $queue->depth(),
	$queue->depth() . ' rows'
);

// Clear it so the backfill, not the hooks, is what populates the queue below.
$queue->clear();

$enqueued = 0;
$pages    = 0;

do {
	$batch     = $backfill->run_batch( 'post', true );
	$enqueued += $batch['enqueued'];
	++$pages;
} while ( ! $batch['done'] && $pages < 20 );

$check( 'the backfill enqueued every eligible post', $enqueued >= 100, $enqueued . ' enqueued over ' . $pages . ' page(s)' );
$check( 'backfill progress was cleared when it finished', null === $backfill->progress() );

$total_synced = 0;
$requests     = 0;

while ( $requests < 20 ) {
	$stats = $good_engine->process_queue();
	++$requests;

	if ( 0 === $stats['claimed'] ) {
		break;
	}

	$total_synced += $stats['succeeded'];
}

$check( 'the queue drained completely', 0 === $queue->depth(), (string) $queue->depth() );
$check(
	'all 100 posts reached Supabase',
	$count() >= 100,
	$count() . ' rows in the mirror'
);
$check(
	'batching kept the request count sane (batch size 50)',
	$requests <= 6,
	$requests . ' process_queue calls for ' . $total_synced . ' posts'
);

/* -------------------------------------------------------------------------
 * 7. Anon can read the published mirror, and only that.
 * ---------------------------------------------------------------------- */

$heading( 'Row level security on real synced data' );

$anon = $good_client->select(
	array(
		'site_id' => 'eq.' . SITE_ID,
		'select'  => 'wp_id,status',
		'limit'   => 500,
	),
	SupabaseClient::ROLE_ANON
);

$anon_rows = is_array( $anon->json ) ? $anon->json : array();

$check( 'the anon key can read the published rows', count( $anon_rows ) >= 100, count( $anon_rows ) . ' rows' );
$check(
	'every row the anon key sees is published',
	count( $anon_rows ) === count( array_filter( $anon_rows, static fn( $r ): bool => 'publish' === $r['status'] ) )
);

// Write a deliberately unpublished row with the service key and confirm anon
// cannot see it. This is the leak test on real data.
$good_client->insert(
	array(
		array(
			'site_id'      => SITE_ID,
			'wp_id'        => 999999,
			'post_type'    => 'post',
			'status'       => 'draft',
			'slug'         => 'leak-probe',
			'content_hash' => 'leak',
		),
	)
);

$leak = $good_client->select(
	array(
		'site_id' => 'eq.' . SITE_ID,
		'wp_id'   => 'eq.999999',
		'select'  => 'wp_id',
	),
	SupabaseClient::ROLE_ANON
);

$check(
	'the anon key cannot see an unpublished row',
	empty( $leak->json ),
	(string) wp_json_encode( $leak->json )
);

$good_client->delete_by_wp_ids( array( 999999 ) );

/* -------------------------------------------------------------------------
 * Cleanup.
 * ---------------------------------------------------------------------- */

$heading( 'Cleanup' );

$wipe();
$queue->clear();

foreach ( array_merge( $bulk_ids, $batch_ids, array( $hook_post, $draft_id ) ) as $id ) {
	if ( $id ) {
		wp_delete_post( (int) $id, true );
	}
}

$check( 'mirror rows for this test were removed', 0 === $count() );

printf( "\n%s\n", str_repeat( '-', 60 ) );
printf( "%d checks, %d failures\n", $checks, $failures );
printf( "WordPress %s, PHP %s\n", get_bloginfo( 'version' ), PHP_VERSION );

exit( $failures > 0 ? 1 : 0 );
