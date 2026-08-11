<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Exercises the persisted "Library Status" snapshot (see
 * WWG_Admin::OPTION_SCAN_STATE): handle_save_scan_result(), the AJAX
 * endpoint admin.js's finishScan() posts to once a client-driven Scan
 * pass completes, and mark_scan_state_stale(), called by WWG_Job the
 * moment a Generate run actually converts something.
 *
 * @covers \WWG_Admin::handle_save_scan_result
 * @covers \WWG_Admin::mark_scan_state_stale
 */
class ScanStateTest extends TestCase {

	const OPTION_SCAN_STATE = 'wwg_scan_state';

	/**
	 * Fake backing store for get_option()/update_option(), keyed exactly
	 * like the real options API.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Captured argument of the most recent wp_send_json_success()/
	 * wp_send_json_error() call -- standing in for the JSON response an
	 * AJAX handler would have sent.
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
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->last_json = $data;
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$this->last_json = $data;
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

	public function test_save_scan_result_persists_the_posted_tally() {
		$_POST = array(
			'missing'        => '3',
			'original_bytes' => '12345',
			'failures'       => encode_failures_for_post( array() ),
		);

		$this->admin()->handle_save_scan_result();

		$this->assertSame( 3, $this->last_json['missing'] );
		$this->assertSame( 12345, $this->last_json['original_bytes'] );
		$this->assertSame( array(), $this->last_json['failures'] );
		$this->assertNull( $this->last_json['invalidated_at'] );
		$this->assertGreaterThan( 0, $this->last_json['finished_at'] );

		// And actually persisted, not just returned in the response.
		$this->assertSame( $this->last_json, $this->options[ self::OPTION_SCAN_STATE ] );
	}

	public function test_save_scan_result_stamps_the_server_clock_not_a_client_supplied_one() {
		$_POST = array(
			'missing'        => '1',
			'original_bytes' => '0',
			// A client can't set finished_at at all (the handler never
			// reads such a field) -- confirmed here by asserting the
			// stamped value is genuinely "now", not some arbitrary
			// injected number.
			'finished_at'    => '999999999999',
			'failures'       => encode_failures_for_post( array() ),
		);

		$before = time();
		$this->admin()->handle_save_scan_result();
		$after = time();

		$this->assertGreaterThanOrEqual( $before, $this->last_json['finished_at'] );
		$this->assertLessThanOrEqual( $after, $this->last_json['finished_at'] );
	}

	public function test_save_scan_result_sanitizes_and_caps_failures() {
		$failures = array();
		for ( $i = 0; $i < 510; $i++ ) {
			$failures[] = array(
				'file'  => "2024/01/img{$i}.jpg",
				'error' => 'Some decode error.',
			);
		}
		// A malformed entry (missing 'error') should be dropped, not
		// fatal -- a client-reported list is never trusted blindly.
		$failures[] = array( 'file' => 'no-error-key.jpg' );

		$_POST = array(
			'missing'        => '510',
			'original_bytes' => '1000',
			'failures'       => encode_failures_for_post( $failures ),
		);

		$this->admin()->handle_save_scan_result();

		// The cap keeps the most recent 500 *raw* entries submitted
		// (array_slice(..., -500) runs before the malformed one is
		// filtered out), so this ends up with 499 valid ones, not a flat
		// 500 -- what matters is that it's bounded at all, and that the
		// most recent well-formed entry submitted is still among them.
		$this->assertCount( 499, $this->last_json['failures'] );
		$this->assertSame( '2024/01/img509.jpg', $this->last_json['failures'][498]['file'] );
	}

	public function test_save_scan_result_defaults_missing_fields_to_empty() {
		$_POST = array(); // no missing/original_bytes/failures at all.

		$this->admin()->handle_save_scan_result();

		$this->assertSame( 0, $this->last_json['missing'] );
		$this->assertSame( 0, $this->last_json['original_bytes'] );
		$this->assertSame( array(), $this->last_json['failures'] );
	}

	public function test_a_fresh_scan_result_clears_a_prior_invalidation() {
		$this->options[ self::OPTION_SCAN_STATE ] = array(
			'missing'        => 5,
			'original_bytes' => 500,
			'failures'       => array(),
			'finished_at'    => 1000,
			'invalidated_at' => 2000, // previously marked stale.
		);

		$_POST = array(
			'missing'        => '0',
			'original_bytes' => '0',
			'failures'       => encode_failures_for_post( array() ),
		);

		$this->admin()->handle_save_scan_result();

		$this->assertNull( $this->last_json['invalidated_at'] );
	}

	public function test_mark_scan_state_stale_sets_invalidated_at_but_keeps_the_old_numbers() {
		$this->options[ self::OPTION_SCAN_STATE ] = array(
			'missing'        => 7,
			'original_bytes' => 777,
			'failures'       => array( array( 'file' => 'a.jpg', 'error' => 'x' ) ),
			'finished_at'    => 1000,
			'invalidated_at' => null,
		);

		$this->admin()->mark_scan_state_stale();

		$stored = $this->options[ self::OPTION_SCAN_STATE ];
		$this->assertNotNull( $stored['invalidated_at'] );
		// The old numbers survive the invalidation -- they're what lets
		// the tool page explain *why* it reset, per OPTION_SCAN_STATE's
		// own docblock.
		$this->assertSame( 7, $stored['missing'] );
		$this->assertSame( 777, $stored['original_bytes'] );
		$this->assertSame( 1000, $stored['finished_at'] );
	}

	public function test_mark_scan_state_stale_is_a_noop_when_never_scanned() {
		// No wwg_scan_state option at all -- nothing to invalidate.
		$this->admin()->mark_scan_state_stale();

		$this->assertArrayNotHasKey( self::OPTION_SCAN_STATE, $this->options );
	}
}

/**
 * Builds the raw $_POST['failures'] string a real admin.js
 * JSON.stringify() call would send -- the handler under test only ever
 * calls json_decode() (a PHP builtin, not a WP wrapper) on it, so a plain
 * json_encode() here is exactly what's on the wire, no stub needed.
 *
 * @param mixed $data
 * @return string
 */
function encode_failures_for_post( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only helper, not plugin code; mirrors a client payload, not a WordPress data write.
}
