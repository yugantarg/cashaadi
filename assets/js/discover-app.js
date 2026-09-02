/**
 * Discover — one full profile at a time, scrollable, Pass/Like fixed at the foot.
 *
 * The whole tray is fetched once and advanced client-side, so moving to the next
 * person is instant and costs no page load. Actions are optimistic: the card
 * advances immediately and the write happens behind it, because making someone
 * wait on a network round trip to see the next profile is the thing that made
 * the old tray feel like a website rather than an app.
 */
( function () {
	'use strict';

	var CFG = window.CSM_DISCOVER;
	var root = document.getElementById( 'csm-discover-app' );
	if ( ! CFG || ! root ) { return; }

	var profiles = [];
	var idx = 0;

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

	var meta = {};

	/**
	 * The empty state.
	 *
	 * "You are all caught up" told the member nothing actionable. It now answers
	 * the two questions they actually have — when do more arrive, and can I get
	 * more now — using the same weekly reset the quota banner quotes.
	 */
	function empty( message ) {
		root.innerHTML = '';
		var box = el( 'div', 'csm-d-empty' );
		box.appendChild( el( 'h2', null, 'You have seen everyone for this week' ) );

		if ( message ) {
			box.appendChild( el( 'p', null, message ) );
		} else if ( meta.resetOn ) {
			var countdown = el( 'p', 'csm-d-countdown' );
			countdown.textContent = 'New profiles arrive ' + meta.resetOn;
			box.appendChild( countdown );

			if ( meta.resetIso ) {
				var left = el( 'p', 'csm-d-left' );
				var ms = new Date( meta.resetIso ) - new Date();
				if ( ms > 0 ) {
					var days = Math.floor( ms / 86400000 );
					var hrs  = Math.floor( ( ms % 86400000 ) / 3600000 );
					left.textContent = days > 0
						? ( days + ( 1 === days ? ' day ' : ' days ' ) + hrs + ( 1 === hrs ? ' hour' : ' hours' ) + ' to go' )
						: ( hrs + ( 1 === hrs ? ' hour' : ' hours' ) + ' to go' );
					box.appendChild( left );
				}
			}
		} else {
			box.appendChild( el( 'p', null, 'New profiles arrive every week.' ) );
		}

		// Premium raises the weekly quota, so this is the one place it is genuinely
		// useful rather than nagging. Never shown to members who already pay.
		if ( ! meta.isPremium && meta.upgrade && ! message ) {
			var up = el( 'div', 'csm-d-upsell' );
			up.appendChild( el( 'h3', null, 'Want to see more now?' ) );
			up.appendChild( el( 'p', null, 'Premium members get a larger weekly set of profiles.' ) );
			var a = document.createElement( 'a' );
			a.className = 'csm-d-upsell-cta';
			a.href = meta.upgrade;
			a.textContent = 'See Premium';
			up.appendChild( a );
			box.appendChild( up );
		}

		root.appendChild( box );
	}

	function draw() {
		var p = profiles[ idx ];
		if ( ! p ) { return empty(); }

		root.innerHTML = '';
		var card = el( 'article', 'csm-d-card' );

		/* photo + name overlay */
		var media = el( 'div', 'csm-d-media' );
		var img = el( 'img', 'csm-d-photo' );
		img.src = p.avatar;
		img.alt = '';
		img.loading = 'eager';
		media.appendChild( img );
		if ( p.isNew ) { media.appendChild( el( 'span', 'csm-d-new', 'NEW' ) ); }
		if ( p.verified ) { media.appendChild( el( 'span', 'csm-d-verified', 'Verified CA' ) ); }

		var over = el( 'div', 'csm-d-over' );
		over.appendChild( el( 'h1', 'csm-d-name', p.name + ( p.age ? ', ' + p.age : '' ) ) );
		var sub = [ p.job, p.city ].filter( Boolean ).join( ' · ' );
		if ( sub ) { over.appendChild( el( 'p', 'csm-d-sub', sub ) ); }
		media.appendChild( over );
		card.appendChild( media );

		if ( p.bio ) {
			var bio = el( 'section', 'csm-d-bio' );
			bio.appendChild( el( 'p', null, p.bio ) );
			card.appendChild( bio );
		}

		/* the full profile — this is the point of the screen */
		( p.groups || [] ).forEach( function ( g ) {
			var sec = el( 'section', 'csm-d-group' );
			sec.appendChild( el( 'h2', 'csm-d-group-h', g.name ) );
			var dl = el( 'dl', 'csm-d-fields' );
			g.fields.forEach( function ( f ) {
				dl.appendChild( el( 'dt', null, f.label ) );
				dl.appendChild( el( 'dd', null, f.value ) );
			} );
			sec.appendChild( dl );
			card.appendChild( sec );
		} );

		root.appendChild( card );

		/* actions, pinned above the bottom nav */
		var bar = el( 'div', 'csm-d-actions' );
		var pass = el( 'button', 'csm-d-btn csm-d-pass' );
		pass.type = 'button';
		pass.setAttribute( 'aria-label', 'Pass' );
		pass.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		var like = el( 'button', 'csm-d-btn csm-d-like' );
		like.type = 'button';
		like.setAttribute( 'aria-label', 'Like' );
		like.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>';

		pass.addEventListener( 'click', function () { act( p, 'pass' ); } );
		like.addEventListener( 'click', function () { act( p, 'like' ); } );
		bar.appendChild( pass );
		bar.appendChild( like );
		root.appendChild( bar );

		window.scrollTo( 0, 0 );
	}

	function act( p, what ) {
		// Advance first: the next profile should appear the instant they tap.
		idx++;
		draw();

		api( CFG.act, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { profile_id: p.id, action: what } )
		} ).then( function ( d ) {
			if ( d && d.isMutual ) { celebrate( p ); }
		} ).catch( function () {
			/* The write failed but the member has moved on. Re-showing the profile
			   would be more confusing than letting the weekly tray carry it over,
			   which it does: the row simply stays 'pending'. */
		} );
	}

	function celebrate( p ) {
		var t = el( 'div', 'csm-d-match' );
		t.appendChild( el( 'strong', null, 'It’s a match!' ) );
		t.appendChild( el( 'span', null, 'You and ' + p.name + ' liked each other.' ) );
		document.body.appendChild( t );
		setTimeout( function () { t.classList.add( 'is-out' ); }, 2600 );
		setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 3200 );
	}

	api( CFG.queue ).then( function ( d ) {
		if ( ! d || ! d.ok ) { return empty( 'We could not load profiles just now.' ); }
		profiles = d.profiles || [];
		meta = { isPremium: d.isPremium, resetOn: d.resetOn, resetIso: d.resetIso, upgrade: d.upgrade };
		idx = 0;
		draw();
	} ).catch( function () {
		empty( 'We could not load profiles just now.' );
	} );
} )();
