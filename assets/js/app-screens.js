/**
 * Shared behaviour for every app screen: the header menu.
 *
 * Deliberately tiny. The old BuddyX hamburger opened a panel whose contents the
 * app-shell CSS had hidden, so it appeared to open nothing; this owns both the
 * button and the panel, so they cannot disagree.
 */
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
window.csmProfileCard = function ( p ) {
	var mk = function ( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	};

	var card  = mk( 'article', 'csm-d-card' );
	var media = mk( 'div', 'csm-d-media' );
	var shots = ( p.photos && p.photos.length ) ? p.photos : [ p.avatar ];

	var img = mk( 'img', 'csm-d-photo' );
	img.src = shots[ 0 ];
	img.alt = '';
	media.appendChild( img );

	if ( window.csmPhotoStack ) { window.csmPhotoStack( media, shots ); }
	if ( p.verified ) { media.appendChild( mk( 'span', 'csm-d-verified', 'Verified CA' ) ); }

	/*
	 * A blurred photo, said out loud. Without this the viewer sees an indistinct
	 * picture and no reason for it — and the two ways to see the real one (match,
	 * or Premium) are nowhere on the screen. Small and low-contrast on purpose:
	 * it explains a state, it is not a call to action competing with Like.
	 * The copy comes from the server (Photos\Gallery), gendered and aware of any
	 * request already in flight.
	 */
	if ( p.photoHidden ) {
		var reveal = mk( 'button', 'csm-d-reveal' );
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
				if ( go ) { window.location.href = p.upgradeUrl || '/membership-pricing/'; }
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
			dl.appendChild( mk( 'dd', null, f.value ) );
		} );
		sec.appendChild( dl );
		card.appendChild( sec );
	} );

	return card;
};
