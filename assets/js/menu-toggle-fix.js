/**
 * BuddyX menu-toggle double-fire fix — migrated from WPCode #11674.
 *
 * The theme (or a plugin extending it) binds more than one jQuery click handler
 * to #menu-toggle, so a single tap opened and immediately closed the menu. This
 * de-duplicates identical handlers by their source text, keeping the first of
 * each and dropping exact repeats.
 *
 * Only relevant on BuddyX-rendered pages. The app screens
 * (Discover/Requests/Profile/Settings/Welcome) own their own header and menu —
 * see app-screens.js — so nothing here touches them.
 *
 * Carried across verbatim: it reads jQuery's private $._data event registry,
 * which is fragile, but the alternative is diagnosing a theme bug we are on our
 * way out of. It no-ops safely when jQuery, the element, or a duplicate handler
 * is absent.
 */
( function () {
	function dedupe() {
		var $ = window.jQuery;
		if ( ! $ ) { return; }
		var t = document.getElementById( 'menu-toggle' );
		if ( ! t ) { return; }
		var ev = $._data( t, 'events' );
		if ( ! ev || ! ev.click || ev.click.length <= 1 ) { return; }

		var seen = {}, keep = [];
		ev.click.forEach( function ( h ) {
			var k = h.handler.toString();
			if ( ! seen[ k ] ) { seen[ k ] = 1; keep.push( h.handler ); }
		} );
		$( t ).off( 'click' );
		keep.forEach( function ( fn ) { $( t ).on( 'click', fn ); } );
	}

	if ( 'complete' === document.readyState ) {
		setTimeout( dedupe, 300 );
	} else {
		window.addEventListener( 'load', function () { setTimeout( dedupe, 300 ); } );
	}
} )();
