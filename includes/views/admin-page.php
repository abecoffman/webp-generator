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

$enabled_formats = array();
foreach ( $readiness['formats'] as $format ) {
	if ( $format['enabled'] ) {
		$enabled_formats[] = $format;
	}
}
$any_format_enabled = ! empty( $enabled_formats );

// One dynamic sentence reflecting what's actually happening, rather than
// a static WebP-only one -- "WebP", "AVIF", or "WebP and AVIF" depending
// on what this server can really produce. WWG_Format::PRIORITY order
// (avif, webp) reads oddly in prose ("AVIF and WebP"), so this
// deliberately lists WebP first here -- the two orderings serve different
// purposes (best-match-wins for the rewrite rule vs. reads naturally as English).
$format_labels_prose = wp_list_pluck( array_reverse( $enabled_formats ), 'label' );
$format_list_and     = implode( ' ' . __( 'and', 'webp-generator' ) . ' ', $format_labels_prose );

$card_heading = $any_format_enabled
	/* translators: %s: format name(s), e.g. "WebP" or "WebP and AVIF". */
	? sprintf( __( 'Generate %s Images', 'webp-generator' ), $format_list_and )
	: __( 'Generate Images', 'webp-generator' );

$server_names = array(
	'apache'    => 'Apache',
	'litespeed' => 'LiteSpeed',
	'nginx'     => 'Nginx',
	'iis'       => 'IIS',
	'unknown'   => __( 'your server', 'webp-generator' ),
);
$server_name  = $server_names[ $readiness['server_type'] ];
?>
<div class="wrap wwg-wrap">
	<h1><?php esc_html_e( 'WebP Generator', 'webp-generator' ); ?></h1>
	<p class="wwg-page-lede">
		<?php if ( $any_format_enabled ) : ?>
			<?php
			printf(
				/* translators: %s: format name(s), e.g. "a WebP version" or "a WebP and AVIF version". */
				esc_html__( 'WordPress creates %s of every new image you upload automatically. Use this page to generate versions for images uploaded before that started, and to set up your server to actually serve them.', 'webp-generator' ),
				/* translators: %s: format names joined with "and" if more than one, e.g. "WebP" or "WebP and AVIF". */
				esc_html( sprintf( __( 'a %s version', 'webp-generator' ), $format_list_and ) )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'WordPress creates a .webp version of every new image you upload automatically. Use this page to generate .webp versions for images uploaded before that started, and to set up your server to actually serve them.', 'webp-generator' ); ?>
		<?php endif; ?>
	</p>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides which notice text to display, same as core's own options.php "settings-updated" check; the actual save already went through a real nonce check in WWG_Admin::maybe_save_settings() before this redirect happened. ?>
	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'webp-generator' ); ?></p></div>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display lookup, not a write; see the note above the settings-updated check. The real nonce check already happened in WWG_Admin::maybe_handle_htaccess_action() before this redirect.
	$htaccess_result  = isset( $_GET['wwg-htaccess'] ) ? sanitize_key( $_GET['wwg-htaccess'] ) : '';
	$htaccess_notices = array(
		'install-success' => array( 'success', __( '.htaccess updated -- the rewrite rule is now active.', 'webp-generator' ) ),
		'install-failed'  => array( 'error', __( "Couldn't write to .htaccess. It may not be writable by PHP on this host -- use the manual rule below instead.", 'webp-generator' ) ),
		'remove-success'  => array( 'success', __( '.htaccess updated -- the rewrite rule was removed.', 'webp-generator' ) ),
		'remove-failed'   => array( 'error', __( "Couldn't write to .htaccess to remove the rule.", 'webp-generator' ) ),
	);
	?>
	<?php if ( isset( $htaccess_notices[ $htaccess_result ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $htaccess_notices[ $htaccess_result ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $htaccess_notices[ $htaccess_result ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $any_format_enabled ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Nothing here will work yet.', 'webp-generator' ); ?></strong>
				<?php esc_html_e( 'Neither Imagick nor GD on this server was compiled with WebP or AVIF support, so this plugin has no way to create either. Ask your host to enable one, or switch to a PHP build that has it.', 'webp-generator' ); ?>
			</p>
		</div>
	<?php else : ?>
		<?php
		// A format the server genuinely can't encode gets no notice at
		// all here (that's just this host's normal capability, nothing
		// to act on) -- only a format that's SUPPORTED but not currently
		// ENABLED (a wwg_enabled_formats filter is narrowing it) is worth
		// a quiet, informational note, distinct from the hard-stop error
		// above. No admin-facing toggle exists to turn it back on from
		// here -- that's a deliberate developer-level decision this page
		// only reports on, never overrides.
		$filtered_off = array();
		foreach ( $readiness['formats'] as $format ) {
			if ( $format['supported'] && ! $format['enabled'] ) {
				$filtered_off[] = $format['label'];
			}
		}
		?>
		<?php if ( ! empty( $filtered_off ) ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: %s: format name(s) turned off by a filter, e.g. "AVIF". */
						esc_html__( '%s encoding is available on this server, but has been turned off by a wwg_enabled_formats customization.', 'webp-generator' ),
						esc_html( implode( ', ', $filtered_off ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php echo esc_html( $card_heading ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Scan your media library for images missing a converted version, then generate them.', 'webp-generator' ); ?></p>
		</div>

		<div class="wwg-panel" data-any-format-enabled="<?php echo $any_format_enabled ? '1' : '0'; ?>">

			<!-- Region 1: Library Status -- known current state, always
				present (even before any scan has ever run). Rendered
				entirely by admin.js from wwgAdmin.scanState, a snapshot
				of Scan's last completed pass that survives a reload --
				see WWG_Admin::OPTION_SCAN_STATE. -->
			<div class="wwg-status-box" id="wwg-status-box">
				<div class="wwg-status-header">
					<span class="wwg-status-dot" id="wwg-status-dot"></span>
					<span class="wwg-status-chip-label" id="wwg-status-chip-label"><?php esc_html_e( 'Library status', 'webp-generator' ); ?></span>
				</div>

				<div id="wwg-status-idle">
					<p class="wwg-status-headline" id="wwg-status-headline"></p>
					<p class="wwg-status-meta" id="wwg-status-meta"></p>
				</div>

				<div id="wwg-status-progress" class="wwg-progress" hidden>
					<div class="wwg-progress-bar">
						<div class="wwg-progress-bar-fill"></div>
					</div>
					<p class="wwg-progress-label"></p>
				</div>
				<p class="wwg-log" id="wwg-status-log" aria-live="polite" hidden></p>

				<details class="wwg-failures" id="wwg-status-failures" hidden>
					<summary>
						<?php esc_html_e( 'Failed conversions', 'webp-generator' ); ?>
						(<span id="wwg-status-failures-count">0</span>)
					</summary>
					<ul class="wwg-failures-list" id="wwg-status-failures-list"></ul>
				</details>
			</div>

			<!-- Region 2: Actions -- available now. Never lives inside
				either status box. -->
			<div class="wwg-actions">
				<button type="button" class="button button-primary" id="wwg-scan"<?php echo $any_format_enabled ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Scan', 'webp-generator' ); ?>
				</button>
				<button type="button" class="button button-primary" id="wwg-generate" disabled<?php echo $any_format_enabled ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Generate', 'webp-generator' ); ?>
				</button>
				<button type="button" class="button" id="wwg-cancel" disabled>
					<?php esc_html_e( 'Cancel', 'webp-generator' ); ?>
				</button>
			</div>

			<!-- Region 3: Generate Results -- absent until Generate has
				ever run (admin.js unhides it); live progress while
				running/paused, settling into a dated "last run" record
				once done (WWG_Job's persisted state, unchanged). -->
			<div class="wwg-results-box" id="wwg-results-box" hidden>
				<div class="wwg-results-header">
					<span class="wwg-status-dot" id="wwg-results-dot"></span>
					<span class="wwg-status-chip-label" id="wwg-results-chip-label"></span>
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
						<span class="wwg-stat-label"><?php esc_html_e( 'Failed', 'webp-generator' ); ?></span>
					</div>
					<!-- One "<format> vs. originals" tile per enabled format, built by admin.js from wwgAdmin.enabledFormats/formatLabels -- 1 tile on a single-format server, 2 side by side when both WebP and AVIF are active. -->
					<div id="wwg-c-bytes-tiles"></div>
				</div>

				<details class="wwg-failures" id="wwg-failures" hidden>
					<summary>
						<?php esc_html_e( 'Failed conversions', 'webp-generator' ); ?>
						(<span id="wwg-failures-count">0</span>)
					</summary>
					<ul class="wwg-failures-list" id="wwg-failures-list"></ul>
				</details>

				<details class="wwg-recoveries" id="wwg-recoveries" hidden>
					<summary>
						<?php esc_html_e( 'Recovered from embedded data', 'webp-generator' ); ?>
						(<span id="wwg-recoveries-count">0</span>)
					</summary>
					<p class="wwg-recoveries-note"><?php esc_html_e( 'A derived file was created successfully for each entry below, but the original itself still has stray bytes before the real image data starts -- worth a look (or re-exporting from the source) if you still have it.', 'webp-generator' ); ?></p>
					<ul class="wwg-recoveries-list" id="wwg-recoveries-list"></ul>
				</details>

				<p class="wwg-log" id="wwg-log" aria-live="polite" hidden></p>
			</div>
		</div>
	</div>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Rewrite Rules', 'webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Generating these files does nothing on its own -- your server needs a rule to serve the best one a browser supports, instead of the original.', 'webp-generator' ); ?></p>
		</div>

		<?php if ( $readiness['htaccess_installed'] && $readiness['htaccess_up_to_date'] ) : ?>
			<p>✅ <?php esc_html_e( 'Detected in your .htaccess already.', 'webp-generator' ); ?></p>
			<form method="post" class="wwg-inline-form">
				<?php wp_nonce_field( WWG_Admin::HTACCESS_NONCE ); ?>
				<input type="hidden" name="wwg_htaccess_action" value="remove" />
				<button type="submit" class="button"><?php esc_html_e( 'Remove it', 'webp-generator' ); ?></button>
			</form>
		<?php elseif ( $readiness['htaccess_installed'] ) : // installed, but stale -- see WWG_Htaccess::is_up_to_date(). ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( "Your installed rule doesn't include everything it should yet.", 'webp-generator' ); ?></strong>
					<?php esc_html_e( 'It was added before this server (or this plugin) supported everything it does now. What it already serves keeps working; update it to also serve the rest.', 'webp-generator' ); ?>
				</p>
			</div>
			<form method="post" class="wwg-inline-form">
				<?php wp_nonce_field( WWG_Admin::HTACCESS_NONCE ); ?>
				<input type="hidden" name="wwg_htaccess_action" value="install" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Update the rule', 'webp-generator' ); ?></button>
			</form>
		<?php elseif ( $readiness['can_auto_install'] ) : ?>
			<form method="post" class="wwg-inline-form">
				<?php wp_nonce_field( WWG_Admin::HTACCESS_NONCE ); ?>
				<input type="hidden" name="wwg_htaccess_action" value="install" />
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: .htaccess path */ __( 'Add this rule to %s automatically', 'webp-generator' ), $readiness['htaccess_path'] ) ); ?></button>
			</form>
			<p class="wwg-status-detail--muted">
				<?php
				printf(
					/* translators: %s: detected server name, e.g. "Apache". */
					esc_html__( 'Detected %s. This only adds a clearly-marked block WordPress\'s own mechanism manages -- it can be removed the same way it was added, any time, and won\'t touch the rest of your .htaccess.', 'webp-generator' ),
					esc_html( $server_name )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: detected server name, e.g. "Nginx". */
					esc_html__( "This plugin can't configure %s automatically -- add the equivalent rule yourself using the example below.", 'webp-generator' ),
					esc_html( $server_name )
				);
				?>
			</p>
			<?php if ( $readiness['server_doc_link'] ) : ?>
				<p>
					<a href="<?php echo esc_url( $readiness['server_doc_link'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( sprintf( /* translators: %s: detected server name. */ __( 'How to configure %s', 'webp-generator' ), $server_name ) ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<details<?php echo ( $readiness['htaccess_installed'] || $readiness['can_auto_install'] ) ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Example rule for Apache (.htaccess)', 'webp-generator' ); ?></summary>
			<?php
			// phpcs:disable Squiz.PHP.EmbeddedPhp.ContentBeforeOpen, Squiz.PHP.EmbeddedPhp.ContentAfterEnd -- deliberately NOT giving the PHP tags their own line here: <pre> makes surrounding whitespace significant, and a leading/trailing newline+indentation from "tag on its own line" would show up as a visible blank line and stray leading tabs in the rendered code block (this exact regression happened once already, from an automated formatter that doesn't know about the <pre> context -- see git blame before reverting this again).
			// A PHP closing tag swallows exactly one trailing newline, so
			// splitting this across two echo statements on separate
			// lines silently loses the line break between them -- keep
			// it in one block with an explicit "\n" instead.
			?>
			<pre class="wwg-code"><?php
			echo '<span class="wwg-tok-comment">' . esc_html( '# ' . __( 'Add near the top of your .htaccess, before any WordPress rewrite rules.', 'webp-generator' ) ) . "</span>\n";
			echo WWG_Htaccess::get_rule_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already HTML-escaped internally (every dynamic piece goes through esc_html() before being wrapped in <span> markup); it returns markup, not plain text, so a second pass through esc_html() here would double-escape it.
			?></pre>
			<?php // phpcs:enable Squiz.PHP.EmbeddedPhp.ContentBeforeOpen, Squiz.PHP.EmbeddedPhp.ContentAfterEnd ?>
			<p class="wwg-status-detail--muted"><?php esc_html_e( 'On Nginx, IIS, or another server: same idea (serve the best derived sibling that exists and the browser accepts, falling back through AVIF, then WebP, then the original), different syntax.', 'webp-generator' ); ?></p>
		</details>
	</div>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Settings', 'webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Applies to images converted from now on -- existing files are never regenerated automatically.', 'webp-generator' ); ?></p>
		</div>

		<?php if ( $any_format_enabled ) : ?>
			<form method="post">
				<?php wp_nonce_field( WWG_Admin::SETTINGS_NONCE ); ?>
				<?php foreach ( $enabled_formats as $format ) : ?>
					<div class="wwg-field">
						<label for="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>">
							<?php echo esc_html( sprintf( /* translators: %s: format label, e.g. "WebP". */ __( '%s quality', 'webp-generator' ), $format['label'] ) ); ?>
						</label>
						<p class="wwg-field-description"><?php esc_html_e( '1-100. Higher looks better but produces larger files; 75 is a reasonable default.', 'webp-generator' ); ?></p>
						<input
							type="number"
							id="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>"
							name="<?php echo esc_attr( $format['option_name'] ); ?>"
							min="1" max="100"
							value="<?php echo esc_attr( $format['quality'] ); ?>"
							class="small-text"
						/>
					</div>
				<?php endforeach; ?>
				<?php if ( count( $enabled_formats ) > 1 ) : ?>
					<p class="wwg-quality-note"><?php esc_html_e( "These quality scales aren't perceptually equivalent at the same number -- AVIF at a given number often looks better and smaller than WebP at that same number. Each is independent; adjust to taste.", 'webp-generator' ); ?></p>
				<?php endif; ?>
				<button type="submit" name="wwg_save_settings" value="1" class="button"><?php esc_html_e( 'Save settings', 'webp-generator' ); ?></button>
			</form>
		<?php else : ?>
			<p class="wwg-status-detail--muted"><?php esc_html_e( 'No quality setting to show -- this server can\'t currently produce any of the formats this plugin supports.', 'webp-generator' ); ?></p>
		<?php endif; ?>
	</div>
</div>
