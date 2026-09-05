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
	var acted = {};   // profile ids already liked/passed this session
	var viewed = {};  // profile ids already reported as viewed, so nav doesn't re-post

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
		box.appendChild( el( 'h2', null, 'That\u2019s your ' + ( meta.freeQuota || 5 ) + ' for this week' ) );

		if ( ! message && ! meta.isPremium ) {
			box.appendChild( el( 'p', null,
				'You\u2019ve seen all ' + ( meta.freeQuota || 5 ) + ' profiles in your free weekly set. Premium members get ' + ( meta.premiumQuota || 10 ) + '.' ) );
		}

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
			up.appendChild( el( 'p', null,
				'Premium doubles your weekly set to ' + ( meta.premiumQuota || 10 ) + ' profiles.' ) );
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

		/* photo stack + name overlay — the stack itself lives in app-screens.js,
		   because the preview screen renders this same card and must not drift. */
		var media = el( 'div', 'csm-d-media' );
		var shots = ( p.photos && p.photos.length ) ? p.photos : [ p.avatar ];
		var img = el( 'img', 'csm-d-photo' );
		img.src = shots[ 0 ];
		img.alt = '';
		img.loading = 'eager';
		media.appendChild( img );
		window.csmPhotoStack( media, shots );

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

		/* Save for later — a third outcome, not a third decision. Deliberately
		   between the arrows and the decisions in weight: larger than navigation,
		   quieter than Like/Pass, because parking a profile should not feel like
		   choosing one. */
		var save = el( 'button', 'csm-d-btn csm-d-save' );
		save.type = 'button';
		save.setAttribute( 'aria-label', 'Save for later' );
		save.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

		/* Explain each action the first time it is used, then carry it out. The
		   explainer runs BEFORE the action, which is the whole point for Pass:
		   telling someone it was irreversible after the fact is no use. Once
		   seen, csmCoach.explain() calls straight through. */
		function guarded( name ) {
			return function () {
				var btn = this;
				if ( window.csmCoach ) {
					window.csmCoach.explain( name, function () { act( p, name, btn ); } );
				} else {
					act( p, name, btn );
				}
			};
		}
		pass.addEventListener( 'click', guarded( 'pass' ) );
		like.addEventListener( 'click', guarded( 'like' ) );
		save.addEventListener( 'click', guarded( 'save' ) );

		/* Move through the tray WITHOUT deciding. The owner's rule: a member
		   should be able to see all 5 before acting on any of them. Skipping is
		   not a pass — nothing is written to the tray, and the profile is still
		   there on the next visit. */
		var prev = el( 'button', 'csm-d-btn csm-d-nav csm-d-prev' );
		prev.type = 'button';
		prev.setAttribute( 'aria-label', 'Previous profile' );
		prev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>';
		var next = el( 'button', 'csm-d-btn csm-d-nav csm-d-next' );
		next.type = 'button';
		next.setAttribute( 'aria-label', 'Next profile' );
		next.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

		prev.disabled = ( step( -1 ) < 0 );
		next.disabled = ( step( 1 ) < 0 );
		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () { go( 1 ); } );

		bar.appendChild( prev );
		bar.appendChild( pass );
		bar.appendChild( save );
		bar.appendChild( like );
		bar.appendChild( next );
		root.appendChild( bar );

		window.scrollTo( 0, 0 );
		report( p );
	}

	/* Index of the next un-acted profile in $dir, or -1 if there is none. */
	function step( dir ) {
		var i = idx + dir;
		while ( i >= 0 && i < profiles.length ) {
			if ( ! acted[ profiles[ i ].id ] ) { return i; }
			i += dir;
		}
		return -1;
	}

	function go( dir ) {
		var i = step( dir );
		if ( i < 0 ) { return; }
		idx = i;
		draw();
	}

	/*
	 * Tell the server this profile was actually put in front of the member —
	 * which is what a "view" means here. Fired on draw, not on tray fill, because
	 * filling happens server-side for members who may never open Discover.
	 *
	 * Once per profile per page load: paging back and forth must not inflate the
	 * count. Failure is silent — a missed view is not worth interrupting anyone.
	 */
	function report( p ) {
		if ( ! CFG.view || ! p || viewed[ p.id ] ) { return; }
		viewed[ p.id ] = true;
		api( CFG.view, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { profile_id: p.id } )
		} ).catch( function () {} );
	}

	function act( p, what, btn ) {
		/* Busy state on the tapped control. The card advances optimistically, so
		   this mostly matters on a slow connection where the old card lingers. */
		var release = ( window.csmBusy && btn ) ? window.csmBusy( btn ) : function () {};
		// Advance first: the next profile should appear the instant they tap.
		// A saved profile leaves the deck too — it moves to the Saved list, and
		// leaving it in place would mean deciding it again on every visit.
		acted[ p.id ] = true;
		var i = step( 1 );
		if ( i < 0 ) { i = step( -1 ); }   // acted on the last one — fall back
		idx = ( i < 0 ) ? profiles.length : i;
		draw();

		api( CFG.act, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { profile_id: p.id, action: what } )
		} ).then( function ( d ) {
			release();
			if ( d && d.isMutual ) { celebrate( p ); }
			if ( 'save' === what ) { toast( 'Saved. Find them under Requests → Saved.' ); }
		} ).catch( function () {
			release();
			/* The write failed but the member has moved on. Re-showing the profile
			   would be more confusing than letting the weekly tray carry it over,
			   which it does: the row simply stays 'pending'. */
		} );
	}

	/** A brief, non-blocking confirmation. Reuses the match toast's styling. */
	function toast( text ) {
		var t = el( 'div', 'csm-d-match csm-d-toast' );
		t.appendChild( el( 'span', null, text ) );
		document.body.appendChild( t );
		setTimeout( function () { t.classList.add( 'is-out' ); }, 2200 );
		setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 2800 );
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

		/* The new-account tour, after the first card is on screen so the member
		   can see what is being described. Runs once ever; the component itself
		   checks and records that. */
		if ( window.csmCoach ) { window.csmCoach.tour(); }
	} ).catch( function () {
		empty( 'We could not load profiles just now.' );
	} );
} )();
