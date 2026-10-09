<?php
/**
 * Unanswered match requests (owner, 2026-10-09).
 *
 * On 9 Oct 2,446 requests were pending, 94% of them waiting on women. For 60%
 * the woman had not logged in since the request arrived; for 40% she had,
 * and still not answered. Two nudges, one for each:
 *
 * 1. Email, at most once a week: to a member with pending requests who has
 *    not been back for 3+ days and has at least one request from after her
 *    last visit. "N people have sent you match requests". Rides the daily
 *    engagement cron; the week in the type key makes it once a week.
 * 2. Popup on login: "N match requests waiting", shown again only when a new
 *    request has arrived since it was last shown.
 *
 * Requests from blocked or closed accounts are not counted.
 */

namespace CAShaadi\Modules\Matches;

use CAShaadi\Modules\Emails\Engagement;
use CAShaadi\Modules\Emails\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestsWaiting {

	const SEEN_META = 'csm_reqwait_seen_id'; // highest request id the popup has covered
	const AWAY_DAYS = 3;

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		// After Engagement's own daily jobs.
		add_action( 'csm_engagement_daily', array( __CLASS__, 'send_emails' ), 20 );
	}

	/** Pending incoming requests: array( count, max id, initiator ids newest first ). */
	public static function pending( $uid ) {
		global $wpdb;
		$uid  = (int) $uid;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, initiator_user_id FROM {$wpdb->prefix}bp_friends WHERE friend_user_id = %d AND is_confirmed = 0 ORDER BY id DESC",
			$uid
		) );
		$hide = class_exists( '\CAShaadi\Modules\Block\Block' ) ? array_flip( array_map( 'intval', (array) \CAShaadi\Modules\Block\Block::hidden_ids( $uid ) ) ) : array();
		$ids  = array();
		$max  = 0;
		foreach ( (array) $rows as $r ) {
			if ( isset( $hide[ (int) $r->initiator_user_id ] ) ) {
				continue;
			}
			$ids[] = (int) $r->initiator_user_id;
			$max   = max( $max, (int) $r->id );
		}
		return array( count( $ids ), $max, $ids );
	}

	private static function url() {
		return home_url( '/requests/' );
	}

	/* ------------------------------------------------------------- popup */

	/** For the app shell: a payload, or false. */
	public static function popup( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		list( $n, $max ) = self::pending( $uid );
		if ( ! $n || $max <= (int) get_user_meta( $uid, self::SEEN_META, true ) ) {
			return false;
		}
		return array(
			'title' => 1 === $n ? '1 match request waiting' : $n . ' match requests waiting',
			'body'  => ( 1 === $n ? 'Someone has' : $n . ' people have' ) . ' sent you a match request. Accept to start talking, or decline so they know.',
			'url'   => self::url(),
			'seen'  => rest_url( 'csm/v1/requests-waiting-seen' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/requests-waiting-seen', array(
			'methods'             => 'POST',
			'callback'            => function () {
				$uid = get_current_user_id();
				list( , $max ) = self::pending( $uid );
				update_user_meta( $uid, self::SEEN_META, $max );
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/* ------------------------------------------------------------- email */

	public static function send_emails() {
		if ( ! class_exists( '\CAShaadi\Modules\Emails\Queue' ) || ! class_exists( '\CAShaadi\Modules\Emails\Engagement' ) ) {
			return;
		}
		global $wpdb;
		$tz    = new \DateTimeZone( 'Asia/Kolkata' );
		$today = new \DateTimeImmutable( 'now', $tz );
		$week  = strtolower( $today->format( 'o-\WW' ) );
		$away  = $today->modify( '-' . self::AWAY_DAYS . ' days' )->format( 'Y-m-d' );

		// Newest pending request per recipient (UTC → IST date).
		$rows = $wpdb->get_results(
			"SELECT friend_user_id uid, DATE(DATE_ADD(MAX(date_created), INTERVAL 330 MINUTE)) newest
			 FROM {$wpdb->prefix}bp_friends WHERE is_confirmed = 0 GROUP BY friend_user_id"
		);
		foreach ( (array) $rows as $r ) {
			$uid  = (int) $r->uid;
			$last = (string) get_user_meta( $uid, 'csm_active_day', true ); // Y-m-d IST
			if ( '' !== $last && ( $last > $away || $r->newest <= $last ) ) {
				continue; // been back recently, or nothing new since the last visit
			}
			if ( user_can( $uid, 'manage_options' )
				|| ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) && \CAShaadi\Modules\Settings\Closed::is_closed( $uid ) )
				|| ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) && \CAShaadi\Modules\Settings\Deactivate::is_paused( $uid ) )
				|| ! Engagement::allowed( $uid, 'csm_email_matches' ) ) {
				continue;
			}
			list( $n, , $ids ) = self::pending( $uid );
			if ( ! $n ) {
				continue;
			}
			Queue::notify( $uid, 'csm-reqwait-' . $week, self::subject( $n ), self::body( $uid, $n, $ids ) );
		}
	}

	private static function subject( $n ) {
		return 1 === $n ? 'Someone is waiting for your answer' : $n . ' people are waiting for your answer';
	}

	private static function body( $uid, $n, $ids ) {
		$names = array();
		foreach ( array_slice( $ids, 0, 3 ) as $id ) {
			$nm = function_exists( 'bp_core_get_user_displayname' ) ? trim( (string) bp_core_get_user_displayname( $id ) ) : '';
			if ( '' !== $nm ) {
				$names[] = '<strong>' . esc_html( $nm ) . '</strong>';
			}
		}
		$rest = $n - count( $names );
		if ( ! $names ) {
			$who = 1 === $n ? 'Someone has' : $n . ' people have';
		} elseif ( $rest > 0 ) {
			$who = implode( ', ', $names ) . ' and ' . $rest . ( 1 === $rest ? ' other have' : ' others have' );
		} else {
			$last = array_pop( $names );
			$who  = ( $names ? implode( ', ', $names ) . ' and ' : '' ) . $last . ( count( $names ) ? ' have' : ' has' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return Engagement::wrap(
			Engagement::greeting( $uid ),
			'<p>' . $who . ' sent you a match request on ' . esc_html( $site ) . ' and ' . ( 1 === $n ? 'is' : 'are' ) . ' waiting for your answer.</p>'
			. '<p>Accept to start a conversation, or decline so they can move on.</p>',
			self::url(),
			'Review your requests'
		);
	}
}
