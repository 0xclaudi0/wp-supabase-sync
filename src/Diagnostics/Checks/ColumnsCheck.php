<?php
/**
 * Check 6: do the table's columns match what the mapper writes?
 *
 * Read from PostgREST's OpenAPI description rather than from a row, because the
 * table is usually empty when this matters most — right after the migration is
 * applied, which is exactly when a typo in it would still be cheap to fix.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Sync\PostMapper;

defined( 'ABSPATH' ) || exit;

final class ColumnsCheck implements DiagnosticCheck {

	public function id(): string {
		return 'columns';
	}

	public function label(): string {
		return __( 'Column agreement', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'table_exists' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		try {
			$response = $context->client->ping( SupabaseClient::ROLE_SERVICE );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		}

		$properties = $response->json['definitions'][ $table ]['properties'] ?? null;

		if ( ! is_array( $properties ) ) {
			return Check::warn(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: table name. */
					__( 'Could not read the column list for public.%s from the API description, so column drift could not be ruled out.', 'wp-supabase-sync' ),
					$table
				),
				__( 'This is not itself a failure. If writes are rejected with PGRST204 or 42703, compare your table against `wp supabase schema --print`.', 'wp-supabase-sync' )
			);
		}

		$actual   = array_keys( $properties );
		$expected = PostMapper::columns();
		$missing  = array_values( array_diff( $expected, $actual ) );

		if ( ! empty( $missing ) ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: table name, 2: comma-separated column list. */
					__( 'public.%1$s is missing columns the plugin writes: %2$s', 'wp-supabase-sync' ),
					$table,
					implode( ', ', $missing )
				),
				__( 'The table and the plugin have drifted apart. Regenerate the migration with `wp supabase schema --print` and apply the difference. Writes will fail with PGRST204 until you do.', 'wp-supabase-sync' )
			);
		}

		// Extra columns are fine — the user may have added their own. Say so
		// rather than staying silent, so a surprise is not mistaken for approval.
		$extra = array_values( array_diff( $actual, array_merge( $expected, array( 'id', 'synced_at' ) ) ) );

		if ( ! empty( $extra ) ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: count of mapped columns, 2: comma-separated extra columns. */
					__( 'All %1$d mapped columns are present. The table also has columns the plugin does not write, which is fine as long as they are nullable or have defaults: %2$s', 'wp-supabase-sync' ),
					count( $expected ),
					implode( ', ', $extra )
				)
			);
		}

		return Check::pass(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: %d: number of columns. */
				__( 'All %d mapped columns are present and the table matches the plugin exactly.', 'wp-supabase-sync' ),
				count( $expected )
			)
		);
	}
}
