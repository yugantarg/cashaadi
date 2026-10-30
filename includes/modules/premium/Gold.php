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
 *   - Unlimited profiles in Discover: no weekly limit, in the grid view,
 *     a page at a time with "Show more". Gold badge for verified members.
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
	const UNLIMITED    = 9999;   // Gold: no weekly limit in Discover (fits a SMALLINT)
	const PAGE         = 24;     // ...loaded a page at a time ("Show more")

	/** Set by the Discover queue when a Gold member taps "Show more". */
	public static $more = false;
	const PREMIUM_PRICE_FOR_CREDIT = 1499;

	public static function register() {
		Migrator::register( 'phone_views', array( __CLASS__, 'schema' ) );
		// Late, after the women's and boost rules, so nothing adds to "unlimited".
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 99, 2 );
		add_filter( 'csm_tray_open_cap', array( __CLASS__, 'open_cap' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'upgrade_credit' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );
		// Buy Gold starts a clean cart, as Buy Premium does (v1.89.34).
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_add_to_cart' ), 10, 2 );
		// Refunds end the membership the order bought (v1.89.2).
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'remember_level' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refund' ), 20, 1 );
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
		// 1 since v1.89.47: Gold's reach comes from its weekly boost, not a permanent 3x (measured ~20x).
		return max( 1, (float) get_option( 'csm_gold_multiplier', 1 ) );
	}

	public static function ceiling_exempt() {
		// Off since v1.89.95 (owner, 2026-10-27): Gold is not shown more
		// without a boost, so it takes the weekly ceilings like everyone.
		return (bool) get_option( 'csm_gold_ceiling_exempt', 0 );
	}

	/** On for everyone from launch; before that, for the owner's test accounts. */
	public static function phone_live( $viewer = 0 ) {
		return (bool) get_option( 'csm_gold_phone_live', 0 ) || ( $viewer && self::is_tester( $viewer ) );
	}

	/** Whether this member's plan includes opening numbers (live or not yet). */
	public static function plan_has_phone( $uid ) {
		return Membership::is_gold( (int) $uid ) || ( Membership::is_premium( (int) $uid ) && self::is_female( $uid ) );
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

	/**
	 * Gold sees every member in Discover (owner, 2026-10-25: no separate
	 * screen, and the word is "unlimited"): no weekly limit.
	 */
	public static function tray_size( $size, $uid ) {
		return $uid && Membership::is_gold( $uid ) ? self::UNLIMITED : $size;
	}

	/**
	 * How many may sit undecided: one page, or one more page than they have
	 * when they ask for more. The member is told "unlimited" (owner,
	 * 2026-10-25); paging only keeps the screen quick.
	 */
	public static function open_cap( $cap, $uid ) {
		/*
		 * Paging applies to every grid member (Gold, and Premium women's 50 a
		 * week, v1.88.3): a page at a time, more on request. The weekly total
		 * is still the weekly size; this only decides how much loads at once.
		 */
		if ( ! $uid || (int) $cap <= self::PAGE ) {
			return $cap;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND status = 'pending'", (int) $uid ) );
		if ( self::$more ) {
			return min( (int) $cap, $pending + self::PAGE );
		}
		/*
		 * A page of what the member can SEE (v1.89.84). Profiles served before
		 * the filters were set stay pending but hidden, and counting them kept
		 * the deck "full" for good: nobody new was added, so a member who
		 * joined today and matched every filter never appeared (owner,
		 * 2026-10-27). Room is now a page less what the filters let through;
		 * with no filters that is the old rule exactly.
		 */
		$visible = class_exists( '\\CAShaadi\\Modules\\Discover\\Filters' ) && Membership::is_gold( (int) $uid )
			? \CAShaadi\Modules\Discover\Filters::pool_size( (int) $uid )
			: $pending;
		return min( (int) $cap, $pending + max( 0, self::PAGE - $visible ) );
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

	/** Women open numbers with Premium; men need Gold (owner, 2026-10-25). */
	public static function is_female( $uid ) {
		return function_exists( 'cashaadi' ) && 'Female' === trim( (string) cashaadi()->get_gender( (int) $uid ) );
	}

	/**
	 * May this viewer open numbers right now: at all, or (with $profile) this
	 * member's, by the member's own choice (SettingsScreen::phone_audience).
	 * A Premium woman counts as Gold here: numbers are her Premium feature.
	 */
	public static function can_reveal( $viewer, $profile = 0 ) {
		if ( ! self::phone_live( $viewer ) || ! Membership::is_premium( (int) $viewer ) ) {
			return false;
		}
		$full = Membership::is_gold( (int) $viewer ) || self::is_female( $viewer );
		if ( ! $profile ) {
			return $full;
		}
		$aud = class_exists( '\\CAShaadi\\Modules\\Settings\\SettingsScreen' )
			? \CAShaadi\Modules\Settings\SettingsScreen::phone_audience( (int) $profile )
			: 'gold';
		return 'paid' === $aud || ( $full && 'gold' === $aud );
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
		if ( ! $pid || $pid === $me || ! self::can_reveal( $me, $pid ) ) {
			return $no( 'This member has not shared their number with you.' );
		}
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $me, $pid ) ) {
			return $no( 'That member could not be found.' );
		}
		/*
		 * Only someone this member could match with (security audit,
		 * 2026-10-27): the id came from the request and any member was
		 * accepted: the same gender, paused, photo-held or closed accounts,
		 * administrators.
		 */
		$opp = function_exists( 'cashaadi' ) ? (string) cashaadi()->get_opposite_gender( $me ) : '';
		if ( '' === $opp || $opp !== trim( (string) cashaadi()->get_gender( $pid ) )
			|| user_can( $pid, 'manage_options' )
			|| ( class_exists( '\\CAShaadi\\Modules\\Settings\\Deactivate' ) && \CAShaadi\Modules\Settings\Deactivate::is_paused( $pid ) )
			|| ( class_exists( '\\CAShaadi\\Modules\\Settings\\Closed' ) && \CAShaadi\Modules\Settings\Closed::is_closed( $pid ) )
			|| ( class_exists( '\\CAShaadi\\Modules\\Photos\\PhotoHold' ) && \CAShaadi\Modules\Photos\PhotoHold::is_held( $pid ) ) ) {
			return $no( 'That member could not be found.' );
		}
		$p = \CAShaadi\Core\Profile::phone( $pid, $me );
		if ( 'not_shared' === $p['state'] || '' === $p['state'] ) {
			return $no( 'This member does not share their number.' );
		}
		if ( ! self::revealed( $me, $pid ) ) {
			global $wpdb;
			// One reveal at a time per member, so parallel requests cannot pass the daily cap.
			$lock = 'csm_reveal_' . (int) $me;
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', $lock ) ) ) {
				return $no( 'Please try again.' );
			}
			if ( self::views_today( $me ) >= self::DAILY_PHONES ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
				return $no( sprintf( 'You can view up to %d new numbers a day. More tomorrow.', self::DAILY_PHONES ) );
			}
			$wpdb->insert( $wpdb->prefix . 'csm_phone_views', array( 'viewer_id' => $me, 'profile_id' => $pid, 'viewed_at' => current_time( 'mysql' ) ) );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			$result = self::send_request( $me, $pid );
			if ( function_exists( 'cashaadi' ) ) {
				cashaadi()->log_event( 'gold_phone_view', $me, $pid, array() );
			}
		}
		/*
		 * The request is decided now, so the card in Discover / Saved is too:
		 * mark the viewer's tray row so its Pass / Save / Send request buttons
		 * give way (owner, 2026-10-26). 'result' tells the screen what happened.
		 */
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}csm_tray SET status = 'liked', acted_at = %s WHERE viewer_id = %d AND profile_id = %d AND status IN ( 'pending', 'saved' )",
			current_time( 'mysql' ), $me, $pid
		) );
		$status = function_exists( 'friends_check_friendship_status' ) ? friends_check_friendship_status( $me, $pid ) : '';
		return new \WP_REST_Response( array(
			'ok'     => true,
			'phone'  => \CAShaadi\Core\Profile::phone( $pid, $me ),
			'result' => 'is_friend' === $status ? 'matched' : ( 'pending' === $status ? 'requested' : '' ),
		), 200 );
	}

	/** A match request from $me to $pid, unless they are already connected either way. */
	private static function send_request( $me, $pid ) {
		if ( ! function_exists( 'friends_check_friendship_status' ) ) {
			return;
		}
		$status = friends_check_friendship_status( $me, $pid );
		/*
		 * They already asked: viewing their number accepts their request, so
		 * the two become a match and they are told (owner, 2026-10-26).
		 */
		if ( 'awaiting_response' === $status && function_exists( 'friends_get_friendship_id' ) && function_exists( 'friends_accept_friendship' ) ) {
			$fid = friends_get_friendship_id( $pid, $me );
			if ( $fid && friends_accept_friendship( $fid ) ) {
				$saved = array_values( array_diff( array_map( 'intval', (array) get_user_meta( $me, 'csm_req_saved', true ) ), array( (int) $pid ) ) );
				update_user_meta( $me, 'csm_req_saved', $saved );
				if ( function_exists( 'cashaadi' ) ) {
					cashaadi()->log_event( 'phone_view_accept', $me, $pid, array() );
				}
			}
			return;
		}
		if ( 'not_friends' !== $status ) {
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
		// What they paid (₹1,499 before 1 Nov 2026, ₹4,499 after); the old price if unknown.
		$paid = isset( $lvl->initial_payment ) && (float) $lvl->initial_payment > 0 ? (float) $lvl->initial_payment : self::PREMIUM_PRICE_FOR_CREDIT;
		return (int) floor( $paid * min( 365, $days ) / 365 );
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

	/**
	 * The owner's own test accounts, before launch (owner, 2026-10-25: "allow
	 * buying gold for yugantargupta aliases"): yugantargupta@gmail.com and any
	 * +alias of it. More patterns can go in option csm_gold_tester_regex.
	 */
	public static function is_tester( $uid = 0 ) {
		$u = get_userdata( $uid ? (int) $uid : get_current_user_id() );
		if ( ! $u ) {
			return false;
		}
		$re = (string) get_option( 'csm_gold_tester_regex', '/^yugantargupta(\+[^@]*)?@gmail\.com$/i' );
		return (bool) @preg_match( $re, (string) $u->user_email ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/* ----------------------------------------------------------- refunds */

	/** At checkout: what membership the buyer had before, for an undo. */
	public static function remember_level( $order ) {
		$uid = (int) $order->get_customer_id();
		if ( ! $uid || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			return;
		}
		$lvl = pmpro_getMembershipLevelForUser( $uid );
		if ( $lvl ) {
			$order->update_meta_data( '_csm_prev_level', array( 'id' => (int) $lvl->id, 'end' => (int) $lvl->enddate ) );
		}
	}

	/**
	 * A FULLY refunded order (WooCommerce marks it "Refunded") ends what it
	 * bought (owner, 2026-10-25: "refunding should remove the membership").
	 * A Gold upgrade gives back the Premium the member had, until its old end
	 * date. A partial refund changes nothing: the order stays Completed.
	 * Only acts when the member's current level is the one this order bought.
	 */
	public static function on_refund( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		if ( ! $uid ) {
			return;
		}
		$bought = 0;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( (int) Config::WC_GOLD_PRODUCT === $pid ) {
				$bought = (int) Config::PMPRO_GOLD_LEVEL;
			} elseif ( (int) Config::WC_PREMIUM_PRODUCT === $pid && ! $bought ) {
				$bought = (int) Config::PMPRO_PREMIUM_LEVEL;
			}
		}
		if ( ! $bought || ! pmpro_hasMembershipLevel( $bought, $uid ) ) {
			return; // nothing of ours, or already changed since
		}
		$prev = $order->get_meta( '_csm_prev_level' );
		if ( (int) Config::PMPRO_GOLD_LEVEL === $bought && is_array( $prev )
			&& (int) Config::PMPRO_PREMIUM_LEVEL === (int) $prev['id'] && (int) $prev['end'] > time() ) {
			pmpro_changeMembershipLevel( array(
				'user_id'       => $uid,
				'membership_id' => (int) Config::PMPRO_PREMIUM_LEVEL,
				'startdate'     => current_time( 'mysql' ),
				'enddate'       => wp_date( 'Y-m-d H:i:s', (int) $prev['end'] ),
			), $uid );
			$what = 'gold_refunded_premium_restored';
		} else {
			pmpro_changeMembershipLevel( 0, $uid );
			$what = 'membership_refunded';
		}
		$order->add_order_note( 'CA Shaadi: full refund, membership ' . ( 'membership_refunded' === $what ? 'ended.' : 'changed from Gold back to Premium.' ) );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( $what, 0, $uid, array( 'order' => (int) $order_id ) );
		}
	}

	/**
	 * A Premium member's old, unpaid Premium item stayed in their saved cart,
	 * so Buy Gold opened a checkout showing Premium (owner, 2026-10-25).
	 * Empty the cart first; a Gold member cannot buy Gold again.
	 */
	public static function guard_add_to_cart( $passed, $product_id ) {
		if ( (int) $product_id !== (int) Config::WC_GOLD_PRODUCT || ! function_exists( 'WC' ) || ! ( WC()->cart instanceof \WC_Cart ) ) {
			return $passed;
		}
		if ( Membership::is_gold( get_current_user_id() ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'You are already a Gold member.', 'notice' );
			}
			return false;
		}
		WC()->cart->empty_cart();
		return $passed;
	}

	/** Founding members: Gold stops selling at the cap (0 = no cap). */
	public static function purchasable( $ok, $product ) {
		if ( ! $ok || ! $product || (int) $product->get_id() !== (int) Config::WC_GOLD_PRODUCT ) {
			return $ok;
		}
		// Not for sale until launch (csm_gold_on_sale), except to an admin or a
		// tester account testing.
		if ( ! get_option( 'csm_gold_on_sale' ) && ! current_user_can( 'manage_options' ) && ! self::is_tester() ) {
			return false;
		}
		$cap = (int) get_option( 'csm_gold_cap', 25 );
		return $cap <= 0 || self::count() < $cap;
	}
}
