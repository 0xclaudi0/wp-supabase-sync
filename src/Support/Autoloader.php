<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * The plugin deliberately ships without a Composer autoloader. Bundling one
 * inside a WordPress plugin risks colliding with whatever another active plugin
 * has already loaded, and the dependency surface here is zero — this plugin
 * talks to Supabase over the WordPress HTTP API and nothing else.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Support;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/**
	 * Map a namespace prefix onto a base directory.
	 *
	 * @param string $prefix   Namespace prefix, without trailing separator.
	 * @param string $base_dir Directory holding the prefix root.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		$prefix   = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		$length   = strlen( $prefix );

		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir, $length ): void {
				if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
					return;
				}

				$relative = substr( $class_name, $length );
				$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
