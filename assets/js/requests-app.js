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
	var tab  = 'received';

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

	function doAct( p, action, row, button ) {
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
				return;
			}
			button.disabled = false;
			alertRow( row, ( d && d.message ) || 'That did not work.' );
		} ).catch( function () {
			button.disabled = false;
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

	function draw() {
		root.innerHTML = '';
		root.appendChild( tabs() );

		var list = el( 'ul', 'csm-r-list' );

		if ( 'received' === tab ) {
			if ( ! ( data.received || [] ).length ) {
				root.appendChild( emptyBox( 'No requests yet', 'When someone likes your profile, they will appear here.' ) );
				return;
			}
			data.received.forEach( function ( p ) {
				list.appendChild( personRow( p, [
					{ kind: 'accept', label: 'Accept', action: 'accept' },
					{ kind: 'decline', label: 'Decline', action: 'reject' }
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
