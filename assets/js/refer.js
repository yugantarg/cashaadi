/* Refer & earn: copy / share the link, and the use-my-cash switch. */
( function () {
	var C = window.CSM_REFER;
	if ( ! C ) { return; }

	var copy = document.querySelector( '.csm-ref-copy' );
	var input = document.querySelector( '.csm-ref-link input' );
	if ( copy && input ) {
		copy.addEventListener( 'click', function () {
			var done = function () {
				copy.textContent = 'Copied';
				setTimeout( function () { copy.textContent = 'Copy'; }, 1800 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( C.link ).then( done, function () { input.select(); document.execCommand( 'copy' ); done(); } );
			} else {
				input.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	}

	// The phone's own share sheet, where the browser has one.
	var native = document.querySelector( '.csm-ref-native' );
	if ( native && navigator.share ) {
		native.hidden = false;
		native.addEventListener( 'click', function () {
			navigator.share( { text: C.share } ).catch( function () {} );
		} );
	}

	var use = document.querySelector( '.csm-ref-use' );
	if ( use ) {
		use.addEventListener( 'change', function () {
			var want = use.checked;
			fetch( C.use, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
				body: JSON.stringify( { use: want } )
			} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
				if ( ! d || ! d.ok ) { use.checked = ! want; }
			} ).catch( function () { use.checked = ! want; } );
		} );
	}
} )();
