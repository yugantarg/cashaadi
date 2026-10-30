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

	/** Admins and the owner's test accounts can preview with ?v2=1. */
	private static function preview() {
		return isset( $_GET['v2'] ) && ( current_user_can( 'manage_options' ) || Gold::is_tester() ); // phpcs:ignore WordPress.Security.NonceVerification
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
		/*
		 * Not tied to "the main loop": page builders such as Elementor draw
		 * the content outside it, which hid this page entirely (2026-10-25).
		 * Anything before <body> (SEO plugins building descriptions) is left
		 * alone.
		 */
		if ( is_admin() || ! did_action( 'wp_head' ) || ! is_page( PricingCopy::SLUG ) || ! self::active() ) {
			return $html;
		}
		static $out = null;
		if ( null !== $out ) {
			return ''; // drawn once; a builder calling the_content again adds nothing
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
		// Premium's old price, for a limited time (OldPrice, v1.90.58).
		// Before 1 November (v1.90.64): the change is coming; buy now at the old
		// price. Everyone who can still join before then is told, signed in or not.
		$op_end  = 0;
		$op_live = false;
		if ( class_exists( __NAMESPACE__ . '\\OldPrice' ) ) {
			$op_live = OldPrice::started() || OldPrice::preview();
			if ( $op_live ) {
				$op_end = OldPrice::offer_for( $uid );
				if ( OldPrice::preview() ) {
					$pp = OldPrice::NEW_PRICE;
				}
			} elseif ( ( ! $is_p && ! $is_g ) || ( isset( $_GET['oldprice'] ) && 'soon' === $_GET['oldprice'] && current_user_can( 'manage_options' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$op_end = ( new \DateTimeImmutable( OldPrice::FROM, wp_timezone() ) )->getTimestamp();
			}
		}

		/*
		 * Three cards (owner, 2026-10-25: "free, premium shows everything in
		 * free plus xyz, Gold shows everything in premium plus xyz"; filters
		 * are one line, "Advanced filters").
		 */
		if ( $female ) {
			$head  = 'Find the right one, on your terms';
			$sub   = 'See who is interested, and choose who reaches you.';
			$badge = 'premium';
			$badge_text = 'Most popular';
		} else {
			$head  = 'Get noticed by the right person';
			$sub   = 'Members whose profile is seen first find their match faster.';
			$badge = 'gold';
			$badge_text = 'Best chance of a match';
		}
		$plans = array(
			'free'    => array(
				'name'  => 'Free',
				'lead'  => '',
				'items' => array(
					$free_n . ' profiles a week',
					'Send requests, and chat once you match',
				),
				'price' => '₹0',
				'btn'   => '',
			),
			'premium' => array(
				'name'  => 'Premium',
				'lead'  => 'Everything in Free, plus:',
				'items' => array(
					$prem_n . ' profiles a week',
					// Owner, 2026-10-26: undecided profiles carry over (Discover::premium_carry_over).
					'Profiles never expire: undecided ones add up week after week',
					'1 profile boost a month: 24 hours at the front of Discover',
					'Advanced filters',
					'See who viewed your profile, and send them a request',
					'See blurred photos',
					'Add a personal note to your requests',
					'Keep all your saved profiles',
				),
				'price' => self::inr( $pp ) . ' / year',
				'note'  => ! $op_end ? '' : ( $op_live ? self::inr( OldPrice::OLD_PRICE ) . ' with code OLDPRICE, for a limited time' : 'Goes up to ' . self::inr( OldPrice::NEW_PRICE ) . ' on 1 November' ),
				'btn'   => $is_p || $is_g ? '<span class="csm-pr-cur">Your plan</span>' : self::buy( Config::WC_PREMIUM_PRODUCT, 'Buy Premium', $anon ),
			),
		);
		// Women open numbers with Premium; men with Gold (owner, 2026-10-25).
		$phone_line = 'Call or WhatsApp any member directly';
		if ( $female ) {
			$plans['premium']['items'][] = $phone_line;
		}
		if ( $gold ) {
			$sold_out = ! $is_g && ! Gold::purchasable( true, function_exists( 'wc_get_product' ) ? wc_get_product( Config::WC_GOLD_PRODUCT ) : null );
			$plans['gold'] = array(
				'name'  => 'Gold',
				'lead'  => 'Everything in Premium, plus:',
				'items' => array(
					'Unlimited profiles: see every member',
					'Send a request to anyone you like',
					'Your requests always at the top of ' . $her . ' list',
					'1 profile boost every week',
					'Gold badge on your profile (with CA verification)',
				),
				'price' => self::inr( $gp ) . ' / year',
				'btn'   => $is_g ? '<span class="csm-pr-cur">Your plan</span>' : ( $sold_out ? '<span class="csm-pr-cur">Founding places taken</span>' : self::buy( Config::WC_GOLD_PRODUCT, 'Buy Gold', $anon ) ),
			);
			if ( ! $female ) {
				array_splice( $plans['gold']['items'], 4, 0, array( $phone_line ) );
			}
		}

		$o  = self::css();
		$o .= '<div class="csm-pr">';
		$o .= '<h1 class="csm-pr-h">' . esc_html( $head ) . '</h1><p class="csm-pr-sub">' . esc_html( $sub ) . '</p>';
		if ( $op_end ) {
			$o .= $op_live ? self::old_price_box( $op_end, $anon ) : self::price_soon_box( $op_end, $anon );
		}
		$o .= '<div class="csm-pr-cards n' . count( $plans ) . '">';
		foreach ( $plans as $key => $pl ) {
			$hl  = $key === $badge;
			$o  .= '<div class="csm-pr-card is-' . esc_attr( $key ) . ( $hl ? ' is-hl' : '' ) . '">';
			if ( $hl ) {
				$o .= '<span class="csm-pr-badge">' . esc_html( $badge_text ) . '</span>';
			}
			$o .= '<h2>' . esc_html( $pl['name'] ) . '</h2>';
			$o .= '<p class="csm-pr-price">' . esc_html( $pl['price'] ) . '</p>';
			if ( ! empty( $pl['note'] ) ) {
				$o .= '<p class="csm-pr-note">' . esc_html( $pl['note'] ) . '</p>';
			}
			if ( $pl['lead'] ) {
				$o .= '<p class="csm-pr-lead">' . esc_html( $pl['lead'] ) . '</p>';
			}
			$o .= '<ul>';
			foreach ( $pl['items'] as $it ) {
				$o .= '<li>' . esc_html( $it ) . '</li>';
			}
			$o .= '</ul>';
			if ( $pl['btn'] ) {
				$o .= '<div class="csm-pr-act">' . $pl['btn'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
			}
			$o .= '</div>';
		}
		$o .= '</div>';

		if ( $gold && $is_p ) {
			$o .= '<p class="csm-pr-credit">Already Premium? Upgrade to Gold and your unused Premium days are credited' . ( $credit ? ': <strong>' . esc_html( self::inr( $credit ) ) . ' off</strong> today' : '' ) . ', so you only pay the difference.</p>';
		}
		// Before phone numbers open: say when, and the free week (v1.89.28).
		if ( ! get_option( 'csm_gold_phone_live' ) && get_option( GoldLaunch::DATE ) ) {
			$o .= '<p class="csm-pr-credit">Phone numbers open on ' . esc_html( wp_date( 'j F', strtotime( get_option( GoldLaunch::DATE ) . ' 12:00:00' ) ) ) . '. ' . ( $female ? 'Buy Premium or Gold' : 'Buy Gold' ) . ' before then and get ' . (int) GoldLaunch::INTERIM_DAYS . ' extra days free.</p>';
		}
		// Boost pack (v1.89.47), as its own small card with a button (v1.89.57).
		if ( class_exists( __NAMESPACE__ . '\\Spotlight' ) && Spotlight::product_id() ) {
			$o .= '<div class="csm-pr-boost"><div><strong>Profile boosts</strong>'
				. '<span>' . (int) Spotlight::PACK . ' boosts for ' . esc_html( self::inr( Spotlight::PRICE ) ) . ' · each puts you first in Discover for 24 hours · never expire</span></div>'
				. self::buy( Spotlight::product_id(), 'Buy boosts', $anon )
				. '</div>';
		}
		$o .= '<p class="csm-pr-foot">One-time payment for a year. No automatic renewal. Viewing a phone number sends that member a match request. Questions? <a href="mailto:support@cashaadi.in">support@cashaadi.in</a></p>';
		$o .= '</div>';
		$out = $o;
		return $o;
	}

	/**
	 * "New prices from 1 November", the code and a countdown to this
	 * member's deadline. Signed-out visitors may get a cached page, so their
	 * deadline is worked out in the browser: 11:59 pm on the later of
	 * 5 November and three days from today (IST), as OldPrice does.
	 */
	private static function old_price_box( $end, $anon ) {
		$old = self::inr( OldPrice::OLD_PRICE );
		$min = ( new \DateTimeImmutable( OldPrice::MIN_END . ' 23:59:59', wp_timezone() ) )->getTimestamp();
		$o   = '<div class="csm-pr-op" data-end="' . ( $anon ? 0 : (int) $end ) . '" data-min="' . (int) $min . '" data-days="' . (int) OldPrice::DAYS . '"' . ( $anon ? '' : OldPrice::ev_attr( 'box' ) ) . '>';
		$o  .= '<strong class="csm-pr-op-h">New prices from 1 November</strong>';
		$o  .= '<p>We have updated our pricing from 1st November, since we have added a lot of features to our Premium plan. You can still get Premium at the old price of ' . esc_html( $old ) . ' for a limited time, with the code <b>OLDPRICE</b>.</p>';
		$o  .= '<p>After this offer ends, Premium will never be available at ' . esc_html( $old ) . ' again, even with discounts.</p>';
		$o  .= '<p class="csm-pr-op-cd">Offer ends in <span class="csm-pr-op-t">' . esc_html( human_time_diff( time(), $end ) ) . '</span><small class="csm-pr-op-d"></small></p>';
		$o  .= $anon
			? '<a class="csm-pr-buy" href="' . esc_url( home_url( '/register/' ) ) . '">Join and get Premium at ' . esc_html( $old ) . '</a>'
			: '<a class="csm-pr-buy" href="' . esc_url( OldPrice::buy_url() ) . '">Buy Premium at ' . esc_html( $old ) . '</a>';
		$o  .= '</div>';
		$o  .= '<script>(function(){var b=document.querySelector(".csm-pr-op");if(!b){return;}'
			. 'var IST=19800000,end=+b.dataset.end*1000;'
			. 'if(!end){var n=new Date(Date.now()+IST),d=Date.UTC(n.getUTCFullYear(),n.getUTCMonth(),n.getUTCDate()+(+b.dataset.days),23,59,59)-IST;end=Math.max(d,+b.dataset.min*1000);}'
			. 'var t=b.querySelector(".csm-pr-op-t"),dl=b.querySelector(".csm-pr-op-d");'
			. 'try{dl.textContent=" (on "+new Date(end).toLocaleDateString("en-IN",{timeZone:"Asia/Kolkata",day:"numeric",month:"long"})+", 11:59 pm)";}catch(e){}'
			. 'function p(x){return(x<10?"0":"")+x;}'
			. 'function tick(){var s=Math.max(0,Math.floor((end-Date.now())/1000));if(!s){b.style.display="none";return;}'
			. 't.textContent=Math.floor(s/86400)+"d "+p(Math.floor(s%86400/3600))+"h "+p(Math.floor(s%3600/60))+"m "+p(s%60)+"s";}'
			. 'tick();setInterval(tick,1000);' . OldPrice::ev_js() . 'ev(b,"shown");var a=b.querySelector(".csm-pr-buy");if(a){a.addEventListener("click",function(){ev(b,"click");});}})();</script>';
		return $o;
	}

	/** Before 1 November: the change, the reason, buy now, and the code to come. */
	private static function price_soon_box( $from, $anon ) {
		$old = self::inr( OldPrice::OLD_PRICE );
		$o   = '<div class="csm-pr-op" data-end="' . (int) $from . '"' . ( $anon ? '' : OldPrice::ev_attr( 'box' ) ) . '>';
		$o  .= '<strong class="csm-pr-op-h">New prices from 1 November</strong>';
		$o  .= '<p>We have updated our pricing from 1st November, since we have added a lot of features to our Premium plan. Premium will cost ' . esc_html( self::inr( OldPrice::NEW_PRICE ) ) . ' a year.</p>';
		$o  .= '<p>Until then, Premium is ' . esc_html( $old ) . '. Members who join before 1 November can also get this price with the code <b>OLDPRICE</b> from 1st to 5th November.</p>';
		$o  .= '<p>After that, Premium will never be available at ' . esc_html( $old ) . ' again, even with discounts.</p>';
		$o  .= '<p class="csm-pr-op-cd">Price goes up in <span class="csm-pr-op-t"></span></p>';
		$o  .= $anon
			? '<a class="csm-pr-buy" href="' . esc_url( home_url( '/register/' ) ) . '">Join and get Premium at ' . esc_html( $old ) . '</a>'
			: '<a class="csm-pr-buy" href="' . esc_url( home_url( '/checkout/?add-to-cart=' . (int) Config::WC_PREMIUM_PRODUCT ) ) . '">Buy Premium at ' . esc_html( $old ) . '</a>';
		$o  .= '</div>';
		$o  .= '<script>(function(){var b=document.querySelector(".csm-pr-op");if(!b){return;}var end=+b.dataset.end*1000,t=b.querySelector(".csm-pr-op-t");'
			. 'function p(x){return(x<10?"0":"")+x;}function tick(){var s=Math.max(0,Math.floor((end-Date.now())/1000));if(!s){b.style.display="none";return;}'
			. 't.textContent=Math.floor(s/86400)+"d "+p(Math.floor(s%86400/3600))+"h "+p(Math.floor(s%3600/60))+"m "+p(s%60)+"s";}'
			. 'tick();setInterval(tick,1000);' . OldPrice::ev_js() . 'ev(b,"shown");var a=b.querySelector(".csm-pr-buy");if(a){a.addEventListener("click",function(){ev(b,"click");});}})();</script>';
		return $o;
	}

	private static function buy( $product, $label, $anon ) {
		$url = $anon ? home_url( '/register/' ) : home_url( '/checkout/?add-to-cart=' . (int) $product );
		return '<a class="csm-pr-buy" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function css() {
		return '<style>
.csm-pr{max-width:980px;margin:0 auto;padding:8px 16px 32px;color:#16212b}
.csm-pr-h{font-size:30px;line-height:1.2;margin:8px 0 6px;text-align:center}
.csm-pr-sub{text-align:center;color:#5c6a76;margin:0 0 26px}
.csm-pr-cards{display:grid;gap:16px;grid-template-columns:1fr}
@media(min-width:760px){.csm-pr-cards.n3{grid-template-columns:repeat(3,1fr)}.csm-pr-cards.n2{grid-template-columns:repeat(2,1fr);max-width:660px;margin:0 auto}}
.csm-pr-card{position:relative;display:flex;flex-direction:column;padding:26px 20px 20px;border:1px solid #e4eaf0;border-radius:18px;background:#fff}
.csm-pr-card.is-premium.is-hl{border:2px solid #0b2350}
.csm-pr-card.is-gold{background:#fffaf0;border-color:#f0d58c}
.csm-pr-card.is-gold.is-hl{border:2px solid #c9a227}
.csm-pr-card h2{margin:0;font-size:22px}
.csm-pr-card.is-gold h2{color:#7a5410}
.csm-pr-price{margin:4px 0 14px;font-size:20px;font-weight:700}
.csm-pr-lead{margin:0 0 6px;font-size:13.5px;color:#5c6a76;font-weight:600}
.csm-pr-card ul{list-style:none;margin:0 0 18px;padding:0;flex:1}
.csm-pr-card li{position:relative;padding:6px 0 6px 24px;font-size:15px;line-height:1.35}
.csm-pr-card li:before{content:"✓";position:absolute;left:0;top:6px;color:#2e7d32;font-weight:700}
.csm-pr-card.is-gold li:before{color:#a87a1c}
.csm-pr-badge{position:absolute;left:50%;top:-12px;transform:translateX(-50%);white-space:nowrap;font-size:11.5px;font-weight:700;letter-spacing:.04em;padding:4px 12px;border-radius:999px;background:#0b2350;color:#fff}
.csm-pr-card.is-gold .csm-pr-badge{background:#3a2806;color:#ffe7a3}
.csm-pr-act{text-align:center}
.csm-pr-buy{display:block;padding:12px 20px;border-radius:999px;background:#0b2350;color:#fff!important;text-decoration:none;font-weight:700;text-align:center}
.csm-pr-card.is-gold .csm-pr-buy{background:#3a2806;color:#ffe7a3!important}
.csm-pr-cur{display:inline-block;padding:8px 14px;border-radius:999px;border:1px solid #c9d2dc;color:#5c6a76;font-size:13px}
.csm-pr-credit{margin:18px 0 0;padding:12px 14px;background:#fffaf0;border:1px solid #f0d58c;border-radius:10px;text-align:center}
.csm-pr-note{margin:-10px 0 12px;font-size:13.5px;color:#2e7d32;font-weight:600}
.csm-pr-op{margin:0 0 22px;padding:18px 20px;border:2px solid #0b2350;border-radius:16px;background:#f3f7fc;text-align:center}
.csm-pr-op-h{display:block;font-size:18px;margin-bottom:6px}
.csm-pr-op p{margin:0 0 8px;line-height:1.45}
.csm-pr-op-cd{font-weight:700;font-size:17px;color:#b3261e}
.csm-pr-op-cd small{display:block;font-weight:400;font-size:13px;color:#5c6a76}
.csm-pr-op .csm-pr-buy{display:inline-block;margin-top:4px}
.csm-pr-foot{margin-top:18px;text-align:center;color:#5c6a76;font-size:13px}
.csm-pr-boost{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:18px 0 0;padding:16px 18px;border:1px solid #e4eaf0;border-radius:14px;background:#fff}
.csm-pr-boost strong{display:block;font-size:16px}
.csm-pr-boost span{display:block;margin-top:3px;color:#5c6a76;font-size:13.5px;line-height:1.4}
.csm-pr-boost .csm-pr-buy{flex:0 0 auto;padding:10px 18px;background:#2c5b8c}
@media(max-width:520px){.csm-pr-boost{flex-direction:column;align-items:stretch;text-align:center}}
@media(max-width:600px){.csm-pr-h{font-size:24px}}
</style>';
	}
}
