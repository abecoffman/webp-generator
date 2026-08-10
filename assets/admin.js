/**
 * Drives the Tools > WebP Generator "Generate WebP Images" panel.
 *
 * Scan is a small, bounded client-driven loop: JS calls WWG_Admin's AJAX
 * endpoint, gets a cursor back, calls again, until done.
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
 *
 *  1. Only "Scan" starts enabled (unless a Generate job is already
 *     running/paused/done from a previous pageview -- see bootGenerate()).
 *  2. Scan runs, live count climbing, until it's walked the whole
 *     uploads tree once.
 *  3. Scan finishes: shows how many images are missing a .webp, enables
 *     "Generate", disables "Scan" (one scan per page load).
 *  4. Generate starts the background job and drives it at full speed
 *     while this tab stays open; enables "Cancel", disables "Generate".
 *     Reloading the page while this is running resumes driving
 *     immediately, no click needed -- closing the tab instead just
 *     stops the fast path, falling back to WP-Cron's own (slower, but
 *     unattended) pace.
 *  5. Cancel pauses the job server-side -- progress and cursor are kept,
 *     not discarded -- and re-enables "Generate" to resume from exactly
 *     where it left off, even across a reload.
 *  6. The job finishing naturally (not cancelled) disables every button
 *     and shows the final summary -- also true immediately on page load
 *     if it finished while this tab was elsewhere.
 */
( function () {
	'use strict';

	var els = {};
	var scanRunning = false;
	var jobState = null;

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

	// Scan's missing-image count, needed once: as the target Generate's
	// progress bar is sized against when it starts a fresh job. Only
	// relevant for a *fresh* start -- Generate stays disabled until Scan
	// finishes in the same pageview, so this is always set by the time
	// it's read; resuming a paused/already-running job (from this or an
	// earlier pageview) uses the target the server already recorded.
	var scanMissingCount = 0;

	document.addEventListener( 'DOMContentLoaded', function () {
		els.panel = document.querySelector( '.wwg-panel' );
		els.scanBtn = document.getElementById( 'wwg-scan' );
		els.generateBtn = document.getElementById( 'wwg-generate' );
		els.cancelBtn = document.getElementById( 'wwg-cancel' );
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

		els.scanBtn.addEventListener( 'click', runScan );
		els.generateBtn.addEventListener( 'click', onGenerateClick );
		els.cancelBtn.addEventListener( 'click', requestCancel );

		bootGenerate();
	} );

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
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

	// ---- Scan: unchanged, small bounded client-driven loop ----

	function freshScanTotals() {
		return { scanned: 0, missing: 0, originalBytes: 0 };
	}

	function runScan() {
		if ( scanRunning ) {
			return;
		}
		scanRunning = true;

		els.scanBtn.disabled = true;
		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = true;

		els.progress.hidden = false;
		els.progressFill.style.width = '0%';
		els.progressLabel.textContent = '';
		els.log.textContent = '';
		els.summary.textContent = wwgAdmin.strings.scannedSoFar.replace( '%d', 0 );

		// A fresh Scan means a fresh picture is coming -- a previous
		// run's Generate results (now possibly stale, especially the
		// "done" case Scan being re-enabled at all is meant to let
		// someone check again from) shouldn't linger next to it looking
		// like part of the new scan. Generate re-populates/un-hides
		// these itself the moment it actually starts.
		els.convertResults.hidden = true;
		els.failures.hidden = true;
		els.recoveries.hidden = true;

		scanStep( freshScanTotals(), 0, 0 );
	}

	function scanStep( totals, dirIndex, fileOffset ) {
		requestBatch( dirIndex, fileOffset ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( json );
				els.scanBtn.disabled = false; // let them retry a failed scan.
				return;
			}

			var data = json.data;
			totals.scanned += data.stats.scanned;
			totals.missing += data.stats.missing;
			totals.originalBytes += data.stats.original_bytes;

			var pct = data.total_dirs ? Math.min( 100, Math.round( ( data.dir_index / data.total_dirs ) * 100 ) ) : 100;
			els.progressFill.style.width = pct + '%';
			els.progressLabel.textContent = pct + '% (' + data.dir_index + ' / ' + data.total_dirs + ' ' + wwgAdmin.strings.folders + ')';

			if ( data.done ) {
				els.log.textContent = '';
			} else {
				var label = data.dir ? data.dir : wwgAdmin.strings.uploadsRoot;
				els.log.textContent = wwgAdmin.strings.checking.replace( '%s', label );
			}

			els.summary.textContent = wwgAdmin.strings.scannedSoFar.replace( '%d', totals.scanned );

			if ( data.done ) {
				scanRunning = false;
				finishScan( totals );
				return;
			}

			scanStep( totals, data.dir_index, data.file_offset );
		} ).catch( function ( err ) {
			handleNetworkFailure( err );
			els.scanBtn.disabled = false;
		} );
	}

	function finishScan( totals ) {
		if ( totals.missing === 0 ) {
			els.summary.textContent = wwgAdmin.strings.missingNone;
			els.generateBtn.disabled = true;
		} else {
			var template = totals.missing === 1 ? wwgAdmin.strings.missingSingular : wwgAdmin.strings.missingPlural;
			els.summary.textContent = template
				.replace( '%1$d', totals.missing )
				.replace( '%2$s', formatBytes( totals.originalBytes ) );
			els.generateBtn.disabled = els.panel.getAttribute( 'data-webp-supported' ) !== '1';
			scanMissingCount = totals.missing;
		}
		// "Scan" stays disabled -- one scan per page load, by design;
		// reload the page for a fresh count.
	}

	// ---- Generate: drives a background job while this tab is open ----

	function onGenerateClick() {
		if ( jobState.status === 'running' ) {
			return;
		}
		var resuming = jobState.status === 'paused';
		if ( resuming || window.confirm( wwgAdmin.strings.confirmGenerate ) ) {
			startGenerateJob();
		}
	}

	function startGenerateJob() {
		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = false;
		els.log.textContent = '';
		els.progress.hidden = false;

		requestJobAction( wwgAdmin.jobActions.start, { total_missing: scanMissingCount } ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( json );
				els.generateBtn.disabled = false;
				els.cancelBtn.disabled = true;
				return;
			}

			jobState = json.data;
			renderJobState();
			startDriving();
		} ).catch( function ( err ) {
			handleNetworkFailure( err );
			els.generateBtn.disabled = false;
			els.cancelBtn.disabled = true;
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
				handleRequestFailure( json );
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
			renderJobState();

			if ( 'advanced' === outcome ) {
				driveOnce();
			} else if ( 'done' === outcome ) {
				driving = false;
				renderDoneUI();
			} else {
				// 'stopped' -- the state changed out from under this loop
				// (e.g. cancelled from another tab); render whatever it
				// actually is now rather than assuming.
				driving = false;
				if ( jobState.status === 'paused' ) {
					renderPausedUI();
				} else if ( jobState.status === 'done' ) {
					renderDoneUI();
				}
			}
		} ).catch( function ( err ) {
			if ( ! driving ) {
				return;
			}
			handleNetworkFailure( err );
			driving = false;
		} );
	}

	function updateGenerateProgressBar() {
		var target = jobState.total_missing;
		var processed = Math.min( jobState.stats.converted + jobState.stats.failed, target || 0 );
		var pct = target ? Math.min( 100, Math.round( ( processed / target ) * 100 ) ) : 100;
		els.progressFill.style.width = pct + '%';
		els.progressLabel.textContent = pct + '% (' + processed + ' / ' + target + ' ' + wwgAdmin.strings.images + ')';
	}

	function renderFailures() {
		var failures = jobState.stats.failures;
		if ( ! failures.length ) {
			return;
		}
		els.failures.hidden = false;
		els.failuresCount.textContent = failures.length;

		// Keep the DOM light on a run with hundreds of failures -- the
		// count above already reflects the true total, this list is for
		// spot-checking specific files, not an exhaustive report.
		var toShow = failures.slice( -50 );
		els.failuresList.innerHTML = '';
		toShow.forEach( function ( failure ) {
			var li = document.createElement( 'li' );
			li.textContent = failure.file + ': ' + failure.error;
			els.failuresList.appendChild( li );
		} );
	}

	function renderRecoveries() {
		var recoveries = jobState.stats.recoveries;
		if ( ! recoveries.length ) {
			return;
		}
		els.recoveries.hidden = false;
		els.recoveriesCount.textContent = recoveries.length;

		// Same "spot-check, not exhaustive report" reasoning as
		// renderFailures() above.
		var toShow = recoveries.slice( -50 );
		els.recoveriesList.innerHTML = '';
		toShow.forEach( function ( recovery ) {
			var li = document.createElement( 'li' );
			li.textContent = recovery.file;
			els.recoveriesList.appendChild( li );
		} );
	}

	function renderJobState() {
		updateGenerateProgressBar();
		renderFailures();
		renderRecoveries();

		els.convertResults.hidden = false;
		document.getElementById( 'wwg-c-failed' ).textContent = jobState.stats.failed;
		document.getElementById( 'wwg-c-bytes' ).textContent =
			formatBytes( jobState.stats.webp_bytes ) + ' ' + wwgAdmin.strings.vsOriginal + ' ' + formatBytes( jobState.stats.original_bytes );

		// Once every missing image Scan found has been processed,
		// converted/failed/bytes are final for this run, whether it's
		// still running (walking the rest of the library for anything
		// Scan might have missed -- bookkeeping, not more conversion
		// work), paused, or done. A fact about the numbers, not the
		// status -- deliberately NOT gated on jobState.status === 'running'
		// (an earlier version of this was, which is exactly why Cancel
		// mid-way-through-the-bookkeeping-phase still showed "so far"
		// while paused: the running-only gate went false right as
		// renderPausedUI() called this, even though the numbers were
		// already final by then).
		var doneWithRealWork = jobState.total_missing > 0
			&& ( jobState.stats.converted + jobState.stats.failed ) >= jobState.total_missing;

		if ( doneWithRealWork ) {
			els.summary.textContent = wwgAdmin.strings.generatedFinal.replace( '%d', jobState.stats.converted )
				+ ( jobState.stats.failed > 0 ? ' ' + wwgAdmin.strings.failedSummary.replace( '%d', jobState.stats.failed ) : '' );
		} else {
			els.summary.textContent = wwgAdmin.strings.generatedSoFar.replace( '%d', jobState.stats.converted );
		}

		if ( jobState.status === 'running' && jobState.current_dir ) {
			var template = doneWithRealWork ? wwgAdmin.strings.stillScanning : wwgAdmin.strings.converting;
			els.log.textContent = template.replace( '%s', jobState.current_dir );
		}
	}

	function renderPausedUI() {
		stopDriving();
		els.generateBtn.disabled = false;
		els.cancelBtn.disabled = true;
		els.log.textContent = wwgAdmin.strings.paused;
		renderJobState();
		// Progress bar / summary are left exactly where they were --
		// paused, not reset or hidden.
	}

	function renderDoneUI() {
		stopDriving();
		// Scan deliberately stays enabled here (unlike Generate/Cancel,
		// which have nothing left to do) -- before jobState was persisted
		// server-side, "reload the page for a fresh count" was a real
		// escape hatch back to a blank slate; now a reload just hydrates
		// straight back into this same done state, so without this, a
		// finished run left Scan permanently unclickable with no way to
		// check the library again short of clearing server state by hand.
		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = true;
		els.progress.hidden = false;
		renderJobState(); // sets els.summary to the final "N generated. M failed…" tally already.

		// Deliberately short -- the summary line renderJobState() just
		// set already states the count+failed tally right above the
		// stats grid; repeating it here would read as the same sentence
		// twice in a row. This line's only job is the explicit "done"
		// signal (distinct from the "still finishing the folder scan"
		// state, which shows the same summary phrasing while running)
		// plus the cache-cleared note, if there is one.
		els.log.textContent = wwgAdmin.strings.doneLabel
			+ ( jobState.cache_cleared ? ' ' + wwgAdmin.strings.cacheCleared : '' );
	}

	function requestCancel() {
		if ( jobState.status !== 'running' ) {
			return;
		}
		// Stop the tight drive loop the instant Cancel is clicked, rather
		// than waiting on this round-trip -- renderPausedUI() also calls
		// stopDriving(), but not until the response below lands.
		stopDriving();
		// Prevent repeat clicks while the in-flight request finishes.
		els.cancelBtn.disabled = true;

		requestJobAction( wwgAdmin.jobActions.cancel ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( json );
				els.cancelBtn.disabled = false;
				return;
			}

			jobState = json.data;
			renderPausedUI();
		} ).catch( function ( err ) {
			handleNetworkFailure( err );
			els.cancelBtn.disabled = false;
		} );
	}

	/**
	 * Called once on page load -- makes the panel reflect whatever the
	 * background job's actual state is, instead of always booting to a
	 * blank "click Generate" screen. This is what lets a mid-run reload,
	 * or coming back after the job finished elsewhere, show up correctly
	 * with no click required.
	 */
	function bootGenerate() {
		if ( jobState.status === 'running' ) {
			// Disabled here to match what's already true within a single
			// pageview: runScan() disables Scan the moment it's clicked
			// and nothing re-enables it while Generate is going, so
			// clicking Scan mid-run was never actually reachable that
			// way. Landing on the page fresh mid-run (a reload, or
			// coming back later) skipped that guard entirely, though --
			// without this, Scan stayed clickable, and clicking it would
			// visually disable Cancel (runScan() does that unconditionally)
			// without actually stopping the drive loop, leaving no way to
			// cancel except reloading again.
			els.scanBtn.disabled = true;
			els.generateBtn.disabled = true;
			els.cancelBtn.disabled = false;
			els.progress.hidden = false;
			renderJobState();
			startDriving();
		} else if ( jobState.status === 'paused' ) {
			els.progress.hidden = false;
			renderPausedUI();
		} else if ( jobState.status === 'done' ) {
			renderDoneUI();
		}
		// 'idle': today's default boot state -- Generate stays disabled
		// until Scan finishes (see finishScan()).
	}

	function handleRequestFailure( json ) {
		els.log.textContent = wwgAdmin.strings.error + ' ' + ( ( json.data && json.data.message ) || '' );
	}

	function handleNetworkFailure( err ) {
		els.log.textContent = wwgAdmin.strings.error + ' ' + err;
	}
} )();
