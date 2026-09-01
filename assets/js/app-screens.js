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
