<?php
/**
 * "Get your Verified CA badge" — a one-time popup, shown to a sample.
 *
 * Owner, 2026-10-01: 912 of 1,144 accounts have never uploaded an ICAI
 * document. Show a popup once, on next login, to a sample of them, then
 * compare upload rates against the members who were not shown it.
 *
 * Eligible: a member (not an admin) with nothing uploaded (member_state
 * 'none'). On the first eligible page load the member is assigned an arm,
 * once and permanently: 'show' with probability csm_verify_nudge_pct (0–100),
 * else 'control'. Both arms are stamped with the same moment, so the two
 * groups are "members who came back after the test started" alike.
 * 'show' is marked seen the moment the popup is shown, and a click on
 * "Upload now" is recorded separately.
 *
 * csm_verify_nudge_pct = 0 (or the option unset) ends the test: nobody is
 * assigned and no popup is shown, including to members already in 'show'.
 * Arms already recorded are kept, so the result can still be read.
 */

namespace CAShaadi\Modules\CaVerify;

use CAShaadi\Modules\ProfileEdit\ProfileEditScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerifyNudge {

	const ARM_META     = 'csm_vn_arm';      // 'show' | 'control'
	const AT_META      = 'csm_vn_at';       // when the arm was assigned
	const SEEN_META    = 'csm_vn_seen';     // when the popup was shown
	const CLICKED_META = 'csm_vn_clicked';  // when "Upload now" was tapped
	const PCT_OPT      = 'csm_verify_nudge_pct';
	const DOC_GROUP    = 10;                // profile editor section holding the ICAI upload

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/** What the app shell hands the page: a destination, or false. */
	public static function payload( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		// pct 0 ends the test outright: no new arms AND no pending popups
		// (owner, 2026-10-04: ended once the Discover card asked everyone).
		if ( (int) get_option( self::PCT_OPT, 0 ) <= 0 ) {
			return false;
		}
		$arm = (string) get_user_meta( $uid, self::ARM_META, true );
		if ( 'control' === $arm || get_user_meta( $uid, self::SEEN_META, true ) ) {
			return false;
		}
		if ( 'none' !== CaVerify::member_state( $uid ) || '' === CaVerify::claim( $uid ) ) {
			return false; // uploaded, decided or in review
		}
		if ( '' === $arm ) {
			$pct = max( 0, min( 100, (int) get_option( self::PCT_OPT, 0 ) ) );
			if ( 0 === $pct ) {
				return false; // test not running
			}
			$arm = wp_rand( 1, 100 ) <= $pct ? 'show' : 'control';
			update_user_meta( $uid, self::ARM_META, $arm );
			update_user_meta( $uid, self::AT_META, time() );
			if ( 'control' === $arm ) {
				return false;
			}
		}
		return array(
			'url'  => ProfileEditScreen::url( self::DOC_GROUP ),
			'seen' => rest_url( 'csm/v1/verify-nudge' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/verify-nudge', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_seen' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** POST marks it shown; POST with clicked=1 records the tap as well. */
	public static function rest_seen( $request ) {
		$uid = get_current_user_id();
		if ( 'show' !== get_user_meta( $uid, self::ARM_META, true ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! get_user_meta( $uid, self::SEEN_META, true ) ) {
			update_user_meta( $uid, self::SEEN_META, time() );
		}
		if ( $request->get_param( 'clicked' ) ) {
			update_user_meta( $uid, self::CLICKED_META, time() );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
