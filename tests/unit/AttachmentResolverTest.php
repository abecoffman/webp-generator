<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WWG_Attachment_Resolver::resolve
 */
class AttachmentResolverTest extends TestCase {

	protected function set_up() {
		parent::set_up();

		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'basedir' => '/var/www/uploads',
				'baseurl' => 'https://example.test/wp-content/uploads',
			)
		);
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/' ) . '/';
			}
		);
	}

	/**
	 * Stubs attachment_url_to_postid() to resolve only the one "original"
	 * URL a test cares about, returning 0 (no match) for anything else --
	 * mirrors the real function's own behavior (it only ever matches an
	 * attachment's recorded _wp_attached_file).
	 */
	private function stub_original_url( $original_rel, $attachment_id ) {
		$expected_url = 'https://example.test/wp-content/uploads/' . $original_rel;
		Functions\when( 'attachment_url_to_postid' )->alias(
			function ( $url ) use ( $expected_url, $attachment_id ) {
				return $url === $expected_url ? $attachment_id : 0;
			}
		);
	}

	public function test_resolves_the_recorded_original_directly() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo.jpg' );

		$this->assertSame(
			array(
				'attachment_id' => 42,
				'is_original'   => true,
				'size_name'     => null,
				'original_path' => null,
			),
			$result
		);
	}

	public function test_resolves_wps_own_scaled_copy_as_the_original_too() {
		// A "-scaled" file is exactly as much "the original" from this
		// plugin's point of view as a literal upload -- it's what
		// _wp_attached_file actually points at once WordPress has
		// down-sized a large upload, so attachment_url_to_postid() a
		// hit on it the same way as any other recorded file.
		$this->stub_original_url( '2024/01/photo-scaled.jpg', 42 );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-scaled.jpg' );

		$this->assertTrue( $result['is_original'] );
	}

	public function test_returns_false_for_a_filename_that_does_not_look_like_a_derivative() {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo.jpg' );

		$this->assertFalse( $result );
	}

	public function test_resolves_a_derivative_confirmed_by_the_attachments_own_metadata() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array(
						'file'   => 'photo-150x150.jpg',
						'width'  => 150,
						'height' => 150,
					),
					'medium'    => array(
						'file'   => 'photo-300x300.jpg',
						'width'  => 300,
						'height' => 300,
					),
				),
			)
		);
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/var/www/uploads/2024/01/photo.jpg' );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-150x150.jpg' );

		$this->assertSame(
			array(
				'attachment_id' => 42,
				'is_original'   => false,
				'size_name'     => 'thumbnail',
				'original_path' => '/var/www/uploads/2024/01/photo.jpg',
			),
			$result
		);
	}

	public function test_returns_false_when_the_derivative_looking_file_resolves_to_no_attachment() {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-150x150.jpg' );

		$this->assertFalse( $result );
	}

	public function test_returns_false_when_the_attachment_has_no_sizes_metadata() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array() );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-150x150.jpg' );

		$this->assertFalse( $result );
	}

	public function test_returns_false_when_no_recorded_size_matches_the_target_filename() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'medium' => array(
						'file'   => 'photo-300x300.jpg',
						'width'  => 300,
						'height' => 300,
					),
				),
			)
		);

		// "photo-150x150.jpg" looks like a derivative and its stripped
		// original resolves, but no recorded size matches this exact
		// filename -- classify_failure()'s caller must not guess.
		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-150x150.jpg' );

		$this->assertFalse( $result );
	}

	public function test_falls_back_to_get_attached_file_when_theres_no_original_image_path() {
		$this->stub_original_url( '2024/01/photo.jpg', 42 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array(
						'file'   => 'photo-150x150.jpg',
						'width'  => 150,
						'height' => 150,
					),
				),
			)
		);
		Functions\when( 'wp_get_original_image_path' )->justReturn( false );
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/uploads/2024/01/photo.jpg' );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/photo-150x150.jpg' );

		$this->assertSame( '/var/www/uploads/2024/01/photo.jpg', $result['original_path'] );
	}

	public function test_does_not_mistake_a_filename_with_incidental_numbers_for_a_derivative() {
		// "event-1920x1080-poster.jpg" contains something that looks like
		// dimensions, but not immediately before the extension -- the
		// real WordPress derivative suffix is always the very last thing
		// before it.
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );

		$result = \WWG_Attachment_Resolver::resolve( '2024/01/event-1920x1080-poster.jpg' );

		$this->assertFalse( $result );
	}
}
