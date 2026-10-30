<?php
/**
 * wp csm boost-deal product   create the hidden ₹99 "1 Profile Boost"
 * wp csm boost-deal [report]  the result of the ₹99 boost test
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoostDealCli {

	/** Create the ₹99 single boost as a hidden, virtual product (once). */
	public function product() {
		$id = BoostDeal::product_id();
		if ( $id && function_exists( 'wc_get_product' ) && wc_get_product( $id ) ) {
			\WP_CLI::success( "Already exists: product {$id}." );
			return;
		}
		if ( ! class_exists( '\WC_Product_Simple' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}
		$p = new \WC_Product_Simple();
		$p->set_name( '1 Profile Boost' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( (string) BoostDeal::PRICE );
		$p->set_virtual( true );
		$p->set_sold_individually( true );
		$p->set_description( 'One profile boost: your profile goes to the front of Discover for 24 hours. It never expires.' );
		$id = $p->save();
		update_option( BoostDeal::PRODUCT_OPT, (int) $id, false );
		\WP_CLI::success( "Created product {$id} at ₹" . BoostDeal::PRICE . '.' );
	}

	/**
	 * Each month's draw: members in each arm, who saw the offer, tapped,
	 * bought it, and who paid for anything else within 30 days of the draw.
	 * Then everyone shown the offer, by gender and "Created for".
	 */
	public function report() {
		global $wpdb;
		\WP_CLI::log( 'Offer share: ' . (int) get_option( BoostDeal::PCT_OPT, 0 ) . '% (' . BoostDeal::PCT_OPT . '), product ' . ( BoostDeal::product_id() ?: 'not created' ) );
		$ev = $wpdb->prefix . 'csm_event_log';
		$mu = $wpdb->pmpro_memberships_users;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT event_type, actor_id, created_at, JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.round' ) ) round, JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.arm' ) ) arm FROM {$ev} WHERE event_type LIKE 'boost\_deal\_%' ORDER BY id" );
		$draw = array(); // round => uid => [arm, at, seen, tap, bought]
		foreach ( $rows as $r ) {
			$u = (int) $r->actor_id;
			if ( 'boost_deal_arm' === $r->event_type ) {
				$draw[ $r->round ][ $u ] = array( 'arm' => $r->arm, 'at' => $r->created_at, 'seen' => 0, 'tap' => 0, 'bought' => 0 );
			} elseif ( isset( $draw[ $r->round ][ $u ] ) ) {
				$k = substr( $r->event_type, 11 );
				$draw[ $r->round ][ $u ][ $k ] = 1;
			}
		}
		$cf_id = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'Created for' ) : 0;
		$seg   = array();
		$out   = array();
		ksort( $draw );
		foreach ( $draw as $round => $members ) {
			foreach ( array( 'show', 'control' ) as $arm ) {
				$t = array( 'month' => $round, 'arm' => $arm, 'members' => 0, 'saw' => 0, 'tapped' => 0, 'bought ₹' . BoostDeal::PRICE => 0, 'paid other, 30d' => 0 );
				foreach ( $members as $uid => $m ) {
					if ( $arm !== $m['arm'] ) {
						continue;
					}
					$end = wp_date( 'Y-m-d H:i:s', strtotime( $m['at'] ) + 30 * DAY_IN_SECONDS );
					$t['members']++;
					$t['saw']    += $m['seen'];
					$t['tapped'] += $m['tap'];
					$t[ 'bought ₹' . BoostDeal::PRICE ] += $m['bought'];
					$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mu} WHERE user_id = %d AND membership_id IN ( %d, %d ) AND initial_payment > 0 AND startdate BETWEEN %s AND %s", $uid, Config::PMPRO_PREMIUM_LEVEL, Config::PMPRO_GOLD_LEVEL, get_gmt_from_date( $m['at'] ), get_gmt_from_date( $end ) ) )
						+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE event_type = 'boost_bought' AND actor_id = %d AND created_at BETWEEN %s AND %s AND metadata NOT LIKE %s", $uid, $m['at'], $end, '%"deal":1%' ) );
					$t['paid other, 30d'] += $other ? 1 : 0;
					if ( 'show' === $arm && $m['seen'] ) {
						$g   = function_exists( 'cashaadi' ) ? (string) cashaadi()->get_gender( $uid ) : '';
						$cf  = $cf_id && class_exists( '\BP_XProfile_ProfileData' ) ? (string) \BP_XProfile_ProfileData::get_value_byid( $cf_id, $uid ) : '';
						$who = in_array( $cf, array( 'Son', 'Daughter' ), true ) ? 'parent' : ( 'Self' === $cf || '' === $cf ? 'self' : 'other family' );
						$k   = ( $g ? $g : '?' ) . ' · ' . $who;
						if ( ! isset( $seg[ $k ] ) ) {
							$seg[ $k ] = array( 'segment' => $k, 'saw' => 0, 'tapped' => 0, 'bought' => 0 );
						}
						$seg[ $k ]['saw']++;
						$seg[ $k ]['tapped'] += $m['tap'];
						$seg[ $k ]['bought'] += $m['bought'];
					}
				}
				$out[] = $t;
			}
		}
		// phpcs:enable
		if ( ! $out ) {
			\WP_CLI::log( 'No draws yet.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $out, array_keys( $out[0] ) );
		if ( $seg ) {
			ksort( $seg );
			foreach ( $seg as &$r ) {
				$r['buy rate'] = $r['saw'] ? round( 100 * $r['bought'] / $r['saw'], 1 ) . '%' : '-';
			}
			unset( $r );
			\WP_CLI::log( 'Shown the offer, all months, by segment:' );
			\WP_CLI\Utils\format_items( 'table', array_values( $seg ), array( 'segment', 'saw', 'tapped', 'bought', 'buy rate' ) );
		}
	}
}
