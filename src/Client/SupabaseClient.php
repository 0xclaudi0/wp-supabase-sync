<?php
/**
 * HTTP client for Supabase PostgREST.
 *
 * Everything goes over HTTPS through the WordPress HTTP API. There is
 * deliberately no raw Postgres connection here, for three reasons worth
 * repeating because they are also the answers to the obvious support question:
 *
 * 1. PHP's request-per-process model plus shared hosting exhausts a Postgres
 *    connection pool quickly. See gotcha 04 in the companion playbook.
 * 2. PostgREST over HTTPS needs no PHP extension. `pdo_pgsql` is frequently
 *    absent on managed WordPress hosts.
 * 3. Port 443 only, so it works behind restrictive egress firewalls.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Client;

use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Support\Logger;
use WPSupabaseSync\Support\Redactor;

use const WPSupabaseSync\VERSION;

defined( 'ABSPATH' ) || exit;

final class SupabaseClient {

	public const ROLE_SERVICE = 'service_role';
	public const ROLE_ANON    = 'anon';

	/**
	 * Send no credentials at all. Only the reachability probe uses this.
	 */
	public const ROLE_NONE = 'none';

	private const TIMEOUT = 15;

	/**
	 * Cap on how much of a response body is kept for logging.
	 *
	 * PostgREST error bodies are small, but a successful ping returns the whole
	 * OpenAPI document — several kilobytes of schema that would bloat every log
	 * row for no diagnostic value.
	 */
	private const MAX_LOGGED_BODY = 2000;

	public function __construct(
		private readonly Settings $settings,
		private readonly ?Logger $logger = null
	) {}

	/**
	 * Cheapest possible liveness check: GET the PostgREST root.
	 *
	 * What a 200 here does and does not prove — verified against the local CLI
	 * stack, because the distinction is not obvious:
	 *
	 * - It **does** prove WordPress can reach the project over HTTP.
	 * - It **does** prove the key we sent parses and verifies. PostgREST
	 *   validates a JWT whenever one is present, so an expired key, a mangled
	 *   key, or a key from a different project all return 401 PGRST301 here.
	 * - It does **not** prove the key has any table privileges, and it does not
	 *   prove the key is a *service* key: a valid anon key returns 200 too.
	 *   Privilege is proven by actually writing, which is what the diagnostics
	 *   `grants` and `write` checks do.
	 * - On the local stack it does not even prove a key was required: with no
	 *   key at all the root still returns 200, because the local API gateway
	 *   does not enforce key presence the way hosted Supabase does. Since this
	 *   client always sends a key, that quirk does not weaken the check.
	 *
	 * @param string $key_role Which configured key to authenticate with.
	 */
	public function ping( string $key_role = self::ROLE_SERVICE ): Response {
		return $this->request( 'GET', '', array( 'key_role' => $key_role ) );
	}

	/**
	 * Probe reachability without sending any credentials.
	 *
	 * This is what makes "unreachable" and "key rejected" two separate
	 * diagnoses instead of one ambiguous failure. Any HTTP answer at all —
	 * including a 401 — proves the host is there and speaking HTTP, which is
	 * exactly what a reachability check should establish and no more.
	 *
	 * Sending no key also means this works identically on hosted Supabase, where
	 * a missing key returns 401, and on the local stack, where it returns 200.
	 * Both are "reachable".
	 *
	 * @throws ApiException When there is no usable project URL.
	 */
	public function probe_reachable(): Response {
		return $this->request( 'GET', '', array( 'key_role' => self::ROLE_NONE ) );
	}

	/**
	 * Upsert a batch of rows.
	 *
	 * `on_conflict=site_id,wp_id` names the unique constraint, and
	 * `resolution=merge-duplicates` turns the insert into an upsert. Without
	 * both, a re-sync of an existing post returns `23505 duplicate key value`.
	 *
	 * `return=minimal` keeps Supabase from echoing every row back, which on a
	 * batch of 500 posts is a lot of content_html travelling home for nothing.
	 *
	 * @param array<int, array<string, mixed>> $rows Mapped rows.
	 * @throws ApiException When the request cannot be attempted.
	 */
	public function upsert( array $rows ): Response {
		if ( empty( $rows ) ) {
			return new Response( 200 );
		}

		return $this->request(
			'POST',
			$this->table_path(),
			array(
				'query'   => array( 'on_conflict' => 'site_id,wp_id' ),
				'headers' => array( 'Prefer' => 'resolution=merge-duplicates,return=minimal' ),
				'body'    => array_values( $rows ),
			)
		);
	}

	/**
	 * Insert rows without upsert semantics.
	 *
	 * Used by the diagnostics write probe, which wants a plain insert so that a
	 * privilege failure surfaces as a privilege failure rather than being
	 * absorbed by conflict resolution.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to insert.
	 * @throws ApiException When the request cannot be attempted.
	 */
	public function insert( array $rows ): Response {
		return $this->request(
			'POST',
			$this->table_path(),
			array(
				'headers' => array( 'Prefer' => 'return=minimal' ),
				'body'    => array_values( $rows ),
			)
		);
	}

	/**
	 * Delete rows for this site by WordPress post ID.
	 *
	 * Always scoped by site_id. Without that scope a second WordPress install
	 * sharing the project would delete the first one's rows whenever post IDs
	 * happened to collide, which they will.
	 *
	 * @param int[] $wp_ids Post IDs to remove.
	 * @throws ApiException When the request cannot be attempted.
	 */
	public function delete_by_wp_ids( array $wp_ids ): Response {
		$wp_ids = array_values( array_unique( array_map( 'intval', $wp_ids ) ) );

		if ( empty( $wp_ids ) ) {
			return new Response( 200 );
		}

		return $this->request(
			'DELETE',
			$this->table_path(),
			array(
				'query'   => array(
					'site_id' => 'eq.' . $this->settings->site_id(),
					'wp_id'   => 'in.(' . implode( ',', $wp_ids ) . ')',
				),
				'headers' => array( 'Prefer' => 'return=minimal' ),
			)
		);
	}

	/**
	 * Select from the mirror table.
	 *
	 * @param array<string, string|int> $query    PostgREST query parameters.
	 * @param string                    $key_role Which key to authenticate with.
	 * @throws ApiException When the request cannot be attempted.
	 */
	public function select( array $query = array(), string $key_role = self::ROLE_SERVICE ): Response {
		return $this->request(
			'GET',
			$this->table_path(),
			array(
				'key_role' => $key_role,
				'query'    => $query,
			)
		);
	}

	/**
	 * Rows for this site, by post ID. Convenience wrapper used by tests and CLI.
	 *
	 * @param int[] $wp_ids Post IDs.
	 * @throws ApiException When the request cannot be attempted.
	 */
	public function fetch_by_wp_ids( array $wp_ids, string $key_role = self::ROLE_SERVICE ): Response {
		$wp_ids = array_values( array_unique( array_map( 'intval', $wp_ids ) ) );

		return $this->select(
			array(
				'site_id' => 'eq.' . $this->settings->site_id(),
				'wp_id'   => 'in.(' . implode( ',', $wp_ids ) . ')',
				'order'   => 'wp_id.asc',
			),
			$key_role
		);
	}

	/**
	 * The validated table path segment.
	 *
	 * @throws ApiException When the configured table name is not a plain identifier.
	 */
	private function table_path(): string {
		$table = $this->settings->table_name();

		if ( '' === Settings::sanitize_table_name( $table ) ) {
			throw ApiException::invalid_table();
		}

		return $table;
	}

	/**
	 * Perform a request against PostgREST.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path below /rest/v1/, e.g. 'wp_content'. Empty for the root.
	 * @param array{
	 *     key_role?: string,
	 *     query?: array<string, string|int>,
	 *     body?: array<mixed>|string,
	 *     headers?: array<string, string>,
	 *     timeout?: int
	 * } $options Request options.
	 * @throws ApiException When the request cannot be attempted at all.
	 */
	public function request( string $method, string $path = '', array $options = array() ): Response {
		$key_role = (string) ( $options['key_role'] ?? self::ROLE_SERVICE );
		$url      = $this->url_for( $path, $options['query'] ?? array() );

		$headers = self::ROLE_NONE === $key_role
			? array( 'Accept' => 'application/json' )
			: $this->headers( $this->key_for( $key_role ) );

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => (int) ( $options['timeout'] ?? self::TIMEOUT ),
			'headers'     => array_merge( $headers, $options['headers'] ?? array() ),
			'user-agent'  => $this->user_agent(),
			// Redirects are never legitimate here. Following one would forward
			// the service role key to whatever host the redirect names.
			'redirection' => 0,
		);

		if ( isset( $options['body'] ) ) {
			$args['body'] = is_string( $options['body'] )
				? $options['body']
				: (string) wp_json_encode( $options['body'] );
		}

		$raw = wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			$response = Response::from_transport_error(
				(string) $raw->get_error_code(),
				Redactor::redact( (string) $raw->get_error_message() )
			);

			$this->log_failure( $method, $path, $key_role, $response );

			return $response;
		}

		$body    = (string) wp_remote_retrieve_body( $raw );
		$decoded = json_decode( $body, true );

		$response = new Response(
			(int) wp_remote_retrieve_response_code( $raw ),
			$body,
			$this->normalize_headers( $raw ),
			is_array( $decoded ) ? $decoded : null
		);

		if ( ! $response->is_success() ) {
			$this->log_failure( $method, $path, $key_role, $response );
		}

		return $response;
	}

	/**
	 * Resolve the configured key for a role.
	 *
	 * @throws ApiException When that key is not configured.
	 */
	private function key_for( string $role ): string {
		$key = self::ROLE_ANON === $role
			? $this->settings->anon_key()
			: $this->settings->service_role_key();

		if ( '' === $key ) {
			// Not output: exceptions here are caught by the diagnostics and CLI
			// layers and rendered through Check, which escapes on the way out.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw ApiException::missing_key( $role );
		}

		return $key;
	}

	/**
	 * Build the full request URL.
	 *
	 * @param array<string, string|int> $query Query parameters.
	 * @throws ApiException When the project URL is unusable.
	 */
	private function url_for( string $path, array $query = array() ): string {
		$base = $this->settings->rest_base();

		if ( '' === $base ) {
			throw ApiException::invalid_url();
		}

		$url = $base . ltrim( $path, '/' );

		if ( empty( $query ) ) {
			return $url;
		}

		/*
		 * Built by hand rather than with add_query_arg, which would encode the
		 * dots in PostgREST operators like `wp_id=in.(1,2,3)`. Only the value is
		 * encoded, and rawurlencode leaves the operator prefix intact while
		 * escaping the parentheses and commas PostgREST expects percent-encoded.
		 */
		$pairs = array();

		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}

		return $url . '?' . implode( '&', $pairs );
	}

	/**
	 * Headers sent on every request.
	 *
	 * Supabase wants the key twice: `apikey` is consumed by the API gateway,
	 * `Authorization` by PostgREST itself to derive the Postgres role. Sending
	 * only one of them produces a confusingly partial failure.
	 *
	 * @return array<string, string>
	 */
	private function headers( string $key ): array {
		return array(
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	/**
	 * A user agent that identifies the plugin and the site.
	 *
	 * When a Supabase support engineer is reading project logs wondering what is
	 * hammering their API, this is the string that answers it.
	 */
	private function user_agent(): string {
		return sprintf(
			'WPSupabaseSync/%s (WordPress/%s; %s)',
			VERSION,
			get_bloginfo( 'version' ),
			home_url( '/' )
		);
	}

	/**
	 * Flatten response headers to a lowercase-keyed string map.
	 *
	 * @param array<string, mixed>|\WP_HTTP_Requests_Response $raw wp_remote_request result.
	 * @return array<string, string>
	 */
	private function normalize_headers( $raw ): array {
		$headers = wp_remote_retrieve_headers( $raw );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $headers as $name => $value ) {
			$normalized[ strtolower( (string) $name ) ] = is_array( $value )
				? implode( ', ', array_map( 'strval', $value ) )
				: (string) $value;
		}

		return $normalized;
	}

	/**
	 * Record a failed request, redacted.
	 */
	private function log_failure( string $method, string $path, string $key_role, Response $response ): void {
		if ( null === $this->logger ) {
			return;
		}

		$this->logger->error(
			'client',
			sprintf(
				/* translators: 1: HTTP method, 2: request path, 3: outcome summary. */
				__( '%1$s %2$s failed: %3$s', 'wp-supabase-sync' ),
				strtoupper( $method ),
				'' === $path ? '/' : $path,
				$response->summary()
			),
			array(
				'status'   => $response->status,
				'code'     => $response->error_code(),
				'hint'     => $response->error_hint(),
				'details'  => $response->error_details(),
				'key_role' => $key_role,
				'body'     => substr( $response->body, 0, self::MAX_LOGGED_BODY ),
			)
		);
	}
}
