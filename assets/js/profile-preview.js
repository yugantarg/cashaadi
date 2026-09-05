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

		var card = window.csmProfileCard( p );

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
