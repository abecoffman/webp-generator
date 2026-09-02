<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Runs against whichever real backend (Imagick or GD) this machine
 * actually has for WebP/AVIF -- matches this codebase's existing
 * philosophy of exercising real capability detection rather than mocking
 * it (see AdminBatchingTest.php's own "real GD/Imagick" tests). Every
 * assertion below only depends on "this machine genuinely supports both
 * formats", never on which specific backend provides that support --
 * WWG_Format::active_backend() prefers Imagick whenever it can actually
 * encode a format, so which one wins is itself a real, portable machine
 * difference (found the hard way: this file used to hardcode "GD",
 * written against a machine with no Imagick at all, which broke the
 * moment CI ran it on a runner whose Imagick handles WebP/AVIF too).
 *
 * @covers \WWG_Format
 */
class FormatTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		// enabled() calls apply_filters( 'wwg_enabled_formats', ... );
		// default to a pass-through so tests not specifically about the
		// filter don't need to stub it individually.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		// enabled() also calls user_wants() -> get_option( ..., $default );
		// default to an ESTABLISHED site (a real, finished scan on record)
		// so tests not specifically about the Settings checkbox, or about
		// user_wants()'s fresh-install-only smart default, get the plain
		// "true unless explicitly unchecked" behavior every test here
		// originally assumed -- see fresh_install()'s own docblock in
		// class-wwg-format.php for why that default only ever applies to
		// a genuinely fresh install. Tests specifically about that
		// default override this locally to simulate a fresh one instead
		// (no wwg_scan_state on record at all).
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				return 'wwg_scan_state' === $option ? array( 'finished_at' => 1000 ) : $default;
			}
		);
	}

	public function test_all_returns_both_formats_in_priority_order() {
		$this->assertSame( array( 'avif', 'webp' ), array_keys( \WWG_Format::all() ) );
	}

	public function test_get_returns_the_right_definition() {
		$avif = \WWG_Format::get( 'avif' );
		$this->assertSame( 'AVIF', $avif['label'] );
		$this->assertSame( 'avif', $avif['extension'] );
		$this->assertSame( 'image/avif', $avif['mime'] );
		$this->assertSame( 'wwg_quality_avif', $avif['quality_option'] );
		$this->assertSame( 85, $avif['default_quality'] );
	}

	public function test_webps_definition_keeps_the_original_quality_option_unchanged() {
		// The actual backward-compatibility guarantee: every site that
		// already set a WebP quality keeps meaning exactly that, with no
		// migration -- pinned here, not just trusted.
		$webp = \WWG_Format::get( 'webp' );
		$this->assertSame( 'wwg_quality', $webp['quality_option'] );
		$this->assertSame( 80, $webp['default_quality'] );
	}

	public function test_get_returns_null_for_an_unrecognized_format() {
		$this->assertNull( \WWG_Format::get( 'heic' ) );
	}

	public function test_label_falls_back_to_the_raw_id_for_an_unrecognized_format() {
		$this->assertSame( 'WebP', \WWG_Format::label( 'webp' ) );
		$this->assertSame( 'heic', \WWG_Format::label( 'heic' ) );
	}

	public function test_has_support_reflects_this_machines_real_capability() {
		$this->assertTrue( \WWG_Format::has_support( 'webp' ) );
		$this->assertTrue( \WWG_Format::has_support( 'avif' ) );
		$this->assertFalse( \WWG_Format::has_support( 'heic' ) );
	}

	public function test_active_backend_reports_a_real_backend_for_every_supported_format() {
		// Which ONE backend wins (Imagick vs. GD) is itself a genuine,
		// portable machine difference -- the only thing safe to assert
		// across machines is that a real, named backend comes back for
		// anything genuinely supported here, and none at all for a
		// format neither backend has ever heard of.
		$this->assertContains( \WWG_Format::active_backend( 'webp' ), array( 'Imagick', 'GD' ) );
		$this->assertContains( \WWG_Format::active_backend( 'avif' ), array( 'Imagick', 'GD' ) );
		$this->assertSame( '', \WWG_Format::active_backend( 'heic' ) );
	}

	public function test_path_for_derives_the_right_extension() {
		$this->assertSame( '/a/b/photo.avif', \WWG_Format::path_for( 'avif', '/a/b/photo.jpg' ) );
		$this->assertSame( '/a/b/photo.webp', \WWG_Format::path_for( 'webp', '/a/b/photo.PNG' ) );
	}

	public function test_path_for_returns_false_for_an_unsupported_source_extension() {
		$this->assertFalse( \WWG_Format::path_for( 'webp', '/a/b/photo.gif' ) );
	}

	public function test_path_for_returns_false_for_an_unrecognized_format() {
		$this->assertFalse( \WWG_Format::path_for( 'heic', '/a/b/photo.jpg' ) );
	}

	public function test_enabled_returns_every_really_supported_format_when_the_filter_is_untouched() {
		$this->assertSame( array( 'avif', 'webp' ), \WWG_Format::enabled() );
	}

	public function test_enabled_lets_a_filter_narrow_to_just_webp() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'webp' ) : $value;
			}
		);

		$this->assertSame( array( 'webp' ), \WWG_Format::enabled() );
	}

	public function test_enabled_falls_back_to_the_supported_list_when_the_filter_returns_a_non_array() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? false : $value;
			}
		);

		$this->assertSame( array( 'avif', 'webp' ), \WWG_Format::enabled() );
	}

	public function test_user_wants_defaults_true_on_an_established_site_when_the_option_has_never_been_set() {
		// The actual upgrade-safety guarantee: a site that predates this
		// option (i.e. anything with a real scan on record already, per
		// set_up()'s default mock) must keep generating exactly what it
		// already was.
		$this->assertTrue( \WWG_Format::user_wants( 'webp' ) );
		$this->assertTrue( \WWG_Format::user_wants( 'avif' ) );
	}

	public function test_user_wants_reflects_each_formats_own_option_independently() {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'wwg_scan_state' === $option ) {
					return array( 'finished_at' => 1000 ); // Established site -- see this test file's own set_up().
				}
				return 'wwg_enabled_avif' === $option ? false : $default;
			}
		);

		$this->assertTrue( \WWG_Format::user_wants( 'webp' ) );
		$this->assertFalse( \WWG_Format::user_wants( 'avif' ) );
	}

	public function test_user_wants_returns_false_for_an_unrecognized_format() {
		$this->assertFalse( \WWG_Format::user_wants( 'heic' ) );
	}

	public function test_user_wants_webp_defaults_off_on_a_fresh_install_when_avif_is_supported() {
		// The new smart default this session added: a fresh install (no
		// wwg_scan_state on record at all -- unlike this file's own
		// set_up() default) with AVIF support gets AVIF only, not both,
		// to avoid doubling storage/CPU for a format most browsers won't
		// use once AVIF exists. This machine's real backend genuinely
		// supports AVIF (see
		// test_has_support_reflects_this_machines_real_capability()), so
		// this is exercising the real capability check, not a mock of
		// it -- there's no honest way to also test the "AVIF unsupported"
		// branch here for the same reason AdminSettingsTest.php's own
		// equivalent test doesn't exist (has_support() asks real
		// Imagick/GD, not something this tier can fake).
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				return 'wwg_scan_state' === $option ? array() : $default;
			}
		);

		$this->assertFalse( \WWG_Format::user_wants( 'webp' ) );
		$this->assertTrue( \WWG_Format::user_wants( 'avif' ) );
	}

	public function test_enabled_excludes_a_format_the_site_owner_unchecked() {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'wwg_scan_state' === $option ) {
					return array( 'finished_at' => 1000 ); // Established site -- see this test file's own set_up().
				}
				return 'wwg_enabled_avif' === $option ? false : $default;
			}
		);

		$this->assertSame( array( 'webp' ), \WWG_Format::enabled() );
	}

	public function test_enabled_returns_only_avif_by_default_on_a_fresh_install() {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				return 'wwg_scan_state' === $option ? array() : $default;
			}
		);

		$this->assertSame( array( 'avif' ), \WWG_Format::enabled() );
	}

	public function test_enabled_can_be_narrowed_to_nothing_at_all() {
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'wwg_scan_state' === $option ) {
					return array( 'finished_at' => 1000 ); // Established site -- see this test file's own set_up().
				}
				return false;
			}
		);

		$this->assertSame( array(), \WWG_Format::enabled() );
	}

	public function test_enabled_cannot_be_tricked_into_including_a_format_outside_priority() {
		// The real narrow-only guarantee this exists to protect: a
		// misbehaving/malicious wwg_enabled_formats callback must never be
		// able to add capability the server doesn't actually have. This
		// machine happens to really support both webp and avif, so the
		// most direct way to prove "can only select FROM the real
		// supported set, never add TO it" is a format that was never a
		// real candidate at all (not in \WWG_Format::PRIORITY) -- if
		// enforcement here is broken, this id would leak straight through.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'avif', 'webp', 'heic' ) : $value;
			}
		);

		$this->assertSame( array( 'avif', 'webp' ), \WWG_Format::enabled() );
	}
}
