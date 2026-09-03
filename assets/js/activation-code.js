/**
 * Activation code — submit without leaving the screen.
 *
 * First increment of the incremental-headless direction (owner, 2026-09-01):
 * the screen talks to REST and re-renders itself, instead of POSTing a page and
 * hoping the server re-renders the same form. A wrong code now shows an error
 * under the field with the digits still there to correct, rather than reloading.
 *
 * PROGRESSIVE ENHANCEMENT, deliberately. The <form> keeps a real method="post"
 * and a real action pointing at the activation page, so with JS blocked or this
 * file failing to load, submitting still works the old way. Nothing here is the
 * security boundary — the server verifies the code, caps attempts and sets the
 * auth cookie exactly as it does for the plain POST.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.csm-actcode-form' );
	if ( ! form || ! window.fetch ) {
		return; // plain POST fallback handles it
	}

	/*
	 * Our REST form is live, so BuddyPress's own activation form beneath it is
	 * redundant — it rendered as an empty extra "bar" under our card (its inputs
	 * styled but valueless). Remove it and its unclassed "Please provide a valid
	 * activation key" note. It survives as the no-JS fallback only when this file
	 * does not run, which is the whole point of leaving it in the markup.
	 */
	var nativeForm = document.getElementById( 'activation-form' );
	if ( nativeForm ) {
		var intro = nativeForm.previousElementSibling;
		if ( intro && 'P' === intro.tagName && ! intro.className ) {
			intro.parentNode.removeChild( intro );
		}
		nativeForm.parentNode.removeChild( nativeForm );
	}

	var endpoint = form.getAttribute( 'data-endpoint' );
	var emailEl  = form.querySelector( '#csm_act_email' );
	var codeEl   = form.querySelector( '#csm_act_code' );
	var nonceEl  = form.querySelector( '[name="csm_act_nonce"]' );
	var button   = form.querySelector( 'button[type="submit"]' );
	var errEl    = document.querySelector( '.csm-actcode-err' );

	if ( ! endpoint || ! emailEl || ! codeEl || ! nonceEl ) {
		return;
	}

	function showError( msg ) {
		if ( errEl ) {
			errEl.textContent = msg;
		}
		// Keep what they typed and select it, so correcting is one keystroke.
		codeEl.focus();
		codeEl.select();
	}

	function busy( on ) {
		if ( ! button ) {
			return;
		}
		button.disabled = on;
		button.classList.toggle( 'is-busy', !! on );
	}

	// Digits only, and submit the moment a 4th is entered — the whole point of a
	// 4-digit code is that it needs no button press.
	codeEl.addEventListener( 'input', function () {
		var clean = codeEl.value.replace( /\D/g, '' ).slice( 0, 4 );
		if ( clean !== codeEl.value ) {
			codeEl.value = clean;
		}
		if ( errEl && errEl.textContent ) {
			errEl.textContent = ''; // clear stale error as soon as they retype
		}
		if ( 4 === clean.length ) {
			submit();
		}
	} );

	function submit() {
		if ( button && button.disabled ) {
			return; // already in flight
		}
		busy( true );

		fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				email: emailEl.value,
				code: codeEl.value,
				nonce: nonceEl.value
			} )
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.ok && data.redirect ) {
					// Activated and logged in server-side; go to the app.
					window.location.href = data.redirect;
					return;
				}
				busy( false );
				showError( ( data && data.message ) || 'Something went wrong. Please try again.' );
			} )
			.catch( function () {
				// Network/parse failure: fall back to the real POST rather than
				// stranding the member on a screen that appears to do nothing.
				busy( false );
				form.submit();
			} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		submit();
	} );

	/* ------------------------------------------------------------- resend */

	var resendBtn = document.querySelector( '.csm-actcode-resend-btn' );
	var resendMsg = document.querySelector( '.csm-actcode-resend-msg' );

	if ( resendBtn && resendBtn.getAttribute( 'data-endpoint' ) ) {
		resendBtn.addEventListener( 'click', function () {
			if ( resendBtn.disabled ) {
				return;
			}
			if ( ! emailEl.value ) {
				if ( resendMsg ) {
					resendMsg.textContent = 'Enter your email address first.';
				}
				emailEl.focus();
				return;
			}

			resendBtn.disabled = true;
			if ( resendMsg ) {
				resendMsg.textContent = '';
			}

			fetch( resendBtn.getAttribute( 'data-endpoint' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { email: emailEl.value, nonce: nonceEl.value } )
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( resendMsg ) {
						resendMsg.textContent = ( data && data.message ) || '';
					}
					// Mirror the server's cooldown in the UI so the button cannot be
					// hammered into an error it already knows the answer to.
					countdown( 60 );
					// A new code means the old digits are dead — clear them.
					codeEl.value = '';
					if ( errEl ) {
						errEl.textContent = '';
					}
					codeEl.focus();
				} )
				.catch( function () {
					resendBtn.disabled = false;
					if ( resendMsg ) {
						resendMsg.textContent = 'Could not send a new code. Please try again.';
					}
				} );
		} );
	}

	function countdown( secs ) {
		var label = 'Send me a new code';
		var left  = secs;

		function tick() {
			if ( left <= 0 ) {
				resendBtn.disabled = false;
				resendBtn.textContent = label;
				return;
			}
			resendBtn.textContent = label + ' (' + left + ')';
			left--;
			window.setTimeout( tick, 1000 );
		}
		tick();
	}
} )();
