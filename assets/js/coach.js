/**
 * Coach — one translucent spotlight, used by the tour and the action explainers.
 *
 * The overlay is a single element with an enormous spread box-shadow, so the
 * "hole" is the element's own box and the dimming is the shadow around it. That
 * avoids four positioned panels that have to be kept in sync, and it means the
 * cut-out follows the target's real rectangle including its border radius.
 *
 * Nothing here writes to localStorage: what a member has seen is server-side, in
 * user meta, so a tour finished on a phone stays finished on a laptop.
 */
( function () {
	'use strict';

	var CFG = window.CSM_COACH;
	if ( ! CFG ) { return; }

	var seen = ( CFG.seen || [] ).slice();
	var open = false;

	function has( key ) { return seen.indexOf( key ) > -1; }

	/** Remember, locally and on the server. Failure is silent and harmless — the
	 *  worst case is a member being shown one hint twice. */
	function remember( key ) {
		if ( has( key ) ) { return; }
		seen.push( key );
		if ( ! CFG.mark ) { return; }
		fetch( CFG.mark, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify( { key: key } )
		} ).catch( function () {} );
	}

	/**
	 * First VISIBLE match for a selector, or null.
	 *
	 * Existence is not enough. The app renders two navigations — a bottom bar for
	 * mobile and a left rail for desktop — and the one for the other breakpoint is
	 * still in the DOM with display:none. querySelector happily returned the
	 * hidden one, which put the spotlight at 0×0 in the corner. A zero-sized
	 * rectangle is the tell, and it is worth checking for its own sake: a target
	 * that cannot be seen must never be spotlighted.
	 */
	function visible( selector ) {
		var list = document.querySelectorAll( selector );
		for ( var i = 0; i < list.length; i++ ) {
			var r = list[ i ].getBoundingClientRect();
			if ( r.width > 0 && r.height > 0 ) { return list[ i ]; }
		}
		return null;
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined ) { n.textContent = text; }
		return n;
	}

	/**
	 * Show one step.
	 *
	 * @param {Object} o
	 *   target  CSS selector, or null for a centred card with no spotlight.
	 *   title   Heading.
	 *   body    One or two sentences.
	 *   cta     Button label.
	 *   skip    Optional secondary label (the tour uses "Skip").
	 *   onCta   Called after the card closes.
	 *   onSkip  Called after a skip.
	 */
	function step( o ) {
		if ( open ) { return; }
		open = true;

		var back = el( 'div', 'csm-coach' );
		var hole = el( 'div', 'csm-coach-hole' );
		var card = el( 'div', 'csm-coach-card' );

		var node = o.target ? visible( o.target ) : null;
		if ( node ) {
			var r = node.getBoundingClientRect();
			var pad = 8;
			hole.style.top    = ( r.top - pad ) + 'px';
			hole.style.left   = ( r.left - pad ) + 'px';
			hole.style.width  = ( r.width + pad * 2 ) + 'px';
			hole.style.height = ( r.height + pad * 2 ) + 'px';
			back.appendChild( hole );

			/* Put the card on whichever side has more room, so a target near the
			   bottom (the nav) gets a card above it rather than off-screen. */
			var below = window.innerHeight - r.bottom;
			card.classList.add( below > 260 ? 'is-below' : 'is-above' );
			if ( below > 260 ) {
				card.style.top = ( r.bottom + 18 ) + 'px';
			} else {
				card.style.bottom = ( window.innerHeight - r.top + 18 ) + 'px';
			}
		} else {
			back.classList.add( 'is-plain' );
			card.classList.add( 'is-centred' );
		}

		if ( o.title ) { card.appendChild( el( 'h2', 'csm-coach-title', o.title ) ); }
		if ( o.body )  { card.appendChild( el( 'p', 'csm-coach-body', o.body ) ); }

		var row = el( 'div', 'csm-coach-row' );
		if ( o.skip ) {
			var s = el( 'button', 'csm-coach-skip', o.skip );
			s.type = 'button';
			s.addEventListener( 'click', function () { close(); if ( o.onSkip ) { o.onSkip(); } } );
			row.appendChild( s );
		}
		var b = el( 'button', 'csm-coach-cta', o.cta || 'Got it' );
		b.type = 'button';
		b.addEventListener( 'click', function () { close(); if ( o.onCta ) { o.onCta(); } } );
		row.appendChild( b );
		card.appendChild( row );

		back.appendChild( card );
		document.body.appendChild( back );
		requestAnimationFrame( function () { back.classList.add( 'is-in' ); } );
		b.focus();

		/*
		 * Escape and a backdrop tap take the SAFE way out.
		 *
		 * Originally Escape ran the primary action, on the reasoning that the
		 * member had already asked to do the thing. That is wrong wherever the
		 * primary action is destructive: dismissing a card that says "passing is
		 * final" must not be what performs the pass. So if the step offers a
		 * secondary (Cancel on an explainer, Skip on the tour) that is what a
		 * dismissal does; only a step with no way out falls through to the CTA.
		 */
		function dismiss() {
			close();
			if ( o.onSkip ) { o.onSkip(); return; }
			if ( o.onCta ) { o.onCta(); }
		}
		function onKey( e ) { if ( 'Escape' === e.key ) { dismiss(); } }
		document.addEventListener( 'keydown', onKey );
		back.addEventListener( 'click', function ( e ) { if ( e.target === back ) { dismiss(); } } );

		function close() {
			document.removeEventListener( 'keydown', onKey );
			back.classList.remove( 'is-in' );
			setTimeout( function () { if ( back.parentNode ) { back.parentNode.removeChild( back ); } }, 200 );
			open = false;
		}
	}

	/**
	 * Explain an action the first time it is used, then carry it out.
	 *
	 * If it has been explained before, `go` runs immediately — the explainer must
	 * never become a second tap on every action forever.
	 */
	function explain( name, go ) {
		var key = 'action_' + name;
		var copy = ( CFG.actions || {} )[ name ];
		if ( ! copy || has( key ) ) { return go(); }

		step( {
			title: copy.title,
			body:  copy.body,
			cta:   copy.cta,
			skip:  copy.cancel || 'Cancel',
			/*
			 * Only remember it once they GO THROUGH with it. If they read
			 * "passing is final" and back out, the warning has not done its job
			 * yet — it should still be there the next time they reach for the
			 * button, not silently spent.
			 */
			onCta:  function () { remember( key ); go(); },
			onSkip: function () {}
		} );
	}

	/** The new-account walkthrough. One key for the whole tour. */
	function tour() {
		var steps = CFG.tour || [];
		if ( ! steps.length || has( 'tour_v1' ) ) { return; }
		if ( ! visible( steps[0].target ) ) { return; } // no navigation on screen to point at
		remember( 'tour_v1' );

		var i = 0;
		( function next() {
			if ( i >= steps.length ) { return; }
			var s = steps[ i++ ];
			step( {
				target: s.target,
				title:  s.title,
				body:   s.body,
				cta:    i >= steps.length ? 'Start looking' : 'Next',
				skip:   i < steps.length ? 'Skip' : '',
				onCta:  next,
				onSkip: function () {}
			} );
		}() );
	}

	window.csmCoach = { step: step, explain: explain, tour: tour, has: has, remember: remember };
}() );
