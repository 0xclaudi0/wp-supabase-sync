<?php
/**
 * Check 9: can the public actually read published rows?
 *
 * The subtle part is that an empty result has two completely different causes:
 * the read policy is missing, or there is simply nothing published yet. Reporting
 * "your policy is broken" to someone whose mirror is legitimately empty is a
 * false alarm, so this compares what the anon key sees against what the service
 * key sees before drawing any conclusion.
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
use WPSupabaseSync\Sync\Schema;

defined( 'ABSPATH' ) || exit;

final class RlsAnonCheck implements DiagnosticCheck {

	public function id(): string {
		return 'rls_anon';
	}

	public function label(): string {
		return __( 'Public read policy', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'table_exists' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		if ( '' === $context->settings->anon_key() ) {
			return Check::skip(
				$this->id(),
				$this->label(),
				__( 'No anon key is configured, so what the public can see cannot be tested. Add the anon (publishable) key under Settings → Supabase Sync to enable this check.', 'wp-supabase-sync' )
			);
		}

		$query = array(
			'site_id' => 'eq.' . $context->settings->site_id(),
			'status'  => 'eq.publish',
			'select'  => 'wp_id',
			'limit'   => 1,
		);

		try {
			$anon    = $context->client->select( $query, SupabaseClient::ROLE_ANON );
			$service = $context->client->select( $query, SupabaseClient::ROLE_SERVICE );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		if ( ! $anon->is_success() ) {
			$translated = ErrorTranslator::translate( $anon, $table );

			// 42501 for anon is the same GRANT lesson, one role over.
			if ( '42501' === $anon->error_code() ) {
				return Check::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: %s: table name. */
						__( 'The anon key was refused with 42501 on public.%s. The public read policy is unreachable because anon has no table privilege — again a GRANT, not a policy.', 'wp-supabase-sync' ),
						$table
					),
					sprintf(
						/* translators: %s: SQL. */
						__( "Run:\n\n%s", 'wp-supabase-sync' ),
						sprintf( 'grant select on public.%s to anon, authenticated;', $table )
					),
					ErrorTranslator::DOC_RLS
				);
			}

			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: observed response, 2: explanation. */
					__( 'Reading with the anon key failed: %1$s. %2$s', 'wp-supabase-sync' ),
					$anon->summary(),
					$translated->explanation()
				),
				$translated->fix(),
				$translated->doc_url()
			);
		}//end if

		$anon_rows    = is_array( $anon->json ) ? count( $anon->json ) : 0;
		$service_rows = is_array( $service->json ) ? count( $service->json ) : 0;

		if ( $anon_rows > 0 ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				__( 'The anon key can read published rows, so a public frontend using the anon key will see your content.', 'wp-supabase-sync' )
			);
		}

		// Nothing to read either way: not a fault, just an empty mirror.
		if ( 0 === $service_rows ) {
			return Check::skip(
				$this->id(),
				$this->label(),
				__( 'There are no published rows in the mirror yet, so there is nothing for the policy to return. Sync some content and run this again.', 'wp-supabase-sync' )
			);
		}

		// The service key sees rows and anon does not. That is the real finding.
		return Check::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: %s: table name. */
				__( 'The service role key can see published rows in public.%s but the anon key gets an empty array. Row level security is enabled and no policy grants anon a read, so PostgREST filters everything out and returns [] with no error.', 'wp-supabase-sync' ),
				$table
			),
			sprintf(
				/* translators: %s: SQL. */
				__( "Add the public read policy:\n\n%s", 'wp-supabase-sync' ),
				Schema::policy_sql( $table )
			),
			ErrorTranslator::DOC_RLS
		);
	}
}
