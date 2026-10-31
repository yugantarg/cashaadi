<?php
/**
 * Unfinished payment email (owner, 2026-10-31: "we don't have abandoned cart
 * emails?"). Of 18 people with a failed or cancelled order in 30 days, 6 never
 * came back to pay.
 *
 * Once an hour: a member whose order has sat unpaid (pending, failed or
 * cancelled) for 1 to 48 hours, who has not paid for anything since and is
 * not on the plan they were buying, gets one email. Once per member ever
 * (the queue's unique key), never in quiet hours, never to admins.
 *
 * The email names what they were buying, the price they will pay (OLDPRICE
 * while it runs for them), links back to the pricing page, and says what to
 * do if the money left their account but nothing changed (Ritesh, #18625).
 * Its opens, clicks and payments within 7 days show in `wp csm emails`.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CheckoutNudge {

	const TYPE = 'csm-checkout-nudge';
	const HOOK = 'csm_checkout_nudge';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::HOOK );
		}
	}

	public static function run() {
		if ( ! function_exists( 'wc_get_orders' ) || Queue::in_quiet_hours() ) {
			return;
		}
		$orders = wc_get_orders( array(
			'status'       => array( 'pending', 'failed', 'cancelled' ),
			'date_created' => ( time() - 48 * HOUR_IN_SECONDS ) . '...' . ( time() - HOUR_IN_SECONDS ),
			'limit'        => 200,
		) );
		$done = array();
		foreach ( $orders as $o ) {
			$uid = (int) $o->get_customer_id();
			if ( ! $uid || isset( $done[ $uid ] ) || user_can( $uid, 'manage_options' ) ) {
				continue;
			}
			$done[ $uid ] = 1;
			$paid = wc_get_orders( array( 'customer_id' => $uid, 'status' => array( 'processing', 'completed' ), 'date_created' => '>' . $o->get_date_created()->getTimestamp(), 'limit' => 1 ) );
			if ( $paid ) {
				continue;
			}
			$what = self::what( $o );
			if ( '' === $what || ( 'Gold' === $what && Membership::is_gold( $uid ) ) || ( 'Premium' === $what && Membership::is_premium( $uid ) ) ) {
				continue;
			}
			Queue::notify( $uid, self::TYPE, self::subject( $what ), self::body( $uid, $what, $o ) );
		}
	}

	/** 'Gold', 'Premium' or 'Boosts', from the order's items. */
	private static function what( $o ) {
		foreach ( $o->get_items() as $it ) {
			$n = strtolower( $it->get_name() );
			if ( false !== strpos( $n, 'gold' ) ) {
				return 'Gold';
			}
			if ( false !== strpos( $n, 'premium' ) ) {
				return 'Premium';
			}
			if ( false !== strpos( $n, 'boost' ) ) {
				return 'Boosts';
			}
		}
		return '';
	}

	public static function subject( $what ) {
		return 'Boosts' === $what ? 'Your profile boosts are waiting' : "Your CA Shaadi {$what} is waiting";
	}

	public static function body( $uid, $what, $o ) {
		$offer = 'Boosts' !== $what && class_exists( '\CAShaadi\Modules\Premium\OldPrice' ) ? \CAShaadi\Modules\Premium\OldPrice::offer_for( $uid ) : 0;
		$price = '';
		if ( $offer ) {
			$old   = 'Gold' === $what ? '₹7,999' : '₹1,499';
			$price = '<p>You can still get it at the old price of <strong>' . $old . '</strong> with the code <strong>OLDPRICE</strong>, until ' . esc_html( wp_date( 'j F, g:i a', $offer ) ) . '.</p>';
		}
		$html = '<p>You started getting ' . ( 'Boosts' === $what ? 'profile boosts' : 'CA Shaadi ' . esc_html( $what ) ) . ' but the payment was not completed.</p>'
			. $price
			. '<p>It takes a minute to finish. If something went wrong with the payment, just try again; the card, UPI or net banking options are all there.</p>'
			. '<p>If the money has already left your account but your plan has not changed, reply to this email with the date and amount and we will sort it out.</p>';
		return Engagement::wrap( Engagement::greeting( $uid ), $html, home_url( '/membership-pricing/?src=checkout-nudge' ), 'Boosts' === $what ? 'Get boosts' : 'Get ' . $what );
	}
}
