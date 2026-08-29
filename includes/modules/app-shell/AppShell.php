<?php
/**
 * App shell — the new cross-cutting member-area UI.
 *
 * This first increment adds the mobile bottom navigation (Discover · Matches ·
 * Messages · Profile) on member screens, hidden on the focused profile wizard.
 * It is purely additive: it does not (yet) remove the existing BuddyX subnav /
 * sidebar — that higher-risk restyle is a separate step. URLs are derived from
 * BuddyPress so they are correct on any environment.
 *
 * Net-new UI (not a snippet migration); uses the shared design tokens.
 */

namespace CAShaadi\Modules\AppShell;

use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AppShell {

	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 22 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** A BuddyPress member screen, or the Discover page. */
	private static function is_member_screen() {
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			return true;
		}
		if ( function_exists( 'is_page' ) && is_page( 'discover' ) ) {
			return true;
		}
		return false;
	}

	/** The focused flow (profile-edit wizard) where the shell is hidden. */
	private static function is_focused() {
		return function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit();
	}

	/** Should the shell render at all on this request? */
	private static function active_here() {
		return is_user_logged_in() && self::is_member_screen() && ! self::is_focused();
	}

	/** Which nav item is current. */
	private static function current() {
		if ( function_exists( 'is_page' ) && is_page( 'discover' ) ) {
			return 'discover';
		}
		if ( function_exists( 'bp_is_current_component' ) ) {
			if ( bp_is_current_component( 'friends' ) ) {
				return 'matches';
			}
			if ( bp_is_current_component( 'messages' ) ) {
				return 'messages';
			}
		}
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			return 'profile';
		}
		return '';
	}

	public static function body_class( $classes ) {
		if ( self::active_here() ) {
			$classes[] = 'csm-has-appnav';
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::active_here() ) {
			return;
		}
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		Assets::style( 'app-shell', 'assets/css/app-shell.css', array( 'cashaadi-tokens' ) );
	}

	public static function render() {
		if ( ! self::active_here() ) {
			return;
		}

		$base = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		$friends_slug  = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';
		$messages_slug = function_exists( 'bp_get_messages_slug' ) ? bp_get_messages_slug() : 'messages';

		$items = array(
			'discover' => array( 'Discover', home_url( '/discover/' ), self::icon_discover() ),
			'matches'  => array( 'Matches', trailingslashit( $base . $friends_slug ), self::icon_matches() ),
			'messages' => array( 'Messages', trailingslashit( $base . $messages_slug ), self::icon_messages() ),
			'profile'  => array( 'Profile', $base, self::icon_profile() ),
		);
		$current = self::current();

		echo '<nav class="csm-appnav" aria-label="Member navigation">';
		foreach ( $items as $key => $item ) {
			list( $label, $url, $svg ) = $item;
			$cls = ( $key === $current ) ? ' is-active' : '';
			$cur = ( $key === $current ) ? ' aria-current="page"' : '';
			printf(
				'<a class="csm-appnav-item%s" href="%s"%s>%s<span>%s</span></a>',
				esc_attr( $cls ),
				esc_url( $url ),
				$cur, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string
				$svg, // trusted inline SVG defined below
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/* ---- inline icons (stroke = currentColor) --------------------------- */

	private static function icon_discover() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M15.5 8.5l-2 5-5 2 2-5 5-2z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	}
	private static function icon_matches() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20s-7-4.35-7-9a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 4.65-7 9-7 9z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	}
	private static function icon_messages() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12a7 7 0 0 1-7 7H6l-3 2 1-4a7 7 0 1 1 16-5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	}
	private static function icon_profile() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.4" stroke="currentColor" stroke-width="1.7"/><path d="M5 19a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
	}
}
