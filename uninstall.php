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
 * plugin, so the option/transient keys below are hardcoded rather than
 * referencing the classes' own constants -- keep them in sync with
 * WWG_Generator::OPTION_QUALITY (includes/class-wwg-generator.php) and
 * the transient keys used in includes/class-wwg-admin.php.
 *
 * @package WWG
 */

// If uninstall.php is not called by WordPress, exit (standard guard --
// prevents this file being hit directly).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wwg_quality' );
delete_transient( 'wwg_scan_dirs' );
delete_transient( 'wwg_convert_run_tally' );

// Site-wide options/transients on multisite installs.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		delete_option( 'wwg_quality' );
		delete_transient( 'wwg_scan_dirs' );
		delete_transient( 'wwg_convert_run_tally' );

		restore_current_blog();
	}
}
