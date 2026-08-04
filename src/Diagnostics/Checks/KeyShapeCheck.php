<?php
/**
 * Check 2: is the configured key actually a service role key?
 *
 * The mistake this catches — the anon key pasted into the service field — is
 * uniquely nasty because it does not produce an error. Row level security simply
 * filters every write, so syncing appears to run and nothing arrives.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Diagnostics\ErrorTranslator;
use WPSupabaseSync\Settings\KeyInspector;

defined( 'ABSPATH' ) || exit;

final class KeyShapeCheck implements DiagnosticCheck {

	public function id(): string {
		return 'key_shape';
	}

	public function label(): string {
		return __( 'Service role key shape', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'settings' );
	}

	public function run( Context $context ): Check {
		$info = KeyInspector::inspect( $context->settings->service_role_key() );

		if ( true === $info['is_expired'] ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: masked key fingerprint. */
					__( 'The key %s has expired. Supabase will reject it with PGRST301.', 'wp-supabase-sync' ),
					(string) $info['fingerprint']
				),
				__( 'Copy a current key from Settings → API Keys in your Supabase dashboard.', 'wp-supabase-sync' ),
				ErrorTranslator::DOC_AUTH_UID
			);
		}

		if ( KeyInspector::is_public_key( $info ) ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: masked key fingerprint. */
					__( 'The key in the service role field (%s) is an anon or publishable key.', 'wp-supabase-sync' ),
					(string) $info['fingerprint']
				),
				__( 'Replace it with the service role key — "secret" in current projects. This matters more than it looks: an anon key does not fail loudly, it just gets filtered by row level security, so syncing will appear to work while writing nothing at all.', 'wp-supabase-sync' ),
				ErrorTranslator::DOC_RLS
			);
		}

		if ( KeyInspector::is_service_key( $info ) ) {
			return Check::pass( $this->id(), $this->label(), KeyInspector::describe( $info ) );
		}

		// Unreadable is not the same as wrong. Newer key formats are opaque, and
		// refusing to proceed on an unrecognised one would be worse than saying so.
		return Check::warn(
			$this->id(),
			$this->label(),
			trim( KeyInspector::describe( $info ) . ' ' . (string) $info['note'] ),
			__( 'The plugin could not determine this key\'s role, so it will try to use it anyway. If writes fail, confirm you copied the service role (secret) key rather than the publishable one.', 'wp-supabase-sync' )
		);
	}
}
