/**
 * "How others see me" — the member's own Discover card.
 *
 * Uses the same markup and stylesheet as Discover, because the point is to show
 * what other members actually see, not an approximation of it. The server
 * resolves visibility as a stranger, so restricted fields are absent here just
 * as they are for anyone else.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_PREVIEW;
	var root = document.getElementById( 'csm-preview-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function draw( p ) {
		root.innerHTML = '';

		var note = el( 'p', 'csm-pv-note', 'This is your profile as another member sees it. Anything you set to “Only me” is hidden here too.' );
		root.appendChild( note );

		var card = el( 'article', 'csm-d-card' );

		var media = el( 'div', 'csm-d-media' );
		var img = el( 'img', 'csm-d-photo' );
		img.src = p.avatar; img.alt = '';
		media.appendChild( img );
		if ( p.verified ) { media.appendChild( el( 'span', 'csm-d-verified', 'Verified CA' ) ); }
		var over = el( 'div', 'csm-d-over' );
		over.appendChild( el( 'h1', 'csm-d-name', p.name + ( p.age ? ', ' + p.age : '' ) ) );
		var sub = [ p.job, p.city ].filter( Boolean ).join( ' · ' );
		if ( sub ) { over.appendChild( el( 'p', 'csm-d-sub', sub ) ); }
		media.appendChild( over );
		card.appendChild( media );

		if ( p.bio ) {
			var bio = el( 'section', 'csm-d-bio' );
			bio.appendChild( el( 'p', null, p.bio ) );
			card.appendChild( bio );
		}

		( p.groups || [] ).forEach( function ( g ) {
			var sec = el( 'section', 'csm-d-group' );
			sec.appendChild( el( 'h2', 'csm-d-group-h', g.name ) );
			var dl = el( 'dl', 'csm-d-fields' );
			g.fields.forEach( function ( f ) {
				dl.appendChild( el( 'dt', null, f.label ) );
				dl.appendChild( el( 'dd', null, f.value ) );
			} );
			sec.appendChild( dl );
			card.appendChild( sec );
		} );

		// An empty preview is information, not a broken page — say so.
		if ( ! ( p.groups || [] ).length && ! p.bio ) {
			var empty = el( 'section', 'csm-d-group' );
			empty.appendChild( el( 'p', 'csm-pv-empty', 'Other members can only see your name and photo so far. Add details to give them a reason to say yes.' ) );
			card.appendChild( empty );
		}

		root.appendChild( card );

		var back = document.createElement( 'a' );
		back.className = 'csm-pv-back';
		back.href = CFG.hub;
		back.textContent = '← Back to my profile';
		root.appendChild( back );
	}

	fetch( CFG.get, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) {
			if ( d && d.ok ) { return draw( d.profile ); }
			root.innerHTML = '<p class="csm-app-loading">Could not load the preview. Please refresh.</p>';
		} )
		.catch( function () {
			root.innerHTML = '<p class="csm-app-loading">Could not load the preview. Please refresh.</p>';
		} );
} )();
