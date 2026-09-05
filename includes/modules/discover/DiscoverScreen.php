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

		AppPage::assets();
		Assets::style( 'discover-app', 'assets/css/discover-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'discover-app', 'assets/js/discover-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-discover-app', 'CSM_DISCOVER', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'queue' => rest_url( 'csm/v1/discover/queue' ),
			'act'   => rest_url( 'csm/v1/discover/act' ),
			'view'  => rest_url( 'csm/v1/discover/view' ),
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
	public static function rest_queue( $request ) {
		unset( $request );
		$viewer_id = get_current_user_id();

		// No-op when the tray is already filled; owned by #11600.
		if ( function_exists( 'csm_refill_tray' ) ) {
			csm_refill_tray( $viewer_id );
		}

		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT profile_id, week_assigned FROM {$tray}
			 WHERE viewer_id = %d AND status = 'pending'
			 ORDER BY assigned_at ASC",
			$viewer_id
		) );

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
			$p['isNew'] = ( isset( $row->week_assigned ) && $row->week_assigned === $week );
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
			'isPremium' => $premium,
			'resetOn'   => $next ? $next->format( 'l, j M' ) : '',
			'resetIso'  => $next ? $next->format( 'c' ) : '',
			// The authoritative weekly quota (Discover engine #11599): free 5,
			// Premium (PMPro level 2) 10. Surfaced so the empty state can name the
			// number the member just hit and what Premium changes it to.
			'freeQuota'    => 5,
			'premiumQuota' => 10,
			'upgrade'   => site_url( '/membership-pricing/' ),
		), 200 );
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

		$res = Discover::act( get_current_user_id(), $pid, $status );

		return new \WP_REST_Response( array(
			'ok'        => (bool) $res['ok'],
			'isMutual'  => (bool) $res['is_mutual'],
			'remaining' => (int) $res['remaining'],
		), 200 );
	}
}
