<?php
/**
 * @package WWG
 */

namespace WWG\Tests\Unit;

use WWG\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WWG_Htaccess
 */
class HtaccessTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * @dataProvider provide_server_software_strings
	 */
	public function test_detect_server_matches_known_servers( $server_software, $expected ) {
		if ( null !== $server_software ) {
			$_SERVER['SERVER_SOFTWARE'] = $server_software;
		}

		$this->assertSame( $expected, \WWG_Htaccess::detect_server() );
	}

	public static function provide_server_software_strings() {
		return array(
			'apache'                  => array( 'Apache/2.4.68 (Unix) PHP/8.5.9', 'apache' ),
			'litespeed'                => array( 'LiteSpeed', 'litespeed' ),
			'litespeed lowercase'      => array( 'litespeed web server', 'litespeed' ),
			'nginx'                    => array( 'nginx/1.24.0', 'nginx' ),
			'iis'                      => array( 'Microsoft-IIS/10.0', 'iis' ),
			'unrecognized'             => array( 'CherryPy/18.8.0', 'unknown' ),
			'not set at all'           => array( null, 'unknown' ),
			// LiteSpeed's SERVER_SOFTWARE string sometimes still says
			// "Apache" (compatibility mode) -- litespeed must win when
			// both substrings are present, since that's the one that
			// determines actual .htaccess compatibility here.
			'litespeed apache compat'  => array( 'LiteSpeed (Apache-compatible)', 'litespeed' ),
		);
	}

	public function test_get_rule_lines_indents_directives_inside_ifmodule_blocks() {
		$lines = \WWG_Htaccess::get_rule_lines();

		$this->assertNotEmpty( $lines );

		foreach ( $lines as $line ) {
			if ( '' === $line || 0 === strpos( $line, '<' ) ) {
				// Tag lines (<IfModule ...>, </IfModule>) sit at the top level, unindented.
				continue;
			}
			$this->assertStringStartsWith( '    ', $line, "Directive line not indented: \"$line\"" );
		}
	}

	// Note: install()/get_path() aren't covered by this lightweight tier
	// -- both do a real require_once against wp-admin/includes/*.php,
	// which only exists inside an actual WordPress install. That path
	// (and that get_rule_lines() really is what ends up in .htaccess) was
	// verified manually against a real Apache request instead -- see the
	// project's memory notes for that session.

	public function test_get_rule_html_never_leaks_an_unescaped_angle_bracket_from_directive_text() {
		// Regression test for a real bug this session: raw <IfModule ...>
		// text must come out HTML-escaped, with only the wrapping <span>
		// tags this method itself adds left as real markup.
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);

		$html = \WWG_Htaccess::get_rule_html();

		$this->assertStringNotContainsString( '<IfModule', $html );
		$this->assertStringContainsString( '&lt;IfModule', $html );
		$this->assertStringContainsString( 'wwg-tok-tag', $html );
		$this->assertStringContainsString( 'wwg-tok-keyword', $html );
		$this->assertStringContainsString( 'wwg-tok-variable', $html );
		$this->assertStringContainsString( 'wwg-tok-flag', $html );
	}
}
