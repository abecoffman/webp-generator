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

	/**
	 * @var WWG_Admin
	 */
	private $admin;

	/**
	 * @param WWG_Admin $admin Supplies run_job_batch() during cron ticks
	 *                         and get_strings() for the notice/heartbeat
	 *                         copy.
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
				$state                  = $this->default_state();
				$state['status']        = 'running';
				$state['total_missing'] = isset( $_POST['total_missing'] ) ? absint( $_POST['total_missing'] ) : 0;
				$state['started_at']    = time();
			}

			$this->write_state( $state );
			$this->ensure_scheduled();
		}

		wp_send_json_success( $state );
	}

	/**
	 * The cron callback. No capability/nonce check -- cron dispatches run
	 * with no current user; authorization already happened when the job
	 * was scheduled from handle_start_job().
	 */
	public function run_tick() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			// Cancelled (or otherwise no longer running) between this
			// event being scheduled and now -- do no work, and don't
			// reschedule; whatever cancelled it already cleared the
			// schedule itself.
			delete_transient( self::LOCK_KEY );
			return;
		}

		$result = $this->admin->run_job_batch( $state['cursor']['dir_index'], $state['cursor']['file_offset'], 'convert' );

		$state['cursor']      = array(
			'dir_index'   => $result['dir_index'],
			'file_offset' => $result['file_offset'],
		);
		$state['current_dir'] = $result['dir'];

		foreach ( array( 'scanned', 'missing', 'converted', 'failed', 'original_bytes', 'webp_bytes' ) as $key ) {
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

		if ( $result['done'] ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			$state['seen']        = false;

			if ( $state['stats']['converted'] > 0 ) {
				WWG_Cache::clear_all();
				$state['cache_cleared'] = true;
			}

			$this->write_state( $state );
			delete_transient( self::LOCK_KEY );
			return;
		}

		$this->write_state( $state );
		delete_transient( self::LOCK_KEY );
		$this->ensure_scheduled();
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

		$state = $this->get_state();

		if ( 'running' === $state['status'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			delete_transient( self::LOCK_KEY );
			$state['status'] = 'paused';
			$this->write_state( $state );
		}

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

		return array_merge( $this->default_state(), $state );
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
