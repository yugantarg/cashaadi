<?php
/**
 * One frame on every page a logged-in member sees (owner, 2026-10-27: the
 * pricing page showed the WordPress menu to a logged-in member, and on a
 * laptop the menu did not work on several screens).
 *
 * The app screens (Core\AppPage) draw their own document. Everything else a
 * member reaches is a theme page: pricing, checkout and orders, Messages,
 * My photos, notifications, BuddyPress profile pages and settings. On those
 * this replaces the theme's header (#masthead) with the app's top bar: the
 * logo, the notifications bell and the member's photo, which opens the
 * account menu (Core\AccountMenu). The bottom bar / left rail comes from
 * AppShell, which now runs on these pages too.
 *
 * Logged-out visitors keep the theme's header and menu.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Chrome {

	private static $done = false;

	public static function register() {
		add_action( 'wp_body_open', array( __CLASS__, 'render' ), 1 );
		// Themes that never call wp_body_open: print it late and move it to the top.
		add_action( 'wp_footer', array( __CLASS__, 'late' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 23 );
	}

	/** A logged-in member on a theme page (not an app screen, not a focused flow). */
	public static function active() {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( AppPage::$open ) {
			return false; // draws its own
		}
		// Onboarding and the BuddyPress profile-edit wizard stay focused, as before.
		// By address too: /welcome/ is drawn by the plugin, not a WordPress page,
		// so is_page() missed it and the wizard showed a second header with the
		// logo (owner, 2026-10-28).
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = strtolower( trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' ) );
		if ( in_array( strtok( $path, '/' ), array( 'welcome', 'activate', 'register', 'join' ), true ) ) {
			return false;
		}
		if ( ( function_exists( 'is_page' ) && ( is_page( 'welcome' ) || is_page( 'activate' ) ) )
			|| ( function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit() ) ) {
			return false;
		}
		return true;
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'csm-chrome';
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		Assets::style( 'app-screens', 'assets/css/app-screens.css', array( 'cashaadi-tokens' ) );
		Assets::style( 'chrome', 'assets/css/chrome.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'app-screens', 'assets/js/app-screens.js' );
	}

	public static function render() {
		if ( self::$done || ! self::active() ) {
			return;
		}
		self::$done = true;
		echo '<header class="csm-app-top csm-chrome-top">';
		echo AppPage::brand(); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped
		echo '<span class="csm-app-top-acts">';
		AccountMenu::bell();
		AccountMenu::button();
		echo '</span></header>';
		AccountMenu::panel();
	}

	public static function late() {
		if ( self::$done || ! self::active() ) {
			return;
		}
		echo '<div id="csm-chrome-late">';
		self::render();
		echo '</div><script>(function(){var w=document.getElementById("csm-chrome-late");if(w&&document.body.firstChild!==w){while(w.lastChild){document.body.insertBefore(w.lastChild,document.body.firstChild);}w.remove();}})();</script>';
	}
}
