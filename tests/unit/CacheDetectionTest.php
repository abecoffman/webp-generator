<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;

/**
 * @covers \WWG_Cache::detect_active_backends
 *
 * Every test but the "nothing detected" baseline runs @runInSeparateProcess:
 * detecting a backend here means defining a real class/function/constant
 * (the same technique used to manually verify this against a real Cache
 * Enabler-shaped mock during development), and PHP has no way to undefine
 * those afterward -- without process isolation, whichever test runs first
 * would permanently pollute global state for every test after it in the
 * same run, regardless of PHPUnit's execution order.
 */
class CacheDetectionTest extends TestCase {

	public function test_detects_nothing_when_no_supported_plugin_is_active() {
		$this->assertSame( array(), \WWG_Cache::detect_active_backends() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_cache_enabler_by_class() {
		eval( 'class Cache_Enabler {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stand-in for a plugin class that isn't installed here; process-isolated, see class docblock.

		$this->assertContains( 'Cache Enabler', \WWG_Cache::detect_active_backends() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_wp_rocket_by_function() {
		eval( 'function rocket_clean_domain() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- see class docblock.

		$this->assertContains( 'WP Rocket', \WWG_Cache::detect_active_backends() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_w3_total_cache_by_function() {
		eval( 'function w3tc_flush_all() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- see class docblock.

		$this->assertContains( 'W3 Total Cache', \WWG_Cache::detect_active_backends() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_wp_super_cache_by_function() {
		eval( 'function wp_cache_clear_cache() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- see class docblock.

		$this->assertContains( 'WP Super Cache', \WWG_Cache::detect_active_backends() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_litespeed_cache_by_constant() {
		define( 'LSCWP_V', 'test' );

		$this->assertContains( 'LiteSpeed Cache', \WWG_Cache::detect_active_backends() );
	}
}
