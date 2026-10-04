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

	const POPUP_SEEN = 'csm_boost_popup_seen';

	public static function register() {
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 30, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/**
	 * One-time popup with the same offer (owner, 2026-10-04: "build the
	 * combined popup"). It replaces the verify-only popup, whose test showed a
	 * one-time prompt more than doubled ICAI uploads (6.3% to 14.4%). Shown
	 * once to a member with something left to earn; a payload, or false.
	 */
	public static function popup( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! self::per() || user_can( $uid, 'manage_options' ) || get_user_meta( $uid, self::POPUP_SEEN, true ) ) {
			return false;
		}
		$photo  = self::has_photo( $uid );
		$verify = self::verify_state( $uid );
		$need_v = self::can_verify( $uid ) && ! in_array( $verify, array( 'approved', 'pending' ), true ); // pending: nothing to do yet
		if ( $photo && ! $need_v ) {
			return false;
		}
		$st    = self::state( $uid );
		$parts = array();
		if ( ! $photo ) {
			$parts[] = sprintf( 'add a photo (+%d)', self::per() );
		}
		if ( $need_v ) {
			$parts[] = sprintf(
				'inter' === \CAShaadi\Modules\CaVerify\CaVerify::claim( $uid )
					? 'verify your CA Inter status by uploading your %s (+%d)'
					: 'verify your CA by uploading your %s (+%d)',
				\CAShaadi\Modules\CaVerify\CaVerify::doc_label( $uid ),
				self::per()
			);
		}
		$n = self::per() * count( $parts );
		return array(
			'title' => sprintf( 'Get %d more profiles this week', $n ),
			'body'  => ucfirst( implode( ' and ', $parts ) ) . ( self::can_verify( $uid ) ? '. Verifying and adding a photo increase your chances 5 times.' : '. Adding a photo increases your chances 5 times.' ),
			'url'   => $photo ? $st['verifyUrl'] : $st['photoUrl'],
			'seen'  => rest_url( 'csm/v1/boost-seen' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/boost-seen', array(
			'methods'             => 'POST',
			'callback'            => function () {
				update_user_meta( get_current_user_id(), self::POPUP_SEEN, time() );
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
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

	/** Is verification offered to this member at all? CA and CA Inter only. */
	public static function can_verify( $uid ) {
		return class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) && '' !== \CAShaadi\Modules\CaVerify\CaVerify::claim( (int) $uid );
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
			// CA / CA Inter only; CA Inter members are asked for their CA Inter ID.
			'canVerify' => self::can_verify( $uid ),
			'claim'     => class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) ? \CAShaadi\Modules\CaVerify\CaVerify::claim( $uid ) : '',
			'docLabel'  => class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) ? \CAShaadi\Modules\CaVerify\CaVerify::doc_label( $uid ) : '',
			'photoUrl'  => function_exists( 'bp_members_get_user_url' ) ? trailingslashit( bp_members_get_user_url( $uid ) ) . 'profile/change-avatar/' : home_url( '/profile/' ),
			'verifyUrl' => home_url( '/profile/edit/?g=10' ),
		);
	}
}
