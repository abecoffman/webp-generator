/**
 * Drives the Tools > WebP Generator "Generate WebP Images" panel.
 *
 * The panel is three regions, always in the same place:
 *   1. Library Status  -- what's currently known about the media library
 *      (a persisted server-side snapshot of the last completed counting
 *      pass -- see WWG_Admin::OPTION_SCAN_STATE -- so it survives a
 *      reload instead of resetting to blank).
 *   2. Actions -- the Generate/Cancel buttons.
 *   3. Generate Results -- Generate's own live progress while running,
 *      settling into a dated "last run" record once done (a persisted
 *      WWG_Job state, same as before).
 * Each region's *substate* changes in place (a small status dot + label)
 * rather than jumping to a different part of the page, and each is
 * rendered from server-authoritative state on every page load, not just
 * live updates -- the same rule applies whether a state just became true
 * a second ago or three weeks ago.
 *
 * Generate runs as a WP-Cron background job (see WWG_Job) so it keeps
 * going even if this tab closes, and it's a single job with two phases:
 * a read-only "counting" pass first (the old standalone Scan's own walk,
 * now folded in here -- see renderRunningState()), then "converting" once
 * that finishes. Region 1 renders the counting phase (there's nothing to
 * report in Region 3 yet); Region 3 only appears once real conversion
 * work starts. While the tab *is* open, this script actively drives the
 * job at full speed -- each batch's response immediately triggers the
 * next request -- rather than passively waiting on WP-Cron's own ~60s
 * self-throttle, which applies whether or not anyone's watching.
 * WWG_Job::process_one_batch() shares one lock between this driving loop
 * and the cron tick, so they can never double-process the same batch;
 * whichever gets there first wins, the other backs off and retries
 * shortly. That's also what makes page load able to resume mid-run (in
 * either phase) at full speed: the state this boots from
 * (`wwgAdmin.jobState`) is the server's, not something this script has to
 * have built up itself in the current pageview.
 */
( function () {
	'use strict';

	var els = {};
	var startingJob = false; // true only for the brief round-trip starting/resuming a job -- prevents a rapid double-click firing wwg_start_job twice.
	var jobState = null;
	var scanState = null;
	var generateDefaultLabel = '';

	// The authoritative "should the drive loop keep going" flag -- not
	// just a pending timeout handle, since during the normal fast-path
	// case (server keeps returning 'advanced') there IS no pending
	// timeout at all: each response triggers the next request directly
	// from inside the previous one's .then(). A timeout-only guard
	// (checking `if (driveTimeout)`) would fail to stop an in-flight
	// request from recursing once more if stopDriving() is called while
	// that request is still in the air (e.g. clicking Cancel mid-batch).
	var driving = false;
	var driveTimeout = null;
	var driveRetryDelay = 400; // ms; doubles on 'locked', capped below.
	var DRIVE_RETRY_MAX_MS = 5000;

	document.addEventListener( 'DOMContentLoaded', function () {
		els.panel = document.querySelector( '.wwg-panel' );
		els.generateBtn = document.getElementById( 'wwg-generate' );
		els.cancelBtn = document.getElementById( 'wwg-cancel' );

		// Region 1: Library Status. No dot/chip of its own here -- the
		// card's own <h2> already says "Library Status", and the dot only
		// ever mirrored that same text with an idle/done color, so it was
		// pure duplication once this became its own card.
		els.statusIdle = document.getElementById( 'wwg-status-idle' );
		els.statusHeadline = document.getElementById( 'wwg-status-headline' );
		els.statusMeta = document.getElementById( 'wwg-status-meta' );
		els.statusTable = document.getElementById( 'wwg-status-table' );
		els.statusAsOf = document.getElementById( 'wwg-status-as-of' );
		els.statusOriginalCount = document.getElementById( 'wwg-status-original-count' );
		els.statusOriginalSize = document.getElementById( 'wwg-status-original-size' );
		els.statusFormatRows = document.getElementById( 'wwg-status-format-rows' );
		// The single Failed Conversions list -- used to be duplicated in
		// Region 3 too (#wwg-failures/etc., now removed); lives here only.
		els.statusFailures = document.getElementById( 'wwg-status-failures' );
		els.statusFailuresCount = document.getElementById( 'wwg-status-failures-count' );
		els.statusFailuresList = document.getElementById( 'wwg-status-failures-list' );
		// Last Run -- folded in from what used to be its own separate box
		// (see renderResultsDone()). Recoveries too -- used to be Region
		// 3's own, relocated here since it's tied to the same "what
		// happened last run" story, now living in the same place.
		els.statusLastRun = document.getElementById( 'wwg-status-last-run' );
		els.recoveries = document.getElementById( 'wwg-recoveries' );
		els.recoveriesCount = document.getElementById( 'wwg-recoveries-count' );
		els.recoveriesList = document.getElementById( 'wwg-recoveries-list' );

		// Shared progress bar + "what's happening now" line -- one
		// physical position used by both a running job's counting and
		// converting phases (see renderRunningState()'s own docblock),
		// not owned by either Region 1 or Region 3.
		els.progressStatus = document.getElementById( 'wwg-progress-status' );
		els.progressDot = document.getElementById( 'wwg-progress-dot' );
		els.progressChipLabel = document.getElementById( 'wwg-progress-chip-label' );
		els.progress = document.getElementById( 'wwg-progress' );
		els.progressFill = els.progress.querySelector( '.wwg-progress-bar-fill' );
		els.progressLabel = els.progress.querySelector( '.wwg-progress-label' );
		els.log = document.getElementById( 'wwg-log' );

		// Region 3: live Generate progress only now (see its own markup
		// comment) -- just the header and a compact summary sentence.
		els.resultsBox = document.getElementById( 'wwg-results-box' );
		els.resultsDot = document.getElementById( 'wwg-results-dot' );
		els.resultsChipLabel = document.getElementById( 'wwg-results-chip-label' );
		els.summary = document.getElementById( 'wwg-summary' );

		jobState = wwgAdmin.jobState;
		scanState = wwgAdmin.scanState;
		generateDefaultLabel = els.generateBtn.textContent;

		els.generateBtn.addEventListener( 'click', onGenerateClick );
		els.cancelBtn.addEventListener( 'click', requestCancel );

		wireQualityControls();
		wireFormatCheckboxes();
		wireSettingsAutosave();

		boot();
	} );

	// Settings card: each quality field is a range + a linked number input
	// (see includes/views/admin-page.php) -- the range is the fast,
	// approximate way to pick a value, the number is the precise one, and
	// this keeps them mirroring each other. The number input is the one
	// actually submitted (name="wwg_quality"/"wwg_quality_avif"); the
	// range carries no name at all, purely a visual/interactive companion.
	// Not scoped to any one region's markup (unlike everything else in
	// this file) -- the Settings card is its own top-level .wwg-card, not
	// part of the Scan/Generate panel above it.
	function wireQualityControls() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wwg-quality-range' ), function ( range ) {
			var number = document.getElementById( range.dataset.pairedWith );
			if ( ! number ) {
				return;
			}

			range.addEventListener( 'input', function () {
				number.value = range.value;
			} );

			// The number field alone keeps its full historical range (see
			// its own min="1" in the markup) -- typing a value under 50
			// is still honored exactly as before, the slider just can't
			// visually sit anywhere below its own min, so it pins there
			// instead of trying to represent a position that isn't on it.
			number.addEventListener( 'input', function () {
				var clamped = Math.min( 100, Math.max( 50, parseInt( number.value, 10 ) || 0 ) );
				range.value = clamped;
			} );
		} );
	}

	// Settings card: each per-format checkbox (see includes/views/
	// admin-page.php's data-controls attribute) dims its own quality row
	// while unchecked -- a visual-only cue (see .wwg-quality-row--disabled
	// in admin.css), not a real disabled attribute: the slider/number
	// underneath stay fully interactive and still submit normally either
	// way, so a quality can be set ahead of turning a format back on.
	function wireFormatCheckboxes() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wwg-format-checkbox' ), function ( checkbox ) {
			var row = document.getElementById( checkbox.dataset.controls );
			if ( ! row ) {
				return;
			}

			var sync = function () {
				row.classList.toggle( 'wwg-quality-row--disabled', ! checkbox.checked );
			};
			sync();
			checkbox.addEventListener( 'change', sync );
		} );
	}

	// Every supported format's current checkbox/quality state, in the
	// same shape a real form submission of this card would have sent --
	// including an unchecked box's absence, standard HTML semantics. Sent
	// in full on every autosave (see wireSettingsAutosave() below), not
	// just whatever one field just changed, so the server-side handler
	// (WWG_Admin::handle_save_settings()) never has to guess whether a
	// missing key means "unchanged" or "just turned off".
	function currentSettingsFields() {
		var fields = {};
		Array.prototype.forEach.call( document.querySelectorAll( '.wwg-format-checkbox' ), function ( checkbox ) {
			if ( checkbox.checked ) {
				fields[ checkbox.name ] = '1';
			}
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.wwg-quality-number' ), function ( number ) {
			fields[ number.name ] = number.value;
		} );
		return fields;
	}

	// Settings card: no Save button -- every checkbox/quality change
	// saves itself immediately. Listens on 'change' (not 'input'), which
	// only fires once a value is actually committed -- releasing the
	// slider thumb, or blurring/pressing Enter in the number field --
	// not on every intermediate value while dragging or typing a digit at
	// a time, so a single edit means a single request. The range and
	// number inputs are two separate elements kept in sync by
	// wireQualityControls() above via plain .value assignment, which
	// doesn't itself dispatch a 'change' event, so both still need their
	// own listener here to catch either interaction path (drag-and-
	// release vs. type-and-blur).
	function wireSettingsAutosave() {
		var triggers = document.querySelectorAll( '.wwg-format-checkbox, .wwg-quality-range, .wwg-quality-number' );
		if ( ! triggers.length ) {
			return;
		}
		var indicator = document.getElementById( 'wwg-settings-save-indicator' );
		var statusEl = document.getElementById( 'wwg-settings-save-status' ); // screen-reader-text -- see admin-page.php; the indicator bar is the sighted equivalent.
		var fadeTimeout = null;

		// A brief color flash on the bar, not a persistent state -- resets
		// back to idle (zero width, transparent) shortly after, whether
		// the save succeeded or failed, so it's never just sitting there
		// implying "still saving" or "still an error" long after the fact.
		function settle( className, delay ) {
			if ( ! indicator ) {
				return;
			}
			indicator.className = 'wwg-settings-save-indicator is-active ' + className;
			fadeTimeout = setTimeout( function () {
				indicator.className = 'wwg-settings-save-indicator';
			}, delay );
		}

		Array.prototype.forEach.call( triggers, function ( trigger ) {
			trigger.addEventListener( 'change', function () {
				if ( fadeTimeout ) {
					clearTimeout( fadeTimeout );
				}
				if ( indicator ) {
					indicator.className = 'wwg-settings-save-indicator is-active';
				}
				if ( statusEl ) {
					statusEl.textContent = wwgAdmin.strings.savingSettings;
				}

				requestJobAction( wwgAdmin.saveSettingsAction, currentSettingsFields() ).then( function ( json ) {
					if ( statusEl ) {
						statusEl.textContent = json.success ? wwgAdmin.strings.settingsSaved : wwgAdmin.strings.settingsSaveFailed;
					}
					// Success fades quickly (it's just a confirmation);
					// an error sits a beat longer, since that's actually
					// worth noticing.
					settle( json.success ? 'is-success' : 'is-error', json.success ? 500 : 1400 );

					// Toggling a format can mark Library Status stale
					// server-side (see handle_save_settings()'s own
					// docblock) -- without this there'd be no full-page
					// reload left to pick that up now that saving doesn't
					// redirect, so Region 1 would keep showing a count
					// that's already known to be wrong.
					if ( json.success && json.data && json.data.scanState ) {
						scanState = json.data.scanState;
						renderStatusFromState();
					}
				} ).catch( function () {
					if ( statusEl ) {
						statusEl.textContent = wwgAdmin.strings.settingsSaveFailed;
					}
					settle( 'is-error', 1400 );
				} );
			} );
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
	}

	// Locale-aware thousands separator (e.g. "47,382") for Library
	// Status's own image-count tile -- the same "let the visitor's own
	// locale decide the formatting" approach formatDateTime() below
	// already takes, rather than a hardcoded separator.
	function formatCount( count ) {
		return count.toLocaleString();
	}

	// An absolute date/time (not "3 days ago") -- deliberately: the whole
	// point of using this is to make unambiguous whether a completed run
	// being displayed happened moments ago or weeks ago, and an absolute
	// timestamp answers that at a glance without needing a live-updating
	// "N minutes ago" label or a pile of new relative-time translated
	// strings just for this one spot.
	function formatDateTime( unixSeconds ) {
		return new Date( unixSeconds * 1000 ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	}

	function requestJobAction( action, extraFields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', wwgAdmin.nonce );
		if ( extraFields ) {
			Object.keys( extraFields ).forEach( function ( key ) {
				body.append( key, extraFields[ key ] );
			} );
		}

		return fetch( wwgAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function requestClassifyFailures( files ) {
		return requestJobAction( wwgAdmin.classifyAction, { files: JSON.stringify( files ) } );
	}

	function requestFixFailure( fileRel ) {
		return requestJobAction( wwgAdmin.fixAction, { file: fileRel } );
	}

	function requestDeleteFailure( fileRel ) {
		return requestJobAction( wwgAdmin.deleteAction, { file: fileRel } );
	}

	// ---- Shared rendering helpers (both Region 1 and Region 3 use these) ----

	function setStatusDot( el, state ) {
		el.className = 'wwg-status-dot wwg-status-dot--' + state;
	}

	function setChipLabel( el, state, text ) {
		el.textContent = text;
		el.className = 'wwg-status-chip-label' + ( state && 'idle' !== state ? ' wwg-status-chip-label--' + state : '' );
	}

	// Groups a flat {file, format, error} list into one entry per file --
	// Fix/Delete both act on every currently-failing enabled format of a
	// file together (see WWG_Admin::classify_failure()'s own docblock),
	// so the UI shows one row per file (with a format badge per entry),
	// not one row per (file, format) pair, which would show duplicate
	// buttons that do the exact same thing. Preserves the input's
	// original first-seen order.
	function groupFailuresByFile( failures ) {
		var order = [];
		var byFile = {};
		failures.forEach( function ( failure ) {
			if ( ! byFile[ failure.file ] ) {
				byFile[ failure.file ] = { file: failure.file, entries: [] };
				order.push( failure.file );
			}
			// A failure entry persisted before AVIF existed has no
			// `format` field at all -- only WebP could have been meant
			// then (mirrors WWG_Admin's own formats_of()/
			// remove_failure_from_scan_state() back-compat reasoning).
			byFile[ failure.file ].entries.push(
				failure.format ? failure : Object.assign( {}, failure, { format: 'webp' } )
			);
		} );
		return order.map( function ( file ) {
			return byFile[ file ];
		} );
	}

	function formatLabel( format ) {
		return ( wwgAdmin.formatLabels && wwgAdmin.formatLabels[ format ] ) || format;
	}

	function buildFormatBadge( format ) {
		var span = document.createElement( 'span' );
		span.className = 'wwg-chip wwg-chip--' + format;
		span.textContent = formatLabel( format );
		return span;
	}

	// Badges only earn their keep once there's more than one format this
	// site actually produces -- on a single-format server every row would
	// show the same one badge, uninformative clutter rather than a signal.
	function shouldShowFormatBadges() {
		return !! ( wwgAdmin.enabledFormats && wwgAdmin.enabledFormats.length > 1 );
	}

	// One combined line if every entry in the group shares the same
	// underlying error (the common case -- one broken source derivative
	// breaks every format converted from it identically); one line per
	// distinct error, labelled by format, otherwise -- so a genuine
	// per-format divergence isn't silently hidden behind whichever entry
	// happened to render.
	function summarizeFailureText( group ) {
		var uniqueErrors = [];
		group.entries.forEach( function ( entry ) {
			if ( -1 === uniqueErrors.indexOf( entry.error ) ) {
				uniqueErrors.push( entry.error );
			}
		} );
		if ( 1 === uniqueErrors.length ) {
			return uniqueErrors[ 0 ];
		}
		return group.entries.map( function ( entry ) {
			return formatLabel( entry.format ) + ': ' + entry.error;
		} ).join( ' — ' );
	}

	// Keeps a <ul> that gets re-rendered on every batch tick (as often as
	// every second or two while a Scan/Generate run is actively driving)
	// STABLE across those re-renders, instead of the wipe-and-rebuild
	// every caller here used to do. That wasn't just wasted work: a row
	// whose content depends on an async follow-up request (resolving a
	// file to its Media Library edit link, or to a Fix/Delete row's
	// available actions) would have that request racing the *next*
	// tick's wipe -- the resolved content only ever existed in the brief
	// window between the request landing and the following rebuild
	// tearing it back down, which is exactly what a flashing "View in
	// Media Library" link was. Worse than cosmetic for the failures list
	// specifically: a row mid Fix/Delete click could get wiped out from
	// under its own in-flight request, so the eventual response would
	// silently update a now-detached element nobody can see.
	//
	// Reconciles instead: rows for keys still present in `items` are left
	// completely untouched (whatever async content they already resolved
	// stays put); rows for keys no longer present (only possible once a
	// list's underlying data grows past its display cap) are removed;
	// only genuinely new keys get a freshly built row appended. Returns
	// {item, li} pairs for just the newly-added rows -- a direct element
	// reference, not a key to re-look-up via a CSS selector afterwards
	// (a file path can contain characters that would need real escaping
	// to use safely in one) -- since that's the only slice callers with
	// an async follow-up (classify/edit_url lookups) need to act on;
	// already-resolved rows have nothing left to fetch.
	//
	// Safe to leave a resolved row's content un-refreshed indefinitely:
	// every action this page offers (Fix/Delete) always re-validates
	// server-side at click time regardless of what a row is currently
	// showing (see fix_failure()'s own docblock), so a mildly stale
	// button is never actually wrong to click, only possibly a beat
	// behind whatever the very latest server state is.
	function reconcileListRows( listEl, items, keyFn, buildFn ) {
		var seen = {};
		items.forEach( function ( item ) {
			seen[ keyFn( item ) ] = true;
		} );

		var existingKeys = {};
		Array.prototype.slice.call( listEl.children ).forEach( function ( li ) {
			if ( seen[ li.dataset.key ] ) {
				existingKeys[ li.dataset.key ] = true;
			} else {
				li.remove(); // Fell out of the capped display window.
			}
		} );

		var added = [];
		items.forEach( function ( item ) {
			var key = keyFn( item );
			if ( existingKeys[ key ] ) {
				return;
			}
			var li = buildFn( item );
			li.dataset.key = key;
			listEl.appendChild( li );
			added.push( { item: item, li: li } );
		} );
		return added;
	}

	// Shared by Region 1's own "Failed conversions" (known failures Scan
	// re-surfaced) and Region 3's (this Generate run's failures) -- a
	// failure reads the same regardless of which box is reporting it,
	// right down to the next-best-action offered on it. $failures is a
	// flat {file, format, error} list -- grouped by file for display, see
	// groupFailuresByFile(). A given file's own group is complete the
	// first time it's seen -- process_batch() resolves every format it
	// needs for a file in the single pass that visits it, never spread
	// across later batches within the same run -- so reconcileListRows()
	// leaving an already-rendered group's row untouched never leaves it
	// showing a stale, incomplete set of formats.
	function renderFailuresInto( listEl, countEl, detailsEl, failures ) {
		if ( ! failures || ! failures.length ) {
			detailsEl.hidden = true;
			return;
		}
		detailsEl.hidden = false;
		// The raw (file, format) unit count -- matches the "%d failed"
		// summary line elsewhere on the page, which counts the same way.
		countEl.textContent = failures.length;

		// Keep the DOM light on a run with hundreds of failures -- the
		// count above already reflects the true total, this list is for
		// spot-checking specific files, not an exhaustive report.
		var groups = groupFailuresByFile( failures ).slice( -50 );
		var added = reconcileListRows(
			listEl,
			groups,
			function ( group ) { return group.file; },
			buildFailureRow
		);

		if ( ! added.length ) {
			return;
		}

		// Rows start with no action buttons at all -- what's actually
		// possible for a given file (fix, delete, or delete-the-whole-
		// attachment) is a fact about WordPress's current state, not
		// something to guess from the filename client-side, so it's
		// asked for fresh the moment a row is added rather than assumed.
		// Only for the newly-added rows above -- an already-resolved row
		// has nothing left to ask for (see reconcileListRows()'s own
		// docblock for why re-asking forever isn't needed either).
		requestClassifyFailures( added.map( function ( pair ) { return pair.item.file; } ) )
			.then( function ( json ) {
				if ( ! json.success ) {
					return;
				}
				added.forEach( function ( pair ) {
					var info = json.data[ pair.item.file ];
					if ( info ) {
						renderRowActions( pair.li, pair.item.file, info );
					}
				} );
			} );
	}

	function buildFailureRow( group ) {
		var li = document.createElement( 'li' );
		li.dataset.file = group.file;

		var fileLabel = document.createElement( 'span' );
		fileLabel.className = 'wwg-failure-file';
		fileLabel.textContent = group.file;
		li.appendChild( fileLabel );

		if ( shouldShowFormatBadges() ) {
			var badges = document.createElement( 'div' );
			badges.className = 'wwg-failure-badges';
			group.entries.forEach( function ( entry ) {
				badges.appendChild( buildFormatBadge( entry.format ) );
			} );
			li.appendChild( badges );
		}

		var text = document.createElement( 'span' );
		text.className = 'wwg-failure-text';
		text.textContent = summarizeFailureText( group );
		li.appendChild( text );

		var actions = document.createElement( 'div' );
		actions.className = 'wwg-failure-actions';
		li.appendChild( actions );

		var status = document.createElement( 'span' );
		status.className = 'wwg-failure-status';
		status.hidden = true;
		li.appendChild( status );

		return li;
	}

	// A file can appear more than once here if more than one of its
	// enabled formats needed recovery (see WWG_Admin::process_batch()'s
	// recoveries push, one entry per (file, format)) -- keyed by both
	// together, and badged the same way a multi-format failure row is,
	// so two rows for the same file read as "WebP, then AVIF", not as an
	// unexplained duplicate.
	function buildRecoveryRow( recovery ) {
		var li = document.createElement( 'li' );

		var fileLabel = document.createElement( 'span' );
		fileLabel.className = 'wwg-recovery-file';
		fileLabel.textContent = recovery.file;
		li.appendChild( fileLabel );

		if ( shouldShowFormatBadges() ) {
			li.appendChild( buildFormatBadge( recovery.format ) );
		}

		return li;
	}

	// A bare relative path isn't actionable on its own -- each row gets a
	// link straight to its actual Media Library edit screen, resolved via
	// the same endpoint renderFailuresInto() uses (it always returns
	// edit_url once an attachment resolves, regardless of which
	// fix/delete action it also worked out -- none of that is relevant
	// here, only the URL is). See reconcileListRows() for why this only
	// ever asks for newly-added rows, not the whole list every render.
	function renderRecoveriesInto( recoveries ) {
		if ( ! recoveries || ! recoveries.length ) {
			els.recoveries.hidden = true;
			return;
		}
		els.recoveries.hidden = false;
		els.recoveriesCount.textContent = recoveries.length;

		// Keep the DOM light on a run with hundreds of recoveries -- same
		// reasoning as renderFailuresInto()'s own cap.
		var toShow = recoveries.slice( -50 );
		var added = reconcileListRows(
			els.recoveriesList,
			toShow,
			function ( recovery ) { return recovery.file + '::' + recovery.format; },
			buildRecoveryRow
		);

		if ( ! added.length ) {
			return;
		}

		requestClassifyFailures( added.map( function ( pair ) { return pair.item.file; } ) )
			.then( function ( json ) {
				if ( ! json.success ) {
					return;
				}
				added.forEach( function ( pair ) {
					var info = json.data[ pair.item.file ];
					if ( info && info.edit_url ) {
						pair.li.appendChild( buildViewInMediaLibraryLink( info.edit_url ) );
					}
				} );
			} );
	}

	function buildRowButton( label, extraClass ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-small wwg-row-btn' + ( extraClass ? ' ' + extraClass : '' );
		btn.textContent = label;
		return btn;
	}

	function buildViewInMediaLibraryLink( url ) {
		var a = document.createElement( 'a' );
		a.className = 'wwg-row-link';
		a.href = url;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = wwgAdmin.strings.viewInMediaLibrary;
		return a;
	}

	function insertReason( li, text ) {
		var reason = document.createElement( 'span' );
		reason.className = 'wwg-failure-reason';
		reason.textContent = text;
		li.insertBefore( reason, li.querySelector( '.wwg-failure-actions' ) );
	}

	// Builds the actual next-best-action for one row, based on what
	// classify_failure() determined server-side -- see its docblock in
	// class-wwg-admin.php for the three possible outcomes this switches on.
	function renderRowActions( li, fileRel, info ) {
		var actions = li.querySelector( '.wwg-failure-actions' );
		actions.innerHTML = '';
		var existingReason = li.querySelector( '.wwg-failure-reason' );
		if ( existingReason ) {
			existingReason.remove();
		}

		if ( 'fix_or_delete' === info.action ) {
			var fixBtn = buildRowButton( wwgAdmin.strings.fixThisFile, 'button-primary' );
			fixBtn.addEventListener( 'click', function () {
				runRowAction( li, fileRel, requestFixFailure, wwgAdmin.strings.fixing );
			} );
			actions.appendChild( fixBtn );

			var deleteLink = document.createElement( 'button' );
			deleteLink.type = 'button';
			deleteLink.className = 'wwg-row-link';
			deleteLink.textContent = wwgAdmin.strings.deleteInstead;
			deleteLink.addEventListener( 'click', function () {
				if ( window.confirm( wwgAdmin.strings.confirmDeleteDerivative ) ) {
					runRowAction( li, fileRel, requestDeleteFailure, wwgAdmin.strings.deleting );
				}
			} );
			actions.appendChild( deleteLink );
		} else if ( 'delete_only' === info.action ) {
			if ( info.reason ) {
				insertReason( li, info.reason );
			}

			var deleteBtn = buildRowButton( wwgAdmin.strings.deleteThisFile );
			deleteBtn.addEventListener( 'click', function () {
				if ( window.confirm( wwgAdmin.strings.confirmDeleteOnly ) ) {
					runRowAction( li, fileRel, requestDeleteFailure, wwgAdmin.strings.deleting );
				}
			} );
			actions.appendChild( deleteBtn );

			if ( info.edit_url ) {
				actions.appendChild( buildViewInMediaLibraryLink( info.edit_url ) );
			}
		} else if ( 'delete_original' === info.action ) {
			insertReason( li, wwgAdmin.strings.originalNote );

			var deleteOriginalBtn = buildRowButton( wwgAdmin.strings.deleteThisFile, 'wwg-row-btn--danger' );
			deleteOriginalBtn.addEventListener( 'click', function () {
				if ( window.confirm( wwgAdmin.strings.confirmDeleteOriginal ) ) {
					runRowAction( li, fileRel, requestDeleteFailure, wwgAdmin.strings.deleting );
				}
			} );
			actions.appendChild( deleteOriginalBtn );

			if ( info.edit_url ) {
				actions.appendChild( buildViewInMediaLibraryLink( info.edit_url ) );
			}
		}
	}

	function setRowBusy( li, label ) {
		var actions = li.querySelector( '.wwg-failure-actions' );
		Array.prototype.forEach.call( actions.querySelectorAll( 'button' ), function ( btn ) {
			btn.disabled = true;
		} );
		var statusEl = li.querySelector( '.wwg-failure-status' );
		statusEl.hidden = false;
		statusEl.className = 'wwg-failure-status';
		statusEl.textContent = label;
	}

	function setRowMessage( li, message, isError ) {
		var actions = li.querySelector( '.wwg-failure-actions' );
		Array.prototype.forEach.call( actions.querySelectorAll( 'button' ), function ( btn ) {
			btn.disabled = false;
		} );
		var statusEl = li.querySelector( '.wwg-failure-status' );
		statusEl.hidden = false;
		statusEl.className = 'wwg-failure-status' + ( isError ? ' wwg-failure-status--error' : '' );
		statusEl.textContent = message;
	}

	// Shared by both the "Fix this file" and every "Delete..." button --
	// only what request function to call and which in-flight label to
	// show actually differs between them; which of fixed/deleted actually
	// happened is read back from the response itself, not assumed from
	// which button was clicked.
	function runRowAction( li, fileRel, requestFn, inFlightLabel ) {
		setRowBusy( li, inFlightLabel );

		requestFn( fileRel ).then( function ( json ) {
			if ( ! json.success ) {
				setRowMessage( li, wwgAdmin.strings.actionFailedMessage, true );
				return;
			}

			var result = json.data;
			if ( 'fixed' === result.outcome || 'deleted' === result.outcome ) {
				var fixed = 'fixed' === result.outcome;
				removeFailureFromScanState( fileRel, fixed );
				removeFailureFromJobState( fileRel, fixed );
				refreshAfterFailureResolved();
				return;
			}

			// 'not_regenerable' / 'stale' / 'failed' -- surface why, and
			// re-classify this one row so its buttons reflect reality
			// (e.g. a since-broken original now offers delete instead of
			// fix) without needing a full page reload.
			setRowMessage( li, result.message || wwgAdmin.strings.actionFailedMessage, true );
			requestClassifyFailures( [ fileRel ] ).then( function ( classifyJson ) {
				if ( classifyJson.success && classifyJson.data[ fileRel ] ) {
					renderRowActions( li, fileRel, classifyJson.data[ fileRel ] );
				}
			} );
		} ).catch( function () {
			setRowMessage( li, wwgAdmin.strings.actionFailedMessage, true );
		} );
	}

	// Mirrors WWG_Admin::remove_failure_from_scan_state()'s $format=null
	// case exactly -- every remaining failure entry for this file is
	// cleared at once, matching Fix/Delete both resolving every
	// currently-failing enabled format of a file together server-side,
	// not just one. Kept in sync client-side so both regions reflect a
	// fix/delete right away, without waiting on a fresh Scan/Generate run
	// to notice.
	function removeFailureFromScanState( fileRel, fixed ) {
		if ( ! scanState || ! scanState.failures || ! scanState.failures.length ) {
			return;
		}
		var removedUnits = 0;
		scanState.failures = scanState.failures.filter( function ( failure ) {
			if ( failure.file !== fileRel ) {
				return true;
			}
			removedUnits += 1;
			return false;
		} );
		if ( ! removedUnits ) {
			return; // Wasn't listed here -- nothing to adjust.
		}
		if ( ! fixed ) {
			scanState.missing = Math.max( 0, scanState.missing - removedUnits );
			scanState.missing_files = Math.max( 0, scanState.missing_files - 1 );
		}
	}

	// Mirrors WWG_Job::remove_failure_from_state()'s $format=null case the
	// same way.
	function removeFailureFromJobState( fileRel, fixed ) {
		if ( ! jobState || ! jobState.stats || ! jobState.stats.failures || ! jobState.stats.failures.length ) {
			return;
		}
		var removedUnits = 0;
		jobState.stats.failures = jobState.stats.failures.filter( function ( failure ) {
			if ( failure.file !== fileRel ) {
				return true;
			}
			removedUnits += 1;
			return false;
		} );
		if ( ! removedUnits ) {
			return; // Wasn't part of this run's own record -- nothing to adjust.
		}
		jobState.stats.failed = Math.max( 0, jobState.stats.failed - removedUnits );
		if ( fixed ) {
			jobState.stats.converted += removedUnits;
		} else {
			jobState.stats.missing = Math.max( 0, jobState.stats.missing - removedUnits );
			jobState.total_missing = Math.max( 0, jobState.total_missing - removedUnits );
		}
	}

	// Re-renders whichever regions actually have something to show,
	// after a fix/delete has already patched scanState/jobState in
	// place -- reuses the existing render functions rather than
	// hand-patching the DOM further, so headline sentences, counts, and
	// the failures list itself all stay correct in one pass.
	function refreshAfterFailureResolved() {
		if ( scanState.finished_at && ! scanState.invalidated_at ) {
			renderStatusDone();
		}
		if ( 'running' === jobState.status ) {
			renderResultsRunning();
		} else if ( 'paused' === jobState.status ) {
			renderResultsPaused();
		} else if ( 'done' === jobState.status ) {
			renderResultsDone();
		}
	}

	// The one place Generate/Cancel's enabled/disabled state and
	// Generate's label are decided -- called at the end of every render
	// function in both regions so the buttons can never drift out of
	// sync with what's actually on screen (past bugs in this file were
	// exactly that: button state set ad hoc in several different places
	// that could disagree with each other).
	function updateActionButtons() {
		var jobRunning = 'running' === jobState.status;
		var jobPaused = 'paused' === jobState.status;
		var anyFormatEnabled = '1' === els.panel.getAttribute( 'data-any-format-enabled' );

		// Never usable at all on a server that can't produce WebP or
		// AVIF -- see the readme's own FAQ: "Generate is disabled until
		// that's resolved, rather than letting you run a tool that can't
		// do anything."
		els.cancelBtn.disabled = ! jobRunning;

		if ( jobPaused ) {
			els.generateBtn.textContent = wwgAdmin.strings.resumeGenerating;
			els.generateBtn.disabled = startingJob || ! anyFormatEnabled;
			return;
		}

		// No longer gated on scanState at all -- Generate no longer
		// depends on a prior completed scan existing; it counts its own
		// scope as its own first phase (see renderRunningState()).
		els.generateBtn.textContent = generateDefaultLabel;
		els.generateBtn.disabled = jobRunning || startingJob || ! anyFormatEnabled;
	}

	// ---- Region 1: Library Status ----

	function renderStatusIdle() {
		els.statusIdle.hidden = false;
		els.statusTable.hidden = true; // nothing counted yet -- see renderLibraryTable().
		els.statusAsOf.hidden = true;
		els.statusFailures.hidden = true;
		// The shared progress bar/log/status (see their own markup
		// comment in admin-page.php) -- nothing running, so nothing to
		// show there.
		els.progressStatus.hidden = true;
		els.progress.hidden = true;
		els.log.hidden = true;

		els.statusHeadline.textContent = wwgAdmin.strings.notCheckedYet;
		els.statusMeta.textContent = scanState.invalidated_at
			? wwgAdmin.strings.notCheckedYetInvalidated
			: wwgAdmin.strings.notCheckedYetFirstTime;

		updateActionButtons();
	}

	// Drives the shared progress bar during the counting phase only --
	// including its own little "what's actively happening" dot+label
	// sitting right above the bar (els.progressStatus), which is what
	// says "Scanning…" / "Paused" while counting is under way. Library
	// Status itself is deliberately left alone here -- it's the
	// library's own persisted status, not this run's, and shouldn't
	// visually change just because a run happens to be checking it
	// right now.
	function renderStatusScanning( pct, label, currentDir, dotState, chipText ) {
		els.statusFailures.hidden = true; // a prior scan's list shouldn't linger while a fresh one runs.

		els.progressStatus.hidden = false;
		setStatusDot( els.progressDot, dotState );
		setChipLabel( els.progressChipLabel, dotState, chipText );

		// Drives the shared progress bar -- the same physical element
		// renderResultsCommon() drives once the job's own converting
		// phase takes over (see renderRunningState()), so it never jumps
		// position when that happens. The current folder is appended
		// directly onto this same line (see 'checkingFolder's own
		// docblock in PHP for why), not shown as a separate line --
		// mirrors exactly how renderResultsRunning() already does this
		// for the converting phase.
		els.progress.hidden = false;
		els.progressFill.style.width = pct + '%';
		els.progressLabel.textContent = label;
		if ( currentDir ) {
			els.progressLabel.textContent += ' - ' + wwgAdmin.strings.checkingFolder.replace( '%s', currentDir );
		}

		// Left free for its other job -- the 'paused' message
		// (renderResultsPaused()'s counting-phase branch) -- same
		// convention the converting phase's own els.log already follows.
		els.log.hidden = true;

		updateActionButtons();
	}

	// The complete picture: one row for the originals, one more per
	// enabled format, each with its own image count and total size --
	// library-wide, not scoped to any one run. Static once rendered
	// (unlike the Failed Conversions list, which
	// renderStatusFailuresLiveDuringJob() keeps live while a run
	// resolves things) -- these refresh on the next completed counting
	// pass, same lifecycle scanState's other fields already have.
	function renderLibraryTable() {
		els.statusTable.hidden = false;
		els.statusOriginalCount.textContent = formatCount( scanState.total_images );
		els.statusOriginalSize.textContent = formatBytes( scanState.original_bytes_total );

		els.statusFormatRows.innerHTML = '';
		( wwgAdmin.enabledFormats || [] ).forEach( function ( format ) {
			var row = document.createElement( 'tr' );

			// Plain text, matching the Original row's own row header
			// exactly -- not buildFormatBadge()'s colored/uppercase chip
			// styling, which reads as a differently-sized, different-font
			// label sitting next to "Original" instead of a plain row
			// name like it. That chip earns its keep in the Failed
			// Conversions/Recoveries lists (scanning many mixed-format
			// rows at a glance), not here, where each format already has
			// exactly one row of its own.
			var th = document.createElement( 'th' );
			th.scope = 'row';
			th.textContent = formatLabel( format );
			row.appendChild( th );

			var countCell = document.createElement( 'td' );
			countCell.textContent = formatCount( scanState[ format + '_present' ] );
			row.appendChild( countCell );

			var sizeCell = document.createElement( 'td' );
			sizeCell.textContent = formatBytes( scanState[ format + '_bytes' ] );
			row.appendChild( sizeCell );

			els.statusFormatRows.appendChild( row );
		} );
	}

	function renderStatusDone() {
		// No headline/meta sentence once actually counted -- the table
		// below says the same thing more directly (an enabled format's
		// row falling short of the Original row's own count *is* "N
		// missing"), so this whole block stays hidden here; only
		// renderStatusIdle() (nothing counted yet) still uses it.
		els.statusIdle.hidden = true;
		// Safe to unconditionally hide the shared progress bar/status/log
		// here -- both call sites (boot(), driveOnce()'s scan_state check)
		// always follow this with their own job-status render on top when
		// a job is actually active, which un-hides it again correctly;
		// this is never the final word when something's really running.
		els.progressStatus.hidden = true;
		els.progress.hidden = true;
		els.log.hidden = true;

		renderLibraryTable();
		// The table's own corner header cell -- otherwise empty space --
		// carries when these numbers were captured, since that's a fact
		// about the table (scanState.finished_at), not about any one
		// Generate run's outcome (see renderResultsDone()'s own summary
		// line for that instead).
		els.statusAsOf.textContent = wwgAdmin.strings.asOfLabel.replace( '%s', formatDateTime( scanState.finished_at ) );
		els.statusAsOf.hidden = false;
		renderFailuresInto( els.statusFailuresList, els.statusFailuresCount, els.statusFailures, scanState.failures );

		updateActionButtons();
	}

	// Renders whatever scanState currently says -- used both right after
	// a scan finishes and to hydrate the box on a plain page load, since
	// (per the whole point of persisting this) those must render
	// identically.
	function renderStatusFromState() {
		if ( ! scanState.finished_at || scanState.invalidated_at ) {
			renderStatusIdle();
		} else {
			renderStatusDone();
		}
	}

	// Layered on top of renderStatusDone()'s render, every time a
	// Generate batch advances: keeps the Failed Conversions list live
	// from this same run's own in-progress record (jobState.stats.failures)
	// rather than the last completed count's (scanState.failures) --
	// a failure you might Fix/Delete right now should never be shown
	// stale. Deliberately still leaves the table (image counts/byte
	// totals) alone -- those describe the last real count, which is
	// still true until the next one completes; only the (actionable)
	// failures list needed to stay live.
	function renderStatusFailuresLiveDuringJob() {
		if ( ! scanState.finished_at || scanState.invalidated_at ) {
			return; // Nothing to live-adjust -- Region 1 is already showing "not checked yet".
		}

		renderFailuresInto( els.statusFailuresList, els.statusFailuresCount, els.statusFailures, jobState.stats.failures );
	}

	// Renders whichever phase of a running job is currently true --
	// called everywhere a running job needs (re)rendering: driveOnce()'s
	// 'advanced' outcome and boot()'s 'running' hydration branch. During
	// counting (the old standalone Scan's own walk, folded into Generate's
	// own first phase), the shared progress bar shows scanning progress
	// -- see renderStatusScanning(), reused verbatim here from data now
	// sourced off jobState instead of a locally-accumulated totals object
	// -- while Region 3 stays absent, since there's nothing to report yet.
	// Once counting finishes (see WWG_Job::process_one_batch()'s phase
	// flip), this same function starts driving Region 3 instead, exactly
	// like Generate always has -- reusing that SAME shared progress bar
	// (see renderResultsCommon()) rather than a separate one of Region
	// 3's own, so it never visually jumps position when this happens.
	function renderRunningState() {
		if ( 'counting' === jobState.phase ) {
			// total_dirs is still the fresh job's own default of 0 for the
			// one tick between wwg_start_job resolving and the first real
			// wwg_drive_job batch coming back with an actual folder count --
			// "0% (0 / 0 folders)" would be technically accurate but reads
			// as broken/nothing-to-do rather than "still figuring out how
			// big this is", so this one sliver gets its own plain label
			// instead (see startingLabel's own docblock in PHP).
			if ( ! jobState.total_dirs ) {
				renderStatusScanning( 0, wwgAdmin.strings.startingLabel, '', 'live', wwgAdmin.strings.scanningLabel );
				return;
			}
			var pct = Math.min( 100, Math.round( ( jobState.cursor.dir_index / jobState.total_dirs ) * 100 ) );
			var label = pct + '% (' + jobState.cursor.dir_index + ' / ' + jobState.total_dirs + ' ' + wwgAdmin.strings.folders + ')';
			renderStatusScanning( pct, label, jobState.current_dir, 'live', wwgAdmin.strings.scanningLabel );
			return;
		}

		renderStatusFailuresLiveDuringJob();
		showResultsBox();
		renderResultsRunning();
	}

	// ---- Region 3: Generate Results ----

	function onGenerateClick() {
		if ( 'running' === jobState.status ) {
			return;
		}
		var resuming = 'paused' === jobState.status;
		if ( resuming || window.confirm( wwgAdmin.strings.confirmGenerate ) ) {
			startGenerateJob();
		}
	}

	function startGenerateJob() {
		startingJob = true;
		updateActionButtons(); // disables Generate immediately -- before the round-trip, not after.

		// Immediate visual feedback, before either this request or the
		// first drive request after it has actually landed -- otherwise
		// Generate just looks frozen for that whole stretch (a real gap
		// on a large library: this request, then handle_start_job()'s own
		// ensure_scheduled(), then still waiting on the first driveOnce()
		// to hear back anything about actual progress). A resume already
		// knows which phase it left off in (jobState's own, untouched by
		// this) and can render straight from that; a fresh start always
		// begins counting, regardless of whatever phase a previous
		// completed run happened to leave jobState in.
		if ( 'paused' === jobState.status ) {
			renderRunningState();
		} else {
			renderStatusScanning( 0, wwgAdmin.strings.startingLabel, '', 'live', wwgAdmin.strings.scanningLabel );
		}

		// No total_missing sent -- the job's own counting phase measures
		// it firsthand (see WWG_Job::process_one_batch()'s phase flip)
		// instead of trusting a client-known number.
		requestJobAction( wwgAdmin.jobActions.start ).then( function ( json ) {
			startingJob = false;
			if ( ! json.success ) {
				handleRequestFailure( els.log, json );
				revertOptimisticStart();
				return;
			}

			jobState = json.data;
			renderRunningState();
			startDriving();
		} ).catch( function ( err ) {
			startingJob = false;
			handleNetworkFailure( els.log, err );
			revertOptimisticStart();
		} );
	}

	// Undoes the optimistic render above if the start/resume request
	// itself never actually succeeded -- jobState was never touched by
	// that render (it only reads from it), so both regions just need
	// re-rendering from whatever's still true rather than what was
	// hopefully about to be.
	function revertOptimisticStart() {
		if ( 'paused' === jobState.status ) {
			renderResultsPaused();
		} else {
			renderStatusFromState();
		}
		updateActionButtons();
	}

	function startDriving() {
		if ( driving ) {
			return;
		}
		driving = true;
		driveRetryDelay = 400;
		driveOnce();
	}

	function stopDriving() {
		driving = false;
		if ( driveTimeout ) {
			clearTimeout( driveTimeout );
			driveTimeout = null;
		}
	}

	function driveOnce() {
		requestJobAction( wwgAdmin.jobActions.drive ).then( function ( json ) {
			if ( ! driving ) {
				return; // stopDriving() ran while this request was in flight.
			}
			if ( ! json.success ) {
				handleRequestFailure( els.log, json );
				driving = false;
				return;
			}

			var outcome = json.data.outcome;
			jobState = json.data.state;

			// Present on the tick where the job's own counting phase just
			// finished, and (as a fallback) on a poll that finds the job
			// already done at entry -- e.g. WP-Cron's own safety-net tick
			// won that same transition first (see
			// WWG_Job::process_one_batch()'s own docblock). Without
			// picking this up, Region 1 would stay frozen on its last
			// "counting" render, since nothing else in this driving loop
			// ever re-renders it with the server's own fresh numbers.
			if ( json.data.scan_state ) {
				scanState = json.data.scan_state;
				renderStatusFromState();
			}

			if ( 'locked' === outcome ) {
				// A cron tick (or another tab) is mid-batch -- back off
				// instead of hammering the server in a tight loop, and
				// grow the wait each consecutive time this happens.
				driveTimeout = setTimeout( driveOnce, driveRetryDelay );
				driveRetryDelay = Math.min( driveRetryDelay * 2, DRIVE_RETRY_MAX_MS );
				return;
			}

			driveRetryDelay = 400;

			if ( 'advanced' === outcome ) {
				renderRunningState();
				driveOnce();
			} else if ( 'done' === outcome ) {
				driving = false;
				renderResultsDone();
			} else {
				// 'stopped' -- the state changed out from under this loop
				// (e.g. cancelled from another tab); render whatever it
				// actually is now rather than assuming.
				driving = false;
				if ( 'paused' === jobState.status ) {
					renderResultsPaused();
				} else if ( 'done' === jobState.status ) {
					renderResultsDone();
				}
			}
		} ).catch( function ( err ) {
			if ( ! driving ) {
				return;
			}
			handleNetworkFailure( els.log, err );
			driving = false;
		} );
	}

	function showResultsBox() {
		els.resultsBox.hidden = false;
		// Region 3's own header (below) takes over narrating this job the
		// instant it becomes visible -- the counting-only "what's
		// happening" dot/chip above the progress bar (see
		// renderStatusScanning()) has nothing left to say from here on.
		els.progressStatus.hidden = true;
	}

	// Once every missing image Scan found has been processed,
	// converted/failed/bytes are final for this run, whether it's still
	// running (walking the rest of the library for anything Scan might
	// have missed -- bookkeeping, not more conversion work), paused, or
	// done. A fact about the numbers, not the status -- deliberately NOT
	// gated on jobState.status === 'running' (an earlier version of this
	// was, which is exactly why Cancel mid-way-through-the-bookkeeping-
	// phase still showed "so far" while paused: the running-only gate
	// went false right as the pause render ran, even though the numbers
	// were already final by then). Returns '' when there's nothing final
	// to say yet -- callers treat that as their own "still working" case.
	function buildRunSummaryText() {
		var doneWithRealWork = jobState.total_missing > 0
			&& ( jobState.stats.converted + jobState.stats.failed ) >= jobState.total_missing;

		if ( ! doneWithRealWork ) {
			return '';
		}

		var stillMissingTemplate = 1 === jobState.stats.failed
			? wwgAdmin.strings.stillMissingSummarySingular
			: wwgAdmin.strings.stillMissingSummaryPlural;
		return wwgAdmin.strings.generatedFinal.replace( '%d', jobState.stats.converted )
			+ ( jobState.stats.failed > 0 ? ' ' + stillMissingTemplate.replace( '%1$d', jobState.stats.failed ) : '' )
			+ ( jobState.cache_cleared ? ' ' + wwgAdmin.strings.cacheClearedPast : '' );
	}

	// Fills in everything renderResultsRunning()/Paused() share (Done no
	// longer calls this at all -- see its own docblock): the shared
	// progress bar, the compact summary sentence, and the recoveries
	// list, plus one nudge to Region 1's Failed Conversions list, since
	// both callers need all of that on every update too and it would
	// otherwise mean duplicating the same calls. Each caller still sets
	// its own dot/chip and log line, since those are the only parts that
	// actually differ between running and paused.
	function renderResultsCommon() {
		var target = jobState.total_missing;
		var processed = Math.min( jobState.stats.converted + jobState.stats.failed, target || 0 );
		var pct = target ? Math.min( 100, Math.round( ( processed / target ) * 100 ) ) : 100;
		els.progress.hidden = false;
		els.progressFill.style.width = pct + '%';
		els.progressLabel.textContent = pct + '% (' + processed + ' / ' + target + ' ' + wwgAdmin.strings.images + ')';

		renderStatusFailuresLiveDuringJob();
		renderRecoveriesInto( jobState.stats.recoveries );

		var summaryText = buildRunSummaryText();
		// Left empty (collapses via .wwg-summary:empty) rather than a
		// "N images generated so far" sentence while still working --
		// that number is exactly the same one the progress line above
		// already shows, and renderResultsRunning() also appends the
		// current folder onto that same line, so this used to be the
		// third line in a row restating "how far along is this".
		els.summary.textContent = summaryText;

		return '' !== summaryText; // doneWithRealWork, for renderResultsRunning()'s own use.
	}

	function renderResultsRunning() {
		setStatusDot( els.resultsDot, 'live' );
		setChipLabel( els.resultsChipLabel, 'live', wwgAdmin.strings.generatingLabel );

		var doneWithRealWork = renderResultsCommon();

		// Appended onto the progress line's own text (set by
		// renderResultsCommon() just above) rather than shown as its own
		// line via els.log -- see 'converting'/'stillScanning's own
		// docblock in PHP for why. els.log is left free for its other job
		// here, the 'paused' message (renderResultsPaused() below).
		if ( jobState.current_dir ) {
			var template = doneWithRealWork ? wwgAdmin.strings.stillScanning : wwgAdmin.strings.converting;
			els.progressLabel.textContent += ' - ' + template.replace( '%s', jobState.current_dir );
		}
		els.log.hidden = true;

		updateActionButtons();
	}

	function renderResultsPaused() {
		stopDriving();

		if ( 'counting' === jobState.phase ) {
			// Region 3 has never been shown yet at this point (see
			// renderRunningState()) -- leave it hidden, and reflect the
			// pause in the shared "what's happening" dot/chip above the
			// progress bar, same one renderStatusScanning() uses -- not
			// Region 1, which never changes for this. The bar itself is
			// left exactly where it was -- frozen, not reset or hidden --
			// same as a converting-phase pause below.
			setStatusDot( els.progressDot, 'paused' );
			setChipLabel( els.progressChipLabel, 'paused', wwgAdmin.strings.pausedLabel );
			els.log.hidden = false;
			els.log.textContent = wwgAdmin.strings.paused;
			updateActionButtons();
			return;
		}

		setStatusDot( els.resultsDot, 'paused' );
		setChipLabel( els.resultsChipLabel, 'paused', wwgAdmin.strings.pausedLabel );

		renderResultsCommon();
		els.log.hidden = false;
		els.log.textContent = wwgAdmin.strings.paused;
		// Progress bar / summary are left exactly where they were --
		// paused, not reset or hidden.

		updateActionButtons();
	}

	// No more separate "Last Run" box once a run settles -- Region 3
	// (els.resultsBox) is only ever for the live running/paused states
	// now (see its own markup comment); this instead writes a single
	// quiet line at the bottom of Library Status (right after the Failed
	// Conversions panel -- see #wwg-status-last-run), reusing the exact
	// same "N generated…" wording buildRunSummaryText() already builds
	// for the live states. No date prefix here anymore -- that moved to
	// the table's own "As of" badge (see renderStatusDone()), since it's
	// a fact about the table, not this sentence; when there's nothing to
	// report (buildRunSummaryText() empty -- e.g. a run that found
	// nothing missing), this collapses away entirely rather than leaving
	// a blank line where a sentence used to be.
	function renderResultsDone() {
		stopDriving();
		els.resultsBox.hidden = true;

		var summaryText = buildRunSummaryText();
		els.statusLastRun.hidden = ( '' === summaryText );
		els.statusLastRun.textContent = summaryText;

		renderRecoveriesInto( jobState.stats.recoveries );

		updateActionButtons();
	}

	function requestCancel() {
		if ( 'running' !== jobState.status ) {
			return;
		}
		// Stop the tight drive loop the instant Cancel is clicked, rather
		// than waiting on this round-trip -- renderResultsPaused() also
		// calls stopDriving(), but not until the response below lands.
		stopDriving();
		// Prevent repeat clicks while the in-flight request finishes.
		els.cancelBtn.disabled = true;

		requestJobAction( wwgAdmin.jobActions.cancel ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( els.log, json );
				els.cancelBtn.disabled = false;
				return;
			}

			jobState = json.data;
			renderResultsPaused();
		} ).catch( function ( err ) {
			handleNetworkFailure( els.log, err );
			els.cancelBtn.disabled = false;
		} );
	}

	/**
	 * Called once on page load -- makes both regions reflect whatever's
	 * actually true server-side, instead of always booting to a blank
	 * screen. This is what lets a mid-run reload, or coming back after
	 * the job finished (or a scan completed) elsewhere, show up correctly
	 * with no click required.
	 */
	function boot() {
		renderStatusFromState();

		if ( 'running' === jobState.status ) {
			renderRunningState(); // shows Region 3 itself, but only once past counting.
			startDriving();
		} else if ( 'paused' === jobState.status ) {
			// Only worth showing Region 3 for a pause past the counting
			// phase -- renderResultsPaused() itself handles a counting-
			// phase pause entirely within Region 1 (see its own docblock).
			if ( 'counting' !== jobState.phase ) {
				showResultsBox();
			}
			renderResultsPaused();
		} else if ( 'done' === jobState.status ) {
			renderResultsDone(); // writes to the bottom of Library Status itself now -- no results box to show.
		}
		// 'idle': neither Region 3 nor the bottom-of-Library-Status last
		// run note have anything to show -- nothing has ever run yet.
	}

	function handleRequestFailure( logEl, json ) {
		logEl.hidden = false;
		logEl.textContent = wwgAdmin.strings.error + ' ' + ( ( json.data && json.data.message ) || '' );
	}

	function handleNetworkFailure( logEl, err ) {
		logEl.hidden = false;
		logEl.textContent = wwgAdmin.strings.error + ' ' + err;
	}
} )();
