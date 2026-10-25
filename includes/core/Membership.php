<?php
/**
 * Membership — the ONE definition of "is this user premium?".
 *
 * Replaces the ~6 copies scattered across snippets (csm_ck_is_premium #11795,
 * csm_ps_is_premium #11801, csm_pv_is_premium #11811, csm_rv_is_premium #11807,
 * and inline PMPro checks in #11614/#11620/#11675). Same logic as #11795.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Membership {

	/**
	 * Is the user a premium member?
	 *
	 * @param int $user_id Defaults to the current user.
	 */
	public static function is_premium( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
			return false;
		}
		// Gold includes everything in Premium (v1.87.0).
		return (bool) pmpro_hasMembershipLevel( array( Config::PMPRO_PREMIUM_LEVEL, Config::PMPRO_GOLD_LEVEL ), $user_id );
	}

	/** Is the user a Gold member? */
	public static function is_gold( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
			return false;
		}
		return (bool) pmpro_hasMembershipLevel( Config::PMPRO_GOLD_LEVEL, $user_id );
	}

	/**
	 * Human label for the user's membership ("Premium" / "Free").
	 *
	 * @param int $user_id Defaults to the current user.
	 */
	public static function level_label( $user_id = 0 ) {
		return self::is_gold( $user_id ) ? 'Gold' : ( self::is_premium( $user_id ) ? 'Premium' : 'Free' );
	}
}
