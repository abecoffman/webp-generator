<?php
/**
 * Plugin Name: WebP Generator
 * Description: Generates a .webp sibling for every size of newly uploaded JPEG/PNG images, and can install the Apache .htaccess rule that serves them to browsers that support it (falls back to a manual example for other servers). Also adds a Tools > WebP Generator admin screen to scan the existing media library for images still missing a .webp version and convert them on demand, and clears common page caches (Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache) when it generates new files.
 * Version:     1.12.0
 * Author:      Abe Coffman
 * License:     GPL-2.0-or-later
 * Text Domain: webp-generator
 *
 * Internal PHP identifiers (classes, constants, functions, option/hook
 * names) keep the historical WWG_/wwg_ prefix ("WP WebP Generator", this
 * plugin's working name before "wp" turned out to be a restricted term
 * for the public-facing plugin Name/Slug -- see readme.txt). That's a
 * purely cosmetic mismatch with the current display name; the prefix
 * itself is still unique/non-colliding, and WordPress.org's Plugin
 * Check tool only flags the Name/Slug, not internal code identifiers,
 * so renaming those wasn't necessary and would have been a much larger,
 * riskier change for no compliance benefit.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WWG_VERSION', '1.12.0' );
define( 'WWG_PATH', plugin_dir_path( __FILE__ ) );
define( 'WWG_FILE', __FILE__ );

require_once WWG_PATH . 'includes/class-wwg-cache.php';
require_once WWG_PATH . 'includes/class-wwg-generator.php';
require_once WWG_PATH . 'includes/class-wwg-htaccess.php';
require_once WWG_PATH . 'includes/class-wwg-admin.php';
require_once WWG_PATH . 'includes/class-wwg-job.php';

/**
 * Boot the plugin.
 */
function wwg_init() {
	$generator = new WWG_Generator();
	$generator->init();

	$admin = new WWG_Admin( $generator );
	$job   = new WWG_Job( $admin );
	$admin->set_job( $job );

	// Registers only the cron tick hook -- must run on every request
	// type, including wp-cron.php dispatches, which never set is_admin()
	// (see WWG_Job::CRON_HOOK's docblock).
	$job->init();

	if ( is_admin() ) {
		$admin->init();
		$job->init_admin();
	}
}
add_action( 'plugins_loaded', 'wwg_init' );

register_deactivation_hook( WWG_FILE, 'wwg_deactivate' );
/**
 * Stop the background job from continuing to fire after deactivation.
 * Deliberately does NOT delete the job's own result (wwg_job_state) --
 * reactivating shortly after should still show the last run's summary;
 * full removal on actual uninstall is uninstall.php's job.
 */
function wwg_deactivate() {
	wp_clear_scheduled_hook( WWG_Job::CRON_HOOK );
	delete_transient( WWG_Job::LOCK_KEY );
}
