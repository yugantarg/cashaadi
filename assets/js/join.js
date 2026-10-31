/**
 * Sign-up screen (/join/, v1.90.39): the form, then the 4-digit code, on one
 * page. Errors appear under the field they belong to. The code step uses
 * ActivationCode's endpoints, so it behaves exactly like the old flow.
 */
( function () {
	'use strict';

	var CFG = window.CSM_JOIN;
	var form = document.getElementById( 'cj-form' );
	if ( ! CFG || ! form ) { return; }
	var stepForm = document.getElementById( 'cj-step-form' );
	var stepCode = document.getElementById( 'cj-step-code' );
	var email = '';

	function post( url, body ) {
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} ).then( function ( r ) { return r.json(); } );
	}

	function errEl( key, root ) { return ( root || document ).querySelector( '.cj-err[data-for="' + key + '"]' ); }
	function clear( root ) {
		[].forEach.call( ( root || document ).querySelectorAll( '.cj-err' ), function ( e ) { e.textContent = ''; } );
		[].forEach.call( ( root || document ).querySelectorAll( '.is-bad' ), function ( e ) { e.classList.remove( 'is-bad' ); } );
	}
	function show( key, msg ) {
		var e = errEl( key );
		if ( ! e ) { e = errEl( '_' ); }
		e.textContent = msg;
		var f = e.closest( '.cj-field' );
		if ( f ) { f.classList.add( 'is-bad' ); }
	}
	function busy( btn, on, label ) {
		btn.disabled = on;
		if ( on ) { btn.dataset.label = btn.textContent; btn.textContent = label || 'Please wait…'; } else if ( btn.dataset.label ) { btn.textContent = btn.dataset.label; }
	}

	/* Show / hide password. */
	var eye = form.querySelector( '.cj-eye' );
	var pass = document.getElementById( 'cj-pass' );
	eye.addEventListener( 'click', function () {
		var hidden = 'password' === pass.type;
		pass.type = hidden ? 'text' : 'password';
		eye.textContent = hidden ? 'Hide' : 'Show';
		eye.setAttribute( 'aria-label', hidden ? 'Hide password' : 'Show password' );
	} );

	/* Say what the choice means (owner: avoid bride / groom confusion). */
	var gHint = document.getElementById( 'cj-gender-hint' );
	[].forEach.call( form.querySelectorAll( 'input[name=gender]' ), function ( r ) {
		r.addEventListener( 'change', function () {
			if ( ! gHint ) { return; }
			gHint.textContent = 'Female' === r.value ? 'You are the bride, looking for a groom.' : ( 'Male' === r.value ? 'You are the groom, looking for a bride.' : '' );
		} );
	} );

	/* Checks the browser can make; the server checks everything again. */
	function local( v ) {
		var e = {};
		if ( v.name.trim().length < 2 ) { e.name = 'Please enter your name.'; }
		if ( ! v.gender ) { e.gender = 'Please choose one.'; }
		if ( v.phone.replace( /\D/g, '' ).length < 10 ) { e.phone = 'Please enter a valid mobile number.'; }
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v.email ) ) { e.email = 'Please enter a valid email address.'; }
		if ( v.password.length < ( parseInt( pass.getAttribute( 'minlength' ), 10 ) || 8 ) ) { e.password = 'Use at least ' + ( pass.getAttribute( 'minlength' ) || 8 ) + ' characters.'; }
		if ( ! v.agree ) { e.agree = 'Please accept the terms and privacy policy.'; }
		return e;
	}

	function toCode( addr ) {
		email = addr;
		document.getElementById( 'cj-sent-to' ).textContent = addr;
		stepForm.hidden = true;
		stepCode.hidden = false;
		window.scrollTo( 0, 0 );
		document.getElementById( 'cj-code' ).focus();
	}

	form.addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		clear( form );
		var d = form.elements;
		var g = form.querySelector( 'input[name=gender]:checked' );
		var v = {
			nonce: CFG.nonce,
			name: d.name.value, gender: g ? g.value : '',
			phone: d.phone.value, email: d.email.value.trim(), password: d.password.value,
			agree: d.agree.checked, cj_trap: d.cj_trap.value
		};
		var e = local( v );
		var keys = Object.keys( e );
		if ( keys.length ) {
			keys.forEach( function ( k ) { show( k, e[ k ] ); } );
			var first = errEl( keys[0] );
			if ( first ) { first.closest( '.cj-field, form' ).scrollIntoView( { block: 'center', behavior: 'smooth' } ); }
			return;
		}
		var btn = form.querySelector( '.cj-btn' );
		busy( btn, true, 'Creating your profile…' );
		post( CFG.submit, v ).then( function ( r ) {
			busy( btn, false );
			if ( r && r.ok ) { return toCode( r.email || v.email ); }
			var errs = ( r && r.errors ) || {};
			Object.keys( errs ).forEach( function ( k ) { show( k, errs[ k ] ); } );
			// Already a member: Log in / Forgot password, not a dead end (v1.90.88).
			if ( r && r.existing ) {
				var ee = errEl( 'email' );
				if ( ee ) {
					var li = document.createElement( 'a' ); li.href = r.existing.login; li.textContent = 'Log in';
					var lo = document.createElement( 'a' ); lo.href = r.existing.lost; lo.textContent = 'Forgot password?';
					ee.appendChild( document.createTextNode( ' ' ) ); ee.appendChild( li );
					ee.appendChild( document.createTextNode( ' · ' ) ); ee.appendChild( lo );
				}
			}
			if ( r && r.message ) { show( '_', r.message ); }
			if ( ! Object.keys( errs ).length && ! ( r && r.message ) ) { show( '_', 'Something went wrong. Please try again.' ); }
			var bad = form.querySelector( '.is-bad, .cj-err-top:not(:empty)' );
			if ( bad ) { bad.scrollIntoView( { block: 'center', behavior: 'smooth' } ); }
		} ).catch( function () {
			busy( btn, false );
			show( '_', 'No connection. Please check your internet and try again.' );
		} );
	} );

	/* The 4-digit code. */
	var codeForm = document.getElementById( 'cj-code-form' );
	var code = document.getElementById( 'cj-code' );
	code.addEventListener( 'input', function () {
		code.value = code.value.replace( /\D/g, '' ).slice( 0, 4 );
		if ( 4 === code.value.length ) { codeForm.requestSubmit ? codeForm.requestSubmit() : codeForm.dispatchEvent( new Event( 'submit' ) ); }
	} );
	codeForm.addEventListener( 'submit', function ( ev ) {
		ev.preventDefault();
		clear( stepCode );
		if ( 4 !== code.value.length ) { errEl( 'code' ).textContent = 'Enter the 4 digits from the email.'; return; }
		var btn = codeForm.querySelector( '.cj-btn' );
		busy( btn, true, 'Checking…' );
		post( CFG.activate, { email: email, code: code.value, nonce: CFG.codeNonce } ).then( function ( r ) {
			if ( r && r.ok ) {
				btn.textContent = 'Welcome!';
				window.location.href = r.redirect || CFG.welcome;
				return;
			}
			busy( btn, false );
			errEl( 'code' ).textContent = ( r && r.message ) || 'That code did not work. Please try again.';
			code.select();
		} ).catch( function () {
			busy( btn, false );
			errEl( 'code' ).textContent = 'No connection. Please try again.';
		} );
	} );

	var resend = document.getElementById( 'cj-resend' );
	var resendMsg = document.getElementById( 'cj-resend-msg' );
	resend.addEventListener( 'click', function () {
		resend.disabled = true;
		resendMsg.textContent = 'Sending…';
		post( CFG.resend, { email: email, nonce: CFG.codeNonce } ).then( function ( r ) {
			resendMsg.textContent = ( r && r.message ) || ( r && r.ok ? 'A new code is on its way.' : 'Please wait a minute and try again.' );
			setTimeout( function () { resend.disabled = false; }, 30000 );
		} ).catch( function () { resend.disabled = false; resendMsg.textContent = 'No connection. Please try again.'; } );
	} );

	document.getElementById( 'cj-change' ).addEventListener( 'click', function () {
		stepCode.hidden = true;
		stepForm.hidden = false;
		document.getElementById( 'cj-email' ).focus();
	} );
} )();
