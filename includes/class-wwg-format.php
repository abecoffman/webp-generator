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
	 * Deliberately higher than WebP's own default (80, see
	 * WWG_Generator::DEFAULT_QUALITY) even though both options share the
	 * same 1-100 range -- they're not on a perceptually equivalent scale
	 * at the same number (see this file's own top-level docblock).
	 *
	 * @var int
	 */
	const DEFAULT_QUALITY_AVIF = 85;

	/**
	 * Per-format "does the site owner actually want this generated at
	 * all" option -- independent of both server support (has_support())
	 * and the developer-only wwg_enabled_formats filter (see enabled()
	 * below). See user_wants()'s own docblock for the default this falls
	 * back to when never explicitly saved.
	 *
	 * @var string
	 */
	const OPTION_ENABLED_WEBP = 'wwg_enabled_webp';

	/**
	 * @var string
	 */
	const OPTION_ENABLED_AVIF = 'wwg_enabled_avif';

	/**
	 * Per-format metadata. A method rather than a class constant so it can
	 * reference WWG_Generator::OPTION_QUALITY/DEFAULT_QUALITY for the webp
	 * entry without a class-constant-expression load-order dependency
	 * between this file and class-wwg-generator.php.
	 *
	 * @return array<string,array{id:string,label:string,extension:string,mime:string,imagick_format:string,gd_function:string,quality_option:string,default_quality:int,enabled_option:string}>
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
				'enabled_option'  => self::OPTION_ENABLED_AVIF,
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
				'enabled_option'  => self::OPTION_ENABLED_WEBP,
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
	 * Whether the site owner has this format checked on in Settings --
	 * independent of has_support() (a fact about the server, not a
	 * choice) and the wwg_enabled_formats filter (a developer-only
	 * override, see enabled() below).
	 *
	 * Default, when the option has genuinely never been saved: AVIF wins
	 * outright on a *fresh* install where the server can produce it --
	 * generating both by default doubles storage/CPU for a format most
	 * visitors' browsers won't even use once AVIF exists (it's strictly
	 * smaller/better than WebP at the same quality number). WebP defaults
	 * on only when AVIF genuinely isn't an option, so a fresh install
	 * still gets real compression, not silently nothing.
	 *
	 * Deliberately narrowed to fresh installs only (see fresh_install()
	 * below) -- an already-running site has been generating both formats
	 * this whole time under the old, unconditional true default, and
	 * upgrading into this option's first release must never silently
	 * change that just because someone saves an unrelated Settings field.
	 * An established site keeps the plain true default until it
	 * explicitly says otherwise.
	 *
	 * @param string $format One of self::WEBP/self::AVIF.
	 * @return bool
	 */
	public static function user_wants( $format ) {
		$def = self::get( $format );
		if ( ! $def ) {
			return false;
		}

		$default = true;
		if ( self::WEBP === $format && self::fresh_install() ) {
			$default = ! self::has_support( self::AVIF );
		}

		return (bool) get_option( $def['enabled_option'], $default );
	}

	/**
	 * Best-effort "has this site actually used this plugin yet" signal --
	 * used only to decide how far user_wants()'s smart default above
	 * reaches; nothing else depends on this being perfectly precise.
	 * Scan's own completion record is the closest thing this plugin has
	 * to that: a real, non-expiring option (unlike WWG_Job's own run
	 * state, which is a transient and can't be trusted not to have
	 * simply expired on an established site that hasn't run Generate
	 * recently). Imperfect for a site that's only ever relied on the
	 * on-upload hook and never once visited Tools > WebP Generator --
	 * accepted as a narrow edge case, weighed against getting every
	 * genuinely fresh install wrong instead.
	 *
	 * @return bool
	 */
	private static function fresh_install() {
		$scan_state = get_option( WWG_Admin::OPTION_SCAN_STATE, array() );
		return empty( $scan_state['finished_at'] );
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
	 * by a developer, AND narrowed again by whatever the site owner has
	 * actually checked on in Settings (see user_wants() above) -- three
	 * independent gates, every one of which can only narrow, never widen,
	 * so none of them can silently override either of the others into
	 * producing something un-asked-for.
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
		 * supports it. Deliberately still receives the raw,
		 * server-supported list -- not also pre-narrowed by user_wants()
		 * below -- so this filter's own contract is unaffected by whether
		 * a Settings checkbox exists at all.
		 *
		 * @param string[] $supported Server-detected-supported formats, PRIORITY order.
		 */
		$filtered = apply_filters( 'wwg_enabled_formats', $supported );
		if ( ! is_array( $filtered ) ) {
			$filtered = $supported;
		}
		$filtered = array_values( array_intersect( self::PRIORITY, array_intersect( $filtered, $supported ) ) );

		// The site owner's own choice, applied last and independently --
		// see user_wants()'s own docblock for why this can never widen
		// past what the two gates above already allowed.
		return array_values( array_filter( $filtered, array( __CLASS__, 'user_wants' ) ) );
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
