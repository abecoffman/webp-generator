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
 * A WWG_Admin double whose run_job_batch() call reenters into a given
 * callback WHILE the outer call is still executing -- and so, since
 * process_one_batch() holds LOCK_KEY for its entire duration, still
 * holding that lock. Exploits PHP's single-threadedness to prove the
 * lock is actually *shared* between the cron and drive paths (a
 * concurrent call really does find it locked), not just coincidentally
 * both present in the source.
 */
class WWG_Admin_Reentrant_Fake extends \WWG_Admin {

	/**
	 * @var int
	 */
	public $calls = 0;

	/**
	 * @var callable
	 */
	private $reentrant_call;

	/**
	 * Whatever the reentrant callback returned.
	 *
	 * @var mixed
	 */
	public $reentrant_result;

	/**
	 * @param callable $reentrant_call Invoked mid-call, before this
	 *                                 method returns its own canned batch.
	 */
	public function __construct( callable $reentrant_call ) {
		parent::__construct( new \WWG_Generator() );
		$this->reentrant_call = $reentrant_call;
	}

	public function run_job_batch( $dir_index, $file_offset, $mode ) {
		++$this->calls;
		$this->reentrant_result = ( $this->reentrant_call )();

		return array(
			'stats'       => array(
				'scanned'        => 1,
				'missing'        => 1,
				'converted'      => 1,
				'failed'         => 0,
				'original_bytes' => 0,
				'webp_bytes'     => 0,
				'failures'       => array(),
				'recoveries'     => array(),
			),
			'dir'         => '2024/01',
			'dir_index'   => 1,
			'file_offset' => 0,
			'done'        => false,
		);
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
					'recoveries'     => array(),
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
					'recoveries'     => array(),
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

	// ---- handle_drive_job() -- the client's fast-path equivalent of
	// run_tick(), sharing process_one_batch() with it entirely ----

	public function test_handle_drive_job_reports_locked_and_does_no_work_when_lock_is_held() {
		$this->transients['wwg_job_lock']  = time();
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_drive_job();

		$this->assertSame( 'locked', $this->last_json['outcome'] );
		$this->assertSame( 0, $admin->calls );
	}

	public function test_handle_drive_job_reports_stopped_when_not_running() {
		$this->transients['wwg_job_state'] = $this->running_state( array( 'status' => 'paused' ) );

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_drive_job();

		$this->assertSame( 'stopped', $this->last_json['outcome'] );
		$this->assertSame( 0, $admin->calls );
		$this->assertArrayNotHasKey( 'wwg_job_lock', $this->transients );
	}

	public function test_handle_drive_job_advances_then_completes_like_run_tick() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin          = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$admin->batches = array(
			$this->canned_batch( array( 'converted' => 2 ), false, 1, 0 ),
			$this->canned_batch( array( 'converted' => 3 ), true, 2, 0 ),
		);
		$job = new \WWG_Job( $admin );

		$job->handle_drive_job();
		$this->assertSame( 'advanced', $this->last_json['outcome'] );
		$this->assertSame( 2, $this->last_json['state']['stats']['converted'] );

		$job->handle_drive_job();
		$this->assertSame( 'done', $this->last_json['outcome'] );
		$this->assertSame( 5, $this->last_json['state']['stats']['converted'] );
		$this->assertSame( 2, $admin->calls );

		// The cron safety net stays primed even though the client did
		// the driving -- ensure_scheduled() runs from the 'advanced' call
		// same as it would from run_tick(); the 'done' call must not
		// schedule anything further. Exactly one call across both.
		$this->assertSame( 1, $this->schedule_single_calls );
	}

	/**
	 * The real mutex-sharing proof: while WWG_Admin_Reentrant_Fake's
	 * run_job_batch() is still executing inside the *outer* run_tick()
	 * call (which is still holding LOCK_KEY), it reenters with a
	 * handle_drive_job() call standing in for a concurrent AJAX request
	 * landing mid-batch. That inner call must find the lock held and do
	 * no work of its own.
	 */
	public function test_a_drive_request_reentering_during_a_cron_tick_finds_it_locked() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$job   = null;
		$admin = new WWG_Admin_Reentrant_Fake(
			function () use ( &$job ) {
				$job->handle_drive_job();
				return $this->last_json;
			}
		);
		$job = new \WWG_Job( $admin );

		$job->run_tick();

		$this->assertSame( 1, $admin->calls, 'The reentrant handle_drive_job() call must not have run a second batch.' );
		$this->assertSame( 'locked', $admin->reentrant_result['outcome'] );
	}

	/**
	 * Mirror image of the above: a cron tick reentering mid-drive-call
	 * must equally find it locked.
	 */
	public function test_a_cron_tick_reentering_during_a_drive_request_finds_it_locked() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$job   = null;
		$admin = new WWG_Admin_Reentrant_Fake(
			function () use ( &$job ) {
				$job->run_tick();
				return null;
			}
		);
		$job = new \WWG_Job( $admin );

		$job->handle_drive_job();

		$this->assertSame( 1, $admin->calls, 'The reentrant run_tick() call must not have run a second batch.' );
		$this->assertSame( 'advanced', $this->last_json['outcome'] );
	}
}
