<?php
/**
 * Outcome of syncing one object.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Sync;

defined( 'ABSPATH' ) || exit;

final class SyncResult {

	/**
	 * Prefer the named constructors below; they encode the valid combinations.
	 *
	 * @param bool   $success True when the desired state was reached.
	 * @param string $action  What was done: upsert, delete, or skip.
	 * @param bool   $skipped True when nothing was sent because nothing changed.
	 * @param string $message Human explanation, already translated and redacted.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $action,
		public readonly bool $skipped = false,
		public readonly string $message = ''
	) {}

	public static function upserted(): self {
		return new self( true, Queue::ACTION_UPSERT );
	}

	public static function deleted(): self {
		return new self( true, Queue::ACTION_DELETE );
	}

	public static function unchanged(): self {
		return new self( true, 'skip', true, __( 'Content hash unchanged; nothing sent.', 'wp-supabase-sync' ) );
	}

	public static function disabled(): self {
		return new self( false, 'skip', true, __( 'Syncing is turned off in settings.', 'wp-supabase-sync' ) );
	}

	public static function failed( string $action, string $message ): self {
		return new self( false, $action, false, $message );
	}
}
