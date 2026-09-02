/**
 * Profile edit — one section at a time.
 *
 * Not a wizard. The /profile/ hub sends members here to change one section and
 * go back, so there is no chain, no "Save & Next", no step counter.
 *
 * Switching sections uses pushState and answers popstate, so Back walks section
 * → section and finally out to the hub. The draft wizard this replaces used
 * replaceState, which is exactly why Back used to leave the flow entirely.
 */
( function () {
	'use strict';

	var CFG  = window.CSM_PEDIT;
	var root = document.getElementById( 'csm-pedit-app' );
	if ( ! CFG || ! root ) { return; }

	var current = CFG.group || 0;
	var index   = [];
	var dirty   = false;

	function api( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = CFG.nonce;
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	/* ------------------------------------------------------------ fields */

	function fieldControl( f ) {
		var wrap = el( 'div', 'csm-pe-field' );

		var label = el( 'label', 'csm-pe-label' );
		label.appendChild( document.createTextNode( f.label ) );
		if ( f.required ) { label.appendChild( el( 'span', 'csm-pe-req', 'Required' ) ); }
		wrap.appendChild( label );

		if ( f.help ) { wrap.appendChild( el( 'p', 'csm-pe-help', f.help ) ); }

		var node, read;

		/*
		 * Upload fields are handled by BuddyPress's own editor.
		 *
		 * `file` is a custom field type whose widget and storage format belong to
		 * the plugin that defines it. Rendering our own control would either not
		 * work or write the wrong thing, so we say so and link to the editor that
		 * does work — rather than showing a text box that looks functional and is
		 * not, which is what ICAI ID was doing.
		 */
		if ( f.native ) {
			/*
			 * A File/Image field (e.g. the ICAI document). This used to send members
			 * out to the old BuddyPress edit form; now the upload happens here. The
			 * file POSTs to /profile/file, which routes it through the SAME plugin
			 * handler the classic form used, so nothing about how it is stored
			 * changes — only where you do it.
			 */
			var box = el( 'div', 'csm-pe-native' );

			var status = el( 'p', 'csm-pe-file-status' );
			function showCurrent( url ) {
				status.textContent = '';
				if ( url ) {
					status.appendChild( document.createTextNode( 'Uploaded: ' ) );
					var v = document.createElement( 'a' );
					v.href = url; v.target = '_blank'; v.rel = 'noopener';
					v.textContent = 'view file';
					status.appendChild( v );
				} else {
					status.textContent = 'No file uploaded yet.';
				}
			}
			showCurrent( f.currentUrl );

			var pick = el( 'label', 'csm-pe-file-btn' );
			pick.appendChild( document.createTextNode( f.currentUrl ? 'Replace file' : ( 'Upload ' + f.label ) ) );
			var input = document.createElement( 'input' );
			input.type = 'file';
			if ( f.accept ) { input.accept = f.accept; }
			input.hidden = true;
			pick.appendChild( input );

			input.addEventListener( 'change', function () {
				if ( ! input.files || ! input.files.length ) { return; }
				var fd = new FormData();
				fd.append( 'action', 'csm_pe_file' );
				fd.append( 'nonce', CFG.uploadNonce );
				fd.append( 'field', f.id );
				fd.append( 'file', input.files[0] );
				pick.classList.add( 'is-busy' );
				status.textContent = 'Uploading…';
				// admin-ajax, not REST: the host's WAF blocks file POSTs to /wp-json/.
				fetch( CFG.upload, {
					method: 'POST', credentials: 'same-origin',
					body: fd
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					pick.classList.remove( 'is-busy' );
					if ( d && d.success && d.data ) {
						showCurrent( d.data.url );
						pick.textContent = 'Replace file';
						pick.appendChild( input );
					} else {
						status.textContent = ( d && d.data && d.data.message ) || 'Upload failed. Try a PDF, JPG or PNG.';
					}
					input.value = '';
				} ).catch( function () {
					pick.classList.remove( 'is-busy' );
					status.textContent = 'Network error, please try again.';
					input.value = '';
				} );
			} );

			box.appendChild( pick );
			box.appendChild( status );

			// Secondary escape hatch to the classic form, de-emphasised.
			if ( f.nativeUrl ) {
				var alt = document.createElement( 'a' );
				alt.className = 'csm-pe-native-link';
				alt.href = f.nativeUrl;
				alt.textContent = 'Use the classic form instead';
				box.appendChild( alt );
			}

			wrap.appendChild( box );
			return { node: wrap, key: 'field_' + f.id, read: function () { return null; }, skip: true };
		}

		if ( f.options && f.options.length ) {
			node = el( 'div', 'csm-pe-opts' );
			var chosen = f.multi ? ( f.value || [] ) : [ f.value ];
			f.options.forEach( function ( o ) {
				var on = chosen.indexOf( o ) !== -1;
				var b = el( 'button', 'csm-pe-opt' + ( on ? ' is-on' : '' ), o );
				b.type = 'button';
				b.setAttribute( 'data-val', o );
				b.addEventListener( 'click', function () {
					if ( f.multi ) {
						b.classList.toggle( 'is-on' );
					} else {
						[].forEach.call( node.children, function ( x ) { x.classList.remove( 'is-on' ); } );
						b.classList.add( 'is-on' );
					}
					setDirty( true );
				} );
				node.appendChild( b );
			} );
			read = function () {
				var on = [].filter.call( node.children, function ( x ) { return x.classList.contains( 'is-on' ); } )
					.map( function ( x ) { return x.getAttribute( 'data-val' ); } );
				return f.multi ? on : ( on[0] || '' );
			};
		} else if ( 'textarea' === f.type ) {
			node = el( 'textarea', 'csm-pe-input' );
			node.rows = 4;
			node.value = f.value || '';
			node.addEventListener( 'input', function () { setDirty( true ); } );
			read = function () { return node.value; };
		} else {
			node = el( 'input', 'csm-pe-input' );
			node.type = ( 'datebox' === f.type ) ? 'date'
				: ( 'telephone' === f.type ) ? 'tel'
				: ( 'number' === f.type ) ? 'number'
				: ( 'url' === f.type ) ? 'url' : 'text';
			// a stored datetime won't populate a date input; trim to the date part
			node.value = ( 'datebox' === f.type ) ? String( f.value || '' ).slice( 0, 10 ) : ( f.value || '' );
			node.addEventListener( 'input', function () { setDirty( true ); } );
			read = function () { return node.value; };
		}

		wrap.appendChild( node );
		wrap.appendChild( el( 'p', 'csm-pe-err' ) );
		return { node: wrap, key: 'field_' + f.id, read: read };
	}

	/* -------------------------------------------------------------- draw */

	function draw( data ) {
		root.innerHTML = '';
		index = data.index || index;

		var head = el( 'div', 'csm-pe-head' );
		var back = el( 'a', 'csm-pe-back', '← Back to profile' );
		back.href = CFG.hub;
		head.appendChild( back );
		head.appendChild( el( 'h1', 'csm-pe-title', data.group.name ) );
		root.appendChild( head );

		// section switcher — a list, not a wizard chain
		if ( index.length ) {
			var strip = el( 'div', 'csm-pe-sections' );
			index.forEach( function ( g ) {
				var b = el( 'button', 'csm-pe-section' + ( g.id === data.group.id ? ' is-on' : '' ), g.name );
				b.type = 'button';
				b.addEventListener( 'click', function () { go( g.id ); } );
				strip.appendChild( b );
			} );
			root.appendChild( strip );
		}

		var card = el( 'div', 'csm-pe-card' );
		var controls = [];
		( data.fields || [] ).forEach( function ( f ) {
			var c = fieldControl( f );
			controls.push( c );
			card.appendChild( c.node );
		} );

		var msg = el( 'p', 'csm-pe-msg' );
		card.appendChild( msg );

		var save = el( 'button', 'csm-pe-save', 'Save changes' );
		save.type = 'button';
		save.addEventListener( 'click', function () { submit( data.group.id, controls, save, msg ); } );
		card.appendChild( save );

		root.appendChild( card );
		window.scrollTo( 0, 0 );
	}

	function submit( gid, controls, button, msg ) {
		button.disabled = true;
		msg.textContent = '';
		msg.className = 'csm-pe-msg';
		[].forEach.call( root.querySelectorAll( '.csm-pe-err' ), function ( e ) { e.textContent = ''; } );

		var values = {};
		controls.forEach( function ( c ) {
			// A native-upload field has no value to send; including it would post
			// null and be read as "clear this field".
			if ( c.skip ) { return; }
			values[ c.key ] = c.read();
		} );

		api( CFG.save, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { id: gid, values: values } )
		} ).then( function ( d ) {
			button.disabled = false;
			if ( d && d.ok ) {
				setDirty( false );
				msg.textContent = 'Saved.';
				msg.className = 'csm-pe-msg is-ok';
				return;
			}
			if ( d && d.errors ) {
				Object.keys( d.errors ).forEach( function ( k ) {
					var c = controls.filter( function ( x ) { return x.key === k; } )[0];
					if ( c ) { c.node.querySelector( '.csm-pe-err' ).textContent = d.errors[ k ]; }
				} );
			}
			msg.textContent = ( d && d.message ) || 'We could not save that.';
			msg.className = 'csm-pe-msg is-bad';
		} ).catch( function () {
			button.disabled = false;
			msg.textContent = 'Network problem. Please try again.';
			msg.className = 'csm-pe-msg is-bad';
		} );
	}

	/* ------------------------------------------------------------ routing */

	function load( gid, push ) {
		api( CFG.get + '?id=' + encodeURIComponent( gid ) ).then( function ( d ) {
			if ( ! d || ! d.ok ) {
				root.innerHTML = '';
				var box = el( 'div', 'csm-pe-card' );
				box.appendChild( el( 'h1', 'csm-pe-title', 'Section not found' ) );
				var a = el( 'a', 'csm-pe-back', '← Back to profile' );
				a.href = CFG.hub;
				box.appendChild( a );
				root.appendChild( box );
				return;
			}
			current = d.group.id;
			if ( push ) { history.pushState( { g: current }, '', '?g=' + current ); }
			draw( d );
		} ).catch( function () {
			root.innerHTML = '<p class="csm-app-loading">Could not load this section. Please refresh.</p>';
		} );
	}

	/* A status pill, not a dialog: it tells you there is something unsaved
	   without interrupting, which is what the owner asked for. */
	var pill = null;
	function setDirty( on ) {
		dirty = !! on;
		if ( ! pill ) {
			pill = document.createElement( 'div' );
			pill.className = 'csm-unsaved';
			pill.textContent = 'Unsaved changes';
			document.body.appendChild( pill );
		}
		pill.classList.toggle( 'is-on', dirty );
	}

	function go( gid ) {
		if ( gid === current ) { return; }
		if ( ! dirty ) { setDirty( false ); return load( gid, true ); }

		window.csmConfirm( 'Your changes to this section have not been saved.', {
			title: 'Leave without saving?',
			okText: 'Leave',
			cancelText: 'Stay',
			danger: true
		} ).then( function ( leave ) {
			if ( ! leave ) { return; }
			setDirty( false );
			load( gid, true );
		} );
	}

	window.addEventListener( 'popstate', function ( e ) {
		var g = e.state && e.state.g;
		if ( g ) { load( g, false ); }
	} );

	// First load: the group asked for, else the first section.
	if ( current ) {
		history.replaceState( { g: current }, '', '?g=' + current );
		load( current, false );
	} else {
		api( CFG.get + '?id=1' ).then( function ( d ) {
			var first = ( d && d.index && d.index[0] ) ? d.index[0].id : 1;
			history.replaceState( { g: first }, '', '?g=' + first );
			load( first, false );
		} );
	}
} )();
