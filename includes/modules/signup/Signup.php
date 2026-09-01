<?php
/**
 * Signup module.
 *
 * Consolidates two still-active WPCode snippets that shape the BuddyPress
 * registration / activation flow:
 *   #11583 One-Click Email Activation + Auto-Login
 *   #11842 Skip Username at Signup (username is derived from the email hash, so
 *          the signup Username field is redundant and hidden + auto-filled)
 *
 * Gated behind Config::signup_enabled() (off unless wp-config sets
 * CASHAADI_SIGNUP_ENABLED = true). The snippets stay live in WPCode until a
 * coordinated cutover: flip the flag ON in the SAME change that disables
 * #11583/#11842. Both hook the front-end request, so running the plugin's copy
 * alongside the active snippets would double the behaviour — hence the gate.
 *
 * #11583 is auth-sensitive: the activation-key handling, auto-login and redirect
 * are reproduced exactly (same order, same cookie/`wp_login` calls, same
 * failure redirects). Nothing here is weakened relative to the snippet.
 */

namespace CAShaadi\Modules\Signup;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signup {

	public static function register() {
		if ( ! Config::signup_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// #11583 — one-click activation + auto-login (priority 1, as in the snippet).
		add_action( 'template_redirect', array( __CLASS__, 'activate_and_login' ), 1 );

		// #11842 — skip username at signup.
		add_action( 'bp_signup_validate', array( __CLASS__, 'username_fallback' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/* ===================================================================
	 * #11583 — One-Click Email Activation + Auto-Login
	 * =================================================================== */

	public static function activate_and_login() {

		// Only act on the BuddyPress activation page.
		if ( ! function_exists( 'bp_is_current_component' ) || ! bp_is_current_component( 'activate' ) ) {
			return;
		}

		// Already-activated success page: leave it alone.
		if ( isset( $_GET['activated'] ) ) {
			return;
		}

		// Read the activation key from the path segment OR the ?key= query string.
		$key = '';
		if ( function_exists( 'bp_current_action' ) && bp_current_action() ) {
			$key = bp_current_action();
		} elseif ( ! empty( $_GET['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}

		$key = trim( $key, '/ ' );
		if ( empty( $key ) ) {
			return; // No key -> let BuddyPress show its default form.
		}

		// Activate the signup using the key.
		$user_id = bp_core_activate_signup( $key );

		if ( is_wp_error( $user_id ) || empty( $user_id ) ) {
			wp_safe_redirect( wp_login_url() . '?activation=failed' );
			exit;
		}

		// Make sure the user actually exists before logging in.
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url() . '?activation=failed' );
			exit;
		}

		// Auto-login the freshly activated user.
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'wp_login', $user->user_login, $user );

		// Redirect to the user's profile edit screen.
		$dest = home_url( '/' );
		if ( function_exists( 'bp_members_get_user_url' ) ) {
			$dest = bp_members_get_user_url( $user_id );
		} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
			$dest = bp_core_get_user_domain( $user_id );
		}
		$dest = trailingslashit( $dest ) . 'profile/change-avatar/';

		wp_safe_redirect( add_query_arg( 'signup', '1', $dest ) );
		exit;
	}

	/* ===================================================================
	 * #11842 — Skip Username at Signup
	 * =================================================================== */

	/** Fallback: if the username somehow arrives empty, supply a valid unique one. */
	public static function username_fallback() {
		if ( isset( $_POST['signup_username'] ) && '' === trim( (string) $_POST['signup_username'] ) ) {
			$_POST['signup_username'] = 'u' . substr( md5( uniqid( '', true ) ), 0, 14 );
		}
	}

	/**
	 * Hide + auto-fill the redundant username field on the registration page.
	 * The snippet echoed its CSS/JS in wp_footer (because WPCode runs after
	 * wp_head); here the same rules ship as real, enqueued assets scoped to the
	 * register page.
	 */
	public static function register_assets() {
		$on_register = function_exists( 'bp_is_register_page' ) && bp_is_register_page();
		$on_activate = function_exists( 'bp_is_activation_page' ) && bp_is_activation_page();

		if ( ! $on_register && ! $on_activate ) {
			return;
		}

		// The stylesheet now also carries the focused app treatment for both
		// screens ("app-like from the signup wizard onwards"), so it loads on
		// activation too.
		Assets::style( 'signup', 'assets/css/signup.css' );

		// The username auto-fill/hide (#11842) only applies to the signup form.
		if ( $on_register ) {
			Assets::script( 'signup', 'assets/js/signup.js' );
		}
	}
}
