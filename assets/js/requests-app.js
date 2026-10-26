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
	var VALID_TABS = [ 'received', 'matches', 'sent', 'saved', 'viewers', 'declined' ];
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
			[ 'received', 'Received', undefined !== data.receivedTotal ? data.receivedTotal : ( data.received || [] ).length ],
			[ 'matches', 'Matches', undefined !== data.matchesTotal ? data.matchesTotal : ( data.matches || [] ).length ],
			[ 'sent', 'Sent', undefined !== data.sentTotal ? data.sentTotal : ( data.sent || [] ).length ],
			[ 'saved', 'Saved', undefined !== data.savedOpenTotal ? data.savedOpenTotal + ( data.saved || [] ).filter( function ( x ) { return x.masked; } ).length : ( data.saved || [] ).length ],
			[ 'viewers', 'Viewed me', data.viewersTotal || 0 ],
			[ 'declined', 'Declined', 0 ]
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

	/* The same filter button and sheet as Discover (app-screens.js, v1.89.13). */
	function receivedFilters() {
		if ( ! data.filters || ! window.csmFilterBar ) { return null; }
		return window.csmFilterBar( {
			state: data.filters,
			url: CFG.filters,
			nonce: CFG.nonce,
			upgrade: CFG.upgrade,
			src: 'filter-requests',
			onApplied: function () { load(); }
		} );
	}

	function draw() {
		root.innerHTML = '';
		root.appendChild( tabs() );

		var list = el( 'ul', 'csm-r-list' );

		if ( 'received' === tab ) {
			if ( ! ( data.received || [] ).length && ! data.hiddenByFilter ) {
				root.appendChild( emptyBox( 'No requests yet', 'When someone likes your profile, they will appear here.' ) );
				return;
			}
			// The server has already applied the member's filters.
			var fbar = receivedFilters();
			if ( fbar ) { root.appendChild( fbar ); }
			if ( data.hiddenByFilter ) {
				var hl = el( 'li', 'csm-r-sub', data.hiddenByFilter + ( 1 === data.hiddenByFilter ? ' request' : ' requests' ) + ' hidden by your filters \u00b7' );
				hl.appendChild( window.csmFilterBar.showAll( CFG.filters, CFG.nonce, function () { load(); } ) );
				list.appendChild( hl );
			}
			// Profile tiles, opening into the same card as Discover (v1.89.40).
			root.appendChild( list );
			root.appendChild( window.csmDeck.tiles( data.received, function ( i ) {
				deck( data.received, i, 'All requests' );
			} ) );
			moreButton( data.receivedTotal, data.received.length );
			return;
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
			// Tiles and the full card, like Received (v1.89.63).
			root.appendChild( list );
			root.appendChild( window.csmDeck.tiles( data.matches, function ( i ) { deck( data.matches, i, 'All matches' ); } ) );
			moreButton( data.matchesTotal, data.matches.length );
			return;
		} else if ( 'sent' === tab ) {
			if ( ! ( data.sent || [] ).length ) {
				root.appendChild( emptyBox( 'Nothing sent yet', 'Requests you send from Discover will show here until they are answered.' ) );
				return;
			}
			// Upgrade prompt (v1.89.0): Gold requests go to the top of their list.
			if ( CFG.goldPrompt ) {
				var gb = el( 'a', 'csm-r-goldprompt', 'Waiting for answers? Gold puts your requests at the top of their list.' );
				gb.href = window.csmSrc( CFG.upgrade, 'requests-gold' );
				root.appendChild( gb );
			}
			root.appendChild( list );
			root.appendChild( window.csmDeck.tiles( data.sent, function ( i ) { deck( data.sent, i, 'All sent' ); } ) );
			moreButton( data.sentTotal, data.sent.length );
			return;
		} else if ( 'saved' === tab ) {
			if ( ! ( data.saved || [] ).length ) {
				root.appendChild( emptyBox( 'Nothing saved yet', 'Use Save on a profile in Discover to keep it here and decide later.' ) );
				return;
			}
			/* Saved requests (Accept / Decline) and saved Discover profiles
			   (Send request / Pass) as tiles; the deck picks the labels from
			   each profile's kind. Locked older saves stay as rows below. */
			var open = data.saved.filter( function ( p ) { return ! p.masked; } );
			if ( open.length ) {
				root.appendChild( window.csmDeck.tiles( open, function ( i ) {
					deck( open, i, 'All saved' );
				} ) );
				moreButton( data.savedOpenTotal, open.length );
			}
			data.saved.forEach( function ( p ) {
				if ( ! p.masked ) { return; }
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
				a.href = window.csmSrc( '/membership-pricing/', 'requests-saves' );
				lock.appendChild( a );
				root.appendChild( list );   // tabs() was already appended above
				root.appendChild( lock );
				return;
			}
		} else if ( 'declined' === tab ) {
			/* Who I declined (2026-10-26): Premium and Gold, like Viewed me. */
			if ( ! ( data.declined || [] ).length ) {
				root.appendChild( emptyBox( 'No declined requests', 'Requests you decline are listed here.' ) );
				return;
			}
			if ( data.isPremium ) {
				root.appendChild( window.csmDeck.tiles( data.declined, function ( i ) { deck( data.declined, i, 'All declined' ); } ) );
				moreButton( data.declinedTotal, data.declined.length );
				return;
			}
			data.declined.forEach( function ( v ) {
				var row = el( 'li', 'csm-r-row csm-r-masked' );
				row.appendChild( el( 'span', 'csm-r-initial', v.initial ) );
				var meta = el( 'div', 'csm-r-meta' );
				meta.appendChild( el( 'span', 'csm-r-name', '\u2022\u2022\u2022\u2022\u2022\u2022' ) );
				if ( v.ago ) { meta.appendChild( el( 'span', 'csm-r-sub', v.ago ) ); }
				row.appendChild( meta );
				list.appendChild( row );
			} );
			root.appendChild( list );
			root.appendChild( upgradeBox( data.declinedTotal || 0, [ 'request you declined', 'requests you declined' ], 'Upgrade to Premium to see who you declined, and send them a request if you change your mind.', 'requests-declined' ) );
			return;
		} else {
			if ( ! ( data.viewers || [] ).length ) {
				root.appendChild( emptyBox( 'No visitors yet', 'Complete your profile and start matching to get noticed.' ) );
				return;
			}
			if ( data.isPremium ) {
				root.appendChild( window.csmDeck.tiles( data.viewers, function ( i ) { deck( data.viewers, i, 'All visitors' ); } ) );
				moreButton( data.viewersTotal, data.viewers.length );
				return;
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

	/*
	 * One profile at a time, with labelled buttons and previous/next
	 * (v1.89.40). A received request: Decline / Save for later / Accept. In
	 * Saved, the labels follow what the profile is waiting for: a request to
	 * answer (Decline / Accept) or a Discover profile (Pass / Send request).
	 */
	function deck( list, index, backLabel ) {
		var I = window.csmDeck.icons;
		window.csmDeck.open( root, {
			list: list,
			index: index,
			backLabel: backLabel,
			onBack: function () { draw(); window.scrollTo( 0, 0 ); },
			onChange: function () { load().then( function () { window.scrollTo( 0, 0 ); } ); },
			actions: function ( p ) {
				// Matches: message them. Sent: withdraw. Viewed me (Premium): send a request.
				if ( 'match' === p.kind ) {
					return [ { cls: 'csm-d-like', label: 'Send message', icon: I.chat, run: function ( q ) {
						if ( q.msgUrl ) { window.location.href = q.msgUrl; }
						return Promise.resolve( false ); // stays in the list
					} } ];
				}
				if ( 'sent' === p.kind ) {
					return [ { cls: 'csm-d-pass', label: 'Withdraw request', icon: I.pass, run: function ( q ) { return send( q, 'withdraw' ); } } ];
				}
				if ( 'viewer' === p.kind || 'declined' === p.kind ) {
					return [ { cls: 'csm-d-like', label: 'Send request', icon: I.like, run: function ( q ) { return send( q, 'like' ); } } ];
				}
				if ( 'request' === p.kind ) {
					var a = [ { cls: 'csm-d-pass', label: 'Decline request', icon: I.pass, run: function ( q ) { return send( q, 'reject' ); } } ];
					if ( 'saved' !== tab ) {
						a.push( { cls: 'csm-d-save', label: 'Save for later', icon: I.save, run: function ( q ) { return send( q, 'save_request' ); } } );
					}
					a.push( { cls: 'csm-d-like', label: 'Accept request', icon: I.like, run: function ( q ) { return send( q, 'accept' ); } } );
					return a;
				}
				return [
					{ cls: 'csm-d-pass', label: 'Pass', icon: I.pass, run: function ( q ) { return send( q, 'pass' ); } },
					{ cls: 'csm-d-like', label: 'Send request', icon: I.like, run: function ( q ) { return send( q, 'like' ); } }
				];
			}
		} );
	}

	/* Do it, then keep the lists and tab counts true without a refetch. */
	function send( p, action ) {
		return api( CFG.act, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { user_id: p.id, action: action } )
		} ).then( function ( d ) {
			if ( ! d || ! d.ok ) {
				if ( window.csmToast ) { window.csmToast( ( d && d.message ) || 'That did not work.', 'bad' ); }
				return false;
			}
			// The deck removes p from the list it shows; drop it everywhere else too,
			// and keep the totals (which count beyond the loaded page) true.
			data.saved = ( data.saved || [] ).filter( function ( x ) { return x.masked || x.id !== p.id; } );
			var totals = { received: 'receivedTotal', saved: 'savedOpenTotal', sent: 'sentTotal', matches: 'matchesTotal', viewers: 'viewersTotal', declined: 'declinedTotal' };
			if ( totals[ tab ] ) { data[ totals[ tab ] ] = Math.max( 0, ( data[ totals[ tab ] ] || 1 ) - 1 ); }
			if ( 'save_request' === action ) { data.savedOpenTotal = ( data.savedOpenTotal || 0 ) + 1; }
			if ( 'accept' === action ) { data.matchesTotal = ( data.matchesTotal || 0 ) + 1; }
			if ( 'accept' === action ) { ( data.matches = data.matches || [] ).push( p ); }
			if ( 'save_request' === action ) {
				data.saved.unshift( p );
				if ( window.csmToast ) { window.csmToast( 'Saved. Find it under Saved.' ); }
			}
			return true;
		} ).catch( function () {
			if ( window.csmToast ) { window.csmToast( 'Network problem. Please try again.', 'bad' ); }
			return false;
		} );
	}

	/* Viewed me by default; Declined passes its own words (2026-10-26). */
	function upgradeBox( n, words, line, src ) {
		if ( undefined === n ) { n = data.viewersTotal || 0; }
		words = words || [ 'member viewed your profile', 'members viewed your profile' ];
		var box = el( 'div', 'csm-r-upgrade' );
		box.appendChild( el( 'div', 'csm-r-upgrade-n', String( n ) ) );
		box.appendChild( el( 'h3', null, 1 === n ? words[0] : words[1] ) );
		box.appendChild( el( 'p', null, line || 'Upgrade to Premium to see exactly who visited you, and when.' ) );
		var a = document.createElement( 'a' );
		a.className = 'csm-r-upgrade-cta';
		a.href = window.csmSrc( CFG.upgrade, src || 'requests-viewers' );
		a.textContent = 'Upgrade to Premium';
		box.appendChild( a );
		return box;
	}

	/* 24 at a time (v1.89.62); "Show more" asks the server for the next 24. */
	var limit = 24;
	function moreButton( total, loaded ) {
		if ( ! total || total <= loaded ) { return; }
		var b = el( 'button', 'csm-d-more', 'Show more' );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			b.disabled = true;
			b.textContent = 'Loading\u2026';
			var y = window.scrollY;
			limit += 24;
			load().then( function () { window.scrollTo( 0, y ); } );
		} );
		root.appendChild( b );
	}

	function load() {
		return api( CFG.list + ( CFG.list.indexOf( '?' ) === -1 ? '?' : '&' ) + 'limit=' + limit ).then( function ( d ) {
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
	}
	load();
} )();
