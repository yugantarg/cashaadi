<?php
/**
 * Conversion events.
 *
 * A client-rendered onboarding flow fires no page loads, so every conversion
 * has to be sent explicitly — the moment /welcome/ replaced the old wizard, any
 * pageview-based conversion silently went to zero. This class decides WHICH
 * events a member still owes and hands that list to the browser; tracking.js
 * does the talking to Google and Meta.
 *
 * EXACTLY-ONCE IS THE WHOLE POINT
 * A conversion counted twice is worse than one missed: Google Ads bids against
 * that number, so inflation quietly wastes money on every subsequent auction.
 * claim() is therefore the only way an event is ever emitted — it checks a
 * per-member, per-event meta flag and sets it in the same call, so a refresh, a
 * back button, a second device or a double-submitted form cannot produce a
 * second copy.
 *
 * THE TRADE-OFF, STATED PLAINLY
 * claim() marks the event when the page is BUILT, not when the browser confirms
 * it fired. So an ad blocker, a crashed tab or a closed laptop loses that event
 * permanently. The alternative — mark on confirmation — risks double counting
 * whenever the confirmation is retried. Given the cost asymmetry above, losing
 * one is the right side to err on, and the server-side APIs (GA4 Measurement
 * Protocol, Meta Conversions API) are how those blocked events get recovered
 * once the owner supplies the keys.
 */

namespace CAShaadi\Modules\Tracking;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Events {

	/** Fired once when the account is activated — the Google Ads conversion. */
	const SIGNUP = 'signup';

	/** Fired once when a member first reaches onboarding. */
	const ONBOARDING_START = 'onboarding_start';

	/** Fired once when onboarding is finished. */
	const ONBOARDING_DONE = 'onboarding_complete';

	private static function meta_key( $event ) {
		return 'csm_fired_' . preg_replace( '/[^a-z_]/', '', (string) $event );
	}

	/**
	 * Claim an event for this member.
	 *
	 * @return bool True exactly once per member per event, false every time after.
	 */
	public static function claim( $uid, $event ) {
		$uid = (int) $uid;
		if ( ! $uid ) {
			return false;
		}
		if ( ! self::enabled() ) {
			return false;
		}

		$key = self::meta_key( $event );
		if ( get_user_meta( $uid, $key, true ) ) {
			return false;
		}

		/*
		 * add_user_meta with $unique = true is the atomic part: two concurrent
		 * requests (a double-tapped button, a retried POST) cannot both succeed,
		 * so only one of them is told to fire.
		 */
		$added = add_user_meta( $uid, $key, time(), true );
		return false !== $added;
	}

	/** Has tracking been switched on, with something to send to? */
	public static function enabled() {
		if ( ! class_exists( '\CAShaadi\Modules\Tracking\TrackingSettings' ) ) {
			return false;
		}
		$all = TrackingSettings::all();
		if ( empty( $all['enabled'] ) ) {
			return false;
		}
		return '' !== self::gads_id() || '' !== self::ga4_id() || '' !== self::meta_pixel();
	}

	/* ---- credentials: screen first, then the long-standing constants ---- */

	public static function gads_id() {
		$v = TrackingSettings::get( 'gads_id' );
		return '' !== $v ? $v : (string) Config::GADS_CONVERSION_ID;
	}

	public static function gads_label() {
		$v = TrackingSettings::get( 'gads_label' );
		return '' !== $v ? $v : (string) Config::GADS_LEAD_LABEL;
	}

	public static function ga4_id() {
		return TrackingSettings::get( 'ga4_id' );
	}

	public static function meta_pixel() {
		return TrackingSettings::get( 'meta_pixel_id' );
	}

	/**
	 * Everything tracking.js needs, including the events this member still owes.
	 *
	 * @param array $claim Event names to claim now (each returned only if unclaimed).
	 */
	public static function config( $uid, $claim = array() ) {
		if ( ! self::enabled() ) {
			// Explicitly off rather than absent, so the JS can say so in the console
			// instead of failing silently and looking like a bug.
			return array( 'enabled' => false, 'pending' => array() );
		}

		$pending = array();
		foreach ( (array) $claim as $event ) {
			if ( self::claim( $uid, $event ) ) {
				$pending[] = $event;
			}
		}

		return array(
			'enabled'   => true,
			'gadsId'    => self::gads_id(),
			'gadsLabel' => self::gads_label(),
			'ga4Id'     => self::ga4_id(),
			'metaPixel' => self::meta_pixel(),
			'pending'   => $pending,
		);
	}
}
