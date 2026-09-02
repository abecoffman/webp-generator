<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Exercises the persisted "Library Status" snapshot (see
 * WWG_Admin::OPTION_SCAN_STATE): save_scan_state(), called directly by
 * WWG_Job the moment a Generate run's own counting phase finishes walking
 * the tree (see WWG_Job::process_one_batch()), and mark_scan_state_stale(),
 * called by WWG_Job the moment a Generate run actually converts something.
 * Unlike the old client-driven Scan flow this replaced, save_scan_state()
 * takes a plain, already-trusted $stats array (process_batch()'s own
 * output) rather than parsing/sanitizing untrusted $_POST -- so there's no
 * nonce/capability/JSON-decoding layer to exercise here anymore.
 *
 * @covers \WWG_Admin::save_scan_state
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

	protected function set_up() {
		parent::set_up();

		$this->options = array();

		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
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

	/**
	 * @return \WWG_Admin
	 */
	private function admin() {
		return new \WWG_Admin( new \WWG_Generator() );
	}

	public function test_save_scan_state_persists_the_given_tally() {
		$state = $this->admin()->save_scan_state(
			array(
				'missing'        => 3,
				'missing_files'  => 2,
				'original_bytes' => 12345,
				'failures'       => array(),
			)
		);

		$this->assertSame( 3, $state['missing'] );
		$this->assertSame( 2, $state['missing_files'] );
		$this->assertSame( 12345, $state['original_bytes'] );
		$this->assertSame( array(), $state['failures'] );
		$this->assertNull( $state['invalidated_at'] );
		$this->assertGreaterThan( 0, $state['finished_at'] );

		// And actually persisted, not just returned.
		$this->assertSame( $state, $this->options[ self::OPTION_SCAN_STATE ] );
	}

	public function test_save_scan_state_stamps_the_server_clock_not_a_caller_supplied_one() {
		$before = time();
		$state  = $this->admin()->save_scan_state(
			array(
				'missing'        => 1,
				'original_bytes' => 0,
				// Not a real field of the shape WWG_Job actually passes --
				// confirms the handler stamps its own clock rather than
				// trusting anything under this key even if present.
				'finished_at'    => 999999999999,
			)
		);
		$after = time();

		$this->assertGreaterThanOrEqual( $before, $state['finished_at'] );
		$this->assertLessThanOrEqual( $after, $state['finished_at'] );
	}

	public function test_save_scan_state_defaults_missing_fields_to_empty() {
		$state = $this->admin()->save_scan_state( array() );

		$this->assertSame( 0, $state['missing'] );
		$this->assertSame( 0, $state['missing_files'] );
		$this->assertSame( 0, $state['original_bytes'] );
		$this->assertSame( 0, $state['total_images'] );
		$this->assertSame( 0, $state['original_bytes_total'] );
		$this->assertSame( 0, $state['webp_bytes'] );
		$this->assertSame( 0, $state['avif_bytes'] );
		$this->assertSame( 0, $state['webp_original_bytes'] );
		$this->assertSame( 0, $state['avif_original_bytes'] );
		$this->assertSame( 0, $state['webp_present'] );
		$this->assertSame( 0, $state['avif_present'] );
		$this->assertSame( array(), $state['failures'] );
	}

	/**
	 * Library Status's own "complete current state" figures -- the whole
	 * library, not just the missing subset the other fields already
	 * cover. Persisted from $stats['scanned'] (as total_images) and the
	 * per-format byte/present fields, which WWG_Job's counting phase
	 * already computes for every file it visits (see process_batch()'s
	 * own $stats docblock) -- what Library Status's Original/WebP/AVIF
	 * table is built from.
	 */
	public function test_save_scan_state_persists_the_whole_library_totals() {
		$state = $this->admin()->save_scan_state(
			array(
				'scanned'              => 47382,
				'original_bytes_total' => 300,
				'webp_bytes'           => 100,
				'avif_bytes'           => 80,
				'webp_original_bytes'  => 200,
				'avif_original_bytes'  => 200,
				'webp_present'         => 47000,
				'avif_present'         => 46000,
			)
		);

		$this->assertSame( 47382, $state['total_images'] );
		$this->assertSame( 300, $state['original_bytes_total'] );
		$this->assertSame( 100, $state['webp_bytes'] );
		$this->assertSame( 80, $state['avif_bytes'] );
		$this->assertSame( 200, $state['webp_original_bytes'] );
		$this->assertSame( 200, $state['avif_original_bytes'] );
		$this->assertSame( 47000, $state['webp_present'] );
		$this->assertSame( 46000, $state['avif_present'] );
	}

	public function test_a_fresh_scan_state_clears_a_prior_invalidation() {
		$this->options[ self::OPTION_SCAN_STATE ] = array(
			'missing'        => 5,
			'original_bytes' => 500,
			'failures'       => array(),
			'finished_at'    => 1000,
			'invalidated_at' => 2000, // previously marked stale.
		);

		$state = $this->admin()->save_scan_state(
			array(
				'missing'        => 0,
				'original_bytes' => 0,
			)
		);

		$this->assertNull( $state['invalidated_at'] );
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
