<?php
/**
 * Check 7: does the service role key have table privileges?
 *
 * This is the check the whole plugin is arguably built around. `service_role`
 * bypasses row level security but **not** table privileges, and migrations run
 * as `postgres`, whose default privileges in schema `public` do not include DML
 * for anon, authenticated or service_role. So a migration that enables RLS and
 * writes beautiful policies, but omits the GRANTs, produces
 * `42501 permission denied for table` — and every policy in it is unreachable,
 * because Postgres never consults row level security at all.
 *
 * The failure text must therefore say **GRANT**, not "check your policies".
 * Sending someone to the policy editor when the grant is missing is the exact
 * wrong-layer diagnosis this plugin exists to avoid.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Diagnostics\ErrorTranslator;
use WPSupabaseSync\Sync\Schema;

defined( 'ABSPATH' ) || exit;

final class GrantsCheck implements DiagnosticCheck {

	public function id(): string {
		return 'grants';
	}

	public function label(): string {
		return __( 'Table privileges (GRANT)', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'table_exists' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		try {
			$response = $context->client->select( array( 'limit' => 1 ) );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		if ( $response->is_success() ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: table name. */
					__( 'The service role key can select from public.%s, so the table privileges are in place.', 'wp-supabase-sync' ),
					$table
				)
			);
		}

		if ( '42501' === $response->error_code() ) {
			$translated = ErrorTranslator::translate( $response, $table );

			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: table name, 2: raw error message. */
					__( 'The service role key was refused with 42501 on public.%1$s: "%2$s". This is a missing GRANT, not a policy problem — without table privileges Postgres never reaches row level security, so the policies on this table are currently unreachable and editing them will change nothing.', 'wp-supabase-sync' ),
					$table,
					$response->error_message()
				),
				$translated->fix() . "\n\n" . sprintf(
					/* translators: %s: SQL. */
					__( "Note that service_role needs the grant too. It bypasses row level security, but not table privileges:\n\n%s", 'wp-supabase-sync' ),
					Schema::grants_sql( $table )
				),
				ErrorTranslator::DOC_RLS
			);
		}

		$translated = ErrorTranslator::translate( $response, $table );

		return Check::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: observed response, 2: explanation. */
				__( '%1$s. %2$s', 'wp-supabase-sync' ),
				$response->summary(),
				$translated->explanation()
			),
			$translated->fix(),
			$translated->doc_url()
		);
	}
}
