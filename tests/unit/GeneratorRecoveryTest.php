<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Unit-tier coverage for WWG_Generator::ensure_format()'s newer branches:
 * the 0-byte "empty_source" early return, and the embedded-data recovery
 * attempt's offset-search mechanics on the *failure* path -- proving a
 * signature is found (or correctly ignored at offset 0) needs no
 * successful decode at all. A genuinely successful recovery needs real
 * image bytes and a real backend, out of scope for this lightweight tier
 * (see tests/integration/GeneratorIntegrationTest.php for that). Format-
 * agnostic logic (the offset search itself) is only exercised against
 * 'webp' -- it doesn't branch on format at all, so duplicating every case
 * against 'avif' too would test nothing new; test_..._works_the_same_for_a_second_format()
 * below is the one spot-check that a second format really does share this
 * code path rather than silently having its own copy.
 *
 * @covers \WWG_Generator::ensure_format
 */
class GeneratorRecoveryTest extends TestCase {

	/**
	 * @var string[]
	 */
	private $created_files = array();

	protected function set_up() {
		parent::set_up();

		Functions\when( '__' )->returnArg();
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
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
		Functions\when( 'wp_generate_password' )->alias(
			function () {
				return substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			function ( $path ) {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
	}

	protected function tear_down() {
		foreach ( $this->created_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * @param string $filename Basename, e.g. "corrupt.jpg".
	 * @param string $contents Raw bytes to write.
	 * @return string Absolute path to the fixture file.
	 */
	private function fixture( $filename, $contents ) {
		$path = sys_get_temp_dir() . '/wwg-recovery-test-' . uniqid() . '-' . $filename;
		file_put_contents( $path, $contents );

		$this->created_files[] = $path;
		$this->created_files[] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
		$this->created_files[] = preg_replace( '/\.(jpe?g|png)$/i', '.avif', $path );

		return $path;
	}

	public function test_a_zero_byte_source_file_gets_a_distinct_status_and_message() {
		$path = $this->fixture( 'empty.jpg', '' );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'webp' );

		$this->assertSame( 'empty_source', $result['status'] );
		$this->assertStringContainsString( '0 bytes', $result['error'] );
	}

	public function test_recovery_reports_the_found_offset_when_the_recovered_data_still_fails_to_decode() {
		$bytes = str_repeat( 'x', 50 ) . "\xFF\xD8\xFF" . str_repeat( 'y', 20 );
		$path  = $this->fixture( 'corrupt.jpg', $bytes );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'webp' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertStringContainsString( 'byte offset 50', $result['error'] );
		// Never modifies the original file, even though recovery was attempted.
		$this->assertSame( $bytes, file_get_contents( $path ) );
	}

	/**
	 * Spot-check that this same offset-search machinery really is shared
	 * by a second format, not silently duplicated/diverged for it.
	 */
	public function test_recovery_offset_search_works_the_same_for_a_second_format() {
		$bytes = str_repeat( 'x', 50 ) . "\xFF\xD8\xFF" . str_repeat( 'y', 20 );
		$path  = $this->fixture( 'corrupt-avif.jpg', $bytes );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'avif' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertStringContainsString( 'byte offset 50', $result['error'] );
	}

	public function test_a_signature_match_at_offset_zero_is_ignored_in_favor_of_a_later_one() {
		// A match at offset 0 is exactly what the normal (non-recovery)
		// attempt already tried and failed on -- the search must skip it
		// and find the second occurrence instead.
		$bytes = "\xFF\xD8\xFF" . str_repeat( 'x', 30 ) . "\xFF\xD8\xFF" . str_repeat( 'y', 20 );
		$path  = $this->fixture( 'corrupt2.jpg', $bytes );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'webp' );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertStringContainsString( 'byte offset 33', $result['error'] );
	}

	public function test_recovery_can_be_disabled_via_filter() {
		Functions\when( 'apply_filters' )->justReturn( false );

		$bytes = str_repeat( 'x', 50 ) . "\xFF\xD8\xFF" . str_repeat( 'y', 20 );
		$path  = $this->fixture( 'corrupt3.jpg', $bytes );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'webp' );

		$this->assertSame( 'failed', $result['status'] );
		// No offset-search attempt happened at all -- just the generic
		// GD/Imagick failure message.
		$this->assertStringNotContainsString( 'byte offset', $result['error'] );
	}

	public function test_ensure_format_returns_unsupported_for_an_unrecognized_format_id() {
		// Never a real call in production (callers only ever pass a
		// WWG_Format::PRIORITY member), but path_for() -- ensure_format()'s
		// very first check -- fails closed on it the same way it fails
		// closed on an unsupported source extension.
		$path = $this->fixture( 'anything.jpg', str_repeat( 'x', 20 ) );

		$generator = new \WWG_Generator();
		$result    = $generator->ensure_format( $path, 'heic' );

		$this->assertSame( 'unsupported', $result['status'] );
	}
}
