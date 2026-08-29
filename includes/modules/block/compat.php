<?php
/**
 * Block compatibility shims (global namespace).
 *
 * The still-active tray-refill (#11599) calls the GLOBAL csm_bl_hidden_ids(), and
 * the profile-view email (#11821 / the Premium module) calls csm_bl_is_blocked_pair()
 * — both defined by #11810 today. When #11810 is migrated (its snippet disabled)
 * and this Block module is active, those cross-snippet callers would otherwise
 * fatal on the now-undefined calls. Provide delegating shims so they keep working.
 * The function_exists guard means these never collide with #11810 while both are
 * active (the snippet's definitions win; ours are skipped).
 *
 * Loaded by Block::register() only when Config::block_enabled() is true.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'csm_bl_hidden_ids' ) ) {
	/**
	 * All user ids hidden for $uid (everyone $uid blocked + everyone who blocked $uid).
	 *
	 * @param int $uid
	 * @return int[]
	 */
	function csm_bl_hidden_ids( $uid ) {
		return \CAShaadi\Modules\Block\Block::hidden_ids( $uid );
	}
}

if ( ! function_exists( 'csm_bl_is_blocked_pair' ) ) {
	/**
	 * True if either user has blocked the other.
	 *
	 * @param int $a
	 * @param int $b
	 * @return bool
	 */
	function csm_bl_is_blocked_pair( $a, $b ) {
		return \CAShaadi\Modules\Block\Block::is_blocked_pair( $a, $b );
	}
}
