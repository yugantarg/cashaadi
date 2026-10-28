/**
 * Landing page motion and the live activity line (v1.90.21).
 *
 * Reveal on scroll, a count-up on the numbers, the hero deck sliding a card
 * every few seconds, and one real activity line at a time from
 * csm/v1/landing/activity. Nothing moves for visitors who ask for reduced
 * motion; the activity line still changes, without the slide.
 */
( function () {
	'use strict';

	var CFG = window.CSM_LANDING || {};
	var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	document.body.classList.add( 'lp-js' );

	/* Reveal and count up as things scroll into view. */
	var items = [].slice.call( document.querySelectorAll( '.lp-in' ) );
	function count( b ) {
		var to = parseInt( b.getAttribute( 'data-count' ), 10 );
		if ( ! to || still ) { return; }
		var t0 = null;
		function tick( t ) {
			if ( ! t0 ) { t0 = t; }
			var k = Math.min( 1, ( t - t0 ) / 1400 );
			var v = Math.floor( to * ( 1 - Math.pow( 1 - k, 3 ) ) / 10 ) * 10;
			b.textContent = ( k < 1 ? v : to ).toLocaleString( 'en-IN' ) + '+';
			if ( k < 1 ) { requestAnimationFrame( tick ); }
		}
		requestAnimationFrame( tick );
	}
	function show( el ) {
		el.classList.add( 'is-in' );
		var b = el.querySelector( '[data-count]' );
		if ( b ) { count( b ); }
	}
	if ( 'IntersectionObserver' in window ) {
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( e ) {
				if ( e.isIntersecting ) { show( e.target ); io.unobserve( e.target ); }
			} );
		}, { threshold: 0.15 } );
		items.forEach( function ( el, i ) {
			el.style.transitionDelay = ( el.closest( '.lp-hero' ) ? i * 90 : 0 ) + 'ms';
			io.observe( el );
		} );
	} else {
		items.forEach( show );
	}

	/* The hero deck: the next card every 3.2 seconds. */
	var deck = document.getElementById( 'lp-deck' );
	if ( deck && ! still ) {
		var cards = [].slice.call( deck.querySelectorAll( '.lp-card' ) );
		var at = 0;
		// Photos after the first two load one card ahead of being shown.
		var load = function ( c ) {
			var ph = c && c.querySelector( '[data-bg]' );
			if ( ph ) { ph.style.backgroundImage = 'url("' + ph.getAttribute( 'data-bg' ) + '")'; ph.removeAttribute( 'data-bg' ); }
		};
		setInterval( function () {
			if ( document.hidden || cards.length < 2 ) { return; }
			var cur = cards[ at ];
			at = ( at + 1 ) % cards.length;
			load( cards[ at ] );
			load( cards[ ( at + 1 ) % cards.length ] );
			cur.classList.remove( 'is-top' );
			cur.classList.add( 'is-gone' );
			cards[ at ].classList.remove( 'is-gone' );
			cards[ at ].classList.add( 'is-top' );
			setTimeout( function () { cur.classList.remove( 'is-gone' ); }, 800 );
		}, 3200 );
	}

	/* The activity line: real events, one at a time. */
	var live = document.getElementById( 'lp-live' );
	if ( ! live || ! CFG.feed || ! window.fetch ) { return; }

	function ago( s ) {
		if ( s < 3600 ) { var m = Math.max( 1, Math.round( s / 60 ) ); return m + ( 1 === m ? ' min ago' : ' mins ago' ); }
		if ( s < 86400 ) { var h = Math.round( s / 3600 ); return h + ( 1 === h ? ' hour ago' : ' hours ago' ); }
		var d = Math.round( s / 86400 ); return d + ( 1 === d ? ' day ago' : ' days ago' );
	}
	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}
	function line( it, skew ) {
		live.innerHTML = '';
		var pic = el( 'i', 'match' === it.kind ? 'is-match' : '' );
		if ( 'match' === it.kind ) {
			pic.textContent = '♥';
		} else if ( it.photo ) {
			pic.style.backgroundImage = 'url("' + String( it.photo ).replace( /"/g, '' ) + '")';
		}
		live.appendChild( pic );
		var t = el( 'span' );
		if ( 'match' === it.kind ) {
			t.appendChild( document.createTextNode( 'A match was made' + ( it.city ? ' in ' : '' ) ) );
			if ( it.city ) { t.appendChild( el( 'b', null, it.city ) ); }
		} else {
			t.appendChild( el( 'b', null, it.name ) );
			t.appendChild( document.createTextNode( ' from ' + it.city + ' joined' ) );
		}
		t.appendChild( document.createTextNode( ' · ' ) );
		t.appendChild( el( 'em', null, ago( Math.max( 60, Math.floor( Date.now() / 1000 ) + skew - it.at ) ) ) );
		live.appendChild( t );
	}

	fetch( CFG.feed, { credentials: 'omit', cache: 'no-store' } ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
		var list = ( d && d.items ) || [];
		if ( ! list.length ) { return; }
		// Server clock minus ours, so "x mins ago" is right whatever the visitor's clock says.
		var skew = ( d.now || 0 ) ? d.now - Math.floor( Date.now() / 1000 ) : 0;
		var i = 0;
		line( list[ 0 ], skew );
		live.hidden = false;
		setInterval( function () {
			if ( document.hidden ) { return; }
			i = ( i + 1 ) % list.length;
			live.classList.add( 'is-out' );
			setTimeout( function () { line( list[ i ], skew ); live.classList.remove( 'is-out' ); }, still ? 0 : 450 );
		}, 4000 );
	} ).catch( function () {} );
} )();
