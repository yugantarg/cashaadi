<?php
/**
 * Matches module.
 *
 * Migrates two BuddyPress-friendship (relabelled "Match") snippets:
 *   #11637 "Requests Sent" owner-only sub-tab under the Matches component
 *   #11694 Match request / accepted emails (native BP templates -> Brevo)
 *
 * Gated behind Config::matches_enabled(): the flag is OFF by default and MUST be
 * flipped on in the SAME change that disables #11637/#11694 (they define the same
 * global functions / hook the same actions, so both-active would double the email
 * or fatal on redeclare). Typically enabled together with the Discover module,
 * since a discovery "Like" (#11630 routing) is what creates the match request.
 */

namespace CAShaadi\Modules\Matches;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Matches {

	public static function register() {
		if ( ! Config::matches_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Global screen/render/helper functions (BuddyPress resolves the screen
		// callback by string name; csm_profile_age is a shared helper).
		require_once __DIR__ . '/functions.php';

		// #11637 — "Requests Sent" sub-nav under Friends/Matches.
		add_action( 'bp_setup_nav', array( __CLASS__, 'setup_nav' ), 100 );

		/*
		 * #11694 — match request / accepted emails.
		 *
		 * THE REQUEST EMAIL IS GONE FROM HERE. Discover fires csm_profile_liked
		 * for the same event, and Engagement::on_liked already emails on it, so
		 * every request produced TWO emails: this one, and the queued one. A
		 * member reported getting both.
		 *
		 * The queued one is the survivor, and not arbitrarily. bp_send_email()
		 * goes out the instant the hook fires — around the queue, not through
		 * it — so this path ignored the master switch, dry-run, quiet hours,
		 * the hourly and daily caps, and the unsubscribe flag. It was sending
		 * while the queue was reporting "paused".
		 *
		 * Acceptance still goes through here, because accepting from the
		 * Requests screen calls friends_accept_friendship() directly and never
		 * fires csm_mutual_match — removing it would mean no acceptance email
		 * at all. It now at least honours the same opt-outs the queue does.
		 */
		add_action( 'friends_friendship_accepted', array( __CLASS__, 'email_on_accepted' ), 10, 3 );

		// Card styling for the Requests Sent screen.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	public static function assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		Assets::style( 'matches', 'assets/css/matches.css' );
	}

	/* ---- #11637 sub-nav ------------------------------------------------- */

	public static function setup_nav() {
		if ( ! function_exists( 'bp_get_friends_slug' ) || ! function_exists( 'bp_loggedin_user_domain' ) ) {
			return;
		}
		$friends_slug = bp_get_friends_slug(); // 'matches' here (relabelled)
		$parent_url   = trailingslashit( bp_loggedin_user_domain() . $friends_slug );

		bp_core_new_subnav_item( array(
			'name'            => __( 'Requests Sent', 'cashaadi' ),
			'slug'            => 'requests-sent',
			'parent_url'      => $parent_url,
			'parent_slug'     => $friends_slug,
			'position'        => 40,
			'user_has_access' => bp_is_my_profile(), // owner-only
			'screen_function' => 'csm_requests_sent_screen',
		) );
	}

	/* ---- #11694 emails -------------------------------------------------- */

	/** Recipient's "pending match requests" page. */
	private static function match_requests_url( $user_id ) {
		if ( ! function_exists( 'bp_core_get_user_domain' ) || ! function_exists( 'bp_get_friends_slug' ) ) {
			return home_url( '/' );
		}
		return esc_url( bp_core_get_user_domain( (int) $user_id ) . bp_get_friends_slug() . '/requests/' );
	}

	public static function email_on_request( $friendship_id, $initiator_user_id, $friend_user_id ) {
		if ( ! function_exists( 'bp_send_email' ) ) {
			return;
		}
		$initiator_user_id = (int) $initiator_user_id;
		$friend_user_id    = (int) $friend_user_id;
		if ( $initiator_user_id < 1 || $friend_user_id < 1 ) {
			return;
		}

		$args = array(
			'tokens' => array(
				'friendship.id'        => (int) $friendship_id,
				'friendship.initiator' => $initiator_user_id,
				'friendship.friend'    => $friend_user_id,
				'initiator.name'       => bp_core_get_user_displayname( $initiator_user_id ),
				'initiator.url'        => esc_url( bp_core_get_user_domain( $initiator_user_id ) ),
				'match-requests.url'   => self::match_requests_url( $friend_user_id ),
			),
		);
		bp_send_email( 'friends-request', $friend_user_id, $args );
	}

	/**
	 * Does this member still want mail from us at all?
	 *
	 * This path sends directly rather than through the queue, so none of the
	 * queue's own refusals apply. Asking the same questions here is the
	 * difference between a direct send and an unaccountable one — somebody who
	 * unsubscribed must not keep receiving these.
	 */
	private static function may_email( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! get_userdata( $uid ) ) {
			return false;
		}
		if ( get_user_meta( $uid, 'csm_remail_optout', true ) ) {
			return false;
		}
		if ( class_exists( '\CAShaadi\Modules\Emails\Engagement' )
			&& method_exists( '\CAShaadi\Modules\Emails\Engagement', 'allowed' )
			&& ! \CAShaadi\Modules\Emails\Engagement::allowed( $uid, 'csm_email_matches' ) ) {
			return false;
		}
		return (bool) apply_filters( 'csm_remail_can_email', true, $uid, 'friends-accepted' );
	}

	public static function email_on_accepted( $friendship_id, $initiator_user_id, $friend_user_id ) {
		if ( ! function_exists( 'bp_send_email' ) ) {
			return;
		}
		$initiator_user_id = (int) $initiator_user_id;
		$friend_user_id    = (int) $friend_user_id;
		if ( $initiator_user_id < 1 || $friend_user_id < 1 ) {
			return;
		}

		// The initiator is the one being told their request was accepted.
		if ( ! self::may_email( $initiator_user_id ) ) {
			return;
		}

		$args = array(
			'tokens' => array(
				'friendship.id' => (int) $friendship_id,
				'friend.id'     => $friend_user_id,
				'friend.name'   => bp_core_get_user_displayname( $friend_user_id ),
				'friend.url'    => esc_url( bp_core_get_user_domain( $friend_user_id ) ),
				'match.url'     => esc_url( bp_core_get_user_domain( $friend_user_id ) ),
			),
		);
		bp_send_email( 'friends-request-accepted', $initiator_user_id, $args );
	}
}
