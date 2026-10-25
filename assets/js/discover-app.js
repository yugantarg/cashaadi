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
		box.appendChild( el( 'h2', null, 'That\u2019s your ' + ( meta.quota || meta.freeQuota || 5 ) + ' for this week' ) );

		if ( ! message && ! meta.isPremium ) {
			box.appendChild( el( 'p', null,
				'You\u2019ve seen all ' + ( meta.freeQuota || 5 ) + ' profiles in your free weekly set. Premium members get ' + ( meta.premiumQuota || 10 ) + ( meta.goldOnSale ? '; Gold members see unlimited profiles.' : '.' ) ) );
		} else if ( ! message && meta.goldOnSale && meta.upgrade ) {
			// Premium, not Gold: the one thing left to offer is unlimited.
			var gp = el( 'p', null, 'Want more? ' );
			var ga = el( 'a', null, 'Gold members see unlimited profiles.' );
			ga.href = meta.upgrade;
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
			a.href = meta.upgrade;
			a.textContent = 'See Premium';
			up.appendChild( a );
			box.appendChild( up );
		}

		if ( meta.filters && meta.filters.eligible ) {
			var fb2 = filterBar();
			if ( fb2 ) {
				if ( meta.filters.filters && ( meta.filters.filters.age_min || meta.filters.filters.in_min || pickCount( meta.filters.filters ) ) ) {
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

	function filterBar() {
		var f = meta.filters;
		if ( ! f ) { return null; }
		var wrap = el( 'div', 'csm-d-filterbar' );
		/* Free members see the control locked: filters are a Premium feature. */
		if ( ! f.eligible ) {
			if ( ! meta.upgrade ) { return null; }
			var lock = el( 'a', 'csm-d-filterbtn is-locked', '\uD83D\uDD12 Filter by community, religion, age\u2026 with Premium' );
			lock.href = meta.upgrade;
			wrap.appendChild( lock );
			return wrap;
		}
		var cur  = f.filters || {};
		var btn  = el( 'button', 'csm-d-filterbtn' );
		btn.type = 'button';
		var on = ( cur.age_min || cur.in_min || pickCount( cur ) );
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

	/* How many pick-list filters are set (community, religion, …). */
	function pickCount( c ) {
		var n = 0;
		( ( meta.filters && meta.filters.choices ) || [] ).forEach( function ( ch ) {
			if ( c[ ch.key ] && ( 'same' === c[ ch.key ] || c[ ch.key ].length ) ) { n++; }
		} );
		return n;
	}

	function summary( c ) {
		var bits = [];
		if ( c.age_min ) { bits.push( c.age_min + '\u2013' + c.age_max + ' yrs' ); }
		if ( c.in_min ) { bits.push( ftIn( c.in_min ) + '\u2013' + ftIn( c.in_max ) ); }
		var n = pickCount( c );
		if ( n ) { bits.push( n + ' more' ); }
		return bits.join( ' \u00b7 ' );
	}

	/* A free-text field: one switch, "Same as mine". */
	function sameSwitch( ch, chosen ) {
		var row = el( 'label', 'csm-d-frow csm-d-fsame' );
		var cb = document.createElement( 'input' );
		cb.type = 'checkbox';
		cb.checked = 'same' === chosen;
		row.appendChild( cb );
		row.appendChild( el( 'span', null, 'Same ' + ch.label.toLowerCase() + ' as mine (' + ch.mine + ')' ) );
		return { row: row, read: function () { return cb.checked ? 'same' : ''; } };
	}

	/* Groups as tick boxes, with Select all / Clear (owner, 2026-10-24:
	   "I can tick whatever categories I'm okay with and all those show").
	   Nothing ticked means no filter. */
	function ticks( ch, chosen ) {
		var row = el( 'div', 'csm-d-frow csm-d-fticks' );
		var head = el( 'div', 'csm-d-ftickhead' );
		head.appendChild( el( 'span', 'csm-d-flabel', ch.label ) );
		var all = el( 'button', 'csm-d-tickall', 'Select all' );
		var none = el( 'button', 'csm-d-tickall', 'Clear' );
		all.type = none.type = 'button';
		head.appendChild( all );
		head.appendChild( none );
		row.appendChild( head );
		var list = el( 'div', 'csm-d-ticklist' );
		var picked = {};
		( chosen || [] ).forEach( function ( v ) { picked[ v ] = true; } );
		var boxes = ch.options.map( function ( opt ) {
			var lab = el( 'label', 'csm-d-tick' );
			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.checked = !! picked[ opt ];
			lab.appendChild( cb );
			lab.appendChild( el( 'span', null, opt ) );
			list.appendChild( lab );
			return { opt: opt, cb: cb };
		} );
		all.addEventListener( 'click', function () { boxes.forEach( function ( b ) { b.cb.checked = true; } ); } );
		none.addEventListener( 'click', function () { boxes.forEach( function ( b ) { b.cb.checked = false; } ); } );
		row.appendChild( list );
		return { row: row, read: function () {
			var on = boxes.filter( function ( b ) { return b.cb.checked; } );
			// Everything ticked is the same as no filter.
			return on.length === boxes.length ? [] : on.map( function ( b ) { return b.opt; } );
		} };
	}

	/* A pick list as tappable chips; returns a reader for the chosen values. */
	function chips( ch, chosen ) {
		var row = el( 'div', 'csm-d-frow csm-d-fchips' );
		row.appendChild( el( 'span', 'csm-d-flabel', ch.label ) );
		var box = el( 'div', 'csm-d-chipset' );
		var picked = {};
		( chosen || [] ).forEach( function ( v ) { picked[ v ] = true; } );
		ch.options.forEach( function ( opt ) {
			var c = el( 'button', 'csm-d-chip' + ( picked[ opt ] ? ' is-on' : '' ), opt );
			c.type = 'button';
			c.addEventListener( 'click', function () {
				picked[ opt ] = ! picked[ opt ];
				c.classList.toggle( 'is-on', !! picked[ opt ] );
			} );
			box.appendChild( c );
		} );
		row.appendChild( box );
		return { row: row, read: function () { return ch.options.filter( function ( o ) { return picked[ o ]; } ); } };
	}

	function sheet() {
		var f = meta.filters, b = f.bounds, cur = f.filters || {};
		var back = el( 'div', 'csm-d-sheetback' );
		var box  = el( 'div', 'csm-d-sheet' );
		box.appendChild( el( 'h2', null, 'Filter profiles' ) );
		box.appendChild( el( 'p', 'csm-d-sheethint',
			meta.unlimited ? 'Gold: unlimited profiles.' : 'You get ' + f.quota + ' profiles a week.' ) );

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

		var picks = ( f.choices || [] ).map( function ( ch ) {
			var c = ch.same ? sameSwitch( ch, cur[ ch.key ] ) : ( ch.tick ? ticks( ch, cur[ ch.key ] ) : chips( ch, cur[ ch.key ] ) );
			box.appendChild( c.row );
			return { key: ch.key, read: c.read };
		} );
		if ( picks.length ) {
			box.appendChild( el( 'p', 'csm-d-sheethint', 'Nothing ticked means any. Community and mother tongue: "Others" covers everyone not in a listed group. Other questions: profiles that have not answered are still shown.' ) );
		}

		var err = el( 'p', 'csm-d-ferr' );
		box.appendChild( err );

		/* The same rule the server applies, checked as they choose. */
		function validate() {
			var msg = '';
			var a1 = parseInt( aMin.sel.value, 10 ), a2 = parseInt( aMax.sel.value, 10 );
			var h1 = parseInt( hMin.sel.value, 10 ), h2 = parseInt( hMax.sel.value, 10 );
			// One end on "Any" is fine: no limit that way (v1.89.8).
			if ( a1 && a2 && ( a2 - a1 ) < b.ageSpan ) { msg = 'The age range must cover at least ' + b.ageSpan + ' years.'; }
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
			var payload = {
				age_min: aMin.sel.value, age_max: aMax.sel.value,
				in_min: hMin.sel.value, in_max: hMax.sel.value
			};
			picks.forEach( function ( pk ) { payload[ pk.key ] = pk.read(); } );
			send( payload );
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

	/* Decided in this visit (not yet counted by the server's decidedWeek). */
	function countActed() {
		return Object.keys( acted ).length;
	}

	function drawGrid() {
		root.innerHTML = '';
		var fb = filterBar();
		if ( fb ) { root.appendChild( fb ); }
		var bc = boostCard();
		if ( bc ) { root.appendChild( bc ); }

		/* Profiles decided in the detail view are gone from here when the member
		   comes back — act() marks them rather than splicing, so the carousel's
		   indexes stay valid; the grid filters on the same map. */
		var left = profiles.filter( function ( p ) { return ! acted[ p.id ]; } );
		// Page decided: fetch the next one rather than saying "that's all".
		if ( ! left.length && meta.grid && ! meta.noMore ) {
			root.appendChild( el( 'p', 'csm-app-loading', 'Loading more profiles\u2026' ) );
			api( CFG.queue + ( CFG.queue.indexOf( '?' ) === -1 ? '?' : '&' ) + 'more=1' ).then( function ( d ) {
				var fresh = ( d && d.ok && d.profiles ) ? d.profiles.filter( function ( p ) { return ! acted[ p.id ]; } ) : [];
				if ( ! fresh.length ) { meta.noMore = true; return empty(); }
				profiles = d.profiles;
				drawGrid();
			} );
			return;
		}
		if ( ! left.length ) { return empty(); }

		/* The real number, not just what has loaded (v1.89.7): Gold is
		   unlimited; everyone else sees what is left of the week. */
		var weekLeft = Math.max( left.length, ( meta.quota || 0 ) - ( meta.decidedWeek || 0 ) - countActed() );
		var head = el( 'p', 'csm-d-gridcount', meta.unlimited
			? 'Unlimited profiles'
			: weekLeft + ' of ' + meta.quota + ' left this week' );
		root.appendChild( head );

		var grid = el( 'div', 'csm-d-grid' );
		profiles.forEach( function ( p, i ) {
			if ( acted[ p.id ] ) { return; }
			grid.appendChild( gridCard( p, i ) );
		} );
		root.appendChild( grid );

		// Grid members (Gold, Premium women): a page at a time (v1.88.3).
		if ( ( meta.unlimited || ( meta.quota || 0 ) > profiles.length ) && ! meta.noMore ) {
			var more = el( 'button', 'csm-d-more', 'Show more profiles' );
			more.type = 'button';
			more.addEventListener( 'click', function () {
				more.disabled = true;
				more.textContent = 'Loading\u2026';
				var before = profiles.length, y = window.scrollY;
				api( CFG.queue + ( CFG.queue.indexOf( '?' ) === -1 ? '?' : '&' ) + 'more=1' ).then( function ( d ) {
					if ( d && d.ok ) {
						profiles = d.profiles || profiles;
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
		meta = { isPremium: d.isPremium, resetOn: d.resetOn, resetIso: d.resetIso, upgrade: d.upgrade, freeQuota: d.freeQuota, premiumQuota: d.premiumQuota, quota: d.quota, filters: d.filters, grid: d.grid, nextSteps: d.nextSteps || [], boost: d.boost || null, decidedWeek: d.decidedWeek || 0, unlimited: !! d.unlimited, goldOnSale: !! ( window.CSM_APP && window.CSM_APP.goldOnSale ) };
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
