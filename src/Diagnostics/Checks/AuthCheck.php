<?php
/**
 * Check 4: does the service role key authenticate?
 *
 * PostgREST verifies a JWT whenever one is present, so sending the key and
 * getting a 2xx proves the key parses, verifies, and belongs to this project.
 * It proves nothing about privileges — that is checks 7 and 8.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Diagnostics\ErrorTranslator;

defined( 'ABSPATH' ) || exit;

final class AuthCheck implements DiagnosticCheck {

	public function id(): string {
		return 'auth';
	}

	public function label(): string {
		return __( 'Authentication', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		/*
		 * key_shape as well as reachable, and for a subtle reason.
		 *
		 * If the anon key was pasted into the service field, every check below
		 * this one fails with `42501 permission denied for table` — because anon
		 * genuinely has no write privilege. The GRANT explanation for that is
		 * accurate and completely unhelpful: it tells the user to run a grant,
		 * when running it would change nothing, because the request is not
		 * authenticating as service_role at all.
		 *
		 * Wrong-layer advice is the failure mode this whole plugin is built to
		 * avoid, so a definitely-wrong key stops the chain here. Note that
		 * key_shape returns `warn` for a key it merely cannot read, and a warn
		 * does not block — only a hard fail does.
		 */
		return array( 'reachable', 'key_shape' );
	}

	public function run( Context $context ): Check {
		try {
			$response = $context->client->ping( SupabaseClient::ROLE_SERVICE );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		if ( $response->is_success() ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %d: HTTP status. */
					__( 'The service role key was accepted (HTTP %d). Note this confirms the key is valid for this project, not that it has table privileges.', 'wp-supabase-sync' ),
					$response->status
				)
			);
		}

		$translated = ErrorTranslator::translate( $response, $context->settings->table_name() );

		return Check::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: observed response, 2: explanation. */
				__( '/rest/v1/ returned %1$s. %2$s', 'wp-supabase-sync' ),
				$response->summary(),
				$translated->explanation()
			),
			$translated->fix(),
			$translated->doc_url()
		);
	}
}
