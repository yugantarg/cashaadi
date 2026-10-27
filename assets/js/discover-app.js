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

	/* 24 profiles at a time (v1.89.44); "more" asks for the next 24. */
	var limit = 24;
	function queueUrl( more ) {
		if ( more ) { limit += 24; }
		return CFG.queue + ( CFG.queue.indexOf( '?' ) === -1 ? '?' : '&' ) + 'limit=' + limit + ( more ? '&more=1' : '' );
	}

	function api( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.cache = 'no-store'; // never an old copy (v1.89.85)
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = CFG.nonce;
		// Without this WordPress does not read a JSON body, and the filter
		// sheet saved nothing, wiping every filter (owner, 2026-10-25).
		if ( typeof opts.body === 'string' ) { opts.headers['Content-Type'] = 'application/json'; }
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
		if ( meta.unlimited ) {
			// Gold has no weekly limit: empty means everyone matching has been seen.
			box.appendChild( el( 'h2', null, 'You\u2019ve seen everyone' ) );
			box.appendChild( el( 'p', null, 'You have seen every member who matches your filters. New members appear here as they join. Widen your filters to see more.' ) );
			var fbG = filterBar();
			if ( fbG ) { box.appendChild( fbG ); }
			root.appendChild( box );
			return;
		}
		if ( ! message && meta.hiddenByFilter ) {
			// Filters only narrow the week's set (v1.89.11): say so, rather than "that's your 50".
			box.appendChild( el( 'h2', null, 'No profiles match your filters' ) );
			var fbF = filterBar();
			if ( fbF ) { box.appendChild( fbF ); }
			root.appendChild( box );
			return;
		}
		box.appendChild( el( 'h2', null, 'That\u2019s your ' + ( meta.quota || meta.freeQuota || 5 ) + ' for this week' ) );

		if ( ! message && ! meta.isPremium ) {
			box.appendChild( el( 'p', null,
				'You\u2019ve seen all ' + ( meta.freeQuota || 5 ) + ' profiles in your free weekly set. Premium members get ' + ( meta.premiumQuota || 10 ) + ( meta.goldOnSale ? '; Gold members see unlimited profiles.' : '.' ) ) );
		} else if ( ! message && meta.goldOnSale && meta.upgrade ) {
			// Premium, not Gold: the one thing left to offer is unlimited.
			var gp = el( 'p', null, 'Want more? ' );
			var ga = el( 'a', null, 'Gold members see unlimited profiles.' );
			ga.href = window.csmSrc( meta.upgrade, 'discover-gold' );
			gp.appendChild( ga );
			box.appendChild( gp );
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

		/*
		 * The +5 / +5 offer again, at the moment it matters most: the member
		 * has just run out of profiles (owner, 2026-10-04).
		 */
		var boostEnd = ! message ? boostCard() : null;
		if ( boostEnd || ( meta.boost && meta.boost.hidden ) ) {
			if ( boostEnd ) { box.appendChild( boostEnd ); }
			// Photo and verification are on the card (or were closed): not again.
			meta.nextSteps = ( meta.nextSteps || [] ).filter( function ( st ) {
				return ! meta.boost || ( st.url !== meta.boost.photoUrl && st.url !== meta.boost.verifyUrl );
			} );
		}

		/*
		 * While you wait (owner, 2026-10-03): new members finished their set in
		 * minutes, found nothing else to do and deleted their account. Up to
		 * three useful things, chosen on the server for this member.
		 */
		if ( ! message && meta.nextSteps && meta.nextSteps.length ) {
			var ws = el( 'div', 'csm-d-wait' );
			ws.appendChild( el( 'h3', null, 'While you wait' ) );
			meta.nextSteps.forEach( function ( st ) {
				var row = document.createElement( 'a' );
				row.className = 'csm-d-next-item';
				row.href = st.url;
				row.appendChild( el( 'strong', null, st.title ) );
				row.appendChild( el( 'span', null, st.body ) );
				ws.appendChild( row );
			} );
			box.appendChild( ws );
		}

		// Premium raises the weekly quota, so this is the one place it is genuinely
		// useful rather than nagging. Never shown to members who already pay.
		if ( ! meta.isPremium && meta.upgrade && ! message ) {
			var up = el( 'div', 'csm-d-upsell' );
			up.appendChild( el( 'h3', null, 'Want to see more now?' ) );
			up.appendChild( el( 'p', null,
				'Premium raises your weekly set to ' + ( meta.premiumQuota || 10 ) + ' profiles.' ) );
			var a = document.createElement( 'a' );
			a.className = 'csm-d-upsell-cta';
			a.href = window.csmSrc( meta.upgrade, 'discover-empty' );
			a.textContent = 'See Premium';
			up.appendChild( a );
			box.appendChild( up );
		}

		if ( meta.filters && meta.filters.eligible ) {
			var fb2 = filterBar();
			if ( fb2 ) {
				if ( window.csmFilterBar.isOn( meta.filters ) ) {
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
	/*
	 * "Get up to 10 more profiles this week" (owner, 2026-10-04): +5 for a
	 * photo, +5 for a verified CA badge. Shown above the profiles until both
	 * are done; each row turns into a tick once it is earned.
	 */
	function boostCard() {
		var b = meta.boost;
		// Verification is offered to CA and CA Inter members only.
		var canV = !! b && b.canVerify;
		if ( ! b || ! b.per || b.hidden || ( b.photo && ( ! canV || 'approved' === b.verify ) ) ) { return null; }
		var todo = ( b.photo ? 0 : 1 ) + ( canV && 'approved' !== b.verify ? 1 : 0 );
		var box = el( 'div', 'csm-d-boost' );
		// Closable for good (owner, 2026-10-25: "give option to remove it").
		var x = el( 'button', 'csm-d-boost-x', '\u2715' );
		x.type = 'button';
		x.setAttribute( 'aria-label', 'Hide this' );
		x.addEventListener( 'click', function () {
			b.hidden = true;
			if ( box.parentNode ) { box.parentNode.removeChild( box ); }
			api( b.hide, { method: 'POST' } );
		} );
		box.appendChild( x );
		box.appendChild( el( 'strong', 'csm-d-boost-h', 'Get ' + ( todo * b.per ) + ' more profiles this week' ) );
		function row( text, href, done, note ) {
			var r = document.createElement( done ? 'span' : 'a' );
			r.className = 'csm-d-boost-row' + ( done ? ' is-done' : '' );
			if ( ! done ) { r.href = href; }
			r.appendChild( el( 'span', null, ( done ? '\u2713 ' : '+' + b.per + ' \u00b7 ' ) + text ) );
			if ( note ) { r.appendChild( el( 'em', null, note ) ); }
			return r;
		}
		box.appendChild( row( b.photo ? 'Photo added' : 'Add a photo', b.photoUrl, b.photo ) );
		if ( canV ) {
			var vDone = 'approved' === b.verify;
			var inter = 'inter' === b.claim;
			box.appendChild( row(
				vDone ? ( inter ? 'CA Inter verified' : 'CA verified' )
					: ( inter ? 'Verify your CA Inter status' : 'Verify your CA' ) + ' (upload your ' + ( b.docLabel || 'ICAI certificate' ) + ')',
				b.verifyUrl,
				vDone,
				'pending' === b.verify ? 'In review \u2014 the extra profiles arrive once approved' : ''
			) );
		}
		box.appendChild( el( 'p', 'csm-d-boost-foot', canV ? 'Verifying and adding a photo increase your chances 5 times.' : 'Adding a photo increases your chances 5 times.' ) );
		return box;
	}

	function showAll() {
		return window.csmFilterBar.showAll( CFG.filters, CFG.nonce, function ( d ) { meta.filters = d; load(); } );
	}

	/* The filter button and sheet live in app-screens.js, shared with Requests. */
	function filterBar( inCount ) {
		if ( ! meta.filters ) { return null; }
		var fb = window.csmFilterBar( {
			state: meta.filters,
			url: CFG.filters,
			nonce: CFG.nonce,
			upgrade: meta.upgrade,
			src: 'filter-discover',
			hint: meta.unlimited ? 'Gold: unlimited profiles.' : 'You get ' + meta.filters.quota + ' profiles a week.',
			onApplied: function ( d ) { meta.filters = d; load(); }
		} );
		/* "N hidden by your filters · Show all": in the grid it is in the count
		   line; elsewhere (card view, empty screen) beside the button. */
		if ( fb && meta.hiddenByFilter && ! inCount ) {
			var hp = el( 'span', 'csm-d-fhidden', meta.hiddenByFilter + ' hidden by your filters \u00b7' );
			hp.appendChild( showAll() );
			fb.insertBefore( hp, fb.firstChild );
		}
		return fb;
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

	/* Decided in this visit (not yet counted by the server's decidedWeek). */
	function countActed() {
		return Object.keys( acted ).length;
	}

	function drawGrid() {
		root.innerHTML = '';
		var fb = filterBar( true ); // the hidden count goes in the count line
		if ( fb ) { root.appendChild( fb ); }
		var bc = boostCard();
		if ( bc ) { root.appendChild( bc ); }

		/* Profiles decided in the detail view are gone from here when the member
		   comes back — act() marks them rather than splicing, so the carousel's
		   indexes stay valid; the grid filters on the same map. */
		var left = profiles.filter( function ( p ) { return ! acted[ p.id ]; } );
		// Page decided: fetch the next one rather than saying "that's all".
		if ( ! left.length && meta.grid ) {
			root.appendChild( el( 'p', 'csm-app-loading', 'Loading more profiles\u2026' ) );
			fetchMore( function ( got ) { return got ? drawGrid() : empty(); } );
			return;
		}
		if ( ! left.length ) { return empty(); }

		/* The real number, not just what has loaded (v1.89.7): Gold is
		   unlimited; everyone else sees what is left of the week. */
		var weekLeft = Math.max( left.length, ( meta.quota || 0 ) - ( meta.decidedWeek || 0 ) - countActed() );
		// Premium's undecided profiles carry over (v1.89.42), so the deck can
		// hold more than one week's worth: then say how many are waiting.
		var head = el( 'p', 'csm-d-gridcount', meta.unlimited
			? 'Unlimited profiles'
			: ( ( meta.total || left.length ) > meta.quota ? ( meta.total || left.length ) + ' profiles to see' : weekLeft + ' of ' + meta.quota + ' left this week' ) );
		// Owner's screenshot (2026-10-25): "Show all" belongs right here.
		if ( meta.hiddenByFilter ) {
			head.appendChild( document.createTextNode( ' \u00b7 ' + meta.hiddenByFilter + ' hidden by your filters \u00b7' ) );
			head.appendChild( showAll() );
		}

		root.appendChild( head );

		var grid = el( 'div', 'csm-d-grid' );
		profiles.forEach( function ( p, i ) {
			if ( acted[ p.id ] ) { return; }
			grid.appendChild( gridCard( p, i ) );
		} );
		root.appendChild( grid );

		// Grid members (Gold, Premium women): a page at a time (v1.88.3).
		// More only while this week still owes profiles that have not loaded.
		if ( ( meta.unlimited || ( meta.total || 0 ) > profiles.length || weekLeft > left.length ) && ! meta.noMore ) {
			var more = el( 'button', 'csm-d-more', 'Show more profiles' );
			more.type = 'button';
			more.addEventListener( 'click', function () {
				more.disabled = true;
				more.textContent = 'Loading\u2026';
				var before = profiles.length, y = window.scrollY;
				api( queueUrl( true ) ).then( function ( d ) {
					if ( d && d.ok ) {
						profiles = d.profiles || profiles;
						meta.total = d.total || meta.total;
						if ( profiles.length <= before ) { meta.noMore = true; }
					}
					drawGrid();
					window.scrollTo( 0, y );
				} );
			} );
			root.appendChild( more );
		} else if ( meta.unlimited ) {
			root.appendChild( el( 'p', 'csm-d-gridcount', 'That is everyone who matches your filters for now.' ) );
		}
	}

	/*
	 * The next page from the server, whatever an earlier "Show more" found
	 * (owner, 2026-10-27: Gold reached the end, saw "widen your filters", and a
	 * reload brought the profiles). Asked fresh each time the loaded ones run
	 * out; then(true) when there is an undecided profile to show.
	 */
	function fetchMore( then ) {
		api( queueUrl( true ) ).then( function ( d ) {
			var fresh = ( d && d.ok && d.profiles ) ? d.profiles.filter( function ( p ) { return ! acted[ p.id ]; } ) : [];
			if ( ! fresh.length ) { meta.noMore = true; return then( false ); }
			meta.noMore = false;
			profiles = d.profiles;
			meta.total = d.total || meta.total;
			then( true );
		} ).catch( function () { then( false ); } );
	}

	/* More may be waiting beyond the loaded page. */
	function mayHaveMore() {
		return meta.unlimited || meta.grid || ( meta.total || 0 ) > profiles.length;
	}

	function draw() {
		if ( 'grid' === view ) { return drawGrid(); }
		var p = profiles[ idx ];
		if ( ! p ) {
			// The loaded page is decided: the grid fetches more; the card view asks here.
			if ( meta.grid ) { view = 'grid'; return drawGrid(); }
			if ( mayHaveMore() ) {
				root.innerHTML = '';
				root.appendChild( el( 'p', 'csm-app-loading', 'Loading more profiles…' ) );
				return fetchMore( function ( got ) {
					if ( ! got ) { return empty(); }
					idx = -1;
					idx = Math.max( 0, step( 1 ) );
					draw();
				} );
			}
			return empty();
		}

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
		// On the first card only, so it does not repeat on every profile.
		if ( 0 === idx ) {
			var bc2 = boostCard();
			if ( bc2 ) { root.appendChild( bc2 ); }
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
		/* Labelled buttons (owner, 2026-10-26), from csmDeck so Requests uses
		   the same ones. Save sits between navigation and the decisions in
		   weight: parking a profile should not feel like choosing one. */
		var passW = window.csmDeck.button( 'csm-d-pass', 'Pass', window.csmDeck.icons.pass );
		var saveW = window.csmDeck.button( 'csm-d-save', 'Save for later', window.csmDeck.icons.save );
		var likeW = window.csmDeck.button( 'csm-d-like', 'Send request', window.csmDeck.icons.like );
		var pass = passW.btn, save = saveW.btn, like = likeW.btn;

		/* Explain each action the first time it is used, then carry it out. The
		   explainer runs BEFORE the action, which is the whole point for Pass:
		   telling someone it was irreversible after the fact is no use. Once
		   seen, csmCoach.explain() calls straight through. */
		function guarded( name ) {
			return function () {
				var btn = this;
				var go = function () {
					// Premium: offer a note with the request (v1.85.0).
					if ( 'like' === name && meta.isPremium && window.csmNoteSheet ) {
						window.csmNoteSheet( p.name, function ( note ) { act( p, name, btn, note ); } );
					} else {
						act( p, name, btn );
					}
				};
				if ( window.csmCoach ) {
					window.csmCoach.explain( name, go );
				} else {
					go();
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
		// Premium and Gold: at the last LOADED card, Next loads the next page.
		next.disabled = atEnd && !! meta.isPremium && ! mayHaveMore();
		if ( atEnd && ! meta.isPremium ) { next.classList.add( 'is-upsell' ); }
		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () {
			if ( atEnd && ! meta.isPremium ) { return upsell(); }
			if ( atEnd ) {
				next.disabled = true;
				var cur = p.id;
				return fetchMore( function ( got ) {
					// Carry on after the card on screen, wherever it sits in the new list.
					idx = profiles.map( function ( q ) { return q.id; } ).indexOf( cur ); // -1: from the top
					var i = got ? step( 1 ) : -1;
					if ( i < 0 ) { next.disabled = true; return; }
					idx = i;
					draw();
				} );
			}
			go( 1 );
		} );

		// Opening the number sent (or accepted) a request: done with this card (v1.89.65).
		var onPhone = function ( e ) {
			if ( ! e.detail || e.detail.id !== p.id ) { return; }
			document.removeEventListener( 'csm:phone-opened', onPhone );
			acted[ p.id ] = true;
			window.csmDeck.decided( bar, e.detail.result );
		};
		document.addEventListener( 'csm:phone-opened', onPhone );

		bar.appendChild( prev );
		bar.appendChild( passW );
		bar.appendChild( saveW );
		bar.appendChild( likeW );
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
			if ( meta.upgrade && window.confirm( note + '\n\nSee Premium?' ) ) { window.location.href = window.csmSrc( meta.upgrade, 'discover-limit' ); }
			return;
		}
		window.csmConfirm( note, {
			title: 'Want more profiles?',
			okText: 'See Premium',
			cancelText: 'Not now'
		} ).then( function ( yes ) {
			if ( yes && meta.upgrade ) { window.location.href = window.csmSrc( meta.upgrade, 'discover-limit' ); }
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

	function act( p, what, btn, note ) {
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
			body: JSON.stringify( { profile_id: p.id, action: what, note: note || '' } )
		} ).then( function ( d ) {
			release();
			if ( d && false === d.ok && d.message ) { toast( d.message ); }
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
		limit = 24;
		return api( queueUrl( false ) ).then( function ( d ) {
			if ( ! d || ! d.ok ) { return empty( 'We could not load profiles just now.' ); }
			profiles = d.profiles || [];
			meta.filters = d.filters || meta.filters;
			// Not for unlimited (Gold): only a page is loaded, so the count misleads (owner, 2026-10-26).
			meta.hiddenByFilter = meta.unlimited ? 0 : ( d.hiddenByFilter || 0 );
			meta.total = d.total || 0;
			meta.noMore = false; // new filters, new deck
			idx = 0;
			view = meta.grid ? 'grid' : 'single';
			draw();
		} );
	}

	api( queueUrl( false ) ).then( function ( d ) {
		if ( ! d || ! d.ok ) { return empty( 'We could not load profiles just now.' ); }
		profiles = d.profiles || [];
		meta = { isPremium: d.isPremium, resetOn: d.resetOn, resetIso: d.resetIso, upgrade: d.upgrade, freeQuota: d.freeQuota, premiumQuota: d.premiumQuota, quota: d.quota, filters: d.filters, grid: d.grid, nextSteps: d.nextSteps || [], boost: d.boost || null, decidedWeek: d.decidedWeek || 0, unlimited: !! d.unlimited, total: d.total || 0, hiddenByFilter: d.unlimited ? 0 : ( d.hiddenByFilter || 0 ), goldOnSale: !! ( window.CSM_APP && window.CSM_APP.goldOnSale ) };
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
