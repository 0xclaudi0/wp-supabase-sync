<?php
/**
 * The result of one diagnostic check.
 *
 * `detail` states what was actually observed. `fix` states what to do about it,
 * with literal SQL where SQL is the answer. Neither may be a generic apology:
 * "something went wrong" costs the reader time to discover it is useless.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics;

use WPSupabaseSync\Support\Redactor;

defined( 'ABSPATH' ) || exit;

final class Check {

	public const STATUS_PASS = 'pass';
	public const STATUS_WARN = 'warn';
	public const STATUS_FAIL = 'fail';
	public const STATUS_SKIP = 'skip';

	/**
	 * Private: use the pass/warn/fail/skip factories, which redact as they build.
	 *
	 * @param string $id      Stable identifier, e.g. grants.
	 * @param string $label   Human name, e.g. Table privileges (GRANT).
	 * @param string $status  One of the STATUS_ constants.
	 * @param string $detail  What was observed.
	 * @param string $fix     What to do about it.
	 * @param string $doc_url Link to the relevant gotcha writeup.
	 */
	private function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $status,
		public readonly string $detail,
		public readonly string $fix = '',
		public readonly string $doc_url = ''
	) {}

	public static function pass( string $id, string $label, string $detail ): self {
		return new self( $id, $label, self::STATUS_PASS, Redactor::redact( $detail ) );
	}

	public static function warn( string $id, string $label, string $detail, string $fix = '', string $doc_url = '' ): self {
		return new self( $id, $label, self::STATUS_WARN, Redactor::redact( $detail ), Redactor::redact( $fix ), $doc_url );
	}

	public static function fail( string $id, string $label, string $detail, string $fix = '', string $doc_url = '' ): self {
		return new self( $id, $label, self::STATUS_FAIL, Redactor::redact( $detail ), Redactor::redact( $fix ), $doc_url );
	}

	/**
	 * A check that could not run because something upstream of it failed.
	 *
	 * Distinct from `fail` on purpose. Cascading red hides the diagnosis; one
	 * accurate red surrounded by honest greys is the diagnosis.
	 */
	public static function skip( string $id, string $label, string $detail ): self {
		return new self( $id, $label, self::STATUS_SKIP, Redactor::redact( $detail ) );
	}

	public function is_problem(): bool {
		return self::STATUS_FAIL === $this->status || self::STATUS_WARN === $this->status;
	}

	/**
	 * Flatten for JSON output, used by `wp supabase doctor --format=json`.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'label'   => $this->label,
			'status'  => $this->status,
			'detail'  => $this->detail,
			'fix'     => $this->fix,
			'doc_url' => $this->doc_url,
		);
	}
}
