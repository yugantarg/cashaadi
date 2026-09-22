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
	// 'grid' for a large weekly set, 'single' for the one-card carousel.
	var view = 'single';
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
		box.appendChild( el( 'h2', null, 'That\u2019s your ' + ( meta.quota || meta.freeQuota || 5 ) + ' for this week' ) );

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

		if ( meta.filters && meta.filters.eligible ) {
			var fb2 = filterBar();
			if ( fb2 ) {
				if ( meta.filters.filters && meta.filters.filters.age_min ) {
					box.appendChild( el( 'p', 'csm-d-fhint', 'Your filters are narrowing this. Widen them to see more.' ) );
				}
				box.appendChild( fb2 );
			}
		}

		root.appendChild( box );
	}

	/* ---------------------------------------------------------- filters ----
	 *
	 * Premium women choose an age and height range (owner, 2026-09-22). The
	 * spans are floored server-side; the sheet enforces the same minimums live
	 * so the member is never told "no" after the fact. Saving re-aims the
	 * unacted part of the tray immediately — the weekly grant is counted
	 * server-side, so this cannot mint extra profiles.
	 */
	function filterBar() {
		var f = meta.filters;
		if ( ! f || ! f.eligible ) { return null; }
		var wrap = el( 'div', 'csm-d-filterbar' );
		var cur  = f.filters || {};
		var btn  = el( 'button', 'csm-d-filterbtn' );
		btn.type = 'button';
		var on = ( cur.age_min || cur.in_min );
		btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5h18M7 12h10M10 19h4"/></svg>';
		btn.appendChild( document.createTextNode( on ? summary( cur ) : 'Filter' ) );
		if ( on ) { btn.classList.add( 'is-on' ); }
		btn.addEventListener( 'click', sheet );
		wrap.appendChild( btn );
		return wrap;
	}

	function ftIn( inches ) {
		return Math.floor( inches / 12 ) + '\u2032' + ( inches % 12 ) + '\u2033';
	}

	function summary( c ) {
		var bits = [];
		if ( c.age_min ) { bits.push( c.age_min + '\u2013' + c.age_max + ' yrs' ); }
		if ( c.in_min ) { bits.push( ftIn( c.in_min ) + '\u2013' + ftIn( c.in_max ) ); }
		return bits.join( ' \u00b7 ' );
	}

	function sheet() {
		var f = meta.filters, b = f.bounds, cur = f.filters || {};
		var back = el( 'div', 'csm-d-sheetback' );
		var box  = el( 'div', 'csm-d-sheet' );
		box.appendChild( el( 'h2', null, 'Filter profiles' ) );
		box.appendChild( el( 'p', 'csm-d-sheethint',
			'You get ' + f.quota + ' profiles a week.' ) );

		function num( label, name, min, max, val, fmt ) {
			var row = el( 'label', 'csm-d-frow' );
			row.appendChild( el( 'span', 'csm-d-flabel', label ) );
			var sel = document.createElement( 'select' );
			sel.name = name;
			var blank = document.createElement( 'option' );
			blank.value = ''; blank.textContent = 'Any';
			sel.appendChild( blank );
			for ( var i = min; i <= max; i++ ) {
				var o = document.createElement( 'option' );
				o.value = i;
				o.textContent = fmt ? fmt( i ) : i;
				if ( val && i === val ) { o.selected = true; }
				sel.appendChild( o );
			}
			row.appendChild( sel );
			return { row: row, sel: sel };
		}

		var aMin = num( 'Age from', 'age_min', b.ageMin, b.ageMax, cur.age_min );
		var aMax = num( 'Age to', 'age_max', b.ageMin, b.ageMax, cur.age_max );
		var hMin = num( 'Height from', 'in_min', b.inMin, b.inMax, cur.in_min, ftIn );
		var hMax = num( 'Height to', 'in_max', b.inMin, b.inMax, cur.in_max, ftIn );
		[ aMin, aMax, hMin, hMax ].forEach( function ( n ) { box.appendChild( n.row ); } );

		var err = el( 'p', 'csm-d-ferr' );
		box.appendChild( err );

		/* The same rule the server applies, checked as they choose. */
		function validate() {
			var msg = '';
			var a1 = parseInt( aMin.sel.value, 10 ), a2 = parseInt( aMax.sel.value, 10 );
			var h1 = parseInt( hMin.sel.value, 10 ), h2 = parseInt( hMax.sel.value, 10 );
			if ( ( a1 && ! a2 ) || ( a2 && ! a1 ) ) { msg = 'Choose both ends of the age range, or leave both on Any.'; }
			else if ( a1 && a2 && ( a2 - a1 ) < b.ageSpan ) { msg = 'The age range must cover at least ' + b.ageSpan + ' years.'; }
			else if ( ( h1 && ! h2 ) || ( h2 && ! h1 ) ) { msg = 'Choose both ends of the height range, or leave both on Any.'; }
			else if ( h1 && h2 && ( h2 - h1 ) < b.inSpan ) { msg = 'The height range must cover at least ' + b.inSpan + ' inches.'; }
			err.textContent = msg;
			save.disabled = !! msg;
			return ! msg;
		}

		var actions = el( 'div', 'csm-d-sheetact' );
		var clear = el( 'button', 'csm-d-fclear', 'Clear' );
		clear.type = 'button';
		var save = el( 'button', 'csm-d-fsave', 'Apply' );
		save.type = 'button';
		actions.appendChild( clear );
		actions.appendChild( save );
		box.appendChild( actions );

		function close() { if ( back.parentNode ) { back.parentNode.removeChild( back ); } }

		function send( payload ) {
			save.disabled = true;
			save.textContent = 'Applying\u2026';
			api( CFG.filters, { method: 'POST', body: JSON.stringify( payload ) } ).then( function ( d ) {
				if ( ! d || ! d.ok ) {
					err.textContent = ( d && d.error ) || 'That did not save. Try again.';
					save.disabled = false; save.textContent = 'Apply';
					return;
				}
				meta.filters = d;
				close();
				load();
			} );
		}

		[ aMin, aMax, hMin, hMax ].forEach( function ( n ) {
			n.sel.addEventListener( 'change', validate );
		} );
		clear.addEventListener( 'click', function () { send( {} ); } );
		save.addEventListener( 'click', function () {
			if ( ! validate() ) { return; }
			send( {
				age_min: aMin.sel.value, age_max: aMax.sel.value,
				in_min: hMin.sel.value, in_max: hMax.sel.value
			} );
		} );
		back.addEventListener( 'click', function ( e ) { if ( e.target === back ) { close(); } } );

		back.appendChild( box );
		document.body.appendChild( back );
		validate();
	}

	/* ------------------------------------------------------------ grid ----
	 *
	 * A 50-profile week cannot be surveyed one card at a time. Above the
	 * server's grid threshold the screen opens on a scrollable grid of plain
	 * cards — photo, name, age, height — and the full card becomes the detail
	 * view, reached by tapping one and still navigated with Previous/Next.
	 *
	 * Deliberately not a third renderer for the profile itself: tapping a tile
	 * hands the SAME index to the same draw() the carousel has always used, so
	 * the two views cannot drift apart.
	 */
	function gridCard( p, i ) {
		var cell = el( 'button', 'csm-d-tile' );
		cell.type = 'button';

		var ph = document.createElement( 'div' );
		ph.className = 'csm-d-tilephoto';
		if ( p.avatar ) {
			var img = document.createElement( 'img' );
			img.src = p.avatar;
			img.alt = '';
			img.loading = i < 4 ? 'eager' : 'lazy';
			ph.appendChild( img );
		}
		if ( p.isNew ) { ph.appendChild( el( 'span', 'csm-d-tilenew', 'New' ) ); }
		cell.appendChild( ph );

		var meta2 = [];
		if ( p.age ) { meta2.push( p.age ); }
		if ( p.height ) { meta2.push( p.height ); }
		cell.appendChild( el( 'span', 'csm-d-tilename', p.name || 'Member' ) );
		cell.appendChild( el( 'span', 'csm-d-tilemeta', meta2.join( '  \u00b7  ' ) ) );

		cell.addEventListener( 'click', function () {
			idx = i;
			view = 'single';
			draw();
			window.scrollTo( 0, 0 );
		} );
		return cell;
	}

	function drawGrid() {
		root.innerHTML = '';
		var fb = filterBar();
		if ( fb ) { root.appendChild( fb ); }

		/* Profiles decided in the detail view are gone from here when the member
		   comes back — act() marks them rather than splicing, so the carousel's
		   indexes stay valid; the grid filters on the same map. */
		var left = profiles.filter( function ( p ) { return ! acted[ p.id ]; } );
		if ( ! left.length ) { return empty(); }

		var head = el( 'p', 'csm-d-gridcount',
			left.length + ( 1 === left.length ? ' profile' : ' profiles' ) + ' waiting for you' );
		root.appendChild( head );

		var grid = el( 'div', 'csm-d-grid' );
		profiles.forEach( function ( p, i ) {
			if ( acted[ p.id ] ) { return; }
			grid.appendChild( gridCard( p, i ) );
		} );
		root.appendChild( grid );
	}

	function draw() {
		if ( 'grid' === view ) { return drawGrid(); }
		var p = profiles[ idx ];
		if ( ! p ) { return empty(); }

		root.innerHTML = '';
		if ( meta.grid ) {
			var back = el( 'button', 'csm-d-back' );
			back.type = 'button';
			back.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>';
			back.appendChild( document.createTextNode( 'All profiles' ) );
			back.addEventListener( 'click', function () { view = 'grid'; draw(); } );
			root.appendChild( back );
		} else {
			var fb = filterBar();
			if ( fb ) { root.appendChild( fb ); }
		}
		// One renderer for Discover, "how others see me" and /member/<id>/ —
		// see csmProfileCard() in app-screens.js. Three copies of this markup is
		// how they drifted apart.
		var card = window.csmProfileCard( p );
		var hero = card.querySelector( '.csm-d-photo' );
		if ( hero ) { hero.loading = 'eager'; }  // the card the member is looking at

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
		/*
		 * At the end of a FREE member's set, Next stays live and explains
		 * itself. A greyed-out arrow at the fifth profile said nothing about
		 * why -- owner, 2026-09-19: "Premium option should show if I try
		 * scrolling past 5th profile." Premium members, and anyone with a
		 * profile still ahead of them, get the plain behaviour.
		 */
		var atEnd = ( step( 1 ) < 0 );
		next.disabled = atEnd && !! meta.isPremium;
		if ( atEnd && ! meta.isPremium ) { next.classList.add( 'is-upsell' ); }
		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () {
			if ( atEnd && ! meta.isPremium ) { return upsell(); }
			go( 1 );
		} );

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

	/* The Premium prompt, in the same dialog the blurred-photo button uses, so
	   a free member meets one consistent ask wherever the limit shows. */
	function upsell() {
		var freeN = meta.freeQuota || 5, premN = meta.premiumQuota || 10;
		var note = 'That\u2019s all ' + freeN + ' profiles in your free set for this week. '
			+ 'Premium members get ' + premN + ' every week, and can see who viewed them.';
		if ( ! window.csmConfirm ) {
			if ( meta.upgrade && window.confirm( note + '\n\nSee Premium?' ) ) { window.location.href = meta.upgrade; }
			return;
		}
		window.csmConfirm( note, {
			title: 'Want more profiles?',
			okText: 'See Premium',
			cancelText: 'Not now'
		} ).then( function ( yes ) {
			if ( yes && meta.upgrade ) { window.location.href = meta.upgrade; }
		} );
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

	function load() {
		return api( CFG.queue ).then( function ( d ) {
			if ( ! d || ! d.ok ) { return empty( 'We could not load profiles just now.' ); }
			profiles = d.profiles || [];
			meta.filters = d.filters || meta.filters;
			idx = 0;
			view = meta.grid ? 'grid' : 'single';
			draw();
		} );
	}

	api( CFG.queue ).then( function ( d ) {
		if ( ! d || ! d.ok ) { return empty( 'We could not load profiles just now.' ); }
		profiles = d.profiles || [];
		meta = { isPremium: d.isPremium, resetOn: d.resetOn, resetIso: d.resetIso, upgrade: d.upgrade, freeQuota: d.freeQuota, premiumQuota: d.premiumQuota, quota: d.quota, filters: d.filters, grid: d.grid };
		idx = 0;
		view = d.grid ? 'grid' : 'single';
		draw();

		/* The new-account tour, after the first card is on screen so the member
		   can see what is being described. Runs once ever; the component itself
		   checks and records that. */
		if ( window.csmCoach ) { window.csmCoach.tour(); }
	} ).catch( function () {
		empty( 'We could not load profiles just now.' );
	} );
} )();
