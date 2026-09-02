/**
 * Media Library List view's own single-image "Generate" button (see
 * WWG_Admin::render_compression_column()'s 'partial' branch, which is the
 * only place that decides whether one of these renders at all). Deliberately
 * standalone, not folded into admin.js -- that file's whole closure is built
 * around the Tools page's job/scan state, none of which exists on this
 * screen; List view's rows are all server-rendered at page load too (normal
 * pagination, not AJAX-injected), so a plain listener per button already on
 * the page is enough -- no event delegation needed.
 *
 * @package WWG
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var buttons = document.querySelectorAll( '.wwg-cc-generate-btn' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				handleClick( btn );
			} );
		} );
	} );

	function handleClick( btn ) {
		var cell = btn.closest( 'td' );
		if ( ! cell ) {
			return;
		}

		var attachmentId  = btn.getAttribute( 'data-attachment-id' );
		var originalLabel = btn.textContent;

		btn.disabled = true;
		btn.textContent = wwgMediaLibrary.strings.generating;

		var body = new FormData();
		body.append( 'action', wwgMediaLibrary.generateAction );
		body.append( 'nonce', wwgMediaLibrary.nonce );
		body.append( 'attachment_id', attachmentId );

		fetch( wwgMediaLibrary.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json.success ) {
					showError( btn, originalLabel );
					return;
				}
				// Whatever this now shows -- fully converted, still
				// partial (another format's button included), or a
				// genuine failure surfaced via the same 'failed' state
				// any other discovery of it would show -- this is the
				// one place that decides, on a fresh page load or right
				// after this click alike. No separate client-side error
				// state for a real conversion failure; it's already
				// handled by what just got rendered.
				cell.innerHTML = json.data.html;
			} )
			.catch( function () {
				showError( btn, originalLabel );
			} );
	}

	// Only for a genuine transport/permission/malformed-request failure --
	// the request itself never reached a real answer, so there's nothing
	// fresh to show; put the button back exactly as it was and let the
	// visitor try again.
	function showError( btn, originalLabel ) {
		btn.disabled = false;
		btn.textContent = originalLabel;

		var existing = btn.parentNode.querySelector( '.wwg-cc-status--failed' );
		if ( existing ) {
			existing.remove();
		}
		var message = document.createElement( 'span' );
		message.className = 'wwg-cc-status wwg-cc-status--failed wwg-cc-generate-error';
		message.textContent = wwgMediaLibrary.strings.error;
		btn.insertAdjacentElement( 'afterend', message );
	}
} )();
