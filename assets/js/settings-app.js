/**
 * Settings — grouped hub.
 *
 * Rows only. Every editor behind them is BuddyPress's own (account security is
 * not worth re-implementing for visual consistency), so this renders the list
 * and links out; AppShell's back link covers those screens.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_SETTINGS;
	var root = document.getElementById( 'csm-settings-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	var chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

	function draw( d ) {
		root.innerHTML = '';

		( d.groups || [] ).forEach( function ( g ) {
			var sec = el( 'section', 'csm-st-group' );
			sec.appendChild( el( 'h2', 'csm-st-h', g.title ) );
			var list = el( 'ul', 'csm-st-list' );

			g.rows.forEach( function ( r ) {
				var li = el( 'li', 'csm-st-row' );
				// A row with no destination is a status readout, not a link — so it
				// must not look tappable.
				var box = r.url ? document.createElement( 'a' ) : el( 'div', 'csm-st-static' );
				if ( r.url ) { box.href = r.url; }

				box.appendChild( el( 'span', 'csm-st-label', r.label ) );
				if ( r.value ) {
					var cls = 'csm-st-value';
					if ( r.ok === true ) { cls += ' is-ok'; }
					if ( r.ok === false ) { cls += ' is-warn'; }
					box.appendChild( el( 'span', cls, r.value ) );
				}
				if ( r.url ) {
					var c = el( 'span', 'csm-st-chev' );
					c.innerHTML = chevron;
					box.appendChild( c );
				}
				li.appendChild( box );
				list.appendChild( li );
			} );

			sec.appendChild( list );
			root.appendChild( sec );
		} );

		var foot = el( 'div', 'csm-st-foot' );
		var out = el( 'a', 'csm-st-logout', 'Log out' );
		out.href = d.logout;
		foot.appendChild( out );

		if ( d.deleteUrl ) {
			var del = el( 'a', 'csm-st-danger', 'Delete my account' );
			del.href = d.deleteUrl;
			foot.appendChild( del );
		}
		root.appendChild( foot );
	}

	fetch( CFG.get, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) {
			if ( d && d.ok ) { return draw( d ); }
			root.innerHTML = '<p class="csm-app-loading">Could not load settings. Please refresh.</p>';
		} )
		.catch( function () {
			root.innerHTML = '<p class="csm-app-loading">Could not load settings. Please refresh.</p>';
		} );
} )();
