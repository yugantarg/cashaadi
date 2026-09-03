<?php
/**
 * Match intro — seed a conversation the moment two people match.
 *
 * Owner: "trigger a message in the chatbox of 2 matched users so they
 * immediately see each other in messages." Until now a mutual like set
 * is_mutual=true and the client showed a celebratory flash, but nothing was
 * written to Messages — the pair only appeared in each other's inbox once ONE of
 * them worked up the nerve to send the first line. This removes that cold start:
 * a conversation already exists, unread, with a warm opener, the instant they
 * match.
 *
 * DESIGN, and why it is built exactly this way (all verified against the
 * installed Better Messages 2.15.13 source, which stores messages in its OWN
 * tables — BuddyPress's messages API would write somewhere BM never reads):
 *
 *   - The greeting is sent with sender_id = 0. BM reserves 0 for system output,
 *     and its send() explicitly does NOT add sender 0 as a thread participant:
 *         if ( $this->sender_id != 0 && ! in_array(...) ) { add sender }
 *     so recipients [A, B] yield a clean 1:1 thread of exactly A and B — the
 *     greeting belongs to neither of them, which is what the owner chose over an
 *     auto-message faked "from" one side. count_unread => true means both see it
 *     as an unread conversation immediately.
 *
 *   - BM's real send_system_message() is no use here: its content is a
 *     placeholder comment rendered by a fixed client-side switch (user_joined,
 *     user_left, …) with show_on_site=false and count_unread=false, so a custom
 *     "matched" line would render blank and never surface. A plain sender_id=0
 *     message with real text is the only thing that both surfaces AND stays
 *     unattributed.
 *
 *   - sender 0 would otherwise display as "Deleted User" (get_name(0) falls
 *     through to that label), so brand() relabels id 0 to the site name via
 *     bp_better_messages_display_name. Edge: BM's deleteMessagesOnUserDelete is
 *     off by default, so a genuinely-deleted member's old messages could also
 *     render under id 0 and would inherit this label. On a matrimonial site that
 *     is rare, and a neutral brand label reads no worse than "Deleted User"; it
 *     is a cosmetic tradeoff, never a data one.
 *
 * IDEMPOTENCY. Matches are detected inside Discover::act(), which runs once for
 * the liker at the moment the second like lands — so the hook fires once per
 * pair. But we still guard: find_existing_threads(A, B, false) (false =
 * include threads either side has "deleted", so a hidden thread still counts)
 * means we never open a second thread and never inject a greeting into a
 * conversation the two have already started themselves.
 */

namespace CAShaadi\Modules\MatchIntro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MatchIntro {

	public static function register() {
		add_action( 'csm_mutual_match', array( __CLASS__, 'seed' ), 10, 2 );
		add_filter( 'bp_better_messages_display_name', array( __CLASS__, 'brand' ), 10, 2 );

		/*
		 * The OTHER way two people match — and in practice the only one that has
		 * ever happened here.
		 *
		 * csm_mutual_match fires from Discover::act() when both sides
		 * independently like. But a member can also accept an incoming request
		 * from the Requests screen, which BuddyPress confirms directly: no like
		 * row is written for the accepter, so act() never runs and nothing fires.
		 * Every confirmed friendship on staging2 was made this way.
		 *
		 * Hooking acceptance here — not in Discover — keeps it working when the
		 * Discover flag is off, and gives every listener ONE definition of
		 * "matched" rather than two that disagree.
		 */
		add_action( 'friends_friendship_accepted', array( __CLASS__, 'on_accept' ), 10, 3 );
	}

	/**
	 * Announce a match made by accepting a request.
	 *
	 * Re-entry guard: a Discover mutual like reaches friends_accept_friendship
	 * through csm_log_event(), so this can fire for a pair that act() has already
	 * announced. seed() is idempotent, but firing the public action twice would
	 * make any future listener's life harder.
	 *
	 * @param int $friendship_id Unused; BuddyPress passes it first.
	 * @param int $initiator     Who sent the request.
	 * @param int $friend        Who accepted it.
	 */
	public static function on_accept( $friendship_id, $initiator, $friend ) {
		unset( $friendship_id );

		$a = (int) $initiator;
		$b = (int) $friend;
		if ( ! $a || ! $b || $a === $b ) {
			return;
		}

		static $announced = array();
		$key = min( $a, $b ) . ':' . max( $a, $b );
		if ( isset( $announced[ $key ] ) ) {
			return;
		}
		$announced[ $key ] = true;

		do_action( 'csm_mutual_match', $a, $b );
	}

	/**
	 * Open the conversation when A and B match.
	 *
	 * @param int $a One matched member.
	 * @param int $b The other.
	 */
	public static function seed( $a, $b ) {
		$a = (int) $a;
		$b = (int) $b;
		if ( ! $a || ! $b || $a === $b ) {
			return;
		}

		$bm = self::bm();
		if ( ! $bm ) {
			return; // Better Messages inactive — nothing to seed.
		}

		// Both members must still exist.
		if ( ! get_userdata( $a ) || ! get_userdata( $b ) ) {
			return;
		}

		// Never seed across a block. csm_bl_is_blocked_pair() is symmetric, so one
		// call covers both directions.
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $a, $b ) ) {
			return;
		}

		// Already have a 1:1 thread (seeded before, or they already talked)?
		// Pass false so a thread one side has hidden still counts — never double up.
		if ( method_exists( $bm->functions, 'find_existing_threads' ) ) {
			$existing = $bm->functions->find_existing_threads( $a, $b, false );
			if ( ! empty( $existing ) ) {
				return;
			}
		}

		$content = self::greeting( $a, $b );
		if ( '' === $content ) {
			return;
		}

		$bm->functions->new_message( array(
			'sender_id'    => 0,           // system: not attributed to either member
			'recipients'   => array( $a, $b ),
			'content'      => $content,
			'subject'      => '',
			'count_unread' => true,        // both see an unread conversation at once
			'show_on_site' => true,
			'send_push'    => true,
			'mobile_push'  => true,
		) );
	}

	/**
	 * The opener. Symmetric on purpose: one message is shared by both members, so
	 * it cannot address one by name without misreading for the other.
	 */
	public static function greeting( $a, $b ) {
		$text = "🎉 You've matched! You can now message each other — say hello and start the conversation.";
		return (string) apply_filters( 'csm_match_intro_greeting', $text, $a, $b );
	}

	/**
	 * Give BM's system sender (id 0) the site's name instead of "Deleted User".
	 */
	public static function brand( $name, $user_id ) {
		if ( 0 === (int) $user_id ) {
			$brand = get_bloginfo( 'name' );
			return $brand ? $brand : $name;
		}
		return $name;
	}

	/** The Better Messages instance, or null if the plugin is not active. */
	private static function bm() {
		if ( ! function_exists( 'Better_Messages' ) ) {
			return null;
		}
		$bm = Better_Messages();
		if ( ! is_object( $bm ) || ! isset( $bm->functions ) || ! is_object( $bm->functions ) ) {
			return null;
		}
		if ( ! method_exists( $bm->functions, 'new_message' ) ) {
			return null;
		}
		return $bm;
	}
}
