<?php
/**
 * Base test case: wires up Brain Monkey's WordPress function stubs around
 * every test.
 *
 * @package WWG
 */

namespace WWG\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;
use Brain\Monkey;

/**
 * Extends the Yoast polyfill base (not PHPUnit's directly) so this suite
 * runs the same across the PHPUnit 9/10 range the CI matrix covers.
 */
abstract class TestCase extends PolyfillTestCase {

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
	}

	protected function tear_down() {
		Monkey\tearDown();
		parent::tear_down();
	}
}
