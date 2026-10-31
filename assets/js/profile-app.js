/**
 * Profile — the member's own hub.
 *
 * Completion leads, because on a matrimonial app the useful question on your own
 * profile is "what do I still need to do to get matches", not "what does my
 * profile say" — the public view is one tap away for that.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_PROFILE;
	var root = document.getElementById( 'csm-profile-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function link( cls, href, text ) {
		var a = document.createElement( 'a' );
		a.className = cls;
		a.href = href;
		if ( text !== undefined ) { a.textContent = text; }
		return a;
	}

	var chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

	/*
	 * Profile boost (v1.89.47): 24 hours at the front of Discover. Running,
	 * ready to use, or how to get one.
	 */
	function boostCard( b ) {
		if ( ! b ) { return null; }
		var box = el( 'div', 'csm-p-boost' );
		if ( b.until ) {
			box.classList.add( 'is-on' );
			box.appendChild( el( 'strong', null, '\uD83D\uDE80 Boosted \u00b7 ' + b.hoursLeft + 'h left' ) );
			box.appendChild( el( 'span', null, 'Your profile is being shown first in Discover.' ) );
			return box;
		}
		if ( b.available > 0 ) {
			box.appendChild( el( 'strong', null, 'Boost my profile' ) );
			box.appendChild( el( 'span', null, 'Be shown first in Discover for 24 hours. ' + b.available + ( 1 === b.available ? ' boost' : ' boosts' ) + ' left.' ) );
			// Say where the free one comes from (owner, 2026-10-26).
			var perk = 'gold' === b.plan ? 'As a Gold member you get 1 free boost every week.'
				: ( 'premium' === b.plan ? 'As a Premium member you get 1 free boost every month.' : '' );
			if ( perk ) { box.appendChild( el( 'span', 'csm-p-boost-perk', perk ) ); }
			var go = el( 'button', 'csm-p-boost-btn', 'Boost now' );
			go.type = 'button';
			go.addEventListener( 'click', function () {
				var run = function () {
					go.disabled = true;
					fetch( b.start, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( res && res.ok ) { box.replaceWith( boostCard( res.state ) ); return; }
							go.disabled = false;
							if ( window.csmToast ) { window.csmToast( ( res && res.message ) || 'That did not work.', 'bad' ); }
						} ).catch( function () { go.disabled = false; } );
				};
				if ( ! window.csmConfirm ) { return run(); }
				window.csmConfirm( 'Your profile goes to the front of Discover for 24 hours, starting now.', { title: 'Boost your profile?', okText: 'Boost now', cancelText: 'Not now' } )
					.then( function ( ok ) { if ( ok ) { run(); } } );
			} );
			box.appendChild( go );
			return box;
		}
		box.appendChild( el( 'strong', null, 'Profile boost' ) );
		box.appendChild( el( 'span', null, 'gold' === b.plan ? 'Your next Gold boost arrives on Monday.'
			: ( 'premium' === b.plan ? 'Your next Premium boost arrives on the 1st of next month.' : 'Be shown first in Discover for 24 hours.' ) ) );
		if ( b.buy ) {
			var a = link( 'csm-p-boost-btn', b.buy, 'Get ' + ( 1 === b.pack ? '1 boost' : b.pack + ' boosts' ) + ' for \u20b9' + b.price );
			box.appendChild( a );
		}
		return box;
	}

	/*
	 * Few members saw this profile this week (v1.89.99): what would help,
	 * each a link to where it is fixed. Shown while it applies.
	 */
	function reachCard( r ) {
		var box = el( 'div', 'csm-p-boost csm-p-reach' );
		box.appendChild( el( 'strong', null, r.title ) );
		box.appendChild( el( 'span', null, r.body ) );
		var ul = el( 'ul', 'csm-p-reach-list' );
		( r.items || [] ).forEach( function ( it ) {
			var li = el( 'li' );
			li.appendChild( link( 'csm-p-reach-a', it.url, it.text + ' \u203a' ) );
			ul.appendChild( li );
		} );
		box.appendChild( ul );
		return box;
	}

	/*
	 * Astro details, asked once (v1.89.74). Either button answers it; the
	 * card does not come back.
	 */
	function astroCard( a ) {
		var box = el( 'div', 'csm-p-boost csm-p-astro' );
		box.appendChild( el( 'strong', null, 'Add your astro details' ) );
		box.appendChild( el( 'span', null, 'Time and place of birth, for families who match horoscopes. One switch decides whether others see them.' ) );
		function answered() {
			return fetch( a.done, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } ).catch( function () {} );
		}
		var row = el( 'div', 'csm-p-astro-act' );
		var go = link( 'csm-p-boost-btn', a.edit, 'Add details' );
		go.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			answered().then( function () { window.location.href = a.edit; } );
		} );
		var no = el( 'button', 'csm-p-astro-no', 'Not now' );
		no.type = 'button';
		no.addEventListener( 'click', function () { answered(); box.remove(); } );
		row.appendChild( go );
		row.appendChild( no );
		box.appendChild( row );
		return box;
	}

	function draw( d ) {
		root.innerHTML = '';

		/* ---- who you are ---- */
		var head = el( 'section', 'csm-p-head' );
		var av = link( 'csm-p-avatar', d.links.photos );
		var img = el( 'img' );
		img.src = d.avatar; img.alt = '';
		av.appendChild( img );
		if ( ! d.hasPhoto ) { av.appendChild( el( 'span', 'csm-p-avatar-add', 'Add a photo' ) ); }
		head.appendChild( av );

		head.appendChild( el( 'h1', 'csm-p-name', d.name + ( d.age ? ', ' + d.age : '' ) ) );
		if ( d.city ) { head.appendChild( el( 'p', 'csm-p-city', d.city ) ); }

		var badges = el( 'div', 'csm-p-badges' );
		if ( d.verified ) {
			badges.appendChild( el( 'span', 'csm-p-badge is-verified', d.verifiedLabel || 'Verified CA' ) );
		} else if ( 'rejected' === d.caState && d.links && d.links.verify ) {
			// Say it was rejected. "Verify now" alone reads as "you have not
			// tried yet", which is exactly what somebody who HAS tried must not
			// be told — they will upload the same wrong document again.
			badges.appendChild( link( 'csm-p-badge is-rejected', d.links.verify, 'Not verified — try again' ) );
		} else if ( 'review' === d.caState ) {
			// The model could not decide and a person is looking. Not a link:
			// there is nothing for the member to do, and a tap that leads to
			// the upload screen would suggest otherwise.
			badges.appendChild( el( 'span', 'csm-p-badge is-pending', 'Verification with our team' ) );
		} else if ( 'pending' === d.caState && d.links && d.links.verify ) {
			badges.appendChild( link( 'csm-p-badge is-pending', d.links.verify, 'Verification in review' ) );
		} else if ( d.links && d.links.verify ) {
			// Same spot as the "Verified CA" badge, but a call to action: a member
			// who is not yet verified taps this to upload their ICAI proof.
			badges.appendChild( link( 'csm-p-badge is-verify', d.links.verify, 'Verify now' ) );
		}
		// Gold is Premium too (Membership::is_premium); name the plan they have.
		if ( d.isPremium ) { badges.appendChild( el( 'span', 'csm-p-badge is-premium' + ( d.isGold ? ' is-gold' : '' ), d.isGold ? 'Gold' : 'Premium' ) ); }
		if ( d.blurred ) { badges.appendChild( el( 'span', 'csm-p-badge', 'Photo blurred' ) ); }
		if ( badges.children.length ) { head.appendChild( badges ); }

		/* Owner asked for "an option to view my own profile i.e. how it looks to
		   others". The destination was already right — the label was not: "public
		   profile" describes a page, not the question being asked. */
		head.appendChild( link( 'csm-p-public', d.links.preview || d.links.public, 'See how my profile looks to others' ) );
		root.appendChild( head );
		var bc = boostCard( d.boost );
		if ( bc ) { root.appendChild( bc ); }
		if ( d.reach ) { root.appendChild( reachCard( d.reach ) ); }
		if ( d.astroAsk ) { root.appendChild( astroCard( d.astroAsk ) ); }

		/* ---- verification told plainly ----
		   The badge alone cannot carry a reason, and a rejection without one
		   leaves the member guessing at what was wrong with their document. */
		if ( 'rejected' === d.caState && d.caNote ) {
			var vwarn = link( 'csm-p-nudge is-warn', d.links.verify );
			var vtxt = el( 'span', 'csm-p-nudge-text' );
			vtxt.appendChild( el( 'strong', null, 'ICAI verification was not accepted' ) );
			vtxt.appendChild( el( 'span', null, d.caNote ) );
			vwarn.appendChild( vtxt );
			vwarn.appendChild( el( 'span', 'csm-p-nudge-cta', 'Re-upload' ) );
			root.appendChild( vwarn );
		} else if ( 'review' === d.caState && d.caNote ) {
			// Told plainly that a person has it, so "in review" stops reading as
			// a promise that quietly never arrives.
			var vinfo = el( 'div', 'csm-p-nudge is-info' );
			var itxt = el( 'span', 'csm-p-nudge-text' );
			itxt.appendChild( el( 'strong', null, 'Your ICAI document is with our team' ) );
			itxt.appendChild( el( 'span', null, d.caNote ) );
			vinfo.appendChild( itxt );
			root.appendChild( vinfo );
		}

		/* ---- a photo too small to look sharp ----
		   Older uploads were cropped to 150px by BuddyPress, which is a 3x
		   upscale on a card. Nothing server-side can recover that — the original
		   was deleted at upload — so the only fix is a new photo, and the member
		   is the only one who can supply it. */
		if ( d.lowResPhoto ) {
			var warn = link( 'csm-p-nudge is-warn', d.links.photos );
			var wtxt = el( 'span', 'csm-p-nudge-text' );
			wtxt.appendChild( el( 'strong', null, 'Your photo looks blurry to others' ) );
			wtxt.appendChild( el( 'span', null, 'It was saved at a small size. Upload it again from your phone for a sharp one.' ) );
			warn.appendChild( wtxt );
			warn.appendChild( el( 'span', 'csm-p-nudge-cta', 'Upload' ) );
			root.appendChild( warn );
		}

		/* ---- what is still missing ---- */
		if ( d.outstanding > 0 && d.firstGap ) {
			var nudge = link( 'csm-p-nudge', d.firstGap );
			var txt = el( 'span', 'csm-p-nudge-text' );
			txt.appendChild( el( 'strong', null, d.outstanding + ( 1 === d.outstanding ? ' detail left' : ' details left' ) ) );
			txt.appendChild( el( 'span', null, 'A fuller profile gets more matches.' ) );
			nudge.appendChild( txt );
			nudge.appendChild( el( 'span', 'csm-p-nudge-cta', 'Continue' ) );
			root.appendChild( nudge );
		}

		/* ---- sections ---- */
		var sec = el( 'section', 'csm-p-sections' );
		sec.appendChild( el( 'h2', 'csm-p-h', d.outstanding ? 'Complete your profile' : 'Profile details' ) );
		var list = el( 'ul', 'csm-p-list' );
		( d.sections || [] ).forEach( function ( s ) {
			var li = el( 'li', 'csm-p-row' );
			var a = link( '', s.url );
			a.appendChild( el( 'span', 'csm-p-label', s.name ) );
			a.appendChild( el( 'span', 'csm-p-state' + ( s.missing ? ' is-todo' : ' is-done' ),
				s.missing ? s.missing + ' left' : 'Complete' ) );
			var chev = el( 'span', 'csm-p-chev' );
			chev.innerHTML = chevron;
			a.appendChild( chev );
			li.appendChild( a );
			list.appendChild( li );
		} );
		sec.appendChild( list );
		root.appendChild( sec );

		/*
		 * Everything else about me, in sections (v1.90.7): the same list the
		 * photo menu in the top bar draws on (Core\AccountMenu), so the two
		 * cannot disagree. The Settings page is folded in here. Each section
		 * has an id (#membership, #privacy, #account ...) for links into it.
		 */
		( d.menu || [] ).forEach( function ( m ) {
			var rows = ( m.rows || [] ).filter( function ( r ) { return 'Edit profile' !== r.label; } ); // the list above is Edit profile
			if ( ! rows.length ) { return; }
			var box = el( 'section', 'csm-p-sections' );
			box.id = m.key;
			box.appendChild( el( 'h2', 'csm-p-h', m.title ) );
			var ul = el( 'ul', 'csm-p-list' );
			rows.forEach( function ( r ) {
				var li = el( 'li', 'csm-p-row' + ( r.cta ? ' is-cta' : '' ) + ( r.out ? ' is-out' : '' ) );
				var inner = r.url ? link( '', r.url ) : el( 'div', 'csm-p-static' );
				inner.appendChild( el( 'span', 'csm-p-label', r.label ) );
				if ( r.value ) {
					inner.appendChild( el( 'span', 'csm-p-sub' + ( false === r.ok ? ' is-warn' : ( true === r.ok ? ' is-ok' : '' ) ), r.value ) );
				}
				if ( r.url && ! r.out ) {
					var chev = el( 'span', 'csm-p-chev' );
					chev.innerHTML = chevron;
					inner.appendChild( chev );
				}
				li.appendChild( inner );
				ul.appendChild( li );
			} );
			box.appendChild( ul );
			root.appendChild( box );
		} );
		// Arrived from the menu with a section in the address: go there.
		if ( window.location.hash ) {
			var target = document.getElementById( window.location.hash.slice( 1 ) );
			if ( target ) { setTimeout( function () { target.scrollIntoView( { block: 'start' } ); }, 0 ); }
		}
	}

	function fail() {
		root.innerHTML = '';
		var box = el( 'div', 'csm-p-empty' );
		box.appendChild( el( 'h2', null, 'Something went wrong' ) );
		box.appendChild( el( 'p', null, 'We could not load your profile. Please refresh.' ) );
		root.appendChild( box );
	}

	fetch( CFG.me, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) { if ( d && d.ok ) { draw( d ); } else { fail(); } } )
		.catch( fail );
} )();
