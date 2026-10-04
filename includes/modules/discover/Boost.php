<?php
/**
 * More profiles for a photo and a verified CA badge (owner, 2026-10-04).
 *
 * "Get 5 more profiles this week by 1. verifying CA or 2. adding photo (10 if
 * both). Tell them that verifying and adding photo increases your chances by
 * 5 times (we want to add profile completeness in our algo)."
 *
 * - +PER profiles a week for having at least one photo, +PER for an approved
 *   ICAI verification. Added through csm_tray_size (priority 30, after the
 *   premium-women and free-women rules), so the engine, the Discover banner
 *   and quota_for() all agree. It counts from the moment it is earned: the
 *   next Discover load refills the extra slots in the current week.
 * - The other half — being SHOWN more — is in Discover\Ranker ('photo' and
 *   'verified' points), so the promise on the card is the algorithm's too.
 */

namespace CAShaadi\Modules\Discover;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Boost {

	const PER = 5;

	public static function register() {
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 30, 2 );
	}

	public static function per() {
		return max( 0, (int) get_option( 'csm_boost_per', self::PER ) );
	}

	public static function has_photo( $uid ) {
		$p = get_user_meta( (int) $uid, 'csm_photos', true );
		return is_array( $p ) && count( $p ) > 0;
	}

	/** '' none, 'pending' uploaded/in review, 'approved', 'rejected'. */
	public static function verify_state( $uid ) {
		$s = (string) get_user_meta( (int) $uid, 'csm_av_status', true );
		if ( 'approved' === $s || 'rejected' === $s ) {
			return $s;
		}
		if ( 'review' === $s ) {
			return 'pending';
		}
		if ( class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) && \CAShaadi\Modules\CaVerify\CaVerify::doc( (int) $uid ) ) {
			return 'pending';
		}
		return '';
	}

	public static function extra( $uid ) {
		$uid = (int) $uid;
		return self::per() * ( ( self::has_photo( $uid ) ? 1 : 0 ) + ( 'approved' === (string) get_user_meta( $uid, 'csm_av_status', true ) ? 1 : 0 ) );
	}

	public static function tray_size( $size, $uid ) {
		return $uid ? (int) $size + self::extra( $uid ) : $size;
	}

	/** What the Discover card needs. */
	public static function state( $uid ) {
		$uid = (int) $uid;
		return array(
			'per'       => self::per(),
			'photo'     => self::has_photo( $uid ),
			'verify'    => self::verify_state( $uid ),
			'photoUrl'  => function_exists( 'bp_members_get_user_url' ) ? trailingslashit( bp_members_get_user_url( $uid ) ) . 'profile/change-avatar/' : home_url( '/profile/' ),
			'verifyUrl' => home_url( '/profile/edit/?g=10' ),
		);
	}
}
