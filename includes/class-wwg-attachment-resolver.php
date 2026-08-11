<?php
/**
 * Bridges a raw filesystem path under uploads/ back to the WordPress
 * attachment (post) it belongs to, if any.
 *
 * This is new territory for this plugin: Scan/Generate/the known-failures
 * cache all operate exclusively on filesystem paths and have never needed
 * to know which attachment a file belongs to. Isolated into its own class
 * (rather than folded into WWG_Admin) specifically so that boundary stays
 * visible -- this is the one place the plugin reaches into wp_posts/
 * postmeta at all.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless resolution helpers -- no instance state, mirrors WWG_Cache/
 * WWG_Htaccess's static-utility-class pattern rather than WWG_Generator/
 * WWG_Job's instance pattern (those hold real per-request state; this
 * doesn't).
 */
class WWG_Attachment_Resolver {

	/**
	 * Figure out what a broken file actually is, from WordPress's own
	 * point of view: the recorded original of some attachment, a
	 * generated size of one, or nothing WordPress knows about at all.
	 * Fails closed on any ambiguity -- never guesses from filename
	 * numbers alone; every positive result is confirmed against what
	 * WordPress itself actually recorded.
	 *
	 * @param string $file_rel Relative path under the uploads basedir,
	 *                         e.g. "2011/03/hat-80x80.jpg".
	 * @return array|false {
	 *     @type int         $attachment_id Attachment (post) ID.
	 *     @type bool        $is_original   True if $file_rel is the
	 *                                      attachment's own recorded
	 *                                      original (or WP's own
	 *                                      "-scaled" copy of it).
	 *     @type string|null $size_name     The registered size name this
	 *                                      file matches, e.g. "thumbnail".
	 *                                      Null when $is_original is true.
	 *     @type string|null $original_path Absolute path to the
	 *                                      attachment's healthy original
	 *                                      file. Null when $is_original
	 *                                      is true (nothing "more
	 *                                      original" than itself).
	 * } False if no attachment could be resolved at all.
	 */
	public static function resolve( $file_rel ) {
		$upload_dir = wp_get_upload_dir();
		$url        = trailingslashit( $upload_dir['baseurl'] ) . $file_rel;

		// attachment_url_to_postid() only ever matches an attachment's own
		// recorded file (_wp_attached_file) -- a hit here means $file_rel
		// *is* that recorded file, whether that's the literal upload or
		// WordPress's own "-scaled" down-sized copy of it (both are
		// legitimately "the original" from this plugin's point of view;
		// neither is a generated size).
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			return array(
				'attachment_id' => $attachment_id,
				'is_original'   => true,
				'size_name'     => null,
				'original_path' => null,
			);
		}

		// Not the recorded original -- see if it looks like one of
		// WordPress's own generated sizes, which always append
		// "-{width}x{height}" immediately before the extension. Anchored
		// so a filename that happens to legitimately contain similar
		// numbers elsewhere isn't mistaken for one (WordPress's own
		// convention only ever appends this suffix at the very end).
		$original_rel = preg_replace( '/-\d+x\d+(?=\.[^.\/]+$)/', '', $file_rel, 1, $replaced );
		if ( ! $replaced ) {
			return false; // Doesn't even look like a generated size.
		}

		$original_url  = trailingslashit( $upload_dir['baseurl'] ) . $original_rel;
		$attachment_id = attachment_url_to_postid( $original_url );
		if ( ! $attachment_id ) {
			return false;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return false;
		}

		// Confirm against what the attachment's own metadata actually
		// recorded for some size -- not a re-derived guess from the
		// filename's numbers, which is what the regex above only
		// tentatively suggested.
		$target_basename = basename( $file_rel );
		$size_name       = null;
		foreach ( $metadata['sizes'] as $name => $size_data ) {
			if ( isset( $size_data['file'] ) && $target_basename === $size_data['file'] ) {
				$size_name = $name;
				break;
			}
		}

		if ( null === $size_name ) {
			return false; // Looked like a derivative, but this attachment doesn't confirm it.
		}

		// wp_get_original_image_path() (not get_attached_file()) so a
		// site that's had "-scaled" down-sizing applied to the original
		// still resolves to the real, full-resolution source to
		// regenerate this size from.
		$original_path = wp_get_original_image_path( $attachment_id );
		if ( ! $original_path ) {
			$original_path = get_attached_file( $attachment_id );
		}

		return array(
			'attachment_id' => $attachment_id,
			'is_original'   => false,
			'size_name'     => $size_name,
			'original_path' => $original_path ? $original_path : null,
		);
	}
}
