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
