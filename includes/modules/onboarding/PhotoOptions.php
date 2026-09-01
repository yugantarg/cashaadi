<?php
/**
 * Photo options on the avatar screen — the blur choice, surfaced where photos
 * are actually added.
 *
 * WAS A REDIRECT GATE (v0.40.x), NOW ISN'T.
 * This started as a hard gate: any member without a photo was redirected to the
 * avatar screen on every page load, to satisfy "a photo is mandatory before
 * proceeding". It worked, but the owner's verdict was right — a redirect is a
 * full page reload, and reloads are exactly what the app-like rebuild is meant
 * to remove. Under the agreed direction the requirement is met properly: photo
 * becomes step 1 of the /welcome/ client-side state machine, uploaded by fetch
 * to buddypress/v1/members/{id}/avatar, with no navigation at all.
 *
 * So the redirect is gone and nothing here blocks anyone. What remains is the
 * part that was independently useful: the blur toggle, on the screen where a
 * member is already thinking about their photos.
 *
 * Blur defaults OFF, and does so by construction rather than by a default value:
 * `csm_photo_private` is simply absent until the member opts in. The key and
 * nonce match Photos\Privacy, so this agrees with whichever layer is live —
 * relevant because the Photos module is flag-gated and currently OFF on
 * staging2, where snippet #11822 still serves photos.
 */

namespace CAShaadi\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoOptions {

	public static function register() {
		add_action( 'bp_before_member_body', array( __CLASS__, 'render' ), 2 );
		add_action( 'bp_template_redirect', array( __CLASS__, 'save' ), 0 );
	}

	/** Does this member have at least one photo? Used by /welcome/ progress. */
	public static function has_photo( $uid ) {
		$ids = get_user_meta( (int) $uid, 'csm_photos', true );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return true;
		}
		// A member who uploaded through BuddyPress directly still counts.
		return function_exists( 'bp_get_user_has_avatar' ) && bp_get_user_has_avatar( (int) $uid );
	}

	private static function on_photo_screen() {
		return function_exists( 'bp_is_user' ) && bp_is_user()
			&& function_exists( 'bp_current_action' ) && 'change-avatar' === bp_current_action();
	}

	public static function render() {
		if ( ! self::on_photo_screen() || ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}

		$blurred = '1' === (string) get_user_meta( get_current_user_id(), 'csm_photo_private', true );

		echo '<section class="csm-photogate">';
		echo '<h2 class="csm-photogate-title">' . esc_html__( 'Your photos', 'cashaadi-ui' ) . '</h2>';

		echo '<form method="post" class="csm-photogate-blur">';
		wp_nonce_field( 'csm_photo_privacy', 'csm_photo_privacy_nonce' );
		echo '<label class="csm-photogate-toggle">';
		echo '<input type="checkbox" name="csm_photo_private" value="1" ' . checked( $blurred, true, false ) . '>';
		echo '<span><strong>' . esc_html__( 'Blur my photo for people I have not matched with', 'cashaadi-ui' ) . '</strong>';
		echo '<em>' . esc_html__( 'Your matches always see it clearly. You can change this any time.', 'cashaadi-ui' ) . '</em></span>';
		echo '</label>';
		echo '<button type="submit" class="csm-photogate-save">' . esc_html__( 'Save preference', 'cashaadi-ui' ) . '</button>';
		echo '</form>';

		echo '</section>';
	}

	public static function save() {
		if ( empty( $_POST['csm_photo_privacy_nonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csm_photo_privacy_nonce'] ) ), 'csm_photo_privacy' ) ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! empty( $_POST['csm_photo_private'] ) ) {
			update_user_meta( $uid, 'csm_photo_private', '1' );
		} else {
			delete_user_meta( $uid, 'csm_photo_private' );
		}
	}
}
