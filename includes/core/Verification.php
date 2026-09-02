<?php
/**
 * Verification — one place that answers "is this user's phone verified?" and
 * "is this a verified CA?", consolidating the status logic spread across
 * #11618 (phone OTP), #11701 (badge), #11815 (AI doc), #11682 (checklist).
 *
 * This is a read-only status library: it never performs OTP or AI calls (those
 * stay in their modules) and stores nothing. Logic mirrors the snippets exactly.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verification {

	/* ---- phone (mirrors #11618) ---------------------------------------- */

	/**
	 * Normalise a phone number to digits, dropping a leading 91/0 country/trunk
	 * prefix down to the local 10 digits where possible.
	 */
	public static function normalize_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $digits ) {
			return '';
		}
		// Drop a leading 91 (India) or 0 trunk if it leaves 10+ digits.
		if ( 0 === strpos( $digits, '91' ) && strlen( $digits ) > 10 ) {
			$digits = substr( $digits, -10 );
		} elseif ( 0 === strpos( $digits, '0' ) && strlen( $digits ) > 10 ) {
			$digits = substr( $digits, -10 );
		}
		return $digits;
	}

	/**
	 * The user's own phone number from xProfile field 277, normalised.
	 */
	public static function user_phone( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}
		/*
		 * RAW value, not xprofile_get_field_data().
		 *
		 * That applies display filters, and the telephone field type returns
		 *   <a href="tel://08697222644" rel="nofollow">08697222644</a>
		 * normalize_phone() then strips non-digits from the whole string and
		 * harvests the number TWICE — once from the href, once from the link text.
		 * Observed live: "86972226448697222644".
		 *
		 * This is not only a display fault: this method is what an OTP would be
		 * sent to.
		 */
		$phone = class_exists( '\BP_XProfile_ProfileData' )
			? \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_PHONE, $user_id )
			: xprofile_get_field_data( Config::FIELD_PHONE, $user_id );

		return self::normalize_phone( $phone );
	}

	/**
	 * Phone verified = the stored verified flag is set AND the stored verified
	 * number still matches the number currently on the profile (field 277).
	 */
	public static function phone_verified( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( '1' !== (string) get_user_meta( $user_id, 'csm_phone_verified', true ) ) {
			return false;
		}
		$verified_number = self::normalize_phone( get_user_meta( $user_id, 'csm_phone_verified_number', true ) );
		if ( '' === $verified_number ) {
			return false;
		}
		$current = self::user_phone( $user_id );
		// If the profile has a number, it must match the verified one.
		return ( '' === $current || $current === $verified_number );
	}

	/* ---- CA verification (mirrors #11701) ------------------------------ */

	/**
	 * Verified CA = phone-verified, not AI-rejected, and an ICAI document
	 * (field 484) is present.
	 */
	public static function ca_verified( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! function_exists( 'bp_get_profile_field_data' ) ) {
			return false;
		}
		if ( ! get_user_meta( $user_id, 'csm_phone_verified', true ) ) {
			return false;
		}
		if ( 'rejected' === get_user_meta( $user_id, 'csm_av_status', true ) ) {
			return false;
		}
		$doc = bp_get_profile_field_data( array( 'field' => Config::FIELD_CA_DOC, 'user_id' => $user_id ) );
		return '' !== trim( wp_strip_all_tags( (string) $doc ) );
	}

	/**
	 * Qualification level from field 571: 'ca' | 'inter' | 'other'.
	 */
	public static function ca_level( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! function_exists( 'bp_get_profile_field_data' ) ) {
			return 'other';
		}
		$v = strtolower( trim( wp_strip_all_tags( (string) bp_get_profile_field_data(
			array( 'field' => Config::FIELD_QUALIFICATION, 'user_id' => $user_id )
		) ) ) );
		if ( '' === $v ) {
			return 'other';
		}
		if ( 'ca inter' === $v || false !== strpos( $v, 'inter' ) ) {
			return 'inter';
		}
		if ( 'ca' === $v || 'ca final' === $v || false !== strpos( $v, 'final' ) ) {
			return 'ca';
		}
		return 'other';
	}
}
