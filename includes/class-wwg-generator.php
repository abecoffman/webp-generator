<?php
/**
 * Core WebP generation logic.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and cleans up .webp siblings for image attachments.
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
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_siblings' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_siblings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_support_notice' ) );
	}

	/**
	 * After WordPress finishes generating an attachment's sizes, create a
	 * .webp sibling for the original file and every generated size. If
	 * any were actually new, best-effort clear any page cache that might
	 * have already baked in a decision made before those files existed
	 * (see class-wwg-cache.php).
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

		foreach ( $this->get_source_files( $metadata ) as $file ) {
			$outcome = $this->ensure_webp( $file );
			if ( 'created' === $outcome['status'] ) {
				$created_any = true;
			}
		}

		if ( $created_any ) {
			WWG_Cache::clear_for_attachment( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Remove .webp siblings when the attachment itself is deleted, so we
	 * don't accumulate files with no source image next to them.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_siblings( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['file'] ) ) {
			return;
		}

		foreach ( $this->get_source_files( $metadata ) as $file ) {
			$webp = $this->webp_path_for( $file );
			if ( $webp && file_exists( $webp ) ) {
				wp_delete_file( $webp );
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
	 * Make sure a .webp sibling exists for one file, creating it if not.
	 * This is the single code path both the on-upload hook and the admin
	 * bulk-conversion screen use, so their behavior can't drift apart.
	 *
	 * @param string $source_path Absolute path to a .jpg/.jpeg/.png file.
	 * @return array {
	 *     @type string $status     One of 'created', 'exists', 'failed',
	 *                              'missing_source', 'unsupported',
	 *                              'unsupported_backend'.
	 *     @type int    $webp_bytes Size of the resulting .webp file.
	 *                              Only present when status is 'created' or
	 *                              'exists'.
	 * }
	 */
	public function ensure_webp( $source_path ) {
		$webp_path = $this->webp_path_for( $source_path );

		if ( ! $webp_path ) {
			return array( 'status' => 'unsupported' );
		}

		if ( ! file_exists( $source_path ) ) {
			return array( 'status' => 'missing_source' );
		}

		if ( file_exists( $webp_path ) ) {
			return array(
				'status'     => 'exists',
				'webp_bytes' => (int) filesize( $webp_path ),
			);
		}

		if ( ! $this->has_webp_support() ) {
			return array( 'status' => 'unsupported_backend' );
		}

		// Imagick first: it copes with CMYK-colorspace JPEGs better than
		// GD, which shares the same libjpeg decoder that some encoders
		// (e.g. cwebp) fail on with an "unsupported color conversion"
		// error.
		$created = $this->convert_with_imagick( $source_path, $webp_path )
			|| $this->convert_with_gd( $source_path, $webp_path );

		if ( $created && file_exists( $webp_path ) ) {
			return array(
				'status'     => 'created',
				'webp_bytes' => (int) filesize( $webp_path ),
			);
		}

		return array( 'status' => 'failed' );
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $webp_path   Destination .webp path.
	 * @return bool Success.
	 */
	private function convert_with_imagick( $source_path, $webp_path ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$image = new Imagick( $source_path );

			if ( 'CMYKColorspace' === $image->getImageColorspace() ) {
				$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $this->get_quality() );
			$result = $image->writeImage( $webp_path );

			$image->clear();
			$image->destroy();

			return (bool) $result;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $webp_path   Destination .webp path.
	 * @return bool Success.
	 */
	private function convert_with_gd( $source_path, $webp_path ) {
		if ( ! function_exists( 'imagewebp' ) ) {
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
			return false;
		}

		$result = imagewebp( $image, $webp_path, $this->get_quality() );
		// No imagedestroy() call: GD images have been garbage-collected
		// objects since PHP 8.0, and calling it is a deprecation warning
		// as of PHP 8.5. $image goes out of scope on return regardless.

		return (bool) $result;
	}

	/**
	 * @param string $source_path Path with a .jpg/.jpeg/.png extension.
	 * @return string|false The equivalent .webp path, or false if the
	 *                       extension isn't one this plugin converts.
	 */
	private function webp_path_for( $source_path ) {
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $source_path ) ) {
			return false;
		}

		return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );
	}

	/**
	 * The configured WebP quality, clamped to a sane range in case the
	 * option ever ends up holding something unexpected.
	 *
	 * @return int 1-100.
	 */
	public function get_quality() {
		$quality = (int) get_option( self::OPTION_QUALITY, self::DEFAULT_QUALITY );

		return max( 1, min( 100, $quality ) );
	}

	/**
	 * @return bool Whether either conversion backend is available on this
	 *              server.
	 */
	public function has_webp_support() {
		return ( class_exists( 'Imagick' ) && count( Imagick::queryFormats( 'WEBP' ) ) > 0 )
			|| function_exists( 'imagewebp' );
	}

	/**
	 * Which backend ensure_webp() will actually use, for display on the
	 * Tools > WebP Generator readiness panel.
	 *
	 * @return string One of 'Imagick', 'GD', or '' if neither supports
	 *                WebP on this server.
	 */
	public function get_active_backend() {
		if ( class_exists( 'Imagick' ) && count( Imagick::queryFormats( 'WEBP' ) ) > 0 ) {
			return 'Imagick';
		}

		if ( function_exists( 'imagewebp' ) ) {
			return 'GD';
		}

		return '';
	}

	/**
	 * Nudge in wp-admin if uploads are silently not getting .webp
	 * siblings because the server's Imagick/GD builds lack WebP support --
	 * easy to miss otherwise, since the upload itself still succeeds.
	 */
	public function maybe_show_missing_support_notice() {
		if ( $this->has_webp_support() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'WebP Generator: neither Imagick nor GD on this server was compiled with WebP support, so new uploads are not getting .webp siblings generated.', 'webp-generator' ) .
			'</p></div>';
	}
}
