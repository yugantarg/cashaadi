/**
 * Conversion tracking for the onboarding flow.
 *
 * The server decides WHAT to fire (see Events::claim — exactly once per member,
 * per event). This file only decides HOW to say it to Google and Meta, and
 * exposes window.csmTrack() so welcome.js can report step views.
 *
 * LOADING TAGS WITHOUT DUPLICATING THEM
 * Site Kit already loads gtag on this site. Loading a second copy would double
 * every GA4 hit, so the loaders below run only when the global is genuinely
 * absent, and gtag's own config() is used to attach our IDs to whichever
 * instance ends up being the live one.
 */
( function () {
	'use strict';

	var T = window.CSM_TRACK;
	if ( ! T ) { return; }

	if ( ! T.enabled ) {
		// Say so once: a silent no-op here is indistinguishable from a bug.
		if ( window.console && console.info ) {
			console.info( '[CAShaadi] Conversion tracking is off (Settings → CA Shaadi Tracking).' );
		}
		window.csmTrack = function () {};
		return;
	}

	/* ---------------------------------------------------------- gtag ---- */

	function ensureGtag( cb ) {
		window.dataLayer = window.dataLayer || [];
		if ( typeof window.gtag !== 'function' ) {
			window.gtag = function () { window.dataLayer.push( arguments ); };
			window.gtag( 'js', new Date() );
		}

		// Only inject the library if nothing else already did.
		var already = document.querySelector( 'script[src*="googletagmanager.com/gtag/js"]' );
		if ( ! already ) {
			var first = T.ga4Id || T.gadsId;
			if ( first ) {
				var s = document.createElement( 'script' );
				s.async = true;
				s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( first );
				s.onload = cb;
				s.onerror = cb; // blocked: carry on, the queue just never drains
				document.head.appendChild( s );
			} else {
				cb();
			}
		} else {
			cb();
		}

		// config() is safe to call repeatedly and is how our IDs get attached to
		// an instance someone else loaded.
		if ( T.ga4Id ) { window.gtag( 'config', T.ga4Id, { send_page_view: false } ); }
		if ( T.gadsId ) { window.gtag( 'config', T.gadsId ); }
	}

	/* ----------------------------------------------------------- meta ---- */

	function ensureFbq() {
		if ( ! T.metaPixel ) { return; }
		if ( typeof window.fbq === 'function' ) { return; }

		/* eslint-disable */
		!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
		n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
		(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
		/* eslint-enable */

		window.fbq( 'init', T.metaPixel );
	}

	/* --------------------------------------------------------- events ---- */

	/**
	 * What each milestone means to each platform.
	 *
	 * The Google Ads conversion is attached to SIGNUP only — the account being
	 * activated. Firing it again at onboarding_complete would count one member
	 * twice against the same conversion action.
	 */
	var MAP = {
		signup: {
			ga4:  { name: 'sign_up', params: { method: 'email' } },
			ads:  true,
			meta: 'CompleteRegistration'
		},
		onboarding_start: {
			ga4:  { name: 'onboarding_start', params: {} },
			ads:  false,
			meta: null
		},
		onboarding_complete: {
			ga4:  { name: 'onboarding_complete', params: {} },
			ads:  false,
			meta: 'Lead'
		}
	};

	function fire( event ) {
		var def = MAP[ event ];
		if ( ! def ) { return; }

		try {
			if ( T.ga4Id && def.ga4 && typeof window.gtag === 'function' ) {
				window.gtag( 'event', def.ga4.name, def.ga4.params );
			}
			if ( def.ads && T.gadsLabel && typeof window.gtag === 'function' ) {
				window.gtag( 'event', 'conversion', { send_to: T.gadsLabel } );
			}
			if ( def.meta && T.metaPixel && typeof window.fbq === 'function' ) {
				window.fbq( 'track', def.meta );
			}
		} catch ( e ) {
			// Never let a tag break onboarding.
		}
	}

	/**
	 * Step views, as synthetic pageviews.
	 *
	 * Without these GA4 sees one page for the whole of onboarding and drop-off
	 * per step is unmeasurable — which is the main thing you want to know about a
	 * signup funnel. Not deduped: a member legitimately revisits a step by going
	 * back, and that is information, not noise.
	 */
	function stepView( label, index, total ) {
		if ( ! T.ga4Id || typeof window.gtag !== 'function' ) { return; }
		var slug = String( label || 'step' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
		try {
			window.gtag( 'event', 'page_view', {
				page_title: 'Onboarding: ' + label,
				page_location: window.location.origin + '/welcome/step/' + slug,
				page_path: '/welcome/step/' + slug,
				step_index: index,
				step_total: total
			} );
		} catch ( e ) {}
	}

	window.csmTrack = function ( what, a, b, c ) {
		if ( 'step' === what ) { return stepView( a, b, c ); }
		return fire( what );
	};

	/* ----------------------------------------------------------- boot ---- */

	ensureGtag( function () {} );
	ensureFbq();

	// Events the server said this member still owed, claimed already.
	( T.pending || [] ).forEach( fire );
} )();
