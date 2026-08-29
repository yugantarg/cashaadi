<?php
/**
 * OTP module — phone-number verification via MSG91 (migrates WPCode #11618).
 *
 * Preserves: the AJAX verify handler (wp_ajax_csm_mark_phone_verified, nonce
 * csm_otp_nonce), the number-tied verified state (user meta csm_phone_verified /
 * _number / _at), the two safety guards (never stamp while User-Switching; the
 * verified number must equal the user's OWN field-277 number), and the profile-
 * edit OTP widget UI.
 *
 * NOT migrated (intentionally): the template_redirect browsing gate — it was
 * already disabled in the snippet ("OTP browsing gate disabled 2026-08-25 — OTP
 * now only affects the Verified badge #11701"), so replicating it would resurrect
 * dead code. Phone-unverified users are handled by the Profile-Gate blur (#11620).
 *
 * Gated behind Config::otp_enabled(). UNLIKE the other modules this one ALSO
 * needs live credentials: set CASHAADI_MSG91_AUTHKEY / _WIDGET_ID / _TOKEN_AUTH
 * in wp-config (read via Core\Secrets). Flip the flag on in the SAME change that
 * disables #11618. The MSG91 authkey/widget/token literals from the snippet are
 * NOT copied here — they come from Secrets.
 */

namespace CAShaadi\Modules\Otp;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Otp {

	public static function register() {
		if ( ! Config::otp_enabled() ) {
			return; // gated OFF until cutover (and until MSG91 creds are set)
		}

		require_once __DIR__ . '/functions.php';

		add_action( 'wp_ajax_csm_mark_phone_verified', array( __CLASS__, 'mark_verified' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/* ---- AJAX: verify the MSG91 access token, then stamp the number -------- */

	public static function mark_verified() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'not_logged_in' );
		}
		check_ajax_referer( 'csm_otp_nonce', 'nonce' );

		$uid             = get_current_user_id();
		$token           = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
		$submitted_phone = isset( $_POST['phone'] ) ? csm_normalize_phone( wp_unslash( $_POST['phone'] ) ) : '';
		if ( empty( $token ) ) {
			wp_send_json_error( 'no_token' );
		}

		// Verify the access token with MSG91 server-side.
		$authkey = Secrets::msg91_authkey();
		$resp    = wp_remote_post( 'https://control.msg91.com/api/v5/widget/verifyAccessToken', array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'authkey' => $authkey, 'access-token' => $token ) ),
		) );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( 'http_error' );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$ok   = isset( $body['type'] ) && 'success' === $body['type'];
		if ( ! $ok ) {
			wp_send_json_error( array( 'reason' => 'verify_failed', 'raw' => $body ) );
		}

		// Prefer the number MSG91 actually verified; fall back to the submitted one.
		$verified_number = '';
		if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$verified_number = csm_normalize_phone( $body['message'] );
		}
		if ( empty( $verified_number ) && isset( $body['mobile'] ) ) {
			$verified_number = csm_normalize_phone( $body['mobile'] );
		}
		if ( empty( $verified_number ) ) {
			$verified_number = $submitted_phone;
		}
		if ( empty( $verified_number ) ) {
			wp_send_json_error( 'no_number' );
		}

		// Guard A: never stamp a verification while impersonating another account
		// (User Switching makes get_current_user_id() the target user).
		if ( function_exists( 'current_user_switched' ) && current_user_switched() ) {
			wp_send_json_error( array( 'reason' => 'switched_user' ) );
		}

		// Guard B: the verified number must be this user's OWN profile number,
		// else csm_phone_is_verified() would lock them out of Discover forever.
		$own_number = csm_get_user_phone( $uid );
		if ( ! empty( $own_number ) && $own_number !== csm_normalize_phone( $verified_number ) ) {
			wp_send_json_error( array( 'reason' => 'number_mismatch' ) );
		}

		update_user_meta( $uid, 'csm_phone_verified', '1' );
		update_user_meta( $uid, 'csm_phone_verified_number', $verified_number );
		update_user_meta( $uid, 'csm_phone_verified_at', current_time( 'mysql' ) );
		wp_send_json_success( 'verified' );
	}

	/* ---- front-end: OTP widget on the profile-edit screen ----------------- */

	public static function assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'bp_is_user_profile_edit' ) || ! bp_is_user_profile_edit() ) {
			return;
		}

		// MSG91's provider is an external script; the widget JS depends on it.
		wp_enqueue_script( 'msg91-otp-provider', 'https://verify.msg91.com/otp-provider.js', array(), null, true );

		Assets::style( 'otp', 'assets/css/otp.css' );
		Assets::script( 'otp', 'assets/js/otp.js', array( 'msg91-otp-provider' ) );

		$uid = get_current_user_id();
		wp_add_inline_script(
			'cashaadi-otp',
			'window.CASHAADI_OTP=' . wp_json_encode( array(
				'verified'       => csm_phone_is_verified() ? 1 : 0,
				'verifiedNumber' => csm_normalize_phone( get_user_meta( $uid, 'csm_phone_verified_number', true ) ),
				'nonce'          => wp_create_nonce( 'csm_otp_nonce' ),
				'ajax'           => admin_url( 'admin-ajax.php' ),
				'widgetId'       => Secrets::msg91_widget_id(),
				'tokenAuth'      => Secrets::msg91_token_auth(),
			) ) . ';',
			'before'
		);
	}
}
