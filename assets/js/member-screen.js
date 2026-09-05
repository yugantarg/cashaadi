/**
 * Another member's profile, inside the app.
 *
 * Uses the shared csmProfileCard() so this, Discover and "how others see me"
 * cannot drift apart. The only thing this screen adds is the action row, which
 * depends on what the viewer can actually do with this person.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_MEMBER;
	var root = document.getElementById( 'csm-member-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	/**
	 * One action, chosen from the relationship — not a row of buttons where
	 * most do nothing. BuddyPress's own page offered Add Match, Private Message
	 * and Block side by side regardless of whether any applied.
	 */
	function actions( d ) {
		var wrap = el( 'div', 'csm-mb-actions' );

		if ( 'is_friend' === d.friendship ) {
			var msg = el( 'a', 'csm-mb-primary', 'Message' );
			msg.href = CFG.messages;
			wrap.appendChild( msg );
			wrap.appendChild( el( 'p', 'csm-mb-note', 'You matched, so you can message each other.' ) );
			return wrap;
		}

		if ( 'pending' === d.friendship ) {
			wrap.appendChild( el( 'p', 'csm-mb-note', 'Your match request is waiting for a reply.' ) );
			return wrap;
		}

		if ( 'awaiting_response' === d.friendship ) {
			var go = el( 'a', 'csm-mb-primary', 'Answer in Requests' );
			go.href = '/requests/';
			wrap.appendChild( go );
			wrap.appendChild( el( 'p', 'csm-mb-note', 'They have asked to match with you.' ) );
			return wrap;
		}

		if ( 'saved' === d.tray ) {
			var back = el( 'a', 'csm-mb-primary', 'Decide in Saved' );
			back.href = '/requests/';
			wrap.appendChild( back );
			wrap.appendChild( el( 'p', 'csm-mb-note', 'You saved this profile to decide on later.' ) );
			return wrap;
		}

		if ( 'pending' === d.tray ) {
			var dis = el( 'a', 'csm-mb-primary', 'Open in Discover' );
			dis.href = '/discover/';
			wrap.appendChild( dis );
			return wrap;
		}

		// No relationship and not in the tray: nothing honest to offer. Saying so
		// beats a button that would fail the tray check on the server.
		wrap.appendChild( el( 'p', 'csm-mb-note', 'This profile is not in your current set.' ) );
		return wrap;
	}

	function draw( d ) {
		root.innerHTML = '';

		var back = el( 'a', 'csm-mb-back', '← Back' );
		back.href = CFG.back;
		root.appendChild( back );

		root.appendChild( window.csmProfileCard( d.profile ) );
		root.appendChild( actions( d ) );
	}

	fetch( CFG.get, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) {
			if ( d && d.ok ) { return draw( d ); }
			root.innerHTML = '<p class="csm-app-loading">' + ( ( d && d.message ) || 'Could not load that profile.' ) + '</p>';
		} )
		.catch( function () {
			root.innerHTML = '<p class="csm-app-loading">Could not load that profile.</p>';
		} );
}() );
