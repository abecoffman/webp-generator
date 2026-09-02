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
				'scanned'              => 1,
				'missing'              => 1,
				'missing_files'        => 1,
				'converted'            => 1,
				'failed'               => 0,
				'original_bytes'       => 0,
				'original_bytes_total' => 0,
				'webp_bytes'           => 0,
				'avif_bytes'           => 0,
				'webp_original_bytes'  => 0,
				'avif_original_bytes'  => 0,
				'webp_present'         => 0,
				'avif_present'         => 0,
				'failures'             => array(),
				'recoveries'           => array(),
			),
			'dir'         => '2024/01',
			'dir_index'   => 1,
			'file_offset' => 0,
			'total_dirs'  => 5,
			'done'        => false,
		);
	}
}

/**
 * A WWG_Admin double whose run_job_batch() call invokes a given callback
 * BEFORE returning its own canned batch result -- used to simulate a
 * Cancel click's *effect* (status flipped to 'paused', schedule cleared)
 * landing on the server *while* a batch's own slow work (a real directory
 * walk or actual image conversion) is still in flight, exactly the race
 * fresh_status() exists to close.
 *
 * Deliberately does NOT call the real handle_cancel_job() reentrantly the
 * way WWG_Admin_Reentrant_Fake above calls handle_drive_job()/run_tick()
 * -- handle_cancel_job() now waits on LOCK_KEY itself (see its own
 * docblock), which the *outer* process_one_batch() call is already
 * holding for the entire duration of this reentrant call; a truly nested
 * call can never wait out its own enclosing call the way two genuinely
 * concurrent requests actually would. Simulating the cancel's end state
 * directly sidesteps that mismatch between this test's single-threaded
 * reentrancy trick and real concurrency, without weakening what's
 * actually being proven (that a status change landing mid-batch isn't
 * silently overwritten).
 */
class WWG_Admin_Cancels_Mid_Batch extends \WWG_Admin {

	/**
	 * @var int
	 */
	public $calls = 0;

	/**
	 * @var callable
	 */
	private $simulate_cancel;

	/**
	 * @var array
	 */
	private $canned;

	/**
	 * @param array    $canned          The single batch result to return,
	 *                                  after the simulated cancel.
	 * @param callable $simulate_cancel Mutates the fake transient store to
	 *                                  the same end state a real
	 *                                  handle_cancel_job() call would.
	 */
	public function __construct( array $canned, callable $simulate_cancel ) {
		parent::__construct( new \WWG_Generator() );
		$this->canned          = $canned;
		$this->simulate_cancel = $simulate_cancel;
	}

	public function run_job_batch( $dir_index, $file_offset, $mode ) {
		++$this->calls;
		( $this->simulate_cancel )();
		return $this->canned;
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
	 * Fake backing store for get_option()/update_option() -- only needed
	 * because process_one_batch()'s done branch now calls
	 * WWG_Admin::mark_scan_state_stale() whenever a batch converts
	 * anything, which reads/writes wwg_scan_state like any other option.
	 *
	 * @var array
	 */
	private $options;

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
		$this->options               = array();
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
				// These tests are all about jobs already past counting
				// (the pre-existing suite, from before the counting phase
				// existed at all) -- explicit here rather than leaning on
				// get_state()'s migration backfill for a missing 'phase'
				// key, which is exercised directly by its own tests below.
				'phase'         => 'converting',
				'total_dirs'    => 5,
				'cursor'        => array(
					'dir_index'   => 0,
					'file_offset' => 0,
				),
				'stats'         => array(
					'scanned'              => 0,
					'missing'              => 0,
					'missing_files'        => 0,
					'converted'            => 0,
					'failed'               => 0,
					'original_bytes'       => 0,
					'original_bytes_total' => 0,
					'webp_bytes'           => 0,
					'avif_bytes'           => 0,
					'webp_original_bytes'  => 0,
					'avif_original_bytes'  => 0,
					'webp_present'         => 0,
					'avif_present'         => 0,
					'failures'             => array(),
					'recoveries'           => array(),
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
	private function canned_batch( array $stats_overrides, $done, $dir_index = 1, $file_offset = 0, $total_dirs = 5 ) {
		return array(
			'stats'       => array_merge(
				array(
					'scanned'              => 0,
					'missing'              => 0,
					'missing_files'        => 0,
					'converted'            => 0,
					'failed'               => 0,
					'original_bytes'       => 0,
					'original_bytes_total' => 0,
					'webp_bytes'           => 0,
					'avif_bytes'           => 0,
					'webp_original_bytes'  => 0,
					'avif_original_bytes'  => 0,
					'webp_present'         => 0,
					'avif_present'         => 0,
					'failures'             => array(),
					'recoveries'           => array(),
				),
				$stats_overrides
			),
			'dir'         => '2024/01',
			'dir_index'   => $dir_index,
			'file_offset' => $file_offset,
			'total_dirs'  => $total_dirs,
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

	// ---- the counting phase -- Generate's own scan-equivalent first
	// phase, replacing the old standalone Scan button/flow ----

	public function test_counting_phase_advances_then_flips_to_converting_once_the_tree_is_walked() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'phase'         => 'counting',
				'total_dirs'    => 0,
				'total_missing' => 0,
			)
		);

		$admin          = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$admin->batches = array(
			$this->canned_batch( array( 'missing' => 3, 'missing_files' => 2, 'original_bytes' => 500 ), false, 1, 0 ),
			$this->canned_batch( array( 'missing' => 4, 'missing_files' => 3, 'original_bytes' => 700 ), true, 2, 0 ),
		);
		$job = new \WWG_Job( $admin );

		$job->run_tick();
		$state = $this->transients['wwg_job_state'];
		$this->assertSame( 'counting', $state['phase'] );
		$this->assertSame( 5, $state['total_dirs'] ); // canned_batch()'s default.
		$this->assertSame( 1, $state['cursor']['dir_index'] );

		// handle_drive_job() here rather than run_tick() -- this is the
		// one tick where the response itself needs checking too (see
		// below), not just the persisted transient.
		$job->handle_drive_job();
		$state = $this->transients['wwg_job_state'];

		// Flipped to the real conversion phase, with total_missing now
		// measured firsthand from the walk that just finished (3 + 4)
		// instead of client-supplied.
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 'converting', $state['phase'] );
		$this->assertSame( 7, $state['total_missing'] );

		// Cursor and stats reset to a clean slate for the conversion work
		// about to start -- they described the counting walk, not it.
		$this->assertSame( 0, $state['cursor']['dir_index'] );
		$this->assertSame( 0, $state['cursor']['file_offset'] );
		$this->assertSame( 0, $state['stats']['missing'] );
		$this->assertSame( 0, $state['stats']['converted'] );

		// Region 1's "Library Status" is populated the moment counting
		// finishes, same as the old standalone Scan used to do.
		$scan_state = $this->options['wwg_scan_state'];
		$this->assertSame( 7, $scan_state['missing'] );
		$this->assertSame( 5, $scan_state['missing_files'] );
		$this->assertSame( 1200, $scan_state['original_bytes'] );

		// And handed back in this exact tick's own response too -- the
		// only place admin.js can learn this happened at all, since it
		// has no finishScan()-equivalent request of its own anymore.
		$this->assertSame( 'advanced', $this->last_json['outcome'] );
		$this->assertSame( 7, $this->last_json['scan_state']['missing'] );

		// Still 'advanced', not 'done' -- driving must continue straight
		// into the conversion phase without the client needing to do
		// anything.
		$this->assertSame( 2, $this->schedule_single_calls );
	}

	public function test_counting_phase_finishes_the_job_immediately_when_nothing_is_missing() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'phase'         => 'counting',
				'total_dirs'    => 0,
				'total_missing' => 0,
			)
		);

		$admin          = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$admin->batches = array(
			$this->canned_batch( array( 'missing' => 0 ), true, 1, 0 ),
		);
		$job = new \WWG_Job( $admin );

		$job->handle_drive_job();
		$state = $this->transients['wwg_job_state'];

		// Nothing to convert -- the job finishes right here rather than
		// starting a converting phase with no work in it.
		$this->assertSame( 'done', $state['status'] );
		$this->assertNotNull( $state['finished_at'] );
		$this->assertSame( 0, $this->options['wwg_scan_state']['missing'] );

		// No conversion happened, so nothing to clear the cache over or
		// invalidate the snapshot that was just written.
		$this->assertFalse( $state['cache_cleared'] );
		$this->assertNull( $this->options['wwg_scan_state']['invalidated_at'] );

		// scan_state present here too -- same reasoning as the
		// counting-to-converting flip above.
		$this->assertSame( 'done', $this->last_json['outcome'] );
		$this->assertSame( 0, $this->last_json['scan_state']['missing'] );
	}

	public function test_get_state_treats_a_transient_with_no_phase_key_as_already_converting() {
		// Exactly the shape a transient persisted before the counting
		// phase existed would have -- no 'phase' or 'total_dirs' keys at
		// all, but genuinely mid-conversion (the old standalone Scan was
		// already a separate, finished step by the time a job like this
		// could exist).
		$this->transients['wwg_job_state'] = array(
			'status'        => 'running',
			'cursor'        => array(
				'dir_index'   => 2,
				'file_offset' => 10,
			),
			'stats'         => array(
				'scanned'   => 5,
				'converted' => 3,
				'failed'    => 0,
			),
			'total_missing' => 5,
			'current_dir'   => '2024/01',
			'cache_cleared' => false,
			'started_at'    => 1000,
			'finished_at'   => null,
			'seen'          => false,
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$state = $job->get_state();

		// Backfilled as 'converting', not default_state()'s 'counting' --
		// a blind merge would otherwise wrongly restart this job counting
		// from scratch instead of resuming its real conversion work.
		$this->assertSame( 'converting', $state['phase'] );
		$this->assertSame( 2, $state['cursor']['dir_index'] ); // untouched.
	}

	// ---- the Cancel race (see wwg-job-cancel-race-condition in project
	// memory): handle_cancel_job() doesn't hold LOCK_KEY, so it can write
	// 'paused' while a batch already past its own 'running' check is
	// still mid-flight (real image conversion, or a real directory walk,
	// both genuinely slow) -- fresh_status() re-checks right before
	// deciding to keep a batch's own result as 'running' and reschedule
	// another tick, so a concurrent cancel can't get silently overwritten
	// and the job resurrected with no one watching. ----

	public function test_a_cancel_landing_mid_batch_is_not_silently_overwritten_by_that_batchs_own_write() {
		$this->transients['wwg_job_state'] = $this->running_state();

		$admin = new WWG_Admin_Cancels_Mid_Batch(
			$this->canned_batch( array( 'converted' => 2 ), false, 1, 0 ),
			function () {
				$state             = $this->transients['wwg_job_state'];
				$state['status']   = 'paused';
				$this->transients['wwg_job_state'] = $state;
			}
		);
		$job = new \WWG_Job( $admin );

		$job->run_tick();

		$state = $this->transients['wwg_job_state'];
		// The cancel that landed mid-batch must win -- not get
		// overwritten by this batch's own stale 'running' read from
		// before the cancel happened.
		$this->assertSame( 'paused', $state['status'] );
		// The real work this batch actually did (files genuinely written
		// to disk by the time run_job_batch() returns) is kept either
		// way -- only whether to call it 'running' and reschedule was
		// ever in question.
		$this->assertSame( 2, $state['stats']['converted'] );
		$this->assertSame( 1, $state['cursor']['dir_index'] );
		// Critically: no cron event left scheduled to keep the job
		// resurrecting itself with no tab watching.
		$this->assertSame( 0, $this->schedule_single_calls );
	}

	public function test_a_cancel_landing_during_the_counting_to_converting_flip_is_honored() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'phase'         => 'counting',
				'total_dirs'    => 0,
				'total_missing' => 0,
			)
		);

		$admin = new WWG_Admin_Cancels_Mid_Batch(
			$this->canned_batch( array( 'missing' => 3, 'missing_files' => 2, 'original_bytes' => 500 ), true, 2, 0 ),
			function () {
				$state             = $this->transients['wwg_job_state'];
				$state['status']   = 'paused';
				$this->transients['wwg_job_state'] = $state;
			}
		);
		$job = new \WWG_Job( $admin );

		$job->run_tick();

		$state = $this->transients['wwg_job_state'];
		$this->assertSame( 'paused', $state['status'] );
		// The phase flip itself is still real, safe-to-keep progress --
		// only 'running'+reschedule is what a concurrent cancel prevents.
		$this->assertSame( 'converting', $state['phase'] );
		$this->assertSame( 3, $state['total_missing'] );
		$this->assertSame( 0, $this->schedule_single_calls );

		// Library Status was still persisted from the counting walk that
		// did complete -- a cancelled job shouldn't lose that on its way
		// to (not) converting.
		$this->assertSame( 3, $this->options['wwg_scan_state']['missing'] );
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
					'avif_bytes'     => 0,
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
					'avif_bytes'     => 0,
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
		// A pause is a live, still-frozen state -- not the fallback below,
		// which is specifically for finding the job already 'done'.
		$this->assertArrayNotHasKey( 'scan_state', $this->last_json );
	}

	/**
	 * The race this closes: WP-Cron's own run_tick() (or another browser
	 * tab) can finish the job -- including the counting-phase transition
	 * that normally hands back a fresh $scan_state -- entirely between
	 * this poll being sent and it arriving. Without the fallback in
	 * process_one_batch()'s "not running at entry" branch, this poll
	 * would report 'stopped'/'done' correctly but leave the client's
	 * Library Status stuck on whatever it last knew, since this is the
	 * only response it will ever see for this job.
	 *
	 * @covers \WWG_Job::process_one_batch
	 */
	public function test_handle_drive_job_reports_fresh_scan_state_when_already_done_at_entry() {
		$this->transients['wwg_job_state'] = $this->running_state( array( 'status' => 'done' ) );
		$this->options['wwg_scan_state']   = array(
			'missing'              => 0,
			'missing_files'        => 0,
			'original_bytes'       => 0,
			'total_images'         => 47382,
			'original_bytes_total' => 300,
			'webp_bytes'           => 100,
			'avif_bytes'           => 80,
			'webp_original_bytes'  => 200,
			'avif_original_bytes'  => 200,
			'webp_present'         => 47382,
			'avif_present'         => 47382,
			'failures'             => array(),
			'finished_at'          => 12345,
			'invalidated_at'       => null,
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->handle_drive_job();

		$this->assertSame( 'stopped', $this->last_json['outcome'] );
		$this->assertSame( 0, $admin->calls );
		$this->assertArrayHasKey( 'scan_state', $this->last_json );
		$this->assertSame( $this->options['wwg_scan_state'], $this->last_json['scan_state'] );
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

	// ---- remove_failure_from_state() ----

	public function test_remove_failure_from_state_reclassifies_a_fixed_entry_as_converted() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 2,
					'converted'      => 3,
					'failed'         => 2,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					'failures'       => array(
						array( 'file' => '2024/01/a.jpg', 'format' => 'webp', 'error' => 'x' ),
						array( 'file' => '2024/01/b.jpg', 'format' => 'webp', 'error' => 'y' ),
					),
				),
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->remove_failure_from_state( '2024/01/a.jpg', 'webp', true );

		$state = $this->transients['wwg_job_state'];
		$this->assertCount( 1, $state['stats']['failures'] );
		$this->assertSame( '2024/01/b.jpg', $state['stats']['failures'][0]['file'] );
		$this->assertSame( 1, $state['stats']['failed'] );
		$this->assertSame( 4, $state['stats']['converted'] ); // +1.
		$this->assertSame( 2, $state['stats']['missing'] ); // unchanged -- a fix doesn't change the run's total attempted count.
		$this->assertSame( 5, $state['total_missing'] ); // unchanged too.
	}

	public function test_remove_failure_from_state_shrinks_missing_and_total_missing_when_deleted() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 2,
					'converted'      => 3,
					'failed'         => 1,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					'failures'       => array(
						array( 'file' => '2024/01/a.jpg', 'format' => 'webp', 'error' => 'x' ),
					),
				),
				'total_missing' => 5,
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->remove_failure_from_state( '2024/01/a.jpg', 'webp', false );

		$state = $this->transients['wwg_job_state'];
		$this->assertSame( array(), $state['stats']['failures'] );
		$this->assertSame( 0, $state['stats']['failed'] );
		$this->assertSame( 3, $state['stats']['converted'] ); // unchanged -- not a fix.
		$this->assertSame( 1, $state['stats']['missing'] ); // -1.
		$this->assertSame( 4, $state['total_missing'] ); // -1.
	}

	public function test_remove_failure_from_state_with_null_format_clears_every_format_of_the_file_at_once() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 2,
					'converted'      => 0,
					'failed'         => 2,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					'failures'       => array(
						array( 'file' => '2024/01/a.jpg', 'format' => 'webp', 'error' => 'x' ),
						array( 'file' => '2024/01/a.jpg', 'format' => 'avif', 'error' => 'y' ),
					),
				),
				'total_missing' => 5,
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		// Mirrors delete_failure_file()'s call convention -- the source
		// file is gone, so every format's record for it is moot at once,
		// not just one.
		$job->remove_failure_from_state( '2024/01/a.jpg', null, false );

		$state = $this->transients['wwg_job_state'];
		$this->assertSame( array(), $state['stats']['failures'] );
		$this->assertSame( 0, $state['stats']['failed'] );
		$this->assertSame( 0, $state['stats']['missing'] ); // both units removed.
		$this->assertSame( 3, $state['total_missing'] ); // -2.
	}

	public function test_remove_failure_from_state_with_a_specific_format_leaves_the_other_format_of_the_same_file_alone() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 2,
					'converted'      => 0,
					'failed'         => 2,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					'failures'       => array(
						array( 'file' => '2024/01/a.jpg', 'format' => 'webp', 'error' => 'x' ),
						array( 'file' => '2024/01/a.jpg', 'format' => 'avif', 'error' => 'y' ),
					),
				),
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->remove_failure_from_state( '2024/01/a.jpg', 'webp', true );

		$state = $this->transients['wwg_job_state'];
		$this->assertCount( 1, $state['stats']['failures'] );
		$this->assertSame( 'avif', $state['stats']['failures'][0]['format'] );
		$this->assertSame( 1, $state['stats']['failed'] );
		$this->assertSame( 1, $state['stats']['converted'] );
	}

	public function test_remove_failure_from_state_treats_a_pre_avif_entry_with_no_format_key_as_webp() {
		$this->transients['wwg_job_state'] = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 1,
					'converted'      => 0,
					'failed'         => 1,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					// No 'format' key -- exactly what a job paused right
					// at the upgrade boundary could still be holding.
					'failures'       => array(
						array( 'file' => '2024/01/old.jpg', 'error' => 'x' ),
					),
				),
			)
		);

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		$job->remove_failure_from_state( '2024/01/old.jpg', 'webp', true );

		$state = $this->transients['wwg_job_state'];
		$this->assertSame( array(), $state['stats']['failures'] );
		$this->assertSame( 1, $state['stats']['converted'] );
	}

	public function test_remove_failure_from_state_is_a_noop_when_the_file_is_not_in_this_runs_failures() {
		$original_state                    = $this->running_state(
			array(
				'stats' => array(
					'scanned'        => 5,
					'missing'        => 1,
					'converted'      => 0,
					'failed'         => 1,
					'original_bytes' => 0,
					'webp_bytes'     => 0,
					'avif_bytes'     => 0,
					'failures'       => array(
						array( 'file' => '2024/01/a.jpg', 'format' => 'webp', 'error' => 'x' ),
					),
				),
			)
		);
		$this->transients['wwg_job_state'] = $original_state;

		$admin = new WWG_Admin_Fake_Batch( new \WWG_Generator() );
		$job   = new \WWG_Job( $admin );

		// Only ever in the Library Status snapshot, not this run's own record.
		$job->remove_failure_from_state( '2024/01/somewhere-else.jpg', 'webp', true );

		$this->assertSame( $original_state, $this->transients['wwg_job_state'] );
	}
}
