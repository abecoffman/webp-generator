<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WWG_Generator::get_quality
 */
class GeneratorQualityTest extends TestCase {

	public function test_default_is_80_when_webp_option_not_set() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'wwg_quality', 80 )
			->andReturn( 80 );

		$generator = new \WWG_Generator();

		$this->assertSame( 80, $generator->get_quality( 'webp' ) );
	}

	public function test_default_is_85_when_avif_option_not_set() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'wwg_quality_avif', 85 )
			->andReturn( 85 );

		$generator = new \WWG_Generator();

		$this->assertSame( 85, $generator->get_quality( 'avif' ) );
	}

	public function test_returns_the_default_for_an_unrecognized_format_without_reading_any_option() {
		Functions\expect( 'get_option' )->never();

		$generator = new \WWG_Generator();

		$this->assertSame( 80, $generator->get_quality( 'heic' ) );
	}

	/**
	 * @dataProvider provide_in_range_values
	 */
	public function test_passes_through_values_already_in_range( $stored, $expected ) {
		Functions\when( 'get_option' )->justReturn( $stored );

		$generator = new \WWG_Generator();

		$this->assertSame( $expected, $generator->get_quality( 'webp' ) );
		$this->assertSame( $expected, $generator->get_quality( 'avif' ) );
	}

	public static function provide_in_range_values() {
		return array(
			'minimum'    => array( 1, 1 ),
			'maximum'    => array( 100, 100 ),
			'typical'    => array( 40, 40 ),
			'as string'  => array( '90', 90 ), // Options come back as strings from the DB.
		);
	}

	/**
	 * @dataProvider provide_out_of_range_values
	 */
	public function test_clamps_out_of_range_values( $stored, $expected ) {
		Functions\when( 'get_option' )->justReturn( $stored );

		$generator = new \WWG_Generator();

		$this->assertSame( $expected, $generator->get_quality( 'webp' ) );
		$this->assertSame( $expected, $generator->get_quality( 'avif' ) );
	}

	public static function provide_out_of_range_values() {
		return array(
			'zero'               => array( 0, 1 ),
			'negative'           => array( -20, 1 ),
			'over 100'           => array( 150, 100 ),
			'way over 100'       => array( 999999, 100 ),
			'non-numeric string' => array( 'not-a-number', 1 ), // (int) cast -> 0 -> clamped to 1.
		);
	}
}
