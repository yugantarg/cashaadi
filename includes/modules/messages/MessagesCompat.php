<?php
/**
 * MessagesCompat — make Better Messages point at OUR member screen.
 *
 * THE PROBLEM. Better Messages renders its own avatars and names inside the
 * chat. Tapping one either did nothing or dropped the member onto BuddyPress's
 * raw member page — different typography, a tab strip, and the "Add Match /
 * Private Message / Block" row the app was built to replace. So the one place a
 * member most wants a profile — mid-conversation, deciding whether to reply —
 * was the one place with no good route to it.
 *
 * HOW. Better Messages builds each user it sends to the front end through
 * `better_messages_rest_user_item`, and $item['url'] is the profile link. Its
 * own bundled addons (Ultimate Member, Profile Grid) do exactly this, so this
 * is the plugin's documented seam rather than a hack around it.
 *
 * Ungated: it only rewrites a URL that already exists, for people the screen is
 * already showing. If Better Messages is not installed the filter never fires
 * and this file does nothing.
 */

namespace CAShaadi\Modules\Messages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MessagesCompat {

	public static function register() {
		// Priority 30: after the bundled addons, so ours is the one that sticks.
		add_filter( 'better_messages_rest_user_item', array( __CLASS__, 'user_item' ), 30, 3 );

		/*
		 * Every link Better Messages emails was 404ing.
		 *
		 * Its bpProfileSlug setting is "bp-messages", but BuddyPress registers
		 * the messages component at "messages" — so /members/<user>/bp-messages/
		 * does not exist. With 404-to-301 installed that 404 becomes a redirect
		 * to the home page, which is why "You have unread messages" appeared to
		 * do nothing rather than to error.
		 *
		 * Overriding the URL rather than editing the plugin's setting: the
		 * setting also drives where Better Messages registers its own nav, and
		 * changing it would move that too. This filter is the seam the plugin
		 * offers for exactly this — a non-null return replaces the URL.
		 */
		add_filter( 'bp_better_messages_page', array( __CLASS__, 'messages_url' ), 10, 2 );
	}

	/**
	 * The member's real messages URL, from BuddyPress's own slug.
	 *
	 * @param string|null $url     Whatever a previous filter decided; null means "unset".
	 * @param int         $user_id Whose messages page is wanted.
	 */
	public static function messages_url( $url, $user_id ) {
		/*
		 * ONLY while building a notification.
		 *
		 * Overriding this for every caller took the site down: Better Messages
		 * redirects the standard BuddyPress messages component to its own page
		 * on template_redirect, and pointing that page back at the BuddyPress
		 * URL made the two redirect to each other until the browser gave up.
		 * The member saw the site "go down" on clicking Messages.
		 *
		 * The bug being fixed only ever concerned EMAIL, so the fix belongs
		 * only there. Front-end routing is left exactly as Better Messages
		 * wants it.
		 */
		if ( ! function_exists( 'Better_Messages' )
			|| ! isset( Better_Messages()->notifications )
			|| ! method_exists( Better_Messages()->notifications, 'is_sending_notifications' )
			|| ! Better_Messages()->notifications->is_sending_notifications() ) {
			return $url;
		}

		$user_id = (int) $user_id;
		if ( ! $user_id || ! function_exists( 'bp_members_get_user_url' ) || ! function_exists( 'bp_get_messages_slug' ) ) {
			return $url;
		}
		$base = bp_members_get_user_url( $user_id );
		if ( ! $base ) {
			return $url;
		}
		return trailingslashit( trailingslashit( $base ) . bp_get_messages_slug() );
	}

	/**
	 * Point a chat participant at /member/<id>/.
	 *
	 * @param array $item             The user item heading for the front end.
	 * @param int   $user_id          Who it describes.
	 * @param bool  $include_personal Unused; part of the filter's signature.
	 */
	public static function user_item( $item, $user_id, $include_personal = false ) {
		unset( $include_personal );

		$user_id = (int) $user_id;
		if ( ! $user_id || ! is_array( $item ) ) {
			return $item;
		}

		/*
		 * Your own name in a thread should not link to a stranger's view of you.
		 * MemberScreen redirects self-views to /profile/ anyway; sending them
		 * straight there saves the bounce.
		 */
		$item['url'] = ( get_current_user_id() === $user_id )
			? home_url( '/profile/' )
			: home_url( '/member/' . $user_id . '/' );

		return $item;
	}
}
