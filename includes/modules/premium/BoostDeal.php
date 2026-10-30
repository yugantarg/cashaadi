<?php
/**
 * The ₹99 boost: a throwaway deal, run as an experiment (owner, 2026-10-30:
 * "Get 1 boost for ₹99 should be the throwaway deal" ... "to find out the
 * segment who is at all willing to pay").
 *
 * Who: members who have never paid (no paid Premium or Gold, no boost
 * bought), past their first two days (Boost::is_new), not admins or paused.
 * On their first eligible app page they get an arm, once and for good: 'show'
 * with probability csm_boost_deal_pct (0-100), else 'control'. 'show' sees a
 * popup once; the offer then runs for 48 hours. While it runs, the Boost card
 * on their profile offers the same ₹99 boost instead of the pack.
 *
 * The product is a hidden ₹99 "1 Profile Boost" (`wp csm boost-deal product`).
 * Only a member whose offer is running can add it to the cart, once. The boost
 * itself is added by Spotlight::on_paid, like the pack, so refunds work the
 * same way.
 *
 * `wp csm boost-deal` reads the result: who bought, by gender and "Created
 * for" (self or parent), and whether either arm went on to pay for anything
 * else afterwards.
 *
 * csm_boost_deal_pct = 0 (default) is off: no new arms, no popups, no offers.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoostDeal {

	const PRICE        = 99;
	const HOURS        = 48;
	const ARM_META     = 'csm_bd_arm';      // 'show' | 'control'
	const AT_META      = 'csm_bd_at';       // when the arm was assigned
	const SEEN_META    = 'csm_bd_seen';     // when the popup was shown
	const UNTIL_META   = 'csm_bd_until';    // when the offer ends
	const CLICKED_META = 'csm_bd_clicked';  // when "Get it" was tapped
	const BOUGHT_META  = 'csm_bd_bought';   // the order id, once bought
	const PCT_OPT      = 'csm_boost_deal_pct';
	const PRODUCT_OPT  = 'csm_boost_deal_product';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_add_to_cart' ), 5, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 31, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 31, 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm boost-deal', __NAMESPACE__ . '\\BoostDealCli' );
		}
	}

	public static function product_id() {
		return (int) get_option( self::PRODUCT_OPT, 0 );
	}

	/** Has this member ever paid for anything? */
	public static function has_paid( $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$plan = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id IN ( %d, %d ) AND initial_payment > 0",
			$uid, Config::PMPRO_PREMIUM_LEVEL, Config::PMPRO_GOLD_LEVEL
		) );
		if ( $plan || Membership::is_premium( $uid ) || Membership::is_gold( $uid ) ) {
			return true;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'boost_bought' AND actor_id = %d LIMIT 1",
			$uid
		) );
	}

	/** Is the ₹99 offer running for this member right now? */
	public static function live( $uid ) {
		$uid = (int) $uid;
		return $uid && self::product_id()
			&& 'show' === get_user_meta( $uid, self::ARM_META, true )
			&& (int) get_user_meta( $uid, self::UNTIL_META, true ) > time()
			&& ! get_user_meta( $uid, self::BOUGHT_META, true );
	}

	/** What the app shell hands the page: the popup's content, or false. */
	public static function payload( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) || ! self::product_id() ) {
			return false;
		}
		$pct = max( 0, min( 100, (int) get_option( self::PCT_OPT, 0 ) ) );
		if ( 0 === $pct ) {
			return false;
		}
		$arm = (string) get_user_meta( $uid, self::ARM_META, true );
		if ( 'control' === $arm || get_user_meta( $uid, self::SEEN_META, true ) ) {
			return false;
		}
		if ( '' === $arm ) {
			if ( ( class_exists( '\\CAShaadi\\Modules\\Discover\\Boost' ) && \CAShaadi\Modules\Discover\Boost::is_new( $uid ) )
				|| get_user_meta( $uid, 'csm_deactivated', true ) || self::has_paid( $uid ) ) {
				return false;
			}
			$arm = wp_rand( 1, 100 ) <= $pct ? 'show' : 'control';
			update_user_meta( $uid, self::ARM_META, $arm );
			update_user_meta( $uid, self::AT_META, time() );
			if ( 'control' === $arm ) {
				return false;
			}
		}
		return array(
			'title' => 'A profile boost for ₹' . self::PRICE,
			'text'  => "Your profile goes to the front of Discover for 24 hours.\n\nThis offer ends in " . self::HOURS . ' hours.',
			'ok'    => 'Get it for ₹' . self::PRICE,
			'url'   => self::buy_url(),
			'seen'  => rest_url( 'csm/v1/boost-deal' ),
		);
	}

	public static function buy_url() {
		return home_url( '/checkout/?add-to-cart=' . self::product_id() );
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/boost-deal', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_seen' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** The popup was shown (the 48 hours start now); ?clicked=1: "Get it" tapped. */
	public static function rest_seen( $request ) {
		$uid = get_current_user_id();
		if ( 'show' !== get_user_meta( $uid, self::ARM_META, true ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! get_user_meta( $uid, self::SEEN_META, true ) ) {
			update_user_meta( $uid, self::SEEN_META, time() );
			update_user_meta( $uid, self::UNTIL_META, time() + self::HOURS * HOUR_IN_SECONDS );
		}
		if ( $request->get_param( 'clicked' ) && ! get_user_meta( $uid, self::CLICKED_META, true ) ) {
			update_user_meta( $uid, self::CLICKED_META, time() );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Only a member whose offer is running can buy it; a clean cart of one. */
	public static function guard_add_to_cart( $passed, $product_id ) {
		if ( (int) $product_id !== self::product_id() || ! self::product_id() ) {
			return $passed;
		}
		if ( ! self::live( get_current_user_id() ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'This offer has ended.', 'error' );
			}
			return false;
		}
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		return $passed;
	}

	/** Record the purchase on the member (the boost itself: Spotlight::on_paid). */
	public static function on_paid( $order_id ) {
		$pid   = self::product_id();
		$order = $pid && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		foreach ( $order->get_items() as $item ) {
			if ( $uid && (int) $item->get_product_id() === $pid && ! get_user_meta( $uid, self::BOUGHT_META, true ) ) {
				update_user_meta( $uid, self::BOUGHT_META, (int) $order_id );
			}
		}
	}
}
