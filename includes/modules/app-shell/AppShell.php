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
		// Top bar (screen title + settings + notifications) above the member
		// header, so it sits at the very top of the content. Member pages only;
		// the Discover page has its own heading.
		add_action( 'bp_before_member_header', array( __CLASS__, 'render_topbar' ) );

		// Support email in the (now minimal) app footer.
		add_action( 'wp_footer', array( __CLASS__, 'render_support' ), 25 );

		// A way back out of a profile-edit group. Editing a group replaces the
		// screen and BuddyPress offers no route back to the profile, so the member
		// gets stuck — reported 2026-09-01.
		add_action( 'bp_before_member_body', array( __CLASS__, 'render_back' ), 1 );
	}

	/** The app footer carries the support address and nothing else. */
	public static function render_support() {
		if ( ! self::active_here() ) {
			return;
		}
		printf(
			'<div class="csm-support-only"><a href="mailto:%1$s">%1$s</a></div>',
			esc_attr( \CAShaadi\Core\Config::SUPPORT_EMAIL )
		);
	}

	/**
	 * Back link on the focused profile-edit screens, so changing a section is not
	 * a one-way trip.
	 */
	public static function render_back() {
		if ( ! function_exists( 'bp_is_user_profile_edit' ) || ! bp_is_user_profile_edit() ) {
			return;
		}
		$base = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		printf(
			'<a class="csm-back" href="%s">%s%s</a>',
			esc_url( $base ),
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>',
			esc_html__( 'Back to profile', 'cashaadi-ui' )
		);
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
			// The site uses the Better Messages plugin, whose component is
			// 'bp-messages' (not the native 'messages').
			if ( bp_is_current_component( 'messages' ) || bp_is_current_component( 'bp-messages' ) ) {
				return 'messages';
			}
		}
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			return 'profile';
		}
		return '';
	}

	/** Screens that show member-card lists (directory + friends/matches). */
	private static function is_member_area() {
		if ( ! is_user_logged_in() || self::is_focused() ) {
			return false;
		}
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			return true;
		}
		if ( function_exists( 'bp_is_members_directory' ) && bp_is_members_directory() ) {
			return true;
		}
		return false;
	}

	public static function body_class( $classes ) {
		if ( self::active_here() ) {
			$classes[] = 'csm-has-appnav';
			$cur = self::current();
			if ( $cur ) {
				$classes[] = 'csm-cur-' . $cur; // e.g. csm-cur-messages, for per-screen tweaks
			}
		}
		if ( self::is_member_area() ) {
			$classes[] = 'csm-screens';
		}
		return $classes;
	}

	public static function assets() {
		$shell = self::active_here();
		$area  = self::is_member_area();
		if ( ! $shell && ! $area ) {
			return;
		}
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		if ( $shell ) {
			Assets::style( 'app-shell', 'assets/css/app-shell.css', array( 'cashaadi-tokens' ) );
		}
		// screens.css carries the member-card + list restyles (scoped to
		// body.csm-screens) and the Discover card restyle (scoped to
		// body.csm-cur-discover) — load it on member area AND the Discover screen.
		if ( $shell || $area ) {
			Assets::style( 'screens', 'assets/css/screens.css', array( 'cashaadi-tokens' ) );
		}
	}

	public static function render() {
		if ( ! self::active_here() ) {
			return;
		}

		$base = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		$friends_slug  = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';
		$messages_slug = function_exists( 'bp_get_messages_slug' ) ? bp_get_messages_slug() : 'messages';

		// Information architecture (owner, 2026-09-01):
		//   Discover — another member's full profile as a scrollable card
		//   Requests — requests sent, received, and profile viewers
		//   Messages — current matches (the conversation list IS the match list)
		//   Profile  — my own profile
		// So this tab points at the Requests sub-screen rather than the component
		// root, which was "My Matches" — that now lives under Messages.
		$items = array(
			'discover' => array( 'Discover', home_url( '/discover/' ), self::icon_discover() ),
			'matches'  => array( 'Requests', trailingslashit( $base . $friends_slug ) . 'requests/', self::icon_matches() ),
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

	/* ---- top bar (member pages only) ----------------------------------- */

	/** Screen title from the current BuddyPress component. */
	private static function screen_title() {
		$c = function_exists( 'bp_current_component' ) ? bp_current_component() : '';
		$map = array(
			''              => 'Profile',
			'profile'       => 'Profile',
			'xprofile'      => 'Profile',
			'friends'       => 'Matches',
			'messages'      => 'Messages',
			'bp-messages'   => 'Messages', // Better Messages plugin component
			'notifications' => 'Notifications',
			'settings'      => 'Settings',
			'media'         => 'Photos',
			'rtmedia'       => 'Photos',
		);
		if ( isset( $map[ $c ] ) ) {
			return $map[ $c ];
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $c ) );
	}

	public static function render_topbar() {
		// bp_before_member_header also fires on the profile-edit wizard — skip there.
		if ( ! self::active_here() ) {
			return;
		}
		$base = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );

		$notif_slug    = function_exists( 'bp_get_notifications_slug' ) ? bp_get_notifications_slug() : 'notifications';
		$settings_slug = function_exists( 'bp_get_settings_slug' ) ? bp_get_settings_slug() : 'settings';
		$notif_url     = trailingslashit( $base . $notif_slug );
		$settings_url  = trailingslashit( $base . $settings_slug );

		$unread = 0;
		if ( function_exists( 'bp_notifications_get_unread_notification_count' ) && function_exists( 'bp_loggedin_user_id' ) ) {
			$unread = (int) bp_notifications_get_unread_notification_count( bp_loggedin_user_id() );
		}

		echo '<div class="csm-topbar">';
		echo '<span class="csm-topbar-title">' . esc_html( self::screen_title() ) . '</span>';
		echo '<span class="csm-topbar-actions">';
		printf(
			'<a class="csm-topbar-act" href="%s" aria-label="Settings">%s</a>',
			esc_url( $settings_url ),
			self::icon_settings() // trusted inline SVG
		);
		$badge = $unread > 0 ? '<i class="csm-topbar-badge">' . esc_html( $unread > 99 ? '99+' : $unread ) . '</i>' : '';
		printf(
			'<a class="csm-topbar-act" href="%s" aria-label="Notifications">%s%s</a>',
			esc_url( $notif_url ),
			self::icon_bell(), // trusted inline SVG
			$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above
		);
		echo '</span>';
		echo '</div>';
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
	private static function icon_settings() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/><path d="M19.4 13a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 4.6 13H4.5a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 11 4.6V4.5a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.6 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>';
	}
	private static function icon_bell() {
		return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
	}
}
