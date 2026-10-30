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
	 * Who was offered the ₹99 boost, who bought it, by gender and "Created
	 * for"; and, for both arms, who paid for anything else after being assigned.
	 */
	public function report() {
		global $wpdb;
		\WP_CLI::log( 'Offer share: ' . (int) get_option( BoostDeal::PCT_OPT, 0 ) . '% (' . BoostDeal::PCT_OPT . '), product ' . ( BoostDeal::product_id() ?: 'not created' ) );
		$cf_id = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'Created for' ) : 0;
		$mu    = $wpdb->pmpro_memberships_users;
		$ev    = $wpdb->prefix . 'csm_event_log';
		$seg   = array();
		foreach ( array( 'show', 'control' ) as $arm ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", BoostDeal::ARM_META, $arm ) ) );
			$n = count( $ids );
			$seen = $clicked = $bought = $later = 0;
			foreach ( $ids as $uid ) {
				$at      = gmdate( 'Y-m-d H:i:s', (int) get_user_meta( $uid, BoostDeal::AT_META, true ) );
				$b       = get_user_meta( $uid, BoostDeal::BOUGHT_META, true ) ? 1 : 0;
				$seen   += get_user_meta( $uid, BoostDeal::SEEN_META, true ) ? 1 : 0;
				$clicked += get_user_meta( $uid, BoostDeal::CLICKED_META, true ) ? 1 : 0;
				$bought += $b;
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$paid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mu} WHERE user_id = %d AND membership_id IN ( %d, %d ) AND initial_payment > 0 AND startdate >= %s", $uid, Config::PMPRO_PREMIUM_LEVEL, Config::PMPRO_GOLD_LEVEL, $at ) )
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE event_type = 'boost_bought' AND actor_id = %d AND created_at >= %s AND metadata NOT LIKE %s", $uid, get_date_from_gmt( $at ), '%"deal":1%' ) );
				// phpcs:enable
				$later += $paid ? 1 : 0;
				if ( 'show' === $arm && get_user_meta( $uid, BoostDeal::SEEN_META, true ) ) {
					$g  = function_exists( 'cashaadi' ) ? (string) cashaadi()->get_gender( $uid ) : '';
					$cf = $cf_id && class_exists( '\BP_XProfile_ProfileData' ) ? (string) \BP_XProfile_ProfileData::get_value_byid( $cf_id, $uid ) : '';
					$who = in_array( $cf, array( 'Son', 'Daughter' ), true ) ? 'parent' : ( 'Self' === $cf || '' === $cf ? 'self' : 'other family' );
					$k   = ( $g ? $g : '?' ) . ' · ' . $who;
					if ( ! isset( $seg[ $k ] ) ) {
						$seg[ $k ] = array( 'segment' => $k, 'saw' => 0, 'tapped' => 0, 'bought' => 0 );
					}
					$seg[ $k ]['saw']++;
					$seg[ $k ]['tapped'] += get_user_meta( $uid, BoostDeal::CLICKED_META, true ) ? 1 : 0;
					$seg[ $k ]['bought'] += $b;
				}
			}
			\WP_CLI::log( sprintf( '  %-8s %5d members · %5d saw it · %4d tapped · %3d bought ₹%d boost · %3d paid for something else afterwards (%s)', $arm, $n, $seen, $clicked, $bought, BoostDeal::PRICE, $later, $n ? round( 100 * $later / $n, 1 ) . '%' : '-' ) );
		}
		if ( $seg ) {
			ksort( $seg );
			foreach ( $seg as &$r ) {
				$r['buy rate'] = $r['saw'] ? round( 100 * $r['bought'] / $r['saw'], 1 ) . '%' : '-';
			}
			unset( $r );
			\WP_CLI::log( 'Shown the offer, by segment:' );
			\WP_CLI\Utils\format_items( 'table', array_values( $seg ), array( 'segment', 'saw', 'tapped', 'bought', 'buy rate' ) );
		}
	}
}
