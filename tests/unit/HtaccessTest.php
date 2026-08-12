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

		// detect_server() unslashes/sanitizes $_SERVER['SERVER_SOFTWARE'];
		// stub both as pass-throughs since these fixtures never contain
		// slashes or markup to strip -- only the real behavior under test
		// (the substring matching) matters here.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
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

	// Note: install()/get_path()/is_up_to_date() aren't covered by this
	// lightweight tier -- all three do a real require_once against
	// wp-admin/includes/*.php, which only exists inside an actual
	// WordPress install. That path (and that get_rule_lines() really is
	// what ends up in .htaccess) was verified manually against a real
	// Apache request instead -- see the project's memory notes for that
	// session.

	/**
	 * Runs against this machine's real GD (genuinely supports AVIF --
	 * confirmed via FormatTest.php), matching this codebase's existing
	 * philosophy of exercising real capability detection rather than
	 * mocking it. The reverse case (AVIF genuinely unsupported, so its
	 * block must be entirely absent) can't be exercised at this tier
	 * without dependency-injecting WWG_Format's capability check, which
	 * doesn't exist -- same structural limit as the note above.
	 */
	public function test_get_rule_lines_puts_avif_before_webp_and_gates_each_by_its_own_file_check() {
		$lines = \WWG_Htaccess::get_rule_lines();
		$text  = implode( "\n", $lines );

		$this->assertStringContainsString( 'RewriteCond %{HTTP_ACCEPT} image/avif', $text );
		$this->assertStringContainsString( 'RewriteCond %1.avif -f', $text );
		$this->assertStringContainsString( "\$1.avif [T=image/avif,E=accept:1,L]", $text );
		$this->assertStringContainsString( 'AddType image/avif .avif', $text );

		// Best format wins: AVIF's whole RewriteCond/RewriteRule group
		// must appear before WebP's, so a file with both siblings gets
		// AVIF.
		$avif_pos = strpos( $text, 'image/avif' );
		$webp_pos = strpos( $text, 'image/webp' );
		$this->assertLessThan( $webp_pos, $avif_pos );

		// Each format is independently -f-gated -- one browser/file
		// combination's fallback can't be broken by the other format's
		// condition.
		$this->assertStringContainsString( 'RewriteCond %1.webp -f', $text );
	}

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
