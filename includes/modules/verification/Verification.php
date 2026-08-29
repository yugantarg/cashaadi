<?php
/**
 * Verification display module.
 *
 * Consolidates two display snippets, both built on Core\Verification:
 *   #11701 Verified CA badge (blue/amber tick, REST-driven)
 *   #11682 OTP status item in the completion checklist
 *
 * Gated behind Config::verification_enabled() (they inject via JS/REST, so
 * both-active would double the badge or apply the checklist %-adjustment twice).
 * The OTP verification itself (#11618, MSG91) is NOT here — it stays in WPCode
 * until its key moves to a wp-config constant.
 */

namespace CAShaadi\Modules\Verification;

use CAShaadi\Core\Verification as Verify;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verification {

	public static function register() {
		if ( ! Config::verification_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}
		add_action( 'rest_api_init', array( __CLASS__, 'rest_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** POST csm/v1/verified {ids:[…]} -> { id: 'ca'|'inter'|'other'|false }. */
	public static function rest_route() {
		register_rest_route( 'csm/v1', '/verified', array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'callback'            => function ( $req ) {
				$out = array();
				foreach ( (array) $req->get_param( 'ids' ) as $id ) {
					$id = (int) $id;
					if ( $id ) {
						$out[ $id ] = Verify::ca_verified( $id ) ? Verify::ca_level( $id ) : false;
					}
				}
				return $out;
			},
		) );
	}

	public static function assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		Assets::style( 'verification', 'assets/css/verification.css' );
		Assets::script( 'verification', 'assets/js/verification.js' );

		// OTP checklist config: shown on the member's OWN profile when their phone
		// isn't verified (raw meta flag, matching #11682).
		$otp = false;
		if ( function_exists( 'bp_is_my_profile' ) && bp_is_my_profile() ) {
			$uid = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : get_current_user_id();
			if ( $uid && '1' !== (string) get_user_meta( $uid, 'csm_phone_verified', true ) ) {
				$edit = function_exists( 'bp_loggedin_user_url' ) && function_exists( 'bp_get_profile_slug' )
					? bp_loggedin_user_url( bp_members_get_path_chunks( array( bp_get_profile_slug(), 'edit' ) ) )
					: home_url( '/' );
				$otp = array( 'editUrl' => esc_url_raw( $edit ) );
			}
		}

		wp_add_inline_script(
			'cashaadi-verification',
			'window.CASHAADI_VERIFY=' . wp_json_encode( array(
				'rest'  => esc_url_raw( rest_url( 'csm/v1/verified' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'otp'   => $otp,
			) ) . ';',
			'before'
		);
	}
}
