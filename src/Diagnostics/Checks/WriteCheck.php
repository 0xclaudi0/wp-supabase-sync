<?php
/**
 * Check 8: can the plugin actually write?
 *
 * Inserts a probe row and removes it again. Selecting proves read privileges
 * only, and a table can readily be readable but not writable — so nothing short
 * of a real insert answers the question the user cares about.
 *
 * The probe is given a non-published status so the public read policy excludes
 * it, and it is deleted in a finally block so a failure part-way through cannot
 * leave it behind.
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

final class WriteCheck implements DiagnosticCheck {

	public function id(): string {
		return 'write';
	}

	public function label(): string {
		return __( 'Write access', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array( 'grants' );
	}

	public function run( Context $context ): Check {
		$table = $context->settings->table_name();

		try {
			$response = $context->client->upsert( array( self::probe_row( $context ) ) );
		} catch ( ApiException $e ) {
			return Check::fail( $this->id(), $this->label(), $e->getMessage() );
		} finally {
			// Always, even if the insert threw. A stray probe row is a small mess,
			// but it is the kind of small mess that erodes trust in a tool.
			$this->cleanup( $context );
		}

		if ( $response->is_success() ) {
			return Check::pass(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: table name. */
					__( 'Inserted a probe row into public.%s and deleted it again, so the full write path works.', 'wp-supabase-sync' ),
					$table
				)
			);
		}

		$translated = ErrorTranslator::translate( $response, $table );

		return Check::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: observed response, 2: explanation. */
				__( 'Writing a probe row failed: %1$s. %2$s', 'wp-supabase-sync' ),
				$response->summary(),
				$translated->explanation()
			),
			$translated->fix(),
			$translated->doc_url()
		);
	}

	/**
	 * A row that satisfies every NOT NULL column and cannot be publicly read.
	 *
	 * `wp_id = 0` can never collide with a real post, since WordPress post IDs
	 * start at 1.
	 *
	 * @return array<string, mixed>
	 */
	public static function probe_row( Context $context ): array {
		return array(
			'site_id'      => $context->settings->site_id(),
			'wp_id'        => 0,
			'post_type'    => Schema::PROBE_POST_TYPE,
			'status'       => Schema::PROBE_STATUS,
			'slug'         => 'wpsb-diagnostics-probe',
			'title'        => 'WP Supabase Sync diagnostics probe',
			'content_hash' => 'probe',
			'meta'         => array(),
			'terms'        => array(),
		);
	}

	/**
	 * Remove the probe row, ignoring any failure.
	 *
	 * A cleanup error must not become the reported diagnosis: it would mask the
	 * real result of the check.
	 */
	private function cleanup( Context $context ): void {
		try {
			$context->client->delete_by_wp_ids( array( 0 ) );
		} catch ( ApiException $e ) {
			return;
		}
	}
}
