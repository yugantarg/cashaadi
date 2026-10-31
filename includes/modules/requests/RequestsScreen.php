<?php
/**
 * Requests — received, sent, and who viewed me, on one screen.
 *
 * Owner's information architecture: "the requests section shows requests sent,
 * received and profile viewers". Today those live in three unrelated places —
 * BuddyPress's Friends requests tab, a Requests-Sent sub-tab, and the Premium
 * module's visitors screen — so a member has to know the site's plumbing to
 * find out who is interested in them.
 *
 * THE PRIVACY RULE THAT SHAPES THIS FILE
 * "Who viewed me" is a paid feature: free members see a count and masked
 * initials, premium members see identities. That gate must be applied on the
 * SERVER, and this is exactly the kind of screen where it is easy to get wrong —
 * the old implementation rendered masked HTML, but a JSON endpoint that returned
 * viewer ids and then let CSS blur them would hand every free member the full
 * list in their browser's network tab. So free members are never sent an
 * identity at all: only a count, an initial and a relative time.
 *
 * Accept / decline go through BuddyPress's own friends_* functions, which own
 * the friendship state machine and the notifications that hang off it.
 */

namespace CAShaadi\Modules\Requests;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestsScreen {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* -------------------------------------------------------------- route */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'requests' ) ) {
			return;
		}

		AppPage::assets();
		Assets::style( 'requests-app', 'assets/css/requests-app.css', array( 'cashaadi-app-screens' ) );
		// The profile tiles, card and action bar are Discover's (v1.89.40).
		Assets::style( 'discover-app', 'assets/css/discover-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'requests-app', 'assets/js/requests-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-requests-app', 'CSM_REQUESTS', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'list'    => rest_url( 'csm/v1/requests/list' ),
			'act'     => rest_url( 'csm/v1/requests/act' ),
			'filters' => rest_url( 'csm/v1/discover/filters' ),
			'upgrade' => site_url( '/membership-pricing/' ),
			'goldPrompt' => (bool) get_option( 'csm_gold_on_sale' ) && class_exists( '\\CAShaadi\\Core\\Membership' ) && ! \CAShaadi\Core\Membership::is_gold( get_current_user_id() ),
			// Matches offer "Message", which is navigation, not an action to POST.
			'messages' => home_url( '/' . ( function_exists( 'bp_get_messages_slug' ) ? bp_get_messages_slug() : 'messages' ) . '/' ),
		) );

		AppPage::open( __( 'Requests', 'cashaadi-ui' ), 'requests' );
		echo '<div id="csm-requests-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'requests' );
		exit;
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/requests/list', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'csm/v1', '/requests/act', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_act' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'user_id' => array( 'required' => true ),
				'action'  => array( 'required' => true ),
			),
		) );
	}

	/** A compact person for a list row — never the full profile. */
	/** Requests saved for later, as a list of sender ids (user meta). */
	const REQ_SAVED = 'csm_req_saved';

	/** Anyone in these lists as a full profile card, with how to message them. */
	private static function card( $id, $uid, $kind ) {
		return array_merge( Profile::full( (int) $id, (int) $uid ), array(
			'kind'   => $kind,
			'msgUrl' => self::compose_url( (int) $id ),
		) );
	}

	/** True when $id closed (deleted) their account. */
	private static function is_gone( $id ) {
		return class_exists( '\\CAShaadi\\Modules\\Settings\\Closed' ) && \CAShaadi\Modules\Settings\Closed::is_closed( (int) $id );
	}

	/** A received request as a full profile card: same card as Discover (v1.89.40). */
	private static function request_card( $rid, $uid ) {
		$row = Profile::full( (int) $rid, (int) $uid );
		$row['kind'] = 'request';
		// Note with the request (v1.85.0).
		$row['note'] = class_exists( __NAMESPACE__ . '\\RequestNote' ) ? RequestNote::get( (int) $rid, (int) $uid ) : '';
		// Gold senders' requests are priority: first, and marked (v1.87.0).
		$row['priority'] = class_exists( '\\CAShaadi\\Core\\Membership' ) && \CAShaadi\Core\Membership::is_gold( (int) $rid );
		return $row;
	}

	private static function req_saved_set( $uid, $other, $on ) {
		$ids = array_map( 'intval', (array) get_user_meta( $uid, self::REQ_SAVED, true ) );
		$ids = array_values( array_diff( $ids, array( (int) $other ) ) );
		if ( $on ) {
			$ids[] = (int) $other;
		}
		update_user_meta( $uid, self::REQ_SAVED, $ids );
	}

	private static function person( $uid ) {
		$uid  = (int) $uid;
		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( ! $name ) {
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : __( 'Member', 'cashaadi-ui' );
		}
		$hidden = Profile::hidden_for( $uid, get_current_user_id() );

		return array(
			'id'     => $uid,
			'name'   => $name,
			'age'    => Profile::age_of( $uid, $hidden ),
			'city'   => Profile::field( 'City', $uid, $hidden ),
			'avatar' => function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'thumb', 'html' => false ) )
				: get_avatar_url( $uid, array( 'size' => 120 ) ),
			/*
			 * Our own member screen, not bp_members_get_user_url(). Tapping a name
			 * in Saved used to land on BuddyPress's member page — different
			 * typography, a tab strip, and an "Add Match / Private Message /
			 * Block" row — i.e. straight out of the app the member was in.
			 */
			'url'    => home_url( '/member/' . $uid . '/' ),

			/*
			 * BuddyPress messages live at /members/<username>/messages/, never at
			 * /messages/. The Matches tab pointed at the latter, which 404s — and
			 * with 404-to-301 installed a 404 becomes a redirect to the home page,
			 * so tapping Message looked like it did nothing at all.
			 *
			 * Composing TO them rather than opening the inbox, because "Message"
			 * on a person's row should reach that person.
			 */
			'msgUrl' => self::compose_url( $uid ),
		);
	}

	/** A compose-to-this-person URL for the CURRENT member, or '' if unavailable. */
	private static function compose_url( $recipient_id ) {
		$me = get_current_user_id();
		if ( ! $me || ! function_exists( 'bp_members_get_user_url' ) || ! function_exists( 'bp_get_messages_slug' ) ) {
			return '';
		}
		$base = trailingslashit( bp_members_get_user_url( $me ) ) . bp_get_messages_slug() . '/';
		$user = get_userdata( (int) $recipient_id );
		if ( ! $user ) {
			return $base;
		}
		return $base . 'compose/?r=' . rawurlencode( $user->user_nicename );
	}

	/** Friend requests this member has SENT and that are still pending. */
	private static function sent( $uid ) {
		global $wpdb;
		$table = $wpdb->base_prefix . 'bp_friends';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT friend_user_id FROM {$table}
			 WHERE initiator_user_id = %d AND is_confirmed = 0
			 ORDER BY date_created DESC",
			(int) $uid
		) );
		return array_map( 'intval', (array) $ids );
	}

	public static function rest_list( $request ) {
		$uid = get_current_user_id();
		// 24 full profiles at a time, as Discover (v1.89.62); the screen asks for more.
		$limit = max( 24, (int) $request->get_param( 'limit' ) );
		/*
		 * Next page only (v1.91.6): ?tab=<list>&offset=<n> builds just that
		 * list's next 24 cards and returns them as `page`. Before, Show more
		 * reloaded everything with 24 more, rebuilding every card from the top.
		 */
		$ptab = sanitize_key( (string) $request->get_param( 'tab' ) );
		$poff = max( 0, (int) $request->get_param( 'offset' ) );
		$page = '' !== $ptab && $poff > 0;
		$take = function ( $key ) use ( $page, $ptab, $poff, $limit ) {
			if ( ! $page ) {
				return array( 0, $limit );
			}
			return $key === $ptab ? array( $poff, 24 ) : array( 0, 0 );
		};

		/*
		 * ---- received ----
		 *
		 * friends_get_friendship_request_user_ids(), NOT
		 * friends_get_friendship_requests() — the latter does not exist in
		 * BuddyPress. The function_exists() guard meant to make this degrade
		 * gracefully instead hid the typo completely: the call was skipped, the
		 * list came back empty, and the screen rendered "no requests" for
		 * everybody from the day it shipped. Found when a member with eight
		 * pending requests could not see one of them.
		 *
		 * The fallback below is the query BuddyPress itself runs, so a missing
		 * or renamed helper degrades to the right answer rather than to silence.
		 */
		$received = array();
		$rids     = array();

		if ( function_exists( 'friends_get_friendship_request_user_ids' ) ) {
			$rids = (array) friends_get_friendship_request_user_ids( $uid );
		} else {
			global $wpdb;
			$ft   = $wpdb->base_prefix . 'bp_friends';
			$rids = (array) $wpdb->get_col( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT initiator_user_id FROM {$ft} WHERE friend_user_id = %d AND is_confirmed = 0",
				$uid
			) );
		}

		/*
		 * The member's Discover filters narrow this list too: one filter, set
		 * from either screen (v1.89.13). Hidden requests stay pending.
		 */
		/*
		 * Requests the member saved for later (v1.89.40) wait under Saved, not
		 * Received. Still pending in BuddyPress; a sender who withdrew drops out.
		 */
		$rids      = array_map( 'intval', $rids );
		/*
		 * Deleted accounts stay where they were, as a "Deleted profile" stand-in
		 * (owner, 2026-10-31), so nobody goes looking for where they went.
		 * Listed apart (gone[]), never as a card that opens.
		 */
		$gone = array( 'received' => 0, 'matches' => 0, 'sent' => 0, 'passed' => 0 );
		$gone['received'] = count( array_filter( $rids, array( __CLASS__, 'is_gone' ) ) );
		$rids             = array_values( array_filter( $rids, function ( $r ) { return ! self::is_gone( $r ); } ) );
		$req_saved = array_values( array_intersect( array_map( 'intval', (array) get_user_meta( $uid, self::REQ_SAVED, true ) ), $rids ) );
		$rids      = array_values( array_diff( $rids, $req_saved ) );

		$has_filters = class_exists( '\\CAShaadi\\Modules\\Discover\\Filters' );
		$all_rids    = count( $rids );
		if ( $has_filters ) {
			$rids = \CAShaadi\Modules\Discover\Filters::keep( $uid, $rids );
		}

		$rids = array_values( array_filter( array_map( 'intval', $rids ), function ( $r ) { return $r && get_userdata( $r ); } ) );
		// Gold senders first (v1.87.0), decided on ids so only the page shown is built.
		$gold_ids = class_exists( '\\CAShaadi\\Modules\\Premium\\Gold' ) ? \CAShaadi\Modules\Premium\Gold::ids_among( $rids ) : array();
		$rids     = array_merge( array_values( array_intersect( $rids, $gold_ids ) ), array_values( array_diff( $rids, $gold_ids ) ) );
		$received_total = count( $rids );
		list( $a, $b ) = $take( 'received' );
		foreach ( array_slice( $rids, $a, $b ) as $rid ) {
			$received[] = self::request_card( $rid, $uid );
		}

		/* ---- sent ---- */
		// Full profiles, 24 at a time, like Received (v1.89.63).
		$sent       = array();
		$sent_ids   = array_values( array_filter( array_map( 'intval', (array) self::sent( $uid ) ) ) );
		$gone['sent'] = count( array_filter( $sent_ids, array( __CLASS__, 'is_gone' ) ) );
		$sent_ids     = array_values( array_filter( $sent_ids, function ( $r ) { return ! self::is_gone( $r ); } ) );
		$sent_total = count( $sent_ids );
		/*
		 * Under More, Premium and Gold only (owner, 2026-10-28): free members
		 * get initials, never identities, like Declined.
		 */
		$sent_premium = class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid );
		list( $a, $b ) = $sent_premium ? $take( 'sent' ) : array( 0, $page ? 0 : 6 );
		foreach ( array_slice( $sent_ids, $a, $b ) as $sid ) {
			if ( $sent_premium ) {
				$sent[] = self::card( $sid, $uid, 'sent' );
				continue;
			}
			$name    = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $sid ) : '';
			$initial = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			$sent[]  = array( 'masked' => true, 'initial' => '' !== $initial ? $initial : 'C' );
		}

		/*
		 * ---- matches ----
		 *
		 * Accepted requests vanished. They left Received, they were never in
		 * Sent, and the bottom nav has no Matches screen — so the only trace of
		 * a person you matched with was a conversation thread, and there was no
		 * route back to their profile from anywhere in the app.
		 *
		 * Same fallback shape as received: the helper is real, but a guard that
		 * silently returns nothing is how the received list stayed empty for
		 * everybody, and once is enough.
		 */
		$matches = array();
		$mids    = array();

		if ( function_exists( 'friends_get_friend_user_ids' ) ) {
			$mids = (array) friends_get_friend_user_ids( $uid );
		} else {
			global $wpdb;
			$ft   = $wpdb->base_prefix . 'bp_friends';
			$mids = (array) $wpdb->get_col( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT IF( initiator_user_id = %d, friend_user_id, initiator_user_id )
				   FROM {$ft} WHERE ( initiator_user_id = %d OR friend_user_id = %d ) AND is_confirmed = 1",
				$uid,
				$uid,
				$uid
			) );
		}

		$match_ids = array();
		foreach ( $mids as $mid ) {
			$mid = (int) $mid;
			if ( ! $mid || ! get_userdata( $mid ) ) {
				continue;
			}
			if ( self::is_gone( $mid ) ) {
				$gone['matches']++;
				continue;
			}
			// A block after matching should hide them here too.
			if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $uid, $mid ) ) {
				continue;
			}
			$match_ids[] = $mid;
		}
		$matches_total = count( $match_ids );
		list( $a, $b ) = $take( 'matches' );
		foreach ( array_slice( $match_ids, $a, $b ) as $mid ) {
			$matches[] = self::card( $mid, $uid, 'match' );
		}

		/* ---- viewers: gated ---- */
		$premium = class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid );
		$rows    = class_exists( '\CAShaadi\Modules\Premium\Premium' )
			? (array) \CAShaadi\Modules\Premium\Premium::pv_rows( $uid )
			: array();

		$viewers = array();
		$now     = current_time( 'timestamp' );
		list( $va, $vb ) = $take( 'viewers' );
		$vi = 0;

		foreach ( $rows as $r ) {
			$ts  = strtotime( (string) $r->last_at );
			$ago = $ts ? human_time_diff( $ts, $now ) . ' ' . __( 'ago', 'cashaadi-ui' ) : '';

			if ( $premium ) {
				// Full profiles for the page shown; the rest are counted (v1.89.63).
				$pos = $vi++;
				if ( $pos >= $va && $pos < $va + $vb ) {
					$viewers[] = array_merge(
						self::card( (int) $r->viewer_id, $uid, 'viewer' ),
						array( 'ago' => $ago, 'hits' => (int) $r->hits )
					);
				}
				continue;
			}

			/*
			 * Free member: an initial and a time, and nothing that identifies
			 * anyone. No id, no name, no avatar — masking in CSS would leave the
			 * real identities sitting in the JSON response.
			 */
			$name    = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( (int) $r->viewer_id ) : '';
			$initial = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			$viewers[] = array(
				'masked'  => true,
				'initial' => '' !== $initial ? $initial : 'C',
				'ago'     => $ago,
			);
		}

		/*
		 * Declined (owner, 2026-10-26): the requests this member turned down,
		 * newest first, from the record Premium already keeps (csm_rejections).
		 * Premium and Gold only, like Viewed me: free members get initials and
		 * times, never identities. Anyone they have since matched with, or who
		 * has asked again, is left out: they are in Matches or Received.
		 */
		$declined     = array();
		$declined_ids = array();
		global $wpdb;
		$rej = $wpdb->get_results( $wpdb->prepare(
			"SELECT rejected_id, created_at FROM {$wpdb->prefix}csm_rejections WHERE rejecter_id = %d AND source = 'request' ORDER BY created_at DESC LIMIT 500",
			$uid
		) );
		foreach ( (array) $rej as $r ) {
			$did = (int) $r->rejected_id;
			if ( ! $did || ! get_userdata( $did ) ) {
				continue;
			}
			if ( function_exists( 'friends_check_friendship_status' ) && 'not_friends' !== friends_check_friendship_status( $uid, $did ) ) {
				continue;
			}
			$declined_ids[] = array( $did, (string) $r->created_at );
		}
		$now = current_time( 'timestamp' );
		list( $a, $b ) = $premium ? $take( 'declined' ) : array( 0, $page ? 0 : 6 );
		foreach ( array_slice( $declined_ids, $a, $b ) as $d ) {
			$ts  = strtotime( $d[1] );
			$ago = $ts ? sprintf( 'Declined %s ago', human_time_diff( $ts, $now ) ) : '';
			if ( $premium ) {
				$declined[] = array_merge( self::card( $d[0], $uid, 'declined' ), array( 'ago' => $ago ) );
				continue;
			}
			$name       = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $d[0] ) : '';
			$initial    = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			$declined[] = array( 'masked' => true, 'initial' => '' !== $initial ? $initial : 'C', 'ago' => $ago );
		}

		/*
		 * Passed (owner, 2026-10-28): the Discover profiles this member passed,
		 * newest first, so a mistaken Pass can be undone. In "More", with Viewed
		 * me and Declined, and like them Premium and Gold only. Since v1.90.98
		 * also withdrawn requests; neither goes back into Discover (owner,
		 * 2026-10-31). Left out: anyone
		 * since matched or asked, blocked, or back in the deck.
		 */
		$passed     = array();
		$passed_ids = array();
		if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
			$seen = \CAShaadi\Modules\Discover\Seen::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.profile_id, s.acted_at FROM {$seen} s
				 WHERE s.viewer_id = %d AND s.action = 'passed'
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}csm_tray t WHERE t.viewer_id = s.viewer_id AND t.profile_id = s.profile_id AND t.status IN ( 'pending', 'saved' ) )
				 ORDER BY s.acted_at DESC LIMIT 500",
				$uid
			) );
			foreach ( (array) $prows as $r ) {
				$pid = (int) $r->profile_id;
				if ( $pid && self::is_gone( $pid ) ) {
					$gone['passed']++;
					continue;
				}
				if ( ! $pid || ! get_userdata( $pid )
					|| ( function_exists( 'friends_check_friendship_status' ) && 'not_friends' !== friends_check_friendship_status( $uid, $pid ) )
					|| ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $uid, $pid ) ) ) {
					continue;
				}
				$passed_ids[] = array( $pid, (string) $r->acted_at );
			}
		}
		// A withdrawn request is listed here too (owner, 2026-10-31), so labelled.
		$withdrawn = class_exists( '\CAShaadi\Modules\Discover\Withdrawn' ) ? array_flip( \CAShaadi\Modules\Discover\Withdrawn::ids( $uid ) ) : array();
		list( $a, $b ) = $premium ? $take( 'passed' ) : array( 0, $page ? 0 : 6 );
		foreach ( array_slice( $passed_ids, $a, $b ) as $d ) {
			$ts  = strtotime( $d[1] );
			$ago = $ts ? sprintf( isset( $withdrawn[ $d[0] ] ) ? 'Request withdrawn %s ago' : 'Passed %s ago', human_time_diff( $ts, $now ) ) : '';
			if ( $premium ) {
				$passed[] = array_merge( self::card( $d[0], $uid, 'passed' ), array( 'ago' => $ago ) );
				continue;
			}
			$name     = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $d[0] ) : '';
			$initial  = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			$passed[] = array( 'masked' => true, 'initial' => '' !== $initial ? $initial : 'C', 'ago' => $ago );
		}

		/*
		 * Saved profiles. These live in the tray as status='saved' rather than in a
		 * table of their own: they are still tray rows, they still count against
		 * the week they were served in, and the weekly reset already leaves them
		 * alone because it only processes liked/passed/expired.
		 *
		 * GATED (owner, 2026-09-05): a free member sees only what they saved THIS
		 * week; Premium keeps the full history. Saving is otherwise unlimited, so
		 * without this the list would quietly become an unlimited shortlist —
		 * which is the thing Premium is meant to be for.
		 *
		 * "This week" is measured from the IST Monday that the tray itself resets
		 * on, via Engine::get_week_id(), so the list empties on exactly the same
		 * boundary the member already understands. Filtering on acted_at (when
		 * they saved) rather than week_assigned (when it was served) is what makes
		 * "saved this week" true for a profile served earlier and saved today.
		 *
		 * Older saves are LOCKED, not removed (owner, 2026-09-05): a free member
		 * still sees that they are there — blurred, with an initial and how long
		 * ago — so the value of Premium is visible rather than merely asserted.
		 * They just cannot reach them.
		 *
		 * Identities are still withheld from the response, exactly as the viewers
		 * list does: sending the name and blurring it in CSS would leave the real
		 * data sitting in the JSON, and a paywall that any reader can lift is not
		 * a paywall. The member has of course seen these people before — this
		 * protects the gate, not their privacy.
		 */
		$saved       = array();
		$saved_total = 0;
		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';

		$all = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT profile_id, acted_at FROM {$tray} WHERE viewer_id = %d AND status = 'saved' ORDER BY acted_at DESC",
			$uid
		) );
		$saved_total = count( (array) $all );

		$cutoff = $premium ? '' : self::week_start_ist();
		$now    = current_time( 'timestamp' );

		// Saved requests come first (built below); count them against the page too.
		$open_total = count( $req_saved );
		$room       = $page ? 0 : max( 0, $limit - $open_total );
		foreach ( (array) $all as $row ) {
			$locked = ( '' !== $cutoff && (string) $row->acted_at < $cutoff );

			if ( ! $locked ) {
				$open_total++;
				if ( $room-- > 0 ) {
					// The whole profile, so it opens in the same card as Discover (v1.89.40).
					$saved[] = array_merge( Profile::full( (int) $row->profile_id, $uid ), array( 'kind' => 'discover' ) );
				}
				continue;
			}

			$name    = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( (int) $row->profile_id ) : '';
			$initial = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			$ts      = strtotime( (string) $row->acted_at );

			$saved[] = array(
				'masked'  => true,
				'initial' => '' !== $initial ? $initial : 'C',
				'ago'     => $ts ? sprintf( 'Saved %s ago', human_time_diff( $ts, $now ) ) : 'Saved earlier',
			);
		}

		// Saved requests first: someone is waiting on an answer.
		$saved_req = array();
		foreach ( array_slice( $req_saved, 0, $page ? 0 : $limit ) as $rid ) {
			if ( get_userdata( $rid ) ) {
				$saved_req[] = self::request_card( $rid, $uid );
			}
		}
		$saved = array_merge( $saved_req, $saved );

		if ( $page ) {
			$lists = array( 'received' => $received, 'matches' => $matches, 'sent' => $sent, 'viewers' => $viewers, 'declined' => $declined, 'passed' => $passed );
			return new \WP_REST_Response( array( 'ok' => true, 'page' => $lists[ $ptab ] ?? array() ), 200 );
		}

		return new \WP_REST_Response( array(
			'ok'           => true,
			// Totals beyond the page loaded, for the counts and "Show more" (v1.89.62).
			'receivedTotal' => $received_total,
			'savedOpenTotal' => $open_total,
			'saved'        => $saved,
			'savedTotal'   => $saved_total,
			'savedLocked'  => $premium ? 0 : count( array_filter( $saved, function ( $r ) {
				return ! empty( $r['masked'] );
			} ) ),
			'isPremium'    => (bool) $premium,
			'received'     => $received,
			// The same filter state and sheet as Discover (v1.89.13).
			'filters'      => $has_filters ? \CAShaadi\Modules\Discover\Filters::state( $uid ) : null,
			'hiddenByFilter' => max( 0, $all_rids - count( $rids ) ),
			'matches'      => $matches,
			'matchesTotal' => $matches_total,
			'sent'         => $sent,
			'sentTotal'    => $sent_total,
			'viewers'      => array_slice( $viewers, 0, $premium ? $limit : 6 ),
			'viewersTotal' => count( $rows ),
			'declined'      => $declined,
			'declinedTotal' => count( $declined_ids ),
			'passed'        => $passed,
			'gone'          => $gone,
			'passedTotal'   => count( $passed_ids ),
		), 200 );
	}

	/**
	 * Accept, decline, withdraw — or decide a saved profile.
	 *
	 * The first three are delegated entirely to BuddyPress: it owns the
	 * friendship state machine, the counts, the caches and the notifications.
	 * Writing to wp_bp_friends directly would leave all of those stale.
	 *
	 * like/pass are delegated just as strictly to Discover::act(), which owns the
	 * tray authorisation, the mutual-match detection and the Seen record. A saved
	 * profile is still a live tray row, so deciding it here must be the same
	 * operation as deciding it in Discover — a second implementation would
	 * eventually disagree about whether two people had matched.
	 */
	/**
	 * Start of the current tray week, as a site-time datetime string.
	 *
	 * Uses the Discover engine's own week id so the Saved list and the tray reset
	 * cannot drift apart — both must mean the same Monday. Falls back to a plain
	 * "monday this week" if the engine is unavailable.
	 */
	private static function week_start_ist() {
		try {
			$tz  = new \DateTimeZone( 'Asia/Kolkata' );
			$now = new \DateTime( 'now', $tz );
			$now->modify( 'monday this week' )->setTime( 0, 0, 0 );
			return $now->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		}
	}

	public static function rest_act( $request ) {
		$me    = get_current_user_id();
		$other = absint( $request->get_param( 'user_id' ) );
		$what  = sanitize_text_field( (string) $request->get_param( 'action' ) );

		if ( ! $other || $other === $me ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'Invalid request.', 'cashaadi-ui' ) ), 200 );
		}

		$done = false;

		// Saved for later stops once the request is answered (or is saved again).
		if ( in_array( $what, array( 'accept', 'reject' ), true ) ) {
			self::req_saved_set( $me, $other, false );
		}

		if ( 'save_request' === $what ) {
			$pending = function_exists( 'friends_check_friendship_status' ) && 'awaiting_response' === friends_check_friendship_status( $me, $other );
			if ( $pending ) {
				self::req_saved_set( $me, $other, true );
			}
			$done = $pending;
		} elseif ( 'accept' === $what && function_exists( 'friends_accept_friendship' ) && function_exists( 'friends_get_friendship_id' ) ) {
			$fid  = friends_get_friendship_id( $other, $me );
			$done = $fid ? (bool) friends_accept_friendship( $fid ) : false;
		} elseif ( 'reject' === $what && function_exists( 'friends_reject_friendship' ) && function_exists( 'friends_get_friendship_id' ) ) {
			$fid  = friends_get_friendship_id( $other, $me );
			$done = $fid ? (bool) friends_reject_friendship( $fid ) : false;

			// Premium's insight features count declines; keep that record intact.
			if ( $done && class_exists( '\CAShaadi\Modules\Premium\Premium' )
				&& method_exists( '\CAShaadi\Modules\Premium\Premium', 'log_rejection' ) ) {
				\CAShaadi\Modules\Premium\Premium::log_rejection( $me, $other, 'request' );
			}
		} elseif ( 'withdraw' === $what && function_exists( 'friends_withdraw_friendship' ) ) {
			$done = (bool) friends_withdraw_friendship( $me, $other );
		} elseif ( 'unmatch' === $what && function_exists( 'friends_remove_friend' ) && function_exists( 'friends_check_friendship_status' ) ) {
			// Remove a match (owner, 2026-10-28). Either side may; the other is not told.
			$done = 'is_friend' === friends_check_friendship_status( $me, $other ) && (bool) friends_remove_friend( $me, $other );
		} elseif ( 'unpass' === $what && Membership::is_premium( $me ) && class_exists( '\CAShaadi\Modules\Discover\Withdrawn' ) ) {
			// A passed profile, from More → Passed: back in the deck, then the request.
			$done = \CAShaadi\Modules\Discover\Withdrawn::restore( $me, $other );
			if ( $done ) {
				$res  = \CAShaadi\Modules\Discover\Discover::act( $me, $other, 'liked' );
				$done = ! empty( $res['ok'] );
			}
		} elseif ( in_array( $what, array( 'like', 'pass' ), true ) && class_exists( '\CAShaadi\Modules\Discover\Discover' ) ) {
			$res  = \CAShaadi\Modules\Discover\Discover::act( $me, $other, 'like' === $what ? 'liked' : 'passed' );
			$done = ! empty( $res['ok'] );
		}

		return new \WP_REST_Response( array(
			'ok'      => $done,
			'message' => $done ? '' : __( 'That did not work. Please refresh and try again.', 'cashaadi-ui' ),
		), 200 );
	}
}
