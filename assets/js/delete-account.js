/**
 * Delete my account — the confirmation screen.
 *
 * Deliberately plain and slightly hostile: no reassuring green, the button is
 * disabled until both locks are satisfied, and what will be destroyed is listed
 * before the form rather than after it. This is the one screen in the app where
 * making the action easy would be the wrong design.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_DELETE;
	var root = document.getElementById( 'csm-delete-app' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	root.innerHTML = '';

	var back = el( 'a', 'csm-mb-back', '← Back to settings' );
	back.href = CFG.back;
	root.appendChild( back );

	var box = el( 'section', 'csm-del-box' );
	box.appendChild( el( 'h1', 'csm-del-h', 'Delete my account' ) );
	box.appendChild( el( 'p', 'csm-del-lead', 'This cannot be undone. We cannot restore your account, your photos or your conversations afterwards.' ) );

	box.appendChild( el( 'h2', 'csm-del-sub', 'What is removed' ) );
	var ul = el( 'ul', 'csm-del-list' );
	[
		'Your profile, and every answer in it',
		'All your photos',
		'Your matches, and every conversation with them',
		'Requests you have sent and received',
		'Your saved profiles and everyone you have liked or passed'
	].forEach( function ( t ) { ul.appendChild( el( 'li', null, t ) ); } );
	box.appendChild( ul );

	// The way back, on the screen where people are most likely to want it.
	var alt = el( 'p', 'csm-del-note' );
	if ( CFG.pause ) {
		alt.appendChild( document.createTextNode( 'If you only want a break, ' ) );
		var pl = el( 'a', null, 'pause your profile instead' );
		pl.href = CFG.pause;
		alt.appendChild( pl );
		alt.appendChild( document.createTextNode( ' — you disappear from Discover and the emails stop, and nothing is lost.' ) );
	} else {
		alt.textContent = 'If you only want a break, you can stop the emails in Settings → Email notifications instead, and your profile stays as it is.';
	}
	box.appendChild( alt );

	/* Why they are going. Required, and placed FIRST — asking after someone has
	   typed DELETE and their password reads as a toll gate; asking before it is
	   a question. The server requires it too. */
	var f0 = el( 'label', 'csm-del-field' );
	f0.appendChild( el( 'span', null, 'Why are you leaving?' ) );
	var reason = el( 'textarea', 'csm-del-input csm-del-reason' );
	reason.rows = 3;
	reason.maxLength = 1000;
	reason.placeholder = 'Found someone, taking a break, something went wrong…';
	f0.appendChild( reason );
	box.appendChild( f0 );

	var f1 = el( 'label', 'csm-del-field' );
	f1.appendChild( el( 'span', null, 'Type DELETE to confirm' ) );
	var word = el( 'input', 'csm-del-input' );
	word.type = 'text';
	word.autocapitalize = 'characters';
	word.placeholder = 'DELETE';
	f1.appendChild( word );
	box.appendChild( f1 );

	var f2 = el( 'label', 'csm-del-field' );
	f2.appendChild( el( 'span', null, 'Your password' ) );
	var pw = el( 'input', 'csm-del-input' );
	pw.type = 'password';
	pw.autocomplete = 'current-password';
	f2.appendChild( pw );
	box.appendChild( f2 );

	var err = el( 'p', 'csm-del-err' );
	box.appendChild( err );

	var go = el( 'button', 'csm-del-go', 'Delete my account permanently' );
	go.type = 'button';
	go.disabled = true;
	box.appendChild( go );

	var cancel = el( 'a', 'csm-del-cancel', 'Keep my account' );
	cancel.href = CFG.back;
	box.appendChild( cancel );

	root.appendChild( box );

	function check() {
		go.disabled = ( 'DELETE' !== word.value.trim().toUpperCase() )
			|| ! pw.value
			|| '' === reason.value.trim();
	}
	word.addEventListener( 'input', check );
	pw.addEventListener( 'input', check );
	reason.addEventListener( 'input', check );

	go.addEventListener( 'click', function () {
		err.textContent = '';
		var release = window.csmBusy ? window.csmBusy( go ) : function () {};

		fetch( CFG.submit, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify( { confirm: word.value.trim(), password: pw.value, reason: reason.value.trim() } )
		} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
			release();
			if ( d && d.ok ) {
				// The session is already gone server-side; going home rather than
				// re-rendering avoids a screen that would 401 on every request.
				window.location.href = CFG.home;
				return;
			}
			err.textContent = ( d && d.message ) || 'That did not work. Please try again.';
		} ).catch( function () {
			release();
			err.textContent = 'Network error. Please try again.';
		} );
	} );
}() );
