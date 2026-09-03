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

		// Optional field steps can be passed on. The photo step has its own logic.
		if ( 'photo' !== s.type && s.required === false ) {
			var skip = el( 'button', 'csm-w-skip', 'Skip for now' );
			skip.type = 'button';
			skip.addEventListener( 'click', function () { skipStep( s, next, skip, err ); } );
			card.appendChild( skip );
		}

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

		// Advisory, not an error — see the change handler below.
		var note = el( 'p', 'csm-w-note' );
		wrap.appendChild( note );

		var uploaded = !! s.done;

		file.addEventListener( 'change', function () {
			var f = file.files && file.files[0];
			if ( ! f ) { return; }
			err.textContent = '';
			var url = URL.createObjectURL( f );
			img.src = url;
			img.style.display = 'block';
			ph.style.display = 'none';
			uploaded = false;

			/* Say so if the photo is small, but do not block on it — prepare()
			   scales it up so BuddyPress accepts it. This is a note, not an error:
			   a sharper photo is better, and they may have a better one to hand. */
			var probe = new Image();
			probe.onload = function () {
				var minW = ( CFG.minPhoto && CFG.minPhoto.w ) || 1080;
				var minH = ( CFG.minPhoto && CFG.minPhoto.h ) || 1350;
				if ( probe.naturalWidth < minW || probe.naturalHeight < minH ) {
					note.textContent = 'This photo is a little small, so it may look soft. '
						+ 'A larger one will look sharper.';
				} else {
					note.textContent = '';
				}
			};
			probe.src = url;
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
			/**
			 * What we actually upload.
			 *
			 * A photo that already clears BuddyPress's floor is sent BYTE FOR
			 * BYTE — no canvas, no re-encode, so the only compression it ever
			 * sees is the single resize BuddyPress does server-side.
			 *
			 * A photo below the floor would be rejected outright, and this is a
			 * step nobody can skip, so it is scaled up to just clear it. That is
			 * soft, and it is meant to be a last resort — but it displays at the
			 * same size either way, and the alternative for that member is not
			 * being able to finish signing up at all.
			 */
			prepare: function () {
				var f = file.files[0];
				var minW = ( CFG.minPhoto && CFG.minPhoto.w ) || 1080;
				var minH = ( CFG.minPhoto && CFG.minPhoto.h ) || 1350;

				return new Promise( function ( resolve ) {
					var probe = new Image();
					probe.onload = function () {
						var w = probe.naturalWidth, h = probe.naturalHeight;
						if ( w >= minW && h >= minH ) { return resolve( f ); }

						var scale = Math.max( minW / w, minH / h );
						var cv = document.createElement( 'canvas' );
						cv.width  = Math.ceil( w * scale );
						cv.height = Math.ceil( h * scale );
						var ctx = cv.getContext( '2d' );
						ctx.imageSmoothingEnabled = true;
						ctx.imageSmoothingQuality = 'high';
						ctx.drawImage( probe, 0, 0, cv.width, cv.height );
						// 0.95 so our own re-encode costs as little as possible on
						// top of the server-side one.
						cv.toBlob( function ( b ) { resolve( b || f ); }, 'image/jpeg', 0.95 );
					};
					probe.onerror = function () { resolve( f ); };
					probe.src = URL.createObjectURL( f );
				} );
			},
			blur: function () { return cb.checked; },
			markDone: function () { uploaded = true; },
			showUploaded: function ( url ) {
				if ( ! url ) { return; }
				img.src = url;
				img.style.display = 'block';
				ph.style.display = 'none';
			}
		};
	}

	/* ------------------------------------------------------------ submit */

	function skipStep( s, nextBtn, skipBtn, err ) {
		if ( nextBtn ) { nextBtn.disabled = true; }
		if ( skipBtn ) { skipBtn.disabled = true; }
		api( CFG.step, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { key: s.key, skip: 1 } )
		} ).then( function ( d ) {
			if ( nextBtn ) { nextBtn.disabled = false; }
			if ( skipBtn ) { skipBtn.disabled = false; }
			if ( d && d.ok ) {
				steps[ idx ].done = true;
				return go( idx + 1 );
			}
			if ( err ) { err.textContent = ( d && d.message ) || 'We could not skip that.'; }
		} ).catch( function () {
			if ( nextBtn ) { nextBtn.disabled = false; }
			if ( skipBtn ) { skipBtn.disabled = false; }
			if ( err ) { err.textContent = 'Network problem. Please try again.'; }
		} );
	}

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

			field.prepare().then( function ( upload ) {
			var fd = new FormData();
			fd.append( 'file', upload, 'photo.jpg' );
			/* Required by BuddyPress. Its endpoint hands the upload to
			   wp_handle_upload(), which tests $_POST['action'] against the
			   attachment's own action name and otherwise rejects the whole thing
			   as "Invalid form submission" — verified live, 2026-09-01. */
			fd.append( 'action', 'bp_avatar_upload' );

			api( CFG.avatar, { method: 'POST', body: fd } ).then( function ( d ) {
				done();
				/* Success is an ARRAY of size variants — [{full, thumb}] — not an
				   object. Checking for d.full alone silently treated every
				   successful upload as a failure. */
				var ok = ( Array.isArray( d ) && d[0] && d[0].full ) || ( d && d.full );
				if ( ok ) {
					field.markDone();
					field.showUploaded( Array.isArray( d ) ? d[0].full : d.full );
					steps[ idx ].done = true;
					return go( idx + 1 );
				}
				if ( d && 'image_too_small' === ( d.data && d.data.reason ) ) {
					err.textContent = 'That photo is too small. Please choose one at least '
						+ ( d.data.min_width || 896 ) + ' × ' + ( d.data.min_height || 1024 ) + ' pixels.';
					return;
				}
				offerFallback( err, d );
			} ).catch( function () {
				done();
				offerFallback( err, null );
			} );
			} ).catch( function () {
				done();
				offerFallback( err, null );
			} );
			return;
		}

		var val = field.value();
		if ( ! val || ! String( val ).trim() ) {
			done();
			// Optional: an empty Continue just skips. Required: must answer.
			if ( s.required === false ) { return skipStep( s, button, null, err ); }
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
