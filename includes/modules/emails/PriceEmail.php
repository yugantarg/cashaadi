<?php
/**
 * The Premium price notice (owner, 2026-10-30): staged from the Email
 * campaigns screen and released by hand, like the other campaigns.
 *
 * Audience: activated members who are not Premium or Gold (everyone today
 * joined before 1 November, so all can use OLDPRICE).
 *
 * Opening it counts as first seeing the offer (OldPrice listens to
 * csm_email_opened): opened by 2 November, the code runs to 5 November;
 * opened later, to 11:59 pm three days after the open.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceEmail {

	const TYPE    = 'csm-price-nov26';
	const SUBJECT = 'Premium price is changing. Get the old price till 5 November';

	public static function audience() {
		$out = array();
		foreach ( Announcement::audience() as $uid ) {
			if ( user_can( $uid, 'manage_options' ) || Membership::is_premium( $uid ) || Membership::is_gold( $uid ) ) {
				continue;
			}
			$out[] = (int) $uid;
		}
		return $out;
	}

	public static function body( $uid ) {
		$female = function_exists( 'cashaadi' ) && 'Female' === trim( (string) cashaadi()->get_gender( (int) $uid ) );
		$items  = array(
			$female ? '50 profiles a week instead of 15' : '10 profiles a week instead of 5',
			'Profiles never expire: undecided ones add up week after week',
			'1 profile boost a month: 24 hours at the front of Discover',
			'See who viewed your profile, and send them a request',
			'See blurred photos',
			'Add a personal note to your requests',
			'Advanced filters',
		);
		if ( $female ) {
			$items[] = 'Call or WhatsApp any member directly';
		}
		$li = '';
		foreach ( $items as $it ) {
			$li .= '<li>' . esc_html( $it ) . '</li>';
		}
		return Engagement::wrap(
			Engagement::greeting( $uid ),
			'<p>From 1st November, Premium on CA Shaadi costs ₹4,499 a year. We have added a lot of features to Premium, so we have updated our pricing.</p>'
			. '<p>You can still get Premium at the old price of <strong>₹1,499</strong>. Use the code <strong>OLDPRICE</strong> when you buy, from 1st to 5th November.</p>'
			. '<p>If you are reading this after 2nd November, you still get 3 full days from when you open this email.</p>'
			. '<p>After that, Premium will never be available at ₹1,499 again, even with discounts.</p>'
			. '<p>With Premium you get:</p><ul style="padding-left:20px;margin:0 0 12px">' . $li . '</ul>'
			. '<p>One payment for the whole year. It does not renew automatically.</p>',
			home_url( '/membership-pricing/?src=price-email' ),
			'Get Premium at ₹1,499'
		);
	}

	/** Writes HELD rows; sends nothing until released on the campaigns screen. */
	public static function stage() {
		$audience = self::audience();
		$staged   = 0;
		$skipped  = 0;
		foreach ( $audience as $uid ) {
			if ( Queue::stage( $uid, self::TYPE, self::SUBJECT, self::body( $uid ) ) ) {
				$staged++;
			} else {
				$skipped++;
			}
		}
		return array( 'audience' => count( $audience ), 'staged' => $staged, 'skipped' => $skipped );
	}
}
