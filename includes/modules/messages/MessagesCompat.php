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
