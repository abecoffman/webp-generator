<?php
/**
 * Runs "Generate" as a background WP-Cron job instead of a client-driven
 * AJAX loop, so a large library keeps converting even if the admin closes
 * the tab -- plus the completion notifications (Tools menu bubble,
 * dismissible admin notice, Heartbeat live-update) and the state hydration
 * that let the Tools > WebP Generator page reflect an in-progress/paused/
 * done job on load instead of always booting blank.
 *
 * Deliberately does not duplicate the actual batch logic -- WWG_Admin's
 * process_batch()/get_scan_directories() stay exactly where they are
 * (private, unit-tested via Reflection); this class reaches them through
 * WWG_Admin::run_job_batch(), a thin public wrapper.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns job state, WP-Cron scheduling, and the admin-area completion UI.
 */
class WWG_Job {

	/**
	 * The self-perpetuating cron hook: each tick processes one batch and,
	 * if not done, schedules the next single event itself. Registered
	 * unconditionally in init() -- wp-cron.php requests never set
	 * is_admin(), so this cannot live behind the same is_admin() gate as
	 * the rest of this class's hooks (see init_admin()) or the tick
	 * callback would never be found when WP-Cron actually fires it.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wwg_process_job_tick';

	/**
	 * Transient holding the single persisted job record. One job at a
	 * time, site-wide -- not per-user, so any admin who can manage the
	 * tool sees the same live progress and the same completion signal.
	 *
	 * @var string
	 */
	const STATE_KEY = 'wwg_job_state';

	/**
	 * Transient used as a mutex around one tick's batch of work, so two
	 * overlapping cron dispatches (e.g. real system cron and WordPress's
	 * own pseudo-cron both firing) can't process the same batch twice.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'wwg_job_lock';

	/**
	 * Comfortably above one batch's expected runtime (40 image
	 * conversions), short enough that a tick that dies mid-batch (OOM,
	 * a host's execution-time kill) self-clears within a couple of
	 * minutes rather than freezing the job forever with no visible error.
	 *
	 * @var int
	 */
	const LOCK_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * How long handle_cancel_job() will wait (in microseconds) for a
	 * batch already in flight to finish and release LOCK_KEY on its own,
	 * before reading/writing state itself -- see its own docblock and
	 * fresh_status()'s. Well under LOCK_TTL: this is bounding a single
	 * batch's normal runtime, not covering for a crashed one (that's what
	 * LOCK_TTL itself is for).
	 *
	 * @var int
	 */
	const CANCEL_LOCK_WAIT = 3 * 1000000;

	/**
	 * Slid forward on every write (not a fixed expiry) so a long-running
	 * job on a large library can't have its own state expire mid-run, and
	 * a finished-but-unseen completion notice survives a normal weekend
	 * gap before anyone opens wp-admin again.
	 *
	 * @var int
	 */
	const STATE_TTL = WEEK_IN_SECONDS;

	const ACTION_START   = 'wwg_start_job';
	const ACTION_STATUS  = 'wwg_job_status';
	const ACTION_CANCEL  = 'wwg_cancel_job';
	const ACTION_DISMISS = 'wwg_dismiss_job_notice';
	const ACTION_DRIVE   = 'wwg_drive_job';

	/**
	 * @var WWG_Admin
	 */
	private $admin;

	/**
	 * @param WWG_Admin $admin Supplies run_job_batch() during cron ticks,
	 *                         get_strings() for the notice/heartbeat copy,
	 *                         save_scan_state() when a job's own counting
	 *                         phase finishes walking the tree, and
	 *                         mark_scan_state_stale() when a batch
	 *                         actually converts something.
	 */
	public function __construct( WWG_Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Register just the cron hook. Called on every request type,
	 * including wp-cron.php dispatches -- see the CRON_HOOK docblock for
	 * why this can't wait for init_admin()'s is_admin() gate.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'run_tick' ) );
	}

	/**
	 * Register the admin-only surface: the AJAX endpoints the tool page
	 * talks to, the completion notice, and Heartbeat/menu-bubble wiring
	 * that needs to run on every wp-admin screen, not just the tool page.
	 */
	public function init_admin() {
		add_action( 'wp_ajax_' . self::ACTION_START, array( $this, 'handle_start_job' ) );
		add_action( 'wp_ajax_' . self::ACTION_STATUS, array( $this, 'handle_status' ) );
		add_action( 'wp_ajax_' . self::ACTION_CANCEL, array( $this, 'handle_cancel_job' ) );
		add_action( 'wp_ajax_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss_notice' ) );
		add_action( 'wp_ajax_' . self::ACTION_DRIVE, array( $this, 'handle_drive_job' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_heartbeat_script' ) );
		add_filter( 'heartbeat_received', array( $this, 'filter_heartbeat' ) );
	}

	/**
	 * AJAX: start a fresh job, or resume a paused one. A no-op (returns
	 * the unchanged state) if a job is already running -- defense in
	 * depth alongside the client already disabling the button, and the
	 * only guard at all against two admins racing to click Generate at
	 * the same instant before either has hydrated the running state.
	 */
	public function handle_start_job() {
		check_ajax_referer( WWG_Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			if ( 'paused' === $state['status'] ) {
				// Resume: cursor and accumulated stats are kept exactly
				// as they were -- this is also what makes a Cancel then
				// a later reload no longer lose all progress.
				$state['status']      = 'running';
				$state['finished_at'] = null;
			} else {
				// total_missing starts at 0, not client-supplied -- it's
				// not knowable yet. The job's own counting phase (see
				// process_one_batch()) fills it in once it's actually
				// walked the tree, the same number Scan used to report
				// ahead of time from a separate, potentially-stale run.
				$state               = $this->default_state();
				$state['status']     = 'running';
				$state['started_at'] = time();
			}

			$this->write_state( $state );
			$this->ensure_scheduled();
		}

		wp_send_json_success( $state );
	}

	/**
	 * The cron callback -- a thin delegate so run_tick() and
	 * handle_drive_job() (the client's fast-path AJAX equivalent) share
	 * every bit of batch-processing/locking logic via process_one_batch()
	 * rather than reimplementing it twice. No capability/nonce check here
	 * -- cron dispatches run with no current user; authorization already
	 * happened when the job was scheduled from handle_start_job().
	 */
	public function run_tick() {
		$this->process_one_batch();
	}

	/**
	 * AJAX: process exactly one batch synchronously and return the
	 * outcome, bypassing spawn_cron()'s loopback (and the ~60s
	 * WP_CRON_LOCK_TIMEOUT self-throttle that comes with it) entirely --
	 * the same way `wp cron event run` does. This is what lets an admin
	 * actively on the tool page drive the job at full speed while
	 * run_tick() stays exactly as the "survives closing the tab" safety
	 * net; both go through process_one_batch() and its LOCK_KEY mutex, so
	 * they can never double-process the same batch.
	 */
	public function handle_drive_job() {
		check_ajax_referer( WWG_Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		// Response shape is deliberately {outcome, state}, not a bare
		// state object like the other AJAX handlers -- the client needs
		// to tell "locked, try again shortly" apart from "stopped" apart
		// from "made progress," not just get a state snapshot.
		wp_send_json_success( $this->process_one_batch() );
	}

	/**
	 * The single place that acquires LOCK_KEY, validates the job is
	 * still running, runs one real batch, merges its results into state,
	 * handles the done transition, and (re)schedules the cron safety
	 * net -- called by both run_tick() (cron) and handle_drive_job()
	 * (the client's fast path), so their behavior can't drift apart.
	 *
	 * @return array {
	 *     @type string $outcome One of:
	 *       'locked'   - another process holds the lock; no work done.
	 *       'stopped'  - state wasn't 'running' at entry, OR a real batch
	 *                    of work (a directory walk or actual conversion)
	 *                    just ran but the status changed out from under it
	 *                    while that was in flight (see fresh_status()) --
	 *                    either way, the caller should stop.
	 *       'advanced' - one batch processed, job continues.
	 *       'done'     - this batch finished the job.
	 *     @type array  $state Fresh state for advanced/done/stopped;
	 *                  last-known state for locked.
	 *     @type array  $scan_state Present on the one tick where the
	 *                  counting phase just finished (see the 'counting'
	 *                  branch below) -- the freshly-persisted "Library
	 *                  Status" snapshot, so the client can update Region 1
	 *                  the instant this happens instead of it staying
	 *                  frozen on its last "counting" render. Also present
	 *                  on a 'stopped' found already-'done' at entry, as a
	 *                  fallback for exactly that tick having been won by a
	 *                  different process (WP-Cron's own run_tick(), racing
	 *                  this one) -- otherwise this poll would have no way
	 *                  to learn it. Absent everywhere else (a 'locked' /
	 *                  paused 'stopped' / mid-run 'advanced' has nothing
	 *                  new to report -- Library Status is frozen by design
	 *                  until the next full count, see OPTION_SCAN_STATE).
	 * }
	 */
	private function process_one_batch() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return array(
				'outcome' => 'locked',
				'state'   => $this->get_state(),
			);
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			// Cancelled (or otherwise no longer running) between this
			// batch being scheduled/requested and now -- do no work, and
			// don't reschedule; whatever stopped it already cleared the
			// schedule itself.
			delete_transient( self::LOCK_KEY );
			$response = array(
				'outcome' => 'stopped',
				'state'   => $state,
			);
			if ( 'done' === $state['status'] ) {
				// The job can finish via a completely different process
				// than the one polling right now -- run_tick() (WP-Cron's
				// own self-perpetuating safety net) keeps a job moving
				// even with no browser tab open at all, so it can just as
				// easily be the one that lands the counting-finished tick
				// below (the one that normally hands back a fresh
				// $scan_state) while THIS request, arriving a moment
				// later, finds the job already done and never sees that
				// response. Without this, Library Status would be stuck
				// showing whatever it last knew -- stale, though never
				// wrong in a way that looks broken (see get_scan_state()'s
				// own self-invalidation for the case where the numbers
				// actually did change) -- until an unrelated event
				// happened to refresh it. A cheap read (one get_option()
				// call), so there's no reason not to always double-check
				// here rather than trust the one-tick handoff alone.
				$response['scan_state'] = $this->admin->get_scan_state();
			}
			return $response;
		}

		// Counting is a read-only pass -- the same walk the old standalone
		// Scan did (file_exists() checks only, see process_batch()'s own
		// 'scan' mode) -- before switching to the real conversion work
		// below it once counting's own done handling (further down) flips
		// this to 'converting'.
		$mode   = 'counting' === $state['phase'] ? 'scan' : 'convert';
		$result = $this->admin->run_job_batch( $state['cursor']['dir_index'], $state['cursor']['file_offset'], $mode );

		$state['cursor']      = array(
			'dir_index'   => $result['dir_index'],
			'file_offset' => $result['file_offset'],
		);
		$state['current_dir'] = $result['dir'];
		$state['total_dirs']  = $result['total_dirs'];

		// 'missing_files' (process_batch()'s distinct-file headline count)
		// is safe to merge the same way as every key below: each batch's
		// slice of files is a monotonically advancing, non-overlapping
		// window (the cursor only ever moves forward within one run, and
		// a 'locked' outcome above does no work and merges nothing), so
		// summing it batch by batch gives a true running distinct-file
		// total -- exactly the same reasoning that already makes
		// 'original_bytes' (computed the same way, once per distinct
		// file) safe to merge. This job's own progress math never reads
		// it (total_missing/started_at only need the conversion-unit
		// numbers), but WWG_Admin's Library Status region does, to keep
		// its last-Scan snapshot's file count live while a run is
		// resolving it (see admin.js's renderStatusLiveDuringJob()).
		foreach ( array( 'scanned', 'missing', 'missing_files', 'converted', 'failed', 'original_bytes', 'original_bytes_total', 'webp_bytes', 'avif_bytes', 'webp_original_bytes', 'avif_original_bytes', 'webp_present', 'avif_present' ) as $key ) {
			$state['stats'][ $key ] += $result['stats'][ $key ];
		}

		if ( ! empty( $result['stats']['failures'] ) ) {
			// Capped at 500 stored entries to bound the transient's size
			// on a run with a lot of failures -- the client only ever
			// displays the most recent 50 of these anyway.
			$state['stats']['failures'] = array_slice(
				array_merge( $state['stats']['failures'], $result['stats']['failures'] ),
				-500
			);
		}

		if ( ! empty( $result['stats']['recoveries'] ) ) {
			// Same array-merge-and-cap treatment as failures above --
			// recoveries are expected to be rare, but not bounding this
			// would still be an unbounded-growth risk on a pathological
			// library.
			$state['stats']['recoveries'] = array_slice(
				array_merge( $state['stats']['recoveries'], $result['stats']['recoveries'] ),
				-500
			);
		}

		if ( $result['done'] && 'counting' === $state['phase'] ) {
			// The read-only pass just finished walking the whole tree --
			// persist exactly what the old standalone Scan used to persist
			// (same shape: missing/missing_files/original_bytes/failures),
			// so Region 1's "Library Status" is populated the moment
			// counting completes, same as before. Also handed back in the
			// response below (a key no other tick's response carries) --
			// unlike the old standalone Scan, admin.js has no
			// finishScan()-equivalent request of its own to learn this
			// from; this transition tick's own response is the only place
			// it can, so Region 1 can leave its "counting" render the
			// instant this happens instead of staying frozen there.
			$scan_state = $this->admin->save_scan_state( $state['stats'] );

			if ( 0 === $state['stats']['missing'] ) {
				// Nothing to convert -- finish the job right here rather
				// than starting a converting phase with no work in it.
				$state['status']      = 'done';
				$state['finished_at'] = time();
				$state['seen']        = false;
				$this->write_state( $state );
				delete_transient( self::LOCK_KEY );
				return array(
					'outcome'    => 'done',
					'state'      => $state,
					'scan_state' => $scan_state,
				);
			}

			// Flip into the real conversion phase: the cursor and stats
			// accumulated above described the counting walk, not the
			// conversion work about to start, so both reset to a clean
			// slate exactly like a brand new job's -- total_missing is the
			// one number that survives, now measured firsthand instead of
			// client-supplied from a possibly-stale prior Scan.
			$state['total_missing'] = $state['stats']['missing'];
			$state['phase']         = 'converting';
			$state['cursor']        = array(
				'dir_index'   => 0,
				'file_offset' => 0,
			);
			$state['stats']         = $this->default_state()['stats'];

			// Re-check the persisted status fresh, right before deciding to
			// keep this 'running' and reschedule another tick -- see
			// fresh_status()'s own docblock for why this specific spot
			// matters (a Cancel can land while the counting walk above was
			// still in flight).
			$fresh_status = $this->fresh_status();
			if ( 'running' !== $fresh_status ) {
				$state['status'] = $fresh_status;
				$this->write_state( $state );
				delete_transient( self::LOCK_KEY );
				return array(
					'outcome'    => 'stopped',
					'state'      => $state,
					'scan_state' => $scan_state,
				);
			}

			$this->write_state( $state );
			delete_transient( self::LOCK_KEY );
			$this->ensure_scheduled();

			return array(
				'outcome'    => 'advanced',
				'state'      => $state,
				'scan_state' => $scan_state,
			);
		}

		if ( $result['done'] ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			$state['seen']        = false;

			if ( $state['stats']['converted'] > 0 ) {
				WWG_Cache::clear_all();
				$state['cache_cleared'] = true;
				// The library just changed -- Scan's last "Library Status"
				// snapshot (see WWG_Admin::OPTION_SCAN_STATE) is now
				// provably out of date, so mark it stale right at the exact
				// moment that becomes true rather than leaving it to
				// silently lie until an arbitrary TTL expires.
				$this->admin->mark_scan_state_stale();
			}

			$this->write_state( $state );
			delete_transient( self::LOCK_KEY );
			return array(
				'outcome' => 'done',
				'state'   => $state,
			);
		}

		// Same re-check as the counting-phase flip above -- the real
		// conversion work in run_job_batch() just above is the slow part
		// (real image encoding, not a cheap file_exists() walk), so this
		// is the spot with the widest window for a Cancel to land while
		// it was running. See fresh_status()'s own docblock.
		$fresh_status = $this->fresh_status();
		if ( 'running' !== $fresh_status ) {
			$state['status'] = $fresh_status;
			$this->write_state( $state );
			delete_transient( self::LOCK_KEY );
			return array(
				'outcome' => 'stopped',
				'state'   => $state,
			);
		}

		$this->write_state( $state );
		delete_transient( self::LOCK_KEY );
		$this->ensure_scheduled();

		return array(
			'outcome' => 'advanced',
			'state'   => $state,
		);
	}

	/**
	 * The persisted status, re-read fresh from the transient store rather
	 * than trusted from whatever process_one_batch() read at its own
	 * entry -- used right before it decides to keep a job 'running' (and
	 * schedule another tick) after the slow part of a batch (a real
	 * directory walk or real image encoding) has already run.
	 *
	 * This closes a real race: handle_cancel_job() doesn't hold LOCK_KEY,
	 * so it can write 'paused' while a batch already past its own
	 * 'running' check is still mid-flight. Without re-checking here, that
	 * batch's own state -- fetched *before* the cancel, still saying
	 * 'running' -- would silently overwrite the cancel's write when it
	 * saves at the end, and then reschedule another tick on top of it,
	 * resurrecting a job the user just told to stop (see
	 * wwg-job-cancel-race-condition in project memory for how this was
	 * first found: it let a real conversion run keep going for close to
	 * an hour after Cancel appeared to succeed). The real work already
	 * done by this batch (files actually written to disk, cursor/stats
	 * already merged into $state by the caller) is kept either way --
	 * only whether to call it 'running' and reschedule is in question.
	 *
	 * @return string
	 */
	private function fresh_status() {
		return $this->get_state()['status'];
	}

	/**
	 * AJAX: pause a running job. Cursor and stats are deliberately kept,
	 * not discarded -- Generate resumes from exactly here, same as
	 * before this class existed, just now surviving a page reload too.
	 */
	public function handle_cancel_job() {
		check_ajax_referer( WWG_Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		// Wait for any batch currently in flight to finish and release
		// LOCK_KEY itself, rather than reading state while it might still
		// be mid-write -- see fresh_status()'s own docblock for the race
		// this closes. Bounded, not indefinite: a stuck/crashed lock
		// (past LOCK_TTL) or a batch that's simply slow shouldn't hang
		// this request forever -- worst case, the race this narrows is
		// still far better than not waiting at all.
		$waited = 0;
		while ( get_transient( self::LOCK_KEY ) && $waited < self::CANCEL_LOCK_WAIT ) {
			usleep( 100000 ); // 100ms.
			$waited += 100000;
		}

		$state = $this->get_state();

		if ( 'running' === $state['status'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$state['status'] = 'paused';
			$this->write_state( $state );
		}

		// Deleted last, after our own write -- if we ever did time out
		// the wait above, this still forces an immediately-resumable
		// state (the original reason this was here at all) rather than
		// leaving a stale lock around for its own LOCK_TTL.
		delete_transient( self::LOCK_KEY );

		wp_send_json_success( $state );
	}

	/**
	 * AJAX: the tool page's poll. Loading this counts as having seen a
	 * finished job, same as loading the page itself (see
	 * get_hydrated_state()).
	 */
	public function handle_status() {
		check_ajax_referer( WWG_Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		wp_send_json_success( $this->get_hydrated_state() );
	}

	/**
	 * AJAX: the completion notice/bubble's explicit dismiss, for an admin
	 * who wants to acknowledge it without navigating to the tool page.
	 */
	public function handle_dismiss_notice() {
		check_ajax_referer( WWG_Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$state = $this->get_state();
		if ( 'done' === $state['status'] ) {
			$state['seen'] = true;
			$this->write_state( $state );
		}

		wp_send_json_success();
	}

	/**
	 * The dismissible completion banner, shown on every wp-admin screen
	 * until seen. Deliberately does NOT mark the job seen just by
	 * rendering -- only actually reading the result (visiting the tool
	 * page, polling it, or explicitly dismissing) should clear it.
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			return;
		}

		$state = $this->get_state();
		if ( 'done' !== $state['status'] || $state['seen'] ) {
			return;
		}

		$strings = WWG_Admin::get_strings();

		$summary = sprintf( $strings['generateDone'], $state['stats']['converted'] );
		if ( $state['stats']['failed'] > 0 ) {
			$summary .= ' ' . sprintf( $strings['failedSummary'], $state['stats']['failed'] );
		}
		if ( $state['cache_cleared'] ) {
			$summary .= ' ' . $strings['cacheCleared'];
		}

		$view_url = add_query_arg( array( 'page' => WWG_Admin::PAGE_SLUG ), admin_url( 'tools.php' ) );

		// notice-dismiss is WP core's own is-dismissible button, injected
		// and fade-out-animated by wp-admin's common.js -- assets/
		// admin-heartbeat.js (loaded on every screen) adds one more click
		// handler on that same button to persist the dismissal via
		// ACTION_DISMISS, rather than this reinventing that button.
		printf(
			'<div class="notice notice-success is-dismissible wwg-job-notice" id="wwg-job-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $summary ),
			esc_url( $view_url ),
			esc_html( $strings['viewResults'] )
		);
	}

	/**
	 * Loads on every admin screen, not just the tool page -- there's
	 * nothing to branch on, unlike WWG_Admin::enqueue_assets().
	 */
	public function enqueue_heartbeat_script() {
		if ( ! current_user_can( WWG_Admin::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_script(
			'wwg-heartbeat',
			plugins_url( 'assets/admin-heartbeat.js', WWG_FILE ),
			array( 'jquery', 'heartbeat' ),
			WWG_VERSION,
			true
		);

		wp_localize_script(
			'wwg-heartbeat',
			'wwgHeartbeat',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( WWG_Admin::NONCE_ACTION ),
				'dismissAction' => self::ACTION_DISMISS,
				'toolUrl'       => add_query_arg( array( 'page' => WWG_Admin::PAGE_SLUG ), admin_url( 'tools.php' ) ),
				'strings'       => WWG_Admin::get_strings(),
			)
		);
	}

	/**
	 * Attaches a small job summary to every Heartbeat tick so the notice/
	 * bubble can appear live if the admin is already sitting on a
	 * different wp-admin screen when the job finishes, rather than only
	 * updating on the next full page load. WordPress's default Heartbeat
	 * interval outside post-editing screens is 60s, so "live" here means
	 * within about a minute, not instant.
	 *
	 * @param array $response Existing Heartbeat response.
	 * @return array
	 */
	public function filter_heartbeat( $response ) {
		if ( current_user_can( WWG_Admin::CAPABILITY ) ) {
			$state               = $this->get_state();
			$response['wwg_job'] = array(
				'status'       => $state['status'],
				'seen'         => $state['seen'],
				'converted'    => $state['stats']['converted'],
				'failed'       => $state['stats']['failed'],
				'cacheCleared' => $state['cache_cleared'],
			);
		}

		return $response;
	}

	/**
	 * The Tools submenu count bubble, appended to the "WebP Generator"
	 * menu label -- same markup component WP core uses for its own
	 * Plugins-update/Comments-pending counts.
	 *
	 * Hydrates (and so can clear itself) when the current request is for
	 * the tool page itself -- register_page() runs on the 'admin_menu'
	 * hook, which fires before 'admin_enqueue_scripts' (where
	 * WWG_Admin::enqueue_assets() normally does this hydration) even on
	 * the tool page's own request, so without this the bubble would
	 * still show once more on the exact pageview that clears it,
	 * correcting itself only on the next navigation. Everywhere else,
	 * a plain (non-mutating) read -- admin_menu fires on every admin
	 * screen, and hydrating unconditionally there would mark the result
	 * seen before admin_notices ever gets a chance to show it.
	 *
	 * @return string HTML, or '' if there's nothing unseen to flag.
	 */
	public function get_bubble_html() {
		$state = $this->is_tool_page_request() ? $this->get_hydrated_state() : $this->get_state();
		if ( 'done' !== $state['status'] || $state['seen'] ) {
			return '';
		}

		return ' <span class="update-plugins count-1"><span class="update-count">1</span></span>';
	}

	/**
	 * Read-only check deciding which of two display branches
	 * get_bubble_html() takes -- not a write, so no nonce is needed here
	 * any more than for admin-page.php's own $_GET['settings-updated']
	 * notice-text check.
	 *
	 * @return bool
	 */
	private function is_tool_page_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides which of two display branches to take, same as admin-page.php's own $_GET['settings-updated'] check.
		return isset( $_GET['page'] ) && WWG_Admin::PAGE_SLUG === $_GET['page'];
	}

	/**
	 * Raw state read, defensively merged against default_state() so a
	 * transient written by an older/different shape (or simply absent)
	 * never produces missing-array-key notices. No side effects -- use
	 * get_hydrated_state() wherever loading the state should also count
	 * as having seen a finished job.
	 *
	 * @return array
	 */
	public function get_state() {
		$state = get_transient( self::STATE_KEY );
		if ( ! is_array( $state ) ) {
			return $this->default_state();
		}

		// Checked against the raw transient, before the merge below fills
		// missing keys in from default_state() -- default_state()'s own
		// 'phase' default ('counting') is only correct for a genuinely new
		// job. A transient persisted before this field existed was, by
		// definition, always mid-conversion: the old standalone Scan was a
		// separate, already-completed step by the time a job could exist
		// at all, so it never had a "counting" phase of its own to be mid-
		// way through. Backfilling 'counting' for one of these via a blind
		// merge would wrongly restart it counting from scratch instead of
		// resuming the real conversion work it was actually doing.
		$had_phase = array_key_exists( 'phase', $state );

		$defaults = $this->default_state();
		$state    = array_merge( $defaults, $state );

		if ( ! $had_phase ) {
			$state['phase'] = 'converting';
		}

		// Nested merge for 'stats' specifically -- the array_merge() above
		// only guards missing top-level keys; a state transient persisted
		// by an older version of this plugin (e.g. a run left paused
		// across an upgrade) would otherwise still be missing a stats key
		// added later (like 'recoveries'), since array_merge() replaces
		// 'stats' wholesale rather than merging into it.
		$state['stats'] = array_merge( $defaults['stats'], is_array( $state['stats'] ) ? $state['stats'] : array() );

		return $state;
	}

	/**
	 * Used by WWG_Admin::enqueue_assets() (page-load hydration) and by
	 * handle_status() (the tool page's poll) -- the two places where
	 * "the admin just read this result" is true and should clear the
	 * unseen-completion flag.
	 *
	 * @return array
	 */
	public function get_hydrated_state() {
		return $this->mark_seen_if_done( $this->get_state() );
	}

	/**
	 * Called by WWG_Admin once a failure listed in this run's own record
	 * stops being one -- fixed (regenerated) or deleted through the
	 * "Failed conversions" panel's per-row action -- so the persisted
	 * "Last run" summary reflects it without needing a whole new Generate
	 * run. A no-op if $file_rel isn't actually in this run's failures
	 * (e.g. it was only ever in the Library Status snapshot, not a
	 * completed Generate run's own record).
	 *
	 * @param string      $file_rel Relative path under uploads.
	 * @param string|null $format   The one format that stopped failing, or
	 *                              null to clear every format of this file
	 *                              at once (the source itself is gone).
	 * @param bool        $fixed    True if regenerated (reclassified from
	 *                              failed to converted -- the run's total
	 *                              attempted count doesn't change); false
	 *                              if deleted (removed from the run's
	 *                              totals entirely -- it's not "missing" a
	 *                              derived version anymore, there's
	 *                              nothing left to convert).
	 */
	public function remove_failure_from_state( $file_rel, $format, $fixed ) {
		$state = $this->get_state();
		if ( empty( $state['stats']['failures'] ) ) {
			return;
		}

		$removed_units              = 0;
		$before                     = count( $state['stats']['failures'] );
		$state['stats']['failures'] = array_values(
			array_filter(
				$state['stats']['failures'],
				static function ( $failure ) use ( $file_rel, $format, &$removed_units ) {
					if ( ! isset( $failure['file'] ) || $failure['file'] !== $file_rel ) {
						return true; // Keep -- a different file entirely.
					}
					// A failure entry recorded before AVIF existed has no
					// `format` key at all -- only WebP could have been
					// meant then.
					$entry_format = isset( $failure['format'] ) ? $failure['format'] : WWG_Format::WEBP;
					if ( null !== $format && $entry_format !== $format ) {
						return true; // Keep -- this file, but a different format than the one clearing now.
					}
					++$removed_units;
					return false; // Drop.
				}
			)
		);

		if ( count( $state['stats']['failures'] ) === $before ) {
			return; // Wasn't part of this run's own record -- nothing to adjust.
		}

		$state['stats']['failed'] = max( 0, $state['stats']['failed'] - $removed_units );

		if ( $fixed ) {
			$state['stats']['converted'] += $removed_units;
		} else {
			$state['stats']['missing'] = max( 0, $state['stats']['missing'] - $removed_units );
			// Keeps the progress bar's processed/target math consistent --
			// a deleted file was never going to be converted, so it
			// shouldn't stay counted in what this run originally set out
			// to do either (otherwise a previously-100%-done run could
			// show under 100% after a deletion that happened well after
			// it finished).
			$state['total_missing'] = max( 0, $state['total_missing'] - $removed_units );
		}

		$this->write_state( $state );
	}

	/**
	 * @param array $state
	 * @return array
	 */
	private function mark_seen_if_done( $state ) {
		if ( 'done' === $state['status'] && ! $state['seen'] ) {
			$state['seen'] = true;
			$this->write_state( $state );
		}

		return $state;
	}

	/**
	 * @return array
	 */
	private function default_state() {
		return array(
			'status'        => 'idle',
			// Every job now starts by counting (a scan-equivalent,
			// read-only pass) before converting anything -- see
			// process_one_batch()'s phase branch. 'converting' is never
			// this default's own value; it only shows up via get_state()'s
			// migration backfill for a transient persisted before this
			// field existed (see get_state()'s own docblock).
			'phase'         => 'counting',
			// Populated from each batch's own run_job_batch() result while
			// counting, so the client can render a "% (dir_index /
			// total_dirs)" progress bar the same way the old standalone
			// Scan did -- not knowable ahead of time the way it was when
			// Scan tracked it purely client-side.
			'total_dirs'    => 0,
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
			'total_missing' => 0,
			'current_dir'   => '',
			'cache_cleared' => false,
			'started_at'    => null,
			'finished_at'   => null,
			'seen'          => false,
		);
	}

	/**
	 * @param array $state
	 */
	private function write_state( array $state ) {
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );
	}

	/**
	 * Ensures a due cron event exists, then kicks WP core's own
	 * non-blocking loopback so it fires right away rather than waiting on
	 * organic site traffic. Gracefully does nothing extra if
	 * DISABLE_WP_CRON is set or a spawn is already in progress -- the job
	 * still completes either way, just at organic-traffic pace instead of
	 * immediately in that case.
	 */
	private function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		spawn_cron();
	}
}
