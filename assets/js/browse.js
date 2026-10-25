/**
 * Browse all members (Gold, v1.88.0): a grid, 24 at a time, using the
 * member's Discover filters. Tapping a card opens their profile.
 */
( function () {
	var CFG  = window.CSM_BROWSE;
	var root = document.getElementById( 'csm-browse-app' );
	if ( ! CFG || ! root ) { return; }
	var page = 1;

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	var head = el( 'div', 'csm-b-head' );
	var grid = el( 'div', 'csm-b-grid' );
	var more = el( 'button', 'csm-b-more', 'Show more' );
	more.type = 'button';
	more.hidden = true;

	function load() {
		more.disabled = true;
		fetch( CFG.list + ( CFG.list.indexOf( '?' ) === -1 ? '?' : '&' ) + 'page=' + page, {
			credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce }
		} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
			if ( 1 === page ) {
				root.innerHTML = '';
				head.innerHTML = '';
				head.appendChild( el( 'h1', 'csm-b-title', 'All members' ) );
				var note = el( 'p', 'csm-b-note', d && d.filtered ? 'Using your Discover filters. ' : 'Everyone, most recently active first. ' );
				var a = el( 'a', null, d && d.filtered ? 'Change filters' : 'Add filters' );
				a.href = CFG.discover;
				note.appendChild( a );
				head.appendChild( note );
				root.appendChild( head );
				root.appendChild( grid );
				root.appendChild( more );
			}
			if ( ! d || ! d.ok ) {
				grid.appendChild( el( 'p', 'csm-b-note', ( d && d.message ) || 'Could not load members.' ) );
				return;
			}
			if ( 1 === page && ! d.items.length ) {
				grid.appendChild( el( 'p', 'csm-b-note', 'No members match your filters.' ) );
			}
			d.items.forEach( function ( p ) {
				var c = el( 'a', 'csm-b-card' );
				c.href = p.url;
				var img = el( 'img', 'csm-b-img' );
				img.src = p.avatar; img.alt = ''; img.loading = 'lazy';
				c.appendChild( img );
				if ( p.gold ) { c.appendChild( el( 'span', 'csm-b-gold', 'Gold' ) ); }
				var m = el( 'div', 'csm-b-meta' );
				m.appendChild( el( 'strong', null, p.name + ( p.age ? ', ' + p.age : '' ) ) );
				m.appendChild( el( 'span', null, [ p.city, p.verified ? 'Verified CA' : '' ].filter( Boolean ).join( ' · ' ) ) );
				c.appendChild( m );
				grid.appendChild( c );
			} );
			more.hidden = ! d.more;
			more.disabled = false;
		} ).catch( function () {
			root.innerHTML = '<p class="csm-app-loading">Could not load members.</p>';
		} );
	}

	more.addEventListener( 'click', function () { page++; load(); } );
	load();
} )();
