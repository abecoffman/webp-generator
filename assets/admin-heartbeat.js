/**
 * Ambient, every-wp-admin-screen half of the WebP Generator background
 * job's completion signal (see includes/class-wwg-job.php).
 *
 * The dismissible notice and the Tools-menu bubble PHP already renders
 * (WWG_Job::maybe_render_notice() / WWG_Admin::register_page()) reflect
 * whatever was true when the *current* page was requested. This script
 * only covers the case that misses: the background job finishing
 * *after* that, while the admin is sitting on this same screen.
 * WordPress's Heartbeat API is what makes that appear "live" (within its
 * own tick interval -- 60s outside post-editing screens by default,
 * i.e. this is a "within about a minute" signal, not instant) without a
 * full page reload.
 *
 * It also owns persisting a click on either notice's dismiss button back
 * to the server -- WP core's own is-dismissible handling (wp-admin's
 * common.js) is purely visual, fading the notice out of the DOM; it
 * knows nothing about this plugin's job state.
 */
( function ( $ ) {
	'use strict';

	function dismiss() {
		var body = new FormData();
		body.append( 'action', wwgHeartbeat.dismissAction );
		body.append( 'nonce', wwgHeartbeat.nonce );
		fetch( wwgHeartbeat.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } );
	}

	function removeBubble() {
		$( '#adminmenu' )
			.find( 'a[href="tools.php?page=webp-generator"]' )
			.find( '.update-plugins' )
			.remove();
	}

	// #wwg-job-notice's dismiss button is WP core's own .notice-dismiss,
	// injected by common.js for any .is-dismissible notice -- both the
	// one PHP may have rendered on this pageload and the one this script
	// might insert live (below) wire through this one delegated handler.
	$( document ).on( 'click', '#wwg-job-notice .notice-dismiss', function () {
		dismiss();
		removeBubble();
	} );

	function insertNotice( summary ) {
		if ( document.getElementById( 'wwg-job-notice' ) ) {
			return;
		}

		var $notice = $( '<div>', {
			id: 'wwg-job-notice',
			'class': 'notice notice-success is-dismissible wwg-job-notice',
		} );
		var $p = $( '<p>' ).text( summary + ' ' );
		$p.append( $( '<a>', { href: wwgHeartbeat.toolUrl, text: wwgHeartbeat.strings.viewResults } ) );
		$notice.append( $p );

		var $heading = $( '.wrap' ).first().find( 'h1, h2' ).first();
		if ( $heading.length ) {
			$heading.after( $notice );
		} else {
			$( '#wpbody-content' ).prepend( $notice );
		}
	}

	function insertBubble() {
		var $link = $( '#adminmenu' ).find( 'a[href="tools.php?page=webp-generator"]' ).first();
		if ( ! $link.length || $link.find( '.update-plugins' ).length ) {
			return;
		}
		$link.append( ' <span class="update-plugins count-1"><span class="update-count">1</span></span>' );
	}

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data || ! data.wwg_job ) {
			return;
		}

		var job = data.wwg_job;
		if ( 'done' !== job.status || job.seen ) {
			return;
		}

		var summary = wwgHeartbeat.strings.generateDone.replace( '%d', job.converted );
		if ( job.failed > 0 ) {
			summary += ' ' + wwgHeartbeat.strings.failedSummary.replace( '%d', job.failed );
		}
		if ( job.cacheCleared ) {
			summary += ' ' + wwgHeartbeat.strings.cacheCleared;
		}

		insertNotice( summary );
		insertBubble();
	} );
} )( jQuery );
