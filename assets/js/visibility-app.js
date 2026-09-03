/**
 * Who sees what — per-field visibility, readable.
 *
 * Replaces BuddyPress's settings/profile page (a column of "Select visibility"
 * dropdowns with the chosen value clipped off). Each field is a row: its name,
 * and a control showing exactly who can see it. BuddyPress still stores the value
 * (POST relays to xprofile_set_field_visibility_level); this is presentation.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_VIS;
	var root = document.getElementById( 'csm-visibility-app' );
	if ( ! CFG || ! root ) { return; }

	function api( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = CFG.nonce;
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function el( t, c, x ) {
		var n = document.createElement( t );
		if ( c ) { n.className = c; }
		if ( x !== undefined ) { n.textContent = x; }
		return n;
	}

	function labelFor( levels, id ) {
		for ( var i = 0; i < levels.length; i++ ) { if ( levels[i].id === id ) { return levels[i].label; } }
		return id;
	}

	function draw( data ) {
		root.innerHTML = '';

		var back = el( 'a', 'csm-st-back', '← Back to settings' );
		back.href = CFG.settings;
		root.appendChild( back );

		root.appendChild( el( 'p', 'csm-vis-intro',
			'Choose who can see each detail. Your phone stays private until you match, and your date of birth is shown to others only as an age.' ) );

		( data.sections || [] ).forEach( function ( sec ) {
			var s = el( 'section', 'csm-vis-sec' );
			s.appendChild( el( 'h2', 'csm-vis-h', sec.name ) );
			sec.fields.forEach( function ( f ) {
				var row = el( 'div', 'csm-vis-row' );
				row.appendChild( el( 'span', 'csm-vis-label', f.label ) );

				if ( f.locked ) {
					row.appendChild( el( 'span', 'csm-vis-locked', labelFor( data.levels, f.level ) ) );
				} else {
					var sel = document.createElement( 'select' );
					sel.className = 'csm-vis-select';
					data.levels.forEach( function ( l ) {
						var o = document.createElement( 'option' );
						o.value = l.id; o.textContent = l.label;
						if ( l.id === f.level ) { o.selected = true; }
						sel.appendChild( o );
					} );
					sel.addEventListener( 'change', function () { save( f.id, sel.value, sel, f ); } );
					row.appendChild( sel );
				}
				s.appendChild( row );
			} );
			root.appendChild( s );
		} );
	}

	function save( fid, level, sel, f ) {
		var prev = f.level;
		sel.disabled = true;
		api( CFG.get, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { field: fid, level: level } )
		} ).then( function ( d ) {
			sel.disabled = false;
			if ( d && d.ok ) {
				f.level = level;
				if ( window.csmToast ) { window.csmToast( 'Saved' ); }
			} else {
				sel.value = prev; // revert
				if ( window.csmToast ) { window.csmToast( ( d && d.message ) || 'Could not save' ); }
			}
		} ).catch( function () {
			sel.disabled = false;
			sel.value = prev;
			if ( window.csmToast ) { window.csmToast( 'Network problem' ); }
		} );
	}

	root.innerHTML = '<p class="csm-app-loading">Loading…</p>';
	api( CFG.get ).then( function ( d ) {
		if ( d && d.ok ) { draw( d ); }
		else { root.innerHTML = '<p class="csm-app-empty">Could not load your visibility settings.</p>'; }
	} ).catch( function () {
		root.innerHTML = '<p class="csm-app-empty">Could not load your visibility settings.</p>';
	} );
} )();
