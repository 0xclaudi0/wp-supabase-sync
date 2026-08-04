<?php
/**
 * Secret redaction.
 *
 * Every string that leaves the plugin for a human — a log row, an admin notice,
 * a diagnostics detail, a support bundle — goes through here first. The service
 * role key bypasses row level security, so leaking it into a log file that gets
 * pasted into a forum thread is the worst outcome this plugin can produce.
 *
 * Two mechanisms, deliberately overlapping:
 *
 * 1. Pattern matching, which catches keys this plugin has never seen — a key
 *    the user pasted into the wrong field, or one echoed back inside a
 *    PostgREST error body.
 * 2. An explicit registry. Settings calls protect() on every key it reads, so
 *    the exact configured secrets are redacted even if Supabase changes key
 *    formats and the patterns below go stale.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Support;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	public const PLACEHOLDER = '[redacted]';

	/**
	 * Secrets registered at runtime, longest first so a longer secret is
	 * replaced before a shorter one that happens to be its prefix.
	 *
	 * @var string[]
	 */
	private static array $secrets = array();

	/**
	 * Patterns for things that look like Supabase credentials.
	 *
	 * Supabase issues two generations of API key. Legacy projects (and the
	 * local CLI stack) use JWTs beginning `eyJ`. Newer projects use opaque
	 * `sb_publishable_…` / `sb_secret_…` keys, which are not JWTs at all — see
	 * KeyInspector, which has to warn rather than fail on those.
	 *
	 * @var string[]
	 */
	private const PATTERNS = array(
		// JWT: three base64url segments, header first so we do not match arbitrary dotted text.
		'/eyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}/',
		// Current-generation Supabase API keys.
		'/sb_(?:secret|publishable)_[A-Za-z0-9_-]{6,}/',
		// Postgres connection strings, which carry the database password inline.
		'/postgres(?:ql)?:\/\/[^:\/\s]+:[^@\s]+@/',
	);

	/**
	 * Register a secret so it is redacted verbatim from here on.
	 *
	 * Safe to call repeatedly with the same value.
	 */
	public static function protect( string $secret ): void {
		// Very short strings are not secrets worth matching, and redacting them
		// globally would mangle unrelated text.
		if ( strlen( $secret ) < 12 || in_array( $secret, self::$secrets, true ) ) {
			return;
		}

		self::$secrets[] = $secret;

		usort(
			self::$secrets,
			static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
		);
	}

	/**
	 * Forget every registered secret. Intended for tests.
	 */
	public static function reset(): void {
		self::$secrets = array();
	}

	/**
	 * Redact secrets from a string.
	 */
	public static function redact( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		foreach ( self::$secrets as $secret ) {
			$text = str_replace( $secret, self::PLACEHOLDER, $text );
		}

		foreach ( self::PATTERNS as $pattern ) {
			$replaced = preg_replace( $pattern, self::PLACEHOLDER, $text );

			// preg_replace returns null on failure (e.g. backtrack limit on a
			// pathological body). Dropping the whole string is the safe failure
			// mode here: better to lose the message than to print a key.
			$text = null === $replaced ? self::PLACEHOLDER : $replaced;
		}

		// A Postgres URI keeps its user@host after the password is stripped, so
		// the pattern above leaves `postgres://[redacted]host`. Acceptable: the
		// secret is gone and the host is still diagnosable.
		return $text;
	}

	/**
	 * Redact recursively through arrays and scalars.
	 *
	 * Array *keys* are left alone deliberately — a key named `apikey` is useful
	 * context, and a secret is never a key in anything this plugin builds.
	 *
	 * @param mixed $value Anything loggable.
	 * @return mixed Same shape, redacted.
	 */
	public static function redact_deep( $value ) {
		if ( is_string( $value ) ) {
			return self::redact( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'redact_deep' ), $value );
		}

		if ( is_object( $value ) ) {
			return self::redact_deep( get_object_vars( $value ) );
		}

		return $value;
	}

	/**
	 * A recognisable but useless fingerprint of a key, for the settings and
	 * diagnostics screens: enough to tell two keys apart, not enough to use.
	 */
	public static function fingerprint( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		// Below this length the first 8 characters would be most of the secret.
		if ( strlen( $secret ) < 16 ) {
			return self::PLACEHOLDER;
		}

		return substr( $secret, 0, 8 ) . '…';
	}

	/**
	 * Strip credential headers from an HTTP header array before logging.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @return array<string, string>
	 */
	public static function redact_headers( array $headers ): array {
		$sensitive = array( 'apikey', 'authorization' );
		$safe      = array();

		foreach ( $headers as $name => $value ) {
			$safe[ $name ] = in_array( strtolower( (string) $name ), $sensitive, true )
				? self::PLACEHOLDER
				: self::redact( (string) $value );
		}

		return $safe;
	}
}
