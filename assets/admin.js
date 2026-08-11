/**
 * Drives the Tools > WebP Generator "Generate WebP Images" panel.
 *
 * The panel is three regions, always in the same place:
 *   1. Library Status  -- what's currently known about the media library
 *      (a persisted server-side snapshot of Scan's last completed run --
 *      see WWG_Admin::OPTION_SCAN_STATE -- so it survives a reload
 *      instead of resetting to blank).
 *   2. Actions -- the Scan/Generate/Cancel buttons.
 *   3. Generate Results -- Generate's own live progress while running,
 *      settling into a dated "last run" record once done (a persisted
 *      WWG_Job state, same as before).
 * Each region's *substate* changes in place (a small status dot + label)
 * rather than jumping to a different part of the page, and each is
 * rendered from server-authoritative state on every page load, not just
 * live updates -- the same rule applies whether a state just became true
 * a second ago or three weeks ago.
 *
 * Scan is a small, bounded client-driven loop: JS calls WWG_Admin's AJAX
 * endpoint, gets a cursor back, calls again, until done, then saves the
 * final tally server-side.
 *
 * Generate is different: it runs as a WP-Cron background job (see
 * WWG_Job) so it keeps going even if this tab closes. While the tab
 * *is* open, though, this script actively drives it at full speed --
 * each batch's response immediately triggers the next request -- rather
 * than passively waiting on WP-Cron's own ~60s self-throttle, which
 * applies whether or not anyone's watching. WWG_Job::process_one_batch()
 * shares one lock between this driving loop and the cron tick, so they
 * can never double-process the same batch; whichever gets there first
 * wins, the other backs off and retries shortly. That's also what makes
 * page load able to resume mid-run at full speed: the state this boots
 * from (`wwgAdmin.jobState`) is the server's, not something this script
 * has to have built up itself in the current pageview.
 */
( function () {
	'use strict';

	var els = {};
	var scanRunning = false;
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
		els.scanBtn = document.getElementById( 'wwg-scan' );
		els.generateBtn = document.getElementById( 'wwg-generate' );
		els.cancelBtn = document.getElementById( 'wwg-cancel' );

		// Region 1: Library Status.
		els.statusDot = document.getElementById( 'wwg-status-dot' );
		els.statusChipLabel = document.getElementById( 'wwg-status-chip-label' );
		els.statusIdle = document.getElementById( 'wwg-status-idle' );
		els.statusHeadline = document.getElementById( 'wwg-status-headline' );
		els.statusMeta = document.getElementById( 'wwg-status-meta' );
		els.statusProgress = document.getElementById( 'wwg-status-progress' );
		els.statusProgressFill = els.statusProgress.querySelector( '.wwg-progress-bar-fill' );
		els.statusProgressLabel = els.statusProgress.querySelector( '.wwg-progress-label' );
		els.statusLog = document.getElementById( 'wwg-status-log' );
		els.statusFailures = document.getElementById( 'wwg-status-failures' );
		els.statusFailuresCount = document.getElementById( 'wwg-status-failures-count' );
		els.statusFailuresList = document.getElementById( 'wwg-status-failures-list' );

		// Region 3: Generate Results.
		els.resultsBox = document.getElementById( 'wwg-results-box' );
		els.resultsDot = document.getElementById( 'wwg-results-dot' );
		els.resultsChipLabel = document.getElementById( 'wwg-results-chip-label' );
		els.progress = document.getElementById( 'wwg-progress' );
		els.progressFill = els.progress.querySelector( '.wwg-progress-bar-fill' );
		els.progressLabel = els.progress.querySelector( '.wwg-progress-label' );
		els.summary = document.getElementById( 'wwg-summary' );
		els.convertResults = document.getElementById( 'wwg-convert-results' );
		els.failures = document.getElementById( 'wwg-failures' );
		els.failuresCount = document.getElementById( 'wwg-failures-count' );
		els.failuresList = document.getElementById( 'wwg-failures-list' );
		els.recoveries = document.getElementById( 'wwg-recoveries' );
		els.recoveriesCount = document.getElementById( 'wwg-recoveries-count' );
		els.recoveriesList = document.getElementById( 'wwg-recoveries-list' );
		els.log = document.getElementById( 'wwg-log' );

		jobState = wwgAdmin.jobState;
		scanState = wwgAdmin.scanState;
		generateDefaultLabel = els.generateBtn.textContent;

		els.scanBtn.addEventListener( 'click', runScan );
		els.generateBtn.addEventListener( 'click', onGenerateClick );
		els.cancelBtn.addEventListener( 'click', requestCancel );

		boot();
	} );

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
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

	function requestBatch( dirIndex, fileOffset ) {
		var body = new FormData();
		body.append( 'action', wwgAdmin.action );
		body.append( 'nonce', wwgAdmin.nonce );
		body.append( 'dir_index', dirIndex );
		body.append( 'file_offset', fileOffset );

		return fetch( wwgAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
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

	function requestSaveScanResult( totals ) {
		return requestJobAction( wwgAdmin.scanSaveAction, {
			missing: totals.missing,
			original_bytes: totals.originalBytes,
			// A JSON string, not repeated form fields -- each entry is a
			// {file, error} pair, and PHP decodes this the same way it
			// already treats the rest of this endpoint's input: capped,
			// sanitized, never trusted length-wise.
			failures: JSON.stringify( totals.failures.slice( -500 ) ),
		} );
	}

	// ---- Shared rendering helpers (both Region 1 and Region 3 use these) ----

	function setStatusDot( el, state ) {
		el.className = 'wwg-status-dot wwg-status-dot--' + state;
	}

	function setChipLabel( el, state, text ) {
		el.textContent = text;
		el.className = 'wwg-status-chip-label' + ( state && 'idle' !== state ? ' wwg-status-chip-label--' + state : '' );
	}

	// Shared by Region 1's own "Failed conversions" (known failures Scan
	// re-surfaced) and Region 3's (this Generate run's failures) -- a
	// failure reads the same regardless of which box is reporting it.
	function renderFailuresInto( listEl, countEl, detailsEl, failures ) {
		if ( ! failures || ! failures.length ) {
			detailsEl.hidden = true;
			return;
		}
		detailsEl.hidden = false;
		countEl.textContent = failures.length;

		// Keep the DOM light on a run with hundreds of failures -- the
		// count above already reflects the true total, this list is for
		// spot-checking specific files, not an exhaustive report.
		var toShow = failures.slice( -50 );
		listEl.innerHTML = '';
		toShow.forEach( function ( failure ) {
			var li = document.createElement( 'li' );
			li.textContent = failure.file + ': ' + failure.error;
			listEl.appendChild( li );
		} );
	}

	// The one place Scan/Generate/Cancel's enabled/disabled state and
	// Generate's label are decided -- called at the end of every render
	// function in both regions so the buttons can never drift out of
	// sync with what's actually on screen (past bugs in this file were
	// exactly that: button state set ad hoc in several different places
	// that could disagree with each other).
	function updateActionButtons() {
		var jobRunning = 'running' === jobState.status;
		var jobPaused = 'paused' === jobState.status;
		var webpSupported = '1' === els.panel.getAttribute( 'data-webp-supported' );

		// Neither button is ever usable at all on a server that can't
		// produce .webp -- see the readme's own FAQ: "Scan/Generate are
		// disabled until that's resolved, rather than letting you run a
		// tool that can't do anything." Scan doesn't strictly need a
		// working backend itself (it only checks file_exists()), but
		// there'd be nothing useful to do with what it finds.
		els.scanBtn.disabled = scanRunning || jobRunning || startingJob || ! webpSupported;
		els.cancelBtn.disabled = ! jobRunning;

		if ( jobPaused ) {
			els.generateBtn.textContent = wwgAdmin.strings.resumeGenerating;
			els.generateBtn.disabled = scanRunning || startingJob || ! webpSupported;
			return;
		}

		els.generateBtn.textContent = generateDefaultLabel;
		els.generateBtn.disabled = jobRunning
			|| scanRunning
			|| startingJob
			|| ! webpSupported
			|| ! scanState.finished_at // never scanned, or invalidated back to "not checked" -- see renderStatusIdle().
			|| !! scanState.invalidated_at
			|| 0 === scanState.missing;
	}

	// ---- Region 1: Library Status ----

	function renderStatusIdle() {
		setStatusDot( els.statusDot, 'idle' );
		setChipLabel( els.statusChipLabel, 'idle', wwgAdmin.strings.libraryStatusLabel );
		els.statusIdle.hidden = false;
		els.statusProgress.hidden = true;
		els.statusLog.hidden = true;
		els.statusFailures.hidden = true;

		els.statusHeadline.textContent = wwgAdmin.strings.notCheckedYet;
		els.statusMeta.textContent = scanState.invalidated_at
			? wwgAdmin.strings.notCheckedYetInvalidated
			: wwgAdmin.strings.notCheckedYetFirstTime;

		updateActionButtons();
	}

	function renderStatusScanning( pct, label, currentDirLog ) {
		setStatusDot( els.statusDot, 'live' );
		setChipLabel( els.statusChipLabel, 'live', wwgAdmin.strings.scanningLabel );
		els.statusIdle.hidden = true;
		els.statusFailures.hidden = true; // a prior scan's list shouldn't linger while a fresh one runs.

		els.statusProgress.hidden = false;
		els.statusProgressFill.style.width = pct + '%';
		els.statusProgressLabel.textContent = label;

		els.statusLog.hidden = ! currentDirLog;
		els.statusLog.textContent = currentDirLog || '';

		updateActionButtons();
	}

	function renderStatusDone() {
		setStatusDot( els.statusDot, 'done' );
		setChipLabel( els.statusChipLabel, 'done', wwgAdmin.strings.libraryStatusLabel );
		els.statusIdle.hidden = false;
		els.statusProgress.hidden = true;
		els.statusLog.hidden = true;

		if ( 0 === scanState.missing ) {
			els.statusHeadline.textContent = wwgAdmin.strings.missingNone;
		} else {
			var template = 1 === scanState.missing ? wwgAdmin.strings.missingSingular : wwgAdmin.strings.missingPlural;
			var headline = template
				.replace( '%1$d', scanState.missing )
				.replace( '%2$s', formatBytes( scanState.original_bytes ) );

			// Some of the "missing" count above may be files already known
			// to fail permanently -- called out as an explicit *subset* of
			// that count ("Of these, N…"), not a second, seemingly separate
			// number (see missingKnownFailures*'s own docblock in PHP).
			if ( scanState.failures.length ) {
				var knownTemplate = 1 === scanState.failures.length
					? wwgAdmin.strings.missingKnownFailuresSingular
					: wwgAdmin.strings.missingKnownFailuresPlural;
				headline += ' ' + knownTemplate.replace( '%d', scanState.failures.length );
			}
			els.statusHeadline.textContent = headline;
		}

		els.statusMeta.textContent = wwgAdmin.strings.asOf.replace( '%s', formatDateTime( scanState.finished_at ) );
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

	function freshScanTotals() {
		return { scanned: 0, missing: 0, originalBytes: 0, failures: [] };
	}

	function runScan() {
		if ( scanRunning ) {
			return;
		}
		scanRunning = true;
		renderStatusScanning( 0, '', '' );
		scanStep( freshScanTotals(), 0, 0 );
	}

	function scanStep( totals, dirIndex, fileOffset ) {
		requestBatch( dirIndex, fileOffset ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( els.statusLog, json );
				scanRunning = false;
				updateActionButtons(); // let them retry a failed scan.
				return;
			}

			var data = json.data;
			totals.scanned += data.stats.scanned;
			totals.missing += data.stats.missing;
			totals.originalBytes += data.stats.original_bytes;
			if ( data.stats.failures && data.stats.failures.length ) {
				// Known permanent failures Scan is just re-surfacing (it
				// never attempts a real decode itself) -- capped the same
				// way the Generate job's own accumulated stats are, purely
				// defensive since this is expected to stay tiny in practice.
				totals.failures = totals.failures.concat( data.stats.failures ).slice( -500 );
			}

			var pct = data.total_dirs ? Math.min( 100, Math.round( ( data.dir_index / data.total_dirs ) * 100 ) ) : 100;
			var label = pct + '% (' + data.dir_index + ' / ' + data.total_dirs + ' ' + wwgAdmin.strings.folders + ')';
			var currentDirLog = data.done ? '' : wwgAdmin.strings.checking.replace( '%s', data.dir ? data.dir : wwgAdmin.strings.uploadsRoot );
			renderStatusScanning( pct, label, currentDirLog );

			if ( data.done ) {
				scanRunning = false;
				finishScan( totals );
				return;
			}

			scanStep( totals, data.dir_index, data.file_offset );
		} ).catch( function ( err ) {
			handleNetworkFailure( els.statusLog, err );
			scanRunning = false;
			updateActionButtons();
		} );
	}

	function finishScan( totals ) {
		// Render immediately from the totals this run just measured --
		// no need to wait on the save round-trip to show the admin their
		// answer -- then reconcile scanState with whatever the server
		// actually persisted (its clock is authoritative for "As of…").
		var optimistic = {
			missing: totals.missing,
			original_bytes: totals.originalBytes,
			failures: totals.failures,
			finished_at: Math.floor( Date.now() / 1000 ),
			invalidated_at: null,
		};
		scanState = optimistic;
		renderStatusDone();

		requestSaveScanResult( totals ).then( function ( json ) {
			if ( json.success ) {
				scanState = json.data;
				renderStatusDone();
			}
			// A failed save just means this exact result won't survive a
			// reload -- Scan itself still did its job, and the optimistic
			// render above already reflects the accurate answer for this
			// pageview, so there's nothing more to recover from here.
		} );
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

		requestJobAction( wwgAdmin.jobActions.start, { total_missing: scanState.missing } ).then( function ( json ) {
			startingJob = false;
			if ( ! json.success ) {
				handleRequestFailure( els.log, json );
				updateActionButtons();
				return;
			}

			jobState = json.data;
			showResultsBox();
			renderResultsRunning();
			startDriving();
		} ).catch( function ( err ) {
			startingJob = false;
			handleNetworkFailure( els.log, err );
			updateActionButtons();
		} );
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
				renderResultsRunning();
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
	}

	// Fills in everything renderResultsRunning()/Paused()/Done() share:
	// the progress bar, the summary sentence, the stats grid, and the
	// failures/recoveries lists. Each caller sets the dot/chip and log
	// line itself, since those are the only parts that actually differ
	// between running/paused/done.
	function renderResultsCommon() {
		var target = jobState.total_missing;
		var processed = Math.min( jobState.stats.converted + jobState.stats.failed, target || 0 );
		var pct = target ? Math.min( 100, Math.round( ( processed / target ) * 100 ) ) : 100;
		els.progress.hidden = false;
		els.progressFill.style.width = pct + '%';
		els.progressLabel.textContent = pct + '% (' + processed + ' / ' + target + ' ' + wwgAdmin.strings.images + ')';

		els.convertResults.hidden = false;
		document.getElementById( 'wwg-c-failed' ).textContent = jobState.stats.failed;
		document.getElementById( 'wwg-c-bytes' ).textContent =
			formatBytes( jobState.stats.webp_bytes ) + ' ' + wwgAdmin.strings.vsOriginal + ' ' + formatBytes( jobState.stats.original_bytes );

		renderFailuresInto( els.failuresList, els.failuresCount, els.failures, jobState.stats.failures );

		var recoveries = jobState.stats.recoveries;
		if ( recoveries.length ) {
			els.recoveries.hidden = false;
			els.recoveriesCount.textContent = recoveries.length;
			var toShow = recoveries.slice( -50 );
			els.recoveriesList.innerHTML = '';
			toShow.forEach( function ( recovery ) {
				var li = document.createElement( 'li' );
				li.textContent = recovery.file;
				els.recoveriesList.appendChild( li );
			} );
		} else {
			els.recoveries.hidden = true;
		}

		// Once every missing image Scan found has been processed,
		// converted/failed/bytes are final for this run, whether it's
		// still running (walking the rest of the library for anything
		// Scan might have missed -- bookkeeping, not more conversion
		// work), paused, or done. A fact about the numbers, not the
		// status -- deliberately NOT gated on jobState.status === 'running'
		// (an earlier version of this was, which is exactly why Cancel
		// mid-way-through-the-bookkeeping-phase still showed "so far"
		// while paused: the running-only gate went false right as
		// renderResultsPaused() called this, even though the numbers
		// were already final by then).
		var doneWithRealWork = jobState.total_missing > 0
			&& ( jobState.stats.converted + jobState.stats.failed ) >= jobState.total_missing;

		if ( doneWithRealWork ) {
			els.summary.textContent = wwgAdmin.strings.generatedFinal.replace( '%d', jobState.stats.converted )
				+ ( jobState.stats.failed > 0 ? ' ' + wwgAdmin.strings.failedSummary.replace( '%d', jobState.stats.failed ) : '' )
				+ ( jobState.cache_cleared ? ' ' + wwgAdmin.strings.cacheClearedPast : '' );
		} else {
			els.summary.textContent = wwgAdmin.strings.generatedSoFar.replace( '%d', jobState.stats.converted );
		}

		return doneWithRealWork;
	}

	function renderResultsRunning() {
		els.resultsBox.classList.remove( 'wwg-results-box--done' );
		setStatusDot( els.resultsDot, 'live' );
		setChipLabel( els.resultsChipLabel, 'live', wwgAdmin.strings.generatingLabel );

		var doneWithRealWork = renderResultsCommon();

		if ( jobState.current_dir ) {
			els.log.hidden = false;
			var template = doneWithRealWork ? wwgAdmin.strings.stillScanning : wwgAdmin.strings.converting;
			els.log.textContent = template.replace( '%s', jobState.current_dir );
		} else {
			els.log.hidden = true;
		}

		updateActionButtons();
	}

	function renderResultsPaused() {
		stopDriving();
		els.resultsBox.classList.remove( 'wwg-results-box--done' );
		setStatusDot( els.resultsDot, 'paused' );
		setChipLabel( els.resultsChipLabel, 'paused', wwgAdmin.strings.pausedLabel );

		renderResultsCommon();
		els.log.hidden = false;
		els.log.textContent = wwgAdmin.strings.paused;
		// Progress bar / summary are left exactly where they were --
		// paused, not reset or hidden.

		updateActionButtons();
	}

	function renderResultsDone() {
		stopDriving();
		// Scan deliberately stays enabled here (unlike Generate/Cancel,
		// which have nothing left to do): it always has been re-clickable
		// any time now (see updateActionButtons()), and a completed
		// Generate run is exactly when someone's most likely to want a
		// fresh Library Status check.
		els.resultsBox.classList.add( 'wwg-results-box--done' );
		setStatusDot( els.resultsDot, 'done' );
		setChipLabel( els.resultsChipLabel, 'done', wwgAdmin.strings.lastRunLabel.replace( '%s', formatDateTime( jobState.finished_at ) ) );

		renderResultsCommon(); // sets els.summary to the final "N generated. M failed…" tally, cache-cleared note included.

		// Nothing left to say here that the chip label and summary above
		// don't already cover -- deliberately empty rather than a "Done."
		// line that would need its own un-timestamped/timestamped split
		// the way the chip already handles for free.
		els.log.hidden = true;
		els.log.textContent = '';

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
			showResultsBox();
			renderResultsRunning();
			startDriving();
		} else if ( 'paused' === jobState.status ) {
			showResultsBox();
			renderResultsPaused();
		} else if ( 'done' === jobState.status ) {
			showResultsBox();
			renderResultsDone();
		}
		// 'idle': Generate Results box stays absent -- nothing has ever
		// run yet, so there's no history to show.
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
