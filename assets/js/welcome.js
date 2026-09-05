/**
 * /welcome/ — onboarding step machine.
 *
 * One route, one question per screen, no page loads. Progress is not tracked
 * here: the server derives the current step from the data on every load, so a
 * refresh or a second device resumes in the right place without this file
 * remembering anything.
 *
 * Back is real. history.pushState gives each step an entry, so the hardware/
 * browser back button moves a step backwards instead of leaving the flow — the
 * bug that has been reported three times against the old wizard, which could not
 * fix it because each of its steps was a separate form POST.
 */
( function () {
	'use strict';

	var CFG = window.CSM_WELCOME;
	if ( ! CFG || ! CFG.state ) { return; }

	var main     = document.getElementById( 'csm-w-main' );
	var progress = document.getElementById( 'csm-w-progress' );
	if ( ! main ) { return; }

	var steps = [];
	var idx   = 0;
	var blurred = false;

	/* ------------------------------------------------------------ helpers */

	function api( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = opts.headers || {};
		opts.headers['X-WP-Nonce'] = CFG.nonce; // cookie auth needs this or 401
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	function setProgress() {
		if ( ! progress || ! steps.length ) { return; }
		var pct = Math.round( ( idx / steps.length ) * 100 );
		progress.style.width = Math.min( 100, Math.max( 4, pct ) ) + '%';
	}

	/* -------------------------------------------------------------- boot */

	function boot() {
		api( CFG.state ).then( function ( d ) {
			if ( ! d || ! d.steps ) { return fail(); }
			steps   = d.steps;
			blurred = !! d.blurred;
			idx     = typeof d.current === 'number' ? d.current : 0;
			if ( idx >= steps.length ) { return finish(); }

			/*
			 * Seed the history stack with one entry per step already behind us.
			 *
			 * Only replaceState'ing the CURRENT step left /welcome/ with a single
			 * history entry, so Back fell straight out of onboarding to whatever
			 * page came before — the very bug this screen exists to fix,
			 * reproduced. Resuming at step 7 has to leave six entries behind it
			 * for Back to walk through.
			 */
			history.replaceState( { step: 0 }, '' );
			for ( var i = 1; i <= idx; i++ ) {
				history.pushState( { step: i }, '' );
			}
			draw();
		} ).catch( fail );
	}

	function fail() {
		main.innerHTML = '';
		var c = el( 'div', 'csm-w-card' );
		c.appendChild( el( 'h1', 'csm-w-q', 'Something went wrong' ) );
		c.appendChild( el( 'p', 'csm-w-help', 'We could not load your profile setup. Please refresh to try again.' ) );
		main.appendChild( c );
	}

	/* Browser/hardware back moves one step back, and only leaves the flow from
	   the first step. */
	window.addEventListener( 'popstate', function ( e ) {
		var target = e.state && typeof e.state.step === 'number' ? e.state.step : 0;
		idx = Math.max( 0, Math.min( target, steps.length - 1 ) );
		draw( true );
	} );

	function go( next ) {
		idx = next;
		if ( idx >= steps.length ) { return finish(); }
		history.pushState( { step: idx }, '' );
		draw();
	}

	function back() {
		if ( idx > 0 ) { history.back(); }
	}

	/* -------------------------------------------------------------- draw */

	function draw( skipPush ) {
		var s = steps[ idx ];
		if ( ! s ) { return finish(); }

		main.innerHTML = '';
		setProgress();

		var card = el( 'div', 'csm-w-card' );

		var count = el( 'p', 'csm-w-count', 'Step ' + ( idx + 1 ) + ' of ' + steps.length );
		card.appendChild( count );

		card.appendChild( el( 'h1', 'csm-w-q', s.label ) );
		if ( s.help ) { card.appendChild( el( 'p', 'csm-w-help', s.help ) ); }

		var err = el( 'p', 'csm-w-err' );
		err.setAttribute( 'role', 'alert' );
		card.appendChild( err );

		var field = ( 'photo' === s.type ) ? photoField( s, err ) : groupField( s );
		card.appendChild( field.node );

		var nav = el( 'div', 'csm-w-nav' );
		if ( idx > 0 ) {
			var b = el( 'button', 'csm-w-back', '← Back' );
			b.type = 'button';
			b.addEventListener( 'click', back );
			nav.appendChild( b );
		}
		var next = el( 'button', 'csm-w-next', idx === steps.length - 1 ? 'Finish' : 'Continue' );
		next.type = 'button';
		next.addEventListener( 'click', function () { submit( s, field, err, next ); } );
		nav.appendChild( next );
		card.appendChild( nav );

		main.appendChild( card );

		/* Report the step as a synthetic pageview. Without this GA4 sees a single
		   page for all of onboarding and per-step drop-off — the main thing worth
		   knowing about a signup funnel — is unmeasurable. */
		if ( typeof window.csmTrack === 'function' ) {
			window.csmTrack( 'step', s.label, idx + 1, steps.length );
		}

		if ( field.focus ) { try { field.focus(); } catch ( e ) {} }
		if ( skipPush ) { /* popstate already moved history */ }
	}

	/* ------------------------------------------------------------ fields */

	/**
	 * One field's control (options as taps, or an input). Reads a FIELD object
	 * from a group step, not the step itself.
	 */
	function fieldControl( f ) {
		var wrap = el( 'div', 'csm-w-field' );
		var opts = f.options || [];
		var node;

		if ( opts.length ) {
			/* Options render as tap targets, not a dropdown — one thumb, one tap.
			   A multi-value field (Hobbies and Interests is a checkbox with twelve
			   options) toggles; a single-value field clears the others. Without
			   this every option list was exclusive, so a member could save exactly
			   one hobby out of twelve. */
			node = el( 'div', 'csm-w-opts' + ( f.multi ? ' is-multi' : '' ) );
			var chosen = f.multi
				? ( Array.isArray( f.value ) ? f.value : ( f.value ? [ f.value ] : [] ) )
				: [ f.value || '' ];

			opts.forEach( function ( o ) {
				var on = chosen.indexOf( o ) !== -1;
				var b = el( 'button', 'csm-w-opt' + ( on ? ' is-on' : '' ), o );
				b.type = 'button';
				b.setAttribute( 'data-val', o );
				b.addEventListener( 'click', function () {
					if ( f.multi ) {
						b.classList.toggle( 'is-on' );
						return;
					}
					[].forEach.call( node.querySelectorAll( '.csm-w-opt' ), function ( x ) {
						x.classList.remove( 'is-on' );
					} );
					b.classList.add( 'is-on' );
				} );
				node.appendChild( b );
			} );

			wrap.appendChild( node );
			return {
				node: wrap,
				value: function () {
					var on = [].filter.call( node.querySelectorAll( '.csm-w-opt' ), function ( x ) {
						return x.classList.contains( 'is-on' );
					} ).map( function ( x ) { return x.getAttribute( 'data-val' ); } );
					return f.multi ? on : ( on[0] || '' );
				}
			};
		}

		if ( 'textarea' === f.type ) {
			node = el( 'textarea', 'csm-w-input' );
			node.rows = 4;
		} else {
			node = el( 'input', 'csm-w-input' );
			node.type = ( 'datebox' === f.type ) ? 'date'
				: ( 'telephone' === f.type ) ? 'tel'
				: ( 'number' === f.type ) ? 'number' : 'text';
		}
		node.value = f.value || '';
		wrap.appendChild( node );

		return {
			node: wrap,
			value: function () { return node.value; },
			focus: function () { node.focus(); }
		};
	}

	/**
	 * A whole section: each of its fields, labelled, with one Continue. Required
	 * fields must be answered; optional ones may be left blank.
	 */
	function groupField( s ) {
		var wrap = el( 'div', 'csm-w-group' );
		var controls = [];
		( s.fields || [] ).forEach( function ( f ) {
			var fw = el( 'div', 'csm-w-groupfield' );
			var lbl = el( 'label', 'csm-w-flabel' );
			lbl.appendChild( document.createTextNode( f.label ) );
			if ( f.required ) { lbl.appendChild( el( 'span', 'csm-w-req', 'Required' ) ); }
			fw.appendChild( lbl );
			if ( f.help ) { fw.appendChild( el( 'p', 'csm-w-fhelp', f.help ) ); }
			var ctl = fieldControl( f );
			fw.appendChild( ctl.node );
			wrap.appendChild( fw );
			controls.push( { f: f, read: ctl.value } );
		} );
		return {
			node: wrap,
			controls: controls,
			values: function () {
				var m = {};
				controls.forEach( function ( c ) { m[ c.f.id ] = c.read(); } );
				return m;
			},
			focus: function () { var i = wrap.querySelector( 'input, textarea, .csm-w-opt' ); if ( i && i.focus ) { i.focus(); } }
		};
	}

	/**
	 * Photo step. Uploads to BuddyPress's own avatar endpoint.
	 *
	 * If that upload fails for any reason we do NOT strand the member on a
	 * mandatory step with no way past it — the existing avatar screen is offered
	 * as a way through. A hard requirement that can deadlock is worse than the
	 * problem it solves.
	 */
	function photoField( s, err ) {
		var wrap = el( 'div', 'csm-w-photo' );

		// Thumbnails of chosen photos; the first is the main (avatar).
		var strip = el( 'div', 'csm-w-thumbs' );
		wrap.appendChild( strip );

		// Where the cropper mounts while a photo is being positioned.
		var cropHost = el( 'div', 'csm-w-crophost' );
		cropHost.style.display = 'none';
		wrap.appendChild( cropHost );

		var note = el( 'p', 'csm-w-note' );
		wrap.appendChild( note );

		var file = el( 'input' );
		file.type = 'file';
		file.accept = 'image/*';
		file.className = 'csm-w-file';
		file.style.display = 'none';
		wrap.appendChild( file );

		var addBtn = el( 'button', 'csm-w-pick' );
		addBtn.type = 'button';
		addBtn.textContent = 'Choose a photo';
		wrap.appendChild( addBtn );

		var photos = [];          // { blob, url }
		var uploaded = !! s.done;  // an earlier onboarding already uploaded
		var activeCrop = null;

		function renderThumbs() {
			strip.innerHTML = '';
			photos.forEach( function ( p, i ) {
				var t = el( 'div', 'csm-w-thumb' + ( i === 0 ? ' is-main' : '' ) );
				var im = el( 'img' ); im.src = p.url; t.appendChild( im );
				if ( i === 0 ) { t.appendChild( el( 'span', 'csm-w-thumb-main', 'Main' ) ); }
				var x = el( 'button', 'csm-w-thumb-x' ); x.type = 'button';
				x.setAttribute( 'aria-label', 'Remove photo' ); x.innerHTML = '&times;';
				x.onclick = function () { photos.splice( i, 1 ); renderThumbs(); syncAdd(); };
				t.appendChild( x );
				strip.appendChild( t );
			} );
		}

		function syncAdd() {
			var max = CFG.photoMax || 6;
			addBtn.textContent = photos.length ? '+ Add another photo' : 'Choose a photo';
			addBtn.style.display = photos.length >= max ? 'none' : '';
			note.textContent = photos.length
				? ( photos.length + ' of ' + max + ' photos' )
				: 'Add at least one. Drag to reposition, pinch or slide to zoom.';
			if ( photos.length ) { uploaded = false; }
		}

		addBtn.addEventListener( 'click', function () { file.value = ''; file.click(); } );

		file.addEventListener( 'change', function () {
			var f = file.files && file.files[0];
			if ( ! f ) { return; }
			err.textContent = '';
			startCrop( f );
		} );

		function startCrop( f ) {
			// Fallback: if the cropper module did not load, use the file as-is so a
			// member is never blocked on this mandatory step.
			if ( typeof window.csmCropper !== 'function' ) {
				photos.push( { blob: f, url: URL.createObjectURL( f ) } );
				renderThumbs(); syncAdd();
				return;
			}
			cropHost.innerHTML = '';
			cropHost.style.display = 'block';
			addBtn.style.display = 'none';
			window.csmCropper( f, { aspect: CFG.cropAspect || 0.8, outW: CFG.cropOutW || 1080 } ).then( function ( cr ) {
				activeCrop = cr;
				cropHost.appendChild( cr.node );
				var actions = el( 'div', 'csm-w-cropactions' );
				var use = el( 'button', 'csm-w-next', 'Use photo' ); use.type = 'button';
				var cancel = el( 'button', 'csm-w-skip', 'Cancel' ); cancel.type = 'button';
				use.onclick = function () {
					cr.export().then( function ( blob ) {
						if ( ! blob ) { return; }
						photos.push( { blob: blob, url: URL.createObjectURL( blob ) } );
						endCrop(); renderThumbs(); syncAdd();
					} );
				};
				cancel.onclick = function () { endCrop(); syncAdd(); };
				actions.appendChild( use ); actions.appendChild( cancel );
				cropHost.appendChild( actions );
			} ).catch( function () {
				endCrop();
				err.textContent = 'Could not open that image. Please try another.';
			} );
		}

		function endCrop() {
			if ( activeCrop ) { try { activeCrop.destroy(); } catch ( e ) {} activeCrop = null; }
			cropHost.innerHTML = ''; cropHost.style.display = 'none'; addBtn.style.display = '';
		}

		// Blur choice, offered here rather than buried in settings.
		var blurWrap = el( 'label', 'csm-w-blur' );
		var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = blurred;
		blurWrap.appendChild( cb );
		var bt = el( 'span' );
		bt.appendChild( el( 'strong', null, 'Blur my photo for people I have not matched with' ) );
		bt.appendChild( el( 'em', null, 'Your matches always see it clearly. You can change this any time.' ) );
		blurWrap.appendChild( bt );
		wrap.appendChild( blurWrap );

		syncAdd();

		return {
			node: wrap,
			isPhoto: true,
			hasAny: function () { return photos.length > 0; },
			alreadyDone: function () { return uploaded; },
			photos: function () { return photos.map( function ( p ) { return p.blob; } ); },
			blur: function () { return cb.checked; },
			markDone: function () { uploaded = true; }
		};
	}


	/* ------------------------------------------------------------ submit */


	function submit( s, field, err, button ) {
		err.textContent = '';
		button.disabled = true;

		function done() { button.disabled = false; }

		if ( field.isPhoto ) {
			// Save the blur choice regardless — it is independent of the upload.
			api( CFG.step, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { key: 'blur', value: field.blur() ? 1 : 0 } )
			} ).catch( function () {} );
			blurred = field.blur();

			if ( ! field.hasAny() ) {
				if ( field.alreadyDone() ) { done(); return go( idx + 1 ); }
				done();
				err.textContent = 'Please add at least one photo to continue.';
				return;
			}

			/*
			 * All photos go to the gallery in one request. The gallery makes the
			 * first the avatar for a member who had none (Gallery::ajax_upload), so
			 * the main photo and the photo stack stay consistent — the wizard no
			 * longer posts to BuddyPress's avatar endpoint separately.
			 */
			var blobs = field.photos();
			var fd = new FormData();
			fd.append( 'action', 'csm_ph_upload' );
			fd.append( 'nonce', CFG.photoNonce );
			blobs.forEach( function ( b, i ) { fd.append( 'photos[]', b, 'photo' + ( i + 1 ) + '.jpg' ); } );

			fetch( CFG.photoAjax, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					done();
					if ( d && d.success ) {
						field.markDone();
						steps[ idx ].done = true;
						return go( idx + 1 );
					}
					offerFallback( err, d && d.data );
				} )
				.catch( function () { done(); offerFallback( err, null ); } );
			return;
		}

		// Group step: gather every field, require the mandatory ones.
		var values = field.values();
		var missing = null;
		field.controls.forEach( function ( c ) {
			if ( missing || ! c.f.required ) { return; }
			var v = values[ c.f.id ];
			var empty = ! v || ( typeof v === 'string' && ! v.trim() ) || ( Array.isArray( v ) && ! v.length );
			if ( empty ) { missing = c.f; }
		} );
		if ( missing ) {
			done();
			err.textContent = missing.label + ' is required.';
			return;
		}

		/* The save can take a second or two. Without this the Continue button
		   looks inert and people tap it again, firing a second save. */
		var release = window.csmBusy ? window.csmBusy( document.querySelector( '.csm-w-next' ) ) : function () {};

		api( CFG.step, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { key: s.key, fields: values } )
		} ).then( function ( d ) {
			release();
			done();
			if ( d && d.ok ) {
				steps[ idx ].done = true;
				return go( idx + 1 );
			}
			err.textContent = ( d && d.message ) || 'We could not save that. Please try again.';
		} ).catch( function () {
			release();
			done();
			err.textContent = 'Network problem. Please try again.';
		} );
	}

	function offerFallback( err, d ) {
		err.textContent = ( d && d.message ) || 'That photo could not be uploaded.';
		if ( ! CFG.fallback || err.querySelector( 'a' ) ) { return; }
		err.appendChild( document.createTextNode( ' ' ) );
		var a = document.createElement( 'a' );
		a.href = CFG.fallback;
		a.textContent = 'Upload it here instead';
		err.appendChild( a );
	}

	/* ----------------------------------------------------------- finish */

	function finish() {
		main.innerHTML = '';
		if ( progress ) { progress.style.width = '100%'; }

		var card = el( 'div', 'csm-w-card' );
		card.appendChild( el( 'h1', 'csm-w-q', 'You are all set' ) );
		card.appendChild( el( 'p', 'csm-w-help', 'Taking you to Discover…' ) );
		main.appendChild( card );

		api( CFG.complete, { method: 'POST' } ).then( function ( d ) {
			if ( d && d.ok ) {
				// The server claims each event exactly once, so a refresh cannot
				// double-count; this just sends what it was handed.
				fireConversions( d.events );
				window.location.href = d.redirect;
				return;
			}
			// Server disagrees that we are done — trust it and go back to the gap.
			if ( d && d.stepKey ) {
				for ( var i = 0; i < steps.length; i++ ) {
					if ( steps[ i ].key === d.stepKey ) { idx = i; break; }
				}
			} else {
				idx = 0;
			}
			draw();
		} ).catch( fail );
	}

	/**
	 * Fire whatever the server said this member still owed.
	 *
	 * The list comes from the server, which claims each event exactly once — this
	 * side never decides whether something is a duplicate, because it cannot know
	 * (a second device has its own JS).
	 */
	function fireConversions( events ) {
		if ( typeof window.csmTrack !== 'function' ) { return; }
		( events || [] ).forEach( function ( e ) { window.csmTrack( e ); } );
	}

	boot();
} )();
