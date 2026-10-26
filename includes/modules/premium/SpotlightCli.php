<?php
/**
 * wp csm spotlight product   create the "Profile boosts" pack (3 for ₹799)
 * wp csm spotlight give <email> [--n=1]   add boosts to a member
 * wp csm spotlight status    running boosts and their reach
 * wp csm spotlight report [--days=30]   what each boost achieved
 */

namespace CAShaadi\Modules\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SpotlightCli {

	/** Create the pack as a hidden, virtual WooCommerce product (once). */
	public function product() {
		$id = Spotlight::product_id();
		if ( $id && function_exists( 'wc_get_product' ) && wc_get_product( $id ) ) {
			\WP_CLI::success( "Already exists: product {$id}." );
			return;
		}
		if ( ! class_exists( '\WC_Product_Simple' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}
		$p = new \WC_Product_Simple();
		$p->set_name( Spotlight::PACK . ' Profile Boosts' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( (string) Spotlight::PRICE );
		$p->set_virtual( true );
		$p->set_sold_individually( false );
		$p->set_description( Spotlight::PACK . ' profile boosts. Each puts your profile at the front of Discover for 24 hours. They never expire.' );
		$id = $p->save();
		update_option( Spotlight::PRODUCT, (int) $id, false );
		\WP_CLI::success( "Created product {$id} at ₹" . Spotlight::PRICE . '.' );
	}

	/**
	 * ## OPTIONS
	 * <member>
	 * [--n=<n>]
	 */
	public function give( $args, $assoc ) {
		$u = is_numeric( $args[0] ) ? get_userdata( (int) $args[0] ) : get_user_by( 'email', $args[0] );
		if ( ! $u ) {
			\WP_CLI::error( 'No such member.' );
		}
		$n = max( 1, (int) ( $assoc['n'] ?? 1 ) );
		update_user_meta( $u->ID, Spotlight::CREDITS, Spotlight::credits( $u->ID ) + $n );
		\WP_CLI::success( "{$u->user_email} now has " . Spotlight::credits( $u->ID ) . ' bought boosts.' );
	}

	public function status() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > %d", Spotlight::UNTIL, time() ) );
		\WP_CLI::log( 'Pack product: ' . ( Spotlight::product_id() ?: 'not created (wp csm spotlight product)' ) );
		\WP_CLI::log( count( $rows ) . ' boosts running.' );
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r->user_id );
			\WP_CLI::log( sprintf( '  %s · reached %d of %d · ends %s', $u ? $u->user_email : $r->user_id, (int) get_user_meta( $r->user_id, Spotlight::REACHED, true ), Spotlight::cap_for( $r->user_id ), wp_date( 'j M H:i', (int) $r->meta_value ) ) );
		}
	}

	/**
	 * What each boost started in the last --days achieved: cards shown,
	 * requests / passes / saves on those cards, requests the member received
	 * during the 24 hours against the 24 hours before, and matches from them.
	 *
	 * ## OPTIONS
	 * [--days=<n>]
	 */
	public function report( $args, $assoc ) {
		global $wpdb;
		$days = max( 1, (int) ( $assoc['days'] ?? 30 ) );
		$ev   = $wpdb->prefix . 'csm_event_log';
		$fr   = $wpdb->prefix . 'bp_friends';
		$from = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$boosts = $wpdb->get_results( $wpdb->prepare(
			"SELECT actor_id, created_at, metadata FROM {$ev} WHERE event_type = 'boost_started' AND created_at >= %s ORDER BY created_at",
			$from
		) );
		$rows = array();
		$sum  = array( 'shown' => 0, 'requests' => 0, 'passes' => 0, 'saves' => 0, 'recv' => 0, 'recv_before' => 0, 'matches' => 0 );
		foreach ( $boosts as $b ) {
			$uid   = (int) $b->actor_id;
			$start = $b->created_at;
			$end   = wp_date( 'Y-m-d H:i:s', strtotime( $start ) + DAY_IN_SECONDS );
			// Outcomes can come later than the 24 hours: up to the member's next boost, or a week.
			$next  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN( created_at ) FROM {$ev} WHERE event_type = 'boost_started' AND actor_id = %d AND created_at > %s", $uid, $start ) );
			$stop  = $next ? $next : wp_date( 'Y-m-d H:i:s', strtotime( $start ) + 7 * DAY_IN_SECONDS );
			$shown = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE event_type = 'boost_shown' AND actor_id = %d AND created_at BETWEEN %s AND %s", $uid, $start, $end ) );
			$out   = $wpdb->get_results( $wpdb->prepare( "SELECT JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.status' ) ) s, COUNT(*) n FROM {$ev} WHERE event_type = 'boost_outcome' AND actor_id = %d AND created_at >= %s AND created_at < %s GROUP BY s", $uid, $start, $stop ), OBJECT_K );
			$gs    = get_gmt_from_date( $start );
			$ge    = get_gmt_from_date( $end );
			$gb    = get_gmt_from_date( wp_date( 'Y-m-d H:i:s', strtotime( $start ) - DAY_IN_SECONDS ) );
			$recv  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fr} WHERE friend_user_id = %d AND date_created BETWEEN %s AND %s", $uid, $gs, $ge ) );
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fr} WHERE friend_user_id = %d AND date_created >= %s AND date_created < %s", $uid, $gb, $gs ) );
			$match = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fr} WHERE friend_user_id = %d AND is_confirmed = 1 AND date_created BETWEEN %s AND %s", $uid, $gs, $ge ) );
			$u     = get_userdata( $uid );
			$meta  = json_decode( (string) $b->metadata, true );
			$r     = array(
				'member'      => $u ? $u->user_email : $uid,
				'started'     => wp_date( 'j M H:i', strtotime( $start ) ),
				'from'        => isset( $meta['from'] ) ? $meta['from'] : '',
				'shown'       => $shown,
				'requests'    => isset( $out['liked'] ) ? (int) $out['liked']->n : 0,
				'passes'      => isset( $out['passed'] ) ? (int) $out['passed']->n : 0,
				'saves'       => isset( $out['saved'] ) ? (int) $out['saved']->n : 0,
				'recv'        => $recv,
				'recv_before' => $before,
				'matches'     => $match,
			);
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $r[ $k ];
			}
			$rows[] = $r;
		}
		// phpcs:enable
		\WP_CLI::log( count( $rows ) . " boosts started in the last {$days} days." );
		\WP_CLI::log( 'shown = boosted cards delivered; requests/passes/saves = what viewers did with them; recv = requests the member received in the 24 hours (recv_before: the 24 hours before); matches = of those, accepted.' );
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
			\WP_CLI::log( sprintf(
				'Totals: %d shown · %d requests (%s of shown) · %d received vs %d the day before · %d matches.',
				$sum['shown'], $sum['requests'], $sum['shown'] ? round( 100 * $sum['requests'] / $sum['shown'] ) . '%' : '-',
				$sum['recv'], $sum['recv_before'], $sum['matches']
			) );
		}
		$taps = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'buy_click' AND created_at >= %s AND metadata LIKE %s", $from, '%boost_pack%' ) );
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'boost_bought' AND created_at >= %s", $from ) );
		\WP_CLI::log( "Boost pack: {$taps} buy taps, {$sold} bought." );
	}
}
