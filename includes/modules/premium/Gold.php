<?php
/**
 * Gold membership (owner, 2026-10-24/25). ₹7,999 for a year, PMPro level 3,
 * WooCommerce product 17394. Everything in Premium (Membership::is_premium
 * is true for Gold), plus:
 *
 *   - 3x ranking points and no weekly ceilings in Discover (Ranker). Only
 *     the 30-days-inactive rule still applies. Both are options, so the
 *     ceilings can be switched back on for Gold without a code change.
 *   - Every request is a priority request: first on the receiver's list,
 *     highlighted (RequestsScreen).
 *   - Phone numbers of every member except "Only me". Viewing one sends a
 *     match request (the member is told first), up to DAILY_PHONES a day,
 *     every view logged. Off until launch day (csm_gold_phone_live).
 *   - 20 profiles a week for men (women already get 50), and Gold badge for
 *     verified members.
 *   - Upgrade credit: a Premium member's unused days are taken off the price.
 *   - Founding cap: the product stops selling at csm_gold_cap members.
 *
 * Options: csm_gold_multiplier (3), csm_gold_ceiling_exempt (1),
 * csm_gold_phone_live (0), csm_gold_cap (25; 0 = none).
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gold {

	const DAILY_PHONES = 20;
	const MEN_WEEKLY   = 20;
	const PREMIUM_PRICE_FOR_CREDIT = 1499;

	public static function register() {
		Migrator::register( 'phone_views', array( __CLASS__, 'schema' ) );
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 15, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'upgrade_credit' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );
	}

	public static function schema( $wpdb ) {
		$t = $wpdb->prefix . 'csm_phone_views';
		return "CREATE TABLE {$t} (
 viewer_id BIGINT UNSIGNED NOT NULL,
 profile_id BIGINT UNSIGNED NOT NULL,
 viewed_at DATETIME NOT NULL,
 PRIMARY KEY  (viewer_id, profile_id),
 KEY viewer_time (viewer_id, viewed_at)
) " . $wpdb->get_charset_collate() . ';';
	}

	/* ------------------------------------------------------------ policy */

	public static function multiplier() {
		return max( 1, (float) get_option( 'csm_gold_multiplier', 3 ) );
	}

	public static function ceiling_exempt() {
		return (bool) get_option( 'csm_gold_ceiling_exempt', 1 );
	}

	public static function phone_live() {
		return (bool) get_option( 'csm_gold_phone_live', 0 );
	}

	/** Which of these members are Gold, in one query. */
	public static function ids_among( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t = $wpdb->prefix . 'pmpro_memberships_users';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$t} WHERE membership_id = %d AND status = 'active' AND user_id IN (" . implode( ',', $ids ) . ')',
			Config::PMPRO_GOLD_LEVEL
		) ) );
	}

	public static function count() {
		global $wpdb;
		$t = $wpdb->prefix . 'pmpro_memberships_users';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$t} WHERE membership_id = %d AND status = 'active'", Config::PMPRO_GOLD_LEVEL ) );
	}

	/** Gold men: 20 a week. Gold women keep the Premium-women 50. */
	public static function tray_size( $size, $uid ) {
		if ( $uid && Membership::is_gold( $uid ) && function_exists( 'cashaadi' ) && 'Female' !== trim( (string) cashaadi()->get_gender( $uid ) ) ) {
			return max( (int) $size, self::MEN_WEEKLY );
		}
		return $size;
	}

	/** Gold badge: Gold AND verified. */
	public static function badge( $uid ) {
		return Membership::is_gold( (int) $uid )
			&& method_exists( '\CAShaadi\Core\Verification', 'ca_verified' )
			&& \CAShaadi\Core\Verification::ca_verified( (int) $uid );
	}

	/** One line for the Sales Dashboard. */
	public static function dashboard_line() {
		$cap = (int) get_option( 'csm_gold_cap', 25 );
		return '<p style="margin:10px 0;padding:8px 12px;background:#fffaf0;border:1px solid #f0d58c;border-radius:6px"><strong>Gold members: ' . (int) self::count() . '</strong>'
			. ( $cap > 0 ? ' of ' . $cap . ' founding places' : '' )
			. ' · on sale: ' . ( get_option( 'csm_gold_on_sale' ) ? 'yes' : 'no' )
			. ' · phone access: ' . ( self::phone_live() ? 'on' : 'off' )
			. ' · ceilings ' . ( self::ceiling_exempt() ? 'off for Gold' : 'on for Gold' ) . '</p>';
	}

	/* ------------------------------------------------------------- phone */

	public static function revealed( $viewer, $profile ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}csm_phone_views WHERE viewer_id = %d AND profile_id = %d", (int) $viewer, (int) $profile ) );
	}

	public static function views_today( $viewer ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_phone_views WHERE viewer_id = %d AND viewed_at >= %s", (int) $viewer, current_time( 'Y-m-d' ) . ' 00:00:00' ) );
	}

	/** May this viewer reveal numbers at all right now? */
	public static function can_reveal( $viewer ) {
		return self::phone_live() && Membership::is_gold( (int) $viewer );
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/phone/reveal', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_reveal' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/**
	 * A Gold member opens a number. Logged, capped per day, and it sends the
	 * other member a match request so they know who has their number.
	 */
	public static function rest_reveal( $request ) {
		$me  = get_current_user_id();
		$pid = (int) $request->get_param( 'id' );
		$no  = function ( $msg ) { return new \WP_REST_Response( array( 'ok' => false, 'message' => $msg ), 200 ); };
		if ( ! $pid || $pid === $me || ! self::can_reveal( $me ) ) {
			return $no( 'Phone numbers are a Gold feature.' );
		}
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $me, $pid ) ) {
			return $no( 'That member could not be found.' );
		}
		$p = \CAShaadi\Core\Profile::phone( $pid, $me );
		if ( 'not_shared' === $p['state'] || '' === $p['state'] ) {
			return $no( 'This member does not share their number.' );
		}
		if ( ! self::revealed( $me, $pid ) ) {
			if ( self::views_today( $me ) >= self::DAILY_PHONES ) {
				return $no( sprintf( 'You can view up to %d new numbers a day. More tomorrow.', self::DAILY_PHONES ) );
			}
			global $wpdb;
			$wpdb->insert( $wpdb->prefix . 'csm_phone_views', array( 'viewer_id' => $me, 'profile_id' => $pid, 'viewed_at' => current_time( 'mysql' ) ) );
			self::send_request( $me, $pid );
			if ( function_exists( 'cashaadi' ) ) {
				cashaadi()->log_event( 'gold_phone_view', $me, $pid, array() );
			}
		}
		return new \WP_REST_Response( array( 'ok' => true, 'phone' => \CAShaadi\Core\Profile::phone( $pid, $me ) ), 200 );
	}

	/** A match request from $me to $pid, unless they are already connected either way. */
	private static function send_request( $me, $pid ) {
		if ( ! function_exists( 'friends_check_friendship_status' ) || 'not_friends' !== friends_check_friendship_status( $me, $pid ) ) {
			return;
		}
		if ( function_exists( 'csm_log_event' ) ) {
			csm_log_event( 'like', $me, $pid ); // creates the BuddyPress request, as a like does
		} elseif ( function_exists( 'friends_add_friend' ) ) {
			friends_add_friend( $me, $pid );
		}
		if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
			\CAShaadi\Modules\Discover\Seen::record_action( $me, $pid, 'liked' );
		}
		do_action( 'csm_profile_liked', $me, $pid );
	}

	/* ---------------------------------------------------------- checkout */

	/** Unused Premium days, credited when upgrading to Gold. */
	public static function credit_for( $uid ) {
		if ( ! $uid || Membership::is_gold( $uid ) || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			return 0;
		}
		$lvl = pmpro_getMembershipLevelForUser( $uid );
		if ( ! $lvl || (int) $lvl->id !== (int) Config::PMPRO_PREMIUM_LEVEL || empty( $lvl->enddate ) ) {
			return 0;
		}
		$days = max( 0, ( (int) $lvl->enddate - time() ) / DAY_IN_SECONDS );
		return (int) floor( self::PREMIUM_PRICE_FOR_CREDIT * min( 365, $days ) / 365 );
	}

	public static function upgrade_credit( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$has_gold = false;
		foreach ( $cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === (int) Config::WC_GOLD_PRODUCT ) {
				$has_gold = true;
			}
		}
		$credit = $has_gold ? self::credit_for( get_current_user_id() ) : 0;
		if ( $credit > 0 ) {
			$cart->add_fee( 'Credit for your unused Premium days', -1 * $credit, false );
		}
	}

	/** Founding members: Gold stops selling at the cap (0 = no cap). */
	public static function purchasable( $ok, $product ) {
		if ( ! $ok || ! $product || (int) $product->get_id() !== (int) Config::WC_GOLD_PRODUCT ) {
			return $ok;
		}
		// Not for sale until launch (csm_gold_on_sale), except to an admin testing.
		if ( ! get_option( 'csm_gold_on_sale' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$cap = (int) get_option( 'csm_gold_cap', 25 );
		return $cap <= 0 || self::count() < $cap;
	}
}
