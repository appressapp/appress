<?php
/**
 * Plugin Name:       Appress — Mobile App Builder
 * Plugin URI:        https://appress.app
 * Description:       Build and manage multiple native mobile apps (iOS & Android) from a single site. No coding required.
 * Version:           1.0.0.34
 * Requires at least: 5.8
 * Tested up to:      6.9
 * Requires PHP:      8.3
 * Author:            Appress
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       appress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'APPRESS_VERSION', '1.0.0.34' );
define( 'APPRESS_PLUGIN_FILE', __FILE__ );
define( 'APPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APPRESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Dev-mode flag. Defaults to false (production). Override in wp-config.php
// with `define( 'APPRESS_IS_DEV', true );` on local/staging to enable
// cache-busting timestamps on asset versions and relax sslverify.
if ( ! defined( 'APPRESS_IS_DEV' ) ) {
	define( 'APPRESS_IS_DEV', 1 );
}

if ( ! defined( 'APPRESS_CENTRAL_URL' ) ) {
	define( 'APPRESS_CENTRAL_URL', 'https://my.appress.app' );
}

// Procedural helpers (\Appress\config, \Appress\get, \Appress\is_app, …)
// — the PSR-4 autoloader below only handles classes, so require them
// manually. Living at `app/helpers.php` keeps the `app/utils/` folder
// consistent as `Appress\Utils\*` classes only.
require_once APPRESS_PLUGIN_DIR . 'app/helpers.php';

// Autoloader function
spl_autoload_register( function ( $class_name ) {
	$namespace_prefix = 'Appress\\';
	if ( strpos( $class_name, $namespace_prefix ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, strlen( $namespace_prefix ) );
	$parts = explode( '\\', ltrim( $relative_class, '\\' ) );
	
	$class_name_part = end( $parts );
	$file_name = strtolower( str_replace( '_', '-', $class_name_part ) ) . '.php'; // WordPress & Voxel File Naming Standard
	
	array_pop( $parts );
	$dir_path = '';
	if ( ! empty( $parts ) ) {
		// Lowercase the directory names
		foreach ( $parts as &$part ) {
			$part = strtolower( str_replace( '_', '-', $part ) );
		}
		$dir_path = implode( DIRECTORY_SEPARATOR, $parts ) . DIRECTORY_SEPARATOR;
	}

	$file_path = APPRESS_PLUGIN_DIR . 'app/' . $dir_path . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
});

// Bootstrap plugin — instantiate every registered controller. Each
// controller's constructor wires its own WP hooks; plugin-level glue
// (deactivation cleanup, action links, notice suppression) lives in
// `Plugin_Controller`, NOT here.
add_action( 'plugins_loaded', function() {
	foreach ( (array) \Appress\config( 'controllers' ) as $controller ) {
		if ( class_exists( $controller ) ) {
			new $controller();
		}
	}
} );
