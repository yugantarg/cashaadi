<?php
/**
 * Profile — "the profile section shows my profile".
 *
 * The member's own hub: who they are, what is still missing, and the way in to
 * every editor. Rendered as its own document via Core\AppPage, like Discover and
 * Requests.
 *
 * WHY IT IS NOT A READ-ONLY VIEW OF THE PROFILE
 * BuddyPress's own member page already shows a profile; putting a second copy
 * here would be duplication, and it is not what this tab is for. On a matrimonial
 * app the thing a member needs from their own profile tab is "what do I still
 * need to do to get matches" — so completion leads, and a link to see the public
 * version sits alongside it rather than replacing it.
 *
 * Completion comes from Core\Profile::completion(), shared with the older
 * BuddyPress-page renderer, so the headline count and the per-row counts cannot
 * drift apart.
 */

namespace CAShaadi\Modules\ProfileScreen;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Profile;
use CAShaadi\Core\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileApp {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* -------------------------------------------------------------- route */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'profile' ) ) {
			return;
		}

		AppPage::assets();
		Assets::style( 'profile-app', 'assets/css/profile-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'profile-app', 'assets/js/profile-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-profile-app', 'CSM_PROFILE', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'me'    => rest_url( 'csm/v1/profile/me' ),
		) );

		AppPage::open( __( 'Profile', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-profile-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/profile/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_me' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_me( $request ) {
		unset( $request );
		$uid = get_current_user_id();

		$base = function_exists( 'bp_members_get_user_url' )
			? trailingslashit( bp_members_get_user_url( $uid ) )
			: home_url( '/' );

		$completion = Profile::completion( $uid );

		$sections = array();
		foreach ( $completion['groups'] as $g ) {
			$sections[] = array(
				'id'      => $g['id'],
				'name'    => $g['name'],
				'missing' => $g['missing'],
				// Our own editor, not BuddyPress's — see ProfileEdit\ProfileEditScreen.
				'url'     => \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url( $g['id'] ),
			);
		}

		/*
		 * Read own fields WITHOUT the visibility filter.
		 *
		 * Core\Profile::field() hides anything the viewer may not see — correct
		 * when looking at someone else, wrong here: a member who set their City to
		 * "Only Me" would be shown a blank City on their own profile and reasonably
		 * conclude the site had lost it.
		 */
		$age  = Profile::age_number( (string) xprofile_get_field_data( 'Age', $uid ) );
		$city = trim( (string) xprofile_get_field_data( 'City', $uid ) );

		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( ! $name ) {
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : __( 'Member', 'cashaadi-ui' );
		}

		$has_photo = class_exists( '\CAShaadi\Modules\Onboarding\PhotoOptions' )
			&& \CAShaadi\Modules\Onboarding\PhotoOptions::has_photo( $uid );

		return new \WP_REST_Response( array(
			'ok'          => true,
			'name'        => $name,
			'age'         => $age,
			'city'        => $city,
			'avatar'      => function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'full', 'html' => false ) )
				: get_avatar_url( $uid, array( 'size' => 300 ) ),
			'hasPhoto'    => (bool) $has_photo,
			'blurred'     => '1' === (string) get_user_meta( $uid, 'csm_photo_private', true ),
			'verified'    => method_exists( '\CAShaadi\Core\Verification', 'ca_verified' ) && Verification::ca_verified( $uid ),
			'isPremium'   => class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid ),
			'outstanding' => (int) $completion['outstanding'],
			'firstGap'    => $completion['firstGap']
				? \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url( (int) $completion['firstGap'] )
				: '',
			'sections'    => $sections,
			'links'       => array(
				'public'   => $base,
				'photos'   => $base . 'profile/change-avatar/',
				'settings' => class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' )
					? \CAShaadi\Modules\Settings\SettingsScreen::url()
					: $base . 'settings/',
				'upgrade'  => site_url( '/membership-pricing/' ),
			),
		), 200 );
	}
}
