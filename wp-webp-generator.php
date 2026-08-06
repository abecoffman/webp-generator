<?php
/**
 * Plugin Name: WP WebP Generator
 * Description: Generates a .webp sibling for every size of newly uploaded JPEG/PNG images, and can install the Apache .htaccess rule that serves them to browsers that support it (falls back to a manual example for other servers). Also adds a Tools > WebP Generator admin screen to scan the existing media library for images still missing a .webp version and convert them on demand, and clears common page caches (Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache) when it generates new files.
 * Version:     1.9.0
 * Author:      Abe Coffman
 * License:     GPL-2.0-or-later
 * Text Domain: wp-webp-generator
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WWG_VERSION', '1.9.0' );
define( 'WWG_PATH', plugin_dir_path( __FILE__ ) );
define( 'WWG_FILE', __FILE__ );

require_once WWG_PATH . 'includes/class-wwg-cache.php';
require_once WWG_PATH . 'includes/class-wwg-generator.php';
require_once WWG_PATH . 'includes/class-wwg-htaccess.php';
require_once WWG_PATH . 'includes/class-wwg-admin.php';

/**
 * Boot the plugin.
 */
function wwg_init() {
	$generator = new WWG_Generator();
	$generator->init();

	if ( is_admin() ) {
		$admin = new WWG_Admin( $generator );
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'wwg_init' );

/**
 * Load translations. WordPress.org auto-loads translations for
 * plugins it hosts as of WP 4.6, so this is a no-op there -- it's for
 * the manual/GitHub-zip install path (see README.md), where nothing
 * else would load a .mo file from /languages.
 */
function wwg_load_textdomain() {
	load_plugin_textdomain( 'wp-webp-generator', false, dirname( plugin_basename( WWG_FILE ) ) . '/languages' );
}
add_action( 'init', 'wwg_load_textdomain' );
