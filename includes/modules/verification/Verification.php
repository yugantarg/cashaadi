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

		/*
		 * The OTP checklist prompt is OFF (owner, 2026-09-08): "completely
		 * remove the phone verification need".
		 *
		 * It asked every member who had not verified a phone number to go and
		 * do it, on their own profile, every visit — for something that now
		 * gates nothing. A standing prompt for an optional step is just noise,
		 * and it implied the profile was incomplete when it was not.
		 *
		 * Nothing is removed from anyone: csm_phone_verified survives for the
		 * members who did it, phone_verified() still reports it, and the OTP
		 * flow still works for anyone who chooses to use it.
		 */
		$otp = false;

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
