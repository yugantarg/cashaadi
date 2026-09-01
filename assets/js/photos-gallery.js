/**
 * Photo gallery / onboarding behaviour.
 * Extracted verbatim (logic-for-logic) from the inline <script> blocks of
 * WPCode #11822 (uploader, profile-gallery set-main, lightbox), #11771
 * (privacy-notice zoom overlay, driven by window.CSM_PN) and #11838 (move the
 * privacy control below the onboarding uploader). AJAX actions, the "csm_ph"
 * nonce field, class names and copy are unchanged.
 */
(function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/* ---- Uploader ( [csm_photos] / .csm-ph ) --------------------------- */
	function initUploader( root ) {
		if ( ! root || root.__csmPhInit ) { return; }
		root.__csmPhInit = 1;
		var ajax = root.getAttribute( 'data-ajax' ), nonce = root.getAttribute( 'data-nonce' );
		var body = root.querySelector( '.csm-ph-body' ), msg = root.querySelector( '.csm-ph-msg' );
		function say( t, ok ) { if ( msg ) { msg.textContent = t || ''; msg.className = 'csm-ph-msg' + ( ok ? ' ok' : '' ); } }
		function post( action, data ) {
			var fd = ( data instanceof FormData ) ? data : new FormData();
			fd.append( 'action', action ); fd.append( 'nonce', nonce );
			if ( data && ! ( data instanceof FormData ) ) { for ( var k in data ) { fd.append( k, data[ k ] ); } }
			root.classList.add( 'is-busy' );
			return fetch( ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					root.classList.remove( 'is-busy' );
					if ( j && j.success ) { body.innerHTML = j.data.html; bind(); return j.data; }
					say( ( j && j.data && j.data.message ) || 'Something went wrong.' ); return null;
				} ).catch( function () { root.classList.remove( 'is-busy' ); say( 'Network error, please try again.' ); } );
		}
		function bind() {
			var inp = root.querySelector( '.csm-ph-add input[type=file]' );
			if ( inp ) {
				inp.onchange = function () {
					if ( ! inp.files.length ) { return; }
					say( 'Uploading…', true );
					var fd = new FormData();
					for ( var i = 0; i < inp.files.length; i++ ) { fd.append( 'photos[]', inp.files[ i ] ); }
					post( 'csm_ph_upload', fd ).then( function ( d ) { if ( d ) { say( 'Photos updated.', true ); } } );
				};
			}
			root.querySelectorAll( '.csm-ph-del' ).forEach( function ( b ) { b.onclick = function () { if ( ! confirm( 'Remove this photo?' ) ) { return; } post( 'csm_ph_delete', { id: b.getAttribute( 'data-id' ) } ); }; } );
			root.querySelectorAll( '.csm-ph-setmain' ).forEach( function ( b ) { b.onclick = function () { post( 'csm_ph_main', { id: b.getAttribute( 'data-id' ) } ).then( function ( d ) { if ( d ) { say( 'Main photo updated.', true ); } } ); }; } );
		}
		bind();
	}

	/* ---- Profile gallery "Set as main" ( .csm-ph-gallery ) ------------- */
	function initGallery( g ) {
		if ( ! g || g.__init ) { return; }
		g.__init = 1;
		var ajax = g.getAttribute( 'data-ajax' ), nonce = g.getAttribute( 'data-nonce' );
		g.querySelectorAll( '.csm-ph-gmain' ).forEach( function ( b ) {
			b.onclick = function () {
				var fd = new FormData(); fd.append( 'action', 'csm_ph_main' ); fd.append( 'nonce', nonce ); fd.append( 'id', b.getAttribute( 'data-id' ) );
				b.disabled = true; b.textContent = 'Saving…';
				fetch( ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
					if ( j && j.success ) { location.reload(); } else { b.disabled = false; b.textContent = 'Set as main'; }
				} ).catch( function () { b.disabled = false; b.textContent = 'Set as main'; } );
			};
		} );
	}

	/* ---- Gallery lightbox ( click .csm-ph-lb ) ------------------------- */
	function initLightbox() {
		if ( window.__csmPhLbInit ) { return; }
		window.__csmPhLbInit = 1;
		var lb = null, img = null;
		function ensure() {
			if ( lb ) { return; }
			lb = document.createElement( 'div' );
			lb.id = 'csm-ph-lb';
			img = document.createElement( 'img' );
			img.alt = '';
			var x = document.createElement( 'button' );
			x.type = 'button'; x.setAttribute( 'aria-label', 'Close' ); x.className = 'csm-ph-lb-close'; x.innerHTML = '&times;';
			lb.appendChild( img ); lb.appendChild( x );
			document.body.appendChild( lb );
			lb.addEventListener( 'click', function ( e ) { if ( e.target !== img ) { close(); } } );
		}
		function open( src ) { ensure(); img.src = src; lb.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
		function close() { if ( lb ) { lb.style.display = 'none'; img.src = ''; } document.body.style.overflow = ''; }
		document.addEventListener( 'click', function ( e ) { var a = e.target.closest( '.csm-ph-lb' ); if ( ! a ) { return; } e.preventDefault(); open( a.getAttribute( 'href' ) ); } );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { close(); } } );
	}

	/* ---- Privacy-notice avatar zoom ( window.CSM_PN, #11771 ) ---------- */
	function initNoticeZoom() {
		var cfg = window.CSM_PN;
		if ( ! cfg || ! cfg.url ) { return; }
		var host = document.getElementById( 'item-header-avatar' );
		if ( ! host ) { return; }
		var img = host.querySelector( 'img' );
		if ( ! img ) { return; }

		/* Don't offer to enlarge a photo that does not exist.
		   Reported live 2026-09-01: tapping a blank profile photo opened the
		   lightbox on the placeholder — a full-screen mystery-man. The zoom was
		   attached whenever CSM_PN.url was set, and that is set even when the
		   member has never uploaded anything, because the avatar then falls back
		   to Gravatar's default (d=mm) or BuddyPress's own mystery-man image. */
		function isPlaceholder( url ) {
			if ( ! url ) { return true; }
			return /gravatar\.com/i.test( url )
				|| /mystery-?man/i.test( url )
				|| /\/bp-core\/images\//i.test( url );
		}
		if ( isPlaceholder( cfg.url ) && isPlaceholder( img.getAttribute( 'src' ) ) ) {
			return;
		}

		img.className = img.className + ' csm-pn-zoom';
		img.setAttribute( 'title', cfg.hidden ? 'View photo (blurred)' : 'View full photo' );
		var opener = img.closest( 'a' ) || img;
		opener.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var ov = document.createElement( 'div' );
			ov.className = 'csm-pn-overlay';
			var box = document.createElement( 'div' );
			box.className = 'csm-pn-box';
			var big = document.createElement( 'img' );
			big.className = 'csm-pn-img';
			big.src = cfg.url;
			big.alt = cfg.hidden ? 'Blurred profile photo' : 'Profile photo';
			box.appendChild( big );
			if ( cfg.hidden && cfg.notice ) {
				var n = document.createElement( 'div' );
				n.className = 'csm-pn-note';
				n.textContent = cfg.notice;
				box.appendChild( n );
				if ( cfg.pricing ) {
					var a = document.createElement( 'a' );
					a.className = 'csm-pn-cta';
					a.href = cfg.pricing;
					a.textContent = cfg.cta;
					box.appendChild( a );
				}
			}
			var x = document.createElement( 'button' );
			x.type = 'button';
			x.className = 'csm-pn-close';
			x.textContent = 'Close';
			box.appendChild( x );
			ov.appendChild( box );
			document.body.appendChild( ov );
			function shut() {
				if ( ov.parentNode ) { ov.parentNode.removeChild( ov ); }
				document.removeEventListener( 'keyup', esc );
			}
			function esc( ev ) { if ( ev.key === 'Escape' ) { shut(); } }
			ov.addEventListener( 'click', function ( ev ) { if ( ev.target === ov ) { shut(); } } );
			x.addEventListener( 'click', shut );
			document.addEventListener( 'keyup', esc );
		} );
	}

	/* ---- Onboarding: move privacy control below uploader ( #11838 ) ---- */
	function initOnboardMove() {
		var o = document.querySelector( '.csm-ph-onboard' );
		var p = document.querySelector( '.csm-photo-privacy' );
		if ( o && p ) { o.parentNode.insertBefore( p, o.nextSibling ); }
	}

	ready( function () {
		document.querySelectorAll( '.csm-ph' ).forEach( initUploader );
		initGallery( document.querySelector( '.csm-ph-gallery[data-nonce]' ) );
		initLightbox();
		initNoticeZoom();
		initOnboardMove();
	} );
} )();
