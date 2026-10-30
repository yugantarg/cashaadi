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
	const SUBJECT = 'Our prices are changing. Get the old prices till 5 November';

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
			// Covers both plans (owner, v1.90.82: notices generic).
			'<p>From 1st November, our plans cost: Premium ₹4,499 a year, Gold ₹14,999 a year. We have added a lot of features, so we have updated our pricing.</p>'
			. '<p>You can still get the old prices, <strong>Premium ₹1,499</strong> and <strong>Gold ₹7,999</strong>. Use the code <strong>OLDPRICE</strong> when you buy, from 1st to 5th November.</p>'
			. '<p>After this offer ends, these prices will never be available again, even with discounts.</p>'
			. '<p>If you missed using this offer, email our support at <a href="mailto:support@cashaadi.in">support@cashaadi.in</a>. We may be able to activate the offer in certain cases.</p>'
			. '<p>With Premium you get:</p><ul style="padding-left:20px;margin:0 0 12px">' . $li . '</ul>'
			. '<p>Gold gives you everything in Premium, plus unlimited profiles and your requests shown first.</p>'
			. '<p>One payment for the whole year. It does not renew automatically.</p>',
			home_url( '/membership-pricing/?src=price-email' ),
			'See plans at the old prices'
		);
	}

	/**
	 * Rewrite emails not yet sent (held or waiting) with the current wording
	 * (v1.90.82): their text was stored when they were staged.
	 */
	public static function refresh_unsent() {
		global $wpdb;
		$t = Queue::table();
		$n = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id FROM {$t} WHERE email_type = %s AND status IN ( 'held', 'pending', 'deferred', 'waiting' )", self::TYPE ) ) as $r ) {
			$n += (int) $wpdb->update( $t, array( 'subject' => self::SUBJECT, 'body' => self::body( (int) $r->user_id ) ), array( 'id' => (int) $r->id ) );
		}
		return $n;
	}

	/** Writes HELD rows; sends nothing until released on the campaigns screen. */
	public static function stage() {
		self::refresh_unsent();
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
