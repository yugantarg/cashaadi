<?php
/**
 * Photo gate — at least one photo before entering the app.
 *
 * Owner, 2026-09-01: "After sign up the first field asked should be photo and
 * that is mandatory. Give the option to blur there itself." Blur defaults OFF.
 *
 * WHY A GATE RATHER THAN A WIZARD STEP
 * profile-wizard.js states its own boundary: "Photo tab is a separate avatar
 * screen and is intentionally NOT part of this wizard." The wizard saves by
 * POSTing BuddyPress's own per-group xProfile forms; a photo is not an xProfile
 * field and uses a completely different upload path. Bolting it into that
 * traversal would put the riskiest possible change in front of every new member.
 * Redirecting to the existing, working photo screen until a photo exists gets
 * the same outcome without touching the wizard.
 *
 * NOT TRAPPING PEOPLE is the hard part of a gate, so the exemptions are
 * deliberate and generous:
 *   - administrators are never gated
 *   - AJAX, REST and CLI are never gated (they are not navigation)
 *   - the photo screen itself, obviously, or it would loop
 *   - logout lives on wp-login.php, which never reaches template_redirect, so a
 *     member can always leave
 *
 * Storage is read directly from user meta rather than through Modules\Photos,
 * because that module is flag-gated and currently OFF on staging2 — photos are
 * still served by snippet #11822 there. `csm_photos` and `csm_photo_private` are
 * the shared contract both layers use, so this works either way.
 */

namespace CAShaadi\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoGate {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 5 );
		add_action( 'bp_before_member_body', array( __CLASS__, 'render_intro' ), 2 );
		add_action( 'bp_template_redirect', array( __CLASS__, 'save_blur' ), 0 );
	}

	/** Does this member have at least one photo? */
	public static function has_photo( $uid ) {
		$ids = get_user_meta( (int) $uid, 'csm_photos', true );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return true;
		}
		// A member who uploaded through BuddyPress directly still counts.
		return function_exists( 'bp_get_user_has_avatar' ) && bp_get_user_has_avatar( (int) $uid );
	}

	/** The screen where photos are added. */
	private static function photo_url() {
		if ( ! function_exists( 'bp_loggedin_user_url' ) ) {
			return '';
		}
		return trailingslashit( bp_loggedin_user_url() ) . 'profile/change-avatar/';
	}

	private static function on_photo_screen() {
		return function_exists( 'bp_is_user' ) && bp_is_user()
			&& function_exists( 'bp_current_action' ) && 'change-avatar' === bp_current_action();
	}

	public static function gate() {
		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$uid = get_current_user_id();

		// Never gate staff — they need to move around the site to support members.
		if ( user_can( $uid, 'manage_options' ) ) {
			return;
		}
		if ( self::has_photo( $uid ) ) {
			return;
		}
		// Already there: never redirect to the page we are on.
		if ( self::on_photo_screen() ) {
			return;
		}

		$target = self::photo_url();
		if ( '' === $target ) {
			return; // BuddyPress not ready — fail open rather than trap
		}

		wp_safe_redirect( $target );
		exit;
	}

	/* ------------------------------------------------------- photo screen */

	/**
	 * Explain why they are here, and offer blur up front.
	 * Blur is OFF by default: `csm_photo_private` is simply absent until opted in.
	 */
	public static function render_intro() {
		if ( ! self::on_photo_screen() || ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}

		$uid     = get_current_user_id();
		$pending = ! self::has_photo( $uid );
		$blurred = '1' === (string) get_user_meta( $uid, 'csm_photo_private', true );

		echo '<section class="csm-photogate">';

		if ( $pending ) {
			echo '<h2 class="csm-photogate-title">' . esc_html__( 'Add a photo to continue', 'cashaadi-ui' ) . '</h2>';
			echo '<p class="csm-photogate-sub">' . esc_html__( 'Profiles with a photo get far more responses. You need at least one to start browsing.', 'cashaadi-ui' ) . '</p>';
		} else {
			echo '<h2 class="csm-photogate-title">' . esc_html__( 'Your photos', 'cashaadi-ui' ) . '</h2>';
		}

		// Blur choice, offered right here rather than buried in settings.
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

	/**
	 * Persist the blur choice. Same meta key and nonce as Photos\Privacy (#11770)
	 * so the two agree whichever layer is live — absent means clear, which is the
	 * intended default.
	 */
	public static function save_blur() {
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
