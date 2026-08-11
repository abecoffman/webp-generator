<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Exercises WWG_Admin's "Fix this file" / "Delete this file" logic --
 * classify_failure()'s branching, and the parts of fix_failure()/
 * delete_failure_file()/delete_original_attachment() that don't require
 * real WordPress image-generation machinery. image_make_intermediate_size()
 * itself is WP core and needs a real WP + GD/Imagick install to actually
 * produce a file -- a genuinely successful regenerate is covered by
 * tests/integration/ instead, matching this tier's existing scope
 * boundary (see AdminBatchingTest.php's own note: real conversion
 * *success* needs a real backend; failure paths don't).
 *
 * @covers \WWG_Admin
 */
class AdminFailureActionsTest extends TestCase {

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

	protected function set_up() {
		parent::set_up();

		$this->fixture_dir = sys_get_temp_dir() . '/wwg-failure-actions-test-' . uniqid();
		mkdir( $this->fixture_dir, 0777, true );
		$this->options = array();

		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'basedir' => $this->fixture_dir,
				'baseurl' => 'https://example.test/wp-content/uploads',
			)
		);
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
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
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.test/wp-admin/post.php?post=42&action=edit' );
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

	/**
	 * @return \WWG_Admin
	 */
	private function admin() {
		return new \WWG_Admin( new \WWG_Generator() );
	}

	private function invoke( \WWG_Admin $admin, $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $admin, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $admin, $args );
	}

	/**
	 * Stubs attachment_url_to_postid() so only the given original's URL
	 * resolves -- everything else (including the derivative's own URL, on
	 * the direct-original check every resolve() call starts with) misses,
	 * matching how the real function behaves.
	 */
	private function stub_original_url( $original_rel, $attachment_id ) {
		$expected_url = 'https://example.test/wp-content/uploads/' . $original_rel;
		Functions\when( 'attachment_url_to_postid' )->alias(
			function ( $url ) use ( $expected_url, $attachment_id ) {
				return $url === $expected_url ? $attachment_id : 0;
			}
		);
	}

	// ---- classify_failure() ----

	public function test_classify_unresolvable_file_offers_delete_only() {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );

		$result = $this->invoke( $this->admin(), 'classify_failure', array( '2024/01/orphan.jpg' ) );

		$this->assertSame( 'delete_only', $result['action'] );
		$this->assertNotEmpty( $result['reason'] );
		$this->assertArrayNotHasKey( 'edit_url', $result );
	}

	public function test_classify_original_file_offers_delete_original() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );

		$result = $this->invoke( $this->admin(), 'classify_failure', array( '2024/01/photo.jpg' ) );

		$this->assertSame( 'delete_original', $result['action'] );
		$this->assertNotEmpty( $result['edit_url'] );
	}

	public function test_classify_derivative_with_missing_original_offers_delete_only_with_reason() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
				),
			)
		);
		// Points at a file that doesn't exist -- the original is itself
		// missing/broken, so nothing to regenerate from.
		Functions\when( 'wp_get_original_image_path' )->justReturn( $this->fixture_dir . '/2024/01/photo.jpg' );

		$result = $this->invoke( $this->admin(), 'classify_failure', array( '2024/01/photo-150x150.jpg' ) );

		$this->assertSame( 'delete_only', $result['action'] );
		$this->assertNotEmpty( $result['reason'] );
		$this->assertNotEmpty( $result['edit_url'] ); // attachment IS known here, unlike the fully-unresolvable case.
	}

	public function test_classify_derivative_with_healthy_original_offers_fix_or_delete() {
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		$original_path = $this->fixture_dir . '/2024/01/photo.jpg';
		file_put_contents( $original_path, 'pretend this is real image bytes' );

		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
				),
			)
		);
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original_path );

		$result = $this->invoke( $this->admin(), 'classify_failure', array( '2024/01/photo-150x150.jpg' ) );

		$this->assertSame( 'fix_or_delete', $result['action'] );
	}

	public function test_classify_respects_the_allow_failure_actions_filter() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return 'wwg_allow_failure_actions' === $tag ? false : $value;
			}
		);
		// Even a file that would otherwise clearly resolve to "delete
		// original" must be refused once the filter is off.
		$this->stub_original_url( '2024/01/photo.jpg', 42 );

		$result = $this->invoke( $this->admin(), 'classify_failure', array( '2024/01/photo.jpg' ) );

		$this->assertSame( 'none', $result['action'] );
	}

	// ---- fix_failure() ----

	public function test_fix_failure_recognizes_an_already_fixed_file() {
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		touch( $this->fixture_dir . '/2024/01/already-fixed.jpg' );
		touch( $this->fixture_dir . '/2024/01/already-fixed.webp' ); // .webp already exists -- ensure_webp() short-circuits to 'exists'.

		$this->options['wwg_known_failures'] = array(
			'2024/01/already-fixed.jpg' => array( 'size' => 0, 'mtime' => 123, 'error' => 'was broken' ),
		);

		$result = $this->invoke( $this->admin(), 'fix_failure', array( '2024/01/already-fixed.jpg' ) );

		$this->assertSame( 'fixed', $result['outcome'] );
		$this->assertArrayNotHasKey( '2024/01/already-fixed.jpg', $this->options['wwg_known_failures'] );
	}

	public function test_fix_failure_refuses_when_not_regenerable() {
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		file_put_contents( $this->fixture_dir . '/2024/01/broken.jpg', 'not a real jpeg' );

		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 ); // fully unresolvable.

		$result = $this->invoke( $this->admin(), 'fix_failure', array( '2024/01/broken.jpg' ) );

		$this->assertSame( 'not_regenerable', $result['outcome'] );
		$this->assertNotEmpty( $result['message'] );
	}

	// ---- delete_failure_file() ----

	public function test_delete_failure_file_removes_the_file_and_clears_the_record() {
		mkdir( $this->fixture_dir . '/2024/01', 0777, true );
		$path = $this->fixture_dir . '/2024/01/to-delete.jpg';
		file_put_contents( $path, 'garbage' );

		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 ); // unresolvable -- no metadata cleanup expected.
		Functions\when( 'wp_delete_file' )->alias(
			function ( $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);

		$this->options['wwg_known_failures'] = array(
			'2024/01/to-delete.jpg' => array( 'size' => 7, 'mtime' => 123, 'error' => 'broken' ),
		);
		$this->options['wwg_scan_state']     = array(
			'missing'        => 1,
			'original_bytes' => 7,
			'failures'       => array( array( 'file' => '2024/01/to-delete.jpg', 'error' => 'broken' ) ),
			'finished_at'    => 100,
			'invalidated_at' => null,
		);

		$result = $this->invoke( $this->admin(), 'delete_failure_file', array( '2024/01/to-delete.jpg' ) );

		$this->assertSame( 'deleted', $result['outcome'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertArrayNotHasKey( '2024/01/to-delete.jpg', $this->options['wwg_known_failures'] );
		$this->assertSame( array(), $this->options['wwg_scan_state']['failures'] );
		$this->assertSame( 0, $this->options['wwg_scan_state']['missing'] );
	}

	// ---- delete_original_attachment() ----

	public function test_delete_original_attachment_bails_when_resolution_is_stale() {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 ); // no longer resolves as the original.
		Functions\expect( 'wp_delete_attachment' )->never();

		$result = $this->invoke( $this->admin(), 'delete_original_attachment', array( '2024/01/photo.jpg' ) );

		$this->assertSame( 'stale', $result['outcome'] );
	}

	public function test_delete_original_attachment_deletes_the_whole_attachment() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\expect( 'wp_delete_attachment' )
			->once()
			->with( 42, true )
			->andReturn( (object) array( 'ID' => 42 ) );

		$result = $this->invoke( $this->admin(), 'delete_original_attachment', array( '2024/01/photo.jpg' ) );

		$this->assertSame( 'deleted', $result['outcome'] );
	}

	public function test_delete_original_attachment_sweeps_other_known_failures_for_the_same_attachment() {
		// Both the original and one of its derivative sizes are known
		// failures -- deleting the original via wp_delete_attachment()
		// is about to remove the derivative from disk too, so its
		// known-failure record should be cleared right along with it.
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
				),
			)
		);
		Functions\when( 'wp_delete_attachment' )->justReturn( (object) array( 'ID' => 42 ) );
		// The sweep re-resolves the derivative sibling too, which needs an
		// original path even though nothing here actually reads its bytes.
		Functions\when( 'wp_get_original_image_path' )->justReturn( $this->fixture_dir . '/2024/01/photo.jpg' );

		$this->options['wwg_known_failures'] = array(
			'2024/01/photo.jpg'          => array( 'size' => 0, 'mtime' => 1, 'error' => 'broken original' ),
			'2024/01/photo-150x150.jpg'  => array( 'size' => 0, 'mtime' => 1, 'error' => 'broken thumb' ),
		);

		$this->invoke( $this->admin(), 'delete_original_attachment', array( '2024/01/photo.jpg' ) );

		$this->assertArrayNotHasKey( '2024/01/photo.jpg', $this->options['wwg_known_failures'] );
		$this->assertArrayNotHasKey( '2024/01/photo-150x150.jpg', $this->options['wwg_known_failures'] );
	}
}
