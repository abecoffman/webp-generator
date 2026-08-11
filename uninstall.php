<?php
/**
 * Fires when the plugin is deleted from Plugins > Installed Plugins
 * (not on deactivation). Removes the plugin's own settings only.
 *
 * Deliberately does NOT touch:
 * - Generated .webp files -- they're the site's media, not plugin state.
 * - Any .htaccess rewrite rule the plugin installed (see
 *   WWG_Htaccess::install()) -- removing it here would silently break
 *   image serving for a site that's otherwise still running fine; that's
 *   a decision for the site owner to make deliberately via the "Remove
 *   it" button, not a side effect of deleting the plugin.
 *
 * WordPress runs this file standalone, without loading the rest of the
 * plugin, so the option/transient/cron-hook keys below are hardcoded
 * rather than referencing the classes' own constants -- keep them in
 * sync with WWG_Generator::OPTION_QUALITY (includes/class-wwg-generator.php),
 * WWG_Admin::OPTION_CLEAN_DIRS, WWG_Admin::OPTION_KNOWN_FAILURES,
 * WWG_Admin::OPTION_SCAN_STATE, and the transient/cron-hook keys used in
 * includes/class-wwg-admin.php and includes/class-wwg-job.php.
 *
 * @package WWG
 */

// If uninstall.php is not called by WordPress, exit (standard guard --
// prevents this file being hit directly).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wwg_quality' );
delete_option( 'wwg_clean_dirs' );
delete_option( 'wwg_known_failures' );
delete_option( 'wwg_scan_state' );
delete_transient( 'wwg_scan_dirs' );
delete_transient( 'wwg_convert_run_tally' ); // Stale key from before the Generate job moved to WP-Cron -- kept here for anyone upgrading from that version.
delete_transient( 'wwg_job_state' );
delete_transient( 'wwg_job_lock' );
wp_clear_scheduled_hook( 'wwg_process_job_tick' );

// Site-wide options/transients on multisite installs.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		delete_option( 'wwg_quality' );
		delete_option( 'wwg_clean_dirs' );
		delete_option( 'wwg_known_failures' );
		delete_option( 'wwg_scan_state' );
		delete_transient( 'wwg_scan_dirs' );
		delete_transient( 'wwg_convert_run_tally' );
		delete_transient( 'wwg_job_state' );
		delete_transient( 'wwg_job_lock' );
		wp_clear_scheduled_hook( 'wwg_process_job_tick' );

		restore_current_blog();
	}
}
