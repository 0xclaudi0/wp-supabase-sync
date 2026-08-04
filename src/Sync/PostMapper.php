<?php
/**
 * Maps a WP_Post onto a row for the Supabase mirror table.
 *
 * This class is the single source of truth for the shape of the mirror. The
 * migration SQL is generated from the field list below (see Cli\Commands and
 * `wp supabase schema --print`), so the table and the mapper cannot drift apart
 * by hand-editing one of them.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

use WPSupabaseSync\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class PostMapper {

	/**
	 * Post meta key holding the hash of the last successfully synced row.
	 *
	 * Underscore-prefixed so it stays out of the custom fields UI.
	 */
	public const HASH_META_KEY = '_wpsb_hash';

	/**
	 * Columns, in table order, with their Postgres types.
	 *
	 * `id` and `synced_at` are excluded deliberately: the database owns both.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'site_id'            => 'text        not null',
		'wp_id'              => 'bigint      not null',
		'post_type'          => 'text        not null',
		'status'             => 'text        not null',
		'slug'               => 'text        not null',
		'title'              => 'text',
		'excerpt'            => 'text',
		'content_html'       => 'text',
		'author_name'        => 'text',
		'featured_image_url' => 'text',
		'url'                => 'text',
		'published_at'       => 'timestamptz',
		'modified_at'        => 'timestamptz',
		'meta'               => "jsonb       not null default '{}'::jsonb",
		'terms'              => "jsonb       not null default '[]'::jsonb",
		'content_hash'       => 'text        not null',
	);

	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Every column the mapper writes, in table order.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array_keys( self::COLUMNS );
	}

	/**
	 * Column definitions for schema generation.
	 *
	 * @return array<string, string>
	 */
	public static function column_definitions(): array {
		return self::COLUMNS;
	}

	/**
	 * Build the row for a post.
	 *
	 * @param \WP_Post $post Post to map.
	 * @return array<string, mixed>
	 */
	public function map( \WP_Post $post ): array {
		$row = array(
			'site_id'            => $this->settings->site_id(),
			'wp_id'              => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'status'             => (string) $post->post_status,
			'slug'               => (string) $post->post_name,
			'title'              => $this->title( $post ),
			'excerpt'            => $this->excerpt( $post ),
			'content_html'       => $this->content( $post ),
			'author_name'        => $this->author_name( $post ),
			'featured_image_url' => $this->featured_image_url( $post ),
			'url'                => (string) get_permalink( $post ),
			'published_at'       => $this->timestamp( $post->post_date_gmt ),
			'modified_at'        => $this->timestamp( $post->post_modified_gmt ),
			'meta'               => $this->meta( $post ),
			'terms'              => $this->terms( $post ),
		);

		$row['content_hash'] = self::hash( $row );

		return $row;
	}

	/**
	 * Hash of a mapped row, used to skip no-op syncs.
	 *
	 * `content_hash` and `synced_at` are removed before hashing: the former
	 * because it is the output, the latter because it changes on every write and
	 * would make every row look modified. Keys are sorted so a reordering of the
	 * mapper cannot invalidate every stored hash on the next release.
	 *
	 * @param array<string, mixed> $row Mapped row.
	 */
	public static function hash( array $row ): string {
		unset( $row['content_hash'], $row['synced_at'] );

		ksort( $row );

		return sha1( (string) wp_json_encode( $row ) );
	}

	/**
	 * The hash stored for a post at its last successful sync.
	 */
	public static function stored_hash( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::HASH_META_KEY, true );
	}

	public static function store_hash( int $post_id, string $hash ): void {
		update_post_meta( $post_id, self::HASH_META_KEY, $hash );
	}

	public static function clear_hash( int $post_id ): void {
		delete_post_meta( $post_id, self::HASH_META_KEY );
	}

	/**
	 * Post title with shortcodes and entities resolved the way a reader sees it.
	 */
	private function title( \WP_Post $post ): string {
		// Deliberately not the_title: that filter is theme territory and can
		// inject markup that has no business in a data mirror.
		return wp_strip_all_tags( (string) $post->post_title );
	}

	/**
	 * Rendered post content.
	 *
	 * `the_content` is applied so blocks, shortcodes and embeds resolve to the
	 * HTML a visitor would see. That is the point of the mirror: the consuming
	 * frontend is not running WordPress and cannot render a block comment.
	 *
	 * The filter is applied outside the loop, so anything depending on global
	 * post state is set up first.
	 */
	private function content( \WP_Post $post ): string {
		$content = (string) $post->post_content;

		if ( '' === trim( $content ) ) {
			return '';
		}

		$previous = $GLOBALS['post'] ?? null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		/*
		 * Applying a core filter, not declaring one of our own, so the plugin
		 * prefix rule does not apply here.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$rendered = apply_filters( 'the_content', $content );

		wp_reset_postdata();

		if ( null === $previous ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		return (string) $rendered;
	}

	/**
	 * Excerpt, falling back to a trimmed version of the content.
	 *
	 * Note that get_the_excerpt() would be the obvious call, but it needs global post
	 * state and returns the password-protected placeholder for protected posts.
	 * Building it explicitly keeps the mapper predictable.
	 */
	private function excerpt( \WP_Post $post ): string {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( (string) $post->post_excerpt );
		}

		$content = strip_shortcodes( (string) $post->post_content );
		$content = excerpt_remove_blocks( $content );

		return wp_trim_words( wp_strip_all_tags( $content ), 55, '…' );
	}

	private function author_name( \WP_Post $post ): ?string {
		$name = get_the_author_meta( 'display_name', (int) $post->post_author );

		return '' === (string) $name ? null : (string) $name;
	}

	private function featured_image_url( \WP_Post $post ): ?string {
		$thumbnail_id = get_post_thumbnail_id( $post );

		if ( ! $thumbnail_id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( (int) $thumbnail_id, 'full' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Convert a WordPress GMT datetime string to an ISO 8601 timestamp.
	 *
	 * WordPress writes `0000-00-00 00:00:00` for "no date", which Postgres
	 * rejects outright as a timestamptz. It has to become NULL.
	 */
	private function timestamp( ?string $gmt_date ): ?string {
		$gmt_date = (string) $gmt_date;

		if ( '' === $gmt_date || str_starts_with( $gmt_date, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $gmt_date . ' UTC' );

		return false === $timestamp ? null : gmdate( 'c', $timestamp );
	}

	/**
	 * Allowlisted post meta.
	 *
	 * Only keys the user explicitly listed. Values are cast to something JSON
	 * can hold: a serialized PHP object in postmeta is not portable to a
	 * frontend that is not running PHP, so arrays pass through and everything
	 * else becomes a string.
	 *
	 * @return array<string, mixed>
	 */
	private function meta( \WP_Post $post ): array {
		$meta = array();

		foreach ( $this->settings->synced_meta_keys() as $key ) {
			$value = get_post_meta( (int) $post->ID, $key, true );

			if ( '' === $value || null === $value ) {
				continue;
			}

			$meta[ $key ] = is_scalar( $value ) ? $value : $this->jsonable( $value );
		}

		return $meta;
	}

	/**
	 * Flatten a meta value into something JSON can hold.
	 *
	 * @param mixed $value Meta value of unknown shape.
	 * @return mixed Something json_encode can represent losslessly.
	 */
	private function jsonable( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'jsonable' ), $value );
		}

		if ( is_object( $value ) ) {
			return array_map( array( $this, 'jsonable' ), get_object_vars( $value ) );
		}

		return is_scalar( $value ) ? $value : null;
	}

	/**
	 * Terms across every public taxonomy for the post type.
	 *
	 * Shape is a flat array of objects rather than a map keyed by taxonomy,
	 * because the table has a GIN index on `terms` and a flat array is what
	 * PostgREST's `cs.` containment operator queries naturally:
	 *
	 *     ?terms=cs.[{"taxonomy":"category","slug":"news"}]
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$out        = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$out[] = array(
					'taxonomy' => $taxonomy->name,
					'term_id'  => (int) $term->term_id,
					'slug'     => (string) $term->slug,
					'name'     => (string) $term->name,
				);
			}
		}

		return $out;
	}
}
