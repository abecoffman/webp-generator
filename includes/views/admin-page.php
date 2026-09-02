<?php
/**
 * Tools > WebP Generator admin page shell. All the actual work happens
 * via AJAX (see assets/admin.js and WWG_Job's start/status/drive/cancel
 * endpoints).
 *
 * @package WWG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$enabled_formats   = array();
$supported_formats = array();
foreach ( $readiness['formats'] as $format ) {
	if ( $format['enabled'] ) {
		$enabled_formats[] = $format;
	}
	if ( $format['supported'] ) {
		$supported_formats[] = $format;
	}
}
$any_format_enabled = ! empty( $enabled_formats );
// Distinct from $any_format_enabled: a format the site owner has simply
// unchecked in Settings is still SUPPORTED -- the hard-stop "nothing here
// will work" error further down is about server capability, not about
// what's currently turned on, so it must gate on this, not that (see the
// notice itself for why conflating the two would be actively wrong once
// a real Settings checkbox exists).
$any_format_supported = ! empty( $supported_formats );

$card_heading = __( 'Bulk Image Backfill Utility', 'webp-generator' );

$server_names = array(
	'apache'    => 'Apache',
	'litespeed' => 'LiteSpeed',
	'nginx'     => 'Nginx',
	'iis'       => 'IIS',
	'unknown'   => __( 'your server', 'webp-generator' ),
);
$server_name  = $server_names[ $readiness['server_type'] ];

// One shared use-case/quality table for the Settings card below, rather
// than repeating the same three rows once per format -- columns are
// whichever formats this server actually supports (not just currently
// enabled ones: the table is reference material for deciding whether to
// turn a format on in the first place, so it stays visible even for one
// that's currently unchecked). Every format's quality_guide() has the
// same tier labels in the same order (see WWG_Admin::quality_guide_for()),
// only the numeric ranges differ, so zipping them by index is safe.
$quality_table_columns = array();
foreach ( $supported_formats as $format ) {
	$quality_table_columns[ $format['id'] ] = $format['label'];
}
$quality_table_rows = array();
if ( ! empty( $supported_formats ) ) {
	$first_format_guide = reset( $supported_formats )['quality_guide'];
	foreach ( $first_format_guide as $tier_index => $first_tier ) {
		$row = array( 'label' => $first_tier['label'] );
		foreach ( $supported_formats as $format ) {
			$row[ $format['id'] ] = $format['quality_guide'][ $tier_index ]['range'];
		}
		$quality_table_rows[] = $row;
	}
}

?>
<div class="wrap wwg-wrap">
	<h1><?php esc_html_e( 'WebP Generator', 'webp-generator' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display lookup, not a write. The real nonce check already happened in WWG_Admin::maybe_handle_htaccess_action() before this redirect. (Settings itself no longer redirects at all -- see admin.js's wireSettingsAutosave() -- so there's no equivalent "settings-updated" query-arg notice here anymore.)
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

	<?php if ( ! $any_format_supported ) : ?>
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
		// to act on). A format the site owner simply unchecked in
		// Settings below also gets none -- that's self-evident right
		// there in the checkbox, not something to also announce up here.
		// The only case still worth a quiet, informational note: the
		// site owner's own checkbox says on, the server can do it, but a
		// developer's wwg_enabled_formats filter is still overriding
		// that -- otherwise checking the box would look like it did
		// nothing, with no explanation why.
		$filtered_off = array();
		foreach ( $readiness['formats'] as $format ) {
			if ( $format['supported'] && $format['user_wants'] && ! $format['enabled'] ) {
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

	<!-- Settings comes first: for someone installing this plugin fresh,
		"which formats do I want, and at what quality" is the first real
		decision to make -- it belongs ahead of Scan/Generate, not after
		it. -->
	<div class="wwg-card">
		<div class="wwg-card-header">
			<div class="wwg-card-title-row">
				<h2><?php esc_html_e( 'Settings', 'webp-generator' ); ?></h2>
				<?php if ( ! empty( $quality_table_rows ) ) : ?>
					<!-- A sibling of the <h2>, not inside it -- a <table>
						isn't valid heading content, and nesting one in
						there would also make a screen reader announce the
						whole table as part of the "Settings" heading's
						name every time it's reached. .wwg-card-title-row's
						own flex layout is what actually places this next
						to the title visually. Hover/focus-revealed, not
						shown inline -- the table is reference material for
						deciding, not something that needs to sit
						permanently on screen once a format's already set
						up, and hiding it behind this is what keeps the
						whole card down to just a checkbox+slider per
						format. Pure CSS (:hover/:focus-within), no JS: a
						keyboard user tabbing to the trigger opens it
						exactly the same way a mouse hovering it does. -->
					<div class="wwg-help">
						<button type="button" class="wwg-help-trigger wwg-help-trigger--icon" aria-describedby="wwg-quality-help-tooltip">
							<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'What quality should I use?', 'webp-generator' ); ?></span>
						</button>
						<div class="wwg-help-tooltip" id="wwg-quality-help-tooltip" role="tooltip">
							<div class="wwg-quality-table-wrap">
								<table class="wwg-quality-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'Quality Guidance', 'webp-generator' ); ?></th>
											<?php foreach ( $quality_table_columns as $column_label ) : ?>
												<th scope="col"><?php echo esc_html( $column_label ); ?></th>
											<?php endforeach; ?>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $quality_table_rows as $row ) : ?>
											<tr>
												<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
												<?php foreach ( $quality_table_columns as $column_id => $column_label ) : ?>
													<td><?php echo esc_html( $row[ $column_id ] ); ?></td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
			<p class="wwg-card-lede"><?php esc_html_e( 'Auto applies to all new image uploads. A bulk backfill can be performed below.', 'webp-generator' ); ?></p>
		</div>

		<?php if ( $any_format_supported ) : ?>
			<div class="wwg-settings-fields">
				<!-- A thin bar, not a growing/shrinking line of text --
					see wireSettingsAutosave() in admin.js. Absolutely
					positioned (see .wwg-settings-fields's own
					position:relative) so it never affects this card's
					height, whether idle, saving, or just finished. -->
				<div class="wwg-settings-save-indicator" id="wwg-settings-save-indicator" aria-hidden="true"></div>
				<?php foreach ( $supported_formats as $format ) : ?>
					<div class="wwg-field">
						<label class="wwg-format-toggle" for="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>">
							<input
								type="checkbox"
								name="<?php echo esc_attr( $format['enabled_option'] ); ?>"
								value="1"
								class="wwg-format-checkbox"
								data-controls="wwg-quality-row-<?php echo esc_attr( $format['id'] ); ?>"
								<?php checked( $format['enabled'] ); ?>
							/>
							<?php echo esc_html( sprintf( /* translators: %s: format label, e.g. "WebP". */ __( 'Generate %s', 'webp-generator' ), $format['label'] ) ); ?>
						</label>

						<div class="wwg-quality-row" id="wwg-quality-row-<?php echo esc_attr( $format['id'] ); ?>">
							<div class="wwg-quality-control">
								<?php
								// The slider's own min="50" -- below that, files
								// get noticeably smaller but the compression
								// starts showing on real photos, so this plugin
								// doesn't offer it as an interactive default.
								// The number field next to it stays min="1" on
								// purpose, not 50: a site that already set
								// something lower (from before this control
								// existed) can keep it without a validation
								// error blocking every future save -- see
								// WWG_Admin::handle_save_settings()'s own
								// [1,100] clamp, unchanged. The slider just can't
								// *visually* represent anything under 50; typing
								// a lower number into the field is still exactly
								// as effective as it always was.
								?>
								<input
									type="range"
									id="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>"
									min="50" max="100"
									value="<?php echo esc_attr( max( 50, min( 100, (int) $format['quality'] ) ) ); ?>"
									class="wwg-quality-range"
									data-paired-with="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>-exact"
								/>
								<input
									type="number"
									id="wwg-quality-<?php echo esc_attr( $format['id'] ); ?>-exact"
									name="<?php echo esc_attr( $format['option_name'] ); ?>"
									min="1" max="100"
									value="<?php echo esc_attr( $format['quality'] ); ?>"
									class="small-text wwg-quality-number"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: format label, e.g. "WebP". */ __( 'Exact %s quality value', 'webp-generator' ), $format['label'] ) ); ?>"
								/>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				<!-- No Save button -- see admin.js's wireSettingsAutosave(),
					which saves on every checkbox/quality change. Visually
					hidden -- the indicator bar above is the sighted
					feedback -- but still announced to screen readers,
					which have no equivalent way to notice a silent color
					flash. -->
				<p class="screen-reader-text" id="wwg-settings-save-status" aria-live="polite"></p>
			</div>
		<?php else : ?>
			<p class="wwg-status-detail--muted"><?php esc_html_e( 'No quality setting to show -- this server can\'t currently produce any of the formats this plugin supports.', 'webp-generator' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Library Status -- known current state, always present (even
		before Generate has ever run) and its own top-level card: it's a
		fact about the library itself, not about the backfill tool below
		it, so it doesn't need that tool's actions/progress UI nested
		around it to make sense on its own. Rendered entirely by admin.js
		from wwgAdmin.scanState, a snapshot of the last completed counting
		pass (Generate's own first phase) that survives a reload -- see
		WWG_Admin::OPTION_SCAN_STATE. -->
	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php esc_html_e( 'Library Status', 'webp-generator' ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( "What's currently true about your media library, as of the last completed scan.", 'webp-generator' ); ?></p>
		</div>

		<div id="wwg-status-box">
			<div id="wwg-status-idle">
				<p class="wwg-status-headline" id="wwg-status-headline"></p>
				<p class="wwg-status-meta" id="wwg-status-meta"></p>
			</div>

			<!-- The complete picture, not just what's missing: how many
				images exist in each form, and their total size --
				library-wide, not scoped to any one run. Only shown once
				a count has ever completed (same gate as the headline/
				meta above). hidden lives on the <table> itself, not this
				wrap -- the wrap is just an overflow-x safety net (same
				pattern as .wwg-quality-table-wrap elsewhere on this
				page), always present. -->
			<div class="wwg-library-table-wrap">
				<table class="wwg-library-table" id="wwg-status-table" hidden>
					<thead>
						<tr>
							<!-- Otherwise-empty corner cell (no row-label column
								needs a heading of its own) -- put to use for a
								small "As of <date>" badge instead, the same
								fact this table's own numbers are stamped with
								(scanState.finished_at), rather than leaving
								that much blank space unused. Built by admin.js
								(renderStatusDone()); hidden lives on the badge
								itself, same pattern as #wwg-status-table. -->
							<th scope="col"><span class="wwg-as-of-badge" id="wwg-status-as-of" hidden></span></th>
							<th scope="col"><?php esc_html_e( 'Images', 'webp-generator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total size', 'webp-generator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th scope="row">
							<?php
							printf(
								/* translators: %s: comma-separated list of source image formats this plugin actually converts, e.g. "jpeg, png" -- see WWG_Generator::supported_extensions_label(). */
								esc_html__( 'Original (%s)', 'webp-generator' ),
								esc_html( WWG_Generator::supported_extensions_label() )
							);
							?>
						</th>
							<td id="wwg-status-original-count">–</td>
							<td id="wwg-status-original-size">–</td>
						</tr>
					</tbody>
					<!-- One row per enabled format, built by admin.js
						(renderLibraryTable()) from wwgAdmin.enabledFormats --
						a second <tbody>, not more <tr>s in the one above, so
						the Original row (always present) is never mistaken
						for one admin.js owns. -->
					<tbody id="wwg-status-format-rows"></tbody>
				</table>
			</div>

			<!-- The single Failed Conversions list -- used to also be
				duplicated here and in the backfill tool's own results
				below, showing two different snapshots. Lives here now:
				it's a fact about the library's current state, not about
				any one run, and stays live (sourced from the active job's
				own in-progress failures) while a run is actually happening
				-- see renderFailuresInto()'s call site in admin.js. -->
			<details class="wwg-failures" id="wwg-status-failures" hidden>
				<summary>
					<?php esc_html_e( 'Failed conversions', 'webp-generator' ); ?>
					(<span id="wwg-status-failures-count">0</span>)
				</summary>
				<ul class="wwg-failures-list" id="wwg-status-failures-list"></ul>
			</details>

			<!-- Last Run's own outcome sentence -- "N generated, N still
				missing a format", not its date (that moved into the
				table's own "As of" badge above -- see #wwg-status-as-of --
				since it's a fact about the table, not about this
				specifically). Folded in here from what used to be its own
				separate box; only shown once a Generate run has actually
				finished AND actually had something to report (see
				buildRunSummaryText()/renderResultsDone() in admin.js,
				which populates this instead of showing the backfill
				tool's own results box for that state) -- empty (nothing
				to report) collapses this away entirely rather than
				showing a blank line. -->
			<p class="wwg-status-meta" id="wwg-status-last-run" hidden></p>

			<details class="wwg-recoveries" id="wwg-recoveries" hidden>
				<summary>
					<?php esc_html_e( 'Recovered from embedded data', 'webp-generator' ); ?>
					(<span id="wwg-recoveries-count">0</span>)
				</summary>
				<p class="wwg-recoveries-note"><?php esc_html_e( 'A derived file was created successfully for each entry below, but the original itself still has stray bytes before the real image data starts -- worth a look (or re-exporting from the source) if you still have it.', 'webp-generator' ); ?></p>
				<ul class="wwg-recoveries-list" id="wwg-recoveries-list"></ul>
			</details>
		</div>
	</div>

	<div class="wwg-card">
		<div class="wwg-card-header">
			<h2><?php echo esc_html( $card_heading ); ?></h2>
			<p class="wwg-card-lede"><?php esc_html_e( 'Generate versions for images uploaded before automatic conversion, or before a format was turned on.', 'webp-generator' ); ?></p>
		</div>

		<div class="wwg-panel" data-any-format-enabled="<?php echo $any_format_enabled ? '1' : '0'; ?>">

			<!-- Actions -- available now. -->
			<div class="wwg-actions">
				<button type="button" class="button button-primary" id="wwg-generate" disabled<?php echo $any_format_enabled ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Generate', 'webp-generator' ); ?>
				</button>
				<button type="button" class="button" id="wwg-cancel" disabled>
					<?php esc_html_e( 'Cancel', 'webp-generator' ); ?>
				</button>
			</div>

			<!-- One shared progress bar -- a single physical position used
				by BOTH phases of a running job (counting, then converting),
				so it never visually jumps position when the job switches
				from one to the other; only its own fill/label and what's
				currently driving it change (see renderStatusScanning()/
				renderResultsCommon() in admin.js).

				#wwg-progress-status is the "what's actively happening"
				dot+label -- ONLY for the counting phase, sitting right
				above the bar it's about; Library Status (its own card,
				above) never changes for this -- it's the library's own
				last-known status, not this run's. Once converting starts,
				the results box below becomes visible and its own header
				takes over that same job -- this one hides rather than
				showing alongside it. -->
			<div class="wwg-progress-status" id="wwg-progress-status" hidden>
				<span class="wwg-status-dot" id="wwg-progress-dot"></span>
				<span class="wwg-status-chip-label" id="wwg-progress-chip-label"></span>
			</div>
			<div id="wwg-progress" class="wwg-progress" hidden>
				<div class="wwg-progress-bar">
					<div class="wwg-progress-bar-fill"></div>
				</div>
				<p class="wwg-progress-label"></p>
			</div>
			<!-- "What's happening right now" (which folder is currently
				being walked, or the "Paused" message) -- belongs right
				next to the progress bar it explains, not trailing after
				the results box's cumulative summary/failures/recoveries
				below. -->
			<p class="wwg-log" id="wwg-log" aria-live="polite" hidden></p>

			<!-- Live Generate progress only -- absent until real
				conversion work has started (admin.js unhides it), and
				hidden again once the run settles: there's no separate
				"done" state here at all -- that's folded into the bottom
				of Library Status's card above instead (see
				#wwg-status-last-run and renderResultsDone() in admin.js).
				While actually running/paused, though, this is still where
				that live activity shows. -->
			<div class="wwg-results-box" id="wwg-results-box" hidden>
				<div class="wwg-results-header">
					<span class="wwg-status-dot" id="wwg-results-dot"></span>
					<span class="wwg-status-chip-label" id="wwg-results-chip-label"></span>
				</div>

				<p id="wwg-summary" class="wwg-summary"></p>
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
</div>
