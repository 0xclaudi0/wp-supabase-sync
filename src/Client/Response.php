<?php
/**
 * Normalized Supabase response.
 *
 * Wraps the three outcomes that matter, so callers never have to re-derive them:
 * the request never left WordPress (transport error), it got an HTTP answer that
 * succeeded, or it got an HTTP answer that failed and carries a PostgREST error
 * body.
 *
 * The distinction between "no answer" and "an answer that said no" is the whole
 * basis of a non-cascading diagnosis, so it is modelled explicitly rather than
 * being smuggled into a status code of 0.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Client;

defined( 'ABSPATH' ) || exit;

final class Response {

	/**
	 * Construct directly for a real HTTP answer; use from_transport_error()
	 * when the request never left WordPress.
	 *
	 * @param int                   $status          HTTP status, 0 when the request never completed.
	 * @param string                $body            Raw response body.
	 * @param array<string, string> $headers         Response headers, lowercased names.
	 * @param array<mixed>|null     $json            Decoded body, null when not JSON.
	 * @param string                $transport_error WP_Error message when WordPress could not complete the request.
	 * @param string                $transport_code  WP_Error code, e.g. http_request_failed.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body = '',
		public readonly array $headers = array(),
		public readonly ?array $json = null,
		public readonly string $transport_error = '',
		public readonly string $transport_code = ''
	) {}

	/**
	 * Build a response representing a request that never reached Supabase.
	 */
	public static function from_transport_error( string $code, string $message ): self {
		return new self( 0, '', array(), null, $message, $code );
	}

	/**
	 * WordPress could not complete the request: DNS, TLS, timeout, blocked
	 * outbound HTTP. Nothing downstream of this is diagnosable.
	 */
	public function is_transport_error(): bool {
		return '' !== $this->transport_error;
	}

	public function is_success(): bool {
		return ! $this->is_transport_error() && $this->status >= 200 && $this->status < 300;
	}

	/**
	 * PostgREST error code, e.g. PGRST205, or a Postgres SQLSTATE like 42501.
	 *
	 * PostgREST returns these in the body as `code`. An empty string means the
	 * response carried no structured error, which is itself informative: a 401
	 * with no body usually came from the API gateway rather than from PostgREST.
	 */
	public function error_code(): string {
		$code = $this->json['code'] ?? '';

		return is_scalar( $code ) ? (string) $code : '';
	}

	public function error_message(): string {
		$message = $this->json['message'] ?? '';

		return is_scalar( $message ) ? (string) $message : '';
	}

	public function error_details(): string {
		$details = $this->json['details'] ?? '';

		return is_scalar( $details ) ? (string) $details : '';
	}

	public function error_hint(): string {
		$hint = $this->json['hint'] ?? '';

		return is_scalar( $hint ) ? (string) $hint : '';
	}

	/**
	 * A short factual description of what happened, for a log row or a
	 * diagnostics `detail` field.
	 *
	 * Deliberately reports the observation, not an interpretation — turning this
	 * into advice is ErrorTranslator's job. The caller is responsible for
	 * passing the result through Redactor before display.
	 */
	public function summary(): string {
		if ( $this->is_transport_error() ) {
			return sprintf(
				/* translators: 1: WP_Error code, 2: error message. */
				__( 'the request did not complete (%1$s: %2$s)', 'wp-supabase-sync' ),
				$this->transport_code,
				$this->transport_error
			);
		}

		$code    = $this->error_code();
		$message = $this->error_message();

		if ( '' !== $code && '' !== $message ) {
			return sprintf(
				/* translators: 1: HTTP status, 2: PostgREST error code, 3: error message. */
				__( 'HTTP %1$d with code %2$s: %3$s', 'wp-supabase-sync' ),
				$this->status,
				$code,
				$message
			);
		}

		if ( '' !== $message ) {
			return sprintf(
				/* translators: 1: HTTP status, 2: error message. */
				__( 'HTTP %1$d: %2$s', 'wp-supabase-sync' ),
				$this->status,
				$message
			);
		}

		return sprintf(
			/* translators: %d: HTTP status. */
			__( 'HTTP %d', 'wp-supabase-sync' ),
			$this->status
		);
	}
}
