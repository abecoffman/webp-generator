/**
 * Drives the Tools > WebP Generator "Generate WebP Images" panel as an
 * explicit 3-button state machine (Scan / Generate / Cancel), each step
 * one bounded batch against WWG_Admin's AJAX endpoint:
 *
 *  1. Only "Scan" starts enabled.
 *  2. Scan runs, live count climbing, until it's walked the whole
 *     uploads tree once.
 *  3. Scan finishes: shows how many images are missing a .webp, enables
 *     "Generate", disables "Scan" (one scan per page load).
 *  5. Generate runs, live count climbing; enables "Cancel", disables
 *     "Generate".
 *  6. Cancel stops the batch loop and *pauses* -- current progress and
 *     cursor position are kept, not discarded -- and re-enables
 *     "Generate" to resume from exactly where it left off.
 *  7. Generate finishing naturally (not cancelled) disables every
 *     button and shows the final summary.
 */
( function () {
	'use strict';

	var els = {};
	var running = false;
	var cancelRequested = false;
	var webpSupported = true;

	// Generate is resumable across Cancel/Generate cycles, so its totals
	// and cursor live outside any single run and only get created once.
	var generateTotals = null;
	var generateCursor = { dirIndex: 0, fileOffset: 0 };

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
		els.log = document.getElementById( 'wwg-log' );

		webpSupported = els.panel.getAttribute( 'data-webp-supported' ) === '1';

		els.scanBtn.addEventListener( 'click', runScan );
		els.generateBtn.addEventListener( 'click', function () {
			var resuming = generateTotals !== null;
			if ( resuming || window.confirm( wwgAdmin.strings.confirmGenerate ) ) {
				runGenerate();
			}
		} );
		els.cancelBtn.addEventListener( 'click', requestCancel );
	} );

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
	}

	function freshTotals() {
		return {
			dirsDone: 0,
			totalDirs: 0,
			scanned: 0,
			missing: 0,
			originalBytes: 0,
			converted: 0,
			failed: 0,
			webpBytes: 0,
			cacheCleared: false,
		};
	}

	function requestBatch( mode, dirIndex, fileOffset ) {
		var body = new FormData();
		body.append( 'action', wwgAdmin.action );
		body.append( 'nonce', wwgAdmin.nonce );
		body.append( 'mode', mode );
		body.append( 'dir_index', dirIndex );
		body.append( 'file_offset', fileOffset );

		return fetch( wwgAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function applyStats( totals, data ) {
		totals.dirsDone = data.dir_index;
		totals.totalDirs = data.total_dirs;
		if ( data.stats ) {
			totals.scanned += data.stats.scanned;
			totals.missing += data.stats.missing;
			totals.originalBytes += data.stats.original_bytes;
			totals.converted += data.stats.converted;
			totals.failed += data.stats.failed;
			totals.webpBytes += data.stats.webp_bytes;
		}
		if ( data.cache_cleared ) {
			totals.cacheCleared = true;
		}
	}

	function updateProgressBar( data ) {
		var pct = data.total_dirs ? Math.min( 100, Math.round( ( data.dir_index / data.total_dirs ) * 100 ) ) : 100;
		els.progressFill.style.width = pct + '%';
		els.progressLabel.textContent = pct + '% (' + data.dir_index + ' / ' + data.total_dirs + ' ' + wwgAdmin.strings.folders + ')';
	}

	function updateLog( mode, data ) {
		if ( data.done ) {
			els.log.textContent = '';
			return;
		}
		var template = mode === 'convert' ? wwgAdmin.strings.converting : wwgAdmin.strings.checking;
		var label = data.dir ? data.dir : wwgAdmin.strings.uploadsRoot;
		els.log.textContent = template.replace( '%s', label );
	}

	function renderGenerateResults( totals ) {
		els.convertResults.hidden = false;
		document.getElementById( 'wwg-c-failed' ).textContent = totals.failed;
		document.getElementById( 'wwg-c-bytes' ).textContent =
			formatBytes( totals.webpBytes ) + ' ' + wwgAdmin.strings.vsOriginal + ' ' + formatBytes( totals.originalBytes );
	}

	// ---- Step 1-3: Scan ----

	function runScan() {
		if ( running ) {
			return;
		}
		running = true;

		els.scanBtn.disabled = true;
		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = true;

		els.progress.hidden = false;
		els.progressFill.style.width = '0%';
		els.progressLabel.textContent = '';
		els.log.textContent = '';
		els.summary.textContent = wwgAdmin.strings.scannedSoFar.replace( '%d', 0 );

		scanStep( freshTotals(), 0, 0 );
	}

	function scanStep( totals, dirIndex, fileOffset ) {
		requestBatch( 'scan', dirIndex, fileOffset ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( json );
				els.scanBtn.disabled = false; // let them retry a failed scan.
				return;
			}

			var data = json.data;
			applyStats( totals, data );
			updateProgressBar( data );
			updateLog( 'scan', data );
			els.summary.textContent = wwgAdmin.strings.scannedSoFar.replace( '%d', totals.scanned );

			if ( data.done ) {
				running = false;
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
			els.generateBtn.disabled = ! webpSupported;
		}
		// "Scan" stays disabled -- one scan per page load, by design;
		// reload the page for a fresh count.
	}

	// ---- Steps 5-7: Generate / Cancel ----

	function runGenerate() {
		if ( running ) {
			return;
		}
		running = true;
		cancelRequested = false;

		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = false;
		els.log.textContent = '';

		if ( ! generateTotals ) {
			generateTotals = freshTotals();
			els.progress.hidden = false;
			els.progressFill.style.width = '0%';
			els.progressLabel.textContent = '';
		}
		// Resuming after a pause: progress bar/label are left exactly as
		// they were, not reset.

		generateStep( generateCursor.dirIndex, generateCursor.fileOffset );
	}

	function generateStep( dirIndex, fileOffset ) {
		if ( cancelRequested ) {
			pauseGenerate( dirIndex, fileOffset );
			return;
		}

		requestBatch( 'convert', dirIndex, fileOffset ).then( function ( json ) {
			if ( ! json.success ) {
				handleRequestFailure( json );
				els.generateBtn.disabled = false;
				els.cancelBtn.disabled = true;
				return;
			}

			var data = json.data;
			applyStats( generateTotals, data );
			updateProgressBar( data );
			updateLog( 'convert', data );
			renderGenerateResults( generateTotals );
			els.summary.textContent = wwgAdmin.strings.generatedSoFar.replace( '%d', generateTotals.converted );

			generateCursor = { dirIndex: data.dir_index, fileOffset: data.file_offset };

			if ( data.done ) {
				running = false;
				finishGenerate();
				return;
			}

			if ( cancelRequested ) {
				pauseGenerate( data.dir_index, data.file_offset );
				return;
			}

			generateStep( data.dir_index, data.file_offset );
		} ).catch( function ( err ) {
			handleNetworkFailure( err );
			els.generateBtn.disabled = false;
			els.cancelBtn.disabled = true;
		} );
	}

	function requestCancel() {
		if ( ! running ) {
			return;
		}
		cancelRequested = true;
		// Prevent repeat clicks while the in-flight request finishes --
		// the pause itself happens as soon as that response lands.
		els.cancelBtn.disabled = true;
	}

	function pauseGenerate( dirIndex, fileOffset ) {
		running = false;
		generateCursor = { dirIndex: dirIndex, fileOffset: fileOffset };
		els.generateBtn.disabled = false;
		els.cancelBtn.disabled = true;
		els.log.textContent = wwgAdmin.strings.paused;
		// Progress bar / summary are left exactly where they were --
		// paused, not reset or hidden.
	}

	function finishGenerate() {
		els.scanBtn.disabled = true;
		els.generateBtn.disabled = true;
		els.cancelBtn.disabled = true;

		els.log.textContent = wwgAdmin.strings.generateDone.replace( '%d', generateTotals.converted )
			+ ( generateTotals.failed > 0 ? ' (' + generateTotals.failed + ' failed -- see error log)' : '' )
			+ ( generateTotals.cacheCleared ? ' ' + wwgAdmin.strings.cacheCleared : '' );
	}

	function handleRequestFailure( json ) {
		running = false;
		els.log.textContent = wwgAdmin.strings.error + ' ' + ( ( json.data && json.data.message ) || '' );
	}

	function handleNetworkFailure( err ) {
		running = false;
		els.log.textContent = wwgAdmin.strings.error + ' ' + err;
	}
} )();
