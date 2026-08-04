<?php
/**
 * Check 5: does the mirror table exist?
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

final class TableExistsCheck implements DiagnosticCheck {

	public function id(): string {
		return 'table_exists';
	}

	public function label(): string {
		return __( 'Mirror table exists', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'auth' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		try {
			$response = $context->client->select( array( 'limit' => 0 ) );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		if ( $response->is_success() ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: table name. */
					__( 'public.%s exists and is exposed through the Data API.', 'wp-supabase-sync' ),
					$table
				)
			);
		}

		$code = $response->error_code();

		// A privilege error means the table is there; check 7 owns that diagnosis.
		if ( '42501' === $code ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: table name. */
					__( 'public.%s exists — the request was refused on privileges, not on the table being missing. See the table privileges check below.', 'wp-supabase-sync' ),
					$table
				)
			);
		}

		$translated = ErrorTranslator::translate( $response, $table );

		if ( in_array( $code, array( 'PGRST205', '42P01' ), true ) ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: observed response, 2: explanation. */
					__( '%1$s. %2$s', 'wp-supabase-sync' ),
					$response->summary(),
					$translated->explanation()
				),
				sprintf(
					/* translators: %s: SQL migration. */
					__( "Apply the migration below in the Supabase SQL editor. It is also available from `wp supabase schema --print`.\n\n%s", 'wp-supabase-sync' ),
					( new Schema( $context->settings ) )->migration()
				),
				$translated->doc_url()
			);
		}

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
