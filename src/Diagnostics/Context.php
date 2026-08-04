<?php
/**
 * Everything a check needs, plus the results of the checks before it.
 *
 * Passing one context rather than five constructor arguments keeps each check
 * class small enough to read at a glance, and lets a check ask whether its
 * dependency actually passed.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics;

use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Sync\PostMapper;
use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Sync\SyncEngine;

defined( 'ABSPATH' ) || exit;

final class Context {

	/**
	 * Results so far, keyed by check id.
	 *
	 * @var array<string, Check>
	 */
	private array $results = array();

	public function __construct(
		public readonly Settings $settings,
		public readonly SupabaseClient $client,
		public readonly PostMapper $mapper,
		public readonly Queue $queue,
		public readonly SyncEngine $engine
	) {}

	public function record( Check $check ): void {
		$this->results[ $check->id ] = $check;
	}

	public function result( string $id ): ?Check {
		return $this->results[ $id ] ?? null;
	}

	/**
	 * Whether a previous check reached a state that lets dependants run.
	 *
	 * A `warn` counts as passing: a warning means "this works but deserves
	 * attention", and blocking every downstream check on it would turn one piece
	 * of advice into a wall of skips.
	 */
	public function passed( string $id ): bool {
		$check = $this->result( $id );

		if ( null === $check ) {
			return false;
		}

		return Check::STATUS_PASS === $check->status || Check::STATUS_WARN === $check->status;
	}

	/**
	 * The label of the first dependency that did not pass, for a skip message.
	 *
	 * @param string[] $ids Dependency check ids.
	 */
	public function first_blocker( array $ids ): string {
		foreach ( $ids as $id ) {
			if ( ! $this->passed( $id ) ) {
				$check = $this->result( $id );

				return null === $check ? $id : $check->label;
			}
		}

		return '';
	}
}
