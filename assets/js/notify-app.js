/**
 * Email notifications — real toggles, saved as you tap.
 *
 * BuddyPress's own form is a grid of yes/no radio pairs plus a Save button.
 * A toggle that saves immediately is the phone-native equivalent, and removes
 * the class of bug where someone changes a setting and leaves without saving.
 *
 * Each toggle reverts visually if its save fails, so the switch never shows a
 * state the server did not accept.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_NOTIFY;
	var root = document.getElementById( 'csm-notify-app' );
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
		return fetch( CFG.api, opts ).then( function ( r ) { return r.json(); } );
	}

	function draw( options ) {
		root.innerHTML = '';

		var back = el( 'a', 'csm-st-back', '← Back to settings' );
		back.href = CFG.settings;
		root.appendChild( back );

		var intro = el( 'p', 'csm-nt-intro', 'Choose which emails you would like to receive. Changes save straight away.' );
		root.appendChild( intro );

		if ( ! options.length ) {
			var none = el( 'div', 'csm-st-empty' );
			none.appendChild( el( 'h2', null, 'Nothing to configure' ) );
			none.appendChild( el( 'p', null, 'There are no email preferences available on your account.' ) );
			root.appendChild( none );
			return;
		}

		var ul = el( 'ul', 'csm-st-list' );
		options.forEach( function ( o ) {
			var li = el( 'li', 'csm-st-row' );
			var lab = el( 'label', 'csm-nt-row' );
			lab.appendChild( el( 'span', 'csm-st-label', o.label ) );

			var sw = el( 'span', 'csm-nt-switch' + ( o.on ? ' is-on' : '' ) );
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.checked = !! o.on;
			input.className = 'csm-nt-input';

			input.addEventListener( 'change', function () {
				var want = input.checked;
				sw.classList.toggle( 'is-on', want );
				input.disabled = true;
				api( {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { key: o.key, on: want } )
				} ).then( function ( d ) {
					input.disabled = false;
					if ( d && d.ok ) { return window.csmToast( 'Saved.', 'ok' ); }
					// Never leave the switch showing a state the server refused.
					input.checked = ! want;
					sw.classList.toggle( 'is-on', ! want );
					window.csmToast( ( d && d.message ) || 'Could not save that.', 'bad' );
				} ).catch( function () {
					input.disabled = false;
					input.checked = ! want;
					sw.classList.toggle( 'is-on', ! want );
					window.csmToast( 'Network problem.', 'bad' );
				} );
			} );

			sw.appendChild( input );
			sw.appendChild( el( 'span', 'csm-nt-knob' ) );
			lab.appendChild( sw );
			li.appendChild( lab );
			ul.appendChild( li );
		} );
		root.appendChild( ul );
	}

	api().then( function ( d ) {
		if ( d && d.ok ) { return draw( d.options || [] ); }
		root.innerHTML = '<p class="csm-app-loading">Could not load your notification settings.</p>';
	} ).catch( function () {
		root.innerHTML = '<p class="csm-app-loading">Could not load your notification settings.</p>';
	} );
} )();
