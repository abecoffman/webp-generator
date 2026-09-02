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

	const ACTION_CLASSIFY_FAILURES   = 'wwg_classify_failures';
	const ACTION_FIX_FAILURE         = 'wwg_fix_failure';
	const ACTION_DELETE_FAILURE      = 'wwg_delete_failure';
	const ACTION_SAVE_SETTINGS       = 'wwg_save_settings';
	const ACTION_GENERATE_ATTACHMENT = 'wwg_generate_attachment';
	const NONCE_ACTION               = 'wwg_admin';
	const HTACCESS_NONCE             = 'wwg_htaccess';
	const CAPABILITY                 = 'manage_options';
	const PAGE_SLUG                  = 'webp-generator';

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
	 * to have nothing further to do, as of that value's mtime, for a
	 * specific set of enabled formats. The value is either a bare mtime
	 * int (a pre-AVIF entry -- only WebP could have been considered), or
	 * an {mtime, formats, known_failures?, totals?} array -- `formats`
	 * records which enabled format ids were actually checked when this
	 * was cached, so a format gaining server support later (or a
	 * wwg_enabled_formats filter loosening) correctly invalidates a
	 * folder that's still genuinely clean for the formats it already knew
	 * about (see is_known_clean()'s format-staleness check); `known_
	 * failures`, if present, is a list of {file, format} pairs -- see
	 * OPTION_KNOWN_FAILURES -- when the only missing conversions are
	 * known, already-confirmed-stable failures. `totals`, if present, is
	 * this folder's own {scanned, webp_bytes, avif_bytes,
	 * webp_original_bytes, avif_original_bytes} contribution (see
	 * process_batch()'s own $stats docblock for what each of those
	 * means) -- lets a cache HIT still contribute this folder's real
	 * numbers to Library Status's whole-library totals without re-
	 * walking it (see is_known_clean()); an entry with no `totals` key
	 * (written before this existed) is treated as a miss so it gets
	 * backfilled the next time it's checked, same as a `formats`
	 * mismatch already forces a recheck today. Shared between scan and
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
	 * Option holding a file_rel => {size, mtime, formats} map of
	 * individual source files with at least one derived format known to
	 * permanently fail conversion (e.g. a 0-byte file, or one with
	 * corrupt image data) -- formats is a format-id => {error} map (e.g.
	 * {webp: {...}, avif: {...}}, or just one of the two if only one
	 * format is currently failing for this file). Convert mode consults
	 * this to skip straight to reporting the remembered failure instead
	 * of re-running an expensive, doomed Imagick/GD decode attempt every
	 * single run; scan mode consults the same record too (it never
	 * attempts a real decode either way) so a known failure is reported
	 * identically no matter which tool notices it first, rather than
	 * showing up as an undifferentiated "missing" count only Convert
	 * knows is a lost cause. A record written before AVIF existed has no
	 * `formats` key at all -- read forever, not migrated, as {webp:
	 * {error: <the old top-level error>}} (see formats_of()), since
	 * WebP was the only format that could possibly have failed then.
	 * Self-invalidates the same way OPTION_CLEAN_DIRS does: if the file's
	 * size/mtime ever change, every format's record for it is discarded
	 * and it gets a real re-attempt (see is_known_failure()).
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
	 * Shape: {missing, missing_files, original_bytes, total_images,
	 * original_bytes_total, webp_bytes, avif_bytes, webp_original_bytes,
	 * avif_original_bytes, webp_present, avif_present, failures[],
	 * finished_at, invalidated_at}. "missing" counts (file, format)
	 * conversion units still needed (what the progress bar tracks
	 * against); "missing_files" counts distinct files needing at least
	 * one of them (what the headline sentence reports) -- a file needing
	 * both WebP and AVIF counts once in missing_files but twice in
	 * missing. "total_images"/"original_bytes_total"/the four *_bytes
	 * fields/the two *_present counts are the *whole* library's own
	 * totals (every file the counting pass visited or had remembered via
	 * OPTION_CLEAN_DIRS, not just the missing subset) -- what Library
	 * Status's own Original/WebP/AVIF table is built from (image count
	 * and total size per row; *_present is that row's image count,
	 * *_bytes/*_original_bytes its size vs. the Original row's own).
	 * Each failures[] entry is {file, format, error}. Self-invalidates
	 * on the specific event that makes
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
		add_action( 'wp_ajax_' . self::ACTION_CLASSIFY_FAILURES, array( $this, 'handle_classify_failures' ) );
		add_action( 'wp_ajax_' . self::ACTION_FIX_FAILURE, array( $this, 'handle_fix_failure' ) );
		add_action( 'wp_ajax_' . self::ACTION_DELETE_FAILURE, array( $this, 'handle_delete_failure' ) );
		add_action( 'wp_ajax_' . self::ACTION_SAVE_SETTINGS, array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_' . self::ACTION_GENERATE_ATTACHMENT, array( $this, 'handle_generate_attachment' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_htaccess_action' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_compression_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_compression_column' ), 10, 2 );
	}

	/**
	 * AJAX: autosave the Settings card the instant a checkbox is toggled
	 * or a quality value changes -- no Save button, no page reload (see
	 * admin.js's wireSettingsAutosave()). The client always sends the
	 * CURRENT state of every supported format's checkbox/quality
	 * together, not just whatever one field just changed, so this stays
	 * exactly the same logic the old form-POST-and-redirect version of
	 * this always ran: a checkbox's absence from the request still means
	 * "unchecked", never "wasn't part of this particular change".
	 *
	 * @return void Sends a JSON response and exits.
	 */
	public function handle_save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$enabled_before = WWG_Format::enabled();

		if ( isset( $_POST['wwg_quality'] ) ) {
			$quality = max( 1, min( 100, absint( $_POST['wwg_quality'] ) ) );
			update_option( WWG_Generator::OPTION_QUALITY, $quality );
		}
		if ( isset( $_POST['wwg_quality_avif'] ) ) {
			$quality = max( 1, min( 100, absint( $_POST['wwg_quality_avif'] ) ) );
			update_option( WWG_Format::OPTION_QUALITY_AVIF, $quality );
		}

		foreach ( WWG_Format::all() as $id => $def ) {
			if ( ! WWG_Format::has_support( $id ) ) {
				continue; // Never offered a checkbox for this -- nothing on the request to read, and nothing to silently disable.
			}
			// A checkbox is only present in the request at all when
			// checked -- standard HTML semantics, and admin.js's
			// currentSettingsFields() follows that same convention on
			// purpose -- so its absence here genuinely means "unchecked",
			// not "wasn't on the page" (the continue above already ruled
			// that case out).
			update_option( $def['enabled_option'], isset( $_POST[ $def['enabled_option'] ] ) );
		}

		// Library Status's "N images missing" count describes a specific
		// set of formats -- if that set just changed, the old count is
		// now describing something that no longer matches reality, same
		// as when a Generate run itself changes the library (see
		// mark_scan_state_stale()'s own docblock). Told to the client in
		// the response below so Region 1 can reflect it immediately,
		// without the full-page reload the old redirect-based version of
		// this used to get that update for free.
		if ( WWG_Format::enabled() !== $enabled_before ) {
			$this->mark_scan_state_stale();
		}

		wp_send_json_success( array( 'scanState' => $this->get_scan_state() ) );
	}

	/**
	 * Handle the "Add this rule to my .htaccess" / "Remove it" buttons on
	 * the Setup & status panel -- a plain POST-and-redirect, unlike
	 * Settings' own autosave (see handle_save_settings()), since these
	 * are deliberate one-off actions a user clicks, not a value that
	 * changes freely.
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
		// Media Library's list view (screen hook 'upload.php') needs the
		// stylesheet for the "WebP/AVIF" column's .wwg-chip/.wwg-cc-*
		// classes, plus (for anyone who could actually use it) a small
		// standalone script for that column's own "Generate" button --
		// see render_compression_column()'s 'partial' branch and
		// assets/media-library.js. Deliberately its own tiny script, not
		// assets/admin.js -- that file's whole closure is built around
		// the Tools page's job/scan state, none of which exists here.
		if ( 'upload.php' === $hook ) {
			wp_enqueue_style(
				'wwg-admin',
				plugins_url( 'assets/admin.css', WWG_FILE ),
				array(),
				WWG_VERSION
			);

			if ( current_user_can( self::CAPABILITY ) ) {
				wp_enqueue_script(
					'wwg-media-library',
					plugins_url( 'assets/media-library.js', WWG_FILE ),
					array(),
					WWG_VERSION,
					true
				);

				$strings = self::get_strings();
				wp_localize_script(
					'wwg-media-library',
					'wwgMediaLibrary',
					array(
						'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
						'generateAction' => self::ACTION_GENERATE_ATTACHMENT,
						'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
						'strings'        => array(
							'generating' => $strings['generatingLabel'],
							'error'      => $strings['error'],
						),
					)
				);
			}
			return;
		}

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

		$enabled_formats = WWG_Format::enabled();
		$format_labels   = array();
		foreach ( $enabled_formats as $format ) {
			$format_labels[ $format ] = WWG_Format::label( $format );
		}

		wp_localize_script(
			'wwg-admin',
			'wwgAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'classifyAction'     => self::ACTION_CLASSIFY_FAILURES,
				'fixAction'          => self::ACTION_FIX_FAILURE,
				'deleteAction'       => self::ACTION_DELETE_FAILURE,
				'saveSettingsAction' => self::ACTION_SAVE_SETTINGS,
				'nonce'              => wp_create_nonce( self::NONCE_ACTION ),
				'jobState'           => $this->job->get_hydrated_state(),
				'scanState'          => $this->get_scan_state(),
				'jobActions'         => array(
					'start'  => WWG_Job::ACTION_START,
					'status' => WWG_Job::ACTION_STATUS,
					'cancel' => WWG_Job::ACTION_CANCEL,
					'drive'  => WWG_Job::ACTION_DRIVE,
				),
				// Enabled-format ids in priority order, with their display
				// labels -- e.g. {avif: "AVIF", webp: "WebP"} on a server
				// that supports both. admin.js uses this to build both-
				// format-aware copy/stat tiles without hardcoding "WebP"/
				// "AVIF" anywhere client-side.
				'enabledFormats'     => $enabled_formats,
				'formatLabels'       => $format_labels,
				'strings'            => self::get_strings(),
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
		// WWG_Format::PRIORITY is avif-first (best format wins in the
		// rewrite rule); reversed here purely because "WebP and AVIF"
		// reads as natural English where "AVIF and WebP" doesn't -- this
		// ordering has no bearing on anything but these sentences.
		$labels         = array_reverse( wp_list_pluck( WWG_Format::all(), 'label' ) );
		$enabled_labels = array_intersect_key( $labels, array_flip( WWG_Format::enabled() ) );
		// Falls back to "WebP" in the (Scan/Generate-disabled) edge case
		// where nothing is enabled at all -- these strings only ever
		// actually get shown once at least one format is enabled, but
		// must still resolve to *something* rather than an empty phrase.
		if ( empty( $enabled_labels ) ) {
			$enabled_labels = array( WWG_Format::WEBP => WWG_Format::label( WWG_Format::WEBP ) );
		}
		/* translators: the word joining two format names in a sentence, e.g. "WebP _and_ AVIF" -- only used when more than one format is enabled. */
		$and_join = __( 'and', 'webp-generator' );
		/* translators: the word joining two format names in a sentence, e.g. "WebP _or_ AVIF" -- only used when more than one format is enabled. */
		$or_join = __( 'or', 'webp-generator' );
		// "has/generates a WebP AND AVIF version" (every enabled format);
		// "is missing a WebP OR AVIF version" (at least one of them).
		$format_and = implode( ' ' . $and_join . ' ', $enabled_labels );
		$format_or  = implode( ' ' . $or_join . ' ', $enabled_labels );

		return array(
			/* translators: %s: format name(s) that will be generated, e.g. "WebP" or "WebP and AVIF". */
			'confirmGenerate'             => sprintf( __( 'Generate %s versions of these images now? This writes new files alongside the originals -- nothing existing gets deleted or replaced.', 'webp-generator' ), $format_and ),
			'scanningLabel'               => __( 'Scanning…', 'webp-generator' ),
			// The progress bar's own label, for the sliver of time after
			// clicking Generate before the very first real batch has come
			// back at all -- either the brief window before wwg_start_job
			// itself has even resolved (no jobState from the server yet),
			// or the one tick just after it where jobState exists but its
			// total_dirs is still the fresh job's own default of 0. Without
			// this, that stretch would show a bare, contentless "0% (0 / 0
			// folders)" -- technically accurate, but reads like nothing is
			// happening (or worse, that it already finished with nothing to
			// do) rather than "still counting up how big this job is".
			'startingLabel'               => __( 'Starting…', 'webp-generator' ),
			'generatingLabel'             => __( 'Generating…', 'webp-generator' ),
			'pausedLabel'                 => __( 'Paused', 'webp-generator' ),
			/* translators: %s: date and time the library was last counted, e.g. "Aug 10, 2026, 5:46 PM" -- formatted client-side in the visitor's own locale/timezone. Shown as a small badge in the Library Status table's own header, next to the row-label column, once a count has completed. */
			'asOfLabel'                   => __( 'As of %s', 'webp-generator' ),
			// Region 1's headline before any scan has ever completed, or
			// once a completed scan has been invalidated back to unknown
			// (see 'notCheckedYetFirstTime'/'notCheckedYetInvalidated' for
			// which meta line pairs with this). No standalone Scan button
			// exists anymore -- Generate's own first phase counts the
			// library itself (see WWG_Job's counting/converting phases),
			// so both point at clicking Generate instead.
			'notCheckedYet'               => __( 'Not checked yet.', 'webp-generator' ),
			/* translators: %s: format name(s), e.g. "WebP" or "WebP or AVIF". */
			'notCheckedYetFirstTime'      => sprintf( __( 'Click Generate to see how many images need a %s version.', 'webp-generator' ), $format_or ),
			// Shown instead of the above once a Generate run has actually
			// changed the library since the last count (see
			// WWG_Admin::mark_scan_state_stale(), called from WWG_Job) --
			// explains *why* Library Status reset instead of leaving the
			// admin to wonder if a count they remember happening got lost.
			'notCheckedYetInvalidated'    => __( 'You generated images since the last check -- click Generate to see what’s left.', 'webp-generator' ),
			// All three of these are appended directly onto the shared
			// progress bar's own "N% (X / Y folders/images)" line (see
			// renderStatusScanning()/renderResultsRunning() in admin.js),
			// never shown as a separate sentence/line of their own -- that
			// used to put multiple redundant statements of "how far along
			// is this" on screen at once (the progress bar, a folder line,
			// and a "so far" summary). Deliberately short clause
			// fragments, not full sentences, and deliberately don't repeat
			// the count they're appended after.
			/* translators: %s: folder path currently being counted, e.g. "2024/03". */
			'checkingFolder'              => __( 'currently checking folder "%s"', 'webp-generator' ),
			/* translators: %s: folder path currently being processed, e.g. "2024/03". */
			'converting'                  => __( 'currently on folder "%s"', 'webp-generator' ),
			// Same clause-fragment shape as the others here -- shown once
			// every missing image Scan found has already been processed
			// and the run is just walking the rest of the library to
			// catch anything Scan might have missed (not converting
			// anything new), so this deliberately says "folder scan", not
			// "on folder", like the string above.
			/* translators: %s: folder path currently being walked, e.g. "2024/03". */
			'stillScanning'               => __( 'finishing folder scan on "%s"', 'webp-generator' ),
			/* translators: %d: number of images generated. Shown once every missing image Scan found has been processed (only the "finishing folder scan" walk, which won't change this further, may still be running) -- while real conversion work is still in progress, this line is deliberately left empty instead of repeating a running tally the progress line above already shows. */
			'generatedFinal'              => __( '%d image(s) generated.', 'webp-generator' ),
			// Full "Done -- generated N, M failed" phrasing for contexts
			// with no other supporting UI around them -- the completion
			// notice (WWG_Job::maybe_render_notice()) and the Heartbeat
			// live-update (admin-heartbeat.js). The tool page itself does
			// NOT use this for its own summary -- see 'generatedFinal'/
			// 'failedSummary' above for what it uses instead.
			/* translators: %d: number of images. */
			'generateDone'                => __( 'Done -- generated %d image(s).', 'webp-generator' ),
			/* translators: %d: number of images that failed to convert. Deliberately doesn't say "below"/"above" -- this string is reused in more than one place on the page relative to the "Failed conversions" panel it points at, so a directional reference goes stale wherever it ends up on the wrong side. */
			'failedSummary'               => __( '%d failed -- see "Failed conversions" for details.', 'webp-generator' ),
			// Last Run's own compact "%d failed" clause -- failedSummary
			// above stays generic for the completion notice/Heartbeat
			// (see their own docblocks), but on the tool page itself
			// "still missing a WebP/AVIF version" (naming which format(s),
			// same $format_or pattern as 'notCheckedYetFirstTime') reads
			// more plainly than "failed" for someone just skimming a
			// receipt. Still points at "Failed conversions", which now
			// lives in the Library Status card right above Last Run.
			/* translators: 1: number of images (always > 1) still missing a converted version after this run, 2: format name(s), e.g. "WebP or AVIF". */
			'stillMissingSummaryPlural'   => sprintf( __( '%%1$d still missing a %s version -- see "Failed conversions" above for details.', 'webp-generator' ), $format_or ),
			/* translators: %s: format name(s), e.g. "WebP or AVIF". */
			'stillMissingSummarySingular' => sprintf( __( '1 still missing a %s version -- see "Failed conversions" above for details.', 'webp-generator' ), $format_or ),
			// "...so these take effect right away" is accurate here
			// specifically because this string's only other use (the
			// completion notice/Heartbeat update) is always seen fresh --
			// it's shown once, right after the run, then dismissed/marked
			// seen. Do NOT reuse this on the tool page's own persisted
			// summary (see 'cacheClearedPast' below for that) -- that
			// state survives indefinitely across reloads, where "right
			// away" would misleadingly imply the run just happened.
			'cacheCleared'                => __( 'Also cleared the page cache so these take effect right away.', 'webp-generator' ),
			// Same underlying fact as 'cacheCleared' above, worded so it
			// reads correctly no matter how long ago the run actually
			// finished -- used in Region 3's done-state summary, which
			// (unlike the notice) is exactly the "possibly reading this
			// days later" context 'cacheCleared' isn't safe for.
			'cacheClearedPast'            => __( 'The page cache was also cleared as part of that run.', 'webp-generator' ),
			'resumeGenerating'            => __( 'Resume Generating', 'webp-generator' ),
			'paused'                      => __( 'Paused. Click "Resume Generating" to pick up where this left off.', 'webp-generator' ),
			'folders'                     => __( 'folders', 'webp-generator' ),
			'images'                      => __( 'images', 'webp-generator' ),
			'viewResults'                 => __( 'View results →', 'webp-generator' ),
			'error'                       => __( 'Something went wrong:', 'webp-generator' ),

			// Settings card: shown next to the format rows while
			// wireSettingsAutosave() (admin.js) saves a checkbox/quality
			// change -- there's no Save button anymore, so this is the
			// only feedback that anything happened at all.
			'savingSettings'              => __( 'Saving…', 'webp-generator' ),
			'settingsSaved'               => __( 'Saved.', 'webp-generator' ),
			'settingsSaveFailed'          => __( "Couldn't save. Try again.", 'webp-generator' ),

			// Per-row "next best action" on a Failed conversions entry --
			// see WWG_Attachment_Resolver/WWG_Admin::classify_failure() for
			// how a file ends up in one of these buckets.
			'fixThisFile'                 => __( 'Fix this file', 'webp-generator' ),
			'fixing'                      => __( 'Fixing…', 'webp-generator' ),
			'deleteInstead'               => __( 'Delete instead', 'webp-generator' ),
			'deleteThisFile'              => __( 'Delete this file', 'webp-generator' ),
			'deleting'                    => __( 'Deleting…', 'webp-generator' ),
			'viewInMediaLibrary'          => __( 'View in Media Library →', 'webp-generator' ),
			'confirmDeleteDerivative'     => __( 'Delete this file instead of fixing it? This permanently removes the broken image size. Nothing will recreate it automatically unless you click Generate again.', 'webp-generator' ),
			'confirmDeleteOnly'           => __( 'Permanently delete this corrupted file? It can’t be recovered afterward.', 'webp-generator' ),
			'confirmDeleteOriginal'       => __( 'This is the original image, not a generated size — deleting it removes the entire attachment (the original and every generated size) permanently, and can’t be undone. Delete it anyway?', 'webp-generator' ),
			'originalNote'                => __( 'This is the original image, not a generated size.', 'webp-generator' ),
			'fixedMessage'                => __( 'Fixed — regenerated from the original.', 'webp-generator' ),
			'deletedMessage'              => __( 'Deleted.', 'webp-generator' ),
			'actionFailedMessage'         => __( 'That didn’t work. Check your server’s error log, or try again.', 'webp-generator' ),
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
		$enabled = WWG_Format::enabled();

		$readiness = array(
			'formats'             => array(),
			'can_auto_install'    => WWG_Htaccess::can_auto_install(),
			'htaccess_installed'  => WWG_Htaccess::is_installed(),
			// Only meaningful once htaccess_installed is true -- see
			// WWG_Htaccess::is_up_to_date()'s own docblock for why a
			// perfectly valid, working rule can still go stale (a host
			// gaining AVIF support after the rule was installed).
			'htaccess_up_to_date' => WWG_Htaccess::is_up_to_date(),
			'htaccess_path'       => WWG_Htaccess::get_path(),
			'server_type'         => WWG_Htaccess::detect_server(),
			'server_doc_link'     => WWG_Htaccess::get_server_doc_link(),
		);

		foreach ( WWG_Format::all() as $id => $def ) {
			$readiness['formats'][ $id ] = array(
				'id'              => $id,
				'label'           => $def['label'],
				'supported'       => WWG_Format::has_support( $id ),
				'enabled'         => in_array( $id, $enabled, true ),
				// Distinct from 'enabled' above once a Settings checkbox
				// exists: 'enabled' is the final answer after every gate
				// (server support, the site owner's own choice, AND a
				// developer's wwg_enabled_formats filter); this is just
				// the site owner's own checkbox, in isolation. The two
				// can disagree -- a supported, checked-on format the dev
				// filter is still overriding -- and the view needs to
				// tell that case apart from "the owner just unchecked
				// it" to know whether the "turned off by a customization"
				// notice still applies.
				'user_wants'      => WWG_Format::user_wants( $id ),
				'enabled_option'  => $def['enabled_option'],
				'quality'         => $this->generator->get_quality( $id ),
				'option_name'     => $def['quality_option'],
				// Not necessarily the same across formats -- see
				// WWG_Format::DEFAULT_QUALITY_AVIF's own docblock for why
				// -- so the Settings field below can't hardcode one
				// number for both.
				'default_quality' => $def['default_quality'],
				'quality_guide'   => $this->quality_guide_for( $id ),
			);
		}

		require WWG_PATH . 'includes/views/admin-page.php';
	}

	/**
	 * Per-format "for this kind of image, use this range" guidance shown
	 * under the Settings quality slider -- not this plugin's own
	 * invention: synthesized from published quality-mapping research
	 * (e.g. a direct JPEG/AVIF/WebP equivalence comparison at
	 * https://www.industrialempathy.com/posts/avif-webp-quality-settings/,
	 * and corroborating ranges from general web-performance guidance)
	 * rather than picked arbitrarily. Deliberately lives here, not in
	 * WWG_Format -- that class is scoped to technical per-format facts
	 * (extension, mime, encoder identifiers, quality option), not UI copy.
	 *
	 * AVIF's tiers run a few points higher than WebP's at the top end
	 * (matching the two formats' own already-different defaults, see
	 * WWG_Format::DEFAULT_QUALITY_AVIF's docblock) because the two
	 * formats aren't on a perceptually equivalent scale at the same
	 * number. The lower two tiers are kept identical across formats: at
	 * low quality settings the gap between formats matters far less than
	 * at the top end, where most of a site's real photography actually
	 * lives.
	 *
	 * @param string $format_id One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return array[] {label, range} pairs, low quality to high.
	 */
	private function quality_guide_for( $format_id ) {
		$top_tier_range = WWG_Format::AVIF === $format_id ? '80-90' : '75-90';

		return array(
			array(
				'label' => __( 'Background or decorative images', 'webp-generator' ),
				'range' => '50-60',
			),
			array(
				'label' => __( 'Thumbnails and previews', 'webp-generator' ),
				'range' => '60-70',
			),
			array(
				'label' => __( 'Website photography, including hero/feature images', 'webp-generator' ),
				'range' => $top_tier_range,
			),
		);
	}

	/**
	 * Persists a completed counting pass's tally as the "Library Status"
	 * snapshot, so Region 1 survives a reload the same way Generate's own
	 * results already do (see OPTION_SCAN_STATE). Called directly by
	 * WWG_Job::process_one_batch() the moment its own counting phase
	 * finishes walking the tree -- an in-process call, not AJAX, since
	 * that phase already runs server-side as part of the same Generate
	 * job (see run_job_batch()'s 'scan' mode). $stats is trusted input
	 * here (process_batch()'s own accumulated output), unlike the old
	 * client-driven Scan flow this replaces, which had to sanitize
	 * everything as untrusted $_POST.
	 *
	 * @param array $stats {
	 *     @type int   $missing              Missing (file, format) pairs.
	 *     @type int   $missing_files        Distinct files missing something.
	 *     @type int   $original_bytes       Total bytes of files missing something.
	 *     @type int   $scanned              Whole-library image count (every
	 *                                       file this counting pass visited
	 *                                       or had remembered via
	 *                                       OPTION_CLEAN_DIRS) -- persisted
	 *                                       as `total_images` below.
	 *     @type int   $original_bytes_total Whole-library original size,
	 *                                       regardless of which formats
	 *                                       are enabled -- the "Original"
	 *                                       row of Library Status's table.
	 *     @type int   $webp_bytes           Whole-library current .webp size.
	 *     @type int   $avif_bytes           Whole-library current .avif size.
	 *     @type int   $webp_original_bytes  Those same files' original size.
	 *     @type int   $avif_original_bytes  Those same files' original size.
	 *     @type int   $webp_present         How many images currently have
	 *                                       a .webp version.
	 *     @type int   $avif_present         How many images currently have
	 *                                       an .avif version.
	 *     @type array $failures             Known permanent per-file failures.
	 * }
	 * @return array The state actually persisted.
	 */
	public function save_scan_state( array $stats ) {
		$state = array(
			'missing'              => isset( $stats['missing'] ) ? absint( $stats['missing'] ) : 0,
			'missing_files'        => isset( $stats['missing_files'] ) ? absint( $stats['missing_files'] ) : 0,
			'original_bytes'       => isset( $stats['original_bytes'] ) ? absint( $stats['original_bytes'] ) : 0,
			'total_images'         => isset( $stats['scanned'] ) ? absint( $stats['scanned'] ) : 0,
			'original_bytes_total' => isset( $stats['original_bytes_total'] ) ? absint( $stats['original_bytes_total'] ) : 0,
			'webp_bytes'           => isset( $stats['webp_bytes'] ) ? absint( $stats['webp_bytes'] ) : 0,
			'avif_bytes'           => isset( $stats['avif_bytes'] ) ? absint( $stats['avif_bytes'] ) : 0,
			'webp_original_bytes'  => isset( $stats['webp_original_bytes'] ) ? absint( $stats['webp_original_bytes'] ) : 0,
			'avif_original_bytes'  => isset( $stats['avif_original_bytes'] ) ? absint( $stats['avif_original_bytes'] ) : 0,
			'webp_present'         => isset( $stats['webp_present'] ) ? absint( $stats['webp_present'] ) : 0,
			'avif_present'         => isset( $stats['avif_present'] ) ? absint( $stats['avif_present'] ) : 0,
			'failures'             => isset( $stats['failures'] ) && is_array( $stats['failures'] ) ? $stats['failures'] : array(),
			'finished_at'          => time(), // Server clock -- never trust a client-sent timestamp.
			'invalidated_at'       => null,   // A count that just finished is, by definition, not stale.
		);

		update_option( self::OPTION_SCAN_STATE, $state, false );

		return $state;
	}

	/**
	 * The persisted "Library Status" snapshot -- Scan's last completed
	 * pass, read fresh on every page load so the tool page can hydrate
	 * Region 1 the same way WWG_Job::get_hydrated_state() already lets it
	 * hydrate Generate's own results. See OPTION_SCAN_STATE's docblock
	 * for the shape and self-invalidation rationale. Public (not just
	 * used by render_page() below): WWG_Job::process_one_batch() also
	 * reads this directly, as a fallback for a poll that lands on the
	 * job *after* it's already finished via a different process
	 * entirely (WP-Cron's own tick, racing this one) -- see its own
	 * call site for why that fallback matters.
	 *
	 * @return array
	 */
	public function get_scan_state() {
		$defaults = array(
			'missing'              => 0,
			'missing_files'        => 0,
			'original_bytes'       => 0,
			'total_images'         => 0,
			'original_bytes_total' => 0,
			'webp_bytes'           => 0,
			'avif_bytes'           => 0,
			'webp_original_bytes'  => 0,
			'avif_original_bytes'  => 0,
			'webp_present'         => 0,
			'avif_present'         => 0,
			'failures'             => array(),
			'finished_at'          => 0,
			'invalidated_at'       => null,
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
	 * File-level, not per-format: Fix and Delete both act on every
	 * currently-failing enabled format of a file together (fixing
	 * regenerates the shared source derivative once, then re-converts
	 * every format from it; deleting removes every derived file for the
	 * source at once) -- there's no separate "fix just the AVIF one"
	 * action to classify, so callers don't need this to also report
	 * *which* formats are failing -- they already have that straight from
	 * the same failures list that put this file in front of them in the
	 * first place (each entry already carries its own `format`).
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
	 * then convert the fresh result to every currently-enabled derived
	 * format. Never touches anything if classify_failure() doesn't
	 * confirm this file is actually regenerable. Fixes every format
	 * that's still failing in one click -- a corrupt source derivative
	 * almost always broke every enabled format identically, and re-
	 * running an already-fine format again is a cheap no-op (its
	 * ensure_format() short-circuits on the file already existing), so
	 * there's no separate "fix just this one format" action to offer.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {outcome, file, message}
	 */
	private function fix_failure( $file_rel ) {
		$abs_path = $this->abs_path_for( $file_rel );
		$formats  = WWG_Format::enabled();

		// Re-verify server-side which formats are actually still broken --
		// never trust a possibly-stale client click for something this
		// consequential. Anything already fine just needs its stale
		// bookkeeping cleared, not a real regenerate attempt below.
		$still_missing = array();
		foreach ( $this->generator->ensure_formats( $abs_path, $formats ) as $format => $outcome ) {
			if ( in_array( $outcome['status'], array( 'exists', 'created' ), true ) ) {
				$this->clear_failure_record( $file_rel, $format, true );
			} else {
				$still_missing[] = $format;
			}
		}

		if ( empty( $still_missing ) ) {
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
		// this plugin's whole point is the derived-format siblings, which
		// still need generating for the freshly-written file, for every
		// format the recheck above confirmed is still actually missing.
		$all_ok = true;
		foreach ( $this->generator->ensure_formats( $abs_path, $still_missing ) as $format => $outcome ) {
			if ( in_array( $outcome['status'], array( 'created', 'exists' ), true ) ) {
				$this->clear_failure_record( $file_rel, $format, true );
			} else {
				$all_ok = false;
			}
		}

		if ( ! $all_ok ) {
			return array(
				'outcome' => 'failed',
				'file'    => $file_rel,
				'message' => __( 'The image itself was regenerated, but creating one of its derived versions still failed. Check your server’s error log, or try again.', 'webp-generator' ),
			);
		}

		return array(
			'outcome' => 'fixed',
			'file'    => $file_rel,
			'message' => __( 'Fixed — regenerated from the original.', 'webp-generator' ),
		);
	}

	/**
	 * Delete one broken source file's derivative(s) (or a fully
	 * unresolvable file's) -- every derived format still on disk for it,
	 * not just the one format the clicked row happened to represent,
	 * since a corrupt/missing source means none of them can be right
	 * anymore. Never the original itself; see delete_original_attachment()
	 * for that.
	 *
	 * @param string $file_rel Relative path under uploads.
	 * @return array {outcome, file, message}
	 */
	private function delete_failure_file( $file_rel ) {
		$abs_path = $this->abs_path_for( $file_rel );

		// $abs_path is the broken JPG/PNG derivative itself (e.g. a
		// corrupt thumbnail size) -- that's the actual "failed
		// conversion," and the whole reason it's a known failure at all
		// is that no derived format could be produced from it. Delete it
		// directly; then, defensively, also clean up any derived-format
		// file that did manage to get partway written for it (e.g. an
		// interrupted previous attempt) even though the common case is
		// there's nothing there.
		if ( file_exists( $abs_path ) ) {
			wp_delete_file( $abs_path );
		}
		foreach ( array_keys( WWG_Format::all() ) as $format ) {
			$target = WWG_Format::path_for( $format, $abs_path );
			if ( $target && file_exists( $target ) ) {
				wp_delete_file( $target );
			}
		}

		// If this resolved to a real attachment size, strip it from the
		// recorded metadata too -- otherwise WordPress keeps returning a
		// URL for a file that now 404s. One size entry covers every
		// format (a size name isn't format-specific -- only the file
		// extension is), so this needs no per-format loop.
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

		$this->clear_failure_record( $file_rel, null, false );

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
				$this->clear_failure_record( $other_file_rel, null, false );
			}
		}

		$deleted = wp_delete_attachment( $attachment_id, true );
		if ( ! $deleted ) {
			return $this->generic_action_failure( $file_rel );
		}

		$this->clear_failure_record( $file_rel, null, false );

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
	 * @param string      $file_rel Relative path under uploads.
	 * @param string|null $format   The one format that stopped failing, or
	 *                               null to clear every format at once
	 *                               (used by delete, where the source
	 *                               itself is gone, so every format's
	 *                               record for it is moot).
	 * @param bool        $fixed    True if regenerated (counts as a fresh
	 *                              conversion); false if deleted (counts
	 *                              as removed, not converted).
	 */
	private function clear_failure_record( $file_rel, $format, $fixed ) {
		$this->forget_failure( $file_rel, $format );
		$this->remove_failure_from_scan_state( $file_rel, $format, $fixed );
		if ( $this->job ) {
			$this->job->remove_failure_from_state( $file_rel, $format, $fixed );
		}
	}

	/**
	 * @param string      $file_rel Relative path.
	 * @param string|null $format   See clear_failure_record().
	 * @param bool        $fixed    See clear_failure_record().
	 */
	private function remove_failure_from_scan_state( $file_rel, $format, $fixed ) {
		$state = get_option( self::OPTION_SCAN_STATE, array() );
		if ( ! is_array( $state ) || empty( $state['failures'] ) ) {
			return;
		}

		$removed_units = 0;
		$before        = count( $state['failures'] );

		$state['failures'] = array_values(
			array_filter(
				$state['failures'],
				static function ( $failure ) use ( $file_rel, $format, &$removed_units ) {
					if ( ! isset( $failure['file'] ) || $failure['file'] !== $file_rel ) {
						return true; // Keep -- a different file entirely.
					}
					$entry_format = isset( $failure['format'] ) ? $failure['format'] : WWG_Format::WEBP;
					if ( null !== $format && $entry_format !== $format ) {
						return true; // Keep -- this file, but a different format than the one clearing now.
					}
					++$removed_units;
					return false; // Drop.
				}
			)
		);

		if ( count( $state['failures'] ) === $before ) {
			return; // Wasn't listed here -- nothing to adjust.
		}

		// A fix doesn't change how many conversion units Scan found
		// missing -- it just stops one of them being one that will never
		// get done. A delete does: that many are just gone, so they can't
		// be "missing" anymore either. missing_files (the distinct-file
		// headline count) only drops once this file has no failure entry
		// left at all, not on every individual format removed from it.
		if ( ! $fixed ) {
			// isset() guards throughout -- a scan-state option written
			// before AVIF existed has no `missing_files` key at all.
			$state['missing'] = max( 0, ( isset( $state['missing'] ) ? (int) $state['missing'] : 0 ) - $removed_units );

			$file_still_listed = false;
			foreach ( $state['failures'] as $failure ) {
				if ( isset( $failure['file'] ) && $failure['file'] === $file_rel ) {
					$file_still_listed = true;
					break;
				}
			}
			if ( ! $file_still_listed ) {
				$state['missing_files'] = max( 0, ( isset( $state['missing_files'] ) ? (int) $state['missing_files'] : 0 ) - 1 );
			}
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
	 * uploads/ regardless of that setting. collect_subdirectories() below
	 * covers all of it without hardcoding any site's particular structure.
	 *
	 * Cached (see collect_subdirectories()'s own docblock for why this can
	 * safely be long-lived) since it's otherwise recomputed on every AJAX
	 * step -- a fresh cache miss right when a Generate run starts is
	 * exactly what used to leave the client's "Starting…" state sitting
	 * for several real seconds on a large library, before this method's
	 * own rewrite away from a per-file iterator (see below).
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
			$this->collect_subdirectories( $base, '', $dirs, 0 );
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
		// An hour, not the 15 minutes this used to be -- safe to leave
		// this long-lived because the common way this list actually goes
		// stale (a new upload creating a fresh "YYYY/MM" folder) already
		// invalidates it proactively and immediately (see
		// WWG_Generator::generate_siblings()'s own delete_transient()
		// call), so the TTL only exists as a safety net for a folder
		// appearing some other way (a migration script, another plugin
		// writing directly into uploads/) -- self-healing within the hour
		// either way, just without paying this walk's cost on every
		// expiry in between.
		set_transient( $cache_key, $dirs, HOUR_IN_SECONDS );

		return $dirs;
	}

	/**
	 * Recursively collects every subdirectory under $abs_dir into $dirs,
	 * as paths relative to $base -- glob()'s GLOB_ONLYDIR, not the
	 * RecursiveDirectoryIterator/RecursiveIteratorIterator pair this
	 * replaced. Real-world difference confirmed live: that iterator
	 * visits every FILE too, not just directories (SELF_FIRST traversal
	 * has to inspect each entry's isDir() to rule it out), so on a
	 * library with hundreds of thousands of images sitting in a handful
	 * of leaf "YYYY/MM" folders, get_scan_directories() was stepping
	 * through the entire library in PHP userland just to find ~200
	 * directory names -- measured at several real seconds on a ~194k-
	 * image library the moment this method's own 15-minute cache (see
	 * get_scan_directories()) expired, which is exactly the stretch a
	 * fresh Generate click has nothing else to show the visitor yet (see
	 * 'startingLabel' in get_strings()). glob() does its directory-vs-file
	 * filtering natively rather than exposing every file to a PHP-level
	 * loop, so this scales with the number of FOLDERS instead.
	 *
	 * @param string $abs_dir Absolute path to search.
	 * @param string $rel_dir That path's own already-known relative path
	 *                        ('' for $base itself).
	 * @param array  $dirs    Accumulator, appended to by reference.
	 * @param int    $depth   Recursion guard -- same 10-level cap the
	 *                        iterator this replaced already enforced, as
	 *                        a safety net against a runaway/circular
	 *                        structure (e.g. a symlink loop) on a server
	 *                        where nothing else guards against one.
	 */
	private function collect_subdirectories( $abs_dir, $rel_dir, array &$dirs, $depth ) {
		if ( $depth >= 10 ) {
			return;
		}

		$children = glob( $abs_dir . '/*', GLOB_ONLYDIR );
		if ( empty( $children ) ) {
			return;
		}

		foreach ( $children as $child ) {
			if ( is_link( $child ) ) {
				continue; // Same symlink-loop guard the iterator this replaced had.
			}
			$child_rel = '' === $rel_dir ? basename( $child ) : $rel_dir . '/' . basename( $child );
			$dirs[]    = $child_rel;
			$this->collect_subdirectories( $child, $child_rel, $dirs, $depth + 1 );
		}
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
	 * do -- either zero images missing an enabled derived format, or the
	 * only ones that are missing are known, still-stable failures (see
	 * is_known_failure()) -- for every format currently enabled, and
	 * nothing invalidating has happened since. Cheap in the common
	 * "never cached" case -- a single get_option() array lookup, no
	 * filesystem stat at all.
	 *
	 * @param string   $dir_rel Relative folder path -- key into the cache.
	 * @param string   $abs_dir Absolute folder path, stat'd only if
	 *                          there's a cache entry to validate.
	 * @param string[] $formats Currently-enabled format ids (WWG_Format::enabled()).
	 * @return array|false False if not cached, or since invalidated (or
	 *                      predates whole-library totals -- see the
	 *                      `totals` check below). Otherwise
	 *                      {known_failures, totals}: known_failures is
	 *                      the (possibly empty) list of {file, format}
	 *                      pairs this folder's clean status depends on --
	 *                      the caller still needs to report those every
	 *                      run, just without re-walking the rest of the
	 *                      folder to find them; totals is this folder's
	 *                      own remembered {scanned, webp_bytes,
	 *                      avif_bytes, webp_original_bytes,
	 *                      avif_original_bytes} contribution (see
	 *                      OPTION_CLEAN_DIRS's own docblock).
	 */
	private function is_known_clean( $dir_rel, $abs_dir, array $formats ) {
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
		// forcing a one-time migration. Same for entries with no
		// `formats` key at all (written before AVIF existed) -- only
		// WebP could have been considered when they were cached.
		$mtime          = is_array( $entry ) ? $entry['mtime'] : $entry;
		$cached_formats = is_array( $entry ) && ! empty( $entry['formats'] ) ? $entry['formats'] : array( WWG_Format::WEBP );
		$known_failures = is_array( $entry ) && ! empty( $entry['known_failures'] ) ? $entry['known_failures'] : array();
		$totals         = is_array( $entry ) && ! empty( $entry['totals'] ) ? $entry['totals'] : null;

		// A format enabled now that wasn't accounted for when this folder
		// was last verified clean (this server only just gained AVIF
		// support, or a wwg_enabled_formats filter loosened) means this
		// folder's "nothing further to do" fact is stale for that format
		// specifically, even though nothing about the folder's own
		// contents changed -- force a real recheck rather than silently
		// never generating the new format for anything already cached.
		if ( array_diff( $formats, $cached_formats ) ) {
			return false;
		}

		// An entry cached before whole-library totals existed at all has
		// no `totals` key; one cached after that but before the
		// Original/WebP/AVIF table's own *_present counts existed has a
		// `totals` missing just those two -- either way, rather than
		// silently contributing an incomplete (or zero) total to Library
		// Status forever, treat both like the format-staleness case
		// above: force one real walk, which backfills the full current
		// shape via mark_known_clean() the moment it finishes. Checking
		// one representative newest field ('webp_present') rather than
		// every key individually -- mark_known_clean() only ever writes
		// this whole array as one literal, so any entry that has this
		// key has every other current key too. Every check after that is
		// a true, fully-accurate hit.
		if ( null === $totals || ! isset( $totals['webp_present'] ) ) {
			return false;
		}

		if ( filemtime( $abs_dir ) !== $mtime ) {
			return false;
		}

		// Normalize each dependency to {file, format} -- entries cached
		// before AVIF existed are bare file_rel strings (only WebP could
		// have been meant).
		$normalized = array();
		foreach ( $known_failures as $item ) {
			$normalized[] = is_array( $item ) ? $item : array(
				'file'   => $item,
				'format' => WWG_Format::WEBP,
			);
		}

		// The directory's own mtime only catches files being added,
		// removed, or renamed -- an in-place edit to one of the known-
		// failure files this folder's clean status depends on typically
		// won't touch it. Cheaply (one stat per known failure, not a walk
		// of the whole folder) re-validate each one before trusting the
		// skip below, so a since-fixed file can never be masked forever
		// just because the rest of the folder never changed.
		foreach ( $normalized as $item ) {
			if ( false === $this->is_known_failure( $item['file'], $abs_dir . '/' . basename( $item['file'] ), $item['format'] ) ) {
				return false;
			}
		}

		return array(
			'known_failures' => $normalized,
			'totals'         => $totals,
		);
	}

	/**
	 * Record $dir_rel as clean (optionally "clean except these known,
	 * already-confirmed-stable failures") as of its current mtime, for
	 * the formats that were actually considered this pass. Call only
	 * after a single process_batch() call has just verified every file
	 * in the folder in one pass -- see process_batch()'s $became_clean
	 * check.
	 *
	 * @param string $dir_rel        Relative folder path.
	 * @param string $abs_dir        Absolute folder path.
	 * @param string[] $formats      Currently-enabled format ids this
	 *                                pass actually checked -- see
	 *                                is_known_clean()'s format-staleness
	 *                                check for why this must be recorded,
	 *                                not just the folder's mtime.
	 * @param array[]  $known_failures {file, format} pairs (already
	 *                                reconfirmed stable this same pass,
	 *                                not freshly discovered) this folder's
	 *                                clean status depends on.
	 * @param array    $totals         This folder's own {scanned,
	 *                                original_bytes_total, webp_bytes,
	 *                                avif_bytes, webp_original_bytes,
	 *                                avif_original_bytes, webp_present,
	 *                                avif_present} contribution --
	 *                                exactly the relevant slice of
	 *                                process_batch()'s own $stats, which
	 *                                is always scoped to just this one
	 *                                folder for the single call that
	 *                                triggers $became_clean. See
	 *                                OPTION_CLEAN_DIRS's own docblock for
	 *                                why this is remembered at all.
	 */
	private function mark_known_clean( $dir_rel, $abs_dir, array $formats, array $known_failures = array(), array $totals = array() ) {
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
		$entry      = array(
			'mtime'   => $mtime,
			'formats' => $formats,
		);
		if ( $known_failures ) {
			$entry['known_failures'] = array_values( $known_failures );
		}
		// Defensive isset() fallbacks, not a bare pass-through -- keeps
		// this method's own contract self-contained rather than trusting
		// its one caller to always supply every key.
		$entry['totals']        = array(
			'scanned'              => isset( $totals['scanned'] ) ? $totals['scanned'] : 0,
			'original_bytes_total' => isset( $totals['original_bytes_total'] ) ? $totals['original_bytes_total'] : 0,
			'webp_bytes'           => isset( $totals['webp_bytes'] ) ? $totals['webp_bytes'] : 0,
			'avif_bytes'           => isset( $totals['avif_bytes'] ) ? $totals['avif_bytes'] : 0,
			'webp_original_bytes'  => isset( $totals['webp_original_bytes'] ) ? $totals['webp_original_bytes'] : 0,
			'avif_original_bytes'  => isset( $totals['avif_original_bytes'] ) ? $totals['avif_original_bytes'] : 0,
			'webp_present'         => isset( $totals['webp_present'] ) ? $totals['webp_present'] : 0,
			'avif_present'         => isset( $totals['avif_present'] ) ? $totals['avif_present'] : 0,
		);
		$clean_dirs[ $dir_rel ] = $entry;
		update_option( self::OPTION_CLEAN_DIRS, $clean_dirs, false );
	}

	/**
	 * Normalizes one OPTION_KNOWN_FAILURES entry's per-format failures,
	 * reading a pre-AVIF flat {size, mtime, error} record (no `formats`
	 * key at all) as if it had always been {..., formats: {webp: {error}}}
	 * -- the only format that could possibly have failed before AVIF
	 * existed. Never rewrites the stored option; this is a read-time
	 * interpretation only.
	 *
	 * @param array $entry One OPTION_KNOWN_FAILURES value.
	 * @return array<string,array{error:string}> format id => {error}.
	 */
	private static function formats_of( $entry ) {
		if ( isset( $entry['formats'] ) && is_array( $entry['formats'] ) ) {
			return $entry['formats'];
		}
		if ( isset( $entry['error'] ) ) {
			return array( WWG_Format::WEBP => array( 'error' => $entry['error'] ) );
		}
		return array();
	}

	/**
	 * Whether $file_rel was already confirmed to fail conversion for
	 * $format specifically, and its size/mtime haven't changed since --
	 * mirrors is_known_clean()'s self-invalidation approach at file
	 * granularity instead of folder.
	 *
	 * @param string $file_rel    Relative path -- key into the cache.
	 * @param string $source_path Absolute path, stat'd only if there's a
	 *                            cache entry to validate.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return array|false {size, mtime, error} for this format if the
	 *                      fingerprint still matches and this format is
	 *                      among its remembered failures, false otherwise.
	 */
	private function is_known_failure( $file_rel, $source_path, $format ) {
		$known = get_option( self::OPTION_KNOWN_FAILURES, array() );

		if ( ! isset( $known[ $file_rel ] ) || ! file_exists( $source_path ) ) {
			return false;
		}

		$entry = $known[ $file_rel ];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish/become unreadable between the directory listing and here; (int) cast already turns a false return into a harmless 0, which simply won't match a real stored fingerprint.
		if ( (int) @filesize( $source_path ) !== $entry['size'] || (int) @filemtime( $source_path ) !== $entry['mtime'] ) {
			return false; // Fingerprint changed -- something touched the file, so re-attempt for real.
		}

		$formats = self::formats_of( $entry );
		if ( ! isset( $formats[ $format ] ) ) {
			return false;
		}

		return array(
			'size'  => $entry['size'],
			'mtime' => $entry['mtime'],
			'error' => $formats[ $format ]['error'],
		);
	}

	/**
	 * Record $file_rel as failing $format, fingerprinted to its current
	 * size/mtime so a later real change to the file is detected and every
	 * format re-attempted. Other formats already remembered as failing
	 * for this exact same fingerprint are preserved alongside the new
	 * one; a changed fingerprint discards them first -- whatever was
	 * remembered against the old bytes no longer means anything for any
	 * format, not just the one just re-attempted.
	 *
	 * @param string $file_rel    Relative path.
	 * @param string $source_path Absolute path.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @param string $error       The failure reason to remember and
	 *                            re-report on future skipped attempts.
	 */
	private function remember_failure( $file_rel, $source_path, $format, $error ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see is_known_failure() above.
		$mtime = (int) @filemtime( $source_path );
		if ( ! $mtime ) {
			return; // Vanished/unreadable -- nothing meaningful to fingerprint.
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see is_known_failure() above.
		$size = (int) @filesize( $source_path );

		$known    = get_option( self::OPTION_KNOWN_FAILURES, array() );
		$existing = isset( $known[ $file_rel ] ) ? $known[ $file_rel ] : null;

		$formats = ( $existing && (int) $existing['size'] === $size && (int) $existing['mtime'] === $mtime )
			? self::formats_of( $existing )
			: array();

		$formats[ $format ] = array( 'error' => $error );

		$known[ $file_rel ] = array(
			'size'    => $size,
			'mtime'   => $mtime,
			'formats' => $formats,
		);
		update_option( self::OPTION_KNOWN_FAILURES, $known, false );
	}

	/**
	 * Stop remembering $file_rel as a failure -- called once it's known to
	 * no longer be one (a fresh success, including a recovered one, or a
	 * derived file appearing for it through some other means).
	 *
	 * @param string      $file_rel Relative path.
	 * @param string|null $format   The one format that stopped failing, or
	 *                              null to forget every format at once
	 *                              (the source itself is gone).
	 */
	private function forget_failure( $file_rel, $format = null ) {
		$known = get_option( self::OPTION_KNOWN_FAILURES, array() );
		if ( ! isset( $known[ $file_rel ] ) ) {
			return;
		}

		if ( null === $format ) {
			unset( $known[ $file_rel ] );
			update_option( self::OPTION_KNOWN_FAILURES, $known, false );
			return;
		}

		$formats = self::formats_of( $known[ $file_rel ] );
		if ( ! isset( $formats[ $format ] ) ) {
			return;
		}
		unset( $formats[ $format ] );

		if ( empty( $formats ) ) {
			unset( $known[ $file_rel ] );
		} else {
			$known[ $file_rel ]['formats'] = $formats;
			unset( $known[ $file_rel ]['error'] ); // Now normalized -- drop any lingering pre-AVIF top-level field.
		}
		update_option( self::OPTION_KNOWN_FAILURES, $known, false );
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
			'scanned'              => 0,
			// Distinct files needing >=1 enabled format -- the headline
			// sentence's count.
			'missing_files'        => 0,
			// (file, format) conversion units still needed -- the
			// progress bar's currency; a file needing both WebP and AVIF
			// counts once in missing_files but twice here.
			'missing'              => 0,
			'converted'            => 0,
			'failed'               => 0,
			'original_bytes'       => 0,
			// The whole library's own original size, once per file
			// regardless of which formats happen to be enabled --
			// Library Status's "Original" table row reads from this, not
			// from either format's own *_original_bytes below (those stay
			// 0 for a format that isn't currently enabled at all).
			'original_bytes_total' => 0,
			// Per-format "X vs. originals" stat-tile figures. Deliberately
			// NOT gated on this run actually needing to convert anything:
			// webp_bytes/avif_bytes is the CURRENT total size of every
			// format file for every source file this run has visited so
			// far, whether that format file already existed coming in or
			// was just created just now, and webp_original_bytes/
			// avif_original_bytes is those same visited files' total
			// original size, regardless of which formats they happened to
			// need. A format that's already fully caught up therefore
			// still climbs both figures together, in real time, as the
			// tree is walked -- reading as "keeping pace" rather than
			// either a fabricated instant "Done" claim or a numerator
			// stuck at 0 against a denominator that keeps growing without
			// it (the two bad alternatives this replaced).
			'webp_bytes'           => 0,
			'avif_bytes'           => 0,
			'webp_original_bytes'  => 0,
			'avif_original_bytes'  => 0,
			// Companion to *_bytes above, but a plain file COUNT (not a
			// byte size) -- how many source files currently have that
			// format's derivative on disk, incremented in lockstep with
			// *_bytes everywhere it's incremented (the initial exists()
			// check, and both convert-outcome branches below). Library
			// Status's own table needs this directly -- *_bytes alone
			// can't tell "fewer, larger files" apart from "many, smaller
			// files".
			'webp_present'         => 0,
			'avif_present'         => 0,
			'failures'             => array(),
			'recoveries'           => array(),
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

		$formats = WWG_Format::enabled();
		if ( empty( $formats ) ) {
			// This server can't actually produce anything -- same "done,
			// nothing to do" shape as an exhausted $dirs list, without
			// walking a single folder to discover that.
			return array(
				'stats'       => $stats,
				'dir'         => '',
				'dir_index'   => count( $dirs ),
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

		$cache_hit = $this->is_known_clean( $dir_rel, $abs_dir, $formats );
		if ( false !== $cache_hit ) {
			// The folder itself isn't walked -- that's the whole point --
			// but any known failures it depends on must still be reported
			// every run, same as if we'd found them the slow way (see
			// is_known_failure()'s docblock: nothing should silently drop
			// out of view). is_known_clean() already cheaply re-validated
			// each of these still matches its remembered fingerprint.
			$counted_files = array();
			foreach ( $cache_hit['known_failures'] as $item ) {
				$file_rel = $item['file'];
				$format   = $item['format'];
				if ( ! in_array( $format, $formats, true ) ) {
					continue; // No longer an enabled format -- nothing to report.
				}
				$entry = $this->is_known_failure( $file_rel, $abs_dir . '/' . basename( $file_rel ), $format );
				if ( false === $entry ) {
					continue; // Shouldn't happen -- is_known_clean() just checked this -- but never trust a stat() race blindly.
				}
				++$stats['missing'];
				++$stats['failed'];
				if ( ! isset( $counted_files[ $file_rel ] ) ) {
					$counted_files[ $file_rel ] = true;
					++$stats['missing_files'];
					$stats['original_bytes'] += $entry['size'];
				}
				$stats[ $format . '_original_bytes' ] += $entry['size'];
				$stats['failures'][]                   = array(
					'file'   => $file_rel,
					'format' => $format,
					'error'  => $entry['error'],
				);
			}

			// The clean majority of this folder was never re-walked --
			// add back its own remembered totals (see mark_known_clean())
			// so this pass's whole-library figures (Library Status's own
			// numbers) still include it, instead of silently excluding
			// every cache-skipped folder -- see OPTION_CLEAN_DIRS's own
			// docblock for why these are safe to just sum in.
			foreach ( $cache_hit['totals'] as $key => $value ) {
				$stats[ $key ] += $value;
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

		// Counts real (expensive) ensure_format() calls this batch only --
		// deliberately separate from $stats['missing'], since a
		// known-failure shortcut hit below reports as missing/failed too
		// but does no real decode work, so it must not eat into the cap
		// that exists specifically to bound that expensive work.
		$real_attempts = 0;

		// {file, format} pairs of known failures reconfirmed via the
		// shortcut below this same pass -- as opposed to ones failing for
		// the first time this pass, which $became_clean below must not
		// yet trust.
		$stable_known_failures = array();

		foreach ( $slice as $filename ) {
			$source_path = $abs_dir . '/' . $filename;
			$file_rel    = '' === $dir_rel ? $filename : $dir_rel . '/' . $filename;

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish or become unreadable between the directory listing above and this stat() call; (int) cast already turns a false return into a harmless 0.
			$source_bytes                   = (int) @filesize( $source_path );
			$stats['original_bytes_total'] += $source_bytes; // every file visited, regardless of enabled formats -- see this key's own docblock above.

			// Belt-and-suspenders, both modes, per format: a derived file
			// may have appeared some other way (manual upload, another
			// tool) since a failure was last remembered for it -- checked
			// before the known-failure shortcut so a stale record can
			// never mask a file that's already actually fine, regardless
			// of which mode happens to notice first. Also doubles as this
			// run's live "X vs. originals" stat-tile tally (see the $stats
			// array's own docblock): every file actually visited here --
			// not just ones with something missing -- feeds its original
			// size into webp_original_bytes/avif_original_bytes, and a
			// format already sitting on disk feeds its current size into
			// webp_bytes/avif_bytes right here, in the same pass that just
			// confirmed it exists.
			$missing_formats = array();
			foreach ( $formats as $format ) {
				$target                                = WWG_Format::path_for( $format, $source_path );
				$stats[ $format . '_original_bytes' ] += $source_bytes;
				if ( $target && file_exists( $target ) ) {
					$this->forget_failure( $file_rel, $format );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same race as $source_bytes above; (int) cast turns a false return into a harmless 0.
					$stats[ $format . '_bytes' ] += (int) @filesize( $target );
					++$stats[ $format . '_present' ];
				} else {
					$missing_formats[] = $format;
				}
			}

			if ( empty( $missing_formats ) ) {
				++$stats['scanned'];
				continue;
			}

			++$stats['missing_files'];
			$stats['original_bytes'] += $source_bytes;

			// Known-failure shortcut, both modes, per format: cheap (a
			// stat, not a decode), and reported identically in either
			// mode's stats -- a permanent failure doesn't become less
			// permanent because Scan found it instead of Generate.
			$pending_formats = array();
			foreach ( $missing_formats as $format ) {
				$known_failure = $this->is_known_failure( $file_rel, $source_path, $format );
				if ( false !== $known_failure ) {
					++$stats['missing'];
					++$stats['failed'];
					$stats['failures'][]     = array(
						'file'   => $file_rel,
						'format' => $format,
						'error'  => $known_failure['error'],
					);
					$stable_known_failures[] = array(
						'file'   => $file_rel,
						'format' => $format,
					);
				} else {
					$pending_formats[] = $format;
				}
			}

			if ( empty( $pending_formats ) ) {
				++$stats['scanned']; // Every missing format for this file was a known, stable failure -- no real decode attempt needed.
				continue;
			}

			if ( 'convert' === $mode ) {
				// Only the real-conversion path is expensive -- once this
				// batch has attempted MAX_CONVERSIONS_PER_BATCH of them,
				// stop and leave the rest of the slice for the next call
				// (not counted as scanned, so resumption starts exactly
				// here). Already-exists files and known-failure shortcuts
				// above never trip this, so a folder that's mostly or
				// fully already resolved sails through the whole (much
				// larger) window in one pass regardless of size. Checked
				// once per FILE, not per pending format within it -- a
				// file needing two formats right at the boundary can push
				// $real_attempts one past the cap, a deliberate trade-off
				// that keeps "a file is fully processed or not touched
				// this batch" true, so resumption needs no partial-file
				// bookkeeping.
				if ( $real_attempts >= self::MAX_CONVERSIONS_PER_BATCH ) {
					break;
				}
				$real_attempts += count( $pending_formats );
				++$stats['scanned'];

				foreach ( $this->generator->ensure_formats( $source_path, $pending_formats ) as $format => $outcome ) {
					if ( 'exists' === $outcome['status'] ) {
						// Shouldn't normally happen -- the shared
						// file_exists() check above just confirmed this
						// format was missing -- but a race (something
						// else creating it in between) is possible; treat
						// it the same as that check would have, including
						// feeding its size into the "X vs. originals"
						// tile's numerator the same as the exists-check
						// loop above would have if it had won the race.
						$this->forget_failure( $file_rel, $format );
						$stats[ $format . '_bytes' ] += $outcome['bytes'];
						++$stats[ $format . '_present' ];
						continue;
					}

					++$stats['missing'];

					if ( 'created' === $outcome['status'] ) {
						++$stats['converted'];
						$stats[ $format . '_bytes' ] += $outcome['bytes'];
						++$stats[ $format . '_present' ];
						$this->forget_failure( $file_rel, $format ); // Covers recovered successes too.
						if ( ! empty( $outcome['recovered'] ) ) {
							$stats['recoveries'][] = array(
								'file'   => $file_rel,
								'format' => $format,
							);
						}
					} else {
						++$stats['failed'];
						$error               = isset( $outcome['error'] ) ? $outcome['error'] : '';
						$stats['failures'][] = array(
							'file'   => $file_rel,
							'format' => $format,
							'error'  => $error,
						);
						$this->remember_failure( $file_rel, $source_path, $format, $error );
					}
				}
			} else {
				++$stats['scanned'];
				$stats['missing'] += count( $pending_formats );
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
		// mode-agnostic and still holds scaled to conversion units
		// instead of files: the known-failure shortcut (and so
		// $stable_known_failures) is shared by both modes, but only
		// convert mode ever increments $stats['converted'] (scan mode
		// never attempts a real conversion, so it stays 0 there) -- for
		// scan mode this reduces to "every missing unit is a known,
		// reconfirmed-stable failure"; for convert mode, missing =
		// converted + failed by construction each batch, so it's
		// equivalent to "every currently-failed unit this pass is a known
		// one already reconfirmed stable via the shortcut above, not a
		// fresh first-time failure" -- a folder with any *fresh* failure
		// is correctly never cached on the pass that discovers it (its
		// stability isn't known yet), only from the next pass on, once
		// the shortcut itself (in either mode) has reconfirmed it.
		$became_clean = ( 0 === $file_offset ) && $dir_done
			&& ( $stats['missing'] === $stats['converted'] + count( $stable_known_failures ) );
		if ( $became_clean ) {
			// $stats is scoped to just this one folder for this one call
			// (fresh at the top of process_batch(), never accumulated
			// across directories) -- exactly what mark_known_clean()
			// needs to remember as this folder's own contribution.
			$this->mark_known_clean(
				$dir_rel,
				$abs_dir,
				$formats,
				$stable_known_failures,
				array(
					'scanned'              => $stats['scanned'],
					'original_bytes_total' => $stats['original_bytes_total'],
					'webp_bytes'           => $stats['webp_bytes'],
					'avif_bytes'           => $stats['avif_bytes'],
					'webp_original_bytes'  => $stats['webp_original_bytes'],
					'avif_original_bytes'  => $stats['avif_original_bytes'],
					'webp_present'         => $stats['webp_present'],
					'avif_present'         => $stats['avif_present'],
				)
			);
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

	// ---- Media Library list-view "WebP/AVIF" column ----
	//
	// Deliberately not sortable, and nothing here is persisted -- every
	// row's status is recomputed live, the same handful of file_exists()/
	// filesize() checks the rest of this class already treats as cheap
	// enough to run on every render (see classify_failure()'s own
	// docblock for the identical reasoning). Sorting would need a real,
	// permanent per-attachment record kept in sync on every conversion/
	// fix/delete -- nothing in this plugin has ever needed that, and nor
	// does simply reporting a fresh answer per page load.

	/**
	 * @param array $columns Existing Media Library list-table columns.
	 * @return array
	 */
	public function add_compression_column( $columns ) {
		$columns['wwg_compression'] = __( 'WebP/AVIF', 'webp-generator' );
		return $columns;
	}

	/**
	 * @param string $column_name The column being rendered -- fires for
	 *                             every custom column, not just ours.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_compression_column( $column_name, $post_id ) {
		if ( 'wwg_compression' !== $column_name ) {
			return;
		}

		$summary = $this->compression_summary_for_attachment( (int) $post_id );

		if ( 'converted' === $summary['status'] || 'partial' === $summary['status'] ) {
			// Badges only earn their keep once there's more than one
			// format to tell apart -- on a single-format server every
			// line would carry the identical one badge, uninformative
			// clutter rather than a signal (mirrors admin.js's own
			// shouldShowFormatBadges() on the Tools page).
			$show_badges = count( $summary['formats'] ) > 1;

			foreach ( $summary['formats'] as $format => $data ) {
				$badge = $show_badges
					? '<span class="wwg-chip wwg-chip--' . esc_attr( $format ) . '">' . esc_html( WWG_Format::label( $format ) ) . '</span> '
					: '';

				if ( ! empty( $data['complete'] ) ) {
					echo '<div class="wwg-cc-line">'
						. '<span class="wwg-cc-check" aria-hidden="true">&#10003;</span> '
						. $badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already HTML-escaped above (esc_attr()/esc_html() on every dynamic piece before being wrapped in <span> markup, or the empty string); a second esc_html() pass here would double-escape it.
						. '<span class="wwg-cc-figure">' . esc_html( size_format( $data['bytes'] ) ) . '</span> '
						/* translators: %d: percent smaller than the original file. */
						. '<span class="wwg-cc-percent">' . esc_html( sprintf( __( '%d%% smaller', 'webp-generator' ), $data['saved_percent'] ) ) . '</span>'
						. '</div>';
				} else {
					// Deliberately still one line per incomplete format
					// (not one shared "Not converted yet" for the whole
					// row) -- on a multi-format site this is exactly what
					// tells "WebP is fine, AVIF just hasn't run yet"
					// apart from "nothing has ever been converted here",
					// which look identical without it.
					echo '<div class="wwg-cc-line">'
						. $badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see the note above.
						. '<span class="wwg-cc-status wwg-cc-status--muted">' . esc_html__( 'Not converted yet', 'webp-generator' ) . '</span>'
						. '</div>';
				}
			}

			// Only 'partial' -- never 'converted' (nothing to do) -- and
			// only for someone who could actually use it: this column
			// itself is visible to anyone who can see the Media Library,
			// but clicking this hits an AJAX action gated the same way
			// every other interactive surface in this plugin already is.
			// Deliberately never offered for 'failed' below -- a known
			// failure needs the real Fix/Delete recovery machinery on the
			// Tools page, not a plain retry that would just fail the same
			// way again with no explanation.
			if ( 'partial' === $summary['status'] && current_user_can( self::CAPABILITY ) ) {
				printf(
					'<button type="button" class="button button-small wwg-row-btn wwg-cc-generate-btn" data-attachment-id="%d">%s</button>',
					(int) $post_id,
					esc_html__( 'Generate', 'webp-generator' )
				);
			}
			return;
		}

		if ( 'failed' === $summary['status'] ) {
			$tools_url = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
			echo '<span class="wwg-chip wwg-chip--failed">' . esc_html__( 'Failed', 'webp-generator' ) . '</span> '
				. '<span class="wwg-cc-status wwg-cc-status--failed">' . esc_html__( 'A size couldn’t be converted.', 'webp-generator' ) . '</span>'
				. '<a class="wwg-cc-link" href="' . esc_url( $tools_url ) . '">' . esc_html__( 'View in Failed Conversions →', 'webp-generator' ) . '</a>';
			return;
		}

		// 'not_yet' (no format enabled on this server at all) / 'unsupported'
		// (not a JPEG/PNG, or no usable attachment metadata) -- both render
		// as a plain empty cell: nothing meaningful to report either way,
		// and 'not_yet' would otherwise repeat the same message on every
		// single row.
	}

	/**
	 * AJAX: the Media Library List view's own single-image "Generate"
	 * button (see render_compression_column()'s 'partial' branch above).
	 * Unlike the Tools page's bulk Generate, there's no job/progress state
	 * to track here -- one attachment's handful of files converts fast
	 * enough to just do it synchronously and hand back the result.
	 *
	 * Responds with fresh HTML for the one column cell that changed,
	 * rather than raw data the client would have to re-render itself --
	 * render_compression_column() is the only place that ever decides
	 * what that cell looks like, on a fresh page load or right after this
	 * click alike.
	 */
	public function handle_generate_attachment() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webp-generator' ) ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong:', 'webp-generator' ) ), 400 );
		}

		$this->regenerate_attachment( $attachment_id );

		ob_start();
		$this->render_compression_column( 'wwg_compression', $attachment_id );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Ensure every enabled format exists for one attachment's original and
	 * every registered size -- the single-image counterpart to
	 * process_batch()'s own per-file loop (~line 1984 above), sharing its
	 * exact bookkeeping (forget_failure()/remember_failure()) so a result
	 * from here is indistinguishable from one the Tools page's own
	 * Generate would have found, whichever happens to surface it first.
	 *
	 * @param int $attachment_id
	 */
	private function regenerate_attachment( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['file'] ) ) {
			return;
		}

		$formats     = WWG_Format::enabled();
		$upload_dir  = wp_get_upload_dir();
		$base_dir    = trailingslashit( $upload_dir['basedir'] );
		$created_any = false;

		foreach ( $this->generator->get_source_files( $metadata ) as $abs_path ) {
			if ( ! file_exists( $abs_path ) ) {
				continue; // Gone from disk -- nothing here to convert.
			}
			$file_rel = ltrim( str_replace( $base_dir, '', $abs_path ), '/' );

			foreach ( $this->generator->ensure_formats( $abs_path, $formats ) as $format => $outcome ) {
				if ( 'exists' === $outcome['status'] ) {
					continue;
				}
				if ( 'created' === $outcome['status'] ) {
					$created_any = true;
					$this->forget_failure( $file_rel, $format );
					continue;
				}
				$this->remember_failure( $file_rel, $abs_path, $format, isset( $outcome['error'] ) ? $outcome['error'] : '' );
			}
		}

		if ( $created_any ) {
			WWG_Cache::clear_for_attachment( $attachment_id );
		}
	}

	/**
	 * Live per-attachment compression summary for the column above.
	 * Cheap by construction -- one wp_get_attachment_metadata() call
	 * (already warmed by the list table's own query) plus a handful of
	 * file_exists()/filesize() stats against this one attachment's own
	 * files, never a folder walk and never a decode.
	 *
	 * @param int $attachment_id
	 * @return array {
	 *     @type string $status         One of 'converted', 'partial',
	 *                                  'failed', 'unsupported', 'not_yet'.
	 *     @type int    $original_bytes Summed across the original + every
	 *                                  registered size. Present only when
	 *                                  status is 'converted'.
	 *     @type array  $formats        format id => {bytes, saved_percent}.
	 *                                  Present only when status is
	 *                                  'converted'.
	 * }
	 */
	private function compression_summary_for_attachment( $attachment_id ) {
		if ( ! in_array( get_post_mime_type( $attachment_id ), WWG_Generator::SUPPORTED_MIME_TYPES, true ) ) {
			return array( 'status' => 'unsupported' );
		}

		$formats = WWG_Format::enabled();
		if ( empty( $formats ) ) {
			return array( 'status' => 'not_yet' );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['file'] ) ) {
			return array( 'status' => 'unsupported' );
		}

		$source_files = $this->generator->get_source_files( $metadata );
		if ( empty( $source_files ) ) {
			return array( 'status' => 'unsupported' );
		}

		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		$original_bytes    = 0;
		$format_bytes      = array_fill_keys( $formats, 0 );
		$format_complete   = array_fill_keys( $formats, true );
		$failed            = false;
		$any_source_exists = false;

		foreach ( $source_files as $abs_path ) {
			if ( ! file_exists( $abs_path ) ) {
				continue; // The source itself is gone -- nothing to report for it specifically.
			}
			$any_source_exists = true;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish/become unreadable between the listing above and here; (int) cast already turns a false return into a harmless 0.
			$original_bytes += (int) @filesize( $abs_path );
			$file_rel        = ltrim( str_replace( $base_dir, '', $abs_path ), '/' );

			foreach ( $formats as $format ) {
				$target = WWG_Format::path_for( $format, $abs_path );
				if ( $target && file_exists( $target ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
					$format_bytes[ $format ] += (int) @filesize( $target );
					continue;
				}
				$format_complete[ $format ] = false;
				if ( false !== $this->is_known_failure( $file_rel, $abs_path, $format ) ) {
					$failed = true;
				}
			}
		}

		if ( ! $any_source_exists ) {
			// Every one of this attachment's files -- original included --
			// is missing from disk (moved/deleted outside WordPress; a
			// real, if rare, data-integrity issue distinct from anything
			// this plugin does). Nothing to honestly report -- specifically
			// NOT "converted, 0 B, 0% smaller", which the loop above would
			// otherwise trivially satisfy having never found anything to
			// check at all.
			return array( 'status' => 'unsupported' );
		}

		if ( $failed ) {
			return array( 'status' => 'failed' );
		}

		// Per-format breakdown either way -- 'partial' and 'converted' both
		// report exactly which formats are actually ready, rather than a
		// single blanket status. A blanket "Not converted yet" can't tell
		// "this format specifically hasn't run yet" apart from "nothing
		// has ever been converted for this file at all" -- the former
		// reads, confusingly, identically to the latter, which is exactly
		// what made an already-converted WebP file look like it had been
		// deleted the moment AVIF (a second format) simply hadn't caught
		// up to it yet.
		$result = array(
			'status'         => in_array( false, $format_complete, true ) ? 'partial' : 'converted',
			'original_bytes' => $original_bytes,
			'formats'        => array(),
		);
		foreach ( $formats as $format ) {
			if ( $format_complete[ $format ] ) {
				$bytes                        = $format_bytes[ $format ];
				$result['formats'][ $format ] = array(
					'complete'      => true,
					'bytes'         => $bytes,
					'saved_percent' => $original_bytes > 0 ? (int) round( ( 1 - ( $bytes / $original_bytes ) ) * 100 ) : 0,
				);
			} else {
				$result['formats'][ $format ] = array( 'complete' => false );
			}
		}
		return $result;
	}
}
