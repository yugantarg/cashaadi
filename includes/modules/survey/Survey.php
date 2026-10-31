<?php
/**
 * Member survey (owner, 2026-10-30), at /survey/.
 *
 *   1. What they have paid for matrimony services, typed in rupees: the price signal. No "what
 *      would you pay" (owner: "questions like these incentivize lying"); a
 *      past fact has nothing to gain by bending it.
 *   2. How important each feature is, 1 to 5.
 *   3. The biggest problems, up to 2.
 *   4. How likely they are to recommend CA Shaadi, 0 to 10.
 *   5. One thing to improve (required).
 *   6. A testimonial (optional), with a switch giving us permission to use
 *      their name and photo with it.
 *
 * Self or parent is read from "Created for", not asked (owner: "our proxy is
 * enough").
 *
 * Rewards, in CA Shaadi cash (the referral ledger, 1 cash = ₹1 at checkout):
 * 300 for answering; another 300 for a testimonial of 30+ characters with
 * the permission switch on. Each once per member (the ledger's UNIQUE key).
 *
 * Batches (v1.90.77): nobody is invited until `wp csm survey invite <n>`;
 * then the next n eligible members to open the app get the popup, and only
 * invited members can answer. `wp csm survey batches` shows each batch's
 * response. Feature and problem lists are shuffled per member ("Something
 * else" stays last) and the order shown is saved with the answer.
 *
 * Who: members who joined 7+ days ago, acted on 3+ profiles and used the app
 * on 2+ days (engaged(), v1.90.84), and were never offered the ₹99
 * boost (owner: "don't send survey to those you have given 99 boost
 * option"). Invited by a one-time popup in AppPage's chain. First
 * csm_survey_max answers (default 300); csm_survey_on = 0 stops it.
 *
 * Answers: user meta csm_survey1 and a survey_answer event.
 * `wp csm survey [--text]` reads them.
 */

namespace CAShaadi\Modules\Survey;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Survey {

	const DONE_META  = 'csm_survey1';
	const SEEN_META  = 'csm_survey1_seen';   // the invitation popup was shown
	const COUNT_OPT  = 'csm_survey1_count';
	const MAX_OPT    = 'csm_survey_max';
	const ON_OPT     = 'csm_survey_on';
	// Invitations go out in batches (owner, v1.90.77): `wp csm survey invite <n>`
	// lets the next n eligible members who open the app see the popup.
	const LEFT_OPT   = 'csm_survey_invites_left';
	const BATCH_OPT  = 'csm_survey_batch_no';
	const BATCH_META = 'csm_survey1_batch';
	const START_META = 'csm_survey1_started'; // opened the survey (v1.90.78)
	const HIDE_META  = 'csm_survey1_banner_off'; // closed the "continue" banner
	const CASH       = 300;
	const CASH_TESTI = 300;
	const TESTI_MIN  = 60;   // characters, for the testimonial cash
	const IMPROVE_MIN = 15;  // characters
	const MIN_SECONDS = 40;  // faster than this is not reading the questions

	/** Bands for reading the amount members type in (question 1). */
	const PAID_BANDS = array(
		'never'  => array( 0, 0, 'Nothing' ),
		'u2k'    => array( 1, 1999, 'Under ₹2,000' ),
		'2-5k'   => array( 2000, 4999, '₹2,000 – ₹5,000' ),
		'5-15k'  => array( 5000, 14999, '₹5,000 – ₹15,000' ),
		'15-50k' => array( 15000, 49999, '₹15,000 – ₹50,000' ),
		'50k+'   => array( 50000, PHP_INT_MAX, '₹50,000 or more' ),
	);
	const FEATURES = array(
		'more'     => 'More profiles every week',
		'phone'    => 'See phone numbers',
		'first'    => 'Your requests shown first',
		'viewers'  => 'See who viewed you',
		'verified' => 'Only verified CAs',
		'filters'  => 'More filters',
		'help'     => 'Personal help from our team',
	);

	const PROBLEMS = array(
		'few'     => 'Too few profiles',
		'match'   => 'Profiles don\'t match what I want',
		'reply'   => 'People don\'t reply',
		'genuine' => 'Not sure profiles are genuine',
		'hard'    => 'The app is hard to use',
		'other'   => 'Something else',
	);

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm survey', array( __CLASS__, 'cli' ), array(
				'synopsis' => array(
					array( 'type' => 'positional', 'name' => 'action', 'optional' => true ),
					array( 'type' => 'positional', 'name' => 'n', 'optional' => true ),
					array( 'type' => 'flag', 'name' => 'text', 'optional' => true ),
					array( 'type' => 'flag', 'name' => 'all', 'optional' => true ),
				),
			) );
		}
	}

	/** Ever offered the ₹99 boost (any month)? */
	private static function had_boost_deal( $uid ) {
		if ( ! class_exists( '\\CAShaadi\\Modules\\Premium\\BoostDeal' ) ) {
			return false;
		}
		if ( 'show' === get_user_meta( $uid, \CAShaadi\Modules\Premium\BoostDeal::ARM_META, true ) ) {
			return true;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'boost_deal_arm' AND actor_id = %d AND metadata LIKE %s LIMIT 1",
			(int) $uid, '%"arm":"show"%'
		) );
	}

	/** Can this member still take it (and get the cash)? */
	public static function open_for( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! get_option( self::ON_OPT, 1 ) || user_can( $uid, 'manage_options' ) || get_user_meta( $uid, self::DONE_META, true ) ) {
			return false;
		}
		if ( (int) get_option( self::COUNT_OPT, 0 ) >= (int) get_option( self::MAX_OPT, 300 ) ) {
			return false;
		}
		if ( class_exists( '\\CAShaadi\\Modules\\Discover\\Boost' ) && \CAShaadi\Modules\Discover\Boost::is_new( $uid ) ) {
			return false;
		}
		if ( self::had_boost_deal( $uid ) ) {
			return false;
		}
		// Only members invited in a batch (the popup) can answer.
		return (bool) get_user_meta( $uid, self::BATCH_META, true );
	}

	/**
	 * Who is invited (owner, v1.90.84): joined at least 7 days ago, acted on
	 * at least 3 profiles (sent a request, passed or saved, in Discover's
	 * record), and used the app on at least 2 different days (the active-days
	 * table; logins themselves are not recorded).
	 */
	private static function engaged( $uid ) {
		global $wpdb;
		$u = get_userdata( (int) $uid );
		if ( ! $u || strtotime( $u->user_registered . ' UTC' ) > time() - 7 * DAY_IN_SECONDS ) {
			return false;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$acted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_seen WHERE viewer_id = %d AND action IN ( 'liked', 'passed', 'saved' )", (int) $uid ) );
		if ( $acted < 3 ) {
			return false;
		}
		$days = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_active_days WHERE user_id = %d", (int) $uid ) );
		// phpcs:enable
		return $days >= 2;
	}

	/** Not invited yet, and could be: the popup takes one place from the batch. */
	private static function invite( $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		if ( ! $uid || ! get_option( self::ON_OPT, 1 ) || user_can( $uid, 'manage_options' ) || get_user_meta( $uid, self::BATCH_META, true ) || get_user_meta( $uid, self::DONE_META, true )
			|| (int) get_option( self::LEFT_OPT, 0 ) <= 0
			|| ! self::engaged( $uid )
			|| self::had_boost_deal( $uid ) ) {
			return false;
		}
		// Take a place atomically, so two pages at once cannot overshoot the batch.
		$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value - 1 WHERE option_name = %s AND CAST( option_value AS SIGNED ) > 0", self::LEFT_OPT ) );
		wp_cache_delete( self::LEFT_OPT, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( 1 !== (int) $took ) {
			return false;
		}
		update_user_meta( $uid, self::BATCH_META, (int) get_option( self::BATCH_OPT, 1 ) );
		return true;
	}

	/** The one-time invitation popup, for AppPage's chain; false when not due. */
	public static function payload( $uid ) {
		if ( ! get_user_meta( (int) $uid, self::BATCH_META, true ) ) {
			self::invite( $uid );
		}
		if ( ! self::open_for( $uid ) || get_user_meta( (int) $uid, self::SEEN_META, true ) ) {
			return false;
		}
		return array(
			'title' => 'Help improve CA Shaadi and earn ₹600',
			'text'  => "₹300 for answering a short survey\n₹300 for a testimonial\n\nYou receive it as CA Shaadi Cash, which you can use anywhere on CA Shaadi, on top of any other discount or offer.",
			'ok'    => 'Start',
			'later' => 'No problem. You can take the survey any time from your Profile tab and still earn ₹600.',
			'url'   => home_url( '/survey/' ),
			'seen'  => rest_url( 'csm/v1/survey/seen' ),
		);
	}

	/**
	 * The row on the Profile tab for an invited member who has not answered
	 * (owner: after "Not now", it stays available from the Profile section).
	 */
	public static function profile_row( $uid ) {
		if ( ! self::open_for( $uid ) ) {
			return null;
		}
		return array(
			'label' => 'Earn ₹600: answer a short survey',
			'url'   => home_url( '/survey/' ),
			'value' => get_user_meta( (int) $uid, self::START_META, true ) ? 'Continue' : '',
		);
	}

	/**
	 * "Continue the survey" above Discover, for a member who opened the
	 * survey and left without submitting. The × hides it for good; the Profile
	 * row stays.
	 */
	public static function banner( $uid ) {
		if ( ! self::open_for( $uid ) || ! get_user_meta( (int) $uid, self::START_META, true ) || get_user_meta( (int) $uid, self::HIDE_META, true ) ) {
			return '';
		}
		$hide = esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'csm/v1/survey/hide' ) ) );
		return '<div class="csm-svb"><button type="button" class="csm-svb-x" aria-label="Close" data-hide="' . $hide . '">×</button>'
			. '<p>You started our survey. Finish it to earn ₹300, or ₹600 with a testimonial.</p>'
			. '<a class="csm-svb-btn" href="' . esc_url( home_url( '/survey/' ) ) . '">Continue the survey</a>'
			. '<small>You can also find it on your Profile tab.</small></div>'
			. '<style>.csm-svb{position:relative;margin:10px 12px 0;padding:12px 40px 12px 14px;border-radius:14px;background:#fffaf0;border:1px solid #f0d58c;font-size:14px;line-height:1.4;color:#16212b}'
			. '.csm-svb p{margin:0 0 8px}.csm-svb small{display:block;margin-top:6px;color:#5c6a76}'
			. '.csm-svb-btn{display:inline-block;padding:8px 16px;border-radius:999px;background:#0b2350;color:#fff!important;font-weight:700;text-decoration:none}'
			. '.csm-svb-x{position:absolute;top:6px;right:8px;border:0;background:none;color:#5c6a76;font-size:22px;line-height:1;cursor:pointer;padding:4px}</style>'
			. '<script>(function(){var b=document.querySelector(".csm-svb");if(!b){return;}var x=b.querySelector(".csm-svb-x");x.onclick=function(){var u=x.getAttribute("data-hide");try{if(!(navigator.sendBeacon&&navigator.sendBeacon(u))){fetch(u,{method:"POST",credentials:"same-origin",keepalive:true});}}catch(e){}b.remove();};})();</script>';
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/survey', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_submit' ),
			'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( 'csm/v1', '/survey/hide', array(
			'methods'             => 'POST',
			'callback'            => function () {
				update_user_meta( get_current_user_id(), self::HIDE_META, time() );
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( 'csm/v1', '/survey/seen', array(
			'methods'             => 'POST',
			'callback'            => function () {
				$uid = get_current_user_id();
				if ( ! get_user_meta( $uid, self::SEEN_META, true ) ) {
					update_user_meta( $uid, self::SEEN_META, time() );
				}
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	private static function created_for( $uid ) {
		$id = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'Created for' ) : 0;
		return $id && class_exists( '\BP_XProfile_ProfileData' ) ? (string) \BP_XProfile_ProfileData::get_value_byid( $id, (int) $uid ) : '';
	}

	/** Looks like words: long enough, enough words, not one character repeated. */
	private static function real_text( $t, $min_chars, $min_words ) {
		$t = trim( (string) $t );
		if ( mb_strlen( $t ) < $min_chars || count( preg_split( '/\s+/u', $t ) ) < $min_words ) {
			return false;
		}
		return count( array_unique( preg_split( '//u', mb_strtolower( preg_replace( '/[^\p{L}]/u', '', $t ) ), -1, PREG_SPLIT_NO_EMPTY ) ) ) >= 6;
	}

	public static function rest_submit( $request ) {
		$uid = get_current_user_id();
		if ( get_user_meta( $uid, self::DONE_META, true ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'You have already answered. Thank you!' ), 200 );
		}
		if ( ! self::open_for( $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'This survey is closed. Thank you for your interest.' ), 200 );
		}
		$bad = array();
		$a   = array();

		// Typed in rupees (owner: "how much you paid should be text"); 0 = never.
		$paid      = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'paid' ) );
		$a['paid'] = '' === $paid ? -1 : (int) $paid;
		if ( $a['paid'] < 0 || $a['paid'] > 10000000 ) {
			$bad['paid'] = 'Please enter an amount in rupees. Write 0 if never.';
		}
		// The 2 that matter most (owner, v1.90.94): 1-5 ratings came back 4-5
		// for everything, so they told us nothing.
		$a['top'] = array_values( array_unique( array_filter( array_map( 'strval', (array) $request->get_param( 'top' ) ), function ( $x ) { return isset( self::FEATURES[ $x ] ); } ) ) );
		if ( 2 !== count( $a['top'] ) ) {
			$bad['top'] = 'Please pick 2.';
		}
		$a['problems'] = array_values( array_unique( array_filter( array_map( 'strval', (array) $request->get_param( 'problems' ) ), function ( $x ) { return isset( self::PROBLEMS[ $x ] ); } ) ) );
		if ( ! $a['problems'] || count( $a['problems'] ) > 2 ) {
			$bad['problems'] = 'Please pick 1 or 2.';
		}
		$nps = $request->get_param( 'nps' );
		$a['nps'] = is_numeric( $nps ) ? (int) $nps : -1;
		if ( $a['nps'] < 0 || $a['nps'] > 10 ) {
			$bad['nps'] = 'Please choose a number.';
		}
		$a['improve'] = mb_substr( trim( sanitize_textarea_field( (string) $request->get_param( 'improve' ) ) ), 0, 1000 );
		if ( ! self::real_text( $a['improve'], self::IMPROVE_MIN, 3 ) ) {
			$bad['improve'] = 'Please write a little more (a short sentence).';
		}
		$a['testimonial'] = mb_substr( trim( sanitize_textarea_field( (string) $request->get_param( 'testimonial' ) ) ), 0, 1000 );
		$a['permission']  = $request->get_param( 'permission' ) ? 1 : 0;
		if ( $a['permission'] && ! self::real_text( $a['testimonial'], self::TESTI_MIN, 8 ) ) {
			$bad['testimonial'] = 'Please write at least ' . self::TESTI_MIN . ' characters (a sentence or two) to earn the extra ₹300.';
		}
		/*
		 * Against random filling (owner, v1.90.76). Refused: answered faster
		 * than MIN_SECONDS (the start time is signed, so it cannot be edited),
		 * and text that is not words (above). Flagged, not refused: every
		 * feature rated the same, or under two minutes; flagged answers are
		 * left out of `wp csm survey` unless --all.
		 */
		list( $t0, $sig ) = array_pad( explode( '.', (string) $request->get_param( 'start' ), 2 ), 2, '' );
		$took = hash_equals( wp_hash( 'csm_sv_' . $uid . '_' . $t0 ), $sig ) ? time() - (int) $t0 : 0;
		if ( $took < self::MIN_SECONDS && ! $bad ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Please take a moment to read each question and answer it properly.' ), 200 );
		}
		$a['seconds'] = $took;
		$a['flags']   = array();
		$a['batch']   = (int) get_user_meta( $uid, self::BATCH_META, true );
		// The order each list was shown in (it is shuffled per member), to check for position effects.
		$a['order']   = array(
			'features' => array_values( array_intersect( array_map( 'strval', (array) $request->get_param( 'order_features' ) ), array_keys( self::FEATURES ) ) ),
			'problems' => array_values( array_intersect( array_map( 'strval', (array) $request->get_param( 'order_problems' ) ), array_keys( self::PROBLEMS ) ) ),
		);
		if ( $bad ) {
			return new \WP_REST_Response( array( 'ok' => false, 'errors' => $bad ), 200 );
		}
		$a['at']          = time();
		$a['created_for'] = self::created_for( $uid );
		// Claim first, so a double tap cannot pay twice.
		if ( ! add_user_meta( $uid, self::DONE_META, wp_json_encode( $a ), true ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'You have already answered. Thank you!' ), 200 );
		}
		update_option( self::COUNT_OPT, (int) get_option( self::COUNT_OPT, 0 ) + 1, false );
		$cash = 0;
		if ( class_exists( '\\CAShaadi\\Modules\\Referral\\Referral' ) ) {
			$cash += \CAShaadi\Modules\Referral\Referral::post( $uid, self::CASH, 'survey', 0, 0, 'Survey answered' ) ? self::CASH : 0;
			if ( $a['permission'] && '' !== $a['testimonial'] ) {
				$cash += \CAShaadi\Modules\Referral\Referral::post( $uid, self::CASH_TESTI, 'testimonial', 0, 0, 'Testimonial with name and photo' ) ? self::CASH_TESTI : 0;
			}
		}
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'survey_answer', $uid, 0, array_merge( $a, array(
				'gender' => (string) cashaadi()->get_gender( $uid ),
				'plan'   => Membership::is_gold( $uid ) ? 'gold' : ( Membership::is_premium( $uid ) ? 'premium' : 'free' ),
				'cash'   => $cash,
			) ) );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'cash' => $cash, 'refer' => home_url( '/refer/' ) ), 200 );
	}

	/* -------------------------------------------------------------- screen */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'survey' ) ) {
			return;
		}
		$uid  = get_current_user_id();
		$done = (bool) get_user_meta( $uid, self::DONE_META, true );
		$open = self::open_for( $uid );
		AppPage::assets();
		AppPage::open( 'Quick survey', 'profile' );
		echo '<div class="csm-sv">';
		if ( $done ) {
			echo '<h1>Thank you!</h1><p>You have already answered. Your CA Shaadi cash is in your account.</p><a class="csm-sv-btn" href="' . esc_url( home_url( '/refer/' ) ) . '">See my CA Shaadi cash</a>';
		} elseif ( ! $open && ! current_user_can( 'manage_options' ) ) {
			echo '<h1>This survey is closed</h1><p>Thank you for your interest.</p><a class="csm-sv-btn" href="' . esc_url( home_url( '/discover/' ) ) . '">Back to Discover</a>';
		} else {
			if ( $open && ! get_user_meta( $uid, self::START_META, true ) ) {
				update_user_meta( $uid, self::START_META, time() );
			}
			echo self::form(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		}
		echo '</div>' . self::css();
		AppPage::close( 'profile' );
		exit;
	}

	/** Random order; $last (e.g. "Something else") stays at the end. */
	private static function shuffled( $opts, $last = '' ) {
		$keys = array_keys( $opts );
		if ( '' !== $last ) {
			$keys = array_values( array_diff( $keys, array( $last ) ) );
		}
		shuffle( $keys );
		if ( '' !== $last && isset( $opts[ $last ] ) ) {
			$keys[] = $last;
		}
		$out = array();
		foreach ( $keys as $k ) {
			$out[ $k ] = $opts[ $k ];
		}
		return $out;
	}

	private static function options( $name, $opts, $type ) {
		$o = '';
		foreach ( $opts as $v => $label ) {
			$o .= '<label class="csm-sv-opt"><input type="' . $type . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $v ) . '"><span>' . esc_html( $label ) . '</span></label>';
		}
		return $o;
	}

	private static function q( $key, $n, $title, $inner, $note = '' ) {
		return '<fieldset class="csm-sv-q" data-key="' . esc_attr( $key ) . '"><legend><span>' . (int) $n . '.</span> ' . esc_html( $title ) . ( $note ? ' <small>' . esc_html( $note ) . '</small>' : '' ) . '</legend>'
			. $inner . '<p class="csm-sv-err"></p></fieldset>';
	}

	private static function form() {
		$t0 = time();
		$o  = '<h1>Help improve CA Shaadi and earn ₹600</h1>';
		$o .= '<div class="csm-sv-cash"><b>₹300</b> for the survey<br><b>₹300</b> for a testimonial<br><small>You receive CA Shaadi Cash that can be freely redeemed across CA Shaadi. It can be used in addition to any other discount or offer.</small></div>';
		$o .= '<form id="csm-sv-form" novalidate data-url="' . esc_url( rest_url( 'csm/v1/survey' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-start="' . esc_attr( $t0 . '.' . wp_hash( 'csm_sv_' . get_current_user_id() . '_' . $t0 ) ) . '" data-min="' . (int) self::TESTI_MIN . '" data-dk="' . esc_attr( 'csm_sv1_draft_' . substr( wp_hash( 'csm_sv_draft_' . get_current_user_id() ), 0, 16 ) ) . '">';

		$o .= self::q( 'paid', 1, 'How much have you paid in total for matrimony services so far (sites, apps, bureaus or agents)?',
			'<p class="csm-sv-hint">An approximate amount is fine. Write 0 if you never paid.</p>'
			. '<label class="csm-sv-amt"><span>₹</span><input type="text" name="paid" inputmode="numeric" autocomplete="off" placeholder="0"></label>' );

		// Shuffled per member (owner: remove the effect of being first or last).
		$o .= self::q( 'top', 2, 'Which 2 of these matter most to you?',
			'<div class="csm-sv-opts">' . self::options( 'top', self::shuffled( self::FEATURES ), 'checkbox' ) . '</div>', '(pick 2)' );

		$o .= self::q( 'problems', 3, 'What are the biggest problems for you on CA Shaadi right now?',
			'<div class="csm-sv-opts">' . self::options( 'problems', self::shuffled( self::PROBLEMS, 'other' ), 'checkbox' ) . '</div>', '(pick up to 2)' );

		$scale = '';
		for ( $i = 0; $i <= 10; $i++ ) {
			$scale .= '<label class="csm-sv-opt"><input type="radio" name="nps" value="' . $i . '"><span>' . $i . '</span></label>';
		}
		$o .= self::q( 'nps', 4, 'How likely are you to recommend CA Shaadi to a friend?',
			'<div class="csm-sv-opts is-scale">' . $scale . '<div class="csm-sv-ends"><span>Not likely</span><span>Very likely</span></div></div>' );

		$o .= self::q( 'improve', 5, 'One thing we should improve',
			'<textarea name="improve" rows="3" maxlength="1000" placeholder="A short sentence"></textarea>' );

		$o .= self::q( 'testimonial', 6, 'Your testimonial',
			'<p class="csm-sv-hint">What do you like about CA Shaadi? Write a sentence or two and turn on the switch below to earn another ₹300.</p>'
			. '<textarea name="testimonial" rows="4" maxlength="1000" placeholder="For example: what you found here, or what you like about it"></textarea>'
			. '<p class="csm-sv-hint csm-sv-left" aria-live="polite"></p>'
			. '<label class="csm-sv-sw"><input type="checkbox" name="permission" value="1"><span class="csm-sv-sw-t"></span><span>CA Shaadi may show this with my name and photo</span></label>',
			'(optional, +₹300)' );

		$o .= '<p class="csm-sv-err csm-sv-top"></p><button type="submit" class="csm-sv-btn">Submit and earn ₹300</button></form>';
		$o .= '<script>(function(){var f=document.getElementById("csm-sv-form");if(!f){return;}'
			. '["problems","top"].forEach(function(k){[].forEach.call(f.querySelectorAll("fieldset[data-key="+k+"] input"),function(c){c.addEventListener("change",function(){if(f.querySelectorAll("fieldset[data-key="+k+"] input:checked").length>2){c.checked=false;}});});});'
			. 'var tx=f.querySelector("textarea[name=testimonial]"),pm=f.querySelector("input[name=permission]");'
			/*
			 * ₹600 needs the switch on and a testimonial of TESTI_MIN characters
			 * and 8 words, as the server checks. Say what is still missing
			 * (owner, 2026-10-31: the button never reached 600 and nothing said why).
			 */
			. 'var MIN=+f.getAttribute("data-min"),left=f.querySelector(".csm-sv-left");'
			. 'function words(){var t=tx.value.trim();return t?t.split(/\\s+/).length:0;}'
			. 'function full(){return tx.value.trim().length>=MIN&&words()>=8;}'
			. 'function lbl(){return "Submit and earn ₹"+(pm.checked&&full()?"600":"300");}'
			. 'function upd(){f.querySelector(".csm-sv-btn").textContent=lbl();var t=tx.value.trim(),m="";'
			. 'if(t||pm.checked){if(!full()){var n=Math.max(0,MIN-t.length);m=n>0?"Write "+n+" more characters to earn the extra ₹300.":"Write a few more words to earn the extra ₹300.";}'
			. 'else if(!pm.checked){m="Turn on the switch below to earn the extra ₹300.";}else{m="You will earn ₹600.";}}left.textContent=m;}'
			. 'tx.addEventListener("input",upd);pm.addEventListener("change",upd);'
			/*
			 * Answers so far are kept in this browser, so leaving and coming back
			 * loses nothing. The key is per member (an opaque hash), so another
			 * account on the same browser never sees them, and the name-and-photo
			 * permission is never saved: it is chosen fresh each time (v1.90.79).
			 * The shared key used by 1.90.78 is removed.
			 */
			. 'var DK=f.getAttribute("data-dk");try{localStorage.removeItem("csm_sv1_draft");}catch(e){}'
			. 'function save(){try{var s={};[].forEach.call(f.querySelectorAll("input,textarea"),function(i){if(i.name==="permission"){return;}if(i.type==="radio"||i.type==="checkbox"){if(i.checked){(s[i.name]=s[i.name]||[]).push(i.value);}}else if(i.name){s[i.name]=i.value;}});localStorage.setItem(DK,JSON.stringify(s));}catch(e){}}'
			. 'try{var s0=JSON.parse(localStorage.getItem(DK)||"null");if(s0){[].forEach.call(f.querySelectorAll("input,textarea"),function(i){var v=s0[i.name];if(v===undefined||i.name==="permission"){return;}if(i.type==="radio"||i.type==="checkbox"){i.checked=v.indexOf(i.value)!==-1;}else{i.value=v;}});}}catch(e){}'
			. 'f.addEventListener("change",save);f.addEventListener("input",save);upd();'
			. 'f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector(".csm-sv-btn"),d={};'
			. '[].forEach.call(f.querySelectorAll(".csm-sv-err"),function(x){x.textContent="";});'
			. 'var c;d.paid=f.querySelector("input[name=paid]").value;'
			. 'd.top=[].map.call(f.querySelectorAll("input[name=top]:checked"),function(i){return i.value;});'
			. 'd.problems=[].map.call(f.querySelectorAll("input[name=problems]:checked"),function(i){return i.value;});'
			. 'd.order_features=[].map.call(f.querySelectorAll("input[name=top]"),function(i){return i.value;});'
			. 'd.order_problems=[].map.call(f.querySelectorAll("input[name=problems]"),function(i){return i.value;});'
			. 'c=f.querySelector("input[name=nps]:checked");d.nps=c?c.value:"";'
			. 'd.improve=f.querySelector("textarea[name=improve]").value;d.testimonial=f.querySelector("textarea[name=testimonial]").value;'
			. 'd.permission=pm.checked?1:0;d.start=f.getAttribute("data-start");'
			. 'b.disabled=true;b.textContent="Sending…";'
			. 'fetch(f.getAttribute("data-url"),{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":f.getAttribute("data-nonce")},body:JSON.stringify(d)}).then(function(r){return r.json();}).then(function(r){'
			. 'if(r&&r.ok){try{localStorage.removeItem(DK);}catch(e){}f.parentNode.innerHTML="<h1>Thank you!</h1><p><b>₹"+r.cash+" CA Shaadi Cash</b> has been added to your account. Use it anywhere on CA Shaadi.</p><a class=\"csm-sv-btn\" href=\""+r.refer+"\">See my CA Shaadi cash</a>";window.scrollTo(0,0);return;}'
			. 'b.disabled=false;b.textContent=lbl();var first=null;'
			. 'Object.keys((r&&r.errors)||{}).forEach(function(k){var fs=f.querySelector("fieldset[data-key=\""+k+"\"]");if(fs){fs.querySelector(".csm-sv-err").textContent=r.errors[k];first=first||fs;}});'
			. 'if(r&&r.message){f.querySelector(".csm-sv-top").textContent=r.message;}'
			. 'if(first){first.scrollIntoView({block:"center",behavior:"smooth"});}'
			. '}).catch(function(){b.disabled=false;b.textContent=lbl();f.querySelector(".csm-sv-top").textContent="No connection. Please try again.";});});})();</script>';
		return $o;
	}

	/**
	 * Which answers came in far faster than their length allows.
	 *
	 * Expected seconds = base + characters typed / rate. The rate is the
	 * median of (seconds over the median time) per extra character among
	 * answers that typed more than the median, bounded to 1-6 characters a
	 * second (6 is fast thumb typing; 3 is the fallback with few answers).
	 * The base is the median of seconds minus typing time.
	 *
	 * @return array{base:int,rate:float,fast:array<int,string>}
	 */
	private static function speed_check( array $all ) {
		$rows = array();
		foreach ( $all as $uid => $m ) {
			if ( isset( $m['seconds'] ) && (int) $m['seconds'] > 0 ) {
				$rows[ $uid ] = array( (int) $m['seconds'], mb_strlen( (string) ( $m['improve'] ?? '' ) ) + mb_strlen( (string) ( $m['testimonial'] ?? '' ) ) );
			}
		}
		$med = function ( $v ) {
			sort( $v );
			$n = count( $v );
			return $n ? ( $n % 2 ? $v[ intdiv( $n, 2 ) ] : ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2 ) : 0;
		};
		$rate = 3.0;
		if ( count( $rows ) >= 8 ) {
			$ms = $med( array_column( $rows, 0 ) );
			$mc = $med( array_column( $rows, 1 ) );
			$r  = array();
			foreach ( $rows as $x ) {
				if ( $x[1] > $mc && $x[0] > $ms ) {
					$r[] = ( $x[1] - $mc ) / ( $x[0] - $ms );
				}
			}
			if ( $r ) {
				$rate = max( 1.0, min( 6.0, (float) $med( $r ) ) );
			}
		}
		$base = (int) round( $med( array_map( function ( $x ) use ( $rate ) { return $x[0] - $x[1] / $rate; }, $rows ) ) );
		$base = max( (int) self::MIN_SECONDS, $base );
		$fast = array();
		foreach ( $rows as $uid => $x ) {
			$exp = $base + $x[1] / $rate;
			if ( $x[0] < 0.5 * $exp ) {
				$fast[ $uid ] = sprintf( '(%ds for %d characters; expected about %ds)', $x[0], $x[1], round( $exp ) );
			}
		}
		return array( 'base' => $base, 'rate' => $rate, 'fast' => $fast );
	}

	private static function css() {
		return '<style>.csm-sv{max-width:640px;margin:0 auto;padding:16px 16px 40px;color:#16212b}.csm-sv h1{font-size:24px;margin:8px 0 10px}'
			. '.csm-sv-cash{background:#fffaf0;border:1px solid #f0d58c;border-radius:12px;padding:12px 14px;margin:0 0 16px;line-height:1.5}.csm-sv-cash small{color:#5c6a76}'
			. '.csm-sv-q{border:1px solid #e4eaf0;border-radius:14px;padding:14px;margin:0 0 14px;background:#fff;min-width:0}'
			. '.csm-sv-q legend{font-weight:700;font-size:16px;line-height:1.35;padding:0 4px}.csm-sv-q legend span{color:#7a1220}.csm-sv-q legend small{font-weight:400;color:#5c6a76}'
			. '.csm-sv-hint{color:#5c6a76;font-size:13px;margin:6px 0}'
			. '.csm-sv-opts{display:flex;flex-direction:column;gap:8px;margin-top:8px}.csm-sv-opt{position:relative;display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e4eaf0;border-radius:10px;cursor:pointer;font-size:15px}'
			. '.csm-sv-opt input{margin:0;flex:0 0 auto}.csm-sv-opt:has(input:checked),.csm-sv-dot:has(input:checked){border-color:#0b2350;background:#f3f7fc}'
			. '.csm-sv-opts.is-scale{flex-direction:row;flex-wrap:wrap;gap:6px}.csm-sv-opts.is-scale .csm-sv-opt{flex:1 0 38px;justify-content:center;padding:10px 0}'
			. '.csm-sv-opts.is-scale .csm-sv-opt input,.csm-sv-dot input{position:absolute;opacity:0;pointer-events:none}.csm-sv-ends{width:100%;display:flex;justify-content:space-between;color:#5c6a76;font-size:12px}'
			. '.csm-sv-rate{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #f0f2f5;flex-wrap:wrap}.csm-sv-rate-l{flex:1 1 160px;font-size:15px}'
			. '.csm-sv-dots{display:flex;gap:6px}.csm-sv-dot{position:relative;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid #e4eaf0;border-radius:50%;cursor:pointer;font-size:14px}'
			. '.csm-sv-amt{display:flex;align-items:center;gap:8px;margin-top:8px;border:1px solid #c9d2dc;border-radius:10px;padding:0 12px;max-width:260px}.csm-sv-amt span{color:#5c6a76;font-weight:700}'
			. '.csm-sv-amt input{border:0;outline:0;font:inherit;font-size:17px;padding:11px 0;width:100%;background:transparent}'
			. '.csm-sv textarea{width:100%;box-sizing:border-box;border:1px solid #c9d2dc;border-radius:10px;padding:10px;font:inherit;margin-top:8px}'
			. '.csm-sv-sw{display:flex;align-items:center;gap:10px;margin-top:10px;cursor:pointer;font-size:14px}.csm-sv-sw input{position:absolute;opacity:0;pointer-events:none}'
			. '.csm-sv-sw-t{flex:0 0 auto;width:40px;height:22px;border-radius:999px;background:#c9d2dc;position:relative;transition:background .15s}'
			. '.csm-sv-sw-t:after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s}'
			. '.csm-sv-sw input:checked+.csm-sv-sw-t{background:#1a7f37}.csm-sv-sw input:checked+.csm-sv-sw-t:after{left:21px}'
			. '.csm-sv-err{color:#b3261e;font-size:13px;margin:6px 0 0}.csm-sv-err:empty{display:none}'
			. '.csm-sv-btn{display:block;width:100%;padding:14px;border:0;border-radius:999px;background:#0b2350;color:#fff!important;font-weight:700;font-size:16px;text-align:center;text-decoration:none;cursor:pointer}'
			. '.csm-sv-btn:disabled{opacity:.6}</style>';
	}

	/* ----------------------------------------------------------------- CLI */

	/**
	 * wp csm survey [--text]
	 *
	 * Answers, overall and split by self / parent ("Created for"), gender and
	 * plan; the average importance of each feature; NPS. --text also lists
	 * the improvement answers and the testimonials (with permission or not).
	 */
	public static function cli( $args, $assoc ) {
		global $wpdb;
		$action = $args[0] ?? '';
		if ( 'invite' === $action ) {
			$n = max( 0, (int) ( $args[1] ?? 0 ) );
			if ( ! $n ) {
				\WP_CLI::error( 'Usage: wp csm survey invite <number of members>' );
			}
			$b = (int) get_option( self::BATCH_OPT, 0 ) + 1;
			update_option( self::BATCH_OPT, $b, false );
			update_option( self::LEFT_OPT, $n, false );
			\WP_CLI::success( "Batch {$b}: the next {$n} eligible members who open the app will see the survey popup." );
			return;
		}
		if ( 'batches' === $action ) {
			$rows = array();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_value b, user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::BATCH_META ) ) as $r ) {
				$b = (int) $r->b;
				if ( ! isset( $rows[ $b ] ) ) {
					$rows[ $b ] = array( 'batch' => $b, 'invited' => 0, 'saw popup' => 0, 'answered' => 0, 'testimonial' => 0 );
				}
				$rows[ $b ]['invited']++;
				$rows[ $b ]['saw popup'] += get_user_meta( (int) $r->user_id, self::SEEN_META, true ) ? 1 : 0;
				$done = json_decode( (string) get_user_meta( (int) $r->user_id, self::DONE_META, true ), true );
				if ( is_array( $done ) ) {
					$rows[ $b ]['answered']++;
					$rows[ $b ]['testimonial'] += ! empty( $done['permission'] ) ? 1 : 0;
				}
			}
			ksort( $rows );
			foreach ( $rows as &$r ) {
				$r['response'] = $r['invited'] ? round( 100 * $r['answered'] / $r['invited'] ) . '%' : '-';
			}
			unset( $r );
			\WP_CLI::log( 'Places left in the current batch: ' . (int) get_option( self::LEFT_OPT, 0 ) . ' · total answers ' . (int) get_option( self::COUNT_OPT, 0 ) . ' of the cap ' . (int) get_option( self::MAX_OPT, 300 ) . '.' );
			if ( $rows ) {
				\WP_CLI\Utils\format_items( 'table', array_values( $rows ), array( 'batch', 'invited', 'saw popup', 'answered', 'testimonial', 'response' ) );
			}
			return;
		}
		$rows = $wpdb->get_results( "SELECT actor_id, metadata FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'survey_answer' ORDER BY id" );
		$all  = array();
		foreach ( (array) $rows as $r ) {
			$m = json_decode( (string) $r->metadata, true );
			if ( is_array( $m ) ) {
				$all[ (int) $r->actor_id ] = $m;
			}
		}
		/*
		 * Careless (owner, v1.90.95): every feature rated the same (earlier
		 * version of question 2), or answered much faster than expected for
		 * the amount written. Expected time = the typical time for the fixed
		 * questions + the characters typed at a typing speed; both learned
		 * from the answers themselves (median), so it follows real members.
		 * "Much faster" = under half the expected time.
		 */
		$speed = self::speed_check( $all );
		$flagged = array_filter( $all, function ( $m ) { return in_array( 'same_ratings', (array) ( $m['flags'] ?? array() ), true ); } );
		foreach ( $speed['fast'] as $uid => $why ) {
			$flagged[ $uid ] = $all[ $uid ];
		}
		\WP_CLI::log( count( $all ) . ' answers (cap ' . (int) get_option( self::MAX_OPT, 300 ) . ', survey ' . ( get_option( self::ON_OPT, 1 ) ? 'on' : 'off' ) . '); ' . count( $flagged ) . ' flagged as careless' . ( empty( $assoc['all'] ) ? ', left out below (--all to include).' : ', included.' ) );
		\WP_CLI::log( sprintf( '  Typical time without typing: %ds; typing speed: %.1f characters a second. Too fast = under half the expected time.', $speed['base'], $speed['rate'] ) );
		foreach ( $speed['fast'] as $uid => $why ) {
			\WP_CLI::log( '  too fast: member ' . $uid . ' ' . $why );
		}
		if ( empty( $assoc['all'] ) ) {
			$all = array_diff_key( $all, $flagged );
		}
		$n = count( $all );
		if ( ! $n ) {
			return;
		}
		$parent = function ( $m ) { return in_array( $m['created_for'] ?? '', array( 'Son', 'Daughter' ), true ); };
		$groups = array(
			'all'    => function () { return true; },
			'self'   => function ( $m ) use ( $parent ) { return ! $parent( $m ); },
			'parent' => $parent,
			'men'    => function ( $m ) { return 'Male' === trim( (string) ( $m['gender'] ?? '' ) ); },
			'women'  => function ( $m ) { return 'Female' === trim( (string) ( $m['gender'] ?? '' ) ); },
			'free'   => function ( $m ) { return 'free' === ( $m['plan'] ?? '' ); },
			'paid'   => function ( $m ) { return 'free' !== ( $m['plan'] ?? 'free' ); },
		);
		$sets = array();
		foreach ( $groups as $g => $fn ) {
			$sets[ $g ] = array_filter( $all, $fn );
		}
		$head = function ( $g ) use ( $sets ) { return $g . ' (' . count( $sets[ $g ] ) . ')'; };

		$share = function ( $title, $opts, $key ) use ( $groups, $sets, $head ) {
			$table = array();
			foreach ( $opts as $v => $label ) {
				$row = array( 'answer' => $label );
				foreach ( $groups as $g => $fn ) {
					$c = 0;
					foreach ( $sets[ $g ] as $m ) {
						$c += in_array( (string) $v, array_map( 'strval', (array) ( $m[ $key ] ?? array() ) ), true ) ? 1 : 0;
					}
					$row[ $head( $g ) ] = count( $sets[ $g ] ) ? round( 100 * $c / count( $sets[ $g ] ) ) . '%' : '-';
				}
				$table[] = $row;
			}
			\WP_CLI::log( "\n" . $title );
			\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );
		};

		/*
		 * Paid before, to OTHER services: question 1 asks about matrimony
		 * services in general, so a member's own CA Shaadi payments (paid
		 * orders before they answered) are taken off what they typed
		 * (owner, v1.90.94).
		 */
		$ours = 0;
		$hit  = 0;
		foreach ( $all as $uid => &$m ) {
			$cs = function_exists( 'wc_get_orders' ) ? array_sum( array_map( function ( $o ) use ( $m ) {
				$t = $o->get_date_paid() ? $o->get_date_paid()->getTimestamp() : 0;
				return $t && $t <= (int) ( $m['at'] ?? time() ) ? (float) $o->get_total() : 0;
			}, wc_get_orders( array( 'customer_id' => $uid, 'status' => array( 'processing', 'completed' ), 'limit' => 20 ) ) ) ) : 0;
			if ( $cs > 0 && (int) ( $m['paid'] ?? 0 ) > 0 ) {
				$hit++;
				$ours += min( $cs, (int) $m['paid'] );
			}
			$m['paid'] = max( 0, (int) ( $m['paid'] ?? 0 ) - (int) round( $cs ) );
		}
		unset( $m );
		foreach ( $sets as $g => $_ ) {
			$sets[ $g ] = array_intersect_key( $all, $sets[ $g ] );
		}
		// Paid before: bands of the typed amounts, then the median and the average.
		$table = array();
		foreach ( self::PAID_BANDS as $band ) {
			$row = array( 'paid before' => $band[2] );
			foreach ( $groups as $g => $fn ) {
				$c = count( array_filter( $sets[ $g ], function ( $m ) use ( $band ) { $p = (int) ( $m['paid'] ?? -1 ); return $p >= $band[0] && $p <= $band[1]; } ) );
				$row[ $head( $g ) ] = count( $sets[ $g ] ) ? round( 100 * $c / count( $sets[ $g ] ) ) . '%' : '-';
			}
			$table[] = $row;
		}
		foreach ( array( 'median of those who paid', 'average of those who paid' ) as $label ) {
			$row = array( 'paid before' => $label );
			foreach ( $groups as $g => $fn ) {
				$v = array_values( array_filter( array_map( function ( $m ) { return (int) ( $m['paid'] ?? 0 ); }, $sets[ $g ] ) ) );
				sort( $v );
				$x = ! $v ? 0 : ( 0 === strpos( $label, 'median' ) ? $v[ (int) floor( ( count( $v ) - 1 ) / 2 ) ] : array_sum( $v ) / count( $v ) );
				$row[ $head( $g ) ] = $v ? '₹' . number_format( $x ) : '-';
			}
			$table[] = $row;
		}
		\WP_CLI::log( "\nPaid to other matrimony services before (" . $hit . ' answers included CA Shaadi payments, ₹' . number_format( $ours ) . ' in all, taken off)' );
		\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );

		$share( 'Matter most (each picked 2, so columns add to 200%)', self::FEATURES, 'top' );

		// Earlier answers rated each feature 1-5 (until v1.90.94).
		$table = array();
		foreach ( self::FEATURES as $f => $label ) {
			$row = array( 'feature' => $label );
			foreach ( $groups as $g => $fn ) {
				$s = array_map( function ( $m ) use ( $f ) { return (int) ( $m['importance'][ $f ] ?? 0 ); }, $sets[ $g ] );
				$s = array_filter( $s );
				$row[ $head( $g ) ] = $s ? number_format( array_sum( $s ) / count( $s ), 1 ) : '-';
			}
			$table[] = $row;
		}
		$k = $head( 'all' );
		usort( $table, function ( $a, $b ) use ( $k ) { return (float) $b[ $k ] <=> (float) $a[ $k ]; } );
		\WP_CLI::log( "\nEarlier answers: how important (average, 1 to 5)" );
		\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );

		$share( 'Biggest problems (up to 2 each, so columns can add to more than 100%)', self::PROBLEMS, 'problems' );

		$s   = array_map( function ( $m ) { return (int) ( $m['nps'] ?? 0 ); }, $all );
		$pro = count( array_filter( $s, function ( $x ) { return $x >= 9; } ) );
		$det = count( array_filter( $s, function ( $x ) { return $x <= 6; } ) );
		\WP_CLI::log( sprintf( "\nRecommend score (NPS): %d · promoters %d%% · detractors %d%%", round( 100 * ( $pro - $det ) / $n ), round( 100 * $pro / $n ), round( 100 * $det / $n ) ) );

		$testi = array_filter( $all, function ( $m ) { return '' !== ( $m['testimonial'] ?? '' ); } );
		$perm  = array_filter( $testi, function ( $m ) { return ! empty( $m['permission'] ); } );
		\WP_CLI::log( sprintf( 'Testimonials: %d written, %d with permission to use name and photo.', count( $testi ), count( $perm ) ) );

		if ( ! empty( $assoc['text'] ) ) {
			\WP_CLI::log( "\nOne thing we should improve:" );
			foreach ( $all as $m ) {
				\WP_CLI::log( '  [' . ( $parent( $m ) ? 'parent' : 'self' ) . ', ' . trim( (string) ( $m['gender'] ?? '' ) ) . ', ' . ( $m['plan'] ?? '' ) . '] ' . str_replace( "\n", ' ', (string) ( $m['improve'] ?? '' ) ) );
			}
			\WP_CLI::log( "\nTestimonials:" );
			foreach ( $testi as $uid => $m ) {
				$u = get_userdata( $uid );
				\WP_CLI::log( '  ' . ( empty( $m['permission'] ) ? '[no permission] ' : '[OK to use, member ' . $uid . ( $u ? ' ' . $u->display_name : '' ) . '] ' ) . str_replace( "\n", ' ', (string) $m['testimonial'] ) );
			}
		}
	}
}
