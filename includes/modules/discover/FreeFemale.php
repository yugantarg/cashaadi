<?php
/**
 * Free women see 15 profiles a week (owner, 2026-10-03).
 *
 * "I want to give free female users 15 profiles a week. This should implement
 * from Monday onwards. We will send a popup on next login that they will get
 * 15 profiles from now (for earlier users). For new female users 15 is the
 * standard."
 *
 * - From START (IST date, Monday 2026-10-05; option csm_free_female_from),
 *   a free member whose profile Gender is Female gets QUOTA a week instead
 *   of 5, through the same csm_tray_size filter the engine, the Discover
 *   banner and Discover::quota_for() all read — so they cannot disagree.
 *   Premium women keep their 50 (Filters, priority 10; this runs at 20 and
 *   leaves premium alone).
 * - Women who registered before START get a one-time popup on their first
 *   app page from START on. Shown once, marked seen when shown.
 * - PricingCopy reads quota_for_women() for the free column.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FreeFemale {

	const QUOTA     = 15;
	const START     = '2026-10-05';
	const SEEN_META = 'csm_ff15_seen';

	public static function register() {
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 20, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	public static function start() {
		$d = (string) get_option( 'csm_free_female_from', self::START );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : self::START;
	}

	/** Has the 15-a-week rule started (IST)? */
	public static function live() {
		return current_time( 'Y-m-d' ) >= self::start();
	}

	public static function quota() {
		return max( 1, (int) get_option( 'csm_free_female_quota', self::QUOTA ) );
	}

	/** What a free woman gets right now: 15 once live, else the old 5. */
	public static function quota_for_women() {
		return self::live() ? self::quota() : 5;
	}

	private static function is_female( $uid ) {
		return function_exists( 'cashaadi' ) && 'Female' === trim( (string) cashaadi()->get_gender( (int) $uid ) );
	}

	public static function tray_size( $size, $uid ) {
		if ( ! self::live() || ! $uid || Membership::is_premium( $uid ) || ! self::is_female( $uid ) ) {
			return $size;
		}
		return max( (int) $size, self::quota() );
	}

	/** One-time popup for women who joined before START: a payload, or false. */
	public static function payload( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! self::live() || user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		if ( get_user_meta( $uid, self::SEEN_META, true ) || Membership::is_premium( $uid ) || ! self::is_female( $uid ) ) {
			return false;
		}
		$u     = get_userdata( $uid );
		$start = get_gmt_from_date( self::start() . ' 00:00:00' );
		if ( ! $u || $u->user_registered >= $start ) {
			return false; // joined after the change: 15 was always their number
		}
		return array(
			'quota' => self::quota(),
			'url'   => home_url( '/discover/' ),
			'seen'  => rest_url( 'csm/v1/ff15-seen' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/ff15-seen', array(
			'methods'             => 'POST',
			'callback'            => function () {
				update_user_meta( get_current_user_id(), self::SEEN_META, time() );
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
	}
}
