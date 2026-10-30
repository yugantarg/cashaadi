<?php
/**
 * "Introducing Gold": a one-time popup, run as an experiment (owner,
 * 2026-10-29: "a pop-up in the app introducing Gold ... don't have it
 * conflict with other pop-ups ... everything we do from now on will be
 * experiments").
 *
 * The same design as VerifyNudge. On a member's first eligible app page they
 * get an arm, once and for good: 'show' with probability csm_gold_intro_pct
 * (0-100), else 'control'; both are stamped with that moment. 'show' sees the
 * popup once (marked seen when it appears); a tap on "See Gold" is recorded
 * separately. `wp csm gold-intro` compares Gold purchases after the stamp,
 * shown against not shown.
 *
 * Eligible: not an admin, not Gold, Gold on sale and places left, joined more
 * than two days ago (Boost::is_new: the first days belong to setting up).
 * One popup per page: AppPage only offers this when no other popup is due.
 *
 * What it says is only what Gold gives (Gold.php): unlimited profiles,
 * requests first on their list, phone numbers members share (left out when
 * the member's plan already has them), the founding places left, the price.
 * Never the ranking boost (owner: boost mechanics stay private).
 *
 * csm_gold_intro_pct = 0 (default) is off: no new arms, no popups. Arms
 * already recorded are kept, so the result can still be read.
 *
 * csm_gold_intro_max (default 200; owner: "a small group of say 200") caps
 * the 'show' arm. Once that many have it, nobody new gets an arm, so with a
 * 50% share the control group ends near the same size. 0 = no cap.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoldIntro {

	const ARM_META     = 'csm_gi_arm';      // 'show' | 'control'
	const AT_META      = 'csm_gi_at';       // when the arm was assigned
	const SEEN_META    = 'csm_gi_seen';     // when the popup was shown
	const CLICKED_META = 'csm_gi_clicked';  // when "See Gold" was tapped
	const PCT_OPT      = 'csm_gold_intro_pct';
	const MAX_OPT      = 'csm_gold_intro_max';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm gold-intro', array( __CLASS__, 'cli' ) );
		}
	}

	private static function places_left() {
		$cap = (int) get_option( 'csm_gold_cap', 25 );
		return $cap > 0 ? max( 0, $cap - (int) Gold::count() ) : -1; // -1: no cap
	}

	/** True once the 'show' arm has reached csm_gold_intro_max. */
	private static function group_full() {
		$max = (int) get_option( self::MAX_OPT, 200 );
		if ( $max <= 0 ) {
			return false;
		}
		if ( get_transient( 'csm_gi_full' ) ) {
			return true;
		}
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = 'show'", self::ARM_META ) );
		if ( $n >= $max ) {
			set_transient( 'csm_gi_full', 1, HOUR_IN_SECONDS );
			return true;
		}
		return false;
	}

	/** What the app shell hands the page: the popup's content, or false. */
	public static function payload( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) || Membership::is_gold( $uid ) ) {
			return false;
		}
		$pct = max( 0, min( 100, (int) get_option( self::PCT_OPT, 0 ) ) );
		if ( 0 === $pct || ! get_option( 'csm_gold_on_sale' ) || 0 === self::places_left() ) {
			return false;
		}
		if ( class_exists( '\\CAShaadi\\Modules\\Discover\\Boost' ) && \CAShaadi\Modules\Discover\Boost::is_new( $uid ) ) {
			return false;
		}
		$arm = (string) get_user_meta( $uid, self::ARM_META, true );
		if ( 'control' === $arm || get_user_meta( $uid, self::SEEN_META, true ) ) {
			return false;
		}
		if ( '' === $arm ) {
			if ( self::group_full() ) {
				return false;
			}
			$arm = wp_rand( 1, 100 ) <= $pct ? 'show' : 'control';
			update_user_meta( $uid, self::ARM_META, $arm );
			update_user_meta( $uid, self::AT_META, time() );
			if ( 'control' === $arm ) {
				return false;
			}
		}

		$lines = array( '• Unlimited profiles every week' );
		if ( ! Gold::plan_has_phone( $uid ) ) {
			$lines[] = '• See the phone numbers members share';
		}
		$lines[] = '• Your requests appear first on their list';
		$left = self::places_left();
		$tail = $left > 0 ? sprintf( "\n\nOnly %d founding Gold places left. ₹7,999 for a year.", $left ) : "\n\n₹7,999 for a year.";
		if ( Membership::is_premium( $uid ) ) {
			$tail .= ' Your unused Premium days are taken off the price.';
		}
		return array(
			'title' => 'Introducing CA Shaadi Gold',
			'text'  => implode( "\n", $lines ) . $tail,
			'url'   => add_query_arg( 'src', 'gold-intro', home_url( '/membership-pricing/' ) ),
			'seen'  => rest_url( 'csm/v1/gold-intro' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/gold-intro', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_seen' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_seen( $request ) {
		$uid = get_current_user_id();
		if ( 'show' !== get_user_meta( $uid, self::ARM_META, true ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! get_user_meta( $uid, self::SEEN_META, true ) ) {
			update_user_meta( $uid, self::SEEN_META, time() );
		}
		if ( $request->get_param( 'clicked' ) && ! get_user_meta( $uid, self::CLICKED_META, true ) ) {
			update_user_meta( $uid, self::CLICKED_META, time() );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Result of the Gold popup test: Gold bought after the arm was assigned,
	 * shown against not shown.
	 *
	 * ## EXAMPLES
	 *
	 *     wp csm gold-intro
	 */
	public static function cli() {
		global $wpdb;
		$t    = $wpdb->pmpro_memberships_users;
		$gold = (int) Config::PMPRO_GOLD_LEVEL;
		\WP_CLI::log( 'Popup share: ' . (int) get_option( self::PCT_OPT, 0 ) . '% (' . self::PCT_OPT . '), max shown ' . (int) get_option( self::MAX_OPT, 200 ) . ' (' . self::MAX_OPT . ')' );
		foreach ( array( 'show', 'control' ) as $arm ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", self::ARM_META, $arm ) ) );
			$n = count( $ids );
			$seen = $clicked = $bought = 0;
			foreach ( $ids as $uid ) {
				$at = (int) get_user_meta( $uid, self::AT_META, true );
				$seen    += get_user_meta( $uid, self::SEEN_META, true ) ? 1 : 0;
				$clicked += get_user_meta( $uid, self::CLICKED_META, true ) ? 1 : 0;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bought  += $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE user_id = %d AND membership_id = %d AND startdate >= %s LIMIT 1", $uid, $gold, gmdate( 'Y-m-d H:i:s', $at ) ) ) ? 1 : 0;
			}
			\WP_CLI::log( sprintf( '  %-8s %5d members · %5d saw it · %4d tapped "See Gold" · %3d bought Gold (%s)', $arm, $n, $seen, $clicked, $bought, $n ? round( 100 * $bought / $n, 2 ) . '%' : '-' ) );
		}
	}
}
