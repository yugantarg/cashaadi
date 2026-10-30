<?php
/**
 * Sales panel at the top of the Sales Dashboard (owner, 2026-10-30: "the
 * sales dashboard should give clear info - not 1 figure").
 *
 * 1. Money: orders and revenue per product (Premium, Gold, boost pack, ₹99
 *    boost) for today, 7 days, 30 days and all time. Paid orders only
 *    (processing / completed), admins' test orders left out.
 * 2. Intent: pricing-page visits and Buy taps against purchases, 7 and 30
 *    days, per product.
 * 3. Experiments running: the Gold popup and the ₹99 boost, each arm's size
 *    and how many bought.
 * 4. Gold places, on sale or not (the line that was here before).
 *
 * Read-only. Built once and kept for 10 minutes, like the member table.
 */

namespace CAShaadi\Modules\Admin;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SalesPanel {

	const CACHE = 'csm_sd_sales';

	public static function card() {
		$d = isset( $_GET['refresh'] ) ? false : get_transient( self::CACHE ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_array( $d ) ) {
			$d = self::build();
			set_transient( self::CACHE, $d, 10 * MINUTE_IN_SECONDS );
		}
		$box = 'margin:15px 0;padding:12px 14px;background:#fff;border:1px solid #ccd0d4;border-radius:6px';
		$th  = 'text-align:right;padding:4px 10px;font-weight:600';
		$td  = 'text-align:right;padding:4px 10px';
		$h   = '<div style="' . $box . '"><h2 style="margin:0 0 8px">Sales</h2>';

		// 1. Money.
		$periods = array( 'today' => 'Today', 'd7' => '7 days', 'd30' => '30 days', 'all' => 'All time' );
		$h .= '<table style="border-collapse:collapse;width:100%;max-width:820px"><tr><th style="text-align:left;padding:4px 10px">Product</th>';
		foreach ( $periods as $label ) {
			$h .= '<th style="' . $th . '">' . esc_html( $label ) . '</th>';
		}
		$h .= '</tr>';
		foreach ( array_merge( array_keys( $d['names'] ), array( 'total' ) ) as $p ) {
			$is_total = 'total' === $p;
			$h .= '<tr style="border-top:1px solid #eee' . ( $is_total ? ';font-weight:600;border-top:2px solid #ccd0d4' : '' ) . '"><td style="padding:4px 10px">' . esc_html( $is_total ? 'Total' : $d['names'][ $p ] ) . '</td>';
			foreach ( array_keys( $periods ) as $k ) {
				$c = $d['money'][ $k ][ $p ] ?? array( 0, 0 );
				$h .= '<td style="' . $td . '">' . ( $c[0] ? '₹' . number_format_i18n( $c[1] ) . ' <span style="color:#666">(' . (int) $c[0] . ')</span>' : '<span style="color:#aaa">—</span>' ) . '</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</table><p style="color:#666;margin:4px 10px 12px">Revenue, with the number of orders in brackets. Paid orders only; refunds and admins left out.</p>';

		// 2. Intent.
		$h .= '<h3 style="margin:8px 0 4px">From interest to purchase</h3><table style="border-collapse:collapse;width:100%;max-width:820px"><tr><th style="text-align:left;padding:4px 10px"></th>';
		foreach ( array( 'd7' => '7 days', 'd30' => '30 days' ) as $label ) {
			$h .= '<th style="' . $th . '" colspan="3">' . esc_html( $label ) . '</th>';
		}
		$h .= '</tr><tr><td></td>';
		for ( $i = 0; $i < 2; $i++ ) {
			$h .= '<td style="' . $td . ';color:#666">Buy taps</td><td style="' . $td . ';color:#666">Bought</td><td style="' . $td . ';color:#666">Rate</td>';
		}
		$h .= '</tr>';
		$h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 10px">Pricing page visits</td><td style="' . $td . '" colspan="3">' . (int) $d['views']['d7'] . ' members</td><td style="' . $td . '" colspan="3">' . (int) $d['views']['d30'] . ' members</td></tr>';
		foreach ( $d['names'] as $p => $name ) {
			$h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 10px">' . esc_html( $name ) . '</td>';
			foreach ( array( 'd7', 'd30' ) as $k ) {
				$taps = (int) ( $d['taps'][ $k ][ $p ] ?? 0 );
				$sold = (int) ( $d['money'][ $k ][ $p ][0] ?? 0 );
				$h .= '<td style="' . $td . '">' . $taps . '</td><td style="' . $td . '">' . $sold . '</td><td style="' . $td . '">' . ( $taps ? round( 100 * min( $sold, $taps ) / $taps ) . '%' : '—' ) . '</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</table>';

		// 3. Experiments.
		if ( $d['tests'] ) {
			$h .= '<h3 style="margin:14px 0 4px">Experiments</h3><table style="border-collapse:collapse;width:100%;max-width:820px"><tr><th style="text-align:left;padding:4px 10px">Test</th><th style="text-align:left;padding:4px 10px">Status</th><th style="' . $th . '">Shown: members</th><th style="' . $th . '">Saw</th><th style="' . $th . '">Tapped</th><th style="' . $th . '">Bought</th><th style="' . $th . '">Not shown: members</th><th style="' . $th . '">Bought</th></tr>';
			foreach ( $d['tests'] as $t ) {
				$h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 10px">' . esc_html( $t['name'] ) . '</td><td style="padding:4px 10px">' . esc_html( $t['status'] ) . '</td>'
					. '<td style="' . $td . '">' . (int) $t['show'] . '</td><td style="' . $td . '">' . (int) $t['seen'] . '</td><td style="' . $td . '">' . (int) $t['tapped'] . '</td>'
					. '<td style="' . $td . '">' . self::rate( $t['bought'], $t['show'] ) . '</td><td style="' . $td . '">' . (int) $t['control'] . '</td><td style="' . $td . '">' . self::rate( $t['c_bought'], $t['control'] ) . '</td></tr>';
			}
			$h .= '</table><p style="color:#666;margin:4px 10px 0">Gold popup: Gold bought by members shown it against members not shown it. ₹99 boost: this month’s draw; members not shown are never offered it.</p>';
		}

		// 3b. The price change notice (v1.90.71).
		if ( ! empty( $d['notice'] ) ) {
			$n  = $d['notice'];
			$h .= '<h3 style="margin:14px 0 4px">Premium price notice</h3><table style="border-collapse:collapse;width:100%;max-width:820px"><tr><th style="text-align:left;padding:4px 10px">Where</th><th style="' . $th . '">Saw it</th><th style="' . $th . '">Clicked</th><th style="' . $th . '">Closed</th></tr>';
			foreach ( array( 'banner' => 'Discover banner', 'box' => 'Pricing page box' ) as $k => $label ) {
				$h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 10px">' . esc_html( $label ) . '</td><td style="' . $td . '">' . (int) $n[ $k ]['shown'] . '</td><td style="' . $td . '">' . self::rate( (int) $n[ $k ]['click'], (int) $n[ $k ]['shown'] ) . '</td><td style="' . $td . '">' . ( 'banner' === $k ? (int) $n[ $k ]['close'] : '—' ) . '</td></tr>';
			}
			$h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 10px">Email</td><td style="' . $td . '">' . (int) $n['email']['opened'] . ' opened of ' . (int) $n['email']['sent'] . '</td><td style="' . $td . '">' . self::rate( (int) $n['email']['clicked'], (int) $n['email']['sent'] ) . '</td><td style="' . $td . '">—</td></tr>';
			$h .= '</table><p style="color:#666;margin:4px 10px 0">Members, not taps. Bought with OLDPRICE: <strong>' . (int) $n['oldprice'] . '</strong> · Premium bought since 30 Oct: <strong>' . (int) $n['premium'] . '</strong>.</p>';
		}

		// 4. Gold places.
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\Gold' ) ) {
			$h .= '<p style="margin:12px 10px 0;color:#555">' . wp_strip_all_tags( \CAShaadi\Modules\Premium\Gold::dashboard_line() ) . '</p>';
		}
		return $h . '</div>';
	}

	private static function rate( $n, $of ) {
		if ( null === $n ) {
			return '<span style="color:#aaa">—</span>';
		}
		return (int) $n . ( $of ? ' <span style="color:#666">(' . round( 100 * $n / $of, 1 ) . '%)</span>' : '' );
	}

	private static function build() {
		global $wpdb;
		$names = array( 'premium' => 'Premium', 'gold' => 'Gold', 'pack' => 'Boost pack (3)', 'deal' => '₹99 boost' );
		$pids  = array(
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
		$now    = time();
		$since  = array(
			'today' => strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() ),
			'd7'    => $now - 7 * DAY_IN_SECONDS,
			'd30'   => $now - 30 * DAY_IN_SECONDS,
			'all'   => 0,
		);
		$money = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => -1, 'type' => 'shop_order' ) );
			foreach ( $orders as $o ) {
				if ( isset( $admins[ (int) $o->get_customer_id() ] ) || ! $o->get_date_paid() && ! $o->get_date_created() ) {
					continue;
				}
				$t = $o->get_date_paid() ? $o->get_date_paid()->getTimestamp() : $o->get_date_created()->getTimestamp();
				foreach ( $o->get_items() as $item ) {
					$p = $pids[ (int) $item->get_product_id() ] ?? '';
					if ( '' === $p ) {
						continue;
					}
					$amt = (float) $item->get_total();
					foreach ( $since as $k => $from ) {
						if ( $t >= $from ) {
							foreach ( array( $p, 'total' ) as $key ) {
								$money[ $k ][ $key ][0] = ( $money[ $k ][ $key ][0] ?? 0 ) + 1;
								$money[ $k ][ $key ][1] = ( $money[ $k ][ $key ][1] ?? 0 ) + $amt;
							}
						}
					}
				}
			}
		}

		$ev    = $wpdb->prefix . 'csm_event_log';
		$taps  = array();
		$views = array();
		$map   = array( 'premium' => 'premium', 'gold' => 'gold', 'boost_pack' => 'pack', 'boost_deal' => 'deal' );
		foreach ( array( 'd7' => 7, 'd30' => 30 ) as $k => $days ) {
			$from = wp_date( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.product' ) ) p, COUNT( DISTINCT actor_id ) n FROM {$ev} WHERE event_type = 'buy_click' AND created_at >= %s GROUP BY p", $from ) ) as $r ) {
				if ( isset( $map[ $r->p ] ) ) {
					$taps[ $k ][ $map[ $r->p ] ] = (int) $r->n;
				}
			}
			$views[ $k ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT actor_id ) FROM {$ev} WHERE event_type = 'pricing_view' AND created_at >= %s", $from ) );
			// phpcs:enable
		}

		$tests = array();
		$mu    = $wpdb->pmpro_memberships_users;
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\GoldIntro' ) ) {
			$gi = '\\CAShaadi\\Modules\\Premium\\GoldIntro';
			$t  = array( 'name' => 'Gold popup', 'status' => (int) get_option( $gi::PCT_OPT, 0 ) ? 'on, ' . (int) get_option( $gi::PCT_OPT, 0 ) . '% shown, up to ' . (int) get_option( 'csm_gold_intro_max', 200 ) : 'off' );
			foreach ( array( 'show', 'control' ) as $arm ) {
				$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", $gi::ARM_META, $arm ) ) );
				$bought = 0;
				$seen   = 0;
				$tap    = 0;
				foreach ( $ids as $uid ) {
					$at = gmdate( 'Y-m-d H:i:s', (int) get_user_meta( $uid, $gi::AT_META, true ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$bought += $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$mu} WHERE user_id = %d AND membership_id = %d AND startdate >= %s LIMIT 1", $uid, Config::PMPRO_GOLD_LEVEL, $at ) ) ? 1 : 0;
					$seen   += get_user_meta( $uid, $gi::SEEN_META, true ) ? 1 : 0;
					$tap    += get_user_meta( $uid, $gi::CLICKED_META, true ) ? 1 : 0;
				}
				if ( 'show' === $arm ) {
					$t += array( 'show' => count( $ids ), 'seen' => $seen, 'tapped' => $tap, 'bought' => $bought );
				} else {
					$t += array( 'control' => count( $ids ), 'c_bought' => $bought );
				}
			}
			$tests[] = $t;
		}
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\BoostDeal' ) ) {
			$bd    = '\\CAShaadi\\Modules\\Premium\\BoostDeal';
			$round = $bd::round();
			$pct   = (int) get_option( $bd::PCT_OPT, 0 );
			$t     = array( 'name' => '₹99 boost (' . wp_date( 'M Y' ) . ')', 'status' => $pct ? 'on, ' . $pct . '% shown' : 'off' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT actor_id, event_type, JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.arm' ) ) arm FROM {$ev} WHERE event_type LIKE 'boost\_deal\_%%' AND JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.round' ) ) = %s", $round ) );
			$arm   = array();
			$c     = array( 'seen' => array(), 'tap' => array(), 'bought' => array() );
			foreach ( $rows as $r ) {
				if ( 'boost_deal_arm' === $r->event_type ) {
					$arm[ (int) $r->actor_id ] = $r->arm;
				} else {
					$c[ substr( $r->event_type, 11 ) ][ (int) $r->actor_id ] = 1;
				}
			}
			$count = function ( $which, $a ) use ( $arm ) {
				$n = 0;
				foreach ( $which as $uid => $_ ) {
					$n += ( $arm[ $uid ] ?? '' ) === $a ? 1 : 0;
				}
				return $n;
			};
			$sizes = array_count_values( $arm );
			$t += array(
				'show'     => (int) ( $sizes['show'] ?? 0 ),
				'seen'     => $count( $c['seen'], 'show' ),
				'tapped'   => $count( $c['tap'], 'show' ),
				'bought'   => $count( $c['bought'], 'show' ),
				'control'  => (int) ( $sizes['control'] ?? 0 ),
				'c_bought' => null, // control is never offered it
			);
			$tests[] = $t;
		}

		// The price change notice: members who saw / clicked / closed it.
		$notice = array();
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\OldPrice' ) ) {
			$z = array( 'shown' => 0, 'click' => 0, 'close' => 0 );
			$notice = array( 'banner' => $z, 'box' => $z );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( "SELECT JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.where' ) ) w, JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.what' ) ) x, COUNT( DISTINCT actor_id ) n FROM {$ev} WHERE event_type = 'oldprice_notice' GROUP BY w, x" ) as $r ) {
				if ( isset( $notice[ $r->w ][ $r->x ] ) ) {
					$notice[ $r->w ][ $r->x ] = (int) $r->n;
				}
			}
			$st = class_exists( '\\CAShaadi\\Modules\\Emails\\Tracking' ) ? \CAShaadi\Modules\Emails\Tracking::stats( 'csm-price-nov26' ) : array();
			$notice['email']    = array( 'sent' => (int) ( $st['sent'] ?? 0 ), 'opened' => (int) ( $st['opened'] ?? 0 ), 'clicked' => (int) ( $st['clicked'] ?? 0 ) );
			$notice['oldprice'] = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT actor_id ) FROM {$ev} WHERE event_type = 'purchase' AND metadata LIKE '%\"oldprice\"%'" );
			$notice['premium']  = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT actor_id ) FROM {$ev} WHERE event_type = 'purchase' AND created_at >= '2026-10-30' AND metadata LIKE '%\"premium\":1%'" );
			// phpcs:enable
		}

		return array( 'names' => $names, 'money' => $money, 'taps' => $taps, 'views' => $views, 'tests' => $tests, 'notice' => $notice );
	}
}
