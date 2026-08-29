<?php
/**
 * OTP — global phone helpers migrated from WPCode #11618. Kept global (not class
 * methods) because sibling snippets/modules call them by name (the Verified-CA
 * badge #11701, the sales dashboard #11688, and the AJAX handler here).
 * All function_exists()-guarded; required by Otp::register() when enabled.
 *
 * Note: Core\Verification also exposes normalize_phone()/user_phone()/
 * phone_verified(); these globals are the number-tied variants the OTP flow and
 * its callers already depend on, kept verbatim to avoid changing behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'csm_normalize_phone' ) ) {
	/** Normalize a phone number to digits with a 91 prefix. */
	function csm_normalize_phone( $raw ) {
		$s = (string) $raw;
		// The phone field may be stored wrapped in HTML (e.g. <a href="tel://…">).
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$s = wp_strip_all_tags( $s );
		} else {
			$s = strip_tags( $s );
		}
		$s = html_entity_decode( $s, ENT_QUOTES );
		$v = preg_replace( '/[^0-9]/', '', $s );
		if ( strlen( $v ) > 12 ) { $v = substr( $v, -10 ); }
		if ( 10 === strlen( $v ) ) { $v = '91' . $v; }
		return $v;
	}
}

if ( ! function_exists( 'csm_get_user_phone' ) ) {
	/** The user's CURRENT profile phone (field 277), normalized. */
	function csm_get_user_phone( $uid ) {
		$phone = '';
		if ( function_exists( 'xprofile_get_field_data' ) ) {
			$phone = xprofile_get_field_data( 277, $uid );
			if ( '' === $phone || false === $phone || null === $phone ) {
				$phone = xprofile_get_field_data( 'Phone Number', $uid );
			}
		}
		if ( is_array( $phone ) ) { $phone = reset( $phone ); }
		return csm_normalize_phone( $phone );
	}
}

if ( ! function_exists( 'csm_phone_is_verified' ) ) {
	/** True only if the verified number still equals the user's current number. */
	function csm_phone_is_verified( $uid = 0 ) {
		if ( ! $uid ) { $uid = get_current_user_id(); }
		if ( '1' !== get_user_meta( $uid, 'csm_phone_verified', true ) ) { return false; }
		$verified_number = csm_normalize_phone( get_user_meta( $uid, 'csm_phone_verified_number', true ) );
		if ( empty( $verified_number ) ) { return false; }
		$current_number = csm_get_user_phone( $uid );
		if ( empty( $current_number ) ) { return false; }
		return $verified_number === $current_number;
	}
}
