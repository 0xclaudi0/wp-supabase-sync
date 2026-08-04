<?php
/**
 * Runs the checks in dependency order and short-circuits.
 *
 * The ordering is the whole design. If the project is unreachable, reporting that
 * authentication, the table, the grants, the policies and the write path all
 * "failed" is technically true and diagnostically useless — five red lines, none
 * of which is the cause. Skipping dependants instead leaves exactly one red line,
 * which is the answer.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics;

use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\Checks\AuthCheck;
use WPSupabaseSync\Diagnostics\Checks\ColumnsCheck;
use WPSupabaseSync\Diagnostics\Checks\CronCheck;
use WPSupabaseSync\Diagnostics\Checks\DiagnosticCheck;
use WPSupabaseSync\Diagnostics\Checks\GrantsCheck;
use WPSupabaseSync\Diagnostics\Checks\KeyShapeCheck;
use WPSupabaseSync\Diagnostics\Checks\QueueHealthCheck;
use WPSupabaseSync\Diagnostics\Checks\ReachableCheck;
use WPSupabaseSync\Diagnostics\Checks\RlsAnonCheck;
use WPSupabaseSync\Diagnostics\Checks\RlsLeakCheck;
use WPSupabaseSync\Diagnostics\Checks\SettingsCheck;
use WPSupabaseSync\Diagnostics\Checks\TableExistsCheck;
use WPSupabaseSync\Diagnostics\Checks\WriteCheck;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Sync\PostMapper;
use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Sync\SyncEngine;
use WPSupabaseSync\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsRunner {

	public function __construct(
		private readonly Settings $settings,
		private readonly SupabaseClient $client,
		private readonly PostMapper $mapper,
		private readonly Queue $queue,
		private readonly SyncEngine $engine,
		private readonly Logger $logger
	) {}

	/**
	 * The checks, in the order SPEC §6.2 defines.
	 *
	 * @return DiagnosticCheck[]
	 */
	public function checks(): array {
		return array(
			new SettingsCheck(),
			new KeyShapeCheck(),
			new ReachableCheck(),
			new AuthCheck(),
			new TableExistsCheck(),
			new ColumnsCheck(),
			new GrantsCheck(),
			new WriteCheck(),
			new RlsAnonCheck(),
			new RlsLeakCheck(),
			new CronCheck(),
			new QueueHealthCheck(),
		);
	}

	/**
	 * Run everything.
	 *
	 * @return Check[]
	 */
	public function run(): array {
		$context = new Context(
			$this->settings,
			$this->client,
			$this->mapper,
			$this->queue,
			$this->engine
		);

		$results = array();

		foreach ( $this->checks() as $check ) {
			$blocker = $context->first_blocker( $check->depends_on() );

			if ( '' !== $blocker ) {
				$result = Check::skip(
					$check->id(),
					$check->label(),
					sprintf(
						/* translators: %s: label of the check that failed. */
						__( 'Not run, because "%s" did not pass. Fix that first — this check cannot produce a meaningful answer until it does.', 'wp-supabase-sync' ),
						$blocker
					)
				);
			} else {
				$result = $this->run_one( $check, $context );
			}

			$context->record( $result );

			$results[] = $result;
		}//end foreach

		$this->log_summary( $results );

		return $results;
	}

	/**
	 * Run one check, converting an unexpected exception into a failed result.
	 *
	 * A diagnostics page that dies with a fatal error while explaining what is
	 * broken is worse than no diagnostics page.
	 */
	private function run_one( DiagnosticCheck $check, Context $context ): Check {
		try {
			return $check->run( $context );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'diagnostics',
				sprintf(
					/* translators: 1: check id, 2: exception message. */
					__( 'Check "%1$s" threw: %2$s', 'wp-supabase-sync' ),
					$check->id(),
					$e->getMessage()
				),
				array( 'exception' => get_class( $e ) )
			);

			return Check::fail(
				$check->id(),
				$check->label(),
				sprintf(
					/* translators: %s: exception message. */
					__( 'This check could not complete because of an unexpected error: %s', 'wp-supabase-sync' ),
					$e->getMessage()
				),
				__( 'This is a bug in the plugin rather than a problem with your configuration. The logs page has the details.', 'wp-supabase-sync' )
			);
		}//end try
	}

	/**
	 * Counts of each status.
	 *
	 * @param Check[] $results Results.
	 * @return array<string, int>
	 */
	public static function summarize( array $results ): array {
		$counts = array(
			Check::STATUS_PASS => 0,
			Check::STATUS_WARN => 0,
			Check::STATUS_FAIL => 0,
			Check::STATUS_SKIP => 0,
		);

		foreach ( $results as $result ) {
			if ( isset( $counts[ $result->status ] ) ) {
				++$counts[ $result->status ];
			}
		}

		return $counts;
	}

	/**
	 * Leave a record of the run, so a later report has history behind it.
	 *
	 * @param Check[] $results Results.
	 */
	private function log_summary( array $results ): void {
		$counts = self::summarize( $results );

		$this->logger->log(
			$counts[ Check::STATUS_FAIL ] > 0 ? Logger::LEVEL_WARNING : Logger::LEVEL_INFO,
			'diagnostics',
			sprintf(
				/* translators: 1: pass count, 2: warn count, 3: fail count, 4: skip count. */
				__( 'Diagnostics run: %1$d passed, %2$d warnings, %3$d failed, %4$d skipped.', 'wp-supabase-sync' ),
				$counts[ Check::STATUS_PASS ],
				$counts[ Check::STATUS_WARN ],
				$counts[ Check::STATUS_FAIL ],
				$counts[ Check::STATUS_SKIP ]
			),
			array(
				'failed' => array_values(
					array_map(
						static fn( Check $c ): string => $c->id,
						array_filter( $results, static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status )
					)
				),
			)
		);
	}
}
