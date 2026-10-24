/**
 * Community and mother-tongue inputs (owner, 2026-10-24).
 *
 * Mother tongue: a typeahead of languages ("Hin" offers Hindi), free text
 * still allowed.
 *
 * Community: the member types their community in their own words, which is
 * what their profile shows. Once they stop typing, the group the filters use
 * is shown, pre-selected from their words, and they can change it. The
 * choice is saved as it is made, tied to the words it was made for.
 */
( function () {
	var CFG = window.CSM_GROUPS || null;

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function call( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = Object.assign( { 'X-WP-Nonce': CFG.nonce }, opts.headers || {} );
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function tongue( input ) {
		var id = 'csm-tongues';
		if ( ! document.getElementById( id ) ) {
			var dl = el( 'datalist' );
			dl.id = id;
			( CFG.tongues || [] ).forEach( function ( t ) {
				var o = document.createElement( 'option' );
				o.value = t;
				dl.appendChild( o );
			} );
			document.body.appendChild( dl );
		}
		input.setAttribute( 'list', id );
		input.setAttribute( 'autocomplete', 'off' );
	}

	function community( input, wrap ) {
		var box = el( 'div', 'csm-gp' );
		box.hidden = true;
		var lab = el( 'label', 'csm-gp-row' );
		lab.appendChild( el( 'span', 'csm-gp-l', 'Community group' ) );
		var sel = document.createElement( 'select' );
		sel.className = 'csm-gp-sel';
		lab.appendChild( sel );
		box.appendChild( lab );
		box.appendChild( el( 'p', 'csm-gp-hint', 'Members filter by this group. Your profile shows what you typed above. Change the group if another fits better.' ) );
		wrap.appendChild( box );

		var timer = null, asked = '', initial = input.value;

		function save() {
			return call( CFG.choose, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { kind: 'community', text: input.value, group: sel.value } )
			} ).catch( function () {} );
		}

		function suggest( persist ) {
			var text = input.value.trim();
			if ( text.length < 3 ) { box.hidden = true; return; }
			if ( text === asked ) { return; }
			asked = text;
			call( CFG.suggest + ( CFG.suggest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'kind=community&text=' + encodeURIComponent( text ) ).then( function ( d ) {
				if ( ! d || ! d.options || text !== input.value.trim() ) { return; }
				sel.innerHTML = '';
				var pick = d.chosen || ( d.groups && d.groups[0] ) || 'Others';
				d.options.forEach( function ( o ) {
					var op = document.createElement( 'option' );
					op.value = o; op.textContent = o;
					if ( o === pick ) { op.selected = true; }
					sel.appendChild( op );
				} );
				box.hidden = false;
				// A new answer: store the suggested group now, so it holds even
				// if they never touch the list. An unchanged answer keeps its
				// earlier choice untouched.
				if ( persist && ! d.chosen ) { save(); }
			} ).catch( function () {} );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () { suggest( true ); }, 900 );
		} );
		input.addEventListener( 'blur', function () { clearTimeout( timer ); suggest( input.value !== initial ); } );
		sel.addEventListener( 'change', save );
		if ( initial.trim() ) { suggest( false ); }
	}

	window.csmGroupPicker = function ( input, kind, wrap ) {
		if ( ! CFG || ! input || 'INPUT' !== input.tagName ) { return; }
		if ( 'tongue' === kind ) { tongue( input ); }
		if ( 'community' === kind ) { community( input, wrap || input.parentNode ); }
	};
} )();
