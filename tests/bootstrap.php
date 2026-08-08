<?php
/**
 * PHPUnit bootstrap for the lightweight (no WP install/DB) unit suite.
 * Uses Brain Monkey to stub WordPress functions rather than booting real
 * WordPress -- fast, but only exercises pure logic, not real hook wiring.
 * The plugin's own manual/live testing (documented in the project's
 * memory notes) is what actually exercises the real WP integration.
 *
 * @package WWG
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The plugin's own files guard on ABSPATH being defined, same as any
// WordPress plugin file loaded outside of WordPress itself.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! defined( 'WWG_PATH' ) ) {
	define( 'WWG_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WWG_FILE' ) ) {
	define( 'WWG_FILE', WWG_PATH . 'webp-generator.php' );
}
if ( ! defined( 'WWG_VERSION' ) ) {
	define( 'WWG_VERSION', 'test' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

require_once WWG_PATH . 'includes/class-wwg-cache.php';
require_once WWG_PATH . 'includes/class-wwg-generator.php';
require_once WWG_PATH . 'includes/class-wwg-htaccess.php';
require_once WWG_PATH . 'includes/class-wwg-admin.php';
require_once WWG_PATH . 'includes/class-wwg-job.php';
