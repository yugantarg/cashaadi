<?php
/**
 * Sales at the top of the Sales Dashboard: one small table (owner,
 * 2026-10-30: "I just want the key numbers ... how many people have bought
 * each product, and what is the revenue from them"). Everything else is
 * still tracked and read with a command when asked for.
 *
 * Per product: members on the plan now (Premium, Gold), people who bought it
 * and the revenue, in the last 30 days and all time. Paid orders only
 * (processing / completed), admins' test orders left out. Cached 10 minutes;
 * "Refresh now" on the dashboard rebuilds it.
 */

namespace CAShaadi\Modules\Admin;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SalesPanel {

	const CACHE = 'csm_sd_sales2';

	public static function card() {
		$d = isset( $_GET['refresh'] ) ? false : get_transient( self::CACHE ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_array( $d ) ) {
			$d = self::build();
			set_transient( self::CACHE, $d, 10 * MINUTE_IN_SECONDS );
		}
		$th = 'text-align:right;padding:6px 12px;font-weight:600';
		$td = 'text-align:right;padding:6px 12px';
		$h  = '<div style="margin:15px 0;padding:12px 14px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;max-width:820px">'
			. '<table style="border-collapse:collapse;width:100%"><tr><th style="text-align:left;padding:6px 12px">Product</th><th style="' . $th . '">On the plan now</th><th style="' . $th . '">Last 30 days</th><th style="' . $th . '">All time</th></tr>';
		foreach ( $d['rows'] as $key => $r ) {
			$total = 'total' === $key;
			$h    .= '<tr style="border-top:' . ( $total ? '2px solid #ccd0d4;font-weight:600' : '1px solid #eee' ) . '"><td style="padding:6px 12px">' . esc_html( $r['name'] ) . '</td>'
				. '<td style="' . $td . '">' . ( null === $r['now'] ? '—' : number_format_i18n( $r['now'] ) ) . '</td>'
				. '<td style="' . $td . '">' . self::cell( $r['d30'] ) . '</td>'
				. '<td style="' . $td . '">' . self::cell( $r['all'] ) . '</td></tr>';
		}
		return $h . '</table><p style="color:#666;margin:6px 12px 0;font-size:12px">People who bought · revenue. Paid orders only.</p></div>';
	}

	private static function cell( $c ) {
		return $c[0] ? number_format_i18n( $c[0] ) . ' · ₹' . number_format_i18n( $c[1] ) : '<span style="color:#aaa">—</span>';
	}

	private static function build() {
		global $wpdb;
		$rows = array(
			'premium' => array( 'name' => 'Premium' ),
			'gold'    => array( 'name' => 'Gold' ),
			'pack'    => array( 'name' => 'Boost pack (3 for ₹799)' ),
			'deal'    => array( 'name' => '1 boost for ₹99' ),
			'total'   => array( 'name' => 'Total' ),
		);
		$pids = array(
			(int) Config::WC_PREMIUM_PRODUCT => 'premium',
			(int) Config::WC_GOLD_PRODUCT    => 'gold',
		);
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\Spotlight' ) && \CAShaadi\Modules\Premium\Spotlight::product_id() ) {
			$pids[ \CAShaadi\Modules\Premium\Spotlight::product_id() ] = 'pack';
		}
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\BoostDeal' ) && \CAShaadi\Modules\Premium\BoostDeal::product_id() ) {
			$pids[ \CAShaadi\Modules\Premium\BoostDeal::product_id() ] = 'deal';
		}
		$admins = array_flip( array_map( 'intval', get_users( array( 'role__in' => array( 'administrator' ), 'fields' => 'ID' ) ) ) );
		$cut    = time() - 30 * DAY_IN_SECONDS;
		$people = array(); // [ period ][ product ] => uid => 1
		$money  = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => -1, 'type' => 'shop_order' ) ) as $o ) {
				$uid = (int) $o->get_customer_id();
				if ( isset( $admins[ $uid ] ) ) {
					continue;
				}
				$when = $o->get_date_paid() ? $o->get_date_paid() : $o->get_date_created();
				$t    = $when ? $when->getTimestamp() : 0;
				foreach ( $o->get_items() as $item ) {
					$p = $pids[ (int) $item->get_product_id() ] ?? '';
					if ( '' === $p ) {
						continue;
					}
					$amt = (float) $item->get_total();
					foreach ( $t >= $cut ? array( 'all', 'd30' ) : array( 'all' ) as $k ) {
						foreach ( array( $p, 'total' ) as $key ) {
							$people[ $k ][ $key ][ $uid ? $uid : 'o' . $o->get_id() ] = 1;
							$money[ $k ][ $key ] = ( $money[ $k ][ $key ] ?? 0 ) + $amt;
						}
					}
				}
			}
		}
		// On the plan now (PMPro active rows), admins left out.
		$now = array();
		foreach ( array( 'premium' => Config::PMPRO_PREMIUM_LEVEL, 'gold' => Config::PMPRO_GOLD_LEVEL ) as $k => $lvl ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d AND status = 'active'", $lvl ) ) );
			$now[ $k ] = count( array_filter( $ids, function ( $id ) use ( $admins ) { return ! isset( $admins[ $id ] ); } ) );
		}
		$now['total'] = $now['premium'] + $now['gold'];
		foreach ( $rows as $key => &$r ) {
			$r['now'] = $now[ $key ] ?? null;
			foreach ( array( 'd30', 'all' ) as $k ) {
				$r[ $k ] = array( isset( $people[ $k ][ $key ] ) ? count( $people[ $k ][ $key ] ) : 0, (float) ( $money[ $k ][ $key ] ?? 0 ) );
			}
		}
		unset( $r );
		return array( 'rows' => $rows );
	}
}
