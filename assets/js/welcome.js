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
			history.replaceState( { step: idx }, '' );
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

		var field = ( 'photo' === s.type ) ? photoField( s, err ) : inputField( s );
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
		if ( field.focus ) { try { field.focus(); } catch ( e ) {} }
		if ( skipPush ) { /* popstate already moved history */ }
	}

	/* ------------------------------------------------------------ fields */

	function inputField( s ) {
		var wrap = el( 'div', 'csm-w-field' );
		var opts = s.options || [];
		var node;

		if ( opts.length ) {
			// Options render as tap targets, not a dropdown — one thumb, one tap.
			node = el( 'div', 'csm-w-opts' );
			var chosen = s.value || '';
			opts.forEach( function ( o ) {
				var b = el( 'button', 'csm-w-opt' + ( o === chosen ? ' is-on' : '' ), o );
				b.type = 'button';
				b.setAttribute( 'data-val', o );
				b.addEventListener( 'click', function () {
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
					var on = node.querySelector( '.csm-w-opt.is-on' );
					return on ? on.getAttribute( 'data-val' ) : '';
				}
			};
		}

		if ( 'textarea' === s.type ) {
			node = el( 'textarea', 'csm-w-input' );
			node.rows = 4;
		} else {
			node = el( 'input', 'csm-w-input' );
			node.type = ( 'datebox' === s.type ) ? 'date'
				: ( 'telephone' === s.type ) ? 'tel'
				: ( 'number' === s.type ) ? 'number' : 'text';
		}
		node.value = s.value || '';
		wrap.appendChild( node );

		return {
			node: wrap,
			value: function () { return node.value; },
			focus: function () { node.focus(); }
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

		var preview = el( 'div', 'csm-w-preview' );
		var img = el( 'img', 'csm-w-preview-img' );
		img.alt = '';
		img.style.display = 'none';
		preview.appendChild( img );
		var ph = el( 'span', 'csm-w-preview-ph', 'No photo yet' );
		preview.appendChild( ph );
		wrap.appendChild( preview );

		var pick = el( 'label', 'csm-w-pick', 'Choose a photo' );
		var file = el( 'input' );
		file.type = 'file';
		file.accept = 'image/*';
		file.className = 'csm-w-file';
		pick.appendChild( file );
		wrap.appendChild( pick );

		var uploaded = !! s.done;

		file.addEventListener( 'change', function () {
			var f = file.files && file.files[0];
			if ( ! f ) { return; }
			err.textContent = '';
			img.src = URL.createObjectURL( f );
			img.style.display = 'block';
			ph.style.display = 'none';
			uploaded = false;
		} );

		// Blur choice, offered here rather than buried in settings. Default off:
		// the meta is simply absent until opted in.
		var blurWrap = el( 'label', 'csm-w-blur' );
		var cb = el( 'input' );
		cb.type = 'checkbox';
		cb.checked = blurred;
		blurWrap.appendChild( cb );
		var bt = el( 'span' );
		bt.appendChild( el( 'strong', null, 'Blur my photo for people I have not matched with' ) );
		bt.appendChild( el( 'em', null, 'Your matches always see it clearly. You can change this any time.' ) );
		blurWrap.appendChild( bt );
		wrap.appendChild( blurWrap );

		return {
			node: wrap,
			isPhoto: true,
			hasFile: function () { return !! ( file.files && file.files[0] ); },
			alreadyDone: function () { return uploaded; },
			file: function () { return file.files[0]; },
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

			if ( ! field.hasFile() ) {
				if ( field.alreadyDone() ) { done(); return go( idx + 1 ); }
				done();
				err.textContent = 'Please choose a photo to continue.';
				return;
			}

			var fd = new FormData();
			fd.append( 'file', field.file() );
			api( CFG.avatar, { method: 'POST', body: fd } ).then( function ( d ) {
				done();
				if ( d && ( d.avatar_urls || d.full || d.id ) ) {
					field.markDone();
					steps[ idx ].done = true;
					return go( idx + 1 );
				}
				offerFallback( err, d );
			} ).catch( function () {
				done();
				offerFallback( err, null );
			} );
			return;
		}

		var val = field.value();
		if ( ! val || ! String( val ).trim() ) {
			done();
			err.textContent = 'Please answer this to continue.';
			return;
		}

		api( CFG.step, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { key: s.key, value: val } )
		} ).then( function ( d ) {
			done();
			if ( d && d.ok ) {
				steps[ idx ].value = val;
				steps[ idx ].done  = true;
				return go( idx + 1 );
			}
			err.textContent = ( d && d.message ) || 'We could not save that. Please try again.';
		} ).catch( function () {
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
				// Conversions fire only when the SERVER says this is the first
				// completion, so a refresh cannot double-count.
				if ( d.fireEvents ) { fireConversions(); }
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

	/* Placeholder until the tracking slice lands; kept here so `fireEvents` has
	   exactly one call site to grow into. */
	function fireConversions() {
		try {
			if ( typeof window.gtag === 'function' ) {
				window.gtag( 'event', 'sign_up', { method: 'welcome' } );
			}
		} catch ( e ) {}
	}

	boot();
} )();
