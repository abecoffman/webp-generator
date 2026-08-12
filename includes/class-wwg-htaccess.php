<?php
/**
 * Installs/removes the .htaccess content-negotiation rule that actually
 * serves .webp files to browsers -- the piece WWG_Generator's file
 * creation is otherwise inert without. Works on Apache and LiteSpeed
 * (LiteSpeed Web Server is Apache mod_rewrite/.htaccess compatible by
 * design, confirmed against LiteSpeed's own documentation -- not a
 * guess). Sites on Nginx, IIS, etc. need an equivalent rule in their own
 * server config, which this can't write for them -- see get_server_doc_link().
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps WordPress core's own .htaccess-editing mechanism
 * (insert_with_markers()/extract_from_markers(), the same functions core
 * uses to manage its "# BEGIN WordPress" block) rather than hand-rolling
 * file writes.
 */
class WWG_Htaccess {

	/**
	 * Marker name insert_with_markers()/extract_from_markers() key their
	 * "# BEGIN ... # END" block on.
	 *
	 * @var string
	 */
	const MARKER = 'WebP Generator';

	/**
	 * Absolute path to the site's root .htaccess -- same location
	 * WordPress manages its own rewrite block in, found the same way
	 * core does (get_home_path() rather than ABSPATH, since WordPress
	 * can be installed in a subdirectory while the site root, and the
	 * .htaccess that actually matters, is one level up).
	 *
	 * @return string
	 */
	public static function get_path() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		return get_home_path() . '.htaccess';
	}

	/**
	 * Whether this looks like Apache with mod_rewrite -- the same check
	 * WordPress's own Permalinks screen uses to decide between offering
	 * to write .htaccess automatically versus just showing the rule to
	 * copy in manually.
	 *
	 * @return bool
	 */
	public static function is_apache_with_mod_rewrite() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return got_mod_rewrite();
	}

	/**
	 * Best-effort web server identification from `$_SERVER['SERVER_SOFTWARE']`
	 * -- the same signal `apache_mod_loaded()`'s own fallback path relies
	 * on when the `apache_get_modules()` function isn't available (e.g.
	 * under LiteSpeed's LSAPI, or any non-mod_php SAPI). Only used to
	 * decide what to tell the user, never to gate anything security- or
	 * correctness-sensitive.
	 *
	 * @return string One of 'apache', 'litespeed', 'nginx', 'iis', or
	 *                'unknown'.
	 */
	public static function detect_server() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'microsoft-iis' ) ) {
			return 'iis';
		}

		return 'unknown';
	}

	/**
	 * Whether install()/remove() can actually do anything here. Broader
	 * than is_apache_with_mod_rewrite() alone: LiteSpeed Web Server reads
	 * Apache-syntax .htaccess/mod_rewrite directives natively (confirmed
	 * against LiteSpeed's own docs), but PHP's apache_get_modules() --
	 * and so got_mod_rewrite() -- isn't available under LiteSpeed's LSAPI
	 * handler, so relying on that check alone would wrongly tell a
	 * LiteSpeed site to configure things manually.
	 *
	 * @return bool
	 */
	public static function can_auto_install() {
		return self::is_apache_with_mod_rewrite() || 'litespeed' === self::detect_server();
	}

	/**
	 * Official documentation for configuring the detected server manually,
	 * for servers can_auto_install() can't handle. Deliberately only
	 * pointing at documentation this plugin's author actually verified is
	 * real and current, not a guessed URL -- a dead or wrong link here is
	 * worse than no link.
	 *
	 * @return string Empty string if there's no good specific link for
	 *                the detected server (e.g. unknown, or one we handle
	 *                automatically anyway).
	 */
	public static function get_server_doc_link() {
		switch ( self::detect_server() ) {
			case 'nginx':
				return 'https://developer.wordpress.org/advanced-administration/server/web-server/nginx/';
			case 'iis':
				return 'https://learn.microsoft.com/en-us/iis/extensions/url-rewrite-module/using-the-url-rewrite-module';
			default:
				return '';
		}
	}

	/**
	 * The rule itself, as an array of lines (the format
	 * insert_with_markers() wants) -- the single source of truth both the
	 * auto-install and the manually-copyable example in the admin page
	 * render from, so they can't drift apart.
	 *
	 * AVIF's RewriteCond/RewriteRule pair comes before WebP's -- best
	 * format wins when a file has both siblings -- and each is
	 * independently gated by its own `-f` existence check, so a file with
	 * only one of the two derived files still falls back correctly to
	 * whichever it actually has, then to the original if neither exists.
	 * The AVIF pair (and its AddType) is only ever emitted at all if this
	 * server can actually encode AVIF -- otherwise it would be dead,
	 * misleading configuration that can never match a real file, and
	 * existing single-format installs would gain lines implying a
	 * capability they don't have.
	 *
	 * @return string[]
	 */
	public static function get_rule_lines() {
		$lines = array(
			'<IfModule mod_rewrite.c>',
			'    RewriteEngine On',
		);

		if ( WWG_Format::has_support( WWG_Format::AVIF ) ) {
			$lines[] = '    RewriteCond %{HTTP_ACCEPT} image/avif';
			$lines[] = '    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png)$';
			$lines[] = '    RewriteCond %1.avif -f';
			$lines[] = '    RewriteRule ^(wp-content/uploads/.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1,L]';
		}

		$lines[] = '    RewriteCond %{HTTP_ACCEPT} image/webp';
		$lines[] = '    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png)$';
		$lines[] = '    RewriteCond %1.webp -f';
		$lines[] = '    RewriteRule ^(wp-content/uploads/.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1,L]';
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '    Header append Vary Accept env=accept';
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_mime.c>';

		if ( WWG_Format::has_support( WWG_Format::AVIF ) ) {
			$lines[] = '    AddType image/avif .avif';
		}
		$lines[] = '    AddType image/webp .webp';
		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * get_rule_lines(), rendered as syntax-highlighted HTML for display in
	 * the admin UI -- never used for the actual .htaccess write, which
	 * always goes through the plain get_rule_lines() above so the two
	 * can't drift out of sync (this just decorates the same lines).
	 *
	 * Deliberately hand-rolled rather than pulling in a JS highlighting
	 * library (e.g. Prism/highlight.js): it's a fixed, small, known set
	 * of Apache directives, not arbitrary user code, so a full general
	 * -purpose highlighter would be a lot of dependency for one <pre>
	 * block wp-admin doesn't otherwise load anything like it for.
	 *
	 * @return string Safe HTML, one line per array entry joined with
	 *                "\n", ready to place inside a <pre>.
	 */
	public static function get_rule_html() {
		$html_lines = array();

		foreach ( self::get_rule_lines() as $line ) {
			if ( ! preg_match( '/^(\s*)(.*)$/', $line, $indent_match ) ) {
				$html_lines[] = esc_html( $line );
				continue;
			}

			$indent = $indent_match[1];
			$rest   = $indent_match[2];

			if ( '' === $rest ) {
				$html_lines[] = '';
			} elseif ( '#' === $rest[0] ) {
				$html_lines[] = $indent . '<span class="wwg-tok-comment">' . esc_html( $rest ) . '</span>';
			} elseif ( '<' === $rest[0] ) {
				$html_lines[] = $indent . '<span class="wwg-tok-tag">' . esc_html( $rest ) . '</span>';
			} elseif ( preg_match( '/^(\S+)(\s+)(.*)$/', $rest, $directive_match ) ) {
				$html_lines[] = $indent
					. '<span class="wwg-tok-keyword">' . esc_html( $directive_match[1] ) . '</span>'
					. esc_html( $directive_match[2] )
					. self::highlight_rule_value( $directive_match[3] );
			} else {
				$html_lines[] = $indent . esc_html( $rest );
			}
		}

		return implode( "\n", $html_lines );
	}

	/**
	 * Highlights the value/argument portion of one rule directive (i.e.
	 * everything after the directive keyword): Apache rewrite variables
	 * and backreferences (%{HTTP_ACCEPT}, %1, $1) as one token, a
	 * trailing [flag,list] as another. Operates on already-escaped text,
	 * since none of these tokens contain characters esc_html() touches
	 * ({ } % $ [ ]), so escaping first and highlighting after can't
	 * reopen an XSS hole.
	 *
	 * @param string $value Unescaped directive argument text.
	 * @return string Safe HTML.
	 */
	private static function highlight_rule_value( $value ) {
		$escaped = esc_html( $value );
		$escaped = preg_replace( '/(%\{[A-Z_]+\}|[%$]\d+)/', '<span class="wwg-tok-variable">$1</span>', $escaped );
		$escaped = preg_replace( '/(\[[^\]]+\])$/', '<span class="wwg-tok-flag">$1</span>', $escaped );

		return $escaped;
	}

	/**
	 * Whether our marked block is currently present in .htaccess.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return (bool) extract_from_markers( self::get_path(), self::MARKER );
	}

	/**
	 * Whether an already-installed rule still matches what get_rule_lines()
	 * would write today. install() only ever writes whatever
	 * get_rule_lines() returns at the moment it's clicked -- there's no
	 * automatic re-sync -- so a rule installed before this server gained
	 * AVIF support (a host upgrading its Imagick/GD build, or before this
	 * plugin added AVIF at all) can genuinely go stale sitting there,
	 * still perfectly correctly serving WebP, just silently never
	 * upgraded to also serve AVIF. Meaningless (and reported as "true":
	 * nothing to be out of date about) if nothing is installed at all --
	 * see is_installed() for that question instead.
	 *
	 * @return bool
	 */
	public static function is_up_to_date() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$installed = extract_from_markers( self::get_path(), self::MARKER );
		if ( empty( $installed ) ) {
			return true;
		}

		return self::get_rule_lines() === $installed;
	}

	/**
	 * Write the rule into .htaccess inside our marked section, replacing
	 * it in place if already present. Doesn't matter whether this ends up
	 * before or after WordPress's own "# BEGIN WordPress" block -- both
	 * rules are guarded by file-existence conditions that only ever match
	 * one or the other, never both, so relative order is inert here.
	 *
	 * @return bool True on success. False if the file isn't writable
	 *              (e.g. a host that requires FTP/SSH credentials WP
	 *              doesn't have) or can_auto_install() is false.
	 */
	public static function install() {
		if ( ! self::can_auto_install() ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return (bool) insert_with_markers( self::get_path(), self::MARKER, self::get_rule_lines() );
	}

	/**
	 * Empty out our marked section again, leaving the rest of .htaccess
	 * untouched. Offered in the admin UI alongside install() so adding
	 * the rule isn't a one-way door.
	 *
	 * Note: insert_with_markers() (core's own mechanism, used as-is here
	 * rather than hand-rolling file surgery on .htaccess) never deletes
	 * the "# BEGIN ... # END" marker lines themselves, only what's
	 * between them -- this leaves an empty, inert marked block behind
	 * rather than removing all trace of it. That's the same way
	 * WordPress's own "# BEGIN WordPress" block behaves (e.g. switching
	 * permalinks to "Plain" empties it the same way), so it's expected,
	 * not a bug.
	 *
	 * @return bool True on success.
	 */
	public static function remove() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return (bool) insert_with_markers( self::get_path(), self::MARKER, array() );
	}
}
