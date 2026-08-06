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

	const AJAX_ACTION    = 'wwg_process_batch';
	const NONCE_ACTION   = 'wwg_admin';
	const SETTINGS_NONCE = 'wwg_settings';
	const HTACCESS_NONCE = 'wwg_htaccess';
	const CAPABILITY     = 'manage_options';
	const PAGE_SLUG      = 'wp-webp-generator';

	/**
	 * Transient used to tally how many images an in-progress convert run
	 * has actually created, across its many stateless AJAX requests, so
	 * the cache is only cleared once at the end -- and only if the run
	 * did real work.
	 *
	 * @var string
	 */
	const CONVERT_RUN_TALLY_KEY = 'wwg_convert_run_tally';

	/**
	 * How many files to check/convert per AJAX request. Scanning is just
	 * file_exists() calls, so it can move through a lot per request;
	 * converting decodes/re-encodes full images, so it stays small enough
	 * that a single batch can't run into PHP's max_execution_time even on
	 * a slow shared host.
	 *
	 * @var array<string,int>
	 */
	const BATCH_SIZE = array(
		'scan'    => 500,
		'convert' => 40,
	);

	/**
	 * @var WWG_Generator
	 */
	private $generator;

	/**
	 * @param WWG_Generator $generator Shared conversion logic.
	 */
	public function __construct( WWG_Generator $generator ) {
		$this->generator = $generator;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
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
	 * Add the Tools submenu page.
	 */
	public function register_page() {
		add_management_page(
			__( 'WebP Generator', 'wp-webp-generator' ),
			__( 'WebP Generator', 'wp-webp-generator' ),
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'strings' => array(
					'confirmGenerate' => __( 'Generate .webp versions of these images now? This writes new files alongside the originals -- nothing existing gets deleted or replaced.', 'wp-webp-generator' ),
					/* translators: %s: folder path currently being scanned, e.g. "2024/03". Substituted client-side in admin.js. */
					'checking'        => __( 'Checking %s…', 'wp-webp-generator' ),
					/* translators: %s: folder path currently being processed, e.g. "2024/03". Substituted client-side in admin.js. */
					'converting'      => __( 'Generating %s…', 'wp-webp-generator' ),
					'uploadsRoot'     => __( 'the uploads folder', 'wp-webp-generator' ),
					/* translators: %d: number of images scanned so far. */
					'scannedSoFar'    => __( '%d images scanned so far…', 'wp-webp-generator' ),
					/* translators: %d: number of images generated so far. */
					'generatedSoFar'  => __( '%d images generated so far…', 'wp-webp-generator' ),
					'missingNone'     => __( 'Every image already has a .webp version. Nothing to generate.', 'wp-webp-generator' ),
					/* translators: 1: number of images (always > 1), 2: combined file size, e.g. "3.2 MB". */
					'missingPlural'   => __( '%1$d images are missing a .webp version (%2$s).', 'wp-webp-generator' ),
					/* translators: 1: combined file size, e.g. "420 KB". */
					'missingSingular' => __( '1 image is missing a .webp version (%2$s).', 'wp-webp-generator' ),
					/* translators: %d: number of images. */
					'generateDone'    => __( 'Done -- generated %d image(s).', 'wp-webp-generator' ),
					'cacheCleared'    => __( 'Also cleared the page cache so these take effect right away.', 'wp-webp-generator' ),
					'paused'          => __( 'Paused. Click "Generate" to pick up where this left off.', 'wp-webp-generator' ),
					'vsOriginal'      => __( 'vs.', 'wp-webp-generator' ),
					'folders'         => __( 'folders', 'wp-webp-generator' ),
					'error'           => __( 'Something went wrong:', 'wp-webp-generator' ),
				),
			)
		);
	}

	/**
	 * Render the page shell -- the setup/status panel and settings form
	 * render server-side here; the scan/convert UI below them does its
	 * actual work via AJAX, driven from assets/admin.js.
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
	 * AJAX handler for both the scan and convert passes. Processes one
	 * bounded batch of files from one folder under the uploads directory
	 * and reports back a cursor to resume from, so the client can loop
	 * this until done without any single request risking a timeout.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'wp-webp-generator' ) ), 403 );
		}

		$mode        = ( isset( $_POST['mode'] ) && 'convert' === $_POST['mode'] ) ? 'convert' : 'scan';
		$dir_index   = isset( $_POST['dir_index'] ) ? absint( $_POST['dir_index'] ) : 0;
		$file_offset = isset( $_POST['file_offset'] ) ? absint( $_POST['file_offset'] ) : 0;

		// Fresh run starting: reset the running tally this AJAX loop
		// accumulates across its many stateless requests.
		if ( 'convert' === $mode && 0 === $dir_index && 0 === $file_offset ) {
			set_transient( self::CONVERT_RUN_TALLY_KEY, 0, HOUR_IN_SECONDS );
		}

		$dirs   = $this->get_scan_directories();
		$result = $this->process_batch( $dirs, $dir_index, $file_offset, $mode );

		$cache_cleared = false;

		if ( 'convert' === $mode ) {
			if ( $result['stats']['converted'] > 0 ) {
				$tally = (int) get_transient( self::CONVERT_RUN_TALLY_KEY );
				set_transient( self::CONVERT_RUN_TALLY_KEY, $tally + $result['stats']['converted'], HOUR_IN_SECONDS );
			}

			if ( $result['done'] ) {
				$total_converted = (int) get_transient( self::CONVERT_RUN_TALLY_KEY );
				delete_transient( self::CONVERT_RUN_TALLY_KEY );

				// Only worth clearing anything if this run actually
				// created new files -- a scan-only pass, or a convert
				// run that found nothing left to do, has nothing to
				// invalidate.
				if ( $total_converted > 0 ) {
					WWG_Cache::clear_all();
					$cache_cleared = true;
				}
			}
		}

		wp_send_json_success(
			array(
				'done'          => $result['done'],
				'dir'           => $result['dir'],
				'dir_index'     => $result['dir_index'],
				'file_offset'   => $result['file_offset'],
				'total_dirs'    => count( $dirs ),
				'stats'         => $result['stats'],
				'cache_cleared' => $cache_cleared,
			)
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
		);

		if ( $dir_index >= count( $dirs ) ) {
			return array(
				'stats'       => $stats,
				'dir'         => '',
				'dir_index'   => $dir_index,
				'file_offset' => 0,
				'done'        => true,
			);
		}

		$dir_rel    = $dirs[ $dir_index ];
		$upload_dir = wp_get_upload_dir();
		$abs_dir    = '' === $dir_rel
			? $upload_dir['basedir']
			: trailingslashit( $upload_dir['basedir'] ) . $dir_rel;

		$files = $this->list_images_in_dir( $abs_dir );
		$slice = array_slice( $files, $file_offset, self::BATCH_SIZE[ $mode ] );

		foreach ( $slice as $filename ) {
			++$stats['scanned'];
			$source_path = $abs_dir . '/' . $filename;

			if ( 'convert' === $mode ) {
				$outcome = $this->generator->ensure_webp( $source_path );

				if ( 'exists' === $outcome['status'] ) {
					continue;
				}

				++$stats['missing'];
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish or become unreadable between the directory listing above and this stat() call; (int) cast already turns a false return into a harmless 0.
				$stats['original_bytes'] += (int) @filesize( $source_path );

				if ( 'created' === $outcome['status'] ) {
					++$stats['converted'];
					$stats['webp_bytes'] += $outcome['webp_bytes'];
				} else {
					++$stats['failed'];
				}
			} else {
				$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );
				if ( file_exists( $webp_path ) ) {
					continue;
				}
				++$stats['missing'];
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can legitimately vanish or become unreadable between the directory listing above and this stat() call; (int) cast already turns a false return into a harmless 0.
				$stats['original_bytes'] += (int) @filesize( $source_path );
			}
		}

		$new_offset = $file_offset + count( $slice );
		$dir_done   = $new_offset >= count( $files );

		return array(
			'stats'       => $stats,
			'dir'         => $dir_rel,
			'dir_index'   => $dir_done ? $dir_index + 1 : $dir_index,
			'file_offset' => $dir_done ? 0 : $new_offset,
			'done'        => false,
		);
	}
}
