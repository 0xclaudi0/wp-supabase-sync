<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's own tables and options, and only when the user opted in.
 * Nothing in Supabase is touched: this plugin did not create that project and
 * deleting someone's content because they removed a WordPress plugin would be
 * indefensible.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Support/Autoloader.php';

Support\Autoloader::register( __NAMESPACE__, __DIR__ . '/src' );

$wpsb_settings = get_option( Settings\Settings::OPTION, array() );

if ( is_array( $wpsb_settings ) && ! empty( $wpsb_settings['delete_data_on_uninstall'] ) ) {
	Installer::drop_everything();
}

unset( $wpsb_settings );
