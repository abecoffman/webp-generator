<?php
/**
 * Admin UI: scan the existing media library for images missing a .webp
 * sibling, report on the work before doing it, then run the conversion
 * with live progress. Complements WWG_Generator, which only handles new
 * uploads going forward.
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Tools > WebP Generator admin page and its AJAX endpoint.
 */
class WWG_Admin {

	const AJAX_ACTION              = 'wwg_process_batch';
	const ACTION_SAVE_SCAN         = 'wwg_save_scan_result';
	const ACTION_CLASSIFY_FAILURES = 'wwg_classify_failures';
	const ACTION_FIX_FAILURE       = 'wwg_fix_failure';
	const ACTION_DELETE_FAILURE    = 'wwg_delete_failure';
	const NONCE_ACTION             = 'wwg_admin';
	const SETTINGS_NONCE           = 'wwg_settings';
	const HTACCESS_NONCE           = 'wwg_htaccess';
	const CAPABILITY               = 'manage_options';
	const PAGE_SLUG                = 'webp-generator';

	/**
	 * How many files list_images_in_dir() results process_batch() looks
	 * at per call, for both scan and convert -- large, since checking
	 * whether a file already has a .webp sibling (file_exists()) is
	 * cheap regardless of mode; only actually converting a missing file
	 * is expensive (see MAX_CONVERSIONS_PER_BATCH for the cap on that).
	 * A larger shared window also means a large folder's full directory
	 * listing (list_images_in_dir() re-lists a folder's entire contents
	 * on every call regardless of how much of it that call actually
	 * uses) gets redundantly redone across fewer batches.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 2000;

	/**
	 * Convert mode stops attempting further real conversions once it's
	 * done this many within one batch -- files after that point are
	 * simply deferred to the next batch/tick, not skipped. Decoding/
	 * re-encoding a full image is the one genuinely expensive per-file
	 * cost in this class, so this (not BATCH_SIZE) is what keeps a
	 * single request safely inside PHP's max_execution_time even on a
	 * slow shared host converting a folder that turns out to need a lot
	 * of real work.
	 *
	 * @var int
	 */
	const MAX_CONVERSIONS_PER_BATCH = 40;

	/**
	 * Option holding a dir_rel => value map of folders already confirmed
	 * to have nothing further to do, as of that value's mtime. The value
	 * is either a bare mtime int (the common case: zero images missing a
	 * .webp sibling), or -- see OPTION_KNOWN_FAILURES -- an
	 * {mtime, known_failures} array when the only missing images are
	 * known, already-confirmed-stable failures. Shared between scan and
	 * convert -- "this folder has nothing [further] missing" is the same
	 * fact regardless of which mode discovered it. Either mode can now
	 * single-batch-verify (and so cache) a folder of any size on its own,
	 * as long as it doesn't need more than MAX_CONVERSIONS_PER_BATCH real
	 * conversions in that one pass -- an already-clean folder is equally
	 * cheap to verify in either mode. A plain option (not a transient)
	 * since this is a durable learned fact, not a short-lived cache -- it
	 * self-invalidates via filemtime() (see is_known_clean()) rather than
	 * an arbitrary TTL. autoload=false: only read during an actual Scan/
	 * Generate run, never on a normal admin page load.
	 *
	 * @var string
	 */
	const OPTION_CLEAN_DIRS = 'wwg_clean_dirs';

	/**
	 * Option holding a file_rel => {size, mtime, error} map of individual
	 * files known to permanently fail conversion (e.g. a 0-byte file, or
	 * one with corrupt image data). Convert mode consults this to skip
	 * straight to reporting the remembered failure instead of re-running
	 * an expensive, doomed Imagick/GD decode attempt every single run;
	 * scan mode consults the same record too (it never attempts a real
	 * decode either way) so a known failure is reported identically no
	 * matter which tool notices it first, rather than showing up as an
	 * undifferentiated "missing" count only Convert knows is a lost cause.
	 * Self-invalidates the same way OPTION_CLEAN_DIRS does: if the file's
	 * size/mtime ever change, it's stale and gets a real re-attempt (see
	 * is_known_failure()).
	 *
	 * A folder isn't excluded from OPTION_CLEAN_DIRS just because it
	 * contains a known failure -- once a failure has been reconfirmed
	 * stable (survived at least one full pass via the shortcut above, not
	 * just failed once), OPTION_CLEAN_DIRS can cache the whole folder
	 * "clean except these known failures". That cache entry still
	 * carries the specific known-failure file_rels it depends on, so
	 * is_known_clean() can cheaply re-validate each of them (a stat, not
	 * a decode) before trusting the folder-level skip -- the directory's
	 * own mtime alone isn't enough, since an in-place edit to one of
	 * those files typically doesn't touch it. See is_known_clean() and
	 * process_batch()'s $became_clean for the full mechanics.
	 *
	 * autoload=false, same reasoning as OPTION_CLEAN_DIRS.
	 *
	 * @var string
	 */
	const OPTION_KNOWN_FAILURES = 'wwg_known_failures';

	/**
	 * Option holding a snapshot of Scan's last completed pass -- what the
	 * Tools > WebP Generator page's "Library Status" region renders,
	 * including on a plain page load, the same way WWG_Job's own
	 * persisted state already lets Generate's results survive a reload.
	 * Deliberately lighter than WWG_Job's state: Scan has no cron leg and
	 * is now fast enough (thanks to OPTION_CLEAN_DIRS/OPTION_KNOWN_FAILURES)
	 * that a resumable in-progress state isn't needed -- this only ever
	 * holds the result of a scan that actually finished.
	 *
	 * Shape: {missing, original_bytes, failures[], finished_at,
	 * invalidated_at}. Self-invalidates on the specific event that makes
	 * it wrong (a Generate run that actually converts something -- see
	 * mark_scan_state_stale(), called from WWG_Job) rather than a bare
	 * TTL, so it never silently goes stale while still claiming to be
	 * current; invalidation keeps the old numbers rather than deleting
	 * them, so the UI can explain *why* it reset instead of showing a
	 * generic first-run message. autoload=false, same reasoning as
	 * OPTION_CLEAN_DIRS.
	 *
	 * @var string
	 */
	const OPTION_SCAN_STATE = 'wwg_scan_state';

	/**
	 * @var WWG_Generator
	 */
	private $generator;

	/**
	 * Set via set_job() rather than the constructor -- WWG_Job itself
	 * depends on this class (it calls run_job_batch() during cron ticks),
	 * so the two can't both take each other as constructor arguments.
	 * wwg_init() wires this immediately after constructing both.
	 *
	 * @var WWG_Job
	 */
	private $job;

	/**
	 * @param WWG_Generator $generator Shared conversion logic.
	 */
	public function __construct( WWG_Generator $generator ) {
		$this->generator = $generator;
	}

	/**
	 * @param WWG_Job $job Background-job state/scheduling, for the Tools
	 *                     menu bubble and the page-load state hydration.
	 */
	public function set_job( WWG_Job $job ) {
		$this->job = $job;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_' . self::ACTION_SAVE_SCAN, array( $this, 'handle_save_scan_result' ) );
		add_action( 'wp_ajax_' . self::ACTION_CLASSIFY_FAILURES, array( $this, 'handle_classify_failures' ) );
		add_action( 'wp_ajax_' . self::ACTION_FIX_FAILURE, array( $this, 'handle_fix_failure' ) );
		add_action( 'wp_ajax_' . self::ACTION_DELETE_FAILURE, array( $this, 'handle_delete_failure' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_htaccess_action' ) );
	}

	/**
	 * Handle the small settings form at the top of Tools > WebP Generator
	 * (currently just quality). A plain POST-and-redirect rather than the
	 * full Settings API, since it's one field on a Tools page rather than
	 * a proper Settings page.
	 */
	public function maybe_save_settings() {
		if ( ! isset( $_POST['wwg_save_settings'] ) ) {
			return;
		}

		check_admin_referer( self::SETTINGS_NONCE );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['wwg_quality'] ) ) {
			$quality = max( 1, min( 100, absint( $_POST['wwg_quality'] ) ) );
			update_option( WWG_Generator::OPTION_QUALITY, $quality );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the "Add this rule to my .htaccess" / "Remove it" buttons on
	 * the Setup & status panel. Same plain POST-and-redirect pattern as
	 * maybe_save_settings().
	 */
	public function maybe_handle_htaccess_action() {
		$action = isset( $_POST['wwg_htaccess_action'] ) ? sanitize_key( $_POST['wwg_htaccess_action'] ) : '';
		if ( ! in_array( $action, array( 'install', 'remove' ), true ) ) {
			return;
		}

		check_admin_referer( self::HTACCESS_NONCE );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$success = ( 'install' === $action ) ? WWG_Htaccess::install() : WWG_Htaccess::remove();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'wwg-htaccess' => $success ? $action . '-success' : $action . '-failed',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Add the Tools submenu page. The menu title (but not the page's own
	 * <h1>) gets an unseen-completion count bubble appended, same
	 * component WP core uses for its own Plugins-update/Comments-pending
	 * counts -- cleared the moment the admin visits this page.
	 */
	public function register_page() {
		add_management_page(
			__( 'WebP Generator', 'webp-generator' ),
			__( 'WebP Generator', 'webp-generator' ) . $this->job->get_bubble_html(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_bubble_html() returns fixed, hardcoded markup with no dynamic data in it (just a literal "1"), nothing here to escape.
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wwg-admin',
			plugins_url( 'assets/admin.css', WWG_FILE ),
			array(),
			WWG_VERSION
		);

		wp_enqueue_script(
			'wwg-admin',
			plugins_url( 'assets/admin.js', WWG_FILE ),
			array(),
			WWG_VERSION,
			true
		);

		wp_localize_script(
			'wwg-admin',
			'wwgAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'action'         => self::AJAX_ACTION,
				'scanSaveAction' => self::ACTION_SAVE_SCAN,
				'classifyAction' => self::ACTION_CLASSIFY_FAILURES,
				'fixAction'      => self::ACTION_FIX_FAILURE,
				'deleteAction'   => self::ACTION_DELETE_FAILURE,
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'jobState'       => $this->job->get_hydrated_state(),
				'scanState'      => $this->get_scan_state(),
				'jobActions'     => array(
					'start'  => WWG_Job::ACTION_START,
					'status' => WWG_Job::ACTION_STATUS,
					'cancel' => WWG_Job::ACTION_CANCEL,
					'drive'  => WWG_Job::ACTION_DRIVE,
				),
				'strings'        => self::get_strings(),
			)
		);
	}

	/**
	 * Every string admin.js templates client-side (via a plain
	 * '%d'/'%s'.replace()), plus the ones WWG_Job::maybe_render_notice()
	 * and the Heartbeat payload reuse verbatim for the completion notice
	 * -- one copy, so the in-page summary and the ambient notification
	 * can never drift out of sync with each other.
	 *
	 * @return array<string,string>
	 */
	public static function get_strings() {
		return array(
			'confirmGenerate'              => __( 'Generate .webp versions of these images now? This writes new files alongside the originals -- nothing existing gets deleted or replaced.', 'webp-generator' ),
			// Static label for Region 1's status chip in its idle/done
			// substates -- while actively scanning, admin.js swaps the
			// chip to scanningLabel below instead.
			'libraryStatusLabel'           => __( 'Library status', 'webp-generator' ),
			'scanningLabel'                => __( 'Scanning…', 'webp-generator' ),
			'generatingLabel'              => __( 'Generating…', 'webp-generator' ),
			'pausedLabel'                  => __( 'Paused', 'webp-generator' ),
			/* translators: %s: date and time the run finished, e.g. "Aug 10, 2026, 5:46 PM" -- formatted client-side in the visitor's own locale/timezone. Region 3's status chip once a Generate run is done. */
			'lastRunLabel'                 => __( 'Last run — %s', 'webp-generator' ),
			// Region 1's headline before any scan has ever completed, or
			// once a completed scan has been invalidated back to unknown
			// (see 'notCheckedYetFirstTime'/'notCheckedYetInvalidated' for
			// which meta line pairs with this).
			'notCheckedYet'                => __( 'Not checked yet.', 'webp-generator' ),
			'notCheckedYetFirstTime'       => __( 'Click Scan to see how many images need a .webp version.', 'webp-generator' ),
			// Shown instead of the above once a Generate run has actually
			// changed the library since the last scan (see
			// WWG_Admin::mark_scan_state_stale(), called from WWG_Job) --
			// explains *why* Library Status reset instead of leaving the
			// admin to wonder if a scan they remember running got lost.
			'notCheckedYetInvalidated'     => __( 'You generated images since the last check -- click Scan to see what’s left.', 'webp-generator' ),
			/* translators: %s: folder path currently being scanned, e.g. "2024/03". Substituted client-side in admin.js. */
			'checking'                     => __( 'Checking %s…', 'webp-generator' ),
			/* translators: %s: folder path currently being processed, e.g. "2024/03". Substituted client-side in admin.js. */
			'converting'                   => __( 'Generating %s…', 'webp-generator' ),
			/* translators: %s: folder path currently being walked, e.g. "2024/03". Substituted client-side in admin.js. Shown once every missing image Scan found has already been processed -- the run keeps walking the rest of the library to catch anything Scan might have missed, but isn't converting anything new, so this deliberately doesn't say "Generating" like the string above. */
			'stillScanning'                => __( 'All missing images found -- finishing folder scan (%s)…', 'webp-generator' ),
			'uploadsRoot'                  => __( 'the uploads folder', 'webp-generator' ),
			/* translators: %d: number of images generated so far. */
			'generatedSoFar'               => __( '%d images generated so far…', 'webp-generator' ),
			/* translators: %d: number of images generated. Shown once every missing image Scan found has been processed -- unlike 'generatedSoFar' above, this is the final count for this run (only the "finishing folder scan" walk is left, which won't change it further), so it deliberately doesn't say "so far". */
			'generatedFinal'               => __( '%d image(s) generated.', 'webp-generator' ),
			'missingNone'                  => __( 'Every image already has a .webp version.', 'webp-generator' ),
			/* translators: 1: number of images (always > 1), 2: combined file size, e.g. "3.2 MB". */
			'missingPlural'                => __( '%1$d images are missing a .webp version (%2$s).', 'webp-generator' ),
			/* translators: 1: combined file size, e.g. "420 KB". */
			'missingSingular'              => __( '1 image is missing a .webp version (%2$s).', 'webp-generator' ),
			// Appended after missingSingular/missingPlural above, only when
			// some (or all) of that same missing count is already known --
			// from an earlier run -- to permanently fail. Deliberately its
			// own "Of these, N..." sentence rather than reusing
			// failedSummary below: failedSummary's "%d failed" describes an
			// attempt that just happened (Convert/Generate just ran), which
			// isn't true here (Scan never attempts a conversion) -- and
			// unlike Convert's generated/failed counts, which are disjoint,
			// this count is a *subset* of the missing count in the sentence
			// right before it, so it needs to read as "of those, some are
			// already known-dead" rather than a second, seemingly separate
			// number.
			/* translators: %d: how many of the missing images above (always > 1) already have a known, permanent failure reason on record. */
			'missingKnownFailuresPlural'   => __( 'Of these, %d are already known to permanently fail -- see "Failed conversions" for details.', 'webp-generator' ),
			// Used instead of the above when that count is exactly 1 -- its
			// own string (not a %d substitution) to avoid "Of these, 1 are…".
			'missingKnownFailuresSingular' => __( 'Of these, 1 is already known to permanently fail -- see "Failed conversions" for details.', 'webp-generator' ),
			/* translators: %s: date and time Scan's last completed pass finished, e.g. "Aug 10, 2026, 5:46 PM" -- formatted client-side. Region 1's meta line once a valid scan result exists. */
			'asOf'                         => __( 'As of %s.', 'webp-generator' ),
			// Full "Done -- generated N, M failed" phrasing for contexts
			// with no other supporting UI around them -- the completion
			// notice (WWG_Job::maybe_render_notice()) and the Heartbeat
			// live-update (admin-heartbeat.js). The tool page itself does
			// NOT use this for its own summary -- see 'generatedFinal'/
			// 'failedSummary' above for what it uses instead.
			/* translators: %d: number of images. */
			'generateDone'                 => __( 'Done -- generated %d image(s).', 'webp-generator' ),
			/* translators: %d: number of images that failed to convert. Deliberately doesn't say "below"/"above" -- this string is reused in more than one place on the page relative to the "Failed conversions" panel it points at, so a directional reference goes stale wherever it ends up on the wrong side. */
			'failedSummary'                => __( '%d failed -- see "Failed conversions" for details.', 'webp-generator' ),
			// "...so these take effect right away" is accurate here
			// specifically because this string's only other use (the
			// completion notice/Heartbeat update) is always seen fresh --
			// it's shown once, right after the run, then dismissed/marked
			// seen. Do NOT reuse this on the tool page's own persisted
			// summary (see 'cacheClearedPast' below for that) -- that
			// state survives indefinitely across reloads, where "right
			// away" would misleadingly imply the run just happened.
			'cacheCleared'                 => __( 'Also cleared the page cache so these take effect right away.', 'webp-generator' ),
			// Same underlying fact as 'cacheCleared' above, worded so it
			// reads correctly no matter how long ago the run actually
			// finished -- used in Region 3's done-state summary, which
			// (unlike the notice) is exactly the "possibly reading this
			// days later" context 'cacheCleared' isn't safe for.
			'cacheClearedPast'             => __( 'The page cache was also cleared as part of that run.', 'webp-generator' ),
			'resumeGenerating'             => __( 'Resume Generating', 'webp-generator' ),
			'paused'                       => __( 'Paused. Click "Resume Generating" to pick up where this left off.', 'webp-generator' ),
			'vsOriginal'                   => __( 'vs.', 'webp-generator' ),
			'folders'                      => __( 'folders', 'webp-generator' ),
			'images'                       => __( 'images', 'webp-generator' ),
			'viewResults'                  => __( 'View results →', 'webp-generator' ),
			'error'                        => __( 'Something went wrong:', 'webp-generator' ),

			// Per-row "next best action" on a Failed conversions entry --
			// see WWG_Attachment_Resolver/WWG_Admin::classify_failure() for
			// how a file ends up in one of these buckets.
			'fixThisFile'                  => __( 'Fix this file', 'webp-generator' ),
			'fixing'                       => __( 'Fixing…', 'webp-generator' ),
			'deleteInstead'                => __( 'Delete instead', 'webp-generator' ),
			'deleteThisFile'               => __( 'Delete this file', 'webp-generator' ),
			'deleting'                     => __( 'Deleting…', 'webp-generator' ),
			'viewInMediaLibrary'           => __( 'View in Media Library →', 'webp-generator' ),
			'confirmDeleteDerivative'      => __( 'Delete this file instead of fixing it? This permanently removes the broken image size. Nothing will recreate it automatically unless you click Generate again.', 'webp-generator' ),
			'confirmDeleteOnly'            => __( 'Permanently delete this corrupted file? It can’t be recovered afterward.', 'webp-generator' ),
			'confirmDeleteOriginal'        => __( 'This is the original image, not a generated size — deleting it removes the entire attachment (the original and every generated size) permanently, and can’t be undone. Delete it anyway?', 'webp-generator' ),
			'originalNote'                 => __( 'This is the original image, not a generated size.', 'webp-generator' ),
			'fixedMessage'                 => __( 'Fixed — regenerated from the original.', 'webp-generator' ),
			'deletedMessage'               => __( 'Deleted.', 'webp-generator' ),
			'actionFailedMessage'          => __( 'That didn’t work. Check your server’s error log, or try again.', 'webp-generator' ),
		);
	}

	/**
	 * Render the page shell -- the setup/status panel and settings form
	 * render server-side here; Scan does its work via AJAX driven from
	 * assets/admin.js, and Generate observes a WP-Cron background job
	 * (see WWG_Job) rather than driving it directly -- either way, the
	 * live UI lives below this in includes/views/admin-page.php.
	 */
	public function render_page() {
		$readiness = array(
			'has_webp_support'   => $this->generator->has_webp_support(),
			'quality'            => $this->generator->get_quality(),
			'can_auto_install'   => WWG_Htaccess::can_auto_install(),
			'htaccess_installed' => WWG_Htaccess::is_installed(),
			'htaccess_path'      => WWG_Htaccess::get_path(),
			'server_type'        => WWG_Htaccess::detect_server(),
			'server_doc_link'    => WWG_Htaccess::get_server_doc_link(),
		);

		require WWG_PATH . 'includes/views/admin-page.php';
	}

	/**
	 * AJAX handler for the scan pass only -- Generate/convert runs as a
	 * WP-Cron background job now (see WWG_Job::run_tick(), which reaches
	 * the same process_batch() through run_job_batch() below), not
	 * through this endpoint. Scan stays client-driven: it's just
	 * file_exists() calls, fast and bounded enough that babysitting a tab
	 * for it was never the problem this plugin needed to solve.
	 *
	 * Processes one bounded batch of files from one folder under the
	 * uploads directory and reports back a cursor to resume from, so the
	 * client can loop this until done without any single request risking
	 * a timeout.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$dir_index   = isset( $_POST['dir_index'] ) ? absint( $_POST['dir_index'] ) : 0;
		$file_offset = isset( $_POST['file_offset'] ) ? absint( $_POST['file_offset'] ) : 0;

		$dirs   = $this->get_scan_directories();
		$result = $this->process_batch( $dirs, $dir_index, $file_offset, 'scan' );

		wp_send_json_success(
			array(
				'done'        => $result['done'],
				'dir'         => $result['dir'],
				'dir_index'   => $result['dir_index'],
				'file_offset' => $result['file_offset'],
				'total_dirs'  => count( $dirs ),
				'stats'       => $result['stats'],
			)
		);
	}

	/**
	 * AJAX: persist the final tally from a completed client-driven Scan
	 * pass, so the tool page's "Library Status" region can survive a
	 * reload the same way Generate's own results already do (see
	 * OPTION_SCAN_STATE). Fired once by admin.js's finishScan(), not
	 * per-batch -- entirely separate from handle_ajax()/process_batch()'s
	 * per-batch request/response shape above, which this doesn't touch.
	 */
	public function handle_save_scan_result() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$failures = array();
		if ( isset( $_POST['failures'] ) ) {
			// wp_unslash() first -- WordPress adds slashes to all $_POST
			// data, and json_decode() on a slashed string silently fails
			// on any value containing a quote (exactly what a real decode
			// error message is often full of).
			$decoded = json_decode( wp_unslash( $_POST['failures'] ), true );
			if ( is_array( $decoded ) ) {
				// Capped the same way WWG_Job's own accumulated failures
				// are -- this is a client-reported list, not to be trusted
				// for length any more than for content.
				foreach ( array_slice( $decoded, -500 ) as $failure ) {
					if ( isset( $failure['file'], $failure['error'] ) && is_string( $failure['file'] ) && is_string( $failure['error'] ) ) {
						$failures[] = array(
							'file'  => sanitize_text_field( $failure['file'] ),
							'error' => sanitize_text_field( $failure['error'] ),
						);
					}
				}
			}
		}

		$state = array(
			'missing'        => isset( $_POST['missing'] ) ? absint( $_POST['missing'] ) : 0,
			'original_bytes' => isset( $_POST['original_bytes'] ) ? absint( $_POST['original_bytes'] ) : 0,
			'failures'       => $failures,
			'finished_at'    => time(), // Server clock -- never trust a client-sent timestamp.
			'invalidated_at' => null,   // A scan that just finished is, by definition, not stale.
		);

		update_option( self::OPTION_SCAN_STATE, $state, false );

		wp_send_json_success( $state );
	}

	/**
	 * The persisted "Library Status" snapshot -- Scan's last completed
	 * pass, read fresh on every page load so the tool page can hydrate
	 * Region 1 the same way WWG_Job::get_hydrated_state() already lets it
	 * hydrate Generate's own results. See OPTION_SCAN_STATE's docblock
	 * for the shape and self-invalidation rationale.
	 *
	 * @return array
	 */
	private function get_scan_state() {
		$defaults = array(
			'missing'        => 0,
			'original_bytes' => 0,
			'failures'       => array(),
			'finished_at'    => 0,
			'invalidated_at' => null,
		);

		$state = get_option( self::OPTION_SCAN_STATE, array() );

		return is_array( $state ) ? array_merge( $defaults, $state ) : $defaults;
	}

	/**
	 * Marks the persisted Library Status snapshot stale. Called by
	 * WWG_Job the moment a Generate run actually changes the library --
	 * the same instant it already decides whether to clear the page
	 * cache -- so Region 1 stops claiming to know something that's now
	 * provably wrong, rather than silently lying until an arbitrary TTL
	 * expires. Keeps the old numbers rather than deleting them, so the
	 * tool page can explain *why* it reset instead of showing a generic
	 * first-run message (see get_scan_state()'s 'invalidated_at').
	 */
	public function mark_scan_state_stale() {
		$state = get_option( self::OPTION_SCAN_STATE, array() );
		if ( ! is_array( $state ) || empty( $state['finished_at'] ) ) {
			return; // Never scanned -- nothing to invalidate.
		}

		$state['invalidated_at'] = time();
		update_option( self::OPTION_SCAN_STATE, $state, false );
	}

	/**
	 * @param string $file_rel Relative path under uploads.
	 * @return string Absolute path.
	 */
	private function abs_path_for( $file_rel ) {
		$upload_dir = wp_get_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . $file_rel;
	}

	/**
	 * AJAX: classify a batch of currently-listed failures so the tool page
	 * can show the right next-best-action per row (Fix, Delete, or a plain
	 * explanation) *before* the admin clicks anything -- getting this
	 * right up front matters most for the riskiest case (a corrupt
	 * original), where the row needs to show the stronger delete warning
	 * from the start, not discover that only after a wrong click.
	 */
	public function handle_classify_failures() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$classifications = array();

		if ( isset( $_POST['files'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['files'] ), true );
			if ( is_array( $decoded ) ) {
				// Capped the same way every other client-reported list in
				// this class is -- not to be trusted for length any more
				// than for content.
				foreach ( array_slice( $decoded, 0, 500 ) as $file_rel ) {
					if ( is_string( $file_rel ) && '' !== $file_rel ) {
						$classifications[ $file_rel ] = $this->classify_failure( $file_rel );
					}
				}
			}
		}

		wp_send_json_success( $classifications );
	}

	/**
	 * AJAX: regenerate one failed file from its attachment's healthy
	 * original. See classify_failure()/fix_failure() for the full
	 * decision tree and mechanics.
	 */
	public function handle_fix_failure() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$file_rel = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' === $file_rel ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong:', 'webp-generator' ) ), 400 );
		}

		// The filter is consulted (and enforced) inside classify_failure()
		// already, and fix_failure() itself refuses to proceed unless
		// classify_failure() confirms 'fix_or_delete' -- a filtered-off
		// site is naturally covered by that same check, not a separate
		// one here.
		wp_send_json_success( $this->fix_failure( $file_rel ) );
	}

	/**
	 * AJAX: delete one failed file -- either just the broken derivative,
	 * or (if it turns out to be the original) the entire attachment. Which
	 * one happens is always re-derived server-side from classify_failure(),
	 * never taken from a client-sent flag, given how consequential getting
	 * this wrong would be. Unlike fix_failure(), delete_failure_file()/
	 * delete_original_attachment() don't re-check classify_failure()
	 * themselves (there's no equivalent "is this actually a fix" fact for
	 * them to re-derive), so the filter is enforced explicitly here.
	 */
	public function handle_delete_failure() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$file_rel = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' === $file_rel ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong:', 'webp-generator' ) ), 400 );
		}

		$classification = $this->classify_failure( $file_rel );

		if ( 'none' === $classification['action'] ) {
			wp_send_json_success( $this->generic_action_failure( $file_rel ) );
			return;
		}

		if ( 'delete_original' === $classification['action'] ) {
			wp_send_json_success( $this->delete_original_attachment( $file_rel ) );
		} else {
			wp_send_json_success( $this->delete_failure_file( $file_rel ) );
		}
	}

	/**
	 * Works out what can actually be done about one failed file: fix it
	 * (a regenerable derivative with a healthy original), delete it (a
	 * derivative that can't be regenerated, or a file WordPress has no
	 * record of at all), or delete the whole attachment (the file itself
	 * is the original). Cheap -- one resolver lookup plus a couple of
	 * file_exists()/metadata checks -- safe to call on every row render,
	 * not just when a button is actually clicked, since a fresh answer
	 * matters more here than caching a possibly-stale one.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {
	 *     @type string $action   One of 'fix_or_delete', 'delete_only',
	 *                            'delete_original', or 'none' (no action
	 *                            offered at all -- see the
	 *                            wwg_allow_failure_actions filter).
	 *     @type string $reason   Human-readable explanation. Present for
	 *                            'delete_only' only.
	 *     @type string $edit_url The attachment's edit-screen URL.
	 *                            Present whenever an attachment was
	 *                            resolved at all.
	 * }
	 */
	private function classify_failure( $file_rel ) {
		/**
		 * Whether the "Fix this file" / "Delete this file" row actions on
		 * a Failed conversions entry are offered at all. Return false to
		 * disable entirely -- e.g. for a site that would rather these
		 * files were always handled by hand. Doesn't affect anything
		 * else about how failures are found or reported, only whether an
		 * action is offered for them.
		 *
		 * @param bool   $allow    Whether to allow it. Default true.
		 * @param string $file_rel The relative file path being classified.
		 */
		if ( ! apply_filters( 'wwg_allow_failure_actions', true, $file_rel ) ) {
			return array( 'action' => 'none' );
		}

		$resolved = WWG_Attachment_Resolver::resolve( $file_rel );

		if ( false === $resolved ) {
			return array(
				'action' => 'delete_only',
				'reason' => __( 'No Media Library item found for this file — probably added outside WordPress, or already removed.', 'webp-generator' ),
			);
		}

		$edit_url = (string) get_edit_post_link( $resolved['attachment_id'], 'raw' );

		if ( $resolved['is_original'] ) {
			return array(
				'action'   => 'delete_original',
				'edit_url' => $edit_url,
			);
		}

		$original_path = $resolved['original_path'];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the original can legitimately be missing or unreadable; that's exactly the condition being checked for here.
		if ( ! $original_path || ! file_exists( $original_path ) || 0 === (int) @filesize( $original_path ) ) {
			return array(
				'action'   => 'delete_only',
				'reason'   => __( 'The original image for this file is also missing or broken, so it can’t be regenerated.', 'webp-generator' ),
				'edit_url' => $edit_url,
			);
		}

		return array(
			'action'   => 'fix_or_delete',
			'edit_url' => $edit_url,
		);
	}

	/**
	 * Regenerate one failed file from its attachment's healthy original,
	 * then convert the fresh result to .webp. Never touches anything if
	 * classify_failure() doesn't confirm this file is actually regenerable.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {outcome, file, message}
	 */
	private function fix_failure( $file_rel ) {
		$abs_path = $this->abs_path_for( $file_rel );

		// Re-verify server-side this file is actually still broken --
		// never trust a possibly-stale client click for something this
		// consequential. If it's already fine, there's nothing to fix,
		// just stale bookkeeping to clear.
		$recheck = $this->generator->ensure_webp( $abs_path );
		if ( in_array( $recheck['status'], array( 'exists', 'created' ), true ) ) {
			$this->clear_failure_record( $file_rel, true );
			return array(
				'outcome' => 'fixed',
				'file'    => $file_rel,
				'message' => __( 'This file was already fixed.', 'webp-generator' ),
			);
		}

		$classification = $this->classify_failure( $file_rel );
		if ( 'fix_or_delete' !== $classification['action'] ) {
			return array(
				'outcome'  => 'not_regenerable',
				'file'     => $file_rel,
				'message'  => isset( $classification['reason'] ) ? $classification['reason'] : __( 'That didn’t work. Check your server’s error log, or try again.', 'webp-generator' ),
				'edit_url' => isset( $classification['edit_url'] ) ? $classification['edit_url'] : '',
			);
		}

		$resolved = WWG_Attachment_Resolver::resolve( $file_rel );
		if ( false === $resolved || $resolved['is_original'] ) {
			// Shouldn't happen -- classify_failure() just confirmed this
			// is a regenerable derivative -- but never trust a resolve()
			// race blindly for something that writes a file.
			return $this->generic_action_failure( $file_rel );
		}

		$metadata  = wp_get_attachment_metadata( $resolved['attachment_id'] );
		$size_data = isset( $metadata['sizes'][ $resolved['size_name'] ] ) ? $metadata['sizes'][ $resolved['size_name'] ] : null;
		if ( ! $size_data ) {
			return $this->generic_action_failure( $file_rel );
		}

		$width  = (int) $size_data['width'];
		$height = (int) $size_data['height'];
		$crop   = $this->crop_setting_for( $resolved['size_name'] );

		$result = image_make_intermediate_size( $resolved['original_path'], $width, $height, $crop );
		if ( ! $result ) {
			return $this->generic_action_failure( $file_rel );
		}

		// Only write metadata back if the regenerated file actually
		// differs from what's already recorded -- the common case is an
		// identical filename/dimensions, nothing to update.
		if ( ! isset( $size_data['file'] ) || $result['file'] !== $size_data['file']
			|| (int) $size_data['width'] !== (int) $result['width']
			|| (int) $size_data['height'] !== (int) $result['height']
		) {
			$metadata['sizes'][ $resolved['size_name'] ] = $result;
			wp_update_attachment_metadata( $resolved['attachment_id'], $metadata );
		}

		// image_make_intermediate_size() only produces the JPEG/PNG --
		// this plugin's whole point is the .webp sibling, which still
		// needs generating for the freshly-written file.
		$webp_outcome = $this->generator->ensure_webp( $abs_path );
		if ( ! in_array( $webp_outcome['status'], array( 'created', 'exists' ), true ) ) {
			return array(
				'outcome' => 'failed',
				'file'    => $file_rel,
				'message' => __( 'The image itself was regenerated, but creating its .webp version still failed. Check your server’s error log, or try again.', 'webp-generator' ),
			);
		}

		$this->clear_failure_record( $file_rel, true );

		return array(
			'outcome' => 'fixed',
			'file'    => $file_rel,
			'message' => __( 'Fixed — regenerated from the original.', 'webp-generator' ),
		);
	}

	/**
	 * Delete one broken derivative file (or a fully unresolvable one) --
	 * never the original; see delete_original_attachment() for that.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {outcome, file, message}
	 */
	private function delete_failure_file( $file_rel ) {
		$abs_path = $this->abs_path_for( $file_rel );

		if ( file_exists( $abs_path ) ) {
			wp_delete_file( $abs_path );
		}

		// If this resolved to a real attachment size, strip it from the
		// recorded metadata too -- otherwise WordPress keeps returning a
		// URL for a file that now 404s.
		$resolved = WWG_Attachment_Resolver::resolve( $file_rel );
		if ( false === $resolved ) {
			$resolved = null; // Already gone from disk -- resolve() naturally can't confirm it now; nothing more to clean up on the WordPress side.
		}
		if ( $resolved && ! $resolved['is_original'] && $resolved['size_name'] ) {
			$metadata = wp_get_attachment_metadata( $resolved['attachment_id'] );
			if ( isset( $metadata['sizes'][ $resolved['size_name'] ] ) ) {
				unset( $metadata['sizes'][ $resolved['size_name'] ] );
				wp_update_attachment_metadata( $resolved['attachment_id'], $metadata );
			}
		}

		$this->clear_failure_record( $file_rel, false );

		return array(
			'outcome' => 'deleted',
			'file'    => $file_rel,
			'message' => __( 'Deleted.', 'webp-generator' ),
		);
	}

	/**
	 * Delete the entire attachment a corrupt *original* file belongs to
	 * -- the file itself, every generated size, its post/postmeta, and
	 * (automatically, via WWG_Generator::delete_siblings() already
	 * hooking `delete_attachment`) any .webp siblings it has.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {outcome, file, message}
	 */
	private function delete_original_attachment( $file_rel ) {
		$resolved = WWG_Attachment_Resolver::resolve( $file_rel );
		if ( false === $resolved || ! $resolved['is_original'] ) {
			// Resolved differently now than when this was classified --
			// don't guess, bail rather than delete the wrong thing.
			return array(
				'outcome' => 'stale',
				'file'    => $file_rel,
				'message' => __( 'This file has changed since the page loaded. Reload and try again.', 'webp-generator' ),
			);
		}

		$attachment_id = $resolved['attachment_id'];

		// Sweep every other known failure belonging to this same
		// attachment too (other broken sizes of it, if separately
		// recorded) -- wp_delete_attachment() below is about to remove
		// all of them from disk in one shot regardless.
		$known = get_option( self::OPTION_KNOWN_FAILURES, array() );
		foreach ( array_keys( $known ) as $other_file_rel ) {
			if ( $other_file_rel === $file_rel ) {
				continue;
			}
			$other = WWG_Attachment_Resolver::resolve( $other_file_rel );
			if ( false !== $other && (int) $other['attachment_id'] === (int) $attachment_id ) {
				$this->clear_failure_record( $other_file_rel, false );
			}
		}

		$deleted = wp_delete_attachment( $attachment_id, true );
		if ( ! $deleted ) {
			return $this->generic_action_failure( $file_rel );
		}

		$this->clear_failure_record( $file_rel, false );

		return array(
			'outcome' => 'deleted',
			'file'    => $file_rel,
			'message' => __( 'Deleted.', 'webp-generator' ),
		);
	}

	/**
	 * Common bookkeeping once a failure stops being one -- whether fixed
	 * (regenerated) or deleted. Keeps OPTION_KNOWN_FAILURES, the
	 * persisted Library Status snapshot, and WWG_Job's own state all in
	 * sync without requiring a fresh Scan/Generate run to reflect it.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @param bool   $fixed    True if regenerated (counts as a fresh
	 *                         conversion); false if deleted (counts as
	 *                         removed, not converted).
	 */
	private function clear_failure_record( $file_rel, $fixed ) {
		$this->forget_failure( $file_rel );
		$this->remove_failure_from_scan_state( $file_rel, $fixed );
		if ( $this->job ) {
			$this->job->remove_failure_from_state( $file_rel, $fixed );
		}
	}

	/**
	 * @param string $file_rel Relative path.
	 * @param bool   $fixed    See clear_failure_record().
	 */
	private function remove_failure_from_scan_state( $file_rel, $fixed ) {
		$state = get_option( self::OPTION_SCAN_STATE, array() );
		if ( ! is_array( $state ) || empty( $state['failures'] ) ) {
			return;
		}

		$before            = count( $state['failures'] );
		$state['failures'] = array_values(
			array_filter(
				$state['failures'],
				static function ( $failure ) use ( $file_rel ) {
					return ! isset( $failure['file'] ) || $failure['file'] !== $file_rel;
				}
			)
		);

		if ( count( $state['failures'] ) === $before ) {
			return; // Wasn't listed here -- nothing to adjust.
		}

		// A fix doesn't change how many images Scan found missing a
		// .webp -- it just stops being one of the ones that will never
		// get one. A delete does: the file's gone, so it can't be
		// "missing" anymore either.
		if ( ! $fixed ) {
			$state['missing'] = max( 0, (int) $state['missing'] - 1 );
		}

		update_option( self::OPTION_SCAN_STATE, $state, false );
	}

	/**
	 * @param string $size_name A registered image size name.
	 * @return bool Best-effort crop setting for regenerating this size --
	 *              affects framing only, never whether the regeneration
	 *              itself succeeds.
	 */
	private function crop_setting_for( $size_name ) {
		if ( 'thumbnail' === $size_name ) {
			return (bool) get_option( 'thumbnail_crop' );
		}
		if ( in_array( $size_name, array( 'medium', 'medium_large', 'large' ), true ) ) {
			return false;
		}

		$additional = wp_get_additional_image_sizes();
		return isset( $additional[ $size_name ]['crop'] ) ? (bool) $additional[ $size_name ]['crop'] : false;
	}

	/**
	 * @param string $file_rel Relative path under uploads.
	 * @return array A generic {outcome: 'failed', ...} response -- WP
	 *               core's own image functions used here (
	 *               image_make_intermediate_size(), wp_delete_attachment())
	 *               don't surface a specific reason on failure.
	 */
	private function generic_action_failure( $file_rel ) {
		return array(
			'outcome' => 'failed',
			'file'    => $file_rel,
			'message' => __( 'That didn’t work. Check your server’s error log, or try again.', 'webp-generator' ),
		);
	}

	/**
	 * The one entry point WWG_Job::run_tick() uses to do real work, each
	 * cron tick. A thin wrapper so get_scan_directories()/process_batch()
	 * can stay private (and stay reachable by tests/unit/
	 * AdminBatchingTest.php's existing Reflection pattern) while still
	 * being usable from a different class.
	 *
	 * @param int    $dir_index   Index into get_scan_directories() to
	 *                            resume at.
	 * @param int    $file_offset Offset into that folder's file list.
	 * @param string $mode        'scan' or 'convert'.
	 * @return array process_batch()'s result, plus total_dirs.
	 */
	public function run_job_batch( $dir_index, $file_offset, $mode ) {
		$dirs = $this->get_scan_directories();

		return $this->process_batch( $dirs, $dir_index, $file_offset, $mode ) + array(
			'total_dirs' => count( $dirs ),
		);
	}

	/**
	 * Every folder under the uploads directory, at any depth, plus the
	 * uploads directory itself (represented as ''). Deliberately makes no
	 * assumption about WordPress's default "YYYY/MM" layout -- that's a
	 * per-site setting (Settings > Media > "Organize my uploads into
	 * month- and year-based folders") that can be off, leaving uploads
	 * flat, and other plugins routinely create their own subfolders under
	 * uploads/ regardless of that setting. A plain recursive walk covers
	 * all of it without hardcoding any site's particular structure.
	 *
	 * Cached briefly since it's recomputed on every AJAX step.
	 *
	 * @return string[] Relative paths (e.g. "2019/03", "2019", or ""
	 *                   for the uploads root itself), sorted.
	 */
	private function get_scan_directories() {
		$cache_key = 'wwg_scan_dirs';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$upload_dir = wp_get_upload_dir();
		$base       = untrailingslashit( $upload_dir['basedir'] );
		$dirs       = array( '' );

		if ( is_dir( $base ) ) {
			$flags    = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS;
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, $flags ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			// Depth cap as a safety net against runaway/circular directory
			// structures (e.g. a symlink loop) on servers where that isn't
			// otherwise guarded against -- 10 levels is far deeper than
			// any real uploads layout needs.
			$iterator->setMaxDepth( 10 );

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isDir() || $file_info->isLink() ) {
					continue;
				}
				$dirs[] = ltrim( str_replace( $base, '', $file_info->getPathname() ), '/' );
			}
		}

		/**
		 * Filter the list of uploads subfolders (relative paths) the
		 * scan/convert tools walk. Return false-y from a callback keyed
		 * on the path to exclude a folder, e.g. to skip a large archive
		 * of images a site never wants converted.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $dirs Relative folder paths, '' meaning the
		 *                       uploads root itself.
		 */
		$dirs = apply_filters( 'wwg_scan_directories', $dirs );

		sort( $dirs );
		set_transient( $cache_key, $dirs, 15 * MINUTE_IN_SECONDS );

		return $dirs;
	}

	/**
	 * Sorted list of .jpg/.jpeg/.png filenames directly inside one folder
	 * (non-recursive -- get_scan_directories() already enumerated every
	 * folder in the tree, so each call here only needs its own files).
	 *
	 * @param string $abs_dir Absolute directory path.
	 * @return string[] Filenames.
	 */
	private function list_images_in_dir( $abs_dir ) {
		if ( ! is_dir( $abs_dir ) ) {
			return array();
		}

		$files = array();
		foreach ( new DirectoryIterator( $abs_dir ) as $file_info ) {
			if ( $file_info->isDot() || ! $file_info->isFile() ) {
				continue;
			}
			if ( preg_match( '/\.(jpe?g|png)$/i', $file_info->getFilename() ) ) {
				$files[] = $file_info->getFilename();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Whether $dir_rel was already confirmed to have nothing further to
	 * do -- either zero images missing a .webp sibling, or the only ones
	 * that are missing are known, still-stable failures (see
	 * is_known_failure()) -- and nothing invalidating has happened since.
	 * Cheap in the common "never cached" case -- a single get_option()
	 * array lookup, no filesystem stat at all.
	 *
	 * @param string $dir_rel Relative folder path -- key into the cache.
	 * @param string $abs_dir Absolute folder path, stat'd only if there's
	 *                        a cache entry to validate.
	 * @return array|false False if not cached, or since invalidated.
	 *                      Otherwise the (possibly empty) list of
	 *                      known-failure file_rels this folder's clean
	 *                      status depends on -- the caller still needs to
	 *                      report those every run, just without re-
	 *                      walking the rest of the folder to find them.
	 */
	private function is_known_clean( $dir_rel, $abs_dir ) {
		$clean_dirs = get_option( self::OPTION_CLEAN_DIRS, array() );

		if ( ! isset( $clean_dirs[ $dir_rel ] ) ) {
			return false;
		}

		// The folder may have vanished entirely since being cached --
		// treat that like "not cached" (a plain recheck finds it empty
		// and moves on), not a filemtime() warning on a gone path.
		if ( ! is_dir( $abs_dir ) ) {
			return false;
		}

		$entry = $clean_dirs[ $dir_rel ];
		// Entries from before known-failures could be cached alongside a
		// folder are a bare mtime int (there was never anything else to
		// store) -- tolerate reading that shape indefinitely rather than
		// forcing a one-time migration.
		$mtime          = is_array( $entry ) ? $entry['mtime'] : $entry;
		$known_failures = is_array( $entry ) && ! empty( $entry['known_failures'] ) ? $entry['known_failures'] : array();

		if ( filemtime( $abs_dir ) !== $mtime ) {
			return false;
		}

		// The directory's own mtime only catches files being added,
		// removed, or renamed -- an in-place edit to one of the known-
		// failure files this folder's clean status depends on typically
		// won't touch it. Cheaply (one stat per known failure, not a walk
		// of the whole folder) re-validate each one before trusting the
		// skip below, so a since-fixed file can never be masked forever
		// just because the rest of the folder never changed.
		foreach ( $known_failures as $file_rel ) {
			if ( false === $this->is_known_failure( $file_rel, $abs_dir . '/' . basename( $file_rel ) ) ) {
				return false;
			}
		}

		return $known_failures;
	}

	/**
	 * Record $dir_rel as clean (optionally "clean except these known,
	 * already-confirmed-stable failures") as of its current mtime. Call
	 * only after a single process_batch() call has just verified every
	 * file in the folder in one pass -- see process_batch()'s
	 * $became_clean check.
	 *
	 * @param string   $dir_rel             Relative folder path.
	 * @param string   $abs_dir             Absolute folder path.
	 * @param string[] $known_failure_rels  file_rels of known failures
	 *                                      (already reconfirmed stable
	 *                                      this same pass, not freshly
	 *                                      discovered) this folder's
	 *                                      clean status depends on.
	 */
	private function mark_known_clean( $dir_rel, $abs_dir, array $known_failure_rels = array() ) {
		if ( ! is_dir( $abs_dir ) ) {
			// Vanished between the file listing and here (rare race) --
			// nothing meaningful to fingerprint.
			return;
		}

		$mtime = filemtime( $abs_dir );
		if ( false === $mtime ) {
			return;
		}

		$clean_dirs = get_option( self::OPTION_CLEAN_DIRS, array() );
		// Keep storing the plain mtime int for the common (zero known
		// failures) case -- smaller, and identical to every entry written
		// before this feature existed; only step up to the richer shape
		// when there's actually something extra to remember.
		$clean_dirs[ $dir_rel ] = $known_failure_rels
			? array(
				'mtime'          => $mtime,
				'known_failures' => array_values( $known_failure_rels ),
			)
			: $mtime;
		update_option( self::OPTION_CLEAN_DIRS, $clean_dirs, false );
	}

	/**
	 * Whether $file_rel was already confirmed to fail conversion, and its
	 * size/mtime haven't changed since -- mirrors is_known_clean()'s
	 * self-invalidation approach at file granularity instead of folder.
	 *
	 * @param string $file_rel    Relative path -- key into the cache.
	 * @param string $source_path Absolute path, stat'd only if there's a
	 *                            cache entry to validate.
	 * @return array|false The remembered {size, mtime, error} entry if the
	 *                      fingerprint still matches, false otherwise.
	 */
	private function is_known_failure( $file_rel, $source_path ) {
		$known = get_option( self::OPTION_KNOWN_FAILURES, array() );

		if ( ! isset( $known[ $file_rel ] ) || ! file_exists( $source_path ) ) {
			return false;
		}

		$entry = $known[ $file_rel ];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish/become unreadable between the directory listing and here; (int) cast already turns a false return into a harmless 0, which simply won't match a real stored fingerprint.
		if ( (int) @filesize( $source_path ) !== $entry['size'] || (int) @filemtime( $source_path ) !== $entry['mtime'] ) {
			return false; // Fingerprint changed -- something touched the file, so re-attempt for real.
		}

		return $entry;
	}

	/**
	 * Record $file_rel as failing, fingerprinted to its current size/mtime
	 * so a later real change to the file is detected and re-attempted.
	 *
	 * @param string $file_rel    Relative path.
	 * @param string $source_path Absolute path.
	 * @param string $error       The failure reason to remember and
	 *                            re-report on future skipped attempts.
	 */
	private function remember_failure( $file_rel, $source_path, $error ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see is_known_failure() above.
		$mtime = (int) @filemtime( $source_path );
		if ( ! $mtime ) {
			return; // Vanished/unreadable -- nothing meaningful to fingerprint.
		}

		$known              = get_option( self::OPTION_KNOWN_FAILURES, array() );
		$known[ $file_rel ] = array(
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see is_known_failure() above.
			'size'  => (int) @filesize( $source_path ),
			'mtime' => $mtime,
			'error' => $error,
		);
		update_option( self::OPTION_KNOWN_FAILURES, $known, false );
	}

	/**
	 * Stop remembering $file_rel as a failure -- called once it's known to
	 * no longer be one (a fresh success, including a recovered one, or a
	 * .webp appearing for it through some other means).
	 *
	 * @param string $file_rel Relative path.
	 */
	private function forget_failure( $file_rel ) {
		$known = get_option( self::OPTION_KNOWN_FAILURES, array() );
		if ( isset( $known[ $file_rel ] ) ) {
			unset( $known[ $file_rel ] );
			update_option( self::OPTION_KNOWN_FAILURES, $known, false );
		}
	}

	/**
	 * Process one bounded slice of files starting at ($dir_index,
	 * $file_offset), advancing into the next folder once the current one
	 * is exhausted.
	 *
	 * @param string[] $dirs        Result of get_scan_directories().
	 * @param int      $dir_index   Index into $dirs to resume at.
	 * @param int      $file_offset Offset into that folder's file list.
	 * @param string   $mode        'scan' or 'convert'.
	 * @return array
	 */
	private function process_batch( $dirs, $dir_index, $file_offset, $mode ) {
		$stats = array(
			'scanned'        => 0,
			'missing'        => 0,
			'converted'      => 0,
			'failed'         => 0,
			'original_bytes' => 0,
			'webp_bytes'     => 0,
			'failures'       => array(),
			'recoveries'     => array(),
		);

		if ( $dir_index >= count( $dirs ) ) {
			return array(
				'stats'       => $stats,
				'dir'         => '',
				'dir_index'   => $dir_index,
				'file_offset' => 0,
				'done'        => true,
				'skipped'     => false,
			);
		}

		$dir_rel    = $dirs[ $dir_index ];
		$upload_dir = wp_get_upload_dir();
		$abs_dir    = '' === $dir_rel
			? $upload_dir['basedir']
			: trailingslashit( $upload_dir['basedir'] ) . $dir_rel;

		$cached_known_failures = $this->is_known_clean( $dir_rel, $abs_dir );
		if ( false !== $cached_known_failures ) {
			// The folder itself isn't walked -- that's the whole point --
			// but any known failures it depends on must still be reported
			// every run, same as if we'd found them the slow way (see
			// is_known_failure()'s docblock: nothing should silently drop
			// out of view). is_known_clean() already cheaply re-validated
			// each of these still matches its remembered fingerprint.
			foreach ( $cached_known_failures as $file_rel ) {
				$entry = $this->is_known_failure( $file_rel, $abs_dir . '/' . basename( $file_rel ) );
				if ( false === $entry ) {
					continue; // Shouldn't happen -- is_known_clean() just checked this -- but never trust a stat() race blindly.
				}
				++$stats['missing'];
				++$stats['failed'];
				$stats['original_bytes'] += $entry['size'];
				$stats['failures'][]      = array(
					'file'  => $file_rel,
					'error' => $entry['error'],
				);
			}

			return array(
				'stats'       => $stats,
				'dir'         => $dir_rel,
				'dir_index'   => $dir_index + 1,
				'file_offset' => 0,
				'done'        => false,
				'skipped'     => true,
			);
		}

		$files = $this->list_images_in_dir( $abs_dir );
		$slice = array_slice( $files, $file_offset, self::BATCH_SIZE );

		// Counts real (expensive) ensure_webp() calls this batch only --
		// deliberately separate from $stats['missing'], since a
		// known-failure shortcut hit below reports as missing/failed too
		// but does no real decode work, so it must not eat into the cap
		// that exists specifically to bound that expensive work.
		$real_attempts = 0;

		// file_rels of known failures reconfirmed via the shortcut below
		// this same pass -- as opposed to ones failing for the first time
		// this pass, which $became_clean below must not yet trust.
		$stable_known_failures = array();

		foreach ( $slice as $filename ) {
			$source_path = $abs_dir . '/' . $filename;
			$file_rel    = '' === $dir_rel ? $filename : $dir_rel . '/' . $filename;
			$webp_path   = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );

			// Belt-and-suspenders, both modes: a .webp may have appeared
			// some other way (manual upload, another tool) since a
			// failure was last remembered for this file -- checked before
			// the known-failure shortcut so a stale record can never mask
			// a file that's already actually fine, regardless of which
			// mode happens to notice first.
			if ( file_exists( $webp_path ) ) {
				++$stats['scanned'];
				$this->forget_failure( $file_rel );
				continue;
			}

			// Known-failure shortcut, both modes: cheap (a stat, not a
			// decode), and reported identically in either mode's stats --
			// a permanent failure doesn't become less permanent because
			// Scan found it instead of Generate.
			$known_failure = $this->is_known_failure( $file_rel, $source_path );
			if ( false !== $known_failure ) {
				++$stats['scanned'];
				++$stats['missing'];
				++$stats['failed'];
				$stats['original_bytes'] += $known_failure['size'];
				$stats['failures'][]      = array(
					'file'  => $file_rel,
					'error' => $known_failure['error'],
				);
				$stable_known_failures[]  = $file_rel;
				continue; // No real decode attempt -- doesn't touch $real_attempts/the cap below.
			}

			if ( 'convert' === $mode ) {
				// Only the real-conversion path is expensive -- once this
				// batch has attempted MAX_CONVERSIONS_PER_BATCH of them,
				// stop and leave the rest of the slice for the next call
				// (not counted as scanned, so resumption starts exactly
				// here). Already-exists files and known-failure shortcuts
				// above never trip this, so a folder that's mostly or
				// fully already resolved sails through the whole (much
				// larger) window in one pass regardless of size.
				if ( $real_attempts >= self::MAX_CONVERSIONS_PER_BATCH ) {
					break;
				}
				++$real_attempts;
				++$stats['scanned'];

				$outcome = $this->generator->ensure_webp( $source_path );

				if ( 'exists' === $outcome['status'] ) {
					// Shouldn't normally happen -- the shared file_exists()
					// check above just confirmed no .webp -- but a race
					// (something else creating it in between) is possible;
					// treat it the same as that check would have.
					$this->forget_failure( $file_rel );
					continue;
				}

				++$stats['missing'];
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish or become unreadable between the directory listing above and this stat() call; (int) cast already turns a false return into a harmless 0.
				$stats['original_bytes'] += (int) @filesize( $source_path );

				if ( 'created' === $outcome['status'] ) {
					++$stats['converted'];
					$stats['webp_bytes'] += $outcome['webp_bytes'];
					$this->forget_failure( $file_rel ); // Covers recovered successes too.
					if ( ! empty( $outcome['recovered'] ) ) {
						$stats['recoveries'][] = array( 'file' => $file_rel );
					}
				} else {
					++$stats['failed'];
					$error               = isset( $outcome['error'] ) ? $outcome['error'] : '';
					$stats['failures'][] = array(
						'file'  => $file_rel,
						'error' => $error,
					);
					$this->remember_failure( $file_rel, $source_path, $error );
				}
			} else {
				++$stats['scanned'];
				++$stats['missing'];
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish or become unreadable between the directory listing above and this stat() call; (int) cast already turns a false return into a harmless 0.
				$stats['original_bytes'] += (int) @filesize( $source_path );
			}
		}

		// $stats['scanned'] (not count( $slice )) -- the loop above may
		// have broken early on the real-conversion cap, in which case
		// fewer files than the full slice were actually looked at, and
		// resuming must start exactly where it left off, not past the
		// file that tripped the cap.
		$new_offset = $file_offset + $stats['scanned'];
		$dir_done   = $new_offset >= count( $files );

		// $file_offset is the untouched parameter -- process_batch()
		// never reassigns it -- so "0 === $file_offset && $dir_done"
		// means precisely "this one call covered the folder's entire
		// file list, start to finish." $stats['missing'] ===
		// $stats['converted'] + count( $stable_known_failures ) is
		// mode-agnostic: the known-failure shortcut (and so
		// $stable_known_failures) is shared by both modes, but only
		// convert mode ever increments $stats['converted'] (scan mode
		// never attempts a real conversion, so it stays 0 there) -- for
		// scan mode this reduces to "every missing file is a known,
		// reconfirmed-stable failure"; for convert mode, missing =
		// converted + failed by construction each batch, so it's
		// equivalent to "every currently-failed file this pass is a known
		// one already reconfirmed stable via the shortcut above, not a
		// fresh first-time failure" -- a folder with any *fresh* failure
		// is correctly never cached on the pass that discovers it (its
		// stability isn't known yet), only from the next pass on, once
		// the shortcut itself (in either mode) has reconfirmed it.
		$became_clean = ( 0 === $file_offset ) && $dir_done
			&& ( $stats['missing'] === $stats['converted'] + count( $stable_known_failures ) );
		if ( $became_clean ) {
			$this->mark_known_clean( $dir_rel, $abs_dir, $stable_known_failures );
		}

		return array(
			'stats'       => $stats,
			'dir'         => $dir_rel,
			'dir_index'   => $dir_done ? $dir_index + 1 : $dir_index,
			'file_offset' => $dir_done ? 0 : $new_offset,
			'done'        => false,
			'skipped'     => false,
		);
	}
}
