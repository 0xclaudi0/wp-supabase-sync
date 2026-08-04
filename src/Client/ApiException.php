<?php
/**
 * Thrown when a request cannot even be attempted.
 *
 * Reserved for local, pre-flight problems: no project URL, no key, a table name
 * that failed validation. Anything that reached Supabase — including a 401 or a
 * 500 — comes back as a Response instead, because a real HTTP answer is
 * information the diagnostics layer needs rather than an exceptional condition.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Client;

defined( 'ABSPATH' ) || exit;

final class ApiException extends \RuntimeException {

	/**
	 * Prefer the named constructors below, which pair a reason with its wording.
	 *
	 * @param string $reason  Machine-readable reason, e.g. not_configured.
	 * @param string $message Human-readable explanation.
	 */
	public function __construct( private readonly string $reason, string $message ) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}

	public static function not_configured(): self {
		return new self(
			'not_configured',
			__( 'Supabase is not configured: both a project URL and a service role key are required.', 'wp-supabase-sync' )
		);
	}

	public static function missing_key( string $role ): self {
		return new self(
			'missing_key',
			sprintf(
				/* translators: %s: key role, e.g. anon. */
				__( 'No %s key is configured.', 'wp-supabase-sync' ),
				$role
			)
		);
	}

	public static function invalid_url(): self {
		return new self(
			'invalid_url',
			__( 'The Supabase project URL is missing or not a valid http/https URL.', 'wp-supabase-sync' )
		);
	}

	public static function invalid_table(): self {
		return new self(
			'invalid_table',
			__( 'The configured table name is not a valid Postgres identifier.', 'wp-supabase-sync' )
		);
	}
}
