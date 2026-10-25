<?php
/**
 * wp csm pricing report [--days=7]
 *
 * Who reached the pricing page, from which button (?src=) or screen, and
 * what came of it: visits, Buy taps and purchases per source, then by plan
 * and gender (owner, 2026-10-25). Read-only, from wp_csm_event_log.
 */

namespace CAShaadi\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PricingCli {

	/**
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : How far back. Default 7.
	 */
	public function report( $args, $assoc ) {
		global $wpdb;
		$days = max( 1, (int) ( $assoc['days'] ?? 7 ) );
		$t    = $wpdb->prefix . 'csm_event_log';
		$from = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$src  = "COALESCE( NULLIF( JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.%s' ) ), '' ), '(none)' )";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s, SUM( v ) visits, SUM( m ) members, SUM( b ) buys, SUM( p ) bought FROM (
				SELECT " . sprintf( $src, 'src' ) . " s, COUNT(*) v, COUNT( DISTINCT actor_id ) m, 0 b, 0 p
				  FROM {$t} WHERE event_type = 'pricing_view' AND created_at >= %s GROUP BY s
				UNION ALL
				SELECT " . sprintf( $src, 'src' ) . ", 0, 0, COUNT(*), 0
				  FROM {$t} WHERE event_type = 'buy_click' AND created_at >= %s GROUP BY 1
				UNION ALL
				SELECT " . sprintf( $src, 'pricing_src' ) . ", 0, 0, 0, COUNT(*)
				  FROM {$t} WHERE event_type = 'purchase' AND created_at >= %s GROUP BY 1
			) x GROUP BY s ORDER BY visits DESC, buys DESC",
			$from, $from, $from
		), ARRAY_A );
		\WP_CLI::log( "Last {$days} days, by source (a button's tag, or the screen they came from):" );
		\WP_CLI\Utils\format_items( 'table', $rows ? $rows : array(), array( 's', 'visits', 'members', 'buys', 'bought' ) );

		$who = $wpdb->get_results( $wpdb->prepare(
			"SELECT " . sprintf( $src, 'plan' ) . " plan, " . sprintf( $src, 'gender' ) . " gender,
			        SUM( event_type = 'pricing_view' ) visits, SUM( event_type = 'buy_click' ) buys
			   FROM {$t} WHERE event_type IN ( 'pricing_view', 'buy_click' ) AND created_at >= %s
			  GROUP BY plan, gender ORDER BY visits DESC",
			$from
		), ARRAY_A );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'By plan and gender (plan and gender are recorded from v1.89.30):' );
		\WP_CLI\Utils\format_items( 'table', $who ? $who : array(), array( 'plan', 'gender', 'visits', 'buys' ) );

		$buys = $wpdb->get_results( $wpdb->prepare(
			"SELECT " . sprintf( $src, 'product' ) . " product, COUNT(*) taps, COUNT( DISTINCT actor_id ) members
			   FROM {$t} WHERE event_type = 'buy_click' AND created_at >= %s GROUP BY product",
			$from
		), ARRAY_A );
		$paid = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM( JSON_EXTRACT( metadata, '$.gold' ) = 1 ) gold, SUM( JSON_EXTRACT( metadata, '$.premium' ) = 1 ) premium
			   FROM {$t} WHERE event_type = 'purchase' AND created_at >= %s",
			$from
		), ARRAY_A );
		// phpcs:enable
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Buy taps by product:' );
		\WP_CLI\Utils\format_items( 'table', $buys ? $buys : array(), array( 'product', 'taps', 'members' ) );
		\WP_CLI::log( sprintf( 'Paid: %d Gold, %d Premium.', (int) ( $paid['gold'] ?? 0 ), (int) ( $paid['premium'] ?? 0 ) ) );
	}
}
