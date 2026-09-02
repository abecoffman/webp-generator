<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * A WWG_Generator double whose ensure_formats() returns a canned outcome
 * per format instead of actually invoking Imagick/GD -- get_source_files()
 * (plain path resolution, no encoder involved) is inherited and used for
 * real. Mirrors JobTest.php's WWG_Admin_Fake_Batch: the point of these
 * tests is WWG_Admin's own bookkeeping (forget_failure()/remember_failure()/
 * cache-clearing/response shape) around a conversion attempt, not the real
 * encode/decode, which needs a real backend and real image bytes (see
 * tests/integration/ for that).
 */
class WWG_Generator_Fake_For_Column extends \WWG_Generator {

	/**
	 * format => outcome, applied to every source file passed in. Missing
	 * formats default to 'exists' (nothing to do).
	 *
	 * @var array
	 */
	public $outcomes = array();

	public function ensure_formats( $source_path, array $formats ) {
		$result = array();
		foreach ( $formats as $format ) {
			$outcome = isset( $this->outcomes[ $format ] ) ? $this->outcomes[ $format ] : array( 'status' => 'exists' );
			if ( 'created' === $outcome['status'] ) {
				// The real ensure_format() writes the target file as its
				// whole point -- mimicked here so the subsequent, real
				// file_exists()-based re-check (compression_summary_for_
				// attachment(), called again by handle_generate_attachment()
				// to build its response) sees a consistent world, not a
				// claimed 'created' with nothing actually on disk.
				$target = \WWG_Format::path_for( $format, $source_path );
				if ( $target ) {
					file_put_contents( $target, str_repeat( 'x', isset( $outcome['bytes'] ) ? $outcome['bytes'] : 10 ) );
				}
			}
			$result[ $format ] = $outcome;
		}
		return $result;
	}
}

/**
 * Exercises WWG_Admin::compression_summary_for_attachment() -- the Media
 * Library "WebP/AVIF" column's live-per-row computation. Real filesystem
 * fixtures (matching AdminBatchingTest.php's own approach) rather than
 * mocking file_exists()/filesize(), since the whole point is proving the
 * real stat-based logic branches correctly.
 *
 * Also exercises render_compression_column()'s "Generate" button and
 * handle_generate_attachment()/regenerate_attachment() -- the single-image
 * counterpart to the Tools page's own bulk Generate, reusing this same
 * fixture setup since it renders from the identical summary.
 *
 * @covers \WWG_Admin::compression_summary_for_attachment
 * @covers \WWG_Admin::add_compression_column
 * @covers \WWG_Admin::render_compression_column
 * @covers \WWG_Admin::handle_generate_attachment
 * @covers \WWG_Admin::regenerate_attachment
 */
class AdminCompressionColumnTest extends TestCase {

	/**
	 * @var string
	 */
	private $fixture_dir;

	/**
	 * Fake backing store for get_option()/update_option().
	 *
	 * @var array
	 */
	private $options;

	/**
	 * @var string
	 */
	private $mime_type;

	/**
	 * @var array
	 */
	private $metadata;

	/**
	 * Captured argument of the most recent wp_send_json_success()/
	 * wp_send_json_error() call.
	 *
	 * @var mixed
	 */
	private $last_json;

	protected function set_up() {
		parent::set_up();

		$this->fixture_dir = sys_get_temp_dir() . '/wwg-compression-column-test-' . uniqid();
		mkdir( $this->fixture_dir . '/2024/03', 0777, true );
		$this->options   = array();
		$this->mime_type = 'image/jpeg';
		$this->metadata  = array();

		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'basedir' => $this->fixture_dir ) );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
		// Pinned to webp-only by default -- this test machine's real GD
		// genuinely supports both webp and avif (see FormatTest.php), so
		// every test below that doesn't care about a second format stays
		// simple; the dedicated multi-format tests override this.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'webp' ) : $value;
			}
		);
		Functions\when( 'get_post_mime_type' )->alias(
			function () {
				return $this->mime_type;
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			function () {
				return $this->metadata;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( isset( $this->options[ $key ] ) ) {
					return $this->options[ $key ];
				}
				// wwg_scan_state defaults to "an established site" here --
				// WWG_Format::enabled() now consults it (see
				// user_wants()'s fresh-install-only smart default), and
				// none of this file's tests are about that default, so
				// they get the plain "true unless explicitly unchecked"
				// behavior they were originally written to expect.
				return 'wwg_scan_state' === $key ? array( 'finished_at' => 1000 ) : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		// The rest is only exercised by render_compression_column()'s
		// actual HTML output and handle_generate_attachment() -- the
		// summary-only tests above never reach any of this. Plain
		// passthroughs throughout: these tests assert on which markup/
		// bookkeeping is present, not on exact translated/formatted text.
		$this->last_json = null;
		$_POST            = array();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'size_format' )->alias(
			function ( $bytes ) {
				return $bytes . 'B';
			}
		);
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->last_json = array(
					'success' => true,
					'data'    => $data,
				);
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$this->last_json = array(
					'success' => false,
					'data'    => $data,
				);
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

	private function admin() {
		return new \WWG_Admin( new \WWG_Generator() );
	}

	private function summary_for( $attachment_id = 42 ) {
		$admin = $this->admin();
		$ref   = new \ReflectionMethod( $admin, 'compression_summary_for_attachment' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $admin, array( $attachment_id ) );
	}

	/**
	 * @param \WWG_Admin $admin
	 * @param int        $attachment_id
	 * @return string The column's own rendered HTML.
	 */
	private function render_column( $admin, $attachment_id = 42 ) {
		ob_start();
		$admin->render_compression_column( 'wwg_compression', $attachment_id );
		return ob_get_clean();
	}

	public function test_add_compression_column_appends_a_new_column() {
		$columns = $this->admin()->add_compression_column( array( 'cb' => '<input />', 'title' => 'File' ) );

		$this->assertArrayHasKey( 'wwg_compression', $columns );
		$this->assertSame( array( 'cb', 'title', 'wwg_compression' ), array_keys( $columns ) );
	}

	public function test_non_image_mime_is_unsupported() {
		$this->mime_type = 'application/pdf';

		$result = $this->summary_for();

		$this->assertSame( 'unsupported', $result['status'] );
	}

	public function test_no_enabled_format_at_all_is_not_yet() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array() : $value;
			}
		);
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source bytes' );

		$result = $this->summary_for();

		$this->assertSame( 'not_yet', $result['status'] );
	}

	public function test_missing_attachment_metadata_is_unsupported() {
		$this->metadata = array(); // no 'file' key -- e.g. a genuinely broken/incomplete attachment record.

		$result = $this->summary_for();

		$this->assertSame( 'unsupported', $result['status'] );
	}

	public function test_fully_converted_reports_bytes_and_percent_per_format() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', str_repeat( 'x', 1000 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', str_repeat( 'x', 250 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo-150x150.jpg', str_repeat( 'x', 200 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo-150x150.webp', str_repeat( 'x', 50 ) );
		$this->metadata = array(
			'file'  => '2024/03/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);

		$result = $this->summary_for();

		$this->assertSame( 'converted', $result['status'] );
		$this->assertSame( 1200, $result['original_bytes'] ); // 1000 + 200.
		$this->assertSame( 300, $result['formats']['webp']['bytes'] ); // 250 + 50.
		// 300 is 25% of 1200 -- i.e. 75% smaller.
		$this->assertSame( 75, $result['formats']['webp']['saved_percent'] );
	}

	public function test_missing_one_derivative_is_partial_not_converted() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', 'derived' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo-150x150.jpg', 'source' );
		// No photo-150x150.webp -- this one size hasn't been converted yet.
		$this->metadata = array(
			'file'  => '2024/03/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);

		$result = $this->summary_for();

		$this->assertSame( 'partial', $result['status'] );
	}

	public function test_a_known_permanent_failure_reports_failed_even_though_other_sizes_are_fine() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', 'derived' );
		$broken_path = $this->fixture_dir . '/2024/03/photo-150x150.jpg';
		file_put_contents( $broken_path, 'not a real jpeg' );
		// No .webp for the broken size, and it's on record as a known,
		// fingerprint-confirmed failure (not just "not attempted yet").
		$this->options['wwg_known_failures'] = array(
			'2024/03/photo-150x150.jpg' => array(
				'size'    => filesize( $broken_path ),
				'mtime'   => filemtime( $broken_path ),
				'formats' => array( 'webp' => array( 'error' => 'Imagick: unable to decode image data.' ) ),
			),
		);
		$this->metadata = array(
			'file'  => '2024/03/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);

		$result = $this->summary_for();

		$this->assertSame( 'failed', $result['status'] );
	}

	public function test_a_stale_known_failure_record_with_a_changed_fingerprint_is_only_partial_not_failed() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', 'derived' );
		$path = $this->fixture_dir . '/2024/03/photo-150x150.jpg';
		file_put_contents( $path, 'not a real jpeg' );
		// The remembered fingerprint no longer matches this file's real
		// size/mtime (e.g. it was replaced since) -- is_known_failure()
		// must not trust it, so this is "not attempted yet", not "failed".
		$this->options['wwg_known_failures'] = array(
			'2024/03/photo-150x150.jpg' => array(
				'size'    => 999999,
				'mtime'   => 111,
				'formats' => array( 'webp' => array( 'error' => 'stale' ) ),
			),
		);
		$this->metadata = array(
			'file'  => '2024/03/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);

		$result = $this->summary_for();

		$this->assertSame( 'partial', $result['status'] );
	}

	public function test_an_entirely_orphaned_attachment_is_unsupported_not_falsely_converted() {
		// Real-world case found via live verification: every one of the
		// attachment's files, original included, has been moved/deleted
		// outside WordPress -- get_source_files() still returns the paths
		// metadata records, but file_exists() is false for every single
		// one. Must not report "converted, 0 B, 0% smaller" just because
		// the loop found nothing to disprove that.
		$this->metadata = array(
			'file'  => '2024/03/gone.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'gone-150x150.jpg' ),
			),
		);
		// Deliberately create neither file.

		$result = $this->summary_for();

		$this->assertSame( 'unsupported', $result['status'] );
	}

	public function test_multi_format_converted_reports_each_enabled_format_independently() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'avif', 'webp' ) : $value;
			}
		);
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', str_repeat( 'x', 1000 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', str_repeat( 'x', 300 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.avif', str_repeat( 'x', 200 ) );
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$result = $this->summary_for();

		$this->assertSame( 'converted', $result['status'] );
		$this->assertSame( 300, $result['formats']['webp']['bytes'] );
		$this->assertSame( 200, $result['formats']['avif']['bytes'] );
		$this->assertSame( 80, $result['formats']['avif']['saved_percent'] );
	}

	public function test_missing_only_the_newly_enabled_second_format_is_partial() {
		// webp already fully converted; avif has just become enabled
		// (e.g. this server just gained support) and genuinely hasn't
		// been generated for this attachment yet -- correctly "partial",
		// not "converted" (webp is fine, but avif isn't there at all).
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'avif', 'webp' ) : $value;
			}
		);
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', 'derived' );
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$result = $this->summary_for();

		$this->assertSame( 'partial', $result['status'] );
	}

	/**
	 * Regression test for a real incident: a site with a completely
	 * WebP-converted library (the normal case before AVIF existed) got
	 * status 'partial' the moment AVIF became a second enabled format,
	 * with NO way for the caller to tell "WebP is fine, AVIF just hasn't
	 * run yet" apart from "nothing has ever been converted for this file
	 * at all" -- both collapsed to the exact same blanket text, which
	 * read as "your existing WebP files were deleted" even though they
	 * never were. 'partial' must still report each format's own
	 * completeness individually, not just the one summary status.
	 */
	public function test_partial_status_still_reports_which_formats_are_actually_complete() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_enabled_formats' === $tag ? array( 'avif', 'webp' ) : $value;
			}
		);
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', str_repeat( 'x', 1000 ) );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', str_repeat( 'x', 300 ) );
		// No photo.avif -- genuinely never generated yet.
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$result = $this->summary_for();

		$this->assertSame( 'partial', $result['status'] );
		$this->assertTrue( $result['formats']['webp']['complete'] );
		$this->assertSame( 300, $result['formats']['webp']['bytes'] );
		$this->assertSame( 70, $result['formats']['webp']['saved_percent'] );
		$this->assertFalse( $result['formats']['avif']['complete'] );
		$this->assertArrayNotHasKey( 'bytes', $result['formats']['avif'] );
	}

	// ---- render_compression_column()'s "Generate" button ----

	public function test_render_compression_column_shows_a_generate_button_when_partial() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		// No photo.webp -- genuinely never converted yet.
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$html = $this->render_column( $this->admin(), 42 );

		$this->assertStringContainsString( 'wwg-cc-generate-btn', $html );
		$this->assertStringContainsString( 'data-attachment-id="42"', $html );
	}

	public function test_render_compression_column_shows_no_button_once_already_converted() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.webp', 'derived' );
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$html = $this->render_column( $this->admin() );

		$this->assertStringNotContainsString( 'wwg-cc-generate-btn', $html );
	}

	public function test_render_compression_column_shows_no_plain_retry_button_for_a_known_failure() {
		// A plain retry would just fail the same way again with no
		// explanation -- the 'failed' branch's existing link to the Tools
		// page's real Fix/Delete machinery is the answer for this case,
		// not this button.
		$broken_path = $this->fixture_dir . '/2024/03/photo.jpg';
		file_put_contents( $broken_path, 'not a real jpeg' );
		$this->options['wwg_known_failures'] = array(
			'2024/03/photo.jpg' => array(
				'size'    => filesize( $broken_path ),
				'mtime'   => filemtime( $broken_path ),
				'formats' => array( 'webp' => array( 'error' => 'boom' ) ),
			),
		);
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$html = $this->render_column( $this->admin() );

		$this->assertStringNotContainsString( 'wwg-cc-generate-btn', $html );
	}

	public function test_render_compression_column_hides_the_button_from_a_visitor_without_the_capability() {
		Functions\when( 'current_user_can' )->justReturn( false );
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$html = $this->render_column( $this->admin() );

		$this->assertStringNotContainsString( 'wwg-cc-generate-btn', $html );
	}

	// ---- handle_generate_attachment() / regenerate_attachment() ----
	//
	// No "rejects without capability" / "rejects a missing attachment_id"
	// tests here -- this handler shares the same unreturned
	// `if ( ! current_user_can(...) ) { wp_send_json_error(...); }` (and
	// equivalent for a bad attachment_id) every other AJAX handler in this
	// class already has, correctly relying on wp_send_json_error()'s real
	// wp_die() to actually stop execution in production. See
	// AdminSettingsTest.php's identical note -- asserting "nothing
	// happened after" here would only be testing an artifact of this
	// tier's non-halting mock, not real behavior.

	public function test_handle_generate_attachment_converts_a_missing_format_and_forgets_the_stale_failure_record() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', str_repeat( 'x', 1000 ) );
		// No photo.webp yet -- and it's on record as a (now stale) known
		// failure from an earlier attempt, which a real success must clear.
		$this->options['wwg_known_failures'] = array(
			'2024/03/photo.jpg' => array(
				'size'    => 1000,
				'mtime'   => filemtime( $this->fixture_dir . '/2024/03/photo.jpg' ),
				'formats' => array( 'webp' => array( 'error' => 'previously failed' ) ),
			),
		);
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$generator            = new WWG_Generator_Fake_For_Column();
		$generator->outcomes  = array( 'webp' => array( 'status' => 'created', 'bytes' => 250 ) );
		$admin                = new \WWG_Admin( $generator );
		$_POST                = array( 'attachment_id' => '42' );

		$admin->handle_generate_attachment();

		$this->assertTrue( $this->last_json['success'] );
		$this->assertStringNotContainsString( 'wwg-cc-generate-btn', $this->last_json['data']['html'] );
		$this->assertArrayNotHasKey( '2024/03/photo.jpg', $this->options['wwg_known_failures'] );
	}

	public function test_handle_generate_attachment_records_a_real_failure_and_the_response_reflects_it() {
		file_put_contents( $this->fixture_dir . '/2024/03/photo.jpg', 'source' );
		$this->metadata = array( 'file' => '2024/03/photo.jpg', 'sizes' => array() );

		$generator           = new WWG_Generator_Fake_For_Column();
		$generator->outcomes = array(
			'webp' => array(
				'status' => 'failed',
				'error'  => 'Imagick: unable to decode image data.',
			),
		);
		$admin = new \WWG_Admin( $generator );
		$_POST = array( 'attachment_id' => '42' );

		$admin->handle_generate_attachment();

		$this->assertTrue( $this->last_json['success'] ); // The AJAX request itself succeeded -- see docblock on handle_generate_attachment().
		$this->assertStringNotContainsString( 'wwg-cc-generate-btn', $this->last_json['data']['html'] );
		$this->assertArrayHasKey( '2024/03/photo.jpg', $this->options['wwg_known_failures'] );
	}

	public function test_handle_generate_attachment_is_a_noop_for_an_attachment_with_no_metadata() {
		$this->metadata = array(); // no 'file' key -- e.g. a genuinely broken/incomplete attachment record.

		$generator = new WWG_Generator_Fake_For_Column();
		$admin     = new \WWG_Admin( $generator );
		$_POST     = array( 'attachment_id' => '42' );

		$admin->handle_generate_attachment();

		$this->assertTrue( $this->last_json['success'] );
		$this->assertArrayNotHasKey( 'wwg_known_failures', $this->options );
	}
}
