<?php
/**
 * Per-format metadata for every derived image format this plugin can
 * produce (currently WebP and AVIF) -- the one place that knows a format's
 * file extension, Accept-header mime type, ImageMagick/GD identifiers, and
 * quality option, so no other class hardcodes 'webp'/'avif' literals.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry -- never instantiated, matches WWG_Htaccess/WWG_Cache's
 * own no-instantiation pattern.
 */
class WWG_Format {

	/**
	 * @var string
	 */
	const WEBP = 'webp';

	/**
	 * @var string
	 */
	const AVIF = 'avif';

	/**
	 * Best-first: what the .htaccess rule and any UI ordering follow.
	 * AVIF first -- roughly half the file size of WebP at comparable
	 * quality, so it wins whenever a browser's Accept header supports it.
	 *
	 * @var string[]
	 */
	const PRIORITY = array( self::AVIF, self::WEBP );

	/**
	 * AVIF's own quality option -- WebP's (WWG_Generator::OPTION_QUALITY)
	 * stays exactly as it's always been; see definitions() below for why.
	 *
	 * @var string
	 */
	const OPTION_QUALITY_AVIF = 'wwg_quality_avif';

	/**
	 * @var int
	 */
	const DEFAULT_QUALITY_AVIF = 75;

	/**
	 * Per-format metadata. A method rather than a class constant so it can
	 * reference WWG_Generator::OPTION_QUALITY/DEFAULT_QUALITY for the webp
	 * entry without a class-constant-expression load-order dependency
	 * between this file and class-wwg-generator.php.
	 *
	 * @return array<string,array{id:string,label:string,extension:string,mime:string,imagick_format:string,gd_function:string,quality_option:string,default_quality:int}>
	 */
	private static function definitions() {
		return array(
			self::AVIF => array(
				'id'              => self::AVIF,
				'label'           => 'AVIF',
				'extension'       => 'avif',
				'mime'            => 'image/avif',
				'imagick_format'  => 'AVIF',
				'gd_function'     => 'imageavif',
				'quality_option'  => self::OPTION_QUALITY_AVIF,
				'default_quality' => self::DEFAULT_QUALITY_AVIF,
			),
			self::WEBP => array(
				'id'              => self::WEBP,
				'label'           => 'WebP',
				'extension'       => 'webp',
				'mime'            => 'image/webp',
				'imagick_format'  => 'WEBP',
				'gd_function'     => 'imagewebp',
				// Deliberately unchanged from before AVIF existed -- every
				// site that's already set a WebP quality keeps meaning
				// exactly that, with zero migration needed.
				'quality_option'  => WWG_Generator::OPTION_QUALITY,
				'default_quality' => WWG_Generator::DEFAULT_QUALITY,
			),
		);
	}

	/**
	 * @return array<string,array> All known formats, keyed by id, in
	 *                             PRIORITY order.
	 */
	public static function all() {
		$definitions = self::definitions();
		$ordered     = array();
		foreach ( self::PRIORITY as $id ) {
			$ordered[ $id ] = $definitions[ $id ];
		}
		return $ordered;
	}

	/**
	 * @param string $format One of self::WEBP/self::AVIF.
	 * @return array|null The format's metadata, or null if unrecognized.
	 */
	public static function get( $format ) {
		$definitions = self::definitions();
		return isset( $definitions[ $format ] ) ? $definitions[ $format ] : null;
	}

	/**
	 * @param string $format One of self::WEBP/self::AVIF.
	 * @return string Human-readable label, or the raw id if unrecognized.
	 */
	public static function label( $format ) {
		$def = self::get( $format );
		return $def ? $def['label'] : $format;
	}

	/**
	 * @param string $format One of self::WEBP/self::AVIF.
	 * @return bool Whether either conversion backend on this server can
	 *              actually encode this format -- mirrors the WebP-only
	 *              check this plugin has always had, parameterized.
	 */
	public static function has_support( $format ) {
		$def = self::get( $format );
		if ( ! $def ) {
			return false;
		}

		return ( class_exists( 'Imagick' ) && count( Imagick::queryFormats( $def['imagick_format'] ) ) > 0 )
			|| function_exists( $def['gd_function'] );
	}

	/**
	 * Which backend will actually be used for a format, for display on the
	 * Tools > WebP Generator readiness panel.
	 *
	 * @param string $format One of self::WEBP/self::AVIF.
	 * @return string One of 'Imagick', 'GD', or '' if this server can't
	 *                encode this format at all.
	 */
	public static function active_backend( $format ) {
		$def = self::get( $format );
		if ( ! $def ) {
			return '';
		}

		if ( class_exists( 'Imagick' ) && count( Imagick::queryFormats( $def['imagick_format'] ) ) > 0 ) {
			return 'Imagick';
		}

		if ( function_exists( $def['gd_function'] ) ) {
			return 'GD';
		}

		return '';
	}

	/**
	 * The formats this plugin actually produces right now: whatever this
	 * server's Imagick/GD can really encode, optionally narrowed further
	 * by a developer.
	 *
	 * @return string[] Format ids, PRIORITY order.
	 */
	public static function enabled() {
		$supported = array_values( array_filter( self::PRIORITY, array( __CLASS__, 'has_support' ) ) );

		/**
		 * Which derived formats WebP Generator actually produces, after
		 * server-capability detection. Can only narrow this list -- a
		 * format the server genuinely can't encode is never enabled no
		 * matter what this filter returns, so a misbehaving callback can't
		 * cause broken files to be generated at scale. Return e.g.
		 * array( 'webp' ) to force AVIF off even on a server that
		 * supports it.
		 *
		 * @param string[] $supported Server-detected-supported formats, PRIORITY order.
		 */
		$filtered = apply_filters( 'wwg_enabled_formats', $supported );
		if ( ! is_array( $filtered ) ) {
			$filtered = $supported;
		}

		return array_values( array_intersect( self::PRIORITY, array_intersect( $filtered, $supported ) ) );
	}

	/**
	 * The one path-derivation chokepoint for every derived format --
	 * replaces WWG_Generator's old (WebP-only) webp_path_for().
	 *
	 * @param string $format      One of self::WEBP/self::AVIF.
	 * @param string $source_path Path with a .jpg/.jpeg/.png extension.
	 * @return string|false The equivalent derived path, or false if the
	 *                       format or source extension is unrecognized.
	 */
	public static function path_for( $format, $source_path ) {
		$def = self::get( $format );
		if ( ! $def || ! preg_match( '/\.(jpe?g|png)$/i', $source_path ) ) {
			return false;
		}

		return preg_replace( '/\.(jpe?g|png)$/i', '.' . $def['extension'], $source_path );
	}
}
