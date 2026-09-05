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

	/* -------------------------------------------------------------- acting */

	function toast( text, strong ) {
		var t = el( 'div', 'csm-d-match' + ( strong ? '' : ' csm-d-toast' ) );
		if ( strong ) { t.appendChild( el( 'strong', null, strong ) ); }
		t.appendChild( el( 'span', null, text ) );
		document.body.appendChild( t );
		setTimeout( function () { t.classList.add( 'is-out' ); }, 2400 );
		setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 3000 );
	}

	function act( d, what, btn ) {
		var release = ( window.csmBusy && btn ) ? window.csmBusy( btn ) : function () {};
		fetch( CFG.act, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify( { profile_id: d.profile.id, action: what } )
		} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
			release();
			if ( res && res.isMutual ) {
				toast( 'You and ' + d.profile.name + ' liked each other.', 'It\u2019s a match!' );
			} else if ( 'save' === what ) {
				toast( 'Saved. Find them under Requests \u2192 Saved.' );
			}
			/* Unlike Discover there is no next card to advance to — this screen
			   IS the one profile. Go back to where they came from, after the
			   toast has had time to be read. */
			setTimeout( function () { window.location.href = CFG.back; }, res && res.isMutual ? 2600 : 1400 );
		} ).catch( function () {
			release();
			toast( 'That did not save. Please try again.' );
		} );
	}

	/**
	 * The action bar — the SAME one Discover has.
	 *
	 * A first version put a single "Decide in Saved" button here, which sent the
	 * member back to a list to do the thing they were already looking at the
	 * profile in order to do. Owner: "doesn't make sense. just show it like in
	 * discover." Same three controls, same markup and stylesheet, same
	 * first-use explainers; only the prev/next arrows are absent, because there
	 * is no deck to page through.
	 */
	function decide( d ) {
		var bar = el( 'div', 'csm-d-actions' );

		var pass = el( 'button', 'csm-d-btn csm-d-pass' );
		pass.type = 'button';
		pass.setAttribute( 'aria-label', 'Pass' );
		pass.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

		var save = el( 'button', 'csm-d-btn csm-d-save' );
		save.type = 'button';
		save.setAttribute( 'aria-label', 'Save for later' );
		save.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

		var like = el( 'button', 'csm-d-btn csm-d-like' );
		like.type = 'button';
		like.setAttribute( 'aria-label', 'Like' );
		like.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>';

		// Already saved: saving again would be a no-op the server rejects.
		if ( 'saved' === d.tray ) { save.disabled = true; }

		function guarded( name ) {
			return function () {
				var btn = this;
				if ( window.csmCoach ) {
					window.csmCoach.explain( name, function () { act( d, name, btn ); } );
				} else {
					act( d, name, btn );
				}
			};
		}
		pass.addEventListener( 'click', guarded( 'pass' ) );
		save.addEventListener( 'click', guarded( 'save' ) );
		like.addEventListener( 'click', guarded( 'like' ) );

		bar.appendChild( pass );
		bar.appendChild( save );
		bar.appendChild( like );
		return bar;
	}

	/**
	 * What to offer, from the relationship.
	 *
	 * Only a profile still awaiting a decision gets the Discover bar. Once
	 * matched, or once a request is in flight, there is nothing left to decide
	 * and a Pass/Like pair would be offering to undo something this screen
	 * cannot undo.
	 */
	function actions( d ) {
		if ( 'is_friend' === d.friendship ) {
			var wrap = el( 'div', 'csm-mb-actions' );
			var msg = el( 'a', 'csm-mb-primary', 'Message' );
			msg.href = CFG.messages;
			wrap.appendChild( msg );
			wrap.appendChild( el( 'p', 'csm-mb-note', 'You matched, so you can message each other.' ) );
			return wrap;
		}

		if ( 'pending' === d.friendship ) {
			var w2 = el( 'div', 'csm-mb-actions' );
			w2.appendChild( el( 'p', 'csm-mb-note', 'You liked this profile. Waiting for them to reply.' ) );
			return w2;
		}

		if ( 'awaiting_response' === d.friendship ) {
			var w3 = el( 'div', 'csm-mb-actions' );
			var go = el( 'a', 'csm-mb-primary', 'Answer in Requests' );
			go.href = '/requests/';
			w3.appendChild( go );
			w3.appendChild( el( 'p', 'csm-mb-note', 'They have asked to match with you.' ) );
			return w3;
		}

		if ( 'saved' === d.tray || 'pending' === d.tray ) {
			return decide( d );
		}

		// Not in the tray and no relationship: nothing honest to offer — the
		// server's tray check would reject an action from here anyway.
		var w4 = el( 'div', 'csm-mb-actions' );
		w4.appendChild( el( 'p', 'csm-mb-note', 'This profile is not in your current set.' ) );
		return w4;
	}

	function draw( d ) {
		root.innerHTML = '';
		document.body.classList.add( 'csm-member-view' );

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
