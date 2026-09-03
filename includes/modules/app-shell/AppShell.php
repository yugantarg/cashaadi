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
	 *
	 * Returns to the /profile/ HUB, not the BuddyPress member page.
	 *
	 * The hub is where members arrive from — every "N left" row on it opens one
	 * of these editors — so sending them to the BuddyPress page instead dropped
	 * them out of the new app into the old design, on a screen they had not asked
	 * for and could not get back from. These editors have no bottom nav either
	 * (deliberately: they are a focused, one-thing-at-a-time screen), which made
	 * this link the only way out.
	 */
	public static function render_back() {
		$on_edit = function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit();

		// The photos screen is reached the same way (from the hub's "My photos"
		// row) and is equally a dead end without this.
		$on_photos = function_exists( 'bp_is_user' ) && bp_is_user()
			&& function_exists( 'bp_current_action' ) && 'change-avatar' === bp_current_action();

		// Same for BuddyPress's settings sub-screens: /settings/ links out to them
		// for email, password, field visibility, blocked members and notifications,
		// and none of them carry the app nav.
		$on_settings = function_exists( 'bp_is_user_settings' ) && bp_is_user_settings();

		if ( ! $on_edit && ! $on_photos && ! $on_settings ) {
			return;
		}
		$dest = class_exists( '\CAShaadi\Core\AppPage' ) ? \CAShaadi\Core\AppPage::nav() : array();
		$back = isset( $dest['profile']['url'] ) ? $dest['profile']['url'] : home_url( '/profile/' );
		$text = __( 'Back to profile', 'cashaadi-ui' );

		// Send them back where they came from: a settings sub-screen belongs to
		// /settings/, not the profile hub.
		if ( $on_settings && class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' ) ) {
			$back = \CAShaadi\Modules\Settings\SettingsScreen::url();
			$text = __( 'Back to settings', 'cashaadi-ui' );
		}

		printf(
			'<a class="csm-back" href="%s">%s%s</a>',
			esc_url( $back ),
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>',
			esc_html( $text )
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

		// The focused profile-edit flow has no app shell, but render_back() still
		// prints the exit control there — and without this stylesheet that markup
		// was completely unstyled, which is why the chevron rendered at 224px and
		// the control looked broken. Loading app-shell.css here is safe: the nav
		// and top bar are display:none by default and only appear under
		// .csm-has-appnav, a class this screen never gets.
		$edit = function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit();

		if ( ! $shell && ! $area && ! $edit ) {
			return;
		}
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		if ( $shell || $edit ) {
			Assets::style( 'app-shell', 'assets/css/app-shell.css', array( 'cashaadi-tokens' ) );
		}
		// screens.css carries the member-card + list restyles (scoped to
		// body.csm-screens) and the Discover card restyle (scoped to
		// body.csm-cur-discover) — load it on member area AND the Discover screen.
		if ( $shell || $area ) {
			Assets::style( 'screens', 'assets/css/screens.css', array( 'cashaadi-tokens' ) );
		}
		// The app-menu toggle (and photo-stack helper) live in app-screens.js; the
		// member-screen top bar now hosts the same hamburger + menu.
		if ( $shell ) {
			Assets::script( 'app-screens', 'assets/js/app-screens.js' );
		}
	}

	public static function render() {
		if ( ! self::active_here() ) {
			return;
		}

		/*
		 * DESTINATIONS COME FROM Core\AppPage, NOT FROM HERE.
		 *
		 * This nav renders on the BuddyPress screens (profile edit, photos,
		 * settings, another member's profile); Core\AppPage renders the one on
		 * Discover, Requests and Profile. They were pointing at different places:
		 * this copy still sent Requests to BuddyPress's friends sub-tab and
		 * Profile to the BuddyPress member page, while the app screens send both
		 * to /requests/ and /profile/.
		 *
		 * The effect was that the SAME tab took you somewhere different depending
		 * on which screen you happened to be standing on — tap "3 left" on the
		 * Profile hub, land on profile-edit, tap Requests, and get the old
		 * requests tab instead of the consolidated one. One definition fixes it,
		 * and keeps them aligned as more screens move across.
		 *
		 * Icons and markup stay local: this shell has to sit inside BuddyX's DOM,
		 * which AppPage never does.
		 */
		$dest = class_exists( '\CAShaadi\Core\AppPage' ) ? \CAShaadi\Core\AppPage::nav() : array();

		$base          = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		$messages_slug = function_exists( 'bp_get_messages_slug' ) ? bp_get_messages_slug() : 'messages';

		$url = function ( $key, $fallback ) use ( $dest ) {
			return isset( $dest[ $key ]['url'] ) ? $dest[ $key ]['url'] : $fallback;
		};

		$items = array(
			'discover' => array( 'Discover', $url( 'discover', home_url( '/discover/' ) ), self::icon_discover() ),
			'matches'  => array( 'Requests', $url( 'requests', home_url( '/requests/' ) ), self::icon_matches() ),
			'messages' => array( 'Messages', $url( 'messages', trailingslashit( $base . $messages_slug ) ), self::icon_messages() ),
			'profile'  => array( 'Profile', $url( 'profile', home_url( '/profile/' ) ), self::icon_profile() ),
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

		// The app menu renders here, at body level — NOT inside #item-header where
		// the Messages screen's own CSS (#item-header > *:not(.csm-topbar)) would
		// hide it. Its hamburger lives in the top bar; the toggle finds both by id.
		if ( class_exists( '\CAShaadi\Core\AppPage' ) ) {
			\CAShaadi\Core\AppPage::render_menu();
		}
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
		// Notifications stay a one-tap action (they are not in the menu).
		$badge = $unread > 0 ? '<i class="csm-topbar-badge">' . esc_html( $unread > 99 ? '99+' : $unread ) . '</i>' : '';
		printf(
			'<a class="csm-topbar-act" href="%s" aria-label="Notifications">%s%s</a>',
			esc_url( $notif_url ),
			self::icon_bell(), // trusted inline SVG
			$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above
		);
		/*
		 * The hamburger opens OUR app menu — the same one Discover/Settings use —
		 * instead of BuddyX's off-canvas theme menu (whose hamburger is hidden on
		 * member screens). The old settings gear is gone: it led to BuddyPress's
		 * settings, not our app settings hub, and Settings now lives in the menu.
		 */
		echo '<button type="button" class="csm-topbar-act csm-app-menu-btn" id="csm-app-menu-btn" aria-expanded="false" aria-controls="csm-app-menu" aria-label="' . esc_attr__( 'Menu', 'cashaadi-ui' ) . '"><span></span><span></span><span></span></button>';
		echo '</span>';
		echo '</div>';
		unset( $settings_slug, $settings_url );
	}

	/* ---- inline icons (stroke = currentColor) --------------------------- */

	private static function icon_discover() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>';
	}
	private static function icon_matches() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
	}
	private static function icon_messages() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>';
	}
	private static function icon_profile() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
	}
	/**
	 * Icons come from Feather, unmodified.
	 *
	 * The gear here used to be a hand-shortened version of Feather's "settings"
	 * path — several arc segments merged or dropped, and stroke-width 1.7 on the
	 * circle against 1.3 on the body. At 21px that renders as a lump rather than a
	 * gear, which is the "mangled settings button" reported repeatedly. Measuring
	 * it did not show the problem: the box was a correct 44x44 with a correct 21px
	 * icon, and the geometry inside it was the fault.
	 *
	 * These are now the complete upstream paths at one stroke width, matching the
	 * bottom-nav icons. Do not "tidy" the `d` attributes: they are data, not code,
	 * and shortening them by hand is what caused this.
	 */
	private static function icon_settings() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<circle cx="12" cy="12" r="3"></circle>'
			. '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>'
			. '</svg>';
	}

	private static function icon_bell() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>'
			. '<path d="M13.73 21a2 2 0 0 1-3.46 0"></path>'
			. '</svg>';
	}
}
