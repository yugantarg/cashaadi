/**
 * Date of birth: type it, don't pick it.
 *
 * Owner: "it should just allow entry in ddmmyyyy format instead of drop box but
 * the display should show dd/mm/yyyy while entering and after fully entering
 * age also should show there."
 *
 * WHY A TEXT FIELD BEATS WHAT WAS THERE. Two controls were in use and both are
 * wrong for a birth date. The app screens used <input type="date">, whose mobile
 * picker opens on the CURRENT month — a 1993 birthday is thirty-odd swipes away.
 * The sign-up form used three <select>s, so it took three separate scroll-and-tap
 * gestures. Typing eight digits beats both, and every adult already knows their
 * own date of birth by heart.
 *
 * Two entry points, because DOB is edited in two different kinds of page:
 *
 *   csmDobInput( input, opts )  — upgrade an input we render ourselves
 *                                 (the wizard, the profile editor).
 *   auto-init on DOMContentLoaded — find BuddyPress's day/month/year selects on
 *                                 the register and native profile-edit forms and
 *                                 put a masked field in front of them, writing
 *                                 back to the selects on every keystroke. The
 *                                 selects stay in the DOM, hidden, so BuddyPress
 *                                 saves exactly as it always has: no new POST
 *                                 shape, no new server-side parsing to get wrong.
 *
 * The age readout is the same arithmetic the server does (FieldLogic::calc_age),
 * INCLUDING its 18–100 bounds — so a date the server would reject is called out
 * here rather than accepted and silently dropped.
 */
( function () {
	'use strict';

	if ( window.csmDobInput ) { return; }   // already loaded on this page

	var MIN_AGE = 18;
	var MAX_AGE = 100;

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }

	/** Digits only, at most 8, formatted dd/mm/yyyy as far as they go. */
	function mask( raw ) {
		var d = String( raw || '' ).replace( /\D/g, '' ).slice( 0, 8 );
		if ( d.length <= 2 ) { return d; }
		if ( d.length <= 4 ) { return d.slice( 0, 2 ) + '/' + d.slice( 2 ); }
		return d.slice( 0, 2 ) + '/' + d.slice( 2, 4 ) + '/' + d.slice( 4 );
	}

	/** A real calendar date, or null. Rejects 31/02 rather than rolling it over. */
	function parse( text ) {
		var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec( String( text || '' ) );
		if ( ! m ) { return null; }
		var day = +m[1], mon = +m[2], year = +m[3];
		if ( mon < 1 || mon > 12 || day < 1 || day > 31 ) { return null; }
		var d = new Date( year, mon - 1, day );
		if ( d.getFullYear() !== year || d.getMonth() !== mon - 1 || d.getDate() !== day ) {
			return null;   // 31 February and friends
		}
		return d;
	}

	function ageOf( d ) {
		var now = new Date();
		var a = now.getFullYear() - d.getFullYear();
		var m = now.getMonth() - d.getMonth();
		if ( m < 0 || ( 0 === m && now.getDate() < d.getDate() ) ) { a--; }
		return a;
	}

	/** "1993-06-18" or "1993-06-18 00:00:00" -> "18/06/1993". Anything else -> ''. */
	function fromIso( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( value || '' ) );
		return m ? ( m[3] + '/' + m[2] + '/' + m[1] ) : '';
	}

	function toIso( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	/**
	 * Turn one input into a masked date-of-birth field.
	 *
	 * @param {HTMLInputElement} input
	 * @param {Object} opts  onChange(iso) fires whenever the value becomes valid
	 *                       or stops being valid (iso is '' in that case).
	 * @return {Object} { iso(), valid(), note }
	 */
	window.csmDobInput = function ( input, opts ) {
		opts = opts || {};

		input.type = 'text';
		input.inputMode = 'numeric';
		input.autocomplete = 'bday';
		input.placeholder = 'dd/mm/yyyy';
		input.maxLength = 10;
		input.setAttribute( 'aria-describedby', input.id ? input.id + '-age' : '' );
		input.value = mask( fromIso( input.value ) || input.value );

		var note = document.createElement( 'p' );
		note.className = 'csm-dob-age';
		if ( input.id ) { note.id = input.id + '-age'; }
		if ( input.parentNode ) {
			input.parentNode.insertBefore( note, input.nextSibling );
		}

		var lastIso = '';

		function render() {
			var d = parse( input.value );
			var iso = '';

			if ( ! d ) {
				// Nothing to say until all eight digits are in — telling someone
				// their half-typed date is invalid is just noise.
				note.textContent = ( 10 === input.value.length ) ? 'That date does not exist.' : '';
				note.className = 'csm-dob-age' + ( 10 === input.value.length ? ' is-bad' : '' );
			} else {
				var a = ageOf( d );
				if ( a < MIN_AGE ) {
					note.textContent = 'You must be at least ' + MIN_AGE + ' to join.';
					note.className = 'csm-dob-age is-bad';
				} else if ( a > MAX_AGE ) {
					note.textContent = 'Please check the year.';
					note.className = 'csm-dob-age is-bad';
				} else {
					note.textContent = 'Age: ' + a;
					note.className = 'csm-dob-age';
					iso = toIso( d );
				}
			}

			if ( iso !== lastIso ) {
				lastIso = iso;
				if ( opts.onChange ) { opts.onChange( iso ); }
			}
		}

		input.addEventListener( 'input', function () {
			/* Re-mask on every keystroke. Caret handling is deliberately simple:
			   it is pushed to the end unless the member is editing mid-string, in
			   which case the slashes before the caret are counted so it does not
			   jump. Anything cleverer misbehaves on Android, where composition
			   events fire in a different order. */
			var atEnd = ( input.selectionStart === input.value.length );
			var before = input.value.slice( 0, input.selectionStart ).replace( /\D/g, '' ).length;
			input.value = mask( input.value );
			if ( ! atEnd ) {
				var pos = before + ( before > 4 ? 2 : before > 2 ? 1 : 0 );
				try { input.setSelectionRange( pos, pos ); } catch ( e ) {}
			}
			render();
		} );
		input.addEventListener( 'blur', render );
		render();

		return {
			iso: function () { return lastIso; },
			valid: function () { return '' !== lastIso; },
			note: note
		};
	};

	/* ---------------------------------------------------------------------
	   BuddyPress's own forms: /register/ and the native profile edit.
	   --------------------------------------------------------------------- */

	/*
	 * BuddyPress's month <select> is keyed by the ENGLISH MONTH NAME
	 * ("January"), not by a number and not 0-indexed. Verified against the live
	 * register form rather than assumed — the first version of this wrote
	 * numbers and would have saved nothing at all.
	 */
	var MONTHS = [ 'January', 'February', 'March', 'April', 'May', 'June',
		'July', 'August', 'September', 'October', 'November', 'December' ];

	/** Make sure a value the member typed actually exists in the select. */
	function ensureOption( sel, value, label ) {
		if ( '' === value ) { return; }
		for ( var i = 0; i < sel.options.length; i++ ) {
			if ( sel.options[ i ].value === value ) { return; }
		}
		/* BuddyPress offers a fixed 60-year window (2025 back to 1965 here), so
		   a member older than that could type a year the select cannot hold and
		   the date would silently save as empty. Add it rather than lose it. */
		sel.appendChild( new Option( label || value, value ) );
	}

	function upgradeSelects( day ) {
		var base  = day.name.replace( /_day$/, '' );
		var month = document.getElementsByName( base + '_month' )[ 0 ];
		var year  = document.getElementsByName( base + '_year' )[ 0 ];
		if ( ! month || ! year ) { return; }

		var input = document.createElement( 'input' );
		input.className = 'csm-dob-field';
		input.id = base + '_typed';

		// Seed from whatever the selects already hold, so editing an existing
		// date of birth starts from that date rather than from blank.
		var mi = MONTHS.indexOf( month.value );
		if ( day.value && year.value && mi > -1 ) {
			input.value = pad( parseInt( day.value, 10 ) ) + '/' + pad( mi + 1 ) + '/' + year.value;
		}

		var host = day.closest ? day.closest( '.datebox-field' ) : null;
		var anchor = host || day.parentNode;
		var wrap = document.createElement( 'div' );
		wrap.className = 'csm-dob-wrap';
		anchor.parentNode.insertBefore( wrap, anchor );
		wrap.appendChild( input );

		// The selects remain in the DOM, hidden, and are still what gets POSTed:
		// BuddyPress saves exactly as it always has, with no new parsing to get
		// wrong on the server.
		[ day, month, year ].forEach( function ( sel ) {
			var box = sel.closest ? sel.closest( '.datebox-field' ) : null;
			( box || sel ).style.display = 'none';
		} );

		window.csmDobInput( input, {
			onChange: function ( iso ) {
				var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( iso );
				var d = m ? String( +m[3] ) : '';
				var n = m ? MONTHS[ +m[2] - 1 ] : '';
				var y = m ? m[1] : '';
				ensureOption( year, y );
				day.value   = d;
				month.value = n;
				year.value  = y;
				[ day, month, year ].forEach( function ( sel ) {
					sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			}
		} );
	}

	function autoInit() {
		var days = document.querySelectorAll( 'select[name$="_day"]' );
		for ( var i = 0; i < days.length; i++ ) {
			if ( ! days[ i ].getAttribute( 'data-csm-dob' ) ) {
				days[ i ].setAttribute( 'data-csm-dob', '1' );
				try { upgradeSelects( days[ i ] ); } catch ( e ) {}
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', autoInit );
	} else {
		autoInit();
	}
}() );
