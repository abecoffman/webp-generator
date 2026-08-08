<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * A scripted WWG_Admin double: overrides run_job_batch() to return a
 * canned sequence of batch results instead of touching the filesystem,
 * so WWG_Job's own orchestration (lock, reschedule, cursor/stats
 * bookkeeping, cancel) can be tested in isolation from real image
 * conversion -- mirrors why AdminBatchingTest itself stays scan-only
 * for the same reason (real conversion needs a real Imagick/GD backend
 * and real image bytes).
 */
class WWG_Admin_Fake_Batch extends \WWG_Admin {

	/**
	 * Popped one at a time, per run_job_batch() call.
	 *
	 * @var array[]
	 */
	public $batches = array();

	/**
	 * @var int
	 */
	public $calls = 0;

	/**
	 * @param int    $dir_index
	 * @param int    $file_offset
	 * @param string $mode
	 * @return array
	 */
	public function run_job_batch( $dir_index, $file_offset, $mode ) {
		++$this->calls;
		return array_shift( $this->batches );
	}
}

/**
 * Exercises WWG_Job's orchestration -- the lock, cursor/stats
 * bookkeeping, rescheduling, cancel, and seen-tracking logic -- against
 * a fake in-memory transient store and the scripted WWG_Admin double
 * above, since nothing in the existing unit suite exercises transients
 * directly and real batch processing is out of scope here.
 *
 * @covers \WWG_Job
 */
class JobTest extends TestCase {

	/**
	 * Fake backing store for get_transient()/set_transient()/
	 * delete_transient(), keyed exactly like the real transients API.
	 *
	 * @var array
	 */
	private $transients;

	/**
	 * Captured argument of the most recent wp_send_json_success() call --
	 * standing in for the JSON response an AJAX handler would have sent.
	 *
	 * @var mixed
	 */
	private $last_json;

	/**
	 * @var int
	 */
	private $schedule_single_calls;

	/**
	 * @var int
	 */
	private $clear_scheduled_calls;

	protected function set_up() {
		parent::set_up();

		$this->transients            = array();
		$this->last_json              = null;
		$this->schedule_single_calls = 0;
		$this->clear_scheduled_calls = 0;

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return isset( $this->transients[ $key ] ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->last_json = $data;
			}
		);

		// Counters rather than Functions\expect()->once() assertions --
		// this file already relies on when()/alias() everywhere else for
		// the fake transient store, and mixing when() with expect() on
		// the same function name across set_up() and individual tests is
		// a real Mockery footgun; a plain counter is simpler and no less
		// precise for what these tests need to assert.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () {
				++$this->schedule_single_calls;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function () {
				++$this->clear_scheduled_calls;
				return true;
			}
		);
		Functions\when( 'spawn_cron' )->justReturn( true );
	}

	/**
	 * @param array $overrides Merged over a minimal 'running' state.
	 * @return array
	 */
	private function running_state( array $overrides = array() ) {
		return array_merge(
			array(
				'status'        => 'running',
				'cursor'        => array(
					'dir_index'   => 0,
					'file_offset' => 0,
				),
				'stats'         => array(
					'scanned'        => 0,
					'missing'        => 0,
					'converted'      => 0,
					'failed'         => 0,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'failures'       => array(),
				),
				'total_missing' => 5,
				'current_dir'   => '',
				'cache_cleared' => false,
				'started_at'    => 1000,
				'finished_at'   => null,
				'seen'          => false,
			),
			$overrides
		);
	}

	/**
	 * @param array $stats_overrides
	 * @param bool  $done
	 * @param int   $dir_index
	 * @param int   $file_offset
	 * @return array Shape returned by WWG_Admin::run_job_batch().
	 */
	private function canned_batch( array $stats_overrides, $done, $dir_index = 1, $file_offset = 0 ) {
		return array(
			'stats'       => array_merge(
				array(
					'scanned'        => 0,
					'missing'        => 0,
					'converted'      => 0,
					'failed'         => 0,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'failures'       => array(),
				),
				$stats_overrides
			),
			'dir'         => '2024/01',
			'dir_index'   => $dir_index,
			'file_offset' => $file_offset,
			'done'        => $done,
		);
	}

	public function test_lock_prevents_a_concurrent_tick_from_running_a_batch() {
		$this->transients['wwg_job_lock']  = time();
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->run_tick();

		$this->assertSame( 0, $admin->calls );
	}

	public function test_run_tick_advances_cursor_and_stats_and_stops_rescheduling_once_done() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin          = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$admin->batches = array(
			$this->canned_batch( array( 'converted' => 2 ), false, 1, 0 ),
			$this->canned_batch( array( 'converted' => 3 ), true, 2, 0 ),
		);
		$job = new \WWG_Job( $admin );

		$job->run_tick();
		$state = $this->transients['wwg_job_state'];
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 2, $state['stats']['converted'] );
		$this->assertSame( 1, $state['cursor']['dir_index'] );

		$job->run_tick();
		$state = $this->transients['wwg_job_state'];
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 5, $state['stats']['converted'] );
		$this->assertSame( 2, $admin->calls );

		// The first (not-done) tick reschedules; the second (done) tick
		// must not -- exactly one call across both.
		$this->assertSame( 1, $this->schedule_single_calls );
	}

	public function test_run_tick_does_no_work_if_state_is_not_running() {
		$this->transients['wwg_job_state'] = $this->running_state( array( 'status' => 'paused' ) );

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->run_tick();

		$this->assertSame( 0, $admin->calls );
		$this->assertArrayNotHasKey( 'wwg_job_lock', $this->transients );
	}

	public function test_handle_start_job_is_a_noop_when_already_running() {
		$state                              = $this->running_state();
		$this->transients['wwg_job_state'] = $state;

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_start_job();

		$this->assertSame( $state, $this->last_json );
		$this->assertSame( 0, $admin->calls );
		$this->assertSame( 0, $this->schedule_single_calls );
	}

	public function test_handle_start_job_resumes_a_paused_job_with_progress_intact() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'status' => 'paused',
				'stats'  => array(
					'scanned'        => 10,
					'missing'        => 4,
					'converted'      => 3,
					'failed'         => 1,
					'original_bytes' => 100,
					'webp_bytes'     => 50,
					'failures'       => array(),
				),
				'cursor' => array(
					'dir_index'   => 3,
					'file_offset' => 2,
				),
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_start_job();

		$this->assertSame( 'running', $this->last_json['status'] );
		$this->assertSame( 3, $this->last_json['stats']['converted'] );
		$this->assertSame( 3, $this->last_json['cursor']['dir_index'] );
	}

	public function test_handle_cancel_job_clears_the_schedule_and_preserves_progress() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 10,
					'missing'        => 4,
					'converted'      => 3,
					'failed'         => 1,
					'original_bytes' => 100,
					'webp_bytes'     => 50,
					'failures'       => array(),
				),
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_cancel_job();

		$this->assertSame( 'paused', $this->last_json['status'] );
		$this->assertSame( 3, $this->last_json['stats']['converted'] );
		$this->assertSame( 1, $this->clear_scheduled_calls );
	}

	public function test_get_hydrated_state_marks_a_finished_job_seen_exactly_once() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'status' => 'done',
				'seen'   => false,
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$hydrated = $job->get_hydrated_state();
		$this->assertTrue( $hydrated['seen'] );
		$this->assertTrue( $this->transients['wwg_job_state']['seen'] );

		// A second read is a no-op -- already seen, nothing left to flip.
		$again = $job->get_hydrated_state();
		$this->assertTrue( $again['seen'] );
	}

	public function test_get_hydrated_state_leaves_a_running_job_unseen() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$hydrated = $job->get_hydrated_state();
		$this->assertFalse( $hydrated['seen'] );
	}
}
