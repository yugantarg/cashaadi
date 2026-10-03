<?php
/**
 * Gender and Date of birth: set at sign-up, changeable ONCE, then fixed.
 *
 * Owner, 2026-10-03: a woman deleted her account four minutes after joining
 * with the reason "Gender" — she had picked the wrong one, and the field
 * locked the moment it was saved, so leaving was her only fix. "Once in a
 * lifetime, give warning that it can't be changed again permanently —
 * actually do this with DOB also."
 *
 * Rules, enforced on the server whatever the client sends:
 *   - The first value (sign-up / wizard) is free: nothing is consumed.
 *   - A later CHANGE goes through only from the app editor, only with the
 *     member's explicit confirmation (the request names the field in
 *     confirm_once), and only if that field's one change is unused. It is then
 *     used: csm_once_changed_{field id} holds when, and from what.
 *   - Every other write path keeps the stored value (FieldLogic::once_lock,
 *     on bp_xprofile_set_field_data_pre_validate).
 *   - Administrators are never locked and never consume the change.
 *
 * A gender change also clears unacted Discover tray rows on both sides: the
 * member's own tray was filled with the old opposite gender, and they sit in
 * other members' trays as the old one.
 */

namespace CAShaadi\Modules\ProfileEdit;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OnceFields {

	/** Set while an approved one-time change is being written. */
	public static $unlocked = 0;

	public static function register() {
		// "Gender" alone reads as the account holder's own; a parent filling
		// in their daughter's profile picked their own (owner, 2026-10-03).
		add_filter( 'bp_get_the_profile_field_name', array( __CLASS__, 'signup_label' ) );
	}

	public static function signup_label( $name ) {
		if ( ! function_exists( 'bp_get_the_profile_field_id' ) || (int) bp_get_the_profile_field_id() !== (int) Config::FIELD_GENDER ) {
			return $name;
		}
		if ( ! function_exists( 'bp_is_register_page' ) || ! bp_is_register_page() ) {
			return $name;
		}
		return __( 'Gender of the bride / groom', 'cashaadi-ui' );
	}

	public static function ids() {
		return array( (int) Config::FIELD_GENDER, (int) Config::FIELD_DOB );
	}

	public static function is_once( $fid ) {
		return in_array( (int) $fid, self::ids(), true );
	}

	private static function meta( $fid ) {
		return 'csm_once_changed_' . (int) $fid;
	}

	public static function used( $uid, $fid ) {
		return (bool) get_user_meta( (int) $uid, self::meta( $fid ), true );
	}

	/** The stored value, normalised for comparison ('' when unset). */
	public static function stored( $uid, $fid ) {
		if ( ! class_exists( '\BP_XProfile_ProfileData' ) ) {
			return '';
		}
		$v = (string) \BP_XProfile_ProfileData::get_value_byid( (int) $fid, (int) $uid );
		$v = trim( wp_strip_all_tags( maybe_unserialize( $v ) ) );
		return '-' === $v ? '' : $v;
	}

	/** Compare like with like: dates by Y-m-d, everything else as text. */
	public static function same( $fid, $a, $b ) {
		if ( (int) $fid === (int) Config::FIELD_DOB ) {
			$ta = strtotime( (string) $a );
			$tb = strtotime( (string) $b );
			return $ta && $tb ? gmdate( 'Y-m-d', $ta ) === gmdate( 'Y-m-d', $tb ) : (string) $a === (string) $b;
		}
		return trim( (string) $a ) === trim( (string) $b );
	}

	/**
	 * Called by the app editor for a once-field before writing it.
	 *
	 * @return true|string True to write, or the error to show the member.
	 */
	public static function check( $uid, $fid, $new, array $confirmed ) {
		$old = self::stored( $uid, $fid );
		if ( '' === $old || self::same( $fid, $old, $new ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( self::used( $uid, $fid ) ) {
			return __( 'This has already been changed once and cannot be changed again.', 'cashaadi-ui' );
		}
		if ( ! in_array( (int) $fid, array_map( 'intval', $confirmed ), true ) ) {
			return __( 'Please confirm this change.', 'cashaadi-ui' );
		}
		return true;
	}

	/** Record the change and tidy up after it. Called after a successful write. */
	public static function consumed( $uid, $fid, $old, $new ) {
		if ( '' === $old || self::same( $fid, $old, $new ) || current_user_can( 'manage_options' ) ) {
			return;
		}
		update_user_meta( (int) $uid, self::meta( $fid ), array( 'at' => current_time( 'mysql' ), 'from' => $old, 'to' => $new ) );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'once_field_changed', (int) $uid, 0, array( 'field' => (int) $fid, 'from' => $old, 'to' => $new ) );
		}
		if ( (int) $fid === (int) Config::FIELD_GENDER ) {
			global $wpdb;
			$tray = $wpdb->prefix . 'csm_tray';
			if ( $tray === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tray ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tray} WHERE status = 'pending' AND ( viewer_id = %d OR profile_id = %d )", $uid, $uid ) );
			}
		}
	}
}
