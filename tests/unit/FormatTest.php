<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Runs against this machine's REAL GD (no Imagick here, confirmed via
 * class_exists( 'Imagick' ) === false) -- matches this codebase's existing
 * philosophy of exercising real capability detection rather than mocking
 * it (see AdminBatchingTest.php's own "real GD/Imagick" tests). This
 * machine's GD has both imagewebp() and imageavif(), so both formats are
 * genuinely supported here.
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
		$this->assertSame( 75, $avif['default_quality'] );
	}

	public function test_webps_definition_keeps_the_original_quality_option_unchanged() {
		// The actual backward-compatibility guarantee: every site that
		// already set a WebP quality keeps meaning exactly that, with no
		// migration -- pinned here, not just trusted.
		$webp = \WWG_Format::get( 'webp' );
		$this->assertSame( 'wwg_quality', $webp['quality_option'] );
		$this->assertSame( 75, $webp['default_quality'] );
	}

	public function test_get_returns_null_for_an_unrecognized_format() {
		$this->assertNull( \WWG_Format::get( 'heic' ) );
	}

	public function test_label_falls_back_to_the_raw_id_for_an_unrecognized_format() {
		$this->assertSame( 'WebP', \WWG_Format::label( 'webp' ) );
		$this->assertSame( 'heic', \WWG_Format::label( 'heic' ) );
	}

	public function test_has_support_reflects_this_machines_real_gd() {
		$this->assertTrue( \WWG_Format::has_support( 'webp' ) );
		$this->assertTrue( \WWG_Format::has_support( 'avif' ) );
		$this->assertFalse( \WWG_Format::has_support( 'heic' ) );
	}

	public function test_active_backend_reports_gd_on_this_machine() {
		$this->assertSame( 'GD', \WWG_Format::active_backend( 'webp' ) );
		$this->assertSame( 'GD', \WWG_Format::active_backend( 'avif' ) );
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
