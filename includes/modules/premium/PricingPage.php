<?php
/**
 * The pricing page with Gold (owner, 2026-10-24/25).
 *
 * Same plans and features for men and women; the words differ. A short
 * comparison first, "Compare all features" to open the full table, and
 * "Buy Premium" / "Buy Gold" buttons (owner: buttons say Buy, nothing else).
 * Badge: "Best chance of a match" on Gold for men, "Most popular" on
 * Premium for women. Logged-out visitors get the men's page in neutral words.
 *
 * Replaces the page content only when csm_pricing_v2 is on; until then an
 * admin can preview it with ?v2=1. The Gold column shows only while Gold is
 * on sale (csm_gold_on_sale) or to an admin previewing.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PricingPage {

	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'render' ), 30 );
	}

	private static function preview() {
		return current_user_can( 'manage_options' ) && isset( $_GET['v2'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function active() {
		return (bool) get_option( 'csm_pricing_v2', 0 ) || self::preview();
	}

	public static function gold_on_sale() {
		return (bool) get_option( 'csm_gold_on_sale', 0 ) || self::preview();
	}

	private static function price( $product, $fallback ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $product ) : null;
		return $p ? (int) $p->get_price() : $fallback;
	}

	private static function inr( $n ) {
		return '₹' . number_format_i18n( (int) $n );
	}

	public static function render( $html ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() || ! is_page( PricingCopy::SLUG ) || ! self::active() ) {
			return $html;
		}
		$uid    = get_current_user_id();
		$female = $uid && function_exists( 'cashaadi' ) && 'Female' === trim( (string) cashaadi()->get_gender( $uid ) );
		$anon   = ! $uid;
		$gold   = self::gold_on_sale();
		$pp     = self::price( Config::WC_PREMIUM_PRODUCT, 1499 );
		$gp     = self::price( Config::WC_GOLD_PRODUCT, 7999 );
		$free_n = $female ? ( class_exists( '\CAShaadi\Modules\Discover\FreeFemale' ) ? (int) \CAShaadi\Modules\Discover\FreeFemale::quota_for_women() : 15 ) : 5;
		$prem_n = $female ? 50 : 10;
		$is_g   = $uid && Membership::is_gold( $uid );
		$is_p   = $uid && ! $is_g && Membership::is_premium( $uid );
		$credit = $is_p ? Gold::credit_for( $uid ) : 0;
		$her    = $anon ? 'their' : ( $female ? 'his' : 'her' );

		if ( $female ) {
			$head = 'Find the right one, on your terms';
			$sub  = 'See who is interested, and choose who reaches you.';
			$rows = array(
				array( 'Profiles you see', $free_n . ' a week', $prem_n . ' a week', '<strong>Everyone</strong>' ),
				array( 'Requests you receive', 'All, unsorted', '<strong>Filter by income, community, CA / Inter, age</strong>', '✓' ),
				array( 'Who viewed you', '—', '<strong>✓</strong>', '✓' ),
				array( 'Filters in Discover', '—', '✓', '✓' ),
				array( 'Your profile', 'Standard', 'Standard', '<strong>Shown first to the men you would like</strong>' ),
				array( 'Phone numbers', 'After a match', 'After a match', '<strong>Anyone’s</strong>' ),
			);
			$badge = 'premium';
			$badge_text = 'Most popular';
		} else {
			$head = 'Get noticed by the right person';
			$sub  = 'Members whose profile is seen first find their match faster.';
			$rows = array(
				array( 'Profiles you see', $free_n . ' a week', $prem_n . ' a week', '<strong>Everyone</strong>' ),
				array( 'Your requests', 'Standard', 'With a personal note', '<strong>Always at the top of ' . $her . ' list</strong>' ),
				array( 'Your profile in Discover', 'Standard', 'Standard', '<strong>Shown first</strong>' ),
				array( 'Filters (community, language, age…)', '—', '✓', '✓' ),
				array( 'Who viewed you', '—', '✓', '✓' ),
				array( 'Phone numbers', 'After a match', 'After a match', '<strong>Anyone’s</strong>' ),
			);
			$badge = 'gold';
			$badge_text = 'Best chance of a match';
		}

		$full = array(
			array( 'Profiles a week in Discover', $free_n . ' (+5 with a photo, +5 verified)', (string) $prem_n, ( $female ? '50' : '20' ) . ' + browse all members' ),
			array( 'Browse all members', '—', '—', '✓' ),
			array( 'Send requests, chat after a match', '✓', '✓', '✓' ),
			array( 'Phone number after a match', '✓', '✓', '✓' ),
			array( 'Phone numbers of all members (except "Only me"); viewing one sends a request', '—', '—', '✓' ),
			array( 'Filters in Discover: community, mother tongue, religion, diet, age, height, income, qualification', '—', '✓', '✓' ),
			array( 'Filter the requests you receive', '—', '✓', '✓' ),
			array( 'Personal note with your request', '—', '✓', '✓' ),
			array( 'Who viewed your profile, and send them a request', '—', '✓', '✓' ),
			array( 'See blurred photos', '—', '✓', '✓' ),
			array( 'Saved profiles', 'This week', 'All', 'All' ),
			array( 'Priority requests: top of ' . $her . ' list, highlighted', '—', '—', 'Every request' ),
			array( 'Shown first in Discover', '—', '—', '✓' ),
			array( 'Gold badge (with CA verification)', '—', '—', '✓' ),
			array( 'Price', 'Free', self::inr( $pp ) . ' / year', self::inr( $gp ) . ' / year' ),
		);

		$cols = $gold ? 3 : 2;
		$btn  = function ( $product, $label, $current ) use ( $anon ) {
			if ( $current ) {
				return '<span class="csm-pr-cur">Your plan</span>';
			}
			$url = $anon ? home_url( '/register/' ) : home_url( '/checkout/?add-to-cart=' . (int) $product );
			return '<a class="csm-pr-buy" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		};
		$sold_out = $gold && class_exists( __NAMESPACE__ . '\\Gold' ) && ! Gold::purchasable( true, function_exists( 'wc_get_product' ) ? wc_get_product( Config::WC_GOLD_PRODUCT ) : null );

		$o  = self::css();
		$o .= '<div class="csm-pr">';
		$o .= '<h1 class="csm-pr-h">' . esc_html( $head ) . '</h1><p class="csm-pr-sub">' . esc_html( $sub ) . '</p>';
		$o .= '<div class="csm-pr-table cols-' . ( $cols + 1 ) . '"><table><thead><tr><th></th><th>Free</th>';
		$o .= '<th class="' . ( 'premium' === $badge ? 'is-hl' : '' ) . '">' . ( 'premium' === $badge ? '<span class="csm-pr-badge">' . esc_html( $badge_text ) . '</span>' : '' ) . 'Premium</th>';
		if ( $gold ) {
			$o .= '<th class="is-gold ' . ( 'gold' === $badge ? 'is-hl' : '' ) . '">' . ( 'gold' === $badge ? '<span class="csm-pr-badge is-gold">' . esc_html( $badge_text ) . '</span>' : '' ) . 'Gold</th>';
		}
		$o .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$o .= '<tr><th>' . esc_html( $r[0] ) . '</th><td>' . wp_kses_post( $r[1] ) . '</td><td>' . wp_kses_post( $r[2] ) . '</td>' . ( $gold ? '<td class="is-gold">' . wp_kses_post( $r[3] ) . '</td>' : '' ) . '</tr>';
		}
		$o .= '<tr class="csm-pr-price"><th>Price</th><td>Free</td><td>' . esc_html( self::inr( $pp ) ) . ' <small>/ year</small><br><small>about ' . esc_html( self::inr( round( $pp / 12 ) ) ) . ' a month</small></td>';
		if ( $gold ) {
			$o .= '<td class="is-gold">' . esc_html( self::inr( $gp ) ) . ' <small>/ year</small><br><small>about ' . esc_html( self::inr( round( $gp / 12 ) ) ) . ' a month</small></td>';
		}
		$o .= '</tr><tr class="csm-pr-btns"><th></th><td></td><td>' . $btn( Config::WC_PREMIUM_PRODUCT, 'Buy Premium', $is_p || $is_g ) . '</td>';
		if ( $gold ) {
			$o .= '<td class="is-gold">' . ( $sold_out && ! $is_g ? '<span class="csm-pr-cur">Founding places taken</span>' : $btn( Config::WC_GOLD_PRODUCT, 'Buy Gold', $is_g ) ) . '</td>';
		}
		$o .= '</tr></tbody></table></div>';

		if ( $gold && $is_p ) {
			$o .= '<p class="csm-pr-credit">Already Premium? Upgrade to Gold and your unused Premium days are credited' . ( $credit ? ': <strong>' . esc_html( self::inr( $credit ) ) . ' off</strong> today' : '' ) . ', so you only pay the difference.</p>';
		}

		$o .= '<details class="csm-pr-all"><summary>Compare all features</summary><div class="csm-pr-table"><table><thead><tr><th></th><th>Free</th><th>Premium</th>' . ( $gold ? '<th class="is-gold">Gold</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $full as $r ) {
			if ( ! $gold && '—' === $r[1] && '—' === $r[2] ) {
				continue; // Gold-only rows, while Gold is not on sale
			}
			$o .= '<tr><th>' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] ) . '</td><td>' . esc_html( $r[2] ) . '</td>' . ( $gold ? '<td class="is-gold">' . esc_html( $r[3] ) . '</td>' : '' ) . '</tr>';
		}
		$o .= '</tbody></table></div></details>';
		$o .= '<p class="csm-pr-foot">One-time payment for a year. No automatic renewal. Questions? <a href="mailto:support@cashaadi.in">support@cashaadi.in</a></p>';
		$o .= '</div>';
		return $o;
	}

	private static function css() {
		return '<style>
.csm-pr{max-width:860px;margin:0 auto;padding:8px 16px 32px;font-family:inherit;color:#16212b}
.csm-pr-h{font-size:30px;line-height:1.2;margin:8px 0 6px;text-align:center}
.csm-pr-sub{text-align:center;color:#5c6a76;margin:0 0 22px}
.csm-pr-table{overflow-x:auto;-webkit-overflow-scrolling:touch}
.csm-pr table{width:100%;border-collapse:separate;border-spacing:0;font-size:14.5px;min-width:480px}
.csm-pr th,.csm-pr td{padding:11px 10px;border-bottom:1px solid #e4eaf0;text-align:center;vertical-align:middle}
.csm-pr tbody th{text-align:left;font-weight:600;color:#38434d;width:30%}
.csm-pr thead th{font-size:17px;padding-top:30px;position:relative}
.csm-pr .is-gold{background:#fffaf0}
.csm-pr thead th.is-gold{background:linear-gradient(180deg,#f3d27a,#e7c062);color:#3a2806;border-radius:14px 14px 0 0}
.csm-pr thead th.is-hl:not(.is-gold){background:#eef3fb;border-radius:14px 14px 0 0;color:#0b2350}
.csm-pr-badge{position:absolute;left:50%;top:4px;transform:translateX(-50%);white-space:nowrap;font-size:11px;font-weight:700;letter-spacing:.04em;padding:3px 10px;border-radius:999px;background:#0b2350;color:#fff}
.csm-pr-badge.is-gold{background:#3a2806;color:#ffe7a3}
.csm-pr-price td{font-size:18px;font-weight:700}.csm-pr-price small{font-size:12px;font-weight:400;color:#5c6a76}
.csm-pr-btns td,.csm-pr-btns th{border-bottom:0}
.csm-pr-buy{display:inline-block;padding:11px 20px;border-radius:999px;background:#0b2350;color:#fff!important;text-decoration:none;font-weight:700}
.is-gold .csm-pr-buy{background:#3a2806;color:#ffe7a3!important}
.csm-pr-cur{display:inline-block;padding:8px 14px;border-radius:999px;border:1px solid #c9d2dc;color:#5c6a76;font-size:13px}
.csm-pr-credit{margin:16px 0 0;padding:12px 14px;background:#fffaf0;border:1px solid #f0d58c;border-radius:10px;text-align:center}
.csm-pr-all{margin-top:22px}.csm-pr-all summary{cursor:pointer;font-weight:700;text-align:center;padding:10px}
.csm-pr-all table{font-size:13.5px}
.csm-pr-foot{margin-top:18px;text-align:center;color:#5c6a76;font-size:13px}
@media(max-width:600px){.csm-pr-h{font-size:24px}.csm-pr table{font-size:13px}.csm-pr th,.csm-pr td{padding:9px 6px}}
</style>';
	}
}
