<?php
/**
 * Photos compatibility shims (global namespace).
 *
 * The still-active photo-request snippet (#11798) calls the GLOBAL function
 * csm_photo_is_hidden(), which #11770 used to define. When #11770 is migrated
 * (its snippet disabled) and our Privacy component is active, #11798 would
 * otherwise fatal on that undefined call. Provide a delegating shim so #11798
 * keeps working until it too is migrated. The function_exists guard means this
 * never collides with the snippet while both are active.
 *
 * Loaded by Photos::register() only when Config::photos_enabled() is true.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'csm_photo_is_hidden' ) ) {
	/**
	 * @param int      $owner_id
	 * @param int|null $viewer_id
	 * @return bool
	 */
	function csm_photo_is_hidden( $owner_id, $viewer_id = null ) {
		return \CAShaadi\Modules\Photos\Privacy::is_hidden( $owner_id, $viewer_id );
	}
}
