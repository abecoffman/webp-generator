<?php
/**
 * Best-effort integration with common WordPress page-cache plugins.
 *
 * Confirmed problem this solves: caches that rewrite <img> tags to .webp
 * at cache-generation time (e.g. Cache Enabler's "Convert image URLs to
 * WebP" option) bake that decision into the cached HTML. If a page was
 * cached before a .webp sibling existed, it keeps serving the original
 * format until that cache entry expires or is cleared -- generating the
 * .webp file after the fact doesn't help until the cache catches up.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers that clear cached pages across whichever supported cache
 * plugin(s) are active. Every call is guarded by a class/function_exists()
 * check, so this is a safe no-op on sites without any of them installed.
 */
class WWG_Cache {

	/**
	 * Best-effort purge for one attachment: its own permalink (attachment
	 * pages exist) and the post it was uploaded into, if any. There's no
	 * cheap way in WordPress to find every post that might embed a given
	 * image elsewhere in its content, so this is a heuristic, not a
	 * guarantee -- clear_all() is what bulk operations use instead, since
	 * they touch too many arbitrary posts for this to be worth it.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function clear_for_attachment( $attachment_id ) {
		$post_ids = array_unique(
			array_filter(
				array(
					(int) $attachment_id,
					(int) wp_get_post_parent_id( $attachment_id ),
				)
			)
		);

		foreach ( $post_ids as $post_id ) {
			self::clear_post( $post_id );
		}

		/**
		 * Fires after WP WebP Generator's own best-effort per-attachment
		 * cache clearing. Hook in here for any cache layer this plugin
		 * doesn't already know about.
		 *
		 * @since 1.2.0
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'wwg_clear_cache_for_attachment', $attachment_id );
	}

	/**
	 * Which of the cache plugins this class knows how to clear are
	 * actually active on this site -- read-only, for display on the
	 * Tools > WebP Generator readiness panel so a site owner can see
	 * up front whether cache-clearing will do anything.
	 *
	 * @return string[] Human-readable names, e.g. array( 'Cache Enabler' ).
	 */
	public static function detect_active_backends() {
		$backends = array();

		if ( class_exists( 'Cache_Enabler' ) ) {
			$backends[] = 'Cache Enabler';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$backends[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$backends[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$backends[] = 'WP Super Cache';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$backends[] = 'LiteSpeed Cache';
		}

		return $backends;
	}

	/**
	 * Clear one post's cached page across every supported cache plugin
	 * that's active.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function clear_post( $post_id ) {
		if ( class_exists( 'Cache_Enabler' ) ) {
			Cache_Enabler::clear_post_cache( $post_id );
		}

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}

		// LiteSpeed Cache listens for this regardless of whether it's
		// active, so it's safe to fire unconditionally.
		do_action( 'litespeed_purge_post', $post_id );
	}

	/**
	 * Full site cache clear across every supported cache plugin that's
	 * active. Used after a bulk conversion run, where figuring out
	 * exactly which posts embed which images isn't worth it -- a run can
	 * touch images scattered across years of old posts.
	 */
	public static function clear_all() {
		if ( class_exists( 'Cache_Enabler' ) ) {
			Cache_Enabler::clear_complete_cache();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		do_action( 'litespeed_purge_all' );

		/**
		 * Fires after WP WebP Generator's own best-effort full-site cache
		 * clear (e.g. after a Tools > WebP Generator bulk conversion run
		 * that actually created new files).
		 *
		 * @since 1.2.0
		 */
		do_action( 'wwg_clear_all_cache' );
	}
}
