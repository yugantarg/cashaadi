/**
 * Shared behaviour for every app screen: the header menu.
 *
 * Deliberately tiny. The old BuddyX hamburger opened a panel whose contents the
 * app-shell CSS had hidden, so it appeared to open nothing; this owns both the
 * button and the panel, so they cannot disagree.
 */
/* Tag an upgrade link with where it was tapped, for the pricing report
   (Analytics\\Journey reads ?src=; v1.89.30). */
window.csmSrc = function ( url, src ) {
	if ( ! url ) { return url; }
	return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'src=' + encodeURIComponent( src );
};

( function () {
	'use strict';

	var btn  = document.getElementById( 'csm-app-menu-btn' );
	var menu = document.getElementById( 'csm-app-menu' );
	if ( ! btn || ! menu ) { return; }

	function setOpen( open ) {
		menu.hidden = ! open;
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		btn.classList.toggle( 'is-open', !! open );
	}

	btn.addEventListener( 'click', function () {
		setOpen( menu.hidden );
	} );

	// Tapping anywhere else closes it — on a phone there is no other way out.
	document.addEventListener( 'click', function ( e ) {
		if ( menu.hidden ) { return; }
		if ( menu.contains( e.target ) || btn.contains( e.target ) ) { return; }
		setOpen( false );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) { setOpen( false ); }
	} );
} )();

/**
 * The photo stack, shared by every screen that shows somebody's card.
 *
 * It lives here rather than in discover-app.js because Discover and the "how
 * others see me" preview render the SAME markup (.csm-d-media / .csm-d-photo)
 * from the SAME payload, and the first version of this shipped only to Discover
 * — so the preview kept drawing a single photo and a member with three of them
 * was told that is what strangers see. That is worse than a missing feature: the
 * preview's whole job is to be truthful about what other people get.
 *
 * Call it with the media element and the photos array. Fewer than two photos and
 * it does nothing, so callers never have to check.
 *
 * Tap zones rather than a swipe: these cards SCROLL — they are the whole
 * profile, not a poster — and a horizontal swipe handler on a vertically
 * scrolling element either steals the scroll or needs an axis-lock heuristic
 * that is wrong often enough to feel broken. Only the next image is preloaded.
 *
 * @param {HTMLElement} media  The .csm-d-media wrapper.
 * @param {string[]}    shots  Photo URLs, main first.
 */
window.csmPhotoStack = function ( media, shots ) {
	shots = ( shots && shots.length ) ? shots : [];
	if ( ! media || shots.length < 2 ) { return; }

	var img = media.querySelector( '.csm-d-photo' );
	if ( ! img ) { return; }

	var shot = 0;
	media.classList.add( 'has-stack' );

	var pips = document.createElement( 'div' );
	pips.className = 'csm-d-pips';
	shots.forEach( function ( _, i ) {
		var pip = document.createElement( 'span' );
		pip.className = 'csm-d-pip' + ( i === 0 ? ' is-on' : '' );
		pips.appendChild( pip );
	} );
	media.appendChild( pips );

	function preload( i ) {
		if ( i >= 0 && i < shots.length ) { ( new Image() ).src = shots[ i ]; }
	}
	preload( 1 );

	function show( i ) {
		if ( i < 0 || i >= shots.length || i === shot ) { return; }
		shot    = i;
		img.src = shots[ i ];
		Array.prototype.forEach.call( pips.children, function ( pip, n ) {
			pip.classList.toggle( 'is-on', n === i );
		} );
		preload( i + 1 );
	}

	function zone( cls, label, delta ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'csm-d-tap ' + cls;
		b.setAttribute( 'aria-label', label );
		b.onclick = function () { show( shot + delta ); };
		media.appendChild( b );
	}
	zone( 'csm-d-tap-prev', 'Previous photo', -1 );
	zone( 'csm-d-tap-next', 'Next photo', 1 );
};

/**
 * Member photos: suppress the native save affordances.
 *
 * Long-press on mobile and right-click on desktop both hand over the
 * ORIGINAL file, which carries no watermark (the mark is CSS). Blocking
 * the menu stops the casual save. It is deliberately not a security
 * control — the URL is still readable in devtools.
 */
( function () {
	var GUARDED = '.csm-d-photo, .csm-card-photo, .csm-photo, img.avatar';

	function guarded( e ) {
		var t = e.target;
		return t && t.closest && t.closest( GUARDED );
	}

	document.addEventListener( 'contextmenu', function ( e ) {
		if ( guarded( e ) ) { e.preventDefault(); }
	} );

	document.addEventListener( 'dragstart', function ( e ) {
		if ( guarded( e ) ) { e.preventDefault(); }
	} );
}() );

/**
 * Mark a button busy while something is in flight.
 *
 * Returns a function that restores it. Sets aria-busy as well as the class, so
 * the state is announced rather than only drawn, and disables the control so a
 * second tap cannot fire a second request — the usual response to a button that
 * appears to do nothing.
 *
 *   var done = csmBusy( btn );
 *   fetch(...).finally( done );
 */
/*
 * "Add a note" before a request is sent (Premium, v1.85.0). Calls done(note)
 * with the note ('' for none), or nothing if the member closes it. The same
 * contact-details rule as the server, checked as they type.
 */
window.csmNoteSheet = function ( name, done ) {
	var mk = function ( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	};
	var back = mk( 'div', 'csm-note-back' );
	var box  = mk( 'div', 'csm-note' );
	box.appendChild( mk( 'h2', null, 'Add a note for ' + name + '?' ) );
	box.appendChild( mk( 'p', 'csm-note-hint', 'A line about why you are writing makes a request more likely to be accepted.' ) );
	var ta = mk( 'textarea', 'csm-note-text' );
	ta.maxLength = 200;
	ta.rows = 3;
	ta.placeholder = 'e.g. We are both from Pune and I liked what you wrote about travel.';
	box.appendChild( ta );
	var err = mk( 'p', 'csm-note-err' );
	box.appendChild( err );
	var row = mk( 'div', 'csm-note-act' );
	var skip = mk( 'button', 'csm-note-skip', 'Send without a note' );
	var send = mk( 'button', 'csm-note-send', 'Send request' );
	skip.type = send.type = 'button';
	row.appendChild( skip );
	row.appendChild( send );
	box.appendChild( row );
	back.appendChild( box );
	document.body.appendChild( back );
	ta.focus();
	var close = function () { if ( back.parentNode ) { back.parentNode.removeChild( back ); } };
	var bad = function ( v ) {
		return /\d[\d\s\-]{6,}\d/.test( v ) || /@|https?:|www\.|whats\s?app|insta(gram)?|telegram/i.test( v );
	};
	ta.addEventListener( 'input', function () {
		err.textContent = bad( ta.value ) ? 'Notes cannot include phone numbers, emails, links or social handles.' : '';
		send.disabled = !! err.textContent;
	} );
	skip.addEventListener( 'click', function () { close(); done( '' ); } );
	send.addEventListener( 'click', function () { if ( bad( ta.value ) ) { return; } close(); done( ta.value.trim() ); } );
	back.addEventListener( 'click', function ( e ) { if ( e.target === back ) { close(); } } );
};

window.csmBusy = function ( btn ) {
	if ( ! btn ) { return function () {}; }
	var wasDisabled = btn.disabled;
	btn.classList.add( 'is-busy' );
	btn.setAttribute( 'aria-busy', 'true' );
	btn.disabled = true;
	return function () {
		btn.classList.remove( 'is-busy' );
		btn.removeAttribute( 'aria-busy' );
		btn.disabled = wasDisabled;
	};
};

/**
 * Build a member's profile card.
 *
 * ONE renderer, shared. Discover, "how others see me" and the member view all
 * show the same card, and three copies of this markup is how they drift: today
 * the wizard and the profile editor disagreed about multi-select because each
 * had its own field rendering. Anything that must look identical should be built
 * in one place.
 *
 * Returns the <article>; callers append their own actions.
 *
 * @param {Object} p  A profile from Core\Profile::full().
 * @return {HTMLElement}
 */
/**
 * Publish the bottom nav's real height as --csm-nav-h.
 *
 * The Discover action bar is pinned above the nav, and its offset was the
 * hardcoded 62px in discover-app.css. The nav is not 62px: its height comes
 * from its own padding, a 22px icon, an 11px label and whatever
 * env(safe-area-inset-bottom) is on the device — so on most phones the two
 * did not meet and a strip of page showed between them. Owner: "there is a
 * slight gap between the like/pass bar and the menu with discover, requests
 * etc".
 *
 * Measuring beats guessing here because the correct number is different on
 * every handset, and a notched iPhone is not the same as an Android.
 */
( function () {
	function syncNavHeight() {
		var nav = document.querySelector( '.csm-app-nav' );
		if ( ! nav ) { return; }
		var h = Math.round( nav.getBoundingClientRect().height );
		if ( h > 0 ) {
			document.documentElement.style.setProperty( '--csm-nav-h', h + 'px' );
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', syncNavHeight );
	} else {
		syncNavHeight();
	}
	window.addEventListener( 'resize', syncNavHeight );
	window.addEventListener( 'orientationchange', syncNavHeight );
	// Fonts land after first paint and change the label's line box.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( syncNavHeight );
	}
}() );

window.csmProfileCard = function ( p ) {
	var mk = function ( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	};

	/*
	 * Phone number, as a plain row in its field's place (owner, 2026-10-25:
	 * "not highlighted as a box in the top"). The member's own number, an
	 * opened one with Call / WhatsApp, "View number" for Gold (or Premium
	 * where allowed), or "Upgrade to view".
	 */
	function phoneDd() {
		var dd = mk( 'dd', 'csm-d-phone' );
		var st = p.phone || {};
		var app = window.CSM_APP || {};
		if ( 'visible' === st.state || 'own' === st.state ) {
			// The number on one line, Call / WhatsApp as buttons under it (v1.89.64).
			dd.appendChild( mk( 'span', 'csm-d-phone-n', st.number ) );
			if ( 'visible' === st.state ) {
				var acts = mk( 'span', 'csm-d-phone-acts' );
				var call = mk( 'a', 'csm-d-phone-btn', 'Call' );
				call.href = 'tel:' + st.tel;
				var wa = mk( 'a', 'csm-d-phone-btn', 'WhatsApp' );
				wa.href = 'https://wa.me/' + st.wa;
				wa.target = '_blank';
				wa.rel = 'noopener';
				acts.appendChild( call );
				acts.appendChild( wa );
				dd.appendChild( acts );
			}
		} else if ( 'reveal' === st.state ) {
			// One tap shows the number and sends them a request; said once first.
			var rv = mk( 'button', 'csm-d-phone-a', 'View number' );
			rv.type = 'button';
			dd.appendChild( rv );
			var open = function () {
				rv.disabled = true;
				fetch( app.reveal || '/wp-json/csm/v1/phone/reveal', {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': app.nonce || '' },
					body: JSON.stringify( { id: p.id } )
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					if ( d && d.ok && d.phone ) {
						p.phone = d.phone;
						dd.replaceWith( phoneDd() );
						// The request was sent or accepted: the screen swaps its buttons (v1.89.65).
						document.dispatchEvent( new CustomEvent( 'csm:phone-opened', { detail: { id: p.id, result: d.result || 'requested' } } ) );
					} else {
						rv.disabled = false;
						if ( window.csmToast ) { window.csmToast( ( d && d.message ) || 'That did not work.', 'bad' ); }
					}
				} ).catch( function () { rv.disabled = false; } );
			};
			rv.addEventListener( 'click', function () {
				var seen = false;
				try { seen = !! localStorage.getItem( 'csm_phone_note' ); } catch ( e ) {}
				// They already asked: viewing accepts their request (v1.89.59). Said every time.
				if ( st.asked && window.csmConfirm ) {
					return window.csmConfirm( p.name + ' has sent you a request. Viewing their number accepts it, and you become a match.', { title: 'View number?', okText: 'View and accept', cancelText: 'Cancel' } )
						.then( function ( ok ) { if ( ok ) { open(); } } );
				}
				if ( seen || ! window.csmConfirm ) { return open(); }
				window.csmConfirm( 'Viewing a phone number sends ' + p.name + ' a match request from you, so they know who has their number.', { title: 'View number?', okText: 'View number', cancelText: 'Cancel' } )
					.then( function ( ok ) { if ( ok ) { try { localStorage.setItem( 'csm_phone_note', '1' ); } catch ( e ) {} open(); } } );
			} );
		} else if ( 'locked' === st.state && st.up && app.upgrade ) {
			// A Premium viewer's next step is Gold; say so.
			var up = mk( 'a', 'csm-d-phone-a', '\uD83D\uDD12 ' + ( app.isPremium ? 'Upgrade to Gold' : 'Upgrade' ) + ' to view' );
			up.href = window.csmSrc( app.upgrade, 'phone' );
			dd.appendChild( up );
		} else {
			dd.textContent = 'not_shared' === st.state ? 'Not shared'
				: ( st.soon ? '\uD83D\uDD12 Opens on launch day' : '\uD83D\uDD12 Hidden' );
		}
		return dd;
	}

	var card  = mk( 'article', 'csm-d-card' );
	var media = mk( 'div', 'csm-d-media' );
	var shots = ( p.photos && p.photos.length ) ? p.photos : [ p.avatar ];

	var img = mk( 'img', 'csm-d-photo' );
	img.src = shots[ 0 ];
	img.alt = '';
	media.appendChild( img );

	if ( window.csmPhotoStack ) { window.csmPhotoStack( media, shots ); }
	if ( p.verified ) { media.appendChild( mk( 'span', 'csm-d-verified', p.verifiedLabel || 'Verified CA' ) ); }
	if ( p.gold ) { media.appendChild( mk( 'span', 'csm-d-goldbadge', 'Gold' ) ); }

	/*
	 * A blurred photo, said out loud. Without this the viewer sees an indistinct
	 * picture and no reason for it — and the two ways to see the real one (match,
	 * or Premium) are nowhere on the screen. Small and low-contrast on purpose:
	 * it explains a state, it is not a call to action competing with Like.
	 * The copy comes from the server (Photos\Gallery), gendered and aware of any
	 * request already in flight.
	 */
	/*
	 * No photo at all — a normal state since photos stopped being mandatory.
	 * Say so, and offer the one thing the viewer can actually do: ask. Without
	 * this the card shows a default silhouette and reads as broken.
	 */
	if ( p.photoMissing && p.askPhoto ) {
		var ask = mk( 'button', 'csm-d-ask' + ( p.verified ? '' : ' is-top' ) );
		ask.type = 'button';
		ask.disabled = ( 'pending' === p.askPhoto );
		ask.textContent = ( 'pending' === p.askPhoto ) ? 'Photo asked for' : 'Ask for a photo';
		ask.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();          // the photo stack pages on tap
			if ( ask.disabled ) { return; }
			ask.disabled = true;
			fetch( ( window.CSM_APP && window.CSM_APP.askPhoto ) || '/wp-json/csm/v1/photo-request', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ( window.CSM_APP && window.CSM_APP.nonce ) || ''
				},
				body: JSON.stringify( { owner: p.id } )
			} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
				ask.textContent = ( d && d.ok ) ? 'Photo asked for' : ( ( d && d.message ) || 'That did not work' );
				ask.disabled = !! ( d && d.ok );
			} ).catch( function () {
				ask.textContent = 'Network problem';
				ask.disabled = false;
			} );
		} );
		media.appendChild( ask );
	}

	if ( p.photoHidden ) {
		/*
		 * Take the corner when nothing else wants it. The button only sits
		 * lower to clear the Verified CA badge, so on a card without one that
		 * gap is just a hole. Decided here rather than in CSS because the
		 * markup already knows the answer — :has() would work but would make
		 * two places responsible for one fact.
		 */
		var reveal = mk( 'button', 'csm-d-reveal' + ( p.verified ? '' : ' is-top' ) );
		reveal.type = 'button';
		reveal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
		reveal.appendChild( mk( 'span', null, 'Unhide photo' ) );
		reveal.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();   // the photo stack pages on tap; this must not
			var note = p.photoNote || 'This member has chosen to blur their photo. Match with them, or upgrade to Premium, to see it.';
			if ( ! window.csmConfirm ) { window.alert( note ); return; }
			window.csmConfirm( note, {
				title: 'Photo is blurred',
				okText: 'See Premium',
				cancelText: 'Close'
			} ).then( function ( go ) {
				if ( go ) { window.location.href = window.csmSrc( p.upgradeUrl || '/membership-pricing/', 'photo-blur' ); }
			} );
		} );
		media.appendChild( reveal );
	}
	if ( p.isNew )   { media.appendChild( mk( 'span', 'csm-d-new', 'NEW' ) ); }

	var over = mk( 'div', 'csm-d-over' );
	over.appendChild( mk( 'h1', 'csm-d-name', p.name + ( p.age ? ', ' + p.age : '' ) ) );
	var sub = [ p.job, p.city ].filter( Boolean ).join( ' \u00b7 ' );
	if ( sub ) { over.appendChild( mk( 'p', 'csm-d-sub', sub ) ); }
	media.appendChild( over );
	card.appendChild( media );

	if ( p.bio ) {
		var bio = mk( 'section', 'csm-d-bio' );
		bio.appendChild( mk( 'p', null, p.bio ) );
		card.appendChild( bio );
	}

	( p.groups || [] ).forEach( function ( g ) {
		var sec = mk( 'section', 'csm-d-group' );
		sec.appendChild( mk( 'h2', 'csm-d-group-h', g.name ) );
		var dl = mk( 'dl', 'csm-d-fields' );
		( g.fields || [] ).forEach( function ( f ) {
			dl.appendChild( mk( 'dt', null, f.label ) );
			// The phone row is built from p.phone, not a value (v1.89.17).
			if ( f.phone ) { dl.appendChild( phoneDd() ); return; }
			/* LinkedIn / Instagram carry a url the server rebuilt from a validated
			   handle on a fixed https host (Core\Social::display), so it is safe
			   to use as an href. Anything else stays plain text. */
			var net = f.url && /^https:\/\/www\.(linkedin|instagram)\.com\//.exec( f.url );
			if ( net ) {
				/* The icon IS the link (owner, 2026-09-24): a full profile URL
				   overflowed the card. The handle stays in the accessible name
				   and the tooltip, so nothing is lost by not printing it. */
				var dd = mk( 'dd' );
				var a = document.createElement( 'a' );
				a.href = f.url;
				a.target = '_blank';
				a.rel = 'noopener nofollow ugc';
				a.className = 'csm-d-social is-' + net[1];
				a.setAttribute( 'aria-label', f.label + ': ' + f.value );
				a.title = f.value;
				a.innerHTML = 'linkedin' === net[1]
					? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="5" fill="currentColor"/><path fill="#fff" d="M7.1 9.6h-2.6V19h2.6V9.6zM5.8 5.2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM19.5 13.6c0-2.6-.6-4.2-3.4-4.2-1.4 0-2.3.7-2.7 1.4h-.1V9.6h-2.5V19h2.6v-4.6c0-1.2.2-2.4 1.8-2.4 1.5 0 1.5 1.4 1.5 2.5V19h2.6v-5.4z"/></svg>'
					: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="currentColor"/><rect x="5.5" y="5.5" width="13" height="13" rx="4" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="12" cy="12" r="3.1" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="16" cy="8" r="1.05" fill="#fff"/></svg>';
				dd.appendChild( a );
				dl.appendChild( dd );
			} else {
				dl.appendChild( mk( 'dd', null, f.value ) );
			}
		} );
		sec.appendChild( dl );
		card.appendChild( sec );
	} );

	/* Report profile (owner, 2026-10-12). One report hides the profile until
	   an admin reviews it. Reasons open inline: a dialog cannot hold a list. */
	var app = window.CSM_APP || {};
	if ( p.id && app.report && String( p.id ) !== String( app.me ) ) {
		var rep = mk( 'section', 'csm-d-report' );
		var open = mk( 'button', 'csm-d-report-open', 'Report profile' );
		open.type = 'button';
		rep.appendChild( open );
		open.addEventListener( 'click', function () {
			open.remove();
			rep.appendChild( mk( 'p', 'csm-d-report-q', 'What is wrong with this profile?' ) );
			[ [ 'fake', 'Fake profile or photo' ], [ 'content', 'Inappropriate photo or text' ], [ 'abusive', 'Rude or abusive' ], [ 'other', 'Something else' ] ].forEach( function ( r ) {
				var b = mk( 'button', 'csm-d-report-why', r[1] );
				b.type = 'button';
				b.addEventListener( 'click', function () {
					rep.querySelectorAll( 'button' ).forEach( function ( x ) { x.disabled = true; } );
					fetch( app.report, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': app.nonce || '' },
						body: JSON.stringify( { target: p.id, reason: r[0] } )
					} ).then( function ( x ) { return x.json(); } ).then( function ( d ) {
						rep.innerHTML = '';
						rep.appendChild( mk( 'p', 'csm-d-report-q', ( d && d.message ) || 'Thank you.' ) );
					} ).catch( function () {
						rep.querySelectorAll( 'button' ).forEach( function ( x ) { x.disabled = false; } );
					} );
				} );
				rep.appendChild( b );
			} );
		} );
		card.appendChild( rep );
	}

	return card;
};


/*
 * One-time popup: existing members can now add LinkedIn and Instagram
 * (owner, 2026-09-24). The server decides who is eligible; this only shows it,
 * never on the screens where the fields already are, and records it as seen
 * the moment it appears so nobody is asked twice.
 */
( function () {
	var cfg = window.CSM_APP && window.CSM_APP.socialIntro;
	if ( ! cfg || ! cfg.url || typeof window.csmConfirm !== 'function' ) { return; }
	if ( /^\/(welcome|profile\/edit)\b/.test( window.location.pathname ) ) { return; }

	setTimeout( function () {
		try {
			fetch( cfg.seen, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CSM_APP.nonce }
			} ).catch( function () {} );
		} catch ( e ) {}

		window.csmConfirm(
			'You can now add your LinkedIn and Instagram to your profile, right under your Bio. Adding these improves your chances of getting a compatible match.',
			{ title: 'New: LinkedIn and Instagram', okText: 'Add them', cancelText: 'Not now' }
		).then( function ( yes ) {
			if ( yes ) { window.location.href = cfg.url; }
		} );
	}, 1200 );
} )();

/*
 * Sampled one-time popup: "get your Verified CA badge" (owner, 2026-10-01).
 * The server picks the sample and records the arm; this only shows it, never
 * on the screens where the upload or the wizard already is, records it as
 * seen the moment it appears, and records a tap on "Upload now" separately
 * so the test can tell a click from an upload.
 */
( function () {
	var cfg = window.CSM_APP && window.CSM_APP.verifyNudge;
	if ( ! cfg || ! cfg.url || typeof window.csmConfirm !== 'function' ) { return; }
	if ( /^\/(welcome|profile\/edit)\b/.test( window.location.pathname ) ) { return; }

	function mark( clicked ) {
		try {
			return fetch( cfg.seen + ( clicked ? '?clicked=1' : '' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CSM_APP.nonce }
			} ).catch( function () {} );
		} catch ( e ) {
			return Promise.resolve();
		}
	}

	setTimeout( function () {
		mark( false );
		window.csmConfirm(
			'Upload your ICAI certificate, marksheet or membership card (PDF, JPG or PNG) and your profile shows the Verified CA badge. Members trust verified profiles more, and it usually takes under an hour. Your document is never shared with any other member; they only see the Verified tag.',
			{ title: 'Get your Verified CA badge', okText: 'Upload now', cancelText: 'Not now' }
		).then( function ( yes ) {
			if ( ! yes ) { return; }
			var go = function () { window.location.href = cfg.url; };
			var p  = mark( true );
			// Never hold the member on a slow network for a statistic.
			setTimeout( go, 800 );
			if ( p && p.then ) { p.then( go, go ); }
		} );
	}, 1200 );
} )();

/*
 * One-time popup: free women now get 15 profiles a week (owner, 2026-10-03).
 * Only for women who joined before the change; the server decides who and
 * when. Marked seen the moment it is shown.
 */
( function () {
	var cfg = window.CSM_APP && window.CSM_APP.ff15;
	if ( ! cfg || typeof window.csmConfirm !== 'function' ) { return; }
	if ( /^\/(welcome|profile\/edit)\b/.test( window.location.pathname ) ) { return; }

	setTimeout( function () {
		try {
			fetch( cfg.seen, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CSM_APP.nonce }
			} ).catch( function () {} );
		} catch ( e ) {}

		window.csmConfirm(
			'From this week you will see ' + cfg.quota + ' new profiles every week in Discover, instead of 5. Free, nothing to do.',
			{ title: 'Now ' + cfg.quota + ' profiles a week', okText: 'See them', cancelText: 'Later' }
		).then( function ( yes ) {
			if ( yes && ! /^\/discover\b/.test( window.location.pathname ) ) { window.location.href = cfg.url; }
		} );
	}, 1200 );
} )();

/*
 * "N match requests waiting" (owner, 2026-10-09). Shown again only when a new
 * request has arrived since; marked seen the moment it is shown.
 */
( function () {
	var cfg = window.CSM_APP && window.CSM_APP.reqsPopup;
	if ( ! cfg || typeof window.csmConfirm !== 'function' ) { return; }

	setTimeout( function () {
		try {
			fetch( cfg.seen, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CSM_APP.nonce }
			} ).catch( function () {} );
		} catch ( e ) {}

		window.csmConfirm( cfg.body, { title: cfg.title, okText: 'Review now', cancelText: 'Later' } )
			.then( function ( yes ) { if ( yes ) { window.location.href = cfg.url; } } );
	}, 1200 );
} )();

/*
 * One-time popup: "Get 10 more profiles this week" for a photo and CA
 * verification (owner, 2026-10-04). The server decides who and what is left;
 * marked seen the moment it is shown. The same offer is repeated on the
 * Discover card and at the end of the week's profiles.
 */
( function () {
	var cfg = window.CSM_APP && window.CSM_APP.boostPopup;
	if ( ! cfg || typeof window.csmConfirm !== 'function' ) { return; }
	if ( /^\/(welcome|profile\/edit)\b/.test( window.location.pathname ) ) { return; }

	setTimeout( function () {
		try {
			fetch( cfg.seen, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CSM_APP.nonce }
			} ).catch( function () {} );
		} catch ( e ) {}

		window.csmConfirm( cfg.body, { title: cfg.title, okText: 'Do it now', cancelText: 'Later' } )
			.then( function ( yes ) { if ( yes ) { window.location.href = cfg.url; } } );
	}, 1200 );
} )();

/**
 * The filter button and sheet, shared by Discover and Requests (v1.89.13;
 * owner: "exactly the same as discover, we dont need two separate codes").
 *
 * window.csmFilterBar( {
 *   state:     Filters::state() from the server,
 *   url:       the csm/v1/discover/filters endpoint,
 *   nonce:     the REST nonce,
 *   upgrade:   where a free member's locked button goes,
 *   hint:      optional line under the sheet's title,
 *   onApplied: called with the new state after Apply or Clear
 * } ) returns the button row, or null.
 */
( function () {
	'use strict';

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function bar( o ) {
		var meta = { filters: o.state, upgrade: o.upgrade };

		function api( url, payload ) {
			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': o.nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} ).then( function ( r ) { return r.json(); } );
		}

	function filterBar() {
		var f = meta.filters;
		if ( ! f ) { return null; }
		var wrap = el( 'div', 'csm-d-filterbar' );
		/* Free members see the control locked: filters are a Premium feature. */
		if ( ! f.eligible ) {
			if ( ! meta.upgrade ) { return null; }
			var lock = el( 'a', 'csm-d-filterbtn is-locked', '\uD83D\uDD12 Filter by community, religion, age\u2026 with Premium' );
			lock.href = window.csmSrc( meta.upgrade, o.src || 'filter' );
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
		if ( o.hint ) { box.appendChild( el( 'p', 'csm-d-sheethint', o.hint ) ); }

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
			api( o.url, payload ).then( function ( d ) {
				if ( ! d || ! d.ok ) {
					err.textContent = ( d && d.error ) || 'That did not save. Try again.';
					save.disabled = false; save.textContent = 'Apply';
					return;
				}
				meta.filters = d;
				close();
				if ( o.onApplied ) { o.onApplied( d ); }
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

		return filterBar();
	}

	/* Whether any filter is set in a saved-filters object. */
	bar.isOn = function ( state ) {
		var c = ( state && state.filters ) || {};
		if ( c.age_min || c.in_min ) { return true; }
		return ( ( state && state.choices ) || [] ).some( function ( ch ) {
			return c[ ch.key ] && ( 'same' === c[ ch.key ] || c[ ch.key ].length );
		} );
	};

	/*
	 * "Show all": a small button that clears every filter, then calls done
	 * (v1.89.19; owner: "7 hidden by your filters · show all").
	 */
	bar.showAll = function ( url, nonce, done ) {
		var b = el( 'button', 'csm-filter-showall', 'Show all' );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			b.disabled = true;
			b.textContent = 'Clearing\u2026';
			fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body: '{}'
			} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
				if ( d && d.ok ) { done( d ); return; }
				b.disabled = false; b.textContent = 'Show all';
			} ).catch( function () { b.disabled = false; b.textContent = 'Show all'; } );
		} );
		return b;
	};

	window.csmFilterBar = bar;
} )();

/*
 * Profile deck shared by Discover's buttons and the Requests screen
 * (v1.89.40; owner: "requests should be formatted similar to the discover
 * page", with labelled buttons and previous/next).
 *
 *   csmDeck.icons            pass / save / like / prev / next SVGs
 *   csmDeck.button( cls, label, svg )  a round button with its label under it;
 *                            the element returned is the wrapper, .btn the button
 *   csmDeck.tiles( list, open )        the Discover-style grid; open( index )
 *   csmDeck.open( root, o )  one profile with an action bar:
 *       o.list       profiles (Profile::full shape)
 *       o.index      which to show first
 *       o.actions    function ( p ) -> [ { cls, label, icon, run( p ) -> Promise } ]
 *       o.backLabel  text of the back link;  o.onBack()  back to the list
 *     A finished action removes the profile and moves to the next one.
 */
( function () {
	'use strict';

	function mk( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	var icons = {
		pass: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
		save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>',
		like: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>',
		chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
		prev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',
		next: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'
	};

	function button( cls, label, svg ) {
		var w = mk( 'div', 'csm-d-actw' );
		var b = mk( 'button', 'csm-d-btn ' + cls );
		b.type = 'button';
		b.setAttribute( 'aria-label', label );
		b.innerHTML = svg;
		w.appendChild( b );
		w.appendChild( mk( 'span', 'csm-d-actl', label ) );
		w.btn = b;
		return w;
	}

	function tiles( list, open ) {
		var grid = mk( 'div', 'csm-d-grid' );
		list.forEach( function ( p, i ) {
			var cell = mk( 'button', 'csm-d-tile' );
			cell.type = 'button';
			var ph = mk( 'div', 'csm-d-tilephoto' );
			if ( p.avatar ) {
				var img = document.createElement( 'img' );
				img.src = p.avatar;
				img.alt = '';
				img.loading = i < 4 ? 'eager' : 'lazy';
				ph.appendChild( img );
			}
			if ( p.priority ) { ph.appendChild( mk( 'span', 'csm-d-tilenew is-gold', 'Gold' ) ); }
			cell.appendChild( ph );
			cell.appendChild( mk( 'span', 'csm-d-tilename', p.name || 'Member' ) );
			cell.appendChild( mk( 'span', 'csm-d-tilemeta', [ p.age, p.height, p.city ].filter( Boolean ).join( '  ·  ' ) ) );
			cell.addEventListener( 'click', function () { open( i ); } );
			grid.appendChild( cell );
		} );
		return grid;
	}

	function open( root, o ) {
		var list = o.list, idx = o.index || 0;
		document.body.classList.add( 'csm-member-view' ); // the bar's clearance (discover-app.css)

		var changed = false;
		document.addEventListener( 'csm:phone-opened', function ( e ) {
			var p = list[ idx ];
			if ( ! p || ! e.detail || e.detail.id !== p.id ) { return; }
			changed = true;
			decided( root.querySelector( '.csm-d-actions' ), e.detail.result );
		} );

		function leave() {
			document.body.classList.remove( 'csm-member-view' );
			// Something was decided from the phone row: the lists need reloading.
			if ( changed && o.onChange ) { return o.onChange(); }
			o.onBack();
		}

		function draw() {
			var p = list[ idx ];
			if ( ! p ) { return leave(); }
			root.innerHTML = '';
			var back = mk( 'button', 'csm-d-back' );
			back.type = 'button';
			back.innerHTML = icons.prev.replace( 'stroke-width="2.2"', 'stroke-width="2"' );
			back.appendChild( document.createTextNode( o.backLabel || 'Back' ) );
			back.addEventListener( 'click', leave );
			root.appendChild( back );
			// The note that came with the request (v1.85.0).
			if ( p.note ) { root.appendChild( mk( 'p', 'csm-r-note', '“' + p.note + '”' ) ); }
			root.appendChild( window.csmProfileCard( p ) );

			var bar = mk( 'div', 'csm-d-actions' );
			var prev = mk( 'button', 'csm-d-btn csm-d-nav csm-d-prev' );
			prev.type = 'button';
			prev.setAttribute( 'aria-label', 'Previous profile' );
			prev.innerHTML = icons.prev;
			prev.disabled = idx <= 0;
			prev.addEventListener( 'click', function () { idx--; draw(); } );
			var next = mk( 'button', 'csm-d-btn csm-d-nav csm-d-next' );
			next.type = 'button';
			next.setAttribute( 'aria-label', 'Next profile' );
			next.innerHTML = icons.next;
			next.disabled = idx >= list.length - 1;
			next.addEventListener( 'click', function () { idx++; draw(); } );

			bar.appendChild( prev );
			o.actions( p ).forEach( function ( a ) {
				var w = button( a.cls, a.label, a.icon );
				w.btn.addEventListener( 'click', function () {
					var release = window.csmBusy ? window.csmBusy( w.btn ) : function () {};
					a.run( p ).then( function ( ok ) {
						release();
						if ( ! ok ) { return; }
						list.splice( idx, 1 );
						if ( idx >= list.length ) { idx = list.length - 1; }
						if ( list.length ) { draw(); } else { leave(); }
					} );
				} );
				bar.appendChild( w );
			} );
			bar.appendChild( next );
			root.appendChild( bar );
			window.scrollTo( 0, 0 );
		}
		draw();
	}

	/*
	 * After a number was opened, the request is sent (or theirs accepted):
	 * replace the decision buttons in an action bar with what happened, keeping
	 * the previous / next arrows (v1.89.65).
	 */
	function decided( bar, result ) {
		if ( ! bar || bar.querySelector( '.csm-d-decided' ) ) { return; }
		Array.prototype.slice.call( bar.querySelectorAll( '.csm-d-actw' ) ).forEach( function ( w ) { w.parentNode.removeChild( w ); } );
		var note = mk( 'span', 'csm-d-decided', 'matched' === result ? '\u2713 You\u2019re a match' : '\u2713 Request sent' );
		var next = bar.querySelector( '.csm-d-next' );
		bar.insertBefore( note, next );
	}

	window.csmDeck = { icons: icons, button: button, tiles: tiles, open: open, decided: decided };
} )();
