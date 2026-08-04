<?php
/**
 * Check 10: can the public see rows it should not?
 *
 * This is the check that finds a *security* misconfiguration rather than a broken
 * one, and it is the reason the write probe uses a non-published status.
 *
 * It cannot simply query for unpublished rows, because a correctly working plugin
 * deletes unpublished content from the mirror, so there would usually be nothing
 * to find and the check would pass vacuously. Instead it writes a probe row that
 * a correct policy must exclude, then tries to read it with the anon key. If the
 * probe comes back, the policy is too permissive and real unpublished content
 * would leak the moment any ever existed.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Sync\Schema;

defined( 'ABSPATH' ) || exit;

final class RlsLeakCheck implements DiagnosticCheck {

	public function id(): string {
		return 'rls_leak';
	}

	public function label(): string {
		return __( 'Unpublished content is not public', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'write' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		if ( '' === $context->settings->anon_key() ) {
			return Check::skip(
				$this->id(),
				$this->label(),
				__( 'No anon key is configured, so this cannot be tested. This is the one check worth adding the anon key for: it is the difference between knowing your unpublished content is private and assuming it.', 'wp-supabase-sync' )
			);
		}

		try {
			$written = $context->client->upsert( array( WriteCheck::probe_row( $context ) ) );

			if ( ! $written->is_success() ) {
				return Check::skip(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: %s: observed response. */
						__( 'Could not write the probe row needed for this test (%s), so leakage could not be assessed.', 'wp-supabase-sync' ),
						$written->summary()
					)
				);
			}

			$anon = $context->client->select(
				array(
					'site_id' => 'eq.' . $context->settings->site_id(),
					'wp_id'   => 'eq.0',
					'select'  => 'wp_id,status',
				),
				SupabaseClient::ROLE_ANON
			);
		} catch ( ApiException $e ) {
			return Check::skip( $this->id(), $this->label(), $e->getMessage() );
		} finally {
			$this->cleanup( $context );
		}//end try

		// An error reading as anon is fine here — being refused is a safe outcome
		// for a check whose failure condition is "the row came back".
		if ( ! $anon->is_success() ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: observed response. */
					__( 'The anon key could not read the unpublished probe row (%s), so unpublished content is not exposed.', 'wp-supabase-sync' ),
					$anon->summary()
				)
			);
		}

		$rows = is_array( $anon->json ) ? $anon->json : array();

		if ( empty( $rows ) ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: probe status value. */
					__( 'A probe row with status "%s" was written and the anon key could not see it. The read policy correctly restricts the public to published rows.', 'wp-supabase-sync' ),
					Schema::PROBE_STATUS
				)
			);
		}

		return Check::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: table name, 2: probe status. */
				__( 'SECURITY: the anon key read a row with status "%2$s" from public.%1$s. The read policy is not restricted to published rows, so anyone holding your anon key — which is public by design and shipped to browsers — can read every row in this table, including anything unpublished that ever lands in it.', 'wp-supabase-sync' ),
				$table,
				Schema::PROBE_STATUS
			),
			sprintf(
				/* translators: %s: SQL. */
				__( "Replace the policy so it filters on status. This drops the existing policy of the same name and recreates it correctly:\n\n%s", 'wp-supabase-sync' ),
				Schema::policy_sql( $table )
			),
			\WPSupabaseSync\Diagnostics\ErrorTranslator::DOC_RLS
		);
	}

	private function cleanup( Context $context ): void {
		try {
			$context->client->delete_by_wp_ids( array( 0 ) );
		} catch ( ApiException $e ) {
			return;
		}
	}
}
