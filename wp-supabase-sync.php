<?php
/**
 * Plugin Name:       WP Supabase Sync
 * Plugin URI:        https://github.com/0xclaudi0/wp-supabase-sync
 * Description:       Connects WordPress to Supabase. Mirrors published content into a Supabase Postgres table so a separate frontend can read it straight from Supabase instead of the WP REST API.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Claudio Ibe
 * Author URI:        https://github.com/0xclaudi0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-supabase-sync
 * Domain Path:       /languages
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;

require_once __DIR__ . '/src/Support/Autoloader.php';

Support\Autoloader::register( __NAMESPACE__, __DIR__ . '/src' );

/**
 * Shared plugin instance.
 */
function plugin(): Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
	}

	return $plugin;
}

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

plugin()->boot();
