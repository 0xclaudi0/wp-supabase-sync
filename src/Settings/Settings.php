<?php
/**
 * Typed accessor over the plugin's stored settings and wp-config constants.
 *
 * Every other class reads configuration through here rather than touching
 * get_option directly, so the constant-before-option precedence for credentials
 * is expressed in exactly one place.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Settings;

use WPSupabaseSync\Support\Redactor;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'wpsb_settings';

	/**
	 * The wp-config.php constants, which always win over the database.
	 *
	 * Keeping credentials out of the options table keeps them out of DB dumps,
	 * out of automated backups, and out of the staging clone somebody made last
	 * month and forgot about.
	 */
	public const CONST_URL         = 'WPSB_PROJECT_URL';
	public const CONST_SERVICE_KEY = 'WPSB_SERVICE_ROLE_KEY';
	public const CONST_ANON_KEY    = 'WPSB_ANON_KEY';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_OPTION   = 'option';
	public const SOURCE_NONE     = 'none';

	public const MAX_BATCH_SIZE     = 500;
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Memoised option array. One read per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * Default values for every stored field.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'project_url'              => '',
			'service_role_key'         => '',
			'anon_key'                 => '',
			'site_id'                  => self::default_site_id(),
			'table_name'               => 'wp_content',
			'synced_post_types'        => array( 'post', 'page' ),
			'synced_meta_keys'         => array(),
			'batch_size'               => self::DEFAULT_BATCH_SIZE,
			'sync_enabled'             => false,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Default site identifier: the host of home_url(), normalised.
	 *
	 * This is what lets several WordPress installs — or a multisite network —
	 * share one Supabase project without overwriting each other's rows, since it
	 * is half of the (site_id, wp_id) upsert conflict target.
	 */
	public static function default_site_id(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $host ) {
			$host = 'WordPress';
		}

		return self::sanitize_site_id( $host );
	}

	/**
	 * Drop the memoised option so a later read sees a just-saved value.
	 */
	public function refresh(): void {
		$this->stored = null;
	}

	/**
	 * Every stored value, merged over the defaults. Memoised per request.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->stored ) {
			$stored = get_option( self::OPTION, array() );

			$this->stored = array_merge(
				self::defaults(),
				is_array( $stored ) ? $stored : array()
			);
		}

		return $this->stored;
	}

	public function project_url(): string {
		if ( defined( self::CONST_URL ) && is_string( constant( self::CONST_URL ) ) ) {
			return $this->normalize_url( (string) constant( self::CONST_URL ) );
		}

		return $this->normalize_url( (string) $this->all()['project_url'] );
	}

	public function service_role_key(): string {
		return $this->credential( self::CONST_SERVICE_KEY, 'service_role_key' );
	}

	public function anon_key(): string {
		return $this->credential( self::CONST_ANON_KEY, 'anon_key' );
	}

	/**
	 * Where the service role key came from.
	 *
	 * The settings page and Notices use this to decide whether to nag: a key in
	 * the options table works fine but is a liability, and the user deserves to
	 * be told once, clearly, rather than discovering it in a leaked backup.
	 */
	public function service_key_source(): string {
		return $this->source( self::CONST_SERVICE_KEY, 'service_role_key' );
	}

	public function anon_key_source(): string {
		return $this->source( self::CONST_ANON_KEY, 'anon_key' );
	}

	public function url_source(): string {
		return $this->source( self::CONST_URL, 'project_url' );
	}

	public function site_id(): string {
		$site_id = self::sanitize_site_id( (string) $this->all()['site_id'] );

		return '' === $site_id ? self::default_site_id() : $site_id;
	}

	public function table_name(): string {
		$table = self::sanitize_table_name( (string) $this->all()['table_name'] );

		return '' === $table ? 'wp_content' : $table;
	}

	/**
	 * Post types the user chose to mirror.
	 *
	 * @return string[]
	 */
	public function synced_post_types(): array {
		$types = $this->all()['synced_post_types'];

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
	}

	/**
	 * Meta keys allowed into the mirror.
	 *
	 * An allowlist rather than "everything not starting with an underscore":
	 * postmeta routinely holds page-builder blobs, licence keys and third-party
	 * plugin state, none of which belongs in a table a public frontend reads.
	 *
	 * @return string[]
	 */
	public function synced_meta_keys(): array {
		$keys = $this->all()['synced_meta_keys'];

		return is_array( $keys ) ? array_values( array_filter( array_map( 'strval', $keys ) ) ) : array();
	}

	public function batch_size(): int {
		$size = (int) $this->all()['batch_size'];

		if ( $size < 1 ) {
			$size = self::DEFAULT_BATCH_SIZE;
		}

		return min( $size, self::MAX_BATCH_SIZE );
	}

	public function sync_enabled(): bool {
		return (bool) $this->all()['sync_enabled'];
	}

	public function delete_data_on_uninstall(): bool {
		return (bool) $this->all()['delete_data_on_uninstall'];
	}

	/**
	 * Whether there is enough configuration to attempt a request at all.
	 */
	public function is_configured(): bool {
		return '' !== $this->project_url() && '' !== $this->service_role_key();
	}

	/**
	 * Base URL for PostgREST, with trailing slash.
	 */
	public function rest_base(): string {
		$url = $this->project_url();

		return '' === $url ? '' : $url . '/rest/v1/';
	}

	/**
	 * Read a credential, constant first, and register it with the Redactor.
	 *
	 * Registering on every read is the invariant that makes redaction reliable:
	 * any key this plugin has looked at is a key the Redactor knows to strip,
	 * regardless of what format Supabase issues it in.
	 */
	private function credential( string $constant, string $field ): string {
		$value = '';

		if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
			$value = trim( (string) constant( $constant ) );
		} else {
			$value = trim( (string) $this->all()[ $field ] );
		}

		if ( '' !== $value ) {
			Redactor::protect( $value );
		}

		return $value;
	}

	private function source( string $constant, string $field ): string {
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== trim( (string) constant( $constant ) ) ) {
			return self::SOURCE_CONSTANT;
		}

		if ( '' !== trim( (string) $this->all()[ $field ] ) ) {
			return self::SOURCE_OPTION;
		}

		return self::SOURCE_NONE;
	}

	/**
	 * Normalise a project URL: scheme + host + port, no trailing slash, no path.
	 *
	 * Users paste all sorts of things into this field — the Studio URL, a URL
	 * with `/rest/v1` already on the end, a bare host. Everything downstream
	 * appends `/rest/v1/…`, so a stray path would produce a 404 that looks like
	 * a missing table. Normalising here turns a whole class of support question
	 * into a non-event.
	 */
	private function normalize_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		// A bare host is a common paste. Assume https rather than rejecting it.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$normalized = $scheme . '://' . strtolower( (string) $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}

		return $normalized;
	}

	/**
	 * Sanitize the whole settings array. Used as the register_setting callback.
	 *
	 * Credential fields are special: an empty submission means "leave the stored
	 * key alone", because the form never renders the existing key back into the
	 * HTML and so cannot round-trip it.
	 *
	 * @param mixed $input Raw $_POST value for the option.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? array_merge( self::defaults(), $existing ) : self::defaults();

		$clean = self::defaults();

		/*
		 * Supply the scheme before esc_url_raw sees the value, not after.
		 * esc_url_raw defaults a scheme-less URL to http://, so a user pasting
		 * a bare `yourproject.supabase.co` would have their project silently
		 * downgraded to cleartext — and Supabase is https-only, so the result is
		 * a confusing connection failure rather than an obvious mistake.
		 */
		$raw_url = isset( $input['project_url'] ) ? trim( (string) $input['project_url'] ) : '';

		if ( '' !== $raw_url && ! preg_match( '#^https?://#i', $raw_url ) ) {
			$raw_url = 'https://' . ltrim( $raw_url, '/' );
		}

		$clean['project_url'] = '' === $raw_url
			? ''
			: esc_url_raw( $raw_url, array( 'http', 'https' ) );

		$clean['service_role_key'] = self::sanitize_credential(
			$input['service_role_key'] ?? '',
			(string) $existing['service_role_key'],
			! empty( $input['clear_service_role_key'] )
		);

		$clean['anon_key'] = self::sanitize_credential(
			$input['anon_key'] ?? '',
			(string) $existing['anon_key'],
			! empty( $input['clear_anon_key'] )
		);

		$site_id          = self::sanitize_site_id( (string) ( $input['site_id'] ?? '' ) );
		$clean['site_id'] = '' === $site_id ? self::default_site_id() : $site_id;

		$table               = self::sanitize_table_name( (string) ( $input['table_name'] ?? '' ) );
		$clean['table_name'] = '' === $table ? 'wp_content' : $table;

		// add_settings_error lives in wp-admin/includes/template.php, which is not
		// loaded for WP-CLI or a REST request. Sanitizing must work everywhere,
		// so the notice is best-effort.
		if ( '' !== (string) ( $input['table_name'] ?? '' ) && '' === $table && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'wpsb_table_name',
				__( 'Table name must be lowercase letters, numbers and underscores, starting with a letter or underscore. Reverted to wp_content.', 'wp-supabase-sync' ),
				'error'
			);
		}

		$clean['synced_post_types'] = self::sanitize_post_types( $input['synced_post_types'] ?? array() );
		$clean['synced_meta_keys']  = self::sanitize_meta_keys( (string) ( $input['synced_meta_keys'] ?? '' ) );

		$batch               = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : self::DEFAULT_BATCH_SIZE;
		$clean['batch_size'] = 0 === $batch ? self::DEFAULT_BATCH_SIZE : min( $batch, self::MAX_BATCH_SIZE );

		$clean['sync_enabled']             = ! empty( $input['sync_enabled'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Resolve a submitted credential against what is already stored.
	 *
	 * @param mixed  $submitted Raw submitted value.
	 * @param string $existing  Currently stored value.
	 * @param bool   $clear     Whether the user asked to remove the stored key.
	 */
	private static function sanitize_credential( $submitted, string $existing, bool $clear ): string {
		if ( $clear ) {
			return '';
		}

		$submitted = trim( (string) $submitted );

		// Blank means "unchanged", not "delete". Deleting needs the checkbox.
		return '' === $submitted ? $existing : sanitize_text_field( $submitted );
	}

	/**
	 * Site identifier: lowercase, alphanumeric plus dot, dash and underscore.
	 *
	 * Kept URL- and filter-safe because it is sent as a PostgREST query value
	 * (`site_id=eq.…`) on every delete.
	 */
	public static function sanitize_site_id( string $site_id ): string {
		$site_id = strtolower( trim( $site_id ) );
		$site_id = preg_replace( '/[^a-z0-9._-]+/', '-', $site_id );

		return trim( (string) $site_id, '-' );
	}

	/**
	 * Validate a Postgres table name.
	 *
	 * This value is interpolated into a request path, so it is validated against
	 * an allowlist rather than escaped. Anything that is not a plain unquoted
	 * identifier is rejected outright.
	 */
	public static function sanitize_table_name( string $table ): string {
		$table = strtolower( trim( $table ) );

		return preg_match( '/^[a-z_][a-z0-9_]{0,62}$/', $table ) ? $table : '';
	}

	/**
	 * Keep only post types that exist, so a stale setting cannot resurrect one.
	 *
	 * @param mixed $types Submitted post type list.
	 * @return string[]
	 */
	private static function sanitize_post_types( $types ): array {
		if ( ! is_array( $types ) ) {
			return array();
		}

		$clean = array();

		foreach ( $types as $type ) {
			$key = sanitize_key( (string) $type );

			if ( '' !== $key && post_type_exists( $key ) ) {
				$clean[] = $key;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Parse the one-key-per-line textarea into a list.
	 *
	 * @return string[]
	 */
	private static function sanitize_meta_keys( string $raw ): array {
		$lines = preg_split( '/\R/', $raw );
		$clean = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$key = trim( sanitize_text_field( $line ) );

			if ( '' !== $key ) {
				$clean[] = $key;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
