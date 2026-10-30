<?php
/**
 * Premium goes from ₹1,499 to ₹4,499 on 1 November 2026, with a last chance
 * at the old price (owner, 2026-10-30).
 *
 * - From FROM, the Premium product sells at NEW_PRICE (a price filter, so it
 *   switches at midnight without anyone touching WooCommerce).
 * - The code OLDPRICE takes Premium back to ₹1,499 (₹3,000 off Premium only,
 *   once per member). It is refused before FROM, when it would make Premium
 *   free, and after the member's own deadline.
 * - Each member's deadline: 11:59 pm (IST) on the later of MIN_END and the
 *   third day after they first saw the offer. So everyone gets at least three
 *   days, and nobody's ends before 5 November. The first sight is stamped on
 *   the pricing page (or at checkout, whichever comes first).
 * - The pricing page says what changed and shows the code with a countdown to
 *   that deadline (PricingPage). Visitors who are not signed in see the
 *   deadline they would get if they signed up today.
 * - Only for members who joined before 1 November; not shown to signed-out
 *   visitors (v1.90.59).
 * - csm_oldprice_open (default 1): set to 0 to stop giving new deadlines.
 *   Members already stamped keep theirs; anyone not stamped by then has until
 *   MIN_END at most.
 *
 * Opening the price email (PriceEmail) also stamps it, and a later open
 * moves it later, never earlier.
 *
 * Admins can see the banner before 1 November with ?oldprice=preview.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OldPrice {

	const CODE       = 'oldprice';
	const OLD_PRICE  = 1499;
	const NEW_PRICE  = 4499;
	const FROM       = '2026-11-01 00:00:00';
	const MIN_END    = '2026-11-05';
	const DAYS       = 3;
	const FIRST_META = 'csm_op_first';
	const OPEN_OPT   = 'csm_oldprice_open';

	public static function register() {
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'price' ), 20, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_valid' ), 20, 2 );
		// "Buy Premium at ₹1,499" adds Premium with the code already applied.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'apply_from_link' ), 30, 2 );
		add_action( 'csm_email_opened', array( __CLASS__, 'on_email_open' ) );
		// Cached pages still show the old price after midnight: purge at the switch.
		add_action( 'csm_oldprice_go', array( __CLASS__, 'purge' ) );
		if ( ! self::started() && ! wp_next_scheduled( 'csm_oldprice_go' ) ) {
			wp_schedule_single_event( self::ts( self::FROM ) + 60, 'csm_oldprice_go' );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm oldprice', array( __CLASS__, 'cli' ), array(
				'synopsis' => array(
					array( 'type' => 'positional', 'name' => 'action', 'optional' => true ),
					array( 'type' => 'positional', 'name' => 'email', 'optional' => true ),
				),
			) );
		}
	}

	private static function ts( $local ) {
		return ( new \DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
	}

	public static function purge() {
		do_action( 'litespeed_purge_all' );
	}

	/** Has the new price started? */
	public static function started() {
		return time() >= self::ts( self::FROM );
	}

	public static function price( $price, $product ) {
		if ( self::started() && $product && (int) $product->get_id() === (int) Config::WC_PREMIUM_PRODUCT ) {
			return (string) self::NEW_PRICE;
		}
		return $price;
	}

	/** 11:59:59 pm IST on the later of MIN_END and three days after $from. */
	public static function deadline_from( $from ) {
		$tz   = wp_timezone();
		$day  = ( new \DateTimeImmutable( '@' . (int) $from ) )->setTimezone( $tz )->modify( '+' . self::DAYS . ' days' )->format( 'Y-m-d' );
		$day  = max( $day, self::MIN_END );
		return self::ts( $day . ' 23:59:59' );
	}

	/**
	 * This member's deadline (0 = no offer). $stamp: record now as their first
	 * sight if there is none yet (only once the new price has started).
	 */
	public static function deadline( $uid, $stamp = false ) {
		$uid   = (int) $uid;
		$first = $uid ? (int) get_user_meta( $uid, self::FIRST_META, true ) : 0;
		if ( ! $first ) {
			if ( ! get_option( self::OPEN_OPT, 1 ) ) {
				return self::ts( self::MIN_END . ' 23:59:59' );
			}
			if ( ! $uid ) {
				return self::deadline_from( max( time(), self::ts( self::FROM ) ) );
			}
			$first = max( time(), self::ts( self::FROM ) );
			if ( $stamp && self::started() ) {
				update_user_meta( $uid, self::FIRST_META, $first );
			}
		}
		return self::deadline_from( $first );
	}

	/** Can this member (or a visitor) still get the old price, and should they be told? */
	public static function offer_for( $uid ) {
		// An admin previewing is usually on a paid plan: show it anyway, stamp nothing.
		if ( self::preview() ) {
			return max( self::deadline( $uid ), time() + DAY_IN_SECONDS );
		}
		if ( ! self::eligible( $uid ) || Membership::is_premium( $uid ) || Membership::is_gold( $uid ) ) {
			return 0;
		}
		$end = self::deadline( $uid, true );
		return $end > time() ? $end : 0;
	}

	/**
	 * Only members who joined before 1 November (owner, v1.90.59): the old
	 * price is for people who saw it. Visitors who are not signed in would
	 * join after it, so they are not offered it either.
	 */
	public static function eligible( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return $u && strtotime( $u->user_registered . ' UTC' ) < self::ts( self::FROM );
	}

	public static function preview() {
		return isset( $_GET['oldprice'] ) && 'preview' === $_GET['oldprice'] && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function coupon_valid( $valid, $coupon ) {
		if ( ! $coupon || self::CODE !== strtolower( (string) $coupon->get_code() ) ) {
			return $valid;
		}
		if ( ! self::started() ) {
			throw new \Exception( 'This code works from 1 November.', 100 );
		}
		$uid = get_current_user_id();
		if ( ! self::eligible( $uid ) || self::deadline( $uid, true ) < time() ) {
			throw new \Exception( 'This offer has ended. Premium is no longer available at the old price.', 100 );
		}
		return $valid;
	}

	public static function apply_from_link( $key, $product_id ) {
		if ( (int) $product_id !== (int) Config::WC_PREMIUM_PRODUCT || empty( $_GET['oldprice'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( self::started() && self::offer_for( get_current_user_id() ) && ! WC()->cart->has_discount( self::CODE ) ) {
			WC()->cart->apply_coupon( self::CODE );
		}
	}

	/**
	 * Opening the price email starts (or restarts, if later) the member's
	 * days (owner: "the count starts from point of opening email").
	 */
	public static function on_email_open( $row ) {
		if ( ! is_object( $row ) || \CAShaadi\Modules\Emails\PriceEmail::TYPE !== $row->email_type ) {
			return;
		}
		$uid = (int) $row->user_id;
		if ( $uid && get_option( self::OPEN_OPT, 1 ) ) {
			update_user_meta( $uid, self::FIRST_META, max( time(), (int) get_user_meta( $uid, self::FIRST_META, true ) ) );
		}
	}

	/**
	 * A banner above Discover for members who can still get the old price
	 * (owner, v1.90.62). Before 1 November it counts down to the price change;
	 * from then, to the member's own deadline, with the code. A close button
	 * hides it until the next day.
	 */
	public static function discover_banner( $uid ) {
		$end = self::offer_for( $uid );
		if ( ! $end || user_can( (int) $uid, 'manage_options' ) && ! self::preview() ) {
			return '';
		}
		$live = self::started();
		$to   = $live ? $end : self::ts( self::FROM );
		$old  = '₹' . number_format_i18n( self::OLD_PRICE );
		$new  = '₹' . number_format_i18n( self::NEW_PRICE );
		$text = $live
			? 'Premium is now ' . $new . '. You can still get it at ' . $old . ' with code OLDPRICE.'
			: 'Premium price goes up to ' . $new . ' on 1 November. Get it now at ' . $old . '.';
		$lead = $live ? 'Offer ends in ' : 'Price goes up in ';
		$url  = $live ? self::buy_url() : home_url( '/membership-pricing/?src=oldprice-banner' . ( self::preview() ? '&oldprice=soon' : '' ) );
		return '<div class="csm-opb" data-end="' . (int) $to . '">'
			. '<button type="button" class="csm-opb-x" aria-label="Close">×</button>'
			. '<p class="csm-opb-t">' . esc_html( $text ) . '</p>'
			. '<p class="csm-opb-cd">' . esc_html( $lead ) . '<span></span></p>'
			. '<a class="csm-opb-btn" href="' . esc_url( $url ) . '">Get Premium at ' . esc_html( $old ) . '</a>'
			. '</div>'
			. '<style>.csm-opb{position:relative;margin:10px 12px 0;padding:12px 40px 12px 14px;border-radius:14px;background:#0b2350;color:#fff;font-size:14px;line-height:1.4}'
			. '.csm-opb p{margin:0 0 6px}.csm-opb-cd{font-weight:700;color:#ffd27a}'
			. '.csm-opb-btn{display:inline-block;padding:8px 16px;border-radius:999px;background:#fff;color:#0b2350!important;font-weight:700;text-decoration:none}'
			. '.csm-opb-x{position:absolute;top:6px;right:8px;border:0;background:none;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:4px}</style>'
			. '<script>(function(){var b=document.querySelector(".csm-opb");if(!b){return;}var k="csm_opb_hide",d=new Date().toDateString();'
			. 'try{if(localStorage.getItem(k)===d){b.remove();return;}}catch(e){}'
			. 'b.querySelector(".csm-opb-x").onclick=function(){try{localStorage.setItem(k,d);}catch(e){}b.remove();};'
			. 'var end=+b.dataset.end*1000,t=b.querySelector(".csm-opb-cd span");function p(x){return(x<10?"0":"")+x;}'
			. 'function tick(){var s=Math.max(0,Math.floor((end-Date.now())/1000));if(!s){b.remove();return;}'
			. 't.textContent=Math.floor(s/86400)+"d "+p(Math.floor(s%86400/3600))+"h "+p(Math.floor(s%3600/60))+"m "+p(s%60)+"s";}'
			. 'tick();setInterval(tick,1000);})();</script>';
	}

	public static function buy_url() {
		return home_url( '/checkout/?add-to-cart=' . (int) Config::WC_PREMIUM_PRODUCT . '&oldprice=1' );
	}

	/**
	 * wp csm oldprice setup    create the OLDPRICE coupon (once)
	 * wp csm oldprice status   how many saw it, used it, and deadlines
	 * wp csm oldprice extend <email>   give a member 3 more days from now (support)
	 *
	 * ## OPTIONS
	 * <action>
	 * [<email>]
	 */
	public static function cli( $args ) {
		$action = $args[0] ?? 'status';
		if ( 'extend' === $action ) {
			$u = isset( $args[1] ) ? get_user_by( 'email', $args[1] ) : null;
			if ( ! $u ) {
				\WP_CLI::error( 'No member with that email.' );
			}
			update_user_meta( $u->ID, self::FIRST_META, time() );
			\WP_CLI::success( $u->user_email . ' can use OLDPRICE until ' . wp_date( 'j F, g:i a', self::deadline_from( time() ) ) . '.' );
			return;
		}
		if ( 'setup' === $action ) {
			if ( ! class_exists( '\WC_Coupon' ) ) {
				\WP_CLI::error( 'WooCommerce is not active.' );
			}
			$c = new \WC_Coupon( self::CODE );
			if ( $c->get_id() ) {
				\WP_CLI::success( 'OLDPRICE already exists (coupon ' . $c->get_id() . ').' );
				return;
			}
			$c = new \WC_Coupon();
			$c->set_code( self::CODE );
			$c->set_description( 'Premium at the old price (₹' . self::OLD_PRICE . '). Deadlines per member: OldPrice.php.' );
			$c->set_discount_type( 'fixed_product' );
			$c->set_amount( self::NEW_PRICE - self::OLD_PRICE );
			$c->set_product_ids( array( (int) Config::WC_PREMIUM_PRODUCT ) );
			$c->set_individual_use( true );
			$c->set_usage_limit_per_user( 1 );
			$c->save();
			\WP_CLI::success( 'Created OLDPRICE (coupon ' . $c->get_id() . '): ₹' . ( self::NEW_PRICE - self::OLD_PRICE ) . ' off Premium, once per member.' );
			return;
		}
		global $wpdb;
		$seen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", self::FIRST_META ) );
		$c    = class_exists( '\WC_Coupon' ) ? new \WC_Coupon( self::CODE ) : null;
		\WP_CLI::log( 'New price ' . ( self::started() ? 'live' : 'starts ' . self::FROM ) . ' · new deadlines ' . ( get_option( self::OPEN_OPT, 1 ) ? 'on' : 'off' ) . " · {$seen} members have seen the offer · OLDPRICE used " . ( $c && $c->get_id() ? (int) $c->get_usage_count() : 0 ) . ' times.' );
	}
}
