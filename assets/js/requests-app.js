/**
 * Requests — received, sent and profile viewers as three tabs on one screen.
 *
 * The viewer list for a free member arrives already masked: initials and a
 * relative time, no ids and no names. There is deliberately nothing to un-blur
 * here, because a CSS blur over real data is not a paywall.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_REQUESTS;
	var root = document.getElementById( 'csm-requests-app' );
	if ( ! CFG || ! root ) { return; }

	var data = null;
	/*
	 * Which tab to open. The hamburger's "Matches" entry pointed at
	 * /members/<user>/matches/, a BuddyPress slug that does not exist — the
	 * component registers "friends" — so it 404'd, and 404-to-301 turned that
	 * into a bounce to the home page. It now points here, and a hash says which
	 * tab it meant.
	 */
	var VALID_TABS = [ 'received', 'matches', 'sent', 'saved', 'viewers' ];
	var tab = ( function () {
		var h = ( window.location.hash || '' ).replace( /^#\/?/, '' );
		return VALID_TABS.indexOf( h ) !== -1 ? h : 'received';
	} )();

	function api( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = CFG.nonce;
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function tabs() {
		var wrap = el( 'div', 'csm-r-tabs' );
		[
			[ 'received', 'Received', ( data.received || [] ).length ],
			[ 'matches', 'Matches', ( data.matches || [] ).length ],
			[ 'sent', 'Sent', ( data.sent || [] ).length ],
			[ 'saved', 'Saved', ( data.saved || [] ).length ],
			[ 'viewers', 'Viewed me', data.viewersTotal || 0 ]
		].forEach( function ( t ) {
			var b = el( 'button', 'csm-r-tab' + ( tab === t[0] ? ' is-on' : '' ) );
			b.type = 'button';
			b.appendChild( document.createTextNode( t[1] ) );
			if ( t[2] ) { b.appendChild( el( 'span', 'csm-r-badge', String( t[2] ) ) ); }
			b.addEventListener( 'click', function () { tab = t[0]; draw(); } );
			wrap.appendChild( b );
		} );
		return wrap;
	}

	function personRow( p, actions ) {
		var row = el( 'li', 'csm-r-row' );

		var a = document.createElement( 'a' );
		a.className = 'csm-r-person';
		a.href = p.url || '#';
		var img = el( 'img', 'csm-r-avatar' );
		img.src = p.avatar; img.alt = '';
		a.appendChild( img );
		var meta = el( 'div', 'csm-r-meta' );
		meta.appendChild( el( 'span', 'csm-r-name', p.name + ( p.age ? ', ' + p.age : '' ) ) );
		var sub = [ p.city, p.ago ].filter( Boolean ).join( ' · ' );
		if ( sub ) { meta.appendChild( el( 'span', 'csm-r-sub', sub ) ); }
		a.appendChild( meta );
		row.appendChild( a );
		// Gold sender: a priority request (v1.87.0).
		if ( p.gold ) {
			row.classList.add( 'is-gold' );
			meta.insertBefore( el( 'span', 'csm-r-gold', 'Gold \u00b7 Priority' ), meta.firstChild );
		}
		// The note that came with the request (v1.85.0).
		if ( p.note ) { row.appendChild( el( 'p', 'csm-r-note', '\u201c' + p.note + '\u201d' ) ); }

		if ( actions && actions.length ) {
			var bar = el( 'div', 'csm-r-actions' );
			actions.forEach( function ( act ) {
				var b = el( 'button', 'csm-r-btn csm-r-' + act.kind, act.label );
				b.type = 'button';
				b.addEventListener( 'click', function () { doAct( p, act.action, row, b ); } );
				bar.appendChild( b );
			} );
			row.appendChild( bar );
		}
		return row;
	}

	/** Drop a person from every list they could be in. */
	function forget( id ) {
		[ 'received', 'sent', 'saved' ].forEach( function ( key ) {
			if ( ! data[ key ] ) { return; }
			data[ key ] = data[ key ].filter( function ( x ) { return x.id !== id; } );
		} );
	}

	/**
	 * Redraw only the tab bar.
	 *
	 * Not draw(): a full redraw would rebuild the list and throw away the
	 * reader's scroll position, which is the thing the local-removal above
	 * exists to protect.
	 */
	function refreshTabs() {
		var old = root.querySelector( '.csm-r-tabs' );
		if ( old && old.parentNode ) { old.parentNode.replaceChild( tabs(), old ); }
	}

	function doAct( p, action, row, button ) {
		/*
		 * "Message" is navigation, not a state change — there is nothing to POST.
		 * The URL comes from the person, not from config: BuddyPress messages live
		 * at /members/<username>/messages/, so a site-wide /messages/ 404s, and
		 * with 404-to-301 that 404 becomes a bounce to the home page — which is
		 * why this button appeared to do nothing.
		 */
		if ( 'message' === action ) {
			if ( p.msgUrl ) { window.location.href = p.msgUrl; }
			return;
		}

		/* Was disabled-only: the button greyed out and then nothing visibly
		   happened until the row vanished. A spinner says the tap registered. */
		var release = window.csmBusy ? window.csmBusy( button ) : function () { button.disabled = false; };
		button.disabled = true;
		api( CFG.act, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { user_id: p.id, action: action } )
		} ).then( function ( d ) {
			if ( d && d.ok ) {
				// Remove locally rather than refetching: the row is gone either way,
				// and a full reload would lose the reader's place in the list.
				row.classList.add( 'is-gone' );
				setTimeout( function () { if ( row.parentNode ) { row.parentNode.removeChild( row ); } }, 220 );

				/*
				 * The DATA has to lose them too, not just the DOM. The tab labels
				 * count data.received.length, so removing only the row left
				 * "Received (8)" above a list of seven — the count appeared to lag
				 * until a reload, which is what it looked like from outside.
				 *
				 * Accepting also makes them a match, so they move rather than
				 * disappear: dropping them from the source list and adding them to
				 * matches keeps both counts honest without a refetch.
				 */
				forget( p.id );
				if ( 'accept' === action ) {
					( data.matches = data.matches || [] ).push( p );
				}
				refreshTabs();
				return;
			}
			release();
			alertRow( row, ( d && d.message ) || 'That did not work.' );
		} ).catch( function () {
			release();
			alertRow( row, 'Network problem. Please try again.' );
		} );
	}

	function alertRow( row, msg ) {
		var existing = row.querySelector( '.csm-r-err' );
		if ( existing ) { existing.textContent = msg; return; }
		row.appendChild( el( 'p', 'csm-r-err', msg ) );
	}

	function emptyBox( title, note ) {
		var box = el( 'div', 'csm-r-empty' );
		box.appendChild( el( 'h2', null, title ) );
		if ( note ) { box.appendChild( el( 'p', null, note ) ); }
		return box;
	}

	var rfilt = {};   // key -> chosen value ('' = any)

	function passes( p ) {
		var a = p.attrs || {};
		return Object.keys( rfilt ).every( function ( k ) {
			var want = rfilt[ k ];
			if ( ! want ) { return true; }
			if ( 'same' === want ) {
				var dim = ( data.filterKeys || [] ).filter( function ( x ) { return x.key === k; } )[0];
				return !! dim && ( a[ k ] || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim() === dim.mine;
			}
			if ( 'age' === k ) {
				var r = want.split( '-' );
				return a.age && a.age >= +r[0] && a.age <= +r[1];
			}
			return Array.isArray( a[ k ] ) ? a[ k ].indexOf( want ) !== -1 : a[ k ] === want;
		} );
	}

	function receivedFilters() {
		var keys = data.filterKeys || [];
		if ( ! keys.length || ( data.received || [] ).length < 2 ) { return null; }
		var bar = el( 'div', 'csm-r-filters' );
		if ( ! data.isPremium ) {
			var lock = el( 'a', 'csm-r-filterlock', '\uD83D\uDD12 Filter requests by income, community, age\u2026 with Premium' );
			lock.href = CFG.upgrade;
			bar.appendChild( lock );
			return bar;
		}
		var dims = keys.concat( [ { key: 'age', label: 'Age' } ] );
		dims.forEach( function ( d ) {
			if ( d.same ) {
				var lab = el( 'label', 'csm-r-filter csm-r-same' );
				var cb = document.createElement( 'input' );
				cb.type = 'checkbox';
				cb.checked = 'same' === rfilt[ d.key ];
				cb.addEventListener( 'change', function () { rfilt[ d.key ] = cb.checked ? 'same' : ''; draw(); } );
				lab.appendChild( cb );
				lab.appendChild( document.createTextNode( ' Same ' + d.label.toLowerCase() + ' as mine' ) );
				bar.appendChild( lab );
				return;
			}
			var vals = {};
			data.received.forEach( function ( p ) {
				var v = ( p.attrs || {} )[ d.key ];
				if ( 'age' === d.key && v ) { var lo = Math.floor( ( v - 1 ) / 3 ) * 3 + 1; v = lo + '-' + ( lo + 2 ); }
				( Array.isArray( v ) ? v : ( v ? [ v ] : [] ) ).forEach( function ( x ) { vals[ x ] = ( vals[ x ] || 0 ) + 1; } );
			} );
			var names = Object.keys( vals ).sort();
			if ( names.length < 2 && ! rfilt[ d.key ] ) { return; }
			var sel = document.createElement( 'select' );
			sel.className = 'csm-r-filter';
			var any = document.createElement( 'option' );
			any.value = ''; any.textContent = d.label + ': any';
			sel.appendChild( any );
			names.forEach( function ( v ) {
				var o = document.createElement( 'option' );
				o.value = v; o.textContent = v + ' (' + vals[ v ] + ')';
				if ( rfilt[ d.key ] === v ) { o.selected = true; }
				sel.appendChild( o );
			} );
			sel.addEventListener( 'change', function () { rfilt[ d.key ] = sel.value; draw(); } );
			bar.appendChild( sel );
		} );
		return bar.childNodes.length ? bar : null;
	}

	function draw() {
		root.innerHTML = '';
		root.appendChild( tabs() );

		var list = el( 'ul', 'csm-r-list' );

		if ( 'received' === tab ) {
			if ( ! ( data.received || [] ).length ) {
				root.appendChild( emptyBox( 'No requests yet', 'When someone likes your profile, they will appear here.' ) );
				return;
			}
			/* Filters for the received list (v1.85.0): Premium picks values that
			   appear among the senders; free members see the control locked. */
			var fbar = receivedFilters();
			if ( fbar ) { root.appendChild( fbar ); }
			var shown = data.received.filter( passes );
			if ( ! shown.length ) {
				list.appendChild( el( 'li', 'csm-r-sub', 'No requests match these filters.' ) );
			}
			shown.forEach( function ( p ) {
				list.appendChild( personRow( p, [
					{ kind: 'accept', label: 'Accept', action: 'accept' },
					{ kind: 'decline', label: 'Decline', action: 'reject' }
				] ) );
			} );
		} else if ( 'matches' === tab ) {
			if ( ! ( data.matches || [] ).length ) {
				root.appendChild( emptyBox( 'No matches yet', 'When you accept a request, or someone accepts yours, they will appear here.' ) );
				return;
			}
			/*
			 * No Accept/Decline here — that is already settled. The row itself
			 * opens their profile, which is the thing that had no route at all:
			 * once a request was accepted the person left Received, was never in
			 * Sent, and the only trace of them was a conversation thread.
			 */
			data.matches.forEach( function ( p ) {
				list.appendChild( personRow( p, [
					{ kind: 'accept', label: 'Message', action: 'message' }
				] ) );
			} );
		} else if ( 'sent' === tab ) {
			if ( ! ( data.sent || [] ).length ) {
				root.appendChild( emptyBox( 'Nothing sent yet', 'Requests you send from Discover will show here until they are answered.' ) );
				return;
			}
			data.sent.forEach( function ( p ) {
				list.appendChild( personRow( p, [
					{ kind: 'withdraw', label: 'Withdraw', action: 'withdraw' }
				] ) );
			} );
		} else if ( 'saved' === tab ) {
			if ( ! ( data.saved || [] ).length ) {
				root.appendChild( emptyBox( 'Nothing saved yet', 'Use Save on a profile in Discover to keep it here and decide later.' ) );
				return;
			}
			/* Decide from here. The profile is still a live tray row, so Like and
			   Pass go through the same endpoint they would in Discover. */
			data.saved.forEach( function ( p ) {
				/* Locked saves render as blurred placeholders, not as people. The
				   server sends no id, name or avatar for these, so there is nothing
				   here to un-blur — the lock is real, not styling. */
				if ( p.masked ) {
					var row = el( 'li', 'csm-r-row csm-r-masked csm-r-locked' );
					row.appendChild( el( 'span', 'csm-r-initial', p.initial ) );
					var meta = el( 'div', 'csm-r-meta' );
					meta.appendChild( el( 'span', 'csm-r-name', '••••••' ) );
					if ( p.ago ) { meta.appendChild( el( 'span', 'csm-r-sub', p.ago ) ); }
					row.appendChild( meta );
					row.appendChild( el( 'span', 'csm-r-lock', '🔒' ) );
					list.appendChild( row );
					return;
				}
				list.appendChild( personRow( p, [
					{ kind: 'accept', label: 'Like', action: 'like' },
					{ kind: 'decline', label: 'Pass', action: 'pass' }
				] ) );
			} );
			/* Free members keep this week's saves; older ones are held behind
			   Premium. The count is real — the identities are never sent — so this
			   says how many without showing who. */
			if ( ! data.isPremium && ( data.savedLocked || 0 ) > 0 ) {
				var lock = el( 'div', 'csm-r-upsell' );
				lock.appendChild( el( 'p', null,
					data.savedLocked + ( 1 === data.savedLocked ? ' save from an earlier week is locked.' : ' saves from earlier weeks are locked.' )
					+ ' Premium keeps every profile you save.' ) );
				var a = el( 'a', 'csm-r-upsell-btn', 'Keep saves with Premium' );
				a.href = '/membership-pricing/';
				lock.appendChild( a );
				root.appendChild( list );   // tabs() was already appended above
				root.appendChild( lock );
				return;
			}
		} else {
			if ( ! ( data.viewers || [] ).length ) {
				root.appendChild( emptyBox( 'No visitors yet', 'Complete your profile and start matching to get noticed.' ) );
				return;
			}
			if ( data.isPremium ) {
				data.viewers.forEach( function ( p ) { list.appendChild( personRow( p, null ) ); } );
			} else {
				data.viewers.forEach( function ( v ) {
					var row = el( 'li', 'csm-r-row csm-r-masked' );
					row.appendChild( el( 'span', 'csm-r-initial', v.initial ) );
					var meta = el( 'div', 'csm-r-meta' );
					meta.appendChild( el( 'span', 'csm-r-name', '••••••' ) );
					if ( v.ago ) { meta.appendChild( el( 'span', 'csm-r-sub', v.ago ) ); }
					row.appendChild( meta );
					list.appendChild( row );
				} );
				root.appendChild( list );
				root.appendChild( upgradeBox() );
				return;
			}
		}

		root.appendChild( list );
	}

	function upgradeBox() {
		var n = data.viewersTotal || 0;
		var box = el( 'div', 'csm-r-upgrade' );
		box.appendChild( el( 'div', 'csm-r-upgrade-n', String( n ) ) );
		box.appendChild( el( 'h3', null, ( 1 === n ? 'member viewed your profile' : 'members viewed your profile' ) ) );
		box.appendChild( el( 'p', null, 'Upgrade to Premium to see exactly who visited you, and when.' ) );
		var a = document.createElement( 'a' );
		a.className = 'csm-r-upgrade-cta';
		a.href = CFG.upgrade;
		a.textContent = 'Upgrade to Premium';
		box.appendChild( a );
		return box;
	}

	api( CFG.list ).then( function ( d ) {
		if ( ! d || ! d.ok ) {
			root.innerHTML = '';
			root.appendChild( emptyBox( 'Something went wrong', 'We could not load your requests. Please refresh.' ) );
			return;
		}
		data = d;
		draw();
	} ).catch( function () {
		root.innerHTML = '';
		root.appendChild( emptyBox( 'Something went wrong', 'We could not load your requests. Please refresh.' ) );
	} );
} )();
