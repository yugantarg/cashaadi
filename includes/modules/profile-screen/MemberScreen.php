<?php
/**
 * MemberScreen — viewing somebody else's profile, in the app.
 *
 * THE GAP THIS FILLS. The rebuild covered Discover, Requests, Messages, the
 * member's own Profile and Settings — but never "look at another member". So
 * every route to someone else (a saved profile, a received request, the
 * viewed-me list) dropped out of the app onto BuddyPress's own member page:
 * different typography, "Add Match / Private Message / Block" buttons, a tab
 * strip. The owner found it by tapping a name in their Saved list.
 *
 * Renders the SAME card as Discover and "how others see me", via the shared
 * csmProfileCard(). Three copies of that markup is how they drift apart.
 *
 * VISIBILITY IS THE VIEWER'S. Core\Profile::full() is asked for the profile as
 * THIS viewer, so per-field privacy, photo blurring and the block list all apply
 * exactly as they do in Discover. This screen adds no new way to see anything.
 */

namespace CAShaadi\Modules\ProfileScreen;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MemberScreen {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** The member id from /member/<id>/, or 0. */
	private static function requested_id() {
		$path = trim( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ), '/' );
		if ( 0 !== strpos( $path, 'member/' ) ) {
			return 0;
		}
		$parts = explode( '/', $path );
		return isset( $parts[1] ) ? absint( $parts[1] ) : 0;
	}

	public static function maybe_render() {
		$uid = self::requested_id();
		if ( ! $uid ) {
			return;
		}
		if ( ! AppPage::claim( 'member/' . $uid ) ) {
			return;
		}

		// Your own profile has its own screen; send them there rather than
		// rendering a stranger's view of themselves.
		if ( get_current_user_id() === $uid ) {
			wp_safe_redirect( home_url( '/profile/' ) );
			exit;
		}

		AppPage::assets();
		Assets::style( 'discover-app', 'assets/css/discover-app.css', array( 'cashaadi-app-screens' ) );
		Assets::style( 'profile-app', 'assets/css/profile-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'member-screen', 'assets/js/member-screen.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-member-screen', 'CSM_MEMBER', array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'get'      => rest_url( 'csm/v1/member/' . $uid ),
			/*
			 * Discover's OWN act endpoint, not a second one. It claims the tray
			 * row as authorisation before it writes anything, so a like from
			 * here is authorised on exactly the same terms as a like from
			 * Discover — and there is no second copy of that check to drift.
			 */
			'act'      => rest_url( 'csm/v1/discover/act' ),
			'messages' => home_url( '/messages/' ),
			'back'     => wp_get_referer() ? wp_get_referer() : home_url( '/requests/' ),
		) );

		AppPage::open( __( 'Profile', 'cashaadi-ui' ), 'requests' );
		echo '<div id="csm-member-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'requests' );
		exit;
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/member/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_member' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/**
	 * One member, as this viewer may see them.
	 *
	 * Blocked pairs get nothing at all — not a redacted profile, which would
	 * still confirm the account exists and that something was hidden.
	 */
	public static function rest_member( $request ) {
		$viewer = get_current_user_id();
		$uid    = absint( $request['id'] );

		if ( ! $uid || ! get_userdata( $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That member could not be found.', 'cashaadi-ui' ) ), 200 );
		}
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $viewer, $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That member could not be found.', 'cashaadi-ui' ) ), 200 );
		}

		$profile = Profile::full( $uid, $viewer );

		/*
		 * What this viewer can DO with them, so the screen offers the one action
		 * that makes sense rather than a row of buttons that mostly do not.
		 */
		$status = function_exists( 'friends_check_friendship_status' )
			? friends_check_friendship_status( $viewer, $uid )
			: 'not_friends';

		global $wpdb;
		$tray  = $wpdb->prefix . 'csm_tray';
		$state = (string) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT status FROM {$tray} WHERE viewer_id = %d AND profile_id = %d ORDER BY id DESC LIMIT 1",
			$viewer,
			$uid
		) );

		return new \WP_REST_Response( array(
			'ok'         => true,
			'profile'    => $profile,
			'friendship' => $status,          // is_friend | pending | awaiting_response | not_friends
			'tray'       => $state,           // pending | saved | liked | passed | ''
		), 200 );
	}
}
