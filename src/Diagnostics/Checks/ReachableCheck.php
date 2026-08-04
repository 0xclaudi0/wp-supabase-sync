<?php
/**
 * Check 3: can WordPress reach the project at all?
 *
 * Sends **no** credentials deliberately. Any HTTP answer — including a 401 —
 * proves the host exists and speaks HTTP, which is the entire question. Mixing a
 * key into this check is what makes "wrong URL" and "wrong key" look like the
 * same failure, and they need completely different fixes.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Diagnostics\ErrorTranslator;

defined( 'ABSPATH' ) || exit;

final class ReachableCheck implements DiagnosticCheck {

	public function id(): string {
		return 'reachable';
	}

	public function label(): string {
		return __( 'Reachability', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'settings' );
	}

	public function run( Context $context ): Check {
		try {
			$response = $context->client->probe_reachable();
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		if ( $response->is_transport_error() ) {
			$translated = ErrorTranslator::translate( $response, $context->settings->table_name() );

			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: project URL, 2: transport error detail. */
					__( 'No HTTP response from %1$s — %2$s', 'wp-supabase-sync' ),
					$context->settings->project_url(),
					$response->summary()
				),
				$translated->fix(),
				$translated->doc_url()
			);
		}

		return Check::pass(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: project URL, 2: HTTP status. */
				__( '%1$s/rest/v1/ answered with HTTP %2$d without credentials, so the host is reachable and speaking HTTP.', 'wp-supabase-sync' ),
				$context->settings->project_url(),
				$response->status
			)
		);
	}
}
