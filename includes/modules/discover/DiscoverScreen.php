<?php
/**
 * Discover — the full profile, as a scrollable card.
 *
 * Owner's information architecture: "the discover section shows full profile of
 * the other user as a scrollable card". That is a different product from the
 * old tray, which showed a photo and six chips and made people leave the screen
 * to learn anything. One person at a time, everything about them, then Pass or
 * Like without navigating anywhere.
 *
 * Rendered as its own document via Core\AppPage — no BuddyX, no BuddyPress
 * template, no page-per-profile reload.
 *
 * IT DOES NOT REIMPLEMENT THE RULES. The tray, the weekly refill and the quota
 * belong to the existing Discover module and the #11600 reset; like and pass go
 * through Discover::act(), the same method admin-ajax uses. This class is a
 * presentation layer over machinery that already works — reimplementing the
 * mutual-match detection would eventually mean two answers to "did these two
 * match?".
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiscoverScreen {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* -------------------------------------------------------------- route */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'discover' ) ) {
			return;
		}

		/*
		 * No gender, no Discover. The engine now refuses to serve a member whose
		 * gender is unknown (it used to serve them men), so this screen would
		 * show an empty tray with no explanation. Send them to the wizard, which
		 * now asks the question. Admins are exempt, as everywhere else.
		 */
		if ( ! current_user_can( 'manage_options' ) && function_exists( 'cashaadi' ) ) {
			$g = trim( (string) cashaadi()->get_gender( get_current_user_id() ) );
			if ( 'Male' !== $g && 'Female' !== $g ) {
				wp_safe_redirect( home_url( '/welcome/' ) );
				exit;
			}
		}

		AppPage::assets();
		Assets::style( 'discover-app', 'assets/css/discover-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'discover-app', 'assets/js/discover-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-discover-app', 'CSM_DISCOVER', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'queue'   => rest_url( 'csm/v1/discover/queue' ),
			'act'     => rest_url( 'csm/v1/discover/act' ),
			'view'    => rest_url( 'csm/v1/discover/view' ),
			'filters' => rest_url( 'csm/v1/discover/filters' ),
		) );

		AppPage::open( __( 'Discover', 'cashaadi-ui' ), 'discover' );
		echo '<div id="csm-discover-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'discover' );
		exit;
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/discover/queue', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_queue' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'csm/v1', '/discover/view', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_view' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'profile_id' => array( 'required' => true ),
			),
		) );

		register_rest_route( 'csm/v1', '/discover/act', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_act' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'profile_id' => array( 'required' => true ),
				'action'     => array( 'required' => true ),
			),
		) );
	}

	/**
	 * The viewer's pending tray, as full profiles.
	 *
	 * Only pending rows, in assignment order — exactly what the shortcode showed,
	 * so the two views of the same tray agree.
	 */
	const PAGE = 24;

	public static function rest_queue( $request ) {
		$viewer_id = get_current_user_id();
		// Gold "Show more": load another page (v1.88.2).
		if ( $request->get_param( 'more' ) && class_exists( '\\CAShaadi\\Modules\\Premium\\Gold' ) ) {
			\CAShaadi\Modules\Premium\Gold::$more = true;
		}

		// No-op when the tray is already filled; owned by #11600.
		if ( function_exists( 'csm_refill_tray' ) ) {
			csm_refill_tray( $viewer_id );
		}
		// A running profile boost, as a bonus card at the front (v1.89.47).
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\Spotlight' ) ) {
			\CAShaadi\Modules\Premium\Spotlight::deliver( $viewer_id );
		}
		// A member who joined mid-week, as an extra card (v1.89.91).
		if ( class_exists( __NAMESPACE__ . '\\Intro' ) ) {
			Intro::deliver( $viewer_id );
		}

		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT profile_id, week_assigned FROM {$tray}
			 WHERE viewer_id = %d AND status = 'pending'
			 ORDER BY ( week_assigned = 'boost' ) DESC, ( week_assigned = 'intro' ) DESC, assigned_at ASC",
			$viewer_id
		) );

		/*
		 * Never a match, or someone already asked, in Discover (v1.89.78). A
		 * number opened before v1.89.65 sent the request but left the card
		 * pending, so a match showed with "Send request" (owner, 2026-10-26).
		 * Such rows are closed as liked, which is what happened.
		 */
		if ( $rows ) {
			$pids  = array_map( 'intval', wp_list_pluck( $rows, 'profile_id' ) );
			$in    = implode( ',', $pids );
			$fr    = $wpdb->prefix . 'bp_friends';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT friend_user_id FROM {$fr} WHERE initiator_user_id = %d AND friend_user_id IN ({$in})
				 UNION SELECT initiator_user_id FROM {$fr} WHERE friend_user_id = %d AND is_confirmed = 1 AND initiator_user_id IN ({$in})",
				$viewer_id,
				$viewer_id
			) ) );
			if ( $done ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$tray} SET status = 'liked', acted_at = %s WHERE viewer_id = %d AND status = 'pending' AND profile_id IN (" . implode( ',', $done ) . ')', current_time( 'mysql' ), $viewer_id ) );
				$done = array_flip( $done );
				$rows = array_values( array_filter( $rows, function ( $r ) use ( $done ) { return ! isset( $done[ (int) $r->profile_id ] ); } ) );
			}
		}

		// Filters only narrow what is in the tray (v1.89.11); hidden rows stay pending.
		$in_tray = count( (array) $rows );
		if ( $rows && class_exists( __NAMESPACE__ . '\\Filters' ) ) {
			$keep = array_flip( Filters::keep( $viewer_id, wp_list_pluck( $rows, 'profile_id' ) ) );
			// A boosted card is shown whatever the filters (owner, 2026-10-26).
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $keep ) { return 'boost' === $r->week_assigned || isset( $keep[ (int) $r->profile_id ] ); } ) );
		}

		/*
		 * 24 at a time for everyone (owner, 2026-10-26): a Premium deck that
		 * has built up over weeks must not load in one go. The screen asks for
		 * more with ?limit=; the deck itself is untouched.
		 */
		$total = count( (array) $rows );
		$limit = max( self::PAGE, (int) $request->get_param( 'limit' ) );
		$rows  = array_slice( (array) $rows, 0, $limit );

		$week     = gmdate( 'o-\WW' );
		$profiles = array();

		foreach ( (array) $rows as $row ) {
			$pid = (int) $row->profile_id;

			// A blocked member must never appear, and the block module owns that
			// list. Guarded because block is flag-gated.
			if ( function_exists( 'csm_bl_hidden_ids' ) ) {
				$hidden = (array) csm_bl_hidden_ids( $viewer_id );
				if ( in_array( $pid, array_map( 'intval', $hidden ), true ) ) {
					continue;
				}
			}

			$p          = Profile::full( $pid, $viewer_id );
			/*
			 * NEW: the member joined in the last 14 days (v1.89.91). It meant
			 * "drawn into your Discover this week", which was every card, and
			 * read as a new member (owner, 2026-10-27).
			 */
			$reg        = get_userdata( $pid );
			$p['isNew'] = $reg && strtotime( $reg->user_registered . ' UTC' ) > time() - 14 * DAY_IN_SECONDS;
			$profiles[] = $p;
		}

		/*
		 * The empty state is a moment worth using, not a dead end.
		 *
		 * Owner: "Suggest premium instead of you're all caught up - use the earlier
		 * thing, how much time remaining". So it now says when the next profiles
		 * arrive — quoting Discover::next_monday_ist(), the SAME reset the quota
		 * banner uses, so the two can never disagree — and offers Premium to free
		 * members, who get a larger weekly quota.
		 */
		$next    = method_exists( '\CAShaadi\Modules\Discover\Discover', 'next_monday_ist' )
			? Discover::next_monday_ist() : null;
		$premium = class_exists( '\CAShaadi\Core\Membership' )
			&& \CAShaadi\Core\Membership::is_premium( $viewer_id );

		return new \WP_REST_Response( array(
			'ok'        => true,
			'profiles'  => $profiles,
			// Undecided profiles the filters are hiding right now.
			'hiddenByFilter' => max( 0, $in_tray - $total ),
			// Undecided profiles waiting beyond those loaded (paging).
			'total'     => $total,
			'isPremium' => $premium,
			'resetOn'   => $next ? $next->format( 'l, j M' ) : '',
			'resetIso'  => $next ? $next->format( 'c' ) : '',
			// The authoritative weekly quota (Discover engine #11599): free 5,
			// Premium (PMPro level 2) 10. Surfaced so the empty state can name the
			// number the member just hit and what Premium changes it to.
			// Women: free 15 (FreeFemale, from 2026-10-05) and Premium 50
			// (Filters); men: 5 and 10.
			'freeQuota'    => self::free_quota( $viewer_id ),
			'premiumQuota' => self::premium_quota( $viewer_id ),
			// What to do while waiting for next Monday (owner, 2026-10-03:
			// members finished their set in minutes and left).
			'nextSteps'    => self::next_steps( $viewer_id ),
			// +5 once for a photo, +5 once for a verified badge (one-time since v1.89.45).
			'boost'        => class_exists( __NAMESPACE__ . '\\Boost' ) ? Boost::state( $viewer_id ) : null,
			// This member's own grant, and the filter sheet's state.
			'quota'        => Discover::quota_for( $viewer_id ),
			// Decided this week (liked / passed / saved), for "N of 50 left" (v1.89.7).
			'decidedWeek'  => (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}csm_tray WHERE viewer_id = %d AND week_assigned = %s AND status <> 'pending'",
				$viewer_id,
				function_exists( 'cashaadi' ) ? cashaadi()->get_week_id() : ''
			) ),
			/*
			 * A 50-profile set cannot be surveyed one card at a time — the
			 * carousel that works for 5 would be 50 sequential taps. Above this
			 * many, the app opens on a grid and the carousel becomes the detail
			 * view you reach by tapping a card (owner, 2026-09-22).
			 */
			// Every Premium and Gold member gets the grid, men's 10 a week too (owner, 2026-10-26).
			'grid'         => Discover::quota_for( $viewer_id ) >= (int) apply_filters( 'csm_discover_grid_from', 20 ) || $premium,

			'filters'      => class_exists( __NAMESPACE__ . '\\Filters' ) ? Filters::state( $viewer_id ) : null,
			'upgrade'   => site_url( '/membership-pricing/' ),
			// Gold: every member, 50 at a time, no weekly limit (v1.88.1).
			'unlimited' => class_exists( '\\CAShaadi\\Core\\Membership' ) && \CAShaadi\Core\Membership::is_gold( $viewer_id ),
		), 200 );
	}

	private static function is_female( $uid ) {
		return function_exists( 'cashaadi' ) && 'Female' === trim( (string) cashaadi()->get_gender( (int) $uid ) );
	}

	private static function free_quota( $uid ) {
		return self::is_female( $uid ) && class_exists( __NAMESPACE__ . '\\FreeFemale' ) ? FreeFemale::quota_for_women() : 5;
	}

	private static function premium_quota( $uid ) {
		return self::is_female( $uid ) && class_exists( __NAMESPACE__ . '\\Filters' ) ? (int) Filters::QUOTA_PREMIUM_FEMALE : 10;
	}

	/**
	 * Up to three things worth doing until the next set arrives, most useful
	 * first, each only when it applies to this member.
	 *
	 * @return array<int,array{title:string,body:string,url:string}>
	 */
	private static function next_steps( $uid ) {
		$uid   = (int) $uid;
		$steps = array();

		// Requests waiting for an answer: the most valuable thing on the site.
		if ( function_exists( 'friends_get_friendship_request_user_ids' ) ) {
			$n = count( (array) friends_get_friendship_request_user_ids( $uid ) );
			if ( $n ) {
				$steps[] = array(
					'title' => sprintf( _n( '%d person wants to match with you', '%d people want to match with you', $n, 'cashaadi-ui' ), $n ),
					'body'  => __( 'Answer their requests.', 'cashaadi-ui' ),
					'url'   => home_url( '/requests/' ),
				);
			}
		}

		$photos = get_user_meta( $uid, 'csm_photos', true );
		$count  = is_array( $photos ) ? count( $photos ) : 0;
		if ( $count < 3 && function_exists( 'bp_members_get_user_url' ) ) {
			$steps[] = array(
				'title' => 0 === $count ? __( 'Add a photo', 'cashaadi-ui' ) : __( 'Add more photos', 'cashaadi-ui' ),
				'body'  => 0 === $count && class_exists( __NAMESPACE__ . '\\Boost' )
					? sprintf( __( 'Get %d more profiles this week, and far more requests.', 'cashaadi-ui' ), Boost::per() )
					: __( 'Profiles with photos get far more requests.', 'cashaadi-ui' ),
				'url'   => trailingslashit( bp_members_get_user_url( $uid ) ) . 'profile/change-avatar/',
			);
		}

		if ( class_exists( '\\CAShaadi\\Modules\\CaVerify\\CaVerify' ) && 'none' === \CAShaadi\Modules\CaVerify\CaVerify::member_state( $uid )
			&& '' !== \CAShaadi\Modules\CaVerify\CaVerify::claim( $uid ) ) {
			$steps[] = array(
				'title' => __( 'Get your Verified badge', 'cashaadi-ui' ),
				'body'  => class_exists( __NAMESPACE__ . '\\Boost' )
					? sprintf( __( 'Upload your %1$s. Get %2$d more profiles once approved.', 'cashaadi-ui' ), \CAShaadi\Modules\CaVerify\CaVerify::doc_label( $uid ), Boost::per() )
					: sprintf( __( 'Upload your %s. Members trust verified profiles more.', 'cashaadi-ui' ), \CAShaadi\Modules\CaVerify\CaVerify::doc_label( $uid ) ),
				'url'   => home_url( '/profile/edit/?g=10' ),
			);
		}

		if ( class_exists( '\\CAShaadi\\Core\\Profile' ) ) {
			$c = \CAShaadi\Core\Profile::completion( $uid );
			if ( is_array( $c ) && ! empty( $c['outstanding'] ) ) {
				$steps[] = array(
					'title' => __( 'Finish your profile', 'cashaadi-ui' ),
					'body'  => sprintf( _n( '%d detail left. Complete profiles are shown more.', '%d details left. Complete profiles are shown more.', (int) $c['outstanding'], 'cashaadi-ui' ), (int) $c['outstanding'] ),
					'url'   => home_url( '/profile/edit/' ),
				);
			}
		}

		return array_slice( $steps, 0, 3 );
	}

	/**
	 * A profile was actually put in front of the member.
	 *
	 * Owner's rule (2026-09-04): appearing in an active member's tray IS being
	 * viewed, and the only ways to avoid triggering a view are to be inactive or
	 * to never scroll your tray — both intentional. So this fires when the card
	 * is drawn, not when the tray is filled: filling happens server-side and
	 * would count members who never opened Discover.
	 *
	 * AUTHORISATION, same lesson as Discover::act(): without a tray check anyone
	 * could POST arbitrary ids and spam "someone viewed your profile" emails to
	 * the entire site. A pending row for this pair is the proof that we put the
	 * profile in front of this member.
	 *
	 * The email itself is capped at one per member per calendar day inside
	 * Premium::notify_view(), so a member scrolling five profiles does not send
	 * five emails to the same person.
	 */
	public static function rest_view( $request ) {
		$viewer = get_current_user_id();
		$pid    = absint( $request->get_param( 'profile_id' ) );

		if ( ! $viewer || ! $pid || $viewer === $pid ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}

		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';
		$in   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND profile_id = %d AND status = 'pending'",
			$viewer,
			$pid
		) );
		if ( ! $in ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}

		if ( ! class_exists( '\CAShaadi\Modules\Premium\Premium' ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}

		\CAShaadi\Modules\Premium\Premium::record_view( $viewer, $pid );
		\CAShaadi\Modules\Premium\Premium::notify_view( $viewer, $pid );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function rest_act( $request ) {
		$pid    = absint( $request->get_param( 'profile_id' ) );
		$what   = sanitize_text_field( (string) $request->get_param( 'action' ) );
		$map    = array( 'like' => 'liked', 'pass' => 'passed', 'save' => 'saved' );
		$status = isset( $map[ $what ] ) ? $map[ $what ] : 'passed';

		$note = '';
		if ( 'liked' === $status && class_exists( '\\CAShaadi\\Modules\\Requests\\RequestNote' ) ) {
			$note = \CAShaadi\Modules\Requests\RequestNote::clean( (string) $request->get_param( 'note' ) );
			if ( is_wp_error( $note ) ) {
				return new \WP_REST_Response( array( 'ok' => false, 'message' => $note->get_error_message(), 'isMutual' => false, 'remaining' => 0 ), 200 );
			}
		}
		$res = Discover::act( get_current_user_id(), $pid, $status, $note );

		return new \WP_REST_Response( array(
			'ok'        => (bool) $res['ok'],
			'isMutual'  => (bool) $res['is_mutual'],
			'remaining' => (int) $res['remaining'],
		), 200 );
	}
}
