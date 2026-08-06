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

	public function test_default_is_75_when_option_not_set() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'wwg_quality', 75 )
			->andReturn( 75 );

		$generator = new \WWG_Generator();

		$this->assertSame( 75, $generator->get_quality() );
	}

	/**
	 * @dataProvider provide_in_range_values
	 */
	public function test_passes_through_values_already_in_range( $stored, $expected ) {
		Functions\when( 'get_option' )->justReturn( $stored );

		$generator = new \WWG_Generator();

		$this->assertSame( $expected, $generator->get_quality() );
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

		$this->assertSame( $expected, $generator->get_quality() );
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
