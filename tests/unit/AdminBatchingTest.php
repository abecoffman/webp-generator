<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Counts real ensure_format() calls without changing their (real GD/Imagick)
 * behavior -- used by the known-failure-shortcut tests below to prove a
 * skip really skipped the expensive path, without relying on timing.
 */
class WWG_Generator_Call_Counting_Fake extends \WWG_Generator {
	/**
	 * @var int
	 */
	public $calls = 0;

	/**
	 * @param string $source_path Absolute path to a .jpg/.jpeg/.png file.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return array
	 */
	public function ensure_format( $source_path, $format ) {
		++$this->calls;
		return parent::ensure_format( $source_path, $format );
	}
}

/**
 * A fully-controlled fake for the handful of tests below that need two
 * formats to diverge (one succeeds, one fails) -- deliberately not real
 * GD/Imagick output, since faking a genuinely successful conversion needs
 * real image bytes (out of this lightweight tier's scope, same as
 * everywhere else in this file); this only needs to prove process_batch()
 * dispatches and tallies each format's outcome independently, which
 * doesn't require real bytes at all.
 */
class WWG_Generator_Format_Divergent_Fake extends \WWG_Generator {
	/**
	 * @param string $source_path Absolute path to a .jpg/.jpeg/.png file.
	 * @param string $format      One of WWG_Format::WEBP/WWG_Format::AVIF.
	 * @return array
	 */
	public function ensure_format( $source_path, $format ) {
		if ( \WWG_Format::WEBP === $format ) {
			// A real ensure_format() always writes the file it reports
			// 'created' for -- process_batch()'s own file_exists() check
			// (run before ever calling this) is what makes a second pass
			// correctly treat webp as already-done instead of re-
			// attempting it, so this fake must actually write something
			// too, or that check would never see it.
			file_put_contents( \WWG_Format::path_for( \WWG_Format::WEBP, $source_path ), 'fake webp bytes' );
			return array(
				'status' => 'created',
				'bytes'  => 500,
			);
		}
		return array(
			'status' => 'failed',
			'error'  => 'fake avif failure',
		);
	}
}

/**
 * Exercises WWG_Admin's directory-batching (the resumable
 * (dir_index, file_offset) cursor the Scan/Generate AJAX loop depends
 * on) against a real filesystem fixture rather than mocking
 * DirectoryIterator -- more honest, and it's what actually matters:
 * the real behavior of walking real folders, not whether it calls a
 * particular filesystem API.
 *
 * Scan mode just checks file_exists(), and convert mode's real GD/Imagick
 * backend is exercised too where it's cheap to do so honestly -- but only
 * on the *failure* path (garbage bytes that any real decoder rejects,
 * needing no successful decode at all). A genuinely successful conversion
 * needs real image bytes and is out of scope for this lightweight tier;
 * that path was verified manually via wp media import against the real
 * site (see the project's memory notes) and is covered end-to-end by
 * tests/integration/GeneratorIntegrationTest.php.
 *
 * @covers \WWG_Admin
 */
class AdminBatchingTest extends TestCase {

	/**
	 * @var string
	 */
	private $fixture_dir;

	/**
	 * Fake backing store for get_option()/update_option(), keyed exactly
	 * like the real options API -- required once process_batch()
	 * unconditionally consults the OPTION_CLEAN_DIRS cache. Mirrors the
	 * fake transient store already built for tests/unit/JobTest.php.
	 *
	 * @var array
	 */
	private $options;

	protected function set_up() {
		parent::set_up();

		$this->fixture_dir = sys_get_temp_dir() . '/wwg-test-' . uniqid();
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		mkdir( $this->fixture_dir . '/2024/02', 0777, true );
		mkdir( $this->fixture_dir . '/2024/03', 0777, true );

		// 3 images missing a .webp sibling, 1 that already has one.
		touch( $this->fixture_dir . '/2024/01/a.jpg' );
		touch( $this->fixture_dir . '/2024/01/b.jpg' );
		touch( $this->fixture_dir . '/2024/02/c.jpg' );
		touch( $this->fixture_dir . '/2024/02/d.jpg' );
		touch( $this->fixture_dir . '/2024/02/d.webp' );

		// A folder that's already fully clean -- the "known clean" cache
		// tests below use this one.
		touch( $this->fixture_dir . '/2024/03/e.jpg' );
		touch( $this->fixture_dir . '/2024/03/e.webp' );

		$this->options = array();

		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'basedir' => $this->fixture_dir ) );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
		// Only needed for the convert-mode failure test below --
		// WWG_Generator's failure paths run their message through __().
		Functions\when( '__' )->returnArg();
		// Only needed for the convert-mode failure test below (GD's path
		// in WWG_Generator::convert_with_gd() calls this to tell jpg from
		// png) -- a minimal stand-in for the real extension-to-mime map.
		Functions\when( 'wp_check_filetype' )->alias(
			function ( $filename ) {
				$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = array(
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png'  => 'image/png',
				);
				return array(
					'ext'  => $ext,
					'type' => isset( $map[ $ext ] ) ? $map[ $ext ] : false,
				);
			}
		);
		// Locks WWG_Format::enabled() to webp-only for every test in this
		// file by default -- this test machine's real GD genuinely
		// supports both webp and avif (see FormatTest.php), so without
		// this every test here would suddenly process two formats per
		// file instead of one. This is deliberate, not a workaround: per
		// the plan, the rewritten process_batch() must first prove it
		// still passes every one of these existing single-format cases
		// unchanged (simulating an AVIF-unsupported environment) before
		// the multi-format-specific tests further down (which override
		// this per-test) are trusted. Also covers the convert-mode
		// failure tests' need for a pass-through wwg_attempt_recovery --
		// once both normal decode attempts fail, ensure_format() also
		// runs the embedded-data recovery attempt, which consults it.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'webp' ) : $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( isset( $this->options[ $key ] ) ) {
					return $this->options[ $key ];
				}
				// wwg_scan_state defaults to "an established site" here --
				// WWG_Format::enabled() now consults it (see
				// user_wants()'s fresh-install-only smart default), and
				// this file's own known-failures cache tests reset
				// $this->options mid-test (see the "Reset the cache..."
				// comment further down), which would otherwise flip these
				// tests to a fresh-install default they were never
				// written to expect.
				return 'wwg_scan_state' === $key ? array( 'finished_at' => 1000 ) : $default;
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
		$this->remove_recursive( $this->fixture_dir );
		parent::tear_down();
	}

	private function remove_recursive( $dir ) {
		if ( is_link( $dir ) ) {
			// Removed as itself, never followed -- the symlinked-directory
			// test below points one back at a real fixture folder still
			// needing its own real cleanup; recursing through the link
			// would delete that folder's contents out from under it.
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_link( $path ) || ! is_dir( $path ) ? unlink( $path ) : $this->remove_recursive( $path );
		}
		rmdir( $dir );
	}

	private function process_batch( $dirs, $dir_index, $file_offset, $mode, $generator = null ) {
		$admin = new \WWG_Admin( $generator ?: new \WWG_Generator() );
		$ref = new \ReflectionMethod( $admin, 'process_batch' );
		// setAccessible() is a no-op (and deprecated) as of PHP 8.1+, but
		// still required on the 7.4/8.0 the CI matrix also covers --
		// leave it in despite the 8.5 deprecation notice this triggers.
		$ref->setAccessible( true );

		return $ref->invoke( $admin, $dirs, $dir_index, $file_offset, $mode );
	}

	public function test_scan_counts_missing_webp_files_across_the_whole_fixture() {
		$dirs = array( '2024/01', '2024/02' );

		$dir_index   = 0;
		$file_offset = 0;
		$scanned     = 0;
		$missing     = 0;
		$iterations  = 0;

		do {
			$result = $this->process_batch( $dirs, $dir_index, $file_offset, 'scan' );

			$scanned += $result['stats']['scanned'];
			$missing += $result['stats']['missing'];
			$dir_index   = $result['dir_index'];
			$file_offset = $result['file_offset'];

			// Guard against an infinite loop if the cursor logic regresses.
			$this->assertLessThan( 20, ++$iterations, 'process_batch never reached done=true.' );
		} while ( ! $result['done'] );

		$this->assertSame( 4, $scanned ); // a, b, c, d -- not d's already-existing sibling.
		$this->assertSame( 3, $missing ); // a, b, c are missing; d already has a .webp.
	}

	public function test_dir_index_advances_to_the_next_folder_only_once_the_current_one_is_exhausted() {
		$dirs = array( '2024/01', '2024/02' );

		// 2024/01 has exactly 2 files; asking for a huge batch size isn't
		// possible directly (BATCH_SIZE is a class constant), but with a
		// batch this small relative to a 2-file folder, the cursor should
		// still land on dir_index 1 (not 0) after processing both files
		// in one call, since scan's real batch size (500) comfortably
		// covers 2 files in a single request.
		$result = $this->process_batch( $dirs, 0, 0, 'scan' );

		$this->assertSame( 1, $result['dir_index'] );
		$this->assertSame( 0, $result['file_offset'] );
		$this->assertSame( '2024/01', $result['dir'] );
		$this->assertFalse( $result['done'] );
	}

	public function test_done_is_true_once_every_folder_is_exhausted() {
		$dirs = array( '2024/01' );

		// dir_index already past the end of a 1-folder list.
		$result = $this->process_batch( $dirs, 1, 0, 'scan' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $result['stats']['scanned'] );
	}

	public function test_empty_dirs_list_processes_the_uploads_root_itself() {
		// '' represents the uploads root -- confirms the "flat uploads
		// structure" fix (a real bug fixed this session) still resolves
		// correctly to the fixture's own basedir, not a "basedir/" typo
		// that would silently scan nothing.
		touch( $this->fixture_dir . '/root-image.jpg' );

		$result = $this->process_batch( array( '' ), 0, 0, 'scan' );

		$this->assertSame( 1, $result['stats']['scanned'] );
		$this->assertSame( 1, $result['stats']['missing'] );
	}

	/**
	 * run_job_batch() is the public wrapper WWG_Job::run_tick() calls
	 * each cron tick -- unlike process_batch() above, it's public, so no
	 * Reflection is needed to reach it directly.
	 */
	public function test_run_job_batch_composes_get_scan_directories_and_process_batch() {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' );
			}
		);

		$admin = new \WWG_Admin( new \WWG_Generator() );

		$result = $admin->run_job_batch( 0, 0, 'scan' );

		// The fixture tree (see set_up()) has the uploads root itself
		// plus 2024, 2024/01, 2024/02 -- get_scan_directories() walking
		// it for real is what total_dirs here is actually proving.
		$this->assertArrayHasKey( 'total_dirs', $result );
		$this->assertGreaterThanOrEqual( 4, $result['total_dirs'] );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertArrayHasKey( 'done', $result );
		$this->assertFalse( $result['done'] );
	}

	private function scan_directories() {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' );
			}
		);

		$admin = new \WWG_Admin( new \WWG_Generator() );
		$ref   = new \ReflectionMethod( $admin, 'get_scan_directories' );
		$ref->setAccessible( true );

		return $ref->invoke( $admin );
	}

	/**
	 * Proves collect_subdirectories()'s recursion actually descends more
	 * than one level -- the fixture's own 2024/01 etc. never exercised
	 * this, since those are already only one level under the root.
	 *
	 * @covers \WWG_Admin::collect_subdirectories
	 */
	public function test_get_scan_directories_finds_a_deeply_nested_subfolder() {
		mkdir( $this->fixture_dir . '/2024/01/edits/final', 0777, true );

		$dirs = $this->scan_directories();

		$this->assertContains( '2024/01/edits', $dirs );
		$this->assertContains( '2024/01/edits/final', $dirs );
	}

	/**
	 * Same symlink-loop guard the RecursiveDirectoryIterator this method
	 * used to be built on had (isLink() alongside isDir()) -- confirms
	 * collect_subdirectories()'s own is_link() check still excludes a
	 * symlinked directory (e.g. one pointing back at an ancestor, which
	 * would recurse forever without this) rather than just trusting that
	 * glob()'s own GLOB_ONLYDIR already rules it out (it doesn't -- a
	 * symlink to a directory is itself reported as a directory).
	 */
	public function test_get_scan_directories_ignores_a_symlinked_directory() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform.' );
		}
		symlink( $this->fixture_dir . '/2024', $this->fixture_dir . '/2024-link' );

		$dirs = $this->scan_directories();

		$this->assertNotContains( '2024-link', $dirs );
	}

	// ---- The "known clean" folder cache (OPTION_CLEAN_DIRS) ----

	public function test_a_fully_clean_folder_gets_cached_and_a_second_pass_skips_real_work() {
		$dirs = array( '2024/03' );

		$first = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertSame( 1, $first['stats']['scanned'] ); // real work happened.
		$this->assertSame( 0, $first['stats']['missing'] );
		$this->assertSame( 1, $first['dir_index'] );
		$this->assertFalse( $first['skipped'] );
		$this->assertArrayHasKey( '2024/03', $this->options[ \WWG_Admin::OPTION_CLEAN_DIRS ] );

		$second = $this->process_batch( $dirs, 0, 0, 'scan' ); // a fresh run over the same folder.
		// No real per-file work happened (no filesize()/file_exists()
		// calls) -- but this folder's own remembered total (1, cached by
		// the first pass) still contributes to the whole-library figures
		// Library Status reads, rather than a skipped folder silently
		// contributing nothing. See OPTION_CLEAN_DIRS's own docblock.
		$this->assertSame( 1, $second['stats']['scanned'] );
		$this->assertSame( 1, $second['dir_index'] );
		$this->assertTrue( $second['skipped'] );
	}

	public function test_a_cache_hit_contributes_its_remembered_byte_totals_too() {
		$dir = $this->fixture_dir . '/2024/15';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/f.jpg', str_repeat( 'a', 1000 ) );
		file_put_contents( $dir . '/f.webp', str_repeat( 'b', 400 ) );

		$dirs = array( '2024/15' );

		$first = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertSame( 1000, $first['stats']['original_bytes_total'] );
		$this->assertSame( 1000, $first['stats']['webp_original_bytes'] );
		$this->assertSame( 400, $first['stats']['webp_bytes'] );
		$this->assertSame( 1, $first['stats']['webp_present'] );
		$this->assertArrayHasKey( '2024/15', $this->clean_dirs() );

		$second = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertTrue( $second['skipped'] );
		// The folder's own remembered byte/count totals (cached by the
		// first pass) still contribute, not just its file count -- this
		// is the whole point of extending the cache: Library Status's
		// Original/WebP/AVIF table must stay accurate even once a stable
		// library is mostly cache-skipped on every later scan.
		$this->assertSame( 1000, $second['stats']['original_bytes_total'] );
		$this->assertSame( 1000, $second['stats']['webp_original_bytes'] );
		$this->assertSame( 400, $second['stats']['webp_bytes'] );
		$this->assertSame( 1, $second['stats']['webp_present'] );
	}

	public function test_a_pre_totals_cache_entry_forces_one_real_rewalk_then_becomes_a_true_hit() {
		$dir = $this->fixture_dir . '/2024/03'; // already-clean fixture folder (e.jpg/e.webp).

		// Simulate an entry written before whole-library totals existed
		// at all -- the exact shape mark_known_clean() used to write,
		// with no `totals` key.
		$this->options[ \WWG_Admin::OPTION_CLEAN_DIRS ] = array(
			'2024/03' => array(
				'mtime'   => filemtime( $dir ),
				'formats' => array( 'webp' ),
			),
		);

		$dirs = array( '2024/03' );

		// The entry would otherwise validate (mtime and formats both
		// match) -- but having no `totals` key must still force one real
		// walk, so this folder's contribution is backfilled instead of
		// silently excluded forever.
		$backfill = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertFalse( $backfill['skipped'] );
		$this->assertSame( 1, $backfill['stats']['scanned'] );
		$this->assertArrayHasKey( 'totals', $this->clean_dirs()['2024/03'] );

		// Every check after that backfill is a true, fully-accurate hit.
		$hit = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertTrue( $hit['skipped'] );
		$this->assertSame( 1, $hit['stats']['scanned'] );
	}

	public function test_a_partial_totals_cache_entry_also_forces_one_real_rewalk() {
		$dir = $this->fixture_dir . '/2024/03'; // already-clean fixture folder (e.jpg/e.webp).

		// Simulate an entry written after whole-library totals existed,
		// but before the Original/WebP/AVIF table's own *_present counts
		// did -- a `totals` key that's present but incomplete, not
		// absent. Must be treated the same as a fully-missing `totals`:
		// force one real walk to backfill the two new fields, rather
		// than silently treating this as a complete hit forever.
		$this->options[ \WWG_Admin::OPTION_CLEAN_DIRS ] = array(
			'2024/03' => array(
				'mtime'   => filemtime( $dir ),
				'formats' => array( 'webp' ),
				'totals'  => array(
					'scanned'             => 1,
					'webp_bytes'          => 0,
					'avif_bytes'          => 0,
					'webp_original_bytes' => 0,
					'avif_original_bytes' => 0,
					// No 'webp_present'/'avif_present'/'original_bytes_total' --
					// the exact intermediate shape this test is about.
				),
			),
		);

		$dirs = array( '2024/03' );

		$backfill = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertFalse( $backfill['skipped'] );
		$this->assertArrayHasKey( 'webp_present', $this->clean_dirs()['2024/03']['totals'] );

		$hit = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertTrue( $hit['skipped'] );
		$this->assertSame( 1, $hit['stats']['webp_present'] );
	}

	public function test_a_directory_mtime_change_forces_a_real_recheck() {
		$dir = $this->fixture_dir . '/2024/03';
		touch( $dir, time() - 100 ); // explicit, deterministic baseline mtime.
		clearstatcache();

		$dirs = array( '2024/03' );
		$this->process_batch( $dirs, 0, 0, 'scan' ); // caches it clean.

		$cached = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertTrue( $cached['skipped'] ); // confirms the cache hit.

		// Directly setting the directory's own mtime is the deterministic
		// way to simulate "a file was added/removed" (what actually
		// changes a directory's mtime on real filesystems) without
		// relying on wall-clock timing or filesystem mtime resolution.
		touch( $dir, time() );
		clearstatcache();

		$rechecked = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertFalse( $rechecked['skipped'] ); // real recheck happened.
		$this->assertSame( 1, $rechecked['stats']['scanned'] );
	}

	public function test_a_folder_with_a_missing_file_is_never_cached_as_clean() {
		$dirs   = array( '2024/01' ); // a.jpg, b.jpg both missing their .webp.
		$result = $this->process_batch( $dirs, 0, 0, 'scan' );

		$this->assertSame( 2, $result['stats']['missing'] );
		$this->assertArrayNotHasKey( '2024/01', $this->clean_dirs() );

		$again = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertFalse( $again['skipped'] );
		$this->assertSame( 2, $again['stats']['scanned'] ); // real recheck, not a skip.
	}

	public function test_a_folder_with_a_real_conversion_failure_is_never_cached() {
		$dir = $this->fixture_dir . '/2024/04';
		mkdir( $dir, 0777, true );
		// Garbage bytes with a .jpg extension -- not a decodable image, so
		// ensure_webp() genuinely fails via GD/Imagick without needing any
		// real image data (safely within this file's existing scope note
		// that real conversion needs a real backend: this exercises the
		// *failure* path, which needs no successful decode at all).
		file_put_contents( $dir . '/broken.jpg', 'not a real jpeg' );

		$dirs   = array( '2024/04' );
		$result = $this->process_batch( $dirs, 0, 0, 'convert' );

		$this->assertSame( 1, $result['stats']['failed'] );
		$this->assertSame( 1, $result['stats']['missing'] );
		$this->assertSame( 0, $result['stats']['converted'] );
		// missing !== converted -- the refined caching condition -- is
		// what must block this, directly relevant given real permanently-
		// corrupt files are exactly what this cache must never paper over.
		$this->assertArrayNotHasKey( '2024/04', $this->clean_dirs() );
	}

	public function test_a_folder_needing_more_real_conversions_than_the_cap_is_never_cached_in_one_pass() {
		$dir = $this->fixture_dir . '/2024/06';
		mkdir( $dir, 0777, true );
		for ( $i = 0; $i < 41; $i++ ) { // one more than MAX_CONVERSIONS_PER_BATCH.
			// Garbage bytes -- each needs a real (failing) conversion
			// attempt, unlike already-`.webp`'d files, which never touch
			// MAX_CONVERSIONS_PER_BATCH at all (see the "large already
			// clean folder" test below for that case).
			file_put_contents( $dir . "/img{$i}.jpg", 'not a real jpeg' );
		}

		$dirs  = array( '2024/06' );
		$first = $this->process_batch( $dirs, 0, 0, 'convert' );

		$this->assertSame( 40, $first['stats']['scanned'] ); // capped, not all 41.
		$this->assertSame( 40, $first['stats']['failed'] );
		$this->assertSame( 0, $first['dir_index'] ); // folder not yet exhausted.
		$this->assertSame( 40, $first['file_offset'] );
		$this->assertArrayNotHasKey( '2024/06', $this->clean_dirs() );

		$second = $this->process_batch( $dirs, 0, 40, 'convert' ); // finishes the folder.
		$this->assertSame( 1, $second['stats']['failed'] );
		$this->assertSame( 1, $second['dir_index'] ); // folder now exhausted.
		// Still never cached -- both because this finishing call didn't
		// start at file_offset 0 (the compound condition), and because
		// every file in it genuinely failed, so it must never be cached
		// regardless of batch boundaries.
		$this->assertArrayNotHasKey( '2024/06', $this->clean_dirs() );
	}

	/**
	 * The actual point of splitting BATCH_SIZE from
	 * MAX_CONVERSIONS_PER_BATCH: an already-clean folder is cheap
	 * (file_exists() only) regardless of mode, so neither mode should
	 * need more than one batch to fully verify (and so cache) it now,
	 * even comfortably past the old 500/40 per-mode limits.
	 */
	public function test_a_large_already_clean_folder_fits_in_one_batch_and_gets_cached_in_either_mode() {
		$dir = $this->fixture_dir . '/2024/07';
		mkdir( $dir, 0777, true );
		for ( $i = 0; $i < 600; $i++ ) {
			touch( $dir . "/img{$i}.jpg" );
			touch( $dir . "/img{$i}.webp" );
		}

		$dirs = array( '2024/07' );

		$scan_result = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertSame( 600, $scan_result['stats']['scanned'] );
		$this->assertSame( 1, $scan_result['dir_index'] );
		$this->assertArrayHasKey( '2024/07', $this->clean_dirs() );

		// Reset the cache to prove convert mode independently achieves
		// the same single-batch verification, without needing scan to
		// have gone first.
		$this->options = array();

		$convert_result = $this->process_batch( $dirs, 0, 0, 'convert' );
		$this->assertSame( 600, $convert_result['stats']['scanned'] );
		$this->assertSame( 1, $convert_result['dir_index'] );
		$this->assertArrayHasKey( '2024/07', $this->clean_dirs() );
	}

	// ---- The known-failure cache (OPTION_KNOWN_FAILURES) ----

	public function test_a_known_stable_failure_is_skipped_on_a_second_pass() {
		$dir = $this->fixture_dir . '/2024/08';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/broken.jpg', 'not a real jpeg' );

		$dirs      = array( '2024/08' );
		$generator = new \WWG\Tests\Unit\WWG_Generator_Call_Counting_Fake();

		$first = $this->process_batch( $dirs, 0, 0, 'convert', $generator );
		$this->assertSame( 1, $generator->calls ); // a real decode was attempted.
		$this->assertSame( 1, $first['stats']['failed'] );
		$this->assertSame( '2024/08/broken.jpg', $first['stats']['failures'][0]['file'] );
		$first_error = $first['stats']['failures'][0]['error'];

		$second = $this->process_batch( $dirs, 0, 0, 'convert', $generator );
		$this->assertSame( 1, $generator->calls ); // unchanged -- no second decode attempt.
		$this->assertSame( 1, $second['stats']['failed'] );
		// Same remembered error, not a freshly re-evaluated one.
		$this->assertSame( $first_error, $second['stats']['failures'][0]['error'] );
	}

	public function test_a_file_fingerprint_change_forces_a_real_retry() {
		$dir  = $this->fixture_dir . '/2024/09';
		mkdir( $dir, 0777, true );
		$path = $dir . '/broken.jpg';
		file_put_contents( $path, 'not a real jpeg' );
		touch( $path, time() - 1000 ); // explicit, deterministic baseline mtime.
		clearstatcache();

		$dirs      = array( '2024/09' );
		$generator = new \WWG\Tests\Unit\WWG_Generator_Call_Counting_Fake();

		$this->process_batch( $dirs, 0, 0, 'convert', $generator );
		$this->assertSame( 1, $generator->calls );

		// Simulate the file genuinely changing -- different bytes, and an
		// explicit later mtime rather than relying on wall-clock timing or
		// filesystem mtime resolution.
		file_put_contents( $path, 'still not a real jpeg, but different length' );
		touch( $path, time() + 1000 );
		clearstatcache();

		$this->process_batch( $dirs, 0, 0, 'convert', $generator );
		$this->assertSame( 2, $generator->calls ); // stale fingerprint correctly forced a real re-attempt.
	}

	public function test_a_known_failure_shortcut_does_not_count_against_the_conversion_cap() {
		$dir = $this->fixture_dir . '/2024/10';
		mkdir( $dir, 0777, true );

		// Establish one known-stable failure first.
		file_put_contents( $dir . '/0-known.jpg', 'not a real jpeg' );
		$dirs = array( '2024/10' );
		$this->process_batch( $dirs, 0, 0, 'convert' ); // caches 0-known.jpg as a known failure.

		// Add exactly MAX_CONVERSIONS_PER_BATCH more files, named to sort
		// after the known one, each needing a real (failing) attempt.
		for ( $i = 0; $i < \WWG_Admin::MAX_CONVERSIONS_PER_BATCH; $i++ ) {
			file_put_contents( $dir . '/' . sprintf( '1-fresh%02d.jpg', $i ), 'not a real jpeg' );
		}

		$result = $this->process_batch( $dirs, 0, 0, 'convert' );

		// If the shortcut hit had wrongly consumed cap budget, only 39 of
		// the 40 fresh files would fit in this one call, leaving the
		// folder unfinished (dir_index still 0).
		$this->assertSame( 41, $result['stats']['scanned'] );
		$this->assertSame( 41, $result['stats']['failed'] );
		$this->assertSame( 1, $result['dir_index'] );
	}

	// ---- OPTION_CLEAN_DIRS + OPTION_KNOWN_FAILURES interaction ----
	//
	// A folder isn't excluded from the folder-level clean cache just
	// because it contains a known failure -- only while that failure is
	// still fresh (not yet reconfirmed stable). Once it has survived one
	// full pass via the shortcut, the folder can cache "clean except this
	// known failure" -- but the cache must still (a) surface that failure
	// every run even while skipping the rest of the folder, and (b) never
	// let an in-place edit to that one file hide behind the folder-level
	// skip the way the directory's own mtime alone would.

	public function test_a_folder_where_the_only_missing_item_is_a_reconfirmed_known_failure_gets_cached_and_still_reports_it_when_skipped() {
		$dir = $this->fixture_dir . '/2024/11';
		mkdir( $dir, 0777, true );
		touch( $dir . '/x.jpg' );
		touch( $dir . '/x.webp' );
		touch( $dir . '/y.jpg' );
		touch( $dir . '/y.webp' );
		file_put_contents( $dir . '/broken.jpg', 'not a real jpeg' );

		$dirs = array( '2024/11' );

		$first = $this->process_batch( $dirs, 0, 0, 'convert' );
		$this->assertSame( 1, $first['stats']['failed'] );
		$this->assertArrayNotHasKey( '2024/11', $this->clean_dirs() ); // not yet -- first-time failure, not proven stable.

		$second = $this->process_batch( $dirs, 0, 0, 'convert' );
		$this->assertSame( 1, $second['stats']['failed'] ); // still reported.
		$this->assertArrayHasKey( '2024/11', $this->clean_dirs() ); // now reconfirmed stable -- folder-level cache unlocked.

		$third = $this->process_batch( $dirs, 0, 0, 'convert' );
		$this->assertTrue( $third['skipped'] );
		// x.jpg/y.jpg/broken.jpg were never re-walked this pass -- but the
		// folder's own remembered total (3, cached by the second pass'
		// real walk) still contributes to the whole-library figures,
		// rather than a skipped folder contributing nothing.
		$this->assertSame( 3, $third['stats']['scanned'] );
		$this->assertSame( 1, $third['stats']['failed'] ); // ...and the known failure is still surfaced too, not silently dropped.
		$this->assertSame( '2024/11/broken.jpg', $third['stats']['failures'][0]['file'] );
	}

	public function test_editing_a_cached_known_failure_file_in_place_is_still_detected_even_though_the_folder_is_cached() {
		$dir  = $this->fixture_dir . '/2024/12';
		mkdir( $dir, 0777, true );
		$path = $dir . '/broken.jpg';
		file_put_contents( $path, 'not a real jpeg' );

		$dirs = array( '2024/12' );

		$this->process_batch( $dirs, 0, 0, 'convert' ); // fresh failure.
		$this->process_batch( $dirs, 0, 0, 'convert' ); // reconfirmed -- folder now cached.
		$this->assertArrayHasKey( '2024/12', $this->clean_dirs() );

		$dir_mtime_before = filemtime( $dir );

		// Simulate fixing the file in place -- same filename, new bytes
		// and mtime -- without touching the directory's own mtime. This is
		// exactly the scenario a directory-mtime-only check can't catch:
		// on a real filesystem, overwriting an existing file's content
		// doesn't change its parent directory's entry list, so the
		// directory's own mtime is untouched by this.
		file_put_contents( $path, 'still not real, but a different length now' );
		touch( $path, time() + 1000 );
		clearstatcache();
		$this->assertSame( $dir_mtime_before, filemtime( $dir ), 'Precondition: editing the file must not have touched the directory mtime, or this test proves nothing.' );

		$rechecked = $this->process_batch( $dirs, 0, 0, 'convert' );
		$this->assertFalse( $rechecked['skipped'] ); // the folder-level cache correctly wasn't trusted blindly.
		$this->assertSame( 1, $rechecked['stats']['scanned'] ); // a real recheck happened, not a silent skip.
	}

	// ---- Scan mode also knows about known failures (not just convert) ----
	//
	// Scan mode never attempts a real decode, so it can only ever
	// *re-surface* an already-discovered failure (established by an
	// earlier convert pass), not discover a new one -- but once one
	// exists, scan should report it exactly like convert does, not lump
	// it into an undifferentiated "missing" count.

	public function test_scan_mode_surfaces_a_known_failure_the_same_way_convert_does() {
		$dir = $this->fixture_dir . '/2024/13';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/broken.jpg', 'not a real jpeg' );

		$dirs = array( '2024/13' );

		// Establish the known-failure record via a real (convert) pass
		// first -- see the note above.
		$this->process_batch( $dirs, 0, 0, 'convert' );

		$scan = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertSame( 1, $scan['stats']['missing'] );
		$this->assertSame( 1, $scan['stats']['failed'] );
		$this->assertSame( '2024/13/broken.jpg', $scan['stats']['failures'][0]['file'] );
	}

	public function test_scan_mode_can_also_reconfirm_a_known_failure_and_unlock_the_folder_cache() {
		$dir = $this->fixture_dir . '/2024/14';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/broken.jpg', 'not a real jpeg' );

		$dirs = array( '2024/14' );

		$this->process_batch( $dirs, 0, 0, 'convert' ); // fresh failure, established.
		$this->assertArrayNotHasKey( '2024/14', $this->clean_dirs() );

		// Reconfirm via SCAN this time, not another convert pass.
		$scan = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertSame( 1, $scan['stats']['failed'] );
		$this->assertArrayHasKey( '2024/14', $this->clean_dirs() ); // scan alone was enough to unlock the folder cache.

		$skipped_scan = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertTrue( $skipped_scan['skipped'] );
		// The folder's own remembered total (1, cached by the reconfirming
		// scan just above) still contributes, same reasoning as the other
		// cache-hit tests in this file.
		$this->assertSame( 1, $skipped_scan['stats']['scanned'] );
		$this->assertSame( 1, $skipped_scan['stats']['failed'] ); // still reported, even though skipped.
	}

	// ---- Multi-format (AVIF alongside WebP) ----
	//
	// Everything above locks WWG_Format::enabled() to webp-only via
	// set_up()'s apply_filters stub, matching this plugin's pre-AVIF
	// behavior exactly. These tests override that stub to enable both
	// formats, covering the parts of process_batch() that only a second
	// format exercises: per-file/per-format missing-unit counting vs. the
	// distinct-file headline count, per-format byte tracking, and a
	// folder's clean-cache correctly going stale for a format it never
	// considered before.

	/**
	 * Both formats enabled for the rest of this one test only.
	 */
	private function enable_both_formats() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'avif', 'webp' ) : $value;
			}
		);
	}

	public function test_a_file_missing_both_formats_counts_two_missing_units_but_one_missing_file() {
		$this->enable_both_formats();

		// 2024/01/a.jpg and b.jpg (see set_up()) have neither a .webp nor
		// an .avif sibling.
		$dirs   = array( '2024/01' );
		$result = $this->process_batch( $dirs, 0, 0, 'scan' );

		$this->assertSame( 4, $result['stats']['missing'] ); // 2 files x 2 formats each.
		$this->assertSame( 2, $result['stats']['missing_files'] ); // still just 2 distinct files.
	}

	public function test_a_file_already_missing_only_one_format_counts_a_single_missing_unit() {
		$this->enable_both_formats();

		$dir = $this->fixture_dir . '/2024/15';
		mkdir( $dir, 0777, true );
		touch( $dir . '/only-avif-missing.jpg' );
		touch( $dir . '/only-avif-missing.webp' ); // webp already exists -- only avif is actually missing.

		$dirs   = array( '2024/15' );
		$result = $this->process_batch( $dirs, 0, 0, 'scan' );

		$this->assertSame( 1, $result['stats']['missing'] );
		$this->assertSame( 1, $result['stats']['missing_files'] );
	}

	/**
	 * Regression test for the "X vs. originals" stat tiles: a format that
	 * needed nothing at all for this file must still have its existing
	 * bytes feed the tile's live numerator/denominator, since the file
	 * was visited this run regardless of which formats it turned out to
	 * need. Without this, a format that's already fully caught up would
	 * never accumulate anything and would sit at a static "0 B vs. 0 B"
	 * for the entire run instead of visibly keeping pace as the tree is
	 * walked.
	 */
	public function test_a_file_that_already_has_one_format_still_feeds_that_formats_running_totals() {
		$this->enable_both_formats();

		$dir = $this->fixture_dir . '/2024/17';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/already-webp.jpg', str_repeat( 'x', 1000 ) );
		file_put_contents( $dir . '/already-webp.webp', str_repeat( 'y', 400 ) ); // webp already exists -- only avif is actually missing.

		$dirs   = array( '2024/17' );
		$result = $this->process_batch( $dirs, 0, 0, 'scan' );

		// Nothing was missing for webp, but the file was still walked, so
		// its already-existing .webp size feeds webp_bytes and its
		// original size feeds webp_original_bytes -- same original size
		// also feeds avif_original_bytes, since avif genuinely is missing
		// here (scan mode never creates anything, so avif_bytes stays 0).
		$this->assertSame( 400, $result['stats']['webp_bytes'] );
		$this->assertSame( 1000, $result['stats']['webp_original_bytes'] );
		$this->assertSame( 0, $result['stats']['avif_bytes'] );
		$this->assertSame( 1000, $result['stats']['avif_original_bytes'] );
	}

	public function test_converting_a_file_where_one_format_succeeds_and_the_other_fails_tallies_each_independently() {
		$this->enable_both_formats();

		$dir = $this->fixture_dir . '/2024/16';
		mkdir( $dir, 0777, true );
		touch( $dir . '/diverges.jpg' );

		$dirs      = array( '2024/16' );
		$generator = new \WWG\Tests\Unit\WWG_Generator_Format_Divergent_Fake();

		$result = $this->process_batch( $dirs, 0, 0, 'convert', $generator );

		$this->assertSame( 2, $result['stats']['missing'] );
		$this->assertSame( 1, $result['stats']['converted'] ); // webp, per the fake.
		$this->assertSame( 1, $result['stats']['failed'] ); // avif, per the fake.
		$this->assertSame( 500, $result['stats']['webp_bytes'] );
		$this->assertSame( 0, $result['stats']['avif_bytes'] );

		$this->assertCount( 1, $result['stats']['failures'] );
		$this->assertSame( 'avif', $result['stats']['failures'][0]['format'] );
		$this->assertSame( '2024/16/diverges.jpg', $result['stats']['failures'][0]['file'] );

		// The failure is real bookkeeping, not just this call's return
		// value -- a second pass should hit the known-failure shortcut
		// for avif specifically, while webp (already converted) is
		// skipped as already-existing, not re-attempted either way.
		$second = $this->process_batch( $dirs, 0, 0, 'convert', $generator );
		$this->assertSame( 1, $second['stats']['failed'] );
		$this->assertSame( 0, $second['stats']['converted'] );
		$this->assertSame( 'avif', $second['stats']['failures'][0]['format'] );
	}

	public function test_a_folder_cached_clean_under_webp_only_is_rechecked_once_avif_becomes_enabled() {
		// Cache '2024/03' (e.jpg + e.webp, see set_up()) clean under
		// today's default (webp-only) stub.
		$dirs  = array( '2024/03' );
		$first = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertArrayHasKey( '2024/03', $this->clean_dirs() );

		// Now simulate this server gaining AVIF support (or a
		// wwg_enabled_formats filter loosening) with nothing about the
		// folder's own contents having changed at all.
		$this->enable_both_formats();

		$rechecked = $this->process_batch( $dirs, 0, 0, 'scan' );
		$this->assertFalse( $rechecked['skipped'], 'A format newly enabled since this folder was cached must force a real recheck, not a stale skip.' );
		$this->assertSame( 1, $rechecked['stats']['scanned'] );
		// e.jpg is still missing only its .avif now (its .webp already
		// exists), so exactly one missing unit, not two.
		$this->assertSame( 1, $rechecked['stats']['missing'] );
	}

	/**
	 * @return array
	 */
	private function clean_dirs() {
		return isset( $this->options[ \WWG_Admin::OPTION_CLEAN_DIRS ] ) ? $this->options[ \WWG_Admin::OPTION_CLEAN_DIRS ] : array();
	}
}
