<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Exercises WWG_Admin's directory-batching (the resumable
 * (dir_index, file_offset) cursor the Scan/Generate AJAX loop depends
 * on) against a real filesystem fixture rather than mocking
 * DirectoryIterator -- more honest, and it's what actually matters:
 * the real behavior of walking real folders, not whether it calls a
 * particular filesystem API.
 *
 * Only exercises scan mode, which just checks file_exists() -- actual
 * image conversion (ensure_webp()) needs a real Imagick/GD backend and
 * real image bytes, out of scope for this lightweight tier; that path
 * was verified manually via wp media import against the real site (see
 * the project's memory notes).
 *
 * @covers \WWG_Admin
 */
class AdminBatchingTest extends TestCase {

	/**
	 * @var string
	 */
	private $fixture_dir;

	protected function set_up() {
		parent::set_up();

		$this->fixture_dir = sys_get_temp_dir() . '/wwg-test-' . uniqid();
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		mkdir( $this->fixture_dir . '/2024/02', 0777, true );

		// 3 images missing a .webp sibling, 1 that already has one.
		touch( $this->fixture_dir . '/2024/01/a.jpg' );
		touch( $this->fixture_dir . '/2024/01/b.jpg' );
		touch( $this->fixture_dir . '/2024/02/c.jpg' );
		touch( $this->fixture_dir . '/2024/02/d.jpg' );
		touch( $this->fixture_dir . '/2024/02/d.webp' );

		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'basedir' => $this->fixture_dir ) );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
	}

	protected function tear_down() {
		$this->remove_recursive( $this->fixture_dir );
		parent::tear_down();
	}

	private function remove_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_recursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function process_batch( $dirs, $dir_index, $file_offset, $mode ) {
		$admin = new \WWG_Admin( new \WWG_Generator() );
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
}
