<?php
/**
 * Profile boosts (owner, 2026-10-26). Called "Spotlight" in code because
 * Discover\Boost is already the +5-profiles reward; members see "boost".
 *
 * A boost lasts 24 hours. While it runs, the boosted member is slotted in at
 * the front of Discover for members of the other gender who open it, as a
 * bonus card that does not use up their week. Reach is capped so later boosts
 * still find new people: a man's boost reaches up to 50 women, a woman's up to
 * 150 men (options csm_spot_cap_male / csm_spot_cap_female). Never someone who
 * has already been shown them; at most one boosted card per viewer a day.
 *
 * Boosts available: Premium 1 a month, Gold 1 a week (neither carries over),
 * plus bought ones, which never expire: the "Profile boosts" product, 3 for
 * ₹799, created by `wp csm spotlight product`.
 *
 * Top of the requests list for Gold senders is separate and unchanged.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Spotlight {

	const HOURS      = 24;
	const PACK       = 3;
	const PRICE      = 799;
	const UNTIL      = 'csm_spot_until';      // when the running boost ends (timestamp)
	const STARTED    = 'csm_spot_started';    // when it started
	const REACHED    = 'csm_spot_reached';    // members reached by the running boost
	const CREDITS    = 'csm_spot_credits';    // bought, unused
	const USED_MONTH = 'csm_spot_used_month'; // Y-m of the Premium allowance used
	const USED_WEEK  = 'csm_spot_used_week';  // week id of the Gold allowance used
	const SEEN_DAY   = 'csm_spot_seen_day';   // viewer: the day they last got a boosted card
	const PRODUCT    = 'csm_spot_product';    // option: the pack's product id
	const WEEK_ROW   = 'boost';               // tray week_assigned for a boosted card
	const START_BAR  = 0.6;  // share of the full match points a viewer needs at the start…
	const WIDEN_HOURS = 18;  // …falling evenly to none after this many hours

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 30, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 30, 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm spotlight', __NAMESPACE__ . '\\SpotlightCli' );
		}
	}

	/* ------------------------------------------------------------ credits */

	private static function week() {
		return function_exists( 'cashaadi' ) ? (string) cashaadi()->get_week_id() : wp_date( 'o-\WW' );
	}

	/** The plan's boost, if not yet used this period: 'gold' | 'premium' | ''. */
	private static function allowance( $uid ) {
		if ( Membership::is_gold( $uid ) ) {
			return get_user_meta( $uid, self::USED_WEEK, true ) === self::week() ? '' : 'gold';
		}
		if ( Membership::is_premium( $uid ) ) {
			return get_user_meta( $uid, self::USED_MONTH, true ) === wp_date( 'Y-m' ) ? '' : 'premium';
		}
		return '';
	}

	public static function credits( $uid ) {
		return (int) get_user_meta( (int) $uid, self::CREDITS, true );
	}

	public static function available( $uid ) {
		return ( '' !== self::allowance( $uid ) ? 1 : 0 ) + self::credits( $uid );
	}

	public static function active_until( $uid ) {
		$t = (int) get_user_meta( (int) $uid, self::UNTIL, true );
		return $t > time() ? $t : 0;
	}

	public static function cap_for( $uid ) {
		$female = Gold::is_female( $uid );
		return (int) get_option( $female ? 'csm_spot_cap_female' : 'csm_spot_cap_male', $female ? 150 : 50 );
	}

	/** Start a boost now, using the plan's allowance first, then a bought one. */
	public static function start( $uid ) {
		$uid = (int) $uid;
		if ( self::active_until( $uid ) ) {
			return new \WP_Error( 'csm_spot_running', 'Your boost is already running.' );
		}
		$plan = self::allowance( $uid );
		if ( 'gold' === $plan ) {
			update_user_meta( $uid, self::USED_WEEK, self::week() );
		} elseif ( 'premium' === $plan ) {
			update_user_meta( $uid, self::USED_MONTH, wp_date( 'Y-m' ) );
		} elseif ( self::credits( $uid ) > 0 ) {
			update_user_meta( $uid, self::CREDITS, self::credits( $uid ) - 1 );
			$plan = 'bought';
		} else {
			return new \WP_Error( 'csm_spot_none', 'You have no boosts left.' );
		}
		update_user_meta( $uid, self::STARTED, time() );
		update_user_meta( $uid, self::UNTIL, time() + self::HOURS * HOUR_IN_SECONDS );
		update_user_meta( $uid, self::REACHED, 0 );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'boost_started', $uid, 0, array( 'from' => $plan, 'cap' => self::cap_for( $uid ) ) );
		}
		return true;
	}

	/* ----------------------------------------------------------- delivery */

	/**
	 * Called by the Discover queue before it reads the viewer's deck: put at
	 * most one boosted member at the front, once a day per viewer.
	 */
	public static function deliver( $viewer ) {
		$viewer = (int) $viewer;
		$today  = wp_date( 'Y-m-d' );
		if ( ! $viewer || get_user_meta( $viewer, self::SEEN_DAY, true ) === $today || ! function_exists( 'cashaadi' ) ) {
			return;
		}
		global $wpdb;
		$opp = cashaadi()->get_opposite_gender( $viewer );
		if ( ! $opp ) {
			return;
		}
		// Boosts running now, for the viewer's other gender, still under their cap.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$live = $wpdb->get_col( $wpdb->prepare(
			"SELECT u.user_id FROM {$wpdb->usermeta} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.user_id AND g.field_id = %d AND g.value = %s
			 WHERE u.meta_key = %s AND CAST( u.meta_value AS UNSIGNED ) > %d",
			Config::FIELD_GENDER, $opp, self::UNTIL, time()
		) );
		if ( ! $live ) {
			return;
		}
		$exclude = array( $viewer );
		$seen    = class_exists( '\CAShaadi\Modules\Discover\Seen' ) ? \CAShaadi\Modules\Discover\Seen::ids_for( $viewer ) : array();
		$exclude = array_merge( $exclude, is_array( $seen ) ? $seen : array() );
		$exclude = array_merge( $exclude, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT profile_id FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d", $viewer ) ) ) );
		if ( function_exists( 'csm_bl_hidden_ids' ) ) {
			$exclude = array_merge( $exclude, (array) csm_bl_hidden_ids( $viewer ) );
		}
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			$exclude = array_merge( $exclude, \CAShaadi\Modules\Settings\Deactivate::paused_ids() );
		}
		if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) {
			$exclude = array_merge( $exclude, \CAShaadi\Modules\Photos\PhotoHold::held_ids() );
		}
		$exclude = array_flip( array_map( 'intval', $exclude ) );

		/*
		 * Best matches first (owner, 2026-10-26): the viewer must be a strong
		 * match at the start of the boost, using Discover's own match points
		 * (Ranker::match_parts: language, community, religion, age, city,
		 * diet). The bar falls as the hours pass, to anyone eligible after
		 * WIDEN_HOURS, so the boost still fills. The viewer's filters are not
		 * used. Among boosts this viewer qualifies for, the best match wins,
		 * then the one furthest from its cap.
		 */
		$cands = array();
		foreach ( $live as $b ) {
			$b = (int) $b;
			if ( ! isset( $exclude[ $b ] ) && self::cap_for( $b ) - (int) get_user_meta( $b, self::REACHED, true ) > 0 ) {
				$cands[] = $b;
			}
		}
		if ( ! $cands ) {
			return;
		}
		$R     = '\\CAShaadi\\Modules\\Discover\\Ranker';
		$P     = $R::points();
		$max   = $P['tongue'] + $P['community'] + $P['religion'] + $P['age_fit'] + $P['city'] + $P['diet'];
		$attrs = $R::attributes( array_merge( $cands, array( $viewer ) ) );
		$me    = isset( $attrs[ $viewer ] ) ? $attrs[ $viewer ] : array();
		$pick  = 0;
		$best  = array( -1, -1 );
		foreach ( $cands as $b ) {
			$score = array_sum( $R::match_parts( $me, isset( $attrs[ $b ] ) ? $attrs[ $b ] : array(), $P ) );
			$hours = ( time() - (int) get_user_meta( $b, self::STARTED, true ) ) / HOUR_IN_SECONDS;
			$need  = $max * self::START_BAR * max( 0, 1 - $hours / self::WIDEN_HOURS );
			if ( $score < $need ) {
				continue;
			}
			$rank = array( $score, self::cap_for( $b ) - (int) get_user_meta( $b, self::REACHED, true ) );
			if ( $rank > $best ) {
				$best = $rank;
				$pick = $b;
			}
		}
		if ( ! $pick ) {
			return;
		}
		$ok = $wpdb->insert( $wpdb->prefix . 'csm_tray', array(
			'viewer_id'     => $viewer,
			'profile_id'    => $pick,
			'week_assigned' => self::WEEK_ROW, // not this week's: does not use up the viewer's profiles
			'status'        => 'pending',
			'assigned_at'   => current_time( 'mysql' ),
		) );
		if ( ! $ok ) {
			return;
		}
		update_user_meta( $viewer, self::SEEN_DAY, $today );
		update_user_meta( $pick, self::REACHED, (int) get_user_meta( $pick, self::REACHED, true ) + 1 );
		if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
			\CAShaadi\Modules\Discover\Seen::record_served( $viewer, $pick, self::WEEK_ROW );
		}
	}

	/* ------------------------------------------------------------- buying */

	public static function product_id() {
		return (int) get_option( self::PRODUCT, 0 );
	}

	/** A paid order with the pack adds PACK boosts per unit, once. */
	public static function on_paid( $order_id ) {
		$pid   = self::product_id();
		$order = $pid && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || $order->get_meta( '_csm_spot_added' ) ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		$n   = 0;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === $pid ) {
				$n += self::PACK * max( 1, (int) $item->get_quantity() );
			}
		}
		if ( ! $uid || ! $n ) {
			return;
		}
		update_user_meta( $uid, self::CREDITS, self::credits( $uid ) + $n );
		$order->update_meta_data( '_csm_spot_added', $n );
		$order->save();
		$order->add_order_note( 'CA Shaadi: ' . $n . ' profile boosts added.' );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'boost_bought', $uid, (int) $order_id, array( 'boosts' => $n ) );
		}
	}

	/* --------------------------------------------------------------- REST */

	/** Everything the Boost button needs. */
	public static function state( $uid ) {
		$uid   = (int) $uid;
		$until = self::active_until( $uid );
		$pid   = self::product_id();
		return array(
			'until'     => $until ? $until : 0,
			'hoursLeft' => $until ? (int) ceil( ( $until - time() ) / HOUR_IN_SECONDS ) : 0,
			'reached'   => (int) get_user_meta( $uid, self::REACHED, true ),
			'cap'       => self::cap_for( $uid ),
			'available' => self::available( $uid ),
			'plan'      => Membership::is_gold( $uid ) ? 'gold' : ( Membership::is_premium( $uid ) ? 'premium' : 'free' ),
			'start'     => rest_url( 'csm/v1/boost/start' ),
			'buy'       => $pid ? home_url( '/checkout/?add-to-cart=' . $pid ) : '',
			'price'     => self::PRICE,
			'pack'      => self::PACK,
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/boost/start', array(
			'methods'             => 'POST',
			'callback'            => function () {
				$uid = get_current_user_id();
				$r   = self::start( $uid );
				return new \WP_REST_Response( array(
					'ok'      => true === $r,
					'message' => is_wp_error( $r ) ? $r->get_error_message() : '',
					'state'   => self::state( $uid ),
				), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
	}
}
