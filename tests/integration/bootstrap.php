<?php
/**
 * PHPUnit bootstrap for the integration tier: real WordPress, real DB
 * (wp_plugin_sandbox_test -- see bin/install-wp-tests.sh), real file I/O.
 * Distinct from tests/bootstrap.php (the fast Brain Monkey unit tier,
 * untouched by this) -- this tier exists specifically for things the
 * unit tier structurally can't cover: real image conversion via
 * Imagick/GD, real .htaccess file writes, real hook wiring.
 *
 * @package WWG
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_webp_generator_plugin() {
	require dirname( dirname( __DIR__ ) ) . '/webp-generator.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_webp_generator_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
