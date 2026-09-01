/**
 * Profile — the member's own hub.
 *
 * Completion leads, because on a matrimonial app the useful question on your own
 * profile is "what do I still need to do to get matches", not "what does my
 * profile say" — the public view is one tap away for that.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_PROFILE;
	var root = document.getElementById( 'csm-profile-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function link( cls, href, text ) {
		var a = document.createElement( 'a' );
		a.className = cls;
		a.href = href;
		if ( text !== undefined ) { a.textContent = text; }
		return a;
	}

	var chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

	function draw( d ) {
		root.innerHTML = '';

		/* ---- who you are ---- */
		var head = el( 'section', 'csm-p-head' );
		var av = link( 'csm-p-avatar', d.links.photos );
		var img = el( 'img' );
		img.src = d.avatar; img.alt = '';
		av.appendChild( img );
		if ( ! d.hasPhoto ) { av.appendChild( el( 'span', 'csm-p-avatar-add', 'Add a photo' ) ); }
		head.appendChild( av );

		head.appendChild( el( 'h1', 'csm-p-name', d.name + ( d.age ? ', ' + d.age : '' ) ) );
		if ( d.city ) { head.appendChild( el( 'p', 'csm-p-city', d.city ) ); }

		var badges = el( 'div', 'csm-p-badges' );
		if ( d.verified ) { badges.appendChild( el( 'span', 'csm-p-badge is-verified', 'Verified CA' ) ); }
		if ( d.isPremium ) { badges.appendChild( el( 'span', 'csm-p-badge is-premium', 'Premium' ) ); }
		if ( d.blurred ) { badges.appendChild( el( 'span', 'csm-p-badge', 'Photo blurred' ) ); }
		if ( badges.children.length ) { head.appendChild( badges ); }

		head.appendChild( link( 'csm-p-public', d.links.public, 'View my public profile' ) );
		root.appendChild( head );

		/* ---- what is still missing ---- */
		if ( d.outstanding > 0 && d.firstGap ) {
			var nudge = link( 'csm-p-nudge', d.firstGap );
			var txt = el( 'span', 'csm-p-nudge-text' );
			txt.appendChild( el( 'strong', null, d.outstanding + ( 1 === d.outstanding ? ' detail left' : ' details left' ) ) );
			txt.appendChild( el( 'span', null, 'A fuller profile gets more matches.' ) );
			nudge.appendChild( txt );
			nudge.appendChild( el( 'span', 'csm-p-nudge-cta', 'Continue' ) );
			root.appendChild( nudge );
		}

		/* ---- sections ---- */
		var sec = el( 'section', 'csm-p-sections' );
		sec.appendChild( el( 'h2', 'csm-p-h', 'Your profile' ) );
		var list = el( 'ul', 'csm-p-list' );
		( d.sections || [] ).forEach( function ( s ) {
			var li = el( 'li', 'csm-p-row' );
			var a = link( '', s.url );
			a.appendChild( el( 'span', 'csm-p-label', s.name ) );
			a.appendChild( el( 'span', 'csm-p-state' + ( s.missing ? ' is-todo' : ' is-done' ),
				s.missing ? s.missing + ' left' : 'Complete' ) );
			var chev = el( 'span', 'csm-p-chev' );
			chev.innerHTML = chevron;
			a.appendChild( chev );
			li.appendChild( a );
			list.appendChild( li );
		} );
		sec.appendChild( list );
		root.appendChild( sec );

		/* ---- manage ---- */
		var manage = el( 'section', 'csm-p-sections' );
		manage.appendChild( el( 'h2', 'csm-p-h', 'Manage' ) );
		var mlist = el( 'ul', 'csm-p-list' );
		var rows = [
			[ 'My photos', d.links.photos ],
			[ 'Settings', d.links.settings ]
		];
		if ( ! d.isPremium ) { rows.push( [ 'Upgrade to Premium', d.links.upgrade ] ); }
		rows.forEach( function ( r ) {
			var li = el( 'li', 'csm-p-row' );
			var a = link( '', r[1] );
			a.appendChild( el( 'span', 'csm-p-label', r[0] ) );
			var chev = el( 'span', 'csm-p-chev' );
			chev.innerHTML = chevron;
			a.appendChild( chev );
			li.appendChild( a );
			mlist.appendChild( li );
		} );
		manage.appendChild( mlist );
		root.appendChild( manage );
	}

	function fail() {
		root.innerHTML = '';
		var box = el( 'div', 'csm-p-empty' );
		box.appendChild( el( 'h2', null, 'Something went wrong' ) );
		box.appendChild( el( 'p', null, 'We could not load your profile. Please refresh.' ) );
		root.appendChild( box );
	}

	fetch( CFG.me, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) { if ( d && d.ok ) { draw( d ); } else { fail(); } } )
		.catch( fail );
} )();
