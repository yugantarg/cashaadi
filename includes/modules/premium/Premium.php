<?php
/**
 * Premium module.
 *
 * The premium snippets inject buttons/gates, so running them alongside the
 * active WPCode versions would double them. The whole module is therefore gated
 * behind Config::premium_enabled() (off unless wp-config sets
 * CASHAADI_PREMIUM_ENABLED = true) — deploying it changes nothing until a
 * coordinated cutover (flip the flag + disable the migrated premium snippets).
 *
 * This first increment migrates:
 *   #11579 Upgrade to Premium button (own profile + members directory)
 *
 * Still to migrate here (later, still behind this flag): #11795 checkout
 * hygiene, #11620 profile gate, #11614 contact gate, #11807/#11811/#11821, etc.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premium {

	public static function register() {
		if ( ! Config::premium_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Upgrade button (#11579): on the member's own profile header and the
		// members directory.
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'upgrade_on_profile' ) );
		add_action( 'bp_before_directory_members_content', array( __CLASS__, 'upgrade_on_directory' ) );
	}

	public static function upgrade_on_profile() {
		if ( function_exists( 'bp_displayed_user_id' ) && function_exists( 'bp_loggedin_user_id' )
			&& bp_displayed_user_id() === bp_loggedin_user_id() ) {
			self::upgrade_button( 'profile' );
		}
	}

	public static function upgrade_on_directory() {
		self::upgrade_button( 'directory' );
	}

	/**
	 * Render an Upgrade-to-Premium button for non-premium users. On the member's
	 * own profile, if the profile is still incomplete, show a single
	 * "complete your profile" CTA instead (mirrors #11579). Styles live in
	 * assets/css/site.css.
	 */
	private static function upgrade_button( $context ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Incomplete profile → single "complete profile" CTA (own profile only).
		if ( 'profile' === $context
			&& function_exists( 'cashaadi_has_missing_required_fields' )
			&& cashaadi_has_missing_required_fields( get_current_user_id() ) ) {
			$edit = function_exists( 'bp_loggedin_user_url' )
				? trailingslashit( bp_loggedin_user_url() . 'profile/edit' )
				: home_url( '/' );
			printf(
				'<div class="cashaadi-complete-profile-wrap"><a class="cashaadi-complete-profile-btn" href="%s">Complete your profile to browse other profiles &rarr;</a></div>',
				esc_url( $edit )
			);
			return;
		}

		if ( Membership::is_premium() ) {
			return; // already premium — no upsell
		}

		printf(
			'<div class="cashaadi-upgrade-wrap"><a class="cashaadi-upgrade-btn" href="%s">Upgrade to Premium</a></div>',
			esc_url( home_url( '/membership-pricing/' ) )
		);
	}
}
