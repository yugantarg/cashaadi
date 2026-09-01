<?php
/**
 * Settings screen — the app-style grouped settings hub.
 *
 * Implements the approved "CAShaadi Member Area" design: a grouped list
 * (ACCOUNT / PRIVACY & PHOTOS / ACCOUNT STATUS) followed by Log out and a
 * destructive "Delete my account" link, replacing the BuddyPress settings tab
 * strip on mobile.
 *
 * Net-new UI (not a snippet migration), so it is NOT behind a cutover flag —
 * but it is deliberately conservative:
 *
 *   - It only RENDERS extra markup; it changes no settings logic and saves
 *     nothing. Every row points at an existing, verified BuddyPress/plugin
 *     screen, so nothing here can strand a member.
 *   - It is shown on mobile only (<= 782px, where the app shell lives). Desktop
 *     keeps the stock BuddyPress settings UI untouched until a desktop nav
 *     exists.
 *   - The native "Account Email / Password" form stays on the page below the
 *     hub — it IS the editor those two rows point at (#csm-set-account).
 *
 * Because the hub links to every settings sub-screen (Email notifications,
 * Profile Visibility, Blocked members, Delete account), hiding the #subnav tab
 * strip on mobile loses no route — that is the point: it replaces the chrome
 * rather than merely covering it.
 *
 * Routes used here were verified live on staging2:
 *   settings/general/  settings/notifications/  settings/profile/
 *   settings/blocked/  settings/delete-account/  profile/change-avatar/
 */

namespace CAShaadi\Modules\Settings;

use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;
use CAShaadi\Core\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public static function register() {
		add_action( 'bp_before_member_body', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 23 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** The member's OWN settings screen (never someone else's). */
	private static function is_here() {
		return function_exists( 'bp_is_user_settings' )
			&& bp_is_user_settings()
			&& function_exists( 'bp_is_my_profile' )
			&& bp_is_my_profile();
	}

	public static function body_class( $classes ) {
		if ( self::is_here() ) {
			$classes[] = 'csm-set-screen';
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::is_here() ) {
			return;
		}
		Assets::style( 'settings', 'assets/css/settings.css' );
	}

	/** Base URL of the logged-in member, trailing-slashed. */
	private static function me() {
		if ( function_exists( 'bp_loggedin_user_url' ) ) {
			return trailingslashit( bp_loggedin_user_url() );
		}
		if ( function_exists( 'bp_loggedin_user_domain' ) ) {
			return trailingslashit( bp_loggedin_user_domain() );
		}
		return '';
	}

	/* ------------------------------------------------------------- markup */

	/** One navigable row: label, optional value, chevron. */
	private static function row( $label, $href, $value = '', $mods = '' ) {
		if ( '' === $href ) {
			return;
		}
		echo '<li class="csm-set-row' . ( $mods ? ' ' . esc_attr( $mods ) : '' ) . '">';
		echo '<a href="' . esc_url( $href ) . '">';
		echo '<span class="csm-set-label">' . esc_html( $label ) . '</span>';
		if ( '' !== $value ) {
			echo '<span class="csm-set-value">' . esc_html( $value ) . '</span>';
		}
		echo '<span class="csm-set-chev" aria-hidden="true">'
			. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>'
			. '</span>';
		echo '</a></li>';
	}

	/** A read-only status row (no destination), e.g. ICAI Verification. */
	private static function status_row( $label, $value, $ok ) {
		echo '<li class="csm-set-row is-static"><span class="csm-set-static">';
		echo '<span class="csm-set-label">' . esc_html( $label ) . '</span>';
		echo '<span class="csm-set-value' . ( $ok ? ' is-ok' : '' ) . '">';
		if ( $ok ) {
			echo '<svg class="csm-set-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';
		}
		echo esc_html( $value ) . '</span>';
		echo '</span></li>';
	}

	private static function group_open( $title ) {
		echo '<h3 class="csm-set-group">' . esc_html( $title ) . '</h3><ul class="csm-set-list">';
	}

	private static function group_close() {
		echo '</ul>';
	}

	public static function render() {
		if ( ! self::is_here() ) {
			return;
		}

		$me   = self::me();
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		if ( '' === $me || ! $uid ) {
			return;
		}

		// Phone + its OTP state. Verification is guarded internally, and the
		// helpers fall back safely when the OTP module/snippet is not loaded.
		$phone = class_exists( Verification::class ) ? Verification::user_phone( $uid ) : '';
		$phone_ok = class_exists( Verification::class ) ? (bool) Verification::phone_verified( $uid ) : false;
		$ca_ok = class_exists( Verification::class ) ? (bool) Verification::ca_verified( $uid ) : false;

		echo '<section class="csm-set" aria-label="' . esc_attr__( 'Settings', 'cashaadi-ui' ) . '">';

		/* ---- ACCOUNT ---- */
		self::group_open( __( 'Account', 'cashaadi-ui' ) );
		self::row( __( 'Email', 'cashaadi-ui' ), '#csm-set-account', $user ? $user->user_email : '' );
		self::row(
			__( 'Phone number', 'cashaadi-ui' ),
			$me . 'profile/edit/',
			$phone_ok ? __( 'Verified', 'cashaadi-ui' ) : ( $phone ? __( 'Not verified', 'cashaadi-ui' ) : __( 'Add', 'cashaadi-ui' ) )
		);
		self::row( __( 'Password', 'cashaadi-ui' ), '#csm-set-account' );
		self::group_close();

		/* ---- PRIVACY & PHOTOS ---- */
		self::group_open( __( 'Privacy & photos', 'cashaadi-ui' ) );
		self::row( __( 'Photos', 'cashaadi-ui' ), $me . 'profile/change-avatar/' );
		// NOT "who can see my profile": every profile is visible to everyone here.
		// BuddyPress's settings/profile/ screen sets visibility PER FIELD, which is
		// the actual control, so the label says so.
		self::row( __( 'Field visibility', 'cashaadi-ui' ), $me . 'settings/profile/' );
		self::row( __( 'Blocked members', 'cashaadi-ui' ), $me . 'settings/blocked/' );
		self::group_close();

		/* ---- ACCOUNT STATUS ---- */
		self::group_open( __( 'Account status', 'cashaadi-ui' ) );
		self::status_row(
			__( 'ICAI verification', 'cashaadi-ui' ),
			$ca_ok ? __( 'Verified', 'cashaadi-ui' ) : __( 'In review', 'cashaadi-ui' ),
			$ca_ok
		);
		self::row( __( 'Email notifications', 'cashaadi-ui' ), $me . 'settings/notifications/' );
		self::row( __( 'Help & support', 'cashaadi-ui' ), 'mailto:' . Config::SUPPORT_EMAIL );
		self::group_close();

		/* ---- log out / delete ----
		 * "Delete my account" is only offered when BuddyPress actually serves a
		 * deletion screen. Verified on staging2: /settings/delete-account/ returns
		 * 200 but renders NO delete UI when deletion is disabled, so linking to it
		 * unconditionally would walk members into a dead end. */
		$can_delete = ! function_exists( 'bp_disable_account_deletion' ) || ! bp_disable_account_deletion();

		echo '<div class="csm-set-foot">';
		echo '<a class="csm-set-logout" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'cashaadi-ui' ) . '</a>';
		if ( $can_delete ) {
			echo '<a class="csm-set-danger" href="' . esc_url( $me . 'settings/delete-account/' ) . '">' . esc_html__( 'Delete my account', 'cashaadi-ui' ) . '</a>';
		}
		echo '</div>';

		echo '</section>';

		// Anchor for the native Account Email / Password form that follows.
		echo '<div id="csm-set-account"></div>';
	}
}
