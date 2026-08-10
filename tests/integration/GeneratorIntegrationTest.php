<?php
/**
 * Integration tests for WWG_Generator against a real WordPress install,
 * real Imagick/GD, and real file I/O -- none of which the fast Brain
 * Monkey unit tier (tests/unit/) can exercise. Run via `composer
 * test:integration` against the plugin-sandbox test DB.
 *
 * @package WWG
 */

/**
 * @covers WWG_Generator
 */
class GeneratorIntegrationTest extends WP_UnitTestCase {

	/**
	 * A real JPEG fixture shipped with WordPress's own test library.
	 *
	 * @var string
	 */
	private $fixture_jpeg;

	public function set_up() {
		parent::set_up();
		$this->fixture_jpeg = DIR_TESTDATA . '/images/canola.jpg';
		$this->assertFileExists( $this->fixture_jpeg, 'WordPress test library fixture image missing -- has install-wp-tests.sh run?' );
	}

	/**
	 * ensure_webp() against a real file: this is the one thing the
	 * Brain Monkey unit tier structurally cannot cover (no real
	 * Imagick/GD, no real filesystem) -- confirmed via GeneratorQualityTest
	 * only ever mocking get_option(), never touching real bytes.
	 */
	public function test_ensure_webp_creates_a_real_webp_file_from_a_real_jpeg() {
		$tmp_dir = get_temp_dir() . 'wwg-integration-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $tmp_dir );
		$source = $tmp_dir . 'source.jpg';
		copy( $this->fixture_jpeg, $source );

		$generator = new WWG_Generator();
		$result    = $generator->ensure_webp( $source );

		$this->assertSame( 'created', $result['status'] );

		$webp_path = $tmp_dir . 'source.webp';
		$this->assertFileExists( $webp_path );
		$this->assertGreaterThan( 0, $result['webp_bytes'] );

		// A real .webp file starts with the RIFF container header and
		// the WEBP fourcc -- confirms this is an actual valid WebP
		// image, not just a file that happens to exist.
		$bytes = file_get_contents( $webp_path, false, null, 0, 12 );
		$this->assertSame( 'RIFF', substr( $bytes, 0, 4 ) );
		$this->assertSame( 'WEBP', substr( $bytes, 8, 4 ) );

		// Re-running against the same source should report 'exists',
		// not regenerate -- the single-source-of-truth guarantee this
		// method gives both the on-upload hook and the admin bulk tool.
		$second = $generator->ensure_webp( $source );
		$this->assertSame( 'exists', $second['status'] );

		self::delete_dir_recursive( $tmp_dir );
	}

	/**
	 * The one scenario the unit tier's GeneratorRecoveryTest.php explicitly
	 * defers here: a file with garbage bytes prepended ahead of a
	 * genuinely valid, complete JPEG should still end up with a real,
	 * working .webp -- flagged as recovered -- and the original file must
	 * come out byte-for-byte untouched. The embedded JPEG is synthesized
	 * with GD itself (imagecreatetruecolor()/imagejpeg()) rather than
	 * relying on a fixture file, so this needs no network access and stays
	 * fast/deterministic.
	 */
	public function test_ensure_webp_recovers_a_valid_image_embedded_after_garbage_bytes() {
		ob_start();
		imagejpeg( imagecreatetruecolor( 2, 2 ) );
		$real_jpeg_bytes = ob_get_clean();

		$tmp_dir = get_temp_dir() . 'wwg-integration-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $tmp_dir );
		$source        = $tmp_dir . 'source.jpg';
		$garbage_bytes = 'HTTP/1.1 200 OK' . str_repeat( "\x00garbage\x00", 20 );
		$original_bytes = $garbage_bytes . $real_jpeg_bytes;
		file_put_contents( $source, $original_bytes );

		$generator = new WWG_Generator();
		$result    = $generator->ensure_webp( $source );

		$this->assertSame( 'created', $result['status'] );
		$this->assertTrue( $result['recovered'] );

		$webp_path = $tmp_dir . 'source.webp';
		$this->assertFileExists( $webp_path );

		$bytes = file_get_contents( $webp_path, false, null, 0, 12 );
		$this->assertSame( 'RIFF', substr( $bytes, 0, 4 ) );
		$this->assertSame( 'WEBP', substr( $bytes, 8, 4 ) );

		// The whole point: the still-malformed original is never touched.
		$this->assertSame( $original_bytes, file_get_contents( $source ) );

		self::delete_dir_recursive( $tmp_dir );
	}

	/**
	 * The real wp_generate_attachment_metadata filter, wired up via
	 * init() exactly as it runs in production -- a real attachment
	 * insert should produce a real .webp sibling for the original file
	 * and every registered size.
	 */
	public function test_real_attachment_upload_generates_webp_siblings_for_every_size() {
		$attachment_id = self::factory()->attachment->create_upload_object( $this->fixture_jpeg );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $metadata['file'], 'Attachment metadata missing -- upload fixture failed.' );

		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$subdir     = trailingslashit( dirname( $metadata['file'] ) );

		$expected_files   = array( $base_dir . $metadata['file'] );
		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				$expected_files[] = $base_dir . $subdir . $size['file'];
			}
		}

		$this->assertNotEmpty( $expected_files );

		foreach ( $expected_files as $file ) {
			$webp = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
			$this->assertFileExists(
				$webp,
				"Expected a .webp sibling for {$file}, generated automatically via the wp_generate_attachment_metadata filter."
			);
		}
	}

	/**
	 * @param string $dir
	 * @return void
	 */
	private static function delete_dir_recursive( $dir ) {
		foreach ( glob( $dir . '*' ) as $file ) {
			wp_delete_file( $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
}
