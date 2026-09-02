/**
 * In-app confirm and toast — replaces browser confirm()/alert().
 *
 * Owner: "remove any chrome driven alerts". A browser dialog announces the
 * hostname, uses the OS chrome, cannot be styled, and blocks the whole tab —
 * on a phone it reads as a security prompt rather than part of the app.
 *
 * Exposed globally because the screens that need it are not all app documents:
 * Block, Photos and CA Verify still render inside BuddyPress pages, so each
 * enqueues this file rather than importing a module.
 *
 * csmConfirm() returns a Promise<boolean> and never throws, so a caller can
 * always `await` it. If anything goes wrong building the sheet it resolves
 * true — the previous behaviour of a browser confirm the member could accept —
 * rather than silently swallowing the action.
 */
( function () {
	'use strict';

	if ( window.csmConfirm ) { return; } // already loaded on this page

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	window.csmConfirm = function ( message, opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			try {
				var overlay = el( 'div', 'csm-dlg-overlay' );
				var sheet   = el( 'div', 'csm-dlg' );

				if ( opts.title ) { sheet.appendChild( el( 'h2', 'csm-dlg-title', opts.title ) ); }
				sheet.appendChild( el( 'p', 'csm-dlg-msg', message ) );

				var row = el( 'div', 'csm-dlg-actions' );
				var cancel = el( 'button', 'csm-dlg-btn csm-dlg-cancel', opts.cancelText || 'Cancel' );
				var ok = el( 'button', 'csm-dlg-btn csm-dlg-ok' + ( opts.danger ? ' is-danger' : '' ), opts.okText || 'Continue' );
				cancel.type = 'button';
				ok.type = 'button';

				function close( answer ) {
					document.removeEventListener( 'keydown', onKey );
					if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); }
					resolve( answer );
				}
				function onKey( e ) {
					if ( 'Escape' === e.key ) { close( false ); }
					if ( 'Enter' === e.key ) { close( true ); }
				}

				cancel.addEventListener( 'click', function () { close( false ); } );
				ok.addEventListener( 'click', function () { close( true ); } );
				overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close( false ); } } );
				document.addEventListener( 'keydown', onKey );

				row.appendChild( cancel );
				row.appendChild( ok );
				sheet.appendChild( row );
				overlay.appendChild( sheet );
				document.body.appendChild( overlay );
				ok.focus();
			} catch ( e ) {
				resolve( true );
			}
		} );
	};

	/** Brief message pill. Not a dialog — never blocks, never needs dismissing. */
	window.csmToast = function ( message, kind ) {
		try {
			var t = el( 'div', 'csm-toast' + ( kind ? ' is-' + kind : '' ), message );
			document.body.appendChild( t );
			setTimeout( function () { t.classList.add( 'is-out' ); }, 2600 );
			setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 3200 );
		} catch ( e ) {}
	};
} )();
