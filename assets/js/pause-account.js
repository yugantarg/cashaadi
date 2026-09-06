/**
 * Pause my profile.
 *
 * The reversible door out. Deliberately calm where the delete screen is
 * hostile: one switch, no typing, no password — because nothing here is lost
 * and making it feel dangerous would push people towards the door that is.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_PAUSE;
	var root = document.getElementById( 'csm-pause-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function draw( paused ) {
		root.innerHTML = '';

		var back = el( 'a', 'csm-mb-back', '← Back to settings' );
		back.href = CFG.back;
		root.appendChild( back );

		var box = el( 'section', 'csm-del-box' );
		box.appendChild( el( 'h1', 'csm-del-h', paused ? 'Your profile is paused' : 'Pause my profile' ) );

		if ( paused ) {
			box.appendChild( el( 'p', 'csm-pause-lead', 'Nobody can see you at the moment. Everything is still here — turn it back on whenever you are ready.' ) );
		} else {
			box.appendChild( el( 'p', 'csm-pause-lead', 'Take a break without losing anything. You can come back whenever you like.' ) );
		}

		box.appendChild( el( 'h2', 'csm-del-sub', paused ? 'While you are paused' : 'While paused' ) );
		var ul = el( 'ul', 'csm-del-list' );
		[
			'You are not shown to anyone in Discover',
			'You are removed from the members directory',
			'We stop sending you emails',
			'Your matches and conversations stay exactly as they are'
		].forEach( function ( t ) { ul.appendChild( el( 'li', null, t ) ); } );
		box.appendChild( ul );

		var err = el( 'p', 'csm-del-err' );
		box.appendChild( err );

		var go = el( 'button', paused ? 'csm-pause-go is-on' : 'csm-pause-go' );
		go.type = 'button';
		go.textContent = paused ? 'Turn my profile back on' : 'Pause my profile';
		box.appendChild( go );

		if ( ! paused && CFG.delete ) {
			var note = el( 'p', 'csm-del-note' );
			note.appendChild( document.createTextNode( 'Want to leave for good instead? ' ) );
			var d = el( 'a', null, 'Delete my account' );
			d.href = CFG.delete;
			note.appendChild( d );
			note.appendChild( document.createTextNode( ' — that one cannot be undone.' ) );
			box.appendChild( note );
		}

		root.appendChild( box );

		go.addEventListener( 'click', function () {
			err.textContent = '';
			var release = window.csmBusy ? window.csmBusy( go ) : function () {};
			fetch( CFG.submit, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				body: JSON.stringify( { paused: ! paused } )
			} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
				release();
				if ( d && d.ok ) { return draw( d.paused ); }
				err.textContent = ( d && d.message ) || 'That did not work. Please try again.';
			} ).catch( function () {
				release();
				err.textContent = 'Network error. Please try again.';
			} );
		} );
	}

	draw( !! CFG.paused );
}() );
