<?php
/**
 * Core WebP/AVIF generation logic.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and cleans up derived-format (WebP, AVIF) siblings for image
 * attachments.
 */
class WWG_Generator {

	/**
	 * Mime types this plugin knows how to convert.
	 *
	 * @var string[]
	 */
	const SUPPORTED_MIME_TYPES = array( 'image/jpeg', 'image/png' );

	/**
	 * Option holding the WebP compression quality (1-100). Configurable
	 * from Tools > WebP Generator rather than hardcoded, since the right
	 * quality/size trade-off varies by site (a photography-heavy site
	 * may want higher fidelity than a blog mostly running icons/graphics).
	 * Deliberately still WebP-specific and unrenamed even now that AVIF
	 * exists alongside it (see WWG_Format::OPTION_QUALITY_AVIF for AVIF's
	 * own, separate option) -- every site that already set this means
	 * exactly "WebP quality," with zero migration needed.
	 *
	 * @var string
	 */
	const OPTION_QUALITY = 'wwg_quality';

	/**
	 * Default quality if the site hasn't set one -- matches what popular
	 * WebP encoders (e.g. cwebp) default to.
	 *
	 * @var int
	 */
	const DEFAULT_QUALITY = 75;

	/**
	 * Human-readable reason the most recent failed conversion attempt
	 * failed, set by convert_with_imagick()/convert_with_gd() and read
	 * back by ensure_format() -- an instance property rather than a return
	 * value so the `convert_with_imagick() || convert_with_gd()`
	 * short-circuit in ensure_format() can stay a one-liner.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_siblings' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_siblings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_support_notice' ) );
	}

	/**
	 * After WordPress finishes generating an attachment's sizes, create a
	 * sibling in every enabled derived format for the original file and
	 * every generated size. If any were actually new, best-effort clear
	 * any page cache that might have already baked in a decision made
	 * before those files existed (see class-wwg-cache.php).
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata -- this only adds files alongside,
	 *               it never rewrites what WordPress recorded.
	 */
	public function generate_siblings( $metadata, $attachment_id ) {
		if ( empty( $metadata['file'] )
			|| ! in_array( get_post_mime_type( $attachment_id ), self::SUPPORTED_MIME_TYPES, true )
		) {
			return $metadata;
		}

		// A brand new attachment can also mean a brand new upload
		// subfolder (e.g. a fresh "YYYY/MM" folder, or one a plugin
		// creates on demand) -- keep WWG_Admin's cached folder listing
		// (see its get_scan_directories()) from serving a stale list for
		// up to its 15-minute TTL.
		delete_transient( 'wwg_scan_dirs' );

		$created_any = false;
		$formats     = WWG_Format::enabled();

		foreach ( $this->get_source_files( $metadata ) as $file ) {
			foreach ( $this->ensure_formats( $file, $formats ) as $outcome ) {
				if ( 'created' === $outcome['status'] ) {
					$created_any = true;
				}
			}
		}

		if ( $created_any ) {
			WWG_Cache::clear_for_attachment( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Remove derived-format siblings when the attachment itself is
	 * deleted, so we don't accumulate files with no source image next to
	 * them.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_siblings( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['file'] ) ) {
			return;
		}

		foreach ( $this->get_source_files( $metadata ) as $file ) {
			foreach ( WWG_Format::all() as $format => $def ) {
				$derived = WWG_Format::path_for( $format, $file );
				if ( $derived && file_exists( $derived ) ) {
					wp_delete_file( $derived );
				}
			}
		}
	}

	/**
	 * Resolve the on-disk path of the original file plus every generated
	 * size recorded in an attachment's metadata.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return string[] Absolute paths.
	 */
	private function get_source_files( $metadata ) {
		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$subdir     = trailingslashit( dirname( $metadata['file'] ) );

		$files   = array();
		$files[] = $base_dir . $metadata['file'];

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $base_dir . $subdir . $size['file'];
				}
			}
		}

		return array_unique( $files );
	}

	/**
	 * ensure_format() for each of several formats against the same source
	 * file. Kept as its own method (not inlined at every call site)
	 * specifically so a future optimization -- skipping a second
	 * decode+recovery-search attempt once the first format has already
	 * failed for a source-level reason (missing/empty/corrupt file) -- has
	 * one place to land later. Not attempted here: today this simply
	 * calls ensure_format() once per format, so a corrupt source pays for
	 * a full decode+recovery attempt per enabled format, not just once.
	 * That only affects files that were already going to fail anyway.
	 *
	 * @param string   $source_path Absolute path to a .jpg/.jpeg/.png file.
	 * @param string[] $formats     Format ids (see WWG_Format) to ensure.
	 * @return array format => ensure_format()'s return.
	 */
	public function ensure_formats( $source_path, array $formats ) {
		$outcomes = array();
		foreach ( $formats as $format ) {
			$outcomes[ $format ] = $this->ensure_format( $source_path, $format );
		}
		return $outcomes;
	}

	/**
	 * Make sure a derived-format sibling exists for one file in one
	 * format, creating it if not. This is the single code path both the
	 * on-upload hook and the admin bulk-conversion screen use, so their
	 * behavior can't drift apart.
	 *
	 * @param string $source_path Absolute path to a .jpg/.jpeg/.png file.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return array {
	 *     @type string $status    One of 'created', 'exists', 'failed',
	 *                             'missing_source', 'empty_source',
	 *                             'unsupported', 'unsupported_backend'.
	 *     @type int    $bytes     Size of the resulting derived file.
	 *                             Only present when status is 'created' or
	 *                             'exists'.
	 *     @type bool   $recovered True if status is 'created' but the
	 *                             source file itself was still malformed --
	 *                             the derived file was produced from image
	 *                             data recovered from partway through the
	 *                             file (see attempt_recovery()). Only
	 *                             present when true.
	 *     @type string $error     Human-readable reason. Present for every
	 *                             status except 'created'/'exists'.
	 * }
	 */
	public function ensure_format( $source_path, $format ) {
		$target_path = WWG_Format::path_for( $format, $source_path );

		if ( ! $target_path ) {
			return array(
				'status' => 'unsupported',
				'error'  => __( 'Not a supported image type (only .jpg/.jpeg/.png are converted).', 'webp-generator' ),
			);
		}

		if ( ! file_exists( $source_path ) ) {
			return array(
				'status' => 'missing_source',
				'error'  => __( 'The source file no longer exists.', 'webp-generator' ),
			);
		}

		if ( file_exists( $target_path ) ) {
			return array(
				'status' => 'exists',
				'bytes'  => (int) filesize( $target_path ),
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish/become unreadable between file_exists() above and here.
		if ( 0 === (int) @filesize( $source_path ) ) {
			return array(
				'status' => 'empty_source',
				'error'  => __( 'The source file is 0 bytes (likely an interrupted upload or thumbnail generation). If this image size is still needed, regenerating thumbnails (e.g. via a plugin like Regenerate Thumbnails) or re-uploading the original may restore it.', 'webp-generator' ),
			);
		}

		if ( ! WWG_Format::has_support( $format ) ) {
			return array(
				'status' => 'unsupported_backend',
				/* translators: %s: format label, e.g. "AVIF". */
				'error'  => sprintf( __( 'Neither Imagick nor GD on this server was compiled with %s support.', 'webp-generator' ), WWG_Format::label( $format ) ),
			);
		}

		$this->last_error = '';

		// Imagick first: it copes with CMYK-colorspace JPEGs better than
		// GD, which shares the same libjpeg decoder that some encoders
		// (e.g. cwebp) fail on with an "unsupported color conversion"
		// error.
		$created = $this->convert_with_imagick( $source_path, $target_path, $format )
			|| $this->convert_with_gd( $source_path, $target_path, $format );

		// Both attempts above assume the file itself starts with a valid
		// image header. If it doesn't, see whether a complete image is
		// embedded somewhere further into the file (e.g. leftover HTTP
		// headers or other garbage prepended ahead of otherwise-valid
		// image bytes) before giving up.
		$recovered = false;
		if ( ! $created ) {
			$recovered = $this->attempt_recovery( $source_path, $target_path, $format );
			$created   = $recovered;
		}

		if ( $created && file_exists( $target_path ) ) {
			$result = array(
				'status' => 'created',
				'bytes'  => (int) filesize( $target_path ),
			);
			if ( $recovered ) {
				$result['recovered'] = true;
			}
			return $result;
		}

		return array(
			'status' => 'failed',
			'error'  => $this->last_error ? $this->last_error : __( 'Unknown error.', 'webp-generator' ),
		);
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $target_path Destination derived-file path.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return bool Success.
	 */
	private function convert_with_imagick( $source_path, $target_path, $format ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		$def = WWG_Format::get( $format );
		if ( ! $def ) {
			return false;
		}

		try {
			$image = new Imagick( $source_path );

			if ( 'CMYKColorspace' === $image->getImageColorspace() ) {
				$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			$image->setImageFormat( $def['imagick_format'] );
			$image->setImageCompressionQuality( $this->get_quality( $format ) );
			$result = $image->writeImage( $target_path );

			$image->clear();
			$image->destroy();

			if ( ! $result ) {
				/* translators: %s: format label, e.g. "AVIF". */
				$this->last_error = sprintf( __( 'Imagick::writeImage() returned false for %s.', 'webp-generator' ), $def['label'] );
			}

			return (bool) $result;
		} catch ( Exception $e ) {
			/* translators: %s: the underlying Imagick exception message. */
			$this->last_error = sprintf( __( 'Imagick: %s', 'webp-generator' ), $e->getMessage() );
			return false;
		}
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $target_path Destination derived-file path.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return bool Success.
	 */
	private function convert_with_gd( $source_path, $target_path, $format ) {
		$def = WWG_Format::get( $format );
		if ( ! $def || ! function_exists( $def['gd_function'] ) ) {
			return false;
		}

		$type  = wp_check_filetype( $source_path );
		$image = false;

		if ( 'image/jpeg' === $type['type'] && function_exists( 'imagecreatefromjpeg' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt/unreadable source file is an expected outcome here (see the two known-bad files this handles in production), not a bug to surface as a PHP warning; the null return already communicates failure to the caller.
			$image = @imagecreatefromjpeg( $source_path );
		} elseif ( 'image/png' === $type['type'] && function_exists( 'imagecreatefrompng' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			$image = @imagecreatefrompng( $source_path );
			if ( $image ) {
				imagepalettetotruecolor( $image );
				imagealphablending( $image, true );
				imagesavealpha( $image, true );
			}
		}

		if ( ! $image ) {
			$this->last_error = __( 'GD could not read the source image (corrupt file, or an unsupported JPEG/PNG variant).', 'webp-generator' );
			return false;
		}

		$gd_function = $def['gd_function'];
		$result      = $gd_function( $image, $target_path, $this->get_quality( $format ) );
		// No imagedestroy() call: GD images have been garbage-collected
		// objects since PHP 8.0, and calling it is a deprecation warning
		// as of PHP 8.5. $image goes out of scope on return regardless.

		if ( ! $result ) {
			/* translators: 1: GD function name, e.g. "imageavif". 2: format label, e.g. "AVIF". */
			$this->last_error = sprintf( __( 'GD %1$s() failed (possibly out of memory, or this GD build lacks real %2$s support).', 'webp-generator' ), $gd_function, $def['label'] );
		}

		return (bool) $result;
	}

	/**
	 * Last resort, called only once both normal attempts above have
	 * already failed. Some corrupt files (a real one seen in production:
	 * a partial download that still has a defunct third-party service's
	 * raw HTTP response headers prepended ahead of an otherwise-complete
	 * JPEG) contain a fully valid image starting partway through the
	 * file rather than at byte 0. This searches for that and, if found,
	 * retries conversion using only the bytes from that point onward --
	 * written to a throwaway temp file (matching the original's
	 * extension, so convert_with_gd()'s wp_check_filetype() dispatch
	 * still works unchanged) so both convert_with_imagick() and
	 * convert_with_gd() above can be reused exactly as-is rather than
	 * duplicating their CMYK-handling/quality/type-dispatch logic against
	 * an in-memory blob. Never modifies $source_path itself.
	 *
	 * @param string $source_path Original (still-broken) source file.
	 * @param string $target_path Destination derived-file path.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return bool Success.
	 */
	private function attempt_recovery( $source_path, $target_path, $format ) {
		/**
		 * Whether to attempt recovering a usable image from a corrupt
		 * source file by searching for an embedded JPEG/PNG signature
		 * further into the file. Return false to disable.
		 *
		 * @param bool   $attempt     Whether to attempt recovery. Default true.
		 * @param string $source_path Absolute path to the source file.
		 */
		if ( ! apply_filters( 'wwg_attempt_recovery', true, $source_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- reading raw local bytes for a byte-signature search, not a remote URL (WP_Filesystem is unused throughout this class already -- filesize()/file_exists() etc. are plain PHP calls elsewhere here too); the file can also legitimately vanish/shrink between the earlier checks in ensure_format() and here.
		$bytes = @file_get_contents( $source_path );
		if ( ! $bytes ) {
			return false;
		}

		$type = wp_check_filetype( $source_path );
		if ( 'image/jpeg' === $type['type'] ) {
			$needle = "\xFF\xD8\xFF";
		} elseif ( 'image/png' === $type['type'] ) {
			$needle = "\x89PNG\x0D\x0A\x1A\x0A";
		} else {
			return false;
		}

		// Start searching at offset 1, not 0 -- an offset-0 match is
		// exactly what the normal attempt above already tried and failed on.
		$offset = strpos( $bytes, $needle, 1 );
		if ( false === $offset ) {
			return false;
		}

		$ext      = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$tmp_path = trailingslashit( get_temp_dir() ) . 'wwg-recovery-' . wp_generate_password( 12, false ) . '.' . $ext;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a throwaway local temp file, not site content; see the file_get_contents() note above for why this class doesn't route through WP_Filesystem.
		if ( false === file_put_contents( $tmp_path, substr( $bytes, $offset ) ) ) {
			return false;
		}

		$recovered_ok = $this->convert_with_imagick( $tmp_path, $target_path, $format )
			|| $this->convert_with_gd( $tmp_path, $target_path, $format );

		if ( ! $recovered_ok ) {
			/* translators: 1: byte offset an embedded image signature was found at, 2: the decode error for that recovered data. */
			$this->last_error = sprintf( __( 'Found what looks like an embedded image at byte offset %1$d, but converting it also failed: %2$s', 'webp-generator' ), $offset, $this->last_error );
		}

		wp_delete_file( $tmp_path );

		return $recovered_ok;
	}

	/**
	 * The configured quality for one format, clamped to a sane range in
	 * case the option ever ends up holding something unexpected.
	 *
	 * @param string $format One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return int 1-100.
	 */
	public function get_quality( $format ) {
		$def = WWG_Format::get( $format );
		if ( ! $def ) {
			return self::DEFAULT_QUALITY;
		}

		$quality = (int) get_option( $def['quality_option'], $def['default_quality'] );

		return max( 1, min( 100, $quality ) );
	}

	/**
	 * Nudge in wp-admin if uploads are silently not getting any derived
	 * format generated because this server's Imagick/GD builds don't
	 * support any of them -- easy to miss otherwise, since the upload
	 * itself still succeeds. A server that supports WebP but not AVIF (or
	 * vice versa) gets no notice here -- that's normal, expected, and
	 * needs no admin action (see WWG_Format::enabled()).
	 */
	public function maybe_show_missing_support_notice() {
		if ( ! empty( WWG_Format::enabled() ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'WebP Generator: neither Imagick nor GD on this server was compiled with WebP or AVIF support, so new uploads are not getting any derived image files generated.', 'webp-generator' ) .
			'</p></div>';
	}
}
