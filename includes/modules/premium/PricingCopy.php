<?php
/**
 * The pricing page, as a logged-in woman sees it.
 *
 * Owner, 2026-09-22: "The premium page should change highlighting 50 profiles
 * per week and ability to filter. Only for logged in female users. Logged out
 * and males continue to see old one."
 *
 * Done as a filter on the rendered content rather than a second page, so there
 * is one page to edit and one URL to link; the swap is a set of exact string
 * replacements against the copy that is actually there, and any that no longer
 * match are skipped rather than guessed at. Gated on gender only — a FREE woman
 * is exactly who this is meant to persuade.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Modules\Discover\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PricingCopy {

	/** The pricing page, by slug, so a rebuilt page keeps working. */
	const SLUG = 'membership-pricing';

	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'rewrite' ), 20 );
	}

	/** Logged in AND female. Membership is deliberately not consulted. */
	public static function applies() {
		if ( ! is_user_logged_in() || ! function_exists( 'cashaadi' ) ) {
			return false;
		}
		return 'Female' === trim( (string) cashaadi()->get_gender( get_current_user_id() ) );
	}

	public static function quota() {
		return class_exists( '\\CAShaadi\\Modules\\Discover\\Filters' )
			? Filters::QUOTA_PREMIUM_FEMALE
			: 50;
	}

	public static function rewrite( $html ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $html;
		}
		if ( ! is_page( self::SLUG ) || ! self::applies() ) {
			return $html;
		}

		$n     = (int) self::quota();
		$spans = class_exists( '\\CAShaadi\\Modules\\Discover\\Filters' )
			? array( Filters::AGE_SPAN_MIN, Filters::IN_SPAN_MIN )
			: array( 5, 5 );

		$swaps = array(
			// The intro promise.
			'Upgrade to Premium to see twice as many profiles each week, discover who viewed your profile, and view members’ private photos.'
				=> sprintf(
					'Upgrade to Premium to see up to %d profiles a week — filtered to the age and height you are looking for — discover who viewed your profile, and view members’ private photos.',
					$n
				),
			// The headline feature in the Premium column.
			'<li>10 new profiles every week in Discover (double the free 5)</li>'
				=> sprintf(
					'<li><strong>Up to %d new profiles every week</strong> in Discover — ten times the free 5</li>'
					. '<li><strong>Filter by age and height</strong>, so your %d profiles are the ones you actually want to see'
					. ' <em>(ranges of at least %d years and %d inches)</em></li>',
					$n,
					$n,
					$spans[0],
					$spans[1]
				),
		);

		foreach ( $swaps as $from => $to ) {
			if ( false !== strpos( $html, $from ) ) {
				$html = str_replace( $from, $to, $html );
			}
		}

		return $html;
	}
}
