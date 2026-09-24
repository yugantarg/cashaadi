<?php
/**
 * "You can now add LinkedIn and Instagram" — once, for existing members.
 *
 * Owner, 2026-09-24: no email for this; members who joined after the fields
 * existed see them in the wizard, and everyone who joined before gets a small
 * popup, once, pointing at their profile.
 *
 * Eligible: a member (not an admin) who registered before the launch stamp,
 * has neither field filled, and has not seen it. The page marks it seen the
 * moment it is shown — shown once, not once per dismissal — so a member who
 * ignores it is never asked again.
 */

namespace CAShaadi\Modules\ProfileEdit;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SocialIntro {

	const SEEN_META  = 'csm_social_intro_seen';
	const LAUNCH_OPT = 'csm_social_launch';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		// The launch moment, recorded once: everyone registered before it is "existing".
		if ( ! get_option( self::LAUNCH_OPT ) ) {
			add_option( self::LAUNCH_OPT, current_time( 'mysql', true ), '', false );
		}
	}

	/** What the app shell hands the page: a destination, or false. */
	public static function payload( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		if ( get_user_meta( $uid, self::SEEN_META, true ) ) {
			return false;
		}
		$launch = (string) get_option( self::LAUNCH_OPT );
		$u      = get_userdata( $uid );
		if ( ! $u || '' === $launch || $u->user_registered >= $launch ) {
			return false; // joined after launch: the wizard asked them
		}
		if ( class_exists( 'BP_XProfile_ProfileData' ) ) {
			$li = trim( (string) \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_LINKEDIN, $uid ) );
			$ig = trim( (string) \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_INSTAGRAM, $uid ) );
			if ( '' !== $li || '' !== $ig ) {
				return false; // already found them
			}
		}
		return array(
			'url'  => ProfileEditScreen::url( 1 ), // Basic Details, where both sit under Bio
			'seen' => rest_url( 'csm/v1/social-intro' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/social-intro', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_seen' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_seen( $request ) {
		unset( $request );
		update_user_meta( get_current_user_id(), self::SEEN_META, time() );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
