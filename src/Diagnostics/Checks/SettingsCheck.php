<?php
/**
 * Check 1: is the plugin configured at all?
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics\Checks;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\Context;
use WPSupabaseSync\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsCheck implements DiagnosticCheck {

	public function id(): string {
		return 'settings';
	}

	public function label(): string {
		return __( 'Settings', 'wp-supabase-sync' );
	}

	public function depends_on(): array {
		return array();
	}

	public function run( Context $context ): Check {
		$settings = $context->settings;
		$url      = $settings->project_url();
		$key      = $settings->service_role_key();

		if ( '' === $url && '' === $key ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				__( 'Neither a project URL nor a service role key is configured.', 'wp-supabase-sync' ),
				__( 'Add both under Settings → Supabase Sync. The project URL and keys are in your Supabase dashboard under Settings → Data API and Settings → API Keys.', 'wp-supabase-sync' )
			);
		}

		if ( '' === $url ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				__( 'No project URL is configured, or what was entered is not a valid http/https URL.', 'wp-supabase-sync' ),
				__( 'Set the project URL under Settings → Supabase Sync. It looks like https://yourproject.supabase.co — the bare project URL, with no /rest/v1 on the end.', 'wp-supabase-sync' )
			);
		}

		if ( '' === $key ) {
			return Check::fail(
				$this->id(),
				$this->label(),
				__( 'No service role key is configured, so the plugin cannot write anything.', 'wp-supabase-sync' ),
				sprintf(
					/* translators: %s: PHP define() statement. */
					__( 'Add it under Settings → Supabase Sync, or better, define it in wp-config.php: %s', 'wp-supabase-sync' ),
					"define( '" . Settings::CONST_SERVICE_KEY . "', '…' );"
				)
			);
		}

		$detail = sprintf(
			/* translators: 1: project URL, 2: table name, 3: site id. */
			__( 'Project URL %1$s, table public.%2$s, site ID "%3$s".', 'wp-supabase-sync' ),
			$url,
			$settings->table_name(),
			$settings->site_id()
		);

		// Working but risky: the key is in the database rather than wp-config.php.
		if ( Settings::SOURCE_OPTION === $settings->service_key_source() ) {
			return Check::warn(
				$this->id(),
				$this->label(),
				$detail . ' ' . __( 'The service role key is stored in the WordPress database.', 'wp-supabase-sync' ),
				sprintf(
					/* translators: %s: PHP define() statement. */
					__( 'That key bypasses row level security, so every database backup and staging clone now contains full access to your Supabase project. Move it to wp-config.php and clear it from the settings screen: %s', 'wp-supabase-sync' ),
					"define( '" . Settings::CONST_SERVICE_KEY . "', '…' );"
				)
			);
		}

		return Check::pass( $this->id(), $this->label(), $detail . ' ' . __( 'The service role key comes from wp-config.php.', 'wp-supabase-sync' ) );
	}
}
