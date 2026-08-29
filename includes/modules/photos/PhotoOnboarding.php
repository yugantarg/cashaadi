<?php
/**
 * Photos on the onboarding / avatar screen (migrated from WPCode #11838) plus the
 * "Next: Fill your profile" step button (migrated from WPCode #11690).
 *
 * Makes the Change Profile Photo step feel like Step 1 of the edit wizard: a
 * "Step 1 of N" bar + the blue section nav (.button-tabs .button-nav), then the
 * friction-light multi-photo uploader (Gallery). The classic single-photo
 * BuddyPress tool is hidden via CSS (assets/css/photos-gallery.css); the separate
 * Cover Image feature is retired. A non-blocking Next button continues the flow
 * to the profile form. The privacy control is moved just below the uploader by
 * the gallery JS (initOnboardMove in assets/js/photos-gallery.js).
 *
 * Registered only when Config::photos_enabled().
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoOnboarding {

	public static function register() {
		if ( ! Config::photos_enabled() ) {
			return;
		}

		// Retire the separate Cover Image feature entirely.
		add_filter( 'bp_disable_cover_image_uploads', '__return_true' );

		// Render the uploader + step chrome on the Change Profile Photo screen.
		add_action( 'bp_before_profile_avatar_upload_content', array( __CLASS__, 'render_on_avatar_screen' ), 5 );
		add_action( 'bp_after_profile_avatar_upload_content', array( __CLASS__, 'render_on_avatar_screen' ), 5 );
		add_action( 'bp_after_member_body', array( __CLASS__, 'render_on_avatar_screen' ), 5 );
		add_action( 'bp_template_content', array( __CLASS__, 'render_on_avatar_screen' ), 1 );

		// Next: Fill your profile (#11690).
		add_action( 'bp_after_profile_avatar_upload_content', array( __CLASS__, 'render_next_button' ), 20 );
		add_action( 'bp_after_member_body', array( __CLASS__, 'render_next_button' ), 20 );
		add_action( 'bp_template_content', array( __CLASS__, 'render_next_button' ), 20 );
	}

	/** Blue section nav + "Step 1 of N" bar, matching the edit wizard. */
	public static function step_chrome() {
		$uid  = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : get_current_user_id();
		$base = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : '';
		if ( ! $base ) {
			return '';
		}
		$base   = trailingslashit( $base );
		$change = $base . 'profile/change-avatar/';

		$order = function_exists( 'csm_pe_group_order' ) ? csm_pe_group_order() : array( 1, 7, 6, 4, 9, 8, 10 );
		$names = array();
		if ( function_exists( 'bp_xprofile_get_groups' ) ) {
			foreach ( (array) bp_xprofile_get_groups( array( 'exclude_fields' => true ) ) as $g ) {
				if ( isset( $g->id ) ) {
					$names[ (int) $g->id ] = $g->name;
				}
			}
		}
		$total = count( $order ) + 1;
		$pct   = $total ? ( 100 / $total ) : 100;

		ob_start();
		echo '<div class="csm-pe-steps" style="margin:0 0 14px">';
		echo '<div style="color:#7a1220;font-weight:700;font-size:15px">Step 1 of ' . (int) $total . '</div>';
		echo '<div class="csm-pe-bar" style="height:6px;background:#eee;border-radius:3px;margin-top:8px"><div style="height:6px;background:#4caf50;border-radius:3px;width:' . esc_attr( number_format( $pct, 1 ) ) . '%"></div></div>';
		echo '</div>';

		echo '<ul class="button-tabs button-nav" style="display:flex;flex-wrap:wrap;gap:10px;list-style:none;padding:0;margin:0 0 18px">';
		echo '<li class="current"><a href="' . esc_url( $change ) . '">Profile Photo</a></li>';
		foreach ( $order as $gid ) {
			$gid   = (int) $gid;
			$label = isset( $names[ $gid ] ) ? $names[ $gid ] : ( 'Section ' . $gid );
			$url   = $base . 'profile/edit/group/' . $gid . '/';
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
		return ob_get_clean();
	}

	public static function render_on_avatar_screen() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		if ( ! class_exists( 'CAShaadi\\Modules\\Photos\\Gallery' ) ) {
			return;
		}
		if ( ! function_exists( 'bp_is_user_change_avatar' ) || ! bp_is_user_change_avatar() ) {
			return;
		}
		if ( ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}
		$printed = true;

		// The classic single-photo avatar/crop tool is hidden via CSS
		// (assets/css/photos-gallery.css); the .csm-photo-privacy control is kept.

		echo '<div class="csm-ph-onboard" style="margin:0 0 26px">';
		echo self::step_chrome(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Gallery::uploader_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p style="color:#7a6f68;font-size:13px;margin-top:10px">Tip: add a few clear photos &mdash; your first photo is what people see in Discover and search. Please crop your photos before uploading for the best fit (portrait works best).</p>';
		echo '</div>';
	}

	/**
	 * Adds a "Next: Fill your profile" button on the Change Profile Photo screen
	 * so the post-activation flow is continuous. Non-blocking: the member may
	 * proceed with or without uploading a photo.
	 */
	public static function render_next_button() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		if ( ! function_exists( 'bp_is_user_change_avatar' ) ) {
			return;
		}
		if ( ! bp_is_user_change_avatar() || ! bp_is_my_profile() ) {
			return;
		}
		$printed = true;

		$uid  = get_current_user_id();
		$base = function_exists( 'bp_members_get_user_url' )
			? bp_members_get_user_url( $uid )
			: ( function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $uid ) : home_url( '/' ) );
		$dest = trailingslashit( $base ) . 'profile/edit/';

		echo '<div class="csm-photo-next" style="margin:24px 0 8px;text-align:right;">';
		echo '<a href="' . esc_url( $dest ) . '" class="button" ';
		echo 'style="display:inline-block;background:#4caf50;color:#fff;border:0;';
		echo 'padding:12px 28px;border-radius:6px;font-weight:600;text-decoration:none;">';
		echo 'Next: Fill your profile &rarr;';
		echo '</a>';
		echo '</div>';
	}
}
