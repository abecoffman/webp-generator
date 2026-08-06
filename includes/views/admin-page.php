<?php
/**
 * Tools > WebP Generator admin page shell. All the actual work happens
 * via AJAX (see assets/admin.js and WWG_Admin::handle_ajax()).
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$webp_ok = $readiness['has_webp_support'];

$server_names = array(
	'apache'    => 'Apache',
	'litespeed' => 'LiteSpeed',
	'nginx'     => 'Nginx',
	'iis'       => 'IIS',
	'unknown'   => __( 'your server', 'wp-webp-generator' ),
);
$server_name  = $server_names[ $readiness['server_type'] ];
?>
<div class="wrap wwg-wrap">
	<h1><?php esc_html_e( 'WebP Generator', 'wp-webp-generator' ); ?></h1>
	<p class="wwg-page-lede">
		<?php esc_html_e( 'WordPress creates a .webp version of every new image you upload automatically. Use this page to generate .webp versions for images uploaded before that started, and to set up your server to actually serve them.', 'wp-webp-generator' ); ?>
	</p>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides which notice text to display, same as core's own options.php "settings-updated" check; the actual save already went through a real nonce check in WWG_Admin::maybe_save_settings() before this redirect happened. ?>
	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wp-webp-generator' ); ?></p></div>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display lookup, not a write; see the note above the settings-updated check. The real nonce check already happened in WWG_Admin::maybe_handle_htaccess_action() before this redirect.
	$htaccess_result  = isset( $_GET['wwg-htaccess'] ) ? sanitize_key( $_GET['wwg-htaccess'] ) : '';
	$htaccess_notices = array(
		'install-success' => array( 'success', __( '.htaccess updated -- the WebP rule is now active.', 'wp-webp-generator' ) ),
		'install-failed'  => array( 'error', __( "Couldn't write to .htaccess. It may not be writable by PHP on this host -- use the manual rule below instead.", 'wp-webp-generator' ) ),
		'remove-success'  => array( 'success', __( '.htaccess updated -- the WebP rule was removed.', 'wp-webp-generator' ) ),
		'remove-failed'   => array( 'error', __( "Couldn't write to .htaccess to remove the rule.", 'wp-webp-generator' ) ),
	);
	?>
	<?php if ( isset( $htaccess_notices[ $htaccess_result ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $htaccess_notices[ $htaccess_result ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $htaccess_notices[ $htaccess_result ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $webp_ok ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Nothing here will work yet.', 'wp-webp-generator' ); ?></strong>
				<?php esc_html_e( 'Neither Imagick nor GD on this server was compiled with WebP support, so this plugin has no way to create .webp files. Ask your host to enable one, or switch to a PHP build that has it.', 'wp-webp-generator' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Generate WebP Images', 'wp-webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Scan your media library for images missing a .webp version, then generate them.', 'wp-webp-generator' ); ?></p>
		</div>

		<div class="wwg-panel" data-webp-supported="<?php echo $webp_ok ? '1' : '0'; ?>">
			<div class="wwg-actions">
				<button type="button" class="button button-primary" id="wwg-scan"<?php echo $webp_ok ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Scan', 'wp-webp-generator' ); ?>
				</button>
				<button type="button" class="button button-primary" id="wwg-generate" disabled<?php echo $webp_ok ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Generate', 'wp-webp-generator' ); ?>
				</button>
				<button type="button" class="button" id="wwg-cancel" disabled>
					<?php esc_html_e( 'Cancel', 'wp-webp-generator' ); ?>
				</button>
			</div>

			<div id="wwg-progress" class="wwg-progress" hidden>
				<div class="wwg-progress-bar">
					<div class="wwg-progress-bar-fill"></div>
				</div>
				<p class="wwg-progress-label"></p>
			</div>

			<p id="wwg-summary" class="wwg-summary"></p>

			<div class="wwg-stats" id="wwg-convert-results" hidden>
				<div class="wwg-stat">
					<span class="wwg-stat-value" id="wwg-c-failed">–</span>
					<span class="wwg-stat-label"><?php esc_html_e( 'Failed', 'wp-webp-generator' ); ?></span>
				</div>
				<div class="wwg-stat wwg-stat--wide">
					<span class="wwg-stat-value" id="wwg-c-bytes">–</span>
					<span class="wwg-stat-label"><?php esc_html_e( 'New size vs. originals', 'wp-webp-generator' ); ?></span>
				</div>
			</div>

			<p class="wwg-log" id="wwg-log" aria-live="polite"></p>
		</div>
	</div>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Rewrite Rules', 'wp-webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Generating .webp files does nothing on its own -- your server needs a rule to serve them instead of the original when a browser supports it.', 'wp-webp-generator' ); ?></p>
		</div>

		<?php if ( $readiness['htaccess_installed'] ) : ?>
			<p>✅ <?php esc_html_e( 'Detected in your .htaccess already.', 'wp-webp-generator' ); ?></p>
			<form method="post" class="wwg-inline-form">
				<?php wp_nonce_field( WWG_Admin::HTACCESS_NONCE ); ?>
				<input type="hidden" name="wwg_htaccess_action" value="remove" />
				<button type="submit" class="button"><?php esc_html_e( 'Remove it', 'wp-webp-generator' ); ?></button>
			</form>
		<?php elseif ( $readiness['can_auto_install'] ) : ?>
			<form method="post" class="wwg-inline-form">
				<?php wp_nonce_field( WWG_Admin::HTACCESS_NONCE ); ?>
				<input type="hidden" name="wwg_htaccess_action" value="install" />
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: .htaccess path */ __( 'Add this rule to %s automatically', 'wp-webp-generator' ), $readiness['htaccess_path'] ) ); ?></button>
			</form>
			<p class="wwg-status-detail--muted">
				<?php
				printf(
					/* translators: %s: detected server name, e.g. "Apache". */
					esc_html__( 'Detected %s. This only adds a clearly-marked block WordPress\'s own mechanism manages -- it can be removed the same way it was added, any time, and won\'t touch the rest of your .htaccess.', 'wp-webp-generator' ),
					esc_html( $server_name )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: detected server name, e.g. "Nginx". */
					esc_html__( "This plugin can't configure %s automatically -- add the equivalent rule yourself using the example below.", 'wp-webp-generator' ),
					esc_html( $server_name )
				);
				?>
			</p>
			<?php if ( $readiness['server_doc_link'] ) : ?>
				<p>
					<a href="<?php echo esc_url( $readiness['server_doc_link'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( sprintf( /* translators: %s: detected server name. */ __( 'How to configure %s', 'wp-webp-generator' ), $server_name ) ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<details<?php echo ( $readiness['htaccess_installed'] || $readiness['can_auto_install'] ) ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Example rule for Apache (.htaccess)', 'wp-webp-generator' ); ?></summary>
			<?php
			// phpcs:disable Squiz.PHP.EmbeddedPhp.ContentBeforeOpen, Squiz.PHP.EmbeddedPhp.ContentAfterEnd -- deliberately NOT giving the PHP tags their own line here: <pre> makes surrounding whitespace significant, and a leading/trailing newline+indentation from "tag on its own line" would show up as a visible blank line and stray leading tabs in the rendered code block (this exact regression happened once already, from an automated formatter that doesn't know about the <pre> context -- see git blame before reverting this again).
			// A PHP closing tag swallows exactly one trailing newline, so
			// splitting this across two echo statements on separate
			// lines silently loses the line break between them -- keep
			// it in one block with an explicit "\n" instead.
			?>
			<pre class="wwg-code"><?php
			echo '<span class="wwg-tok-comment">' . esc_html( '# ' . __( 'Add near the top of your .htaccess, before any WordPress rewrite rules.', 'wp-webp-generator' ) ) . "</span>\n";
			echo WWG_Htaccess::get_rule_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already HTML-escaped internally (every dynamic piece goes through esc_html() before being wrapped in <span> markup); it returns markup, not plain text, so a second pass through esc_html() here would double-escape it.
			?></pre>
			<?php // phpcs:enable Squiz.PHP.EmbeddedPhp.ContentBeforeOpen, Squiz.PHP.EmbeddedPhp.ContentAfterEnd ?>
			<p class="wwg-status-detail--muted"><?php esc_html_e( 'On Nginx, IIS, or another server: same idea (serve the .webp sibling when it exists and the browser sends Accept: image/webp), different syntax.', 'wp-webp-generator' ); ?></p>
		</details>
	</div>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Settings', 'wp-webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Applies to images converted from now on -- existing .webp files are never regenerated automatically.', 'wp-webp-generator' ); ?></p>
		</div>

		<form method="post">
			<?php wp_nonce_field( WWG_Admin::SETTINGS_NONCE ); ?>
			<div class="wwg-field">
				<label for="wwg-quality"><?php esc_html_e( 'WebP quality', 'wp-webp-generator' ); ?></label>
				<p class="wwg-field-description"><?php esc_html_e( '1-100. Higher looks better but produces larger files; 75 is a reasonable default.', 'wp-webp-generator' ); ?></p>
				<input type="number" id="wwg-quality" name="wwg_quality" min="1" max="100" value="<?php echo esc_attr( $readiness['quality'] ); ?>" class="small-text" />
			</div>
			<button type="submit" name="wwg_save_settings" value="1" class="button"><?php esc_html_e( 'Save settings', 'wp-webp-generator' ); ?></button>
		</form>
	</div>
</div>
