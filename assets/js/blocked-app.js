/**
 * Blocked members — in the app rather than BuddyPress's settings sub-tab.
 *
 * Rendering only. Unblocking calls the Block module, which owns the table and
 * the cache invalidation, so this screen cannot drift from what "blocked" means
 * anywhere else.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_BLOCKED;
	var root = document.getElementById( 'csm-blocked-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function api( opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = Object.assign( { 'X-WP-Nonce': CFG.nonce }, opts.headers || {} );
		return fetch( CFG.list, opts ).then( function ( r ) { return r.json(); } );
	}

	function draw( list ) {
		root.innerHTML = '';

		var back = el( 'a', 'csm-st-back', '← Back to settings' );
		back.href = CFG.settings;
		root.appendChild( back );

		if ( ! list.length ) {
			var empty = el( 'div', 'csm-st-empty' );
			empty.appendChild( el( 'h2', null, 'No one is blocked' ) );
			empty.appendChild( el( 'p', null, 'Members you block stop seeing you, and you stop seeing them.' ) );
			root.appendChild( empty );
			return;
		}

		var ul = el( 'ul', 'csm-st-list' );
		list.forEach( function ( m ) {
			var li = el( 'li', 'csm-st-row' );
			var wrap = el( 'div', 'csm-bl-person' );
			var img = el( 'img', 'csm-bl-avatar' );
			img.src = m.avatar; img.alt = '';
			wrap.appendChild( img );
			wrap.appendChild( el( 'span', 'csm-st-label', m.name ) );

			var btn = el( 'button', 'csm-bl-unblock', 'Unblock' );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				window.csmConfirm( m.name + ' will be able to see you again, and you will see them.', {
					title: 'Unblock ' + m.name + '?', okText: 'Unblock'
				} ).then( function ( yes ) {
					if ( ! yes ) { return; }
					btn.disabled = true;
					api( {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { user_id: m.id } )
					} ).then( function ( d ) {
						if ( d && d.ok ) {
							li.parentNode.removeChild( li );
							window.csmToast( m.name + ' unblocked.', 'ok' );
							if ( ! root.querySelectorAll( '.csm-st-row' ).length ) { draw( [] ); }
							return;
						}
						btn.disabled = false;
						window.csmToast( 'That did not work.', 'bad' );
					} ).catch( function () {
						btn.disabled = false;
						window.csmToast( 'Network problem.', 'bad' );
					} );
				} );
			} );

			wrap.appendChild( btn );
			li.appendChild( wrap );
			ul.appendChild( li );
		} );
		root.appendChild( ul );
	}

	api().then( function ( d ) {
		if ( d && d.ok ) { return draw( d.blocked || [] ); }
		root.innerHTML = '<p class="csm-app-loading">Could not load your blocked list.</p>';
	} ).catch( function () {
		root.innerHTML = '<p class="csm-app-loading">Could not load your blocked list.</p>';
	} );
} )();
