<?php
/**
 * Admin warnings.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Admin;

use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Settings\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public function __construct( private readonly Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_key_storage_notice' ) );
	}

	/**
	 * Warn while the service role key lives in the options table.
	 *
	 * Not dismissible, and deliberately so. The risk does not go away when the
	 * notice does: the key is in every database dump, every automated backup,
	 * and every staging clone made from one — and it bypasses row level
	 * security, so a copy of it is full read/write access to the project.
	 *
	 * Storing it there still works, and for a quick evaluation it is a
	 * reasonable trade. The user just needs to know they made it.
	 */
	public function render_key_storage_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( Settings::SOURCE_OPTION !== $this->settings->service_key_source() ) {
			return;
		}

		$screen = get_current_screen();

		// Shown on the plugin's own screen and on the dashboard and plugins
		// list. Repeating it on every admin page is nagging, not helping.
		$relevant = array( 'dashboard', 'plugins', 'settings_page_' . SettingsPage::PAGE_SLUG );

		if ( null === $screen || ! in_array( $screen->id, $relevant, true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><code>%s</code></p><p>%s</p></div>',
			esc_html__( 'WP Supabase Sync: your Supabase service role key is stored in the database.', 'wp-supabase-sync' ),
			esc_html__(
				'That key bypasses row level security, so anyone with a copy of your database — a backup, a staging clone, a leaked dump — has full read and write access to your Supabase project. Move it into wp-config.php instead, then clear it from the settings screen.',
				'wp-supabase-sync'
			),
			"define( '" . esc_html( Settings::CONST_SERVICE_KEY ) . "', 'your-service-role-key' );",
			sprintf(
				/* translators: %s: link to the settings screen. */
				wp_kses_post( __( 'Settings for this plugin are under %s.', 'wp-supabase-sync' ) ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG ) ) . '">'
					. esc_html__( 'Settings → Supabase Sync', 'wp-supabase-sync' ) . '</a>'
			)
		);
	}
}
