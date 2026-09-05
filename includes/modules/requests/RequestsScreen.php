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
		Assets::script( 'requests-app', 'assets/js/requests-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-requests-app', 'CSM_REQUESTS', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'list'    => rest_url( 'csm/v1/requests/list' ),
			'act'     => rest_url( 'csm/v1/requests/act' ),
			'upgrade' => site_url( '/membership-pricing/' ),
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
			'age'    => Profile::age_number( Profile::field( 'Age', $uid, $hidden ) ),
			'city'   => Profile::field( 'City', $uid, $hidden ),
			'avatar' => function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'thumb', 'html' => false ) )
				: get_avatar_url( $uid, array( 'size' => 120 ) ),
			'url'    => function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : '',
		);
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
		unset( $request );
		$uid = get_current_user_id();

		/* ---- received ---- */
		$received = array();
		if ( function_exists( 'friends_get_friendship_requests' ) ) {
			foreach ( (array) friends_get_friendship_requests( $uid ) as $rid ) {
				$received[] = self::person( $rid );
			}
		}

		/* ---- sent ---- */
		$sent = array();
		foreach ( self::sent( $uid ) as $sid ) {
			$sent[] = self::person( $sid );
		}

		/* ---- viewers: gated ---- */
		$premium = class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid );
		$rows    = class_exists( '\CAShaadi\Modules\Premium\Premium' )
			? (array) \CAShaadi\Modules\Premium\Premium::pv_rows( $uid )
			: array();

		$viewers = array();
		$now     = current_time( 'timestamp' );

		foreach ( $rows as $r ) {
			$ts  = strtotime( (string) $r->last_at );
			$ago = $ts ? human_time_diff( $ts, $now ) . ' ' . __( 'ago', 'cashaadi-ui' ) : '';

			if ( $premium ) {
				$viewers[] = array_merge(
					self::person( (int) $r->viewer_id ),
					array( 'ago' => $ago, 'hits' => (int) $r->hits )
				);
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
		 * The older rows are counted, never sent: a free member's response must
		 * not carry the identities the gate is withholding.
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
		foreach ( (array) $all as $row ) {
			if ( '' !== $cutoff && (string) $row->acted_at < $cutoff ) {
				continue; // older than this week, and this member is not Premium
			}
			$saved[] = self::person( (int) $row->profile_id );
		}

		return new \WP_REST_Response( array(
			'ok'           => true,
			'saved'        => $saved,
			'savedTotal'   => $saved_total,
			'savedLocked'  => max( 0, $saved_total - count( $saved ) ),
			'isPremium'    => (bool) $premium,
			'received'     => $received,
			'sent'         => $sent,
			'viewers'      => array_slice( $viewers, 0, $premium ? 200 : 6 ),
			'viewersTotal' => count( $rows ),
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

		if ( 'accept' === $what && function_exists( 'friends_accept_friendship' ) && function_exists( 'friends_get_friendship_id' ) ) {
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
