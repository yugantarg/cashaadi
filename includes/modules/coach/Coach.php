<?php
/**
 * Coach — the translucent spotlight overlay, and the record of who has seen what.
 *
 * Two things use it and they are deliberately the same component:
 *
 *   1. The FIRST-ACTION EXPLAINER. The first time a member uses Like, Pass or
 *      Save, the click is intercepted and the overlay explains what that button
 *      does before it happens. Pass is the one that matters — it is irreversible
 *      and members need telling BEFORE they use it, not after.
 *   2. The NEW-ACCOUNT TOUR. A short walkthrough of the four screens, shown once,
 *      on a member's first visit to Discover.
 *
 * Building one component for both is not tidiness. They are the same interaction
 * — dim the screen, spotlight one thing, say one sentence, remember it was seen —
 * and two implementations would drift into looking like two different products.
 *
 * PERSISTENCE IS SERVER-SIDE, in user meta, not localStorage: a member who
 * signs up on a phone and next opens the site on a laptop has already seen the
 * tour, and being shown it again reads as a bug. It is also the only way the
 * count is true if they clear their browser.
 */

namespace CAShaadi\Modules\Coach;

use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coach {

	/** User meta holding the keys this member has already been shown. */
	const META = 'csm_coach_seen';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/coach', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_mark' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array( 'key' => array( 'required' => true ) ),
		) );
	}

	/**
	 * Keys this member has already seen.
	 *
	 * @return string[]
	 */
	public static function seen( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$v = get_user_meta( $user_id, self::META, true );
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : array();
	}

	/**
	 * Record that a key has been shown.
	 *
	 * Additive and idempotent: the client may fire this more than once for the
	 * same key (a double tap, a retried request) and it must stay one entry.
	 */
	public static function rest_mark( $request ) {
		$uid = get_current_user_id();
		$key = sanitize_key( (string) $request->get_param( 'key' ) );

		if ( ! $uid || '' === $key ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}

		$seen = self::seen( $uid );
		if ( ! in_array( $key, $seen, true ) ) {
			$seen[] = $key;
			// Cap it: keys are ours and finite, but a bug that appended forever
			// should not be able to bloat a user's meta row unbounded.
			update_user_meta( $uid, self::META, array_slice( $seen, -50 ) );
		}

		return new \WP_REST_Response( array( 'ok' => true, 'seen' => $seen ), 200 );
	}

	/**
	 * Config for the front end: what has been seen, where to record, and the tour
	 * itself.
	 *
	 * The tour lives here rather than in JavaScript so the copy is translatable
	 * and so a step can be added without touching the component.
	 */
	public static function config() {
		return array(
			'seen'  => self::seen(),
			'mark'  => rest_url( 'csm/v1/coach' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),

			/*
			 * Every step targets the navigation, which is present on all four app
			 * screens and does not move, so a step cannot end up pointing at
			 * something that has scrolled away.
			 *
			 * BOTH selectors are listed because there are two navigations: a bottom
			 * bar (.csm-appnav-item) for mobile and a left rail (.csm-app-nav-item)
			 * for desktop, and the one for the other breakpoint stays in the DOM
			 * with display:none. The component picks the first VISIBLE match — a
			 * selector alone would have found the hidden one.
			 */
			'tour'  => array(
				array(
					'target' => '.csm-app-nav-item[href*="/discover"], .csm-appnav-item[href*="/discover"]',
					'title'  => __( 'Five profiles a week', 'cashaadi-ui' ),
					'body'   => __( 'We choose a small set for you each Monday, rather than an endless list. Read the whole profile, then decide.', 'cashaadi-ui' ),
				),
				array(
					'target' => '.csm-app-nav-item[href*="/requests"], .csm-appnav-item[href*="/requests"]',
					'title'  => __( 'Requests and saves', 'cashaadi-ui' ),
					'body'   => __( 'Who has asked to match with you, who you have asked, and the profiles you saved to decide on later.', 'cashaadi-ui' ),
				),
				array(
					'target' => '.csm-app-nav-item[href*="/messages"], .csm-appnav-item[href*="/messages"]',
					'title'  => __( 'Messages open on a match', 'cashaadi-ui' ),
					'body'   => __( 'When two people both say yes, a conversation opens here. Nobody can message you before that.', 'cashaadi-ui' ),
				),
				array(
					'target' => '.csm-app-nav-item[href*="/profile"], .csm-appnav-item[href*="/profile"]',
					'title'  => __( 'A fuller profile is shown more', 'cashaadi-ui' ),
					'body'   => __( 'Add your photos and details here. Complete, active profiles are the ones we put in front of other members.', 'cashaadi-ui' ),
				),
			),

			/*
			 * The three action explainers. Pass leads because it is the only
			 * irreversible one, and the only place a member can lose something by
			 * not understanding the button.
			 */
			'actions' => array(
				'pass' => array(
					'title' => __( 'Passing is final', 'cashaadi-ui' ),
					'body'  => __( 'This profile will not come back, and they are not told. Use the arrows to look through everyone before you decide.', 'cashaadi-ui' ),
					'cta'   => __( 'Got it, pass', 'cashaadi-ui' ),
				),
				'like' => array(
					'title' => __( 'Liking sends a request', 'cashaadi-ui' ),
					'body'  => __( 'They will see that someone is interested and can accept. If they do, it is a match and you can message each other.', 'cashaadi-ui' ),
					'cta'   => __( 'Got it, like', 'cashaadi-ui' ),
				),
				'save' => array(
					'title' => __( 'Save to decide later', 'cashaadi-ui' ),
					'body'  => __( 'Nothing is sent and they are not told. The profile moves to Requests → Saved, where you can like or pass whenever you are ready.', 'cashaadi-ui' ),
					'cta'   => __( 'Got it, save', 'cashaadi-ui' ),
				),
			),
		);
	}

	/** Enqueue the component. Called by AppPage for every app screen. */
	public static function assets() {
		Assets::style( 'coach', 'assets/css/coach.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'coach', 'assets/js/coach.js' );
		wp_localize_script( 'cashaadi-coach', 'CSM_COACH', self::config() );
	}
}
