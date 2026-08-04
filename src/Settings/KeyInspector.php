<?php
/**
 * Inspects a Supabase API key without contacting Supabase.
 *
 * The point is to catch the single most common misconfiguration — the anon key
 * pasted into the service role field, or vice versa — before the user spends an
 * hour wondering why writes silently do nothing.
 *
 * Supabase issues two generations of API key and they are inspectable to very
 * different depths:
 *
 * - **Legacy JWT keys** (`eyJ…`), still used by older projects and by the local
 *   CLI stack, carry a readable `role` claim. We can say with confidence which
 *   role a key grants.
 * - **Current keys** (`sb_secret_…` / `sb_publishable_…`) are opaque. There is
 *   no claim to read, so the prefix is all we have. That is still enough to
 *   catch the anon/service mix-up, which is the whole reason this class exists.
 *
 * No signature verification. We are not authenticating the key — PostgREST does
 * that, and it does it properly. We are reading the label on the tin.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Settings;

use WPSupabaseSync\Support\Redactor;

defined( 'ABSPATH' ) || exit;

final class KeyInspector {

	public const SHAPE_EMPTY       = 'empty';
	public const SHAPE_JWT         = 'jwt';
	public const SHAPE_SECRET      = 'secret';
	public const SHAPE_PUBLISHABLE = 'publishable';
	public const SHAPE_UNKNOWN     = 'unknown';

	public const ROLE_SERVICE = 'service_role';
	public const ROLE_ANON    = 'anon';

	/** Role read from a JWT claim — trustworthy. */
	public const ROLE_FROM_CLAIM = 'claim';

	/** Role inferred from a key prefix — reliable, but not a claim. */
	public const ROLE_FROM_PREFIX = 'prefix';

	public const ROLE_UNKNOWN = 'unknown';

	/**
	 * A JWT payload larger than this is not a Supabase API key, and decoding it
	 * would only waste memory.
	 */
	private const MAX_PAYLOAD_BYTES = 8192;

	/**
	 * Inspect a key.
	 *
	 * Never throws. A key this class cannot read is a `warn`, not a fatal — the
	 * user may legitimately be holding a key format that postdates this code,
	 * and refusing to proceed would be worse than proceeding uncertainly.
	 *
	 * @param string $key Raw API key.
	 * @return array{
	 *     shape: string,
	 *     role: string,
	 *     role_source: string,
	 *     issuer: string,
	 *     expires_at: int|null,
	 *     is_expired: bool,
	 *     fingerprint: string,
	 *     note: string
	 * }
	 */
	public static function inspect( string $key ): array {
		$key = trim( $key );

		$info = array(
			'shape'       => self::SHAPE_EMPTY,
			'role'        => '',
			'role_source' => self::ROLE_UNKNOWN,
			'issuer'      => '',
			'expires_at'  => null,
			'is_expired'  => false,
			'fingerprint' => Redactor::fingerprint( $key ),
			'note'        => '',
		);

		if ( '' === $key ) {
			$info['note'] = __( 'No key set.', 'wp-supabase-sync' );

			return $info;
		}

		if ( str_starts_with( $key, 'sb_secret_' ) ) {
			$info['shape']       = self::SHAPE_SECRET;
			$info['role']        = self::ROLE_SERVICE;
			$info['role_source'] = self::ROLE_FROM_PREFIX;
			$info['note']        = __( 'Current-generation Supabase secret key. Opaque by design, so its role is read from the key prefix rather than from a claim.', 'wp-supabase-sync' );

			return $info;
		}

		if ( str_starts_with( $key, 'sb_publishable_' ) ) {
			$info['shape']       = self::SHAPE_PUBLISHABLE;
			$info['role']        = self::ROLE_ANON;
			$info['role_source'] = self::ROLE_FROM_PREFIX;
			$info['note']        = __( 'Current-generation Supabase publishable key. Safe to expose to browsers, and not usable for syncing.', 'wp-supabase-sync' );

			return $info;
		}

		$payload = self::decode_jwt_payload( $key );

		if ( null === $payload ) {
			$info['shape'] = self::SHAPE_UNKNOWN;
			$info['note']  = __( 'This does not look like a Supabase API key. Expected either a JWT beginning "eyJ" or a key beginning "sb_secret_" / "sb_publishable_".', 'wp-supabase-sync' );

			return $info;
		}

		$info['shape'] = self::SHAPE_JWT;

		if ( isset( $payload['role'] ) && is_string( $payload['role'] ) ) {
			$info['role']        = $payload['role'];
			$info['role_source'] = self::ROLE_FROM_CLAIM;
		} else {
			$info['note'] = __( 'This is a JWT but it has no "role" claim, so it is probably a user access token rather than a project API key.', 'wp-supabase-sync' );
		}

		if ( isset( $payload['iss'] ) && is_string( $payload['iss'] ) ) {
			$info['issuer'] = $payload['iss'];
		}

		if ( isset( $payload['exp'] ) && is_numeric( $payload['exp'] ) ) {
			$info['expires_at'] = (int) $payload['exp'];
			$info['is_expired'] = $info['expires_at'] < time();

			if ( $info['is_expired'] ) {
				$info['note'] = __( 'This key expired. Supabase will reject it with PGRST301.', 'wp-supabase-sync' );
			}
		}

		return $info;
	}

	/**
	 * Whether a key is usable for syncing, i.e. it writes past row level security.
	 *
	 * @param array<string, mixed> $info Result of inspect().
	 */
	public static function is_service_key( array $info ): bool {
		return self::ROLE_SERVICE === ( $info['role'] ?? '' );
	}

	/**
	 * Whether a key is definitely the wrong one for the service role field.
	 *
	 * Deliberately narrower than `! is_service_key()`: a key we could not read
	 * is unknown, not wrong, and must not be reported as an error.
	 *
	 * @param array<string, mixed> $info Result of inspect().
	 */
	public static function is_public_key( array $info ): bool {
		return self::ROLE_ANON === ( $info['role'] ?? '' )
			|| self::SHAPE_PUBLISHABLE === ( $info['shape'] ?? '' );
	}

	/**
	 * One-line human summary for the settings screen.
	 *
	 * @param array<string, mixed> $info Result of inspect().
	 */
	public static function describe( array $info ): string {
		$shape = (string) ( $info['shape'] ?? self::SHAPE_UNKNOWN );
		$role  = (string) ( $info['role'] ?? '' );

		if ( self::SHAPE_EMPTY === $shape ) {
			return __( 'Not set.', 'wp-supabase-sync' );
		}

		if ( '' === $role ) {
			return sprintf(
				/* translators: %s: masked key fingerprint. */
				__( '%s — role could not be determined.', 'wp-supabase-sync' ),
				(string) ( $info['fingerprint'] ?? '' )
			);
		}

		$from_claim = self::ROLE_FROM_CLAIM === ( $info['role_source'] ?? '' );

		return sprintf(
			$from_claim
				/* translators: 1: masked key fingerprint, 2: role name. */
				? __( '%1$s — role claim says "%2$s".', 'wp-supabase-sync' )
				/* translators: 1: masked key fingerprint, 2: role name. */
				: __( '%1$s — key prefix indicates "%2$s".', 'wp-supabase-sync' ),
			(string) ( $info['fingerprint'] ?? '' ),
			$role
		);
	}

	/**
	 * Decode a JWT's payload segment.
	 *
	 * @return array<string, mixed>|null Null when the string is not a readable JWT.
	 */
	private static function decode_jwt_payload( string $token ): ?array {
		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) || '' === $parts[1] ) {
			return null;
		}

		$decoded = self::base64url_decode( $parts[1] );

		if ( null === $decoded || strlen( $decoded ) > self::MAX_PAYLOAD_BYTES ) {
			return null;
		}

		$payload = json_decode( $decoded, true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Decode base64url, which is what JWT uses: `-` and `_` for `+` and `/`, and
	 * padding stripped.
	 */
	private static function base64url_decode( string $segment ): ?string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $segment ) ) {
			return null;
		}

		$padded  = str_pad( $segment, (int) ( ceil( strlen( $segment ) / 4 ) * 4 ), '=', STR_PAD_RIGHT );
		$decoded = base64_decode( strtr( $padded, '-_', '+/' ), true );

		return is_string( $decoded ) ? $decoded : null;
	}
}
