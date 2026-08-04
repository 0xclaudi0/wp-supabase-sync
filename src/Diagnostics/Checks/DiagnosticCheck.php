<?php
/**
 * Contract for a single diagnostic check.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;

defined( 'ABSPATH' ) || exit;

interface DiagnosticCheck {

	/**
	 * Stable identifier, used for dependencies and JSON output.
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 */
	public function label(): string;

	/**
	 * Check ids that must have passed for this one to be meaningful.
	 *
	 * The runner skips this check when any of them did not, so a single root
	 * cause produces one red result rather than a cascade of them.
	 *
	 * @return string[]
	 */
	public function depends_on(): array;

	/**
	 * Run the check.
	 */
	public function run( Context $context ): Check;
}
