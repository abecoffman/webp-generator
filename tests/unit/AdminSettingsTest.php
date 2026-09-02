<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Exercises WWG_Admin::handle_save_settings() -- the AJAX endpoint behind
 * the Settings card's autosave (see wireSettingsAutosave() in admin.js):
 * quality sliders and per-format enable checkboxes, saved the instant
 * either changes, no Save button.
 *
 * @covers \WWG_Admin::handle_save_settings
 */
class AdminSettingsTest extends TestCase {

	/**
	 * Fake backing store for get_option()/update_option().
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Captured argument of the most recent wp_send_json_success()/
	 * wp_send_json_error() call -- standing in for the JSON response the
	 * real AJAX endpoint would have sent.
	 *
	 * @var mixed
	 */
	private $last_json;

	protected function set_up() {
		parent::set_up();

		$this->options   = array();
		$this->last_json = null;
		$_POST            = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->last_json = array(
					'success' => true,
					'data'    => $data,
				);
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$this->last_json = array(
					'success' => false,
					'data'    => $data,
				);
			}
		);
		// This test machine's real GD supports both webp and avif (see
		// FormatTest.php) -- pass the filter through untouched so
		// WWG_Format::enabled()/has_support() reflect real capability,
		// matching how the save handler itself re-derives support rather
		// than trusting anything client-sent.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return isset( $this->options[ $key ] ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * @return \WWG_Admin
	 */
	private function admin() {
		return new \WWG_Admin( new \WWG_Generator() );
	}

	public function test_checking_a_format_on_persists_true() {
		$this->options['wwg_enabled_avif'] = false; // Previously off.
		$_POST                             = array(
			'wwg_enabled_webp' => '1',
			'wwg_enabled_avif' => '1',
		);

		$this->admin()->handle_save_settings();

		$this->assertTrue( $this->options['wwg_enabled_webp'] );
		$this->assertTrue( $this->options['wwg_enabled_avif'] );
		$this->assertTrue( $this->last_json['success'] );
	}

	public function test_unchecking_a_format_persists_false() {
		// Absent from $_POST entirely -- a checkbox only submits when
		// checked, standard HTML semantics (see currentSettingsFields()
		// in admin.js, which always sends the full current state this
		// same way, whether or not this particular save is what changed
		// it).
		$_POST = array(
			'wwg_enabled_webp' => '1',
		);

		$this->admin()->handle_save_settings();

		$this->assertTrue( $this->options['wwg_enabled_webp'] );
		$this->assertFalse( $this->options['wwg_enabled_avif'] );
	}

	// No test here for "an unsupported format's enabled-option is never
	// touched even though the request omits it" (the actual reason for
	// the has_support() guard in handle_save_settings()) -- has_support()
	// asks real Imagick/GD, not the wwg_enabled_formats filter (which
	// only narrows enabled(), see WWG_Format::enabled()'s own docblock),
	// and this test machine's real GD genuinely supports both formats
	// (same constraint FormatTest.php's own top docblock already
	// documents), so there's no honest way to make has_support( 'avif' )
	// return false here without faking PHP capability detection itself.
	// The guard logic (`if ( ! WWG_Format::has_support( $id ) ) {
	// continue; }`) is a one-line, self-evidently-correct read; see
	// AdminBatchingTest.php for this same real-vs-mocked-capability
	// boundary elsewhere in this suite.

	public function test_toggling_a_format_marks_the_scan_state_stale() {
		$this->options['wwg_scan_state'] = array(
			'finished_at'    => 1000,
			'invalidated_at' => null,
		);
		$_POST                           = array(
			'wwg_enabled_webp' => '1',
			// wwg_enabled_avif omitted -- turns AVIF off, a real change
			// from its true default (on).
		);

		$this->admin()->handle_save_settings();

		$this->assertNotNull( $this->options['wwg_scan_state']['invalidated_at'] );
		// The whole point of returning this in the response at all: with
		// no page reload left to pick it up (see handle_save_settings()'s
		// own docblock), the client needs the fresh scanState handed
		// straight back so Region 1 can reflect it immediately. Asserted
		// via get_scan_state()'s own contract (defaults merged in, see
		// its own docblock) rather than exact array equality against the
		// raw persisted option -- this test isn't the place to also pin
		// that merge's exact shape.
		$this->assertSame( 1000, $this->last_json['data']['scanState']['finished_at'] );
		$this->assertNotNull( $this->last_json['data']['scanState']['invalidated_at'] );
	}

	public function test_leaving_every_format_as_is_does_not_touch_the_scan_state() {
		$this->options['wwg_scan_state'] = array(
			'finished_at'    => 1000,
			'invalidated_at' => null,
		);
		$_POST                           = array(
			'wwg_enabled_webp' => '1',
			'wwg_enabled_avif' => '1', // Both stay on -- their true default -- nothing actually changes.
		);

		$this->admin()->handle_save_settings();

		$this->assertNull( $this->options['wwg_scan_state']['invalidated_at'] );
	}

	public function test_quality_values_are_still_clamped_and_saved_same_as_before() {
		$_POST = array(
			'wwg_quality'      => '150', // Out of range -- must clamp to 100.
			'wwg_quality_avif' => '0',   // Out of range -- must clamp to 1.
		);

		$this->admin()->handle_save_settings();

		$this->assertSame( 100, $this->options['wwg_quality'] );
		$this->assertSame( 1, $this->options['wwg_quality_avif'] );
	}

	// No "refuses without the capability" test here -- every existing
	// AJAX handler in this class shares the identical, unreturned
	// `if ( ! current_user_can(...) ) { wp_send_json_error(...); }`
	// pattern (no explicit `return;` after it), correctly relying on
	// wp_send_json_error()'s real wp_die() to actually stop execution in
	// production. This tier's mock of wp_send_json_error() only captures
	// its argument and returns normally rather than exiting (see
	// ScanStateTest.php's own identical mock), so a test asserting
	// "nothing was written" here would only be testing an artifact of
	// that mock, not real behavior -- and, consistent with that, no
	// other handler in this suite has an equivalent test either.
}
