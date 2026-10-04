<?php
/**
 * The member journey: rails for "what made them pay, stay, or go quiet".
 *
 * Owner, 2026-10-04: "We need proper data to understand what causes users to
 * pay ... what exactly their experience was before they paid. Did they get
 * more popular profiles or profiles that match them more? Data is too thin
 * now, but I want the rails in place so I can see what patterns cause a user
 * to subscribe, increase engagement, or drop off and become inactive."
 *
 * Read-only for the product: nothing ranks or decides off these tables.
 *
 * 1. wp_csm_member_day — one row per member per day (IST), written nightly
 *    for the day before. Two halves:
 *    - what HAPPENED that day: active, profiles served and their average
 *      match points and popularity (from wp_csm_impressions), likes and
 *      passes given, likes received, requests sent/received, matches, messages
 *      sent/received, profile views received;
 *    - what they WERE that night: premium, weekly quota, photos, verified,
 *      paused, gender. Written for every member, so "was free and active on
 *      the 12th, premium by the 20th" can be read straight off the table.
 *    Older days can be rebuilt (state columns left NULL) with
 *    `wp csm journey backfill --days=90`.
 * 2. Events in wp_csm_event_log, kept forever (exempt from the 180-day prune):
 *    - purchase: the order, and the member's journey totals up to that moment
 *      plus where their last pricing-page visit came from;
 *    - pricing_view: each visit to the pricing page (at most hourly per
 *      member), with what sent them (?src= or the referring screen);
 *    - match_made: both member ids, at the moment of the mutual match.
 * 3. `wp csm journey export member_day|events|impressions` prints CSV.
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Config;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Journey {

	const CRON = 'csm_journey_daily';

	/** Event types never pruned: the record a later analysis depends on. */
	const KEEP_EVENTS = array( 'account_deleted', 'purchase', 'pricing_view', 'match_made', 'once_field_changed' );

	public static function register() {
		Migrator::register( 'member_day', array( __CLASS__, 'schema' ) );

		add_action( self::CRON, array( __CLASS__, 'nightly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order' ) );
		add_action( 'template_redirect', array( __CLASS__, 'on_pricing_view' ), 5 );
		add_action( 'csm_mutual_match', array( __CLASS__, 'on_match' ), 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm journey', __NAMESPACE__ . '\\JourneyCli' );
		}
	}

	public static function schema( $wpdb ) {
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			user_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			active TINYINT UNSIGNED NOT NULL DEFAULT 0,
			served SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			served_match FLOAT NULL,
			served_pop FLOAT NULL,
			likes_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			passes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			likes_recv SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			req_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			req_recv SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			msgs_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			msgs_recv SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			views_recv SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			gender CHAR(1) NOT NULL DEFAULT '',
			premium TINYINT NULL,
			quota SMALLINT NULL,
			photos TINYINT NULL,
			verified TINYINT NULL,
			paused TINYINT NULL,
			PRIMARY KEY  (user_id, day),
			KEY day (day)
		) {$charset};";
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_member_day';
	}

	private static function exists( $t ) {
		global $wpdb;
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			// 02:30 IST tomorrow, then daily.
			$tz   = wp_timezone();
			$next = ( new \DateTimeImmutable( 'tomorrow 02:30', $tz ) )->getTimestamp();
			wp_schedule_event( $next, 'daily', self::CRON );
		}
	}

	/** Yesterday, with tonight's state. */
	public static function nightly() {
		$day = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
		self::compute_day( $day, true );
	}

	/* -------------------------------------------------------- one day */

	/**
	 * Write $day (IST, Y-m-d) for every member. $with_state also records what
	 * each member is right now (only true for the most recent day).
	 *
	 * @return int rows written
	 */
	public static function compute_day( $day, $with_state = false ) {
		global $wpdb;
		if ( ! self::exists( self::table() ) ) {
			return 0;
		}
		$tz    = wp_timezone();
		$ist_a = $day . ' 00:00:00';
		$ist_b = $day . ' 23:59:59';
		$utc_a = ( new \DateTimeImmutable( $ist_a, $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$utc_b = ( new \DateTimeImmutable( $ist_b, $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$rows = array(); // uid => [col => value]
		$put  = function ( $col, $pairs ) use ( &$rows ) {
			foreach ( (array) $pairs as $r ) {
				$rows[ (int) $r->uid ][ $col ] = $r->v;
			}
		};
		$q = function ( $sql ) use ( $wpdb ) {
			return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by caller
		};

		$p = $wpdb->prefix;

		// Active (IST days, ActiveUsers).
		if ( self::exists( $p . 'csm_active_days' ) ) {
			$put( 'active', $q( $wpdb->prepare( "SELECT user_id uid, 1 v FROM {$p}csm_active_days WHERE day = %s", $day ) ) );
		}

		// Served, and what was served: impressions where logged, else first serving.
		if ( self::exists( $p . 'csm_impressions' ) && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}csm_impressions WHERE served_at BETWEEN %s AND %s LIMIT 1", $ist_a, $ist_b ) ) ) {
			$match = "COALESCE(JSON_EXTRACT(parts,'$.tongue'),0)+COALESCE(JSON_EXTRACT(parts,'$.community'),0)+COALESCE(JSON_EXTRACT(parts,'$.religion'),0)+COALESCE(JSON_EXTRACT(parts,'$.city'),0)+COALESCE(JSON_EXTRACT(parts,'$.diet'),0)+COALESCE(JSON_EXTRACT(parts,'$.age'),0)";
			foreach ( (array) $q( $wpdb->prepare(
				"SELECT viewer_id uid, COUNT(*) n, AVG({$match}) m, AVG(JSON_EXTRACT(profile_snap,'$.pop')) pp
				 FROM {$p}csm_impressions WHERE served_at BETWEEN %s AND %s GROUP BY viewer_id",
				$ist_a, $ist_b
			) ) as $r ) {
				$rows[ (int) $r->uid ]['served']       = $r->n;
				$rows[ (int) $r->uid ]['served_match'] = $r->m;
				$rows[ (int) $r->uid ]['served_pop']   = $r->pp;
			}
		} elseif ( self::exists( $p . 'csm_seen' ) ) {
			$put( 'served', $q( $wpdb->prepare( "SELECT viewer_id uid, COUNT(*) v FROM {$p}csm_seen WHERE first_seen_at BETWEEN %s AND %s GROUP BY viewer_id", $ist_a, $ist_b ) ) );
		}

		// Likes and passes given, likes received (csm_seen, IST).
		if ( self::exists( $p . 'csm_seen' ) ) {
			$put( 'likes_sent', $q( $wpdb->prepare( "SELECT viewer_id uid, COUNT(*) v FROM {$p}csm_seen WHERE action = 'liked' AND acted_at BETWEEN %s AND %s GROUP BY viewer_id", $ist_a, $ist_b ) ) );
			$put( 'passes', $q( $wpdb->prepare( "SELECT viewer_id uid, COUNT(*) v FROM {$p}csm_seen WHERE action = 'passed' AND acted_at BETWEEN %s AND %s GROUP BY viewer_id", $ist_a, $ist_b ) ) );
			$put( 'likes_recv', $q( $wpdb->prepare( "SELECT profile_id uid, COUNT(*) v FROM {$p}csm_seen WHERE action = 'liked' AND acted_at BETWEEN %s AND %s GROUP BY profile_id", $ist_a, $ist_b ) ) );
		}

		// Requests (bp_friends, UTC). A friendship row is the request.
		if ( self::exists( $p . 'bp_friends' ) ) {
			$put( 'req_sent', $q( $wpdb->prepare( "SELECT initiator_user_id uid, COUNT(*) v FROM {$p}bp_friends WHERE date_created BETWEEN %s AND %s GROUP BY initiator_user_id", $utc_a, $utc_b ) ) );
			$put( 'req_recv', $q( $wpdb->prepare( "SELECT friend_user_id uid, COUNT(*) v FROM {$p}bp_friends WHERE date_created BETWEEN %s AND %s GROUP BY friend_user_id", $utc_a, $utc_b ) ) );
		}

		// Matches: the match_made event (exact) when logged, else none.
		if ( self::exists( $p . 'csm_event_log' ) ) {
			$put( 'matches', $q( $wpdb->prepare(
				"SELECT uid, COUNT(*) v FROM (
				   SELECT actor_id uid FROM {$p}csm_event_log WHERE event_type = 'match_made' AND created_at BETWEEN %s AND %s
				   UNION ALL
				   SELECT target_id uid FROM {$p}csm_event_log WHERE event_type = 'match_made' AND created_at BETWEEN %s AND %s
				 ) m GROUP BY uid",
				$ist_a, $ist_b, $ist_a, $ist_b
			) ) );
		}

		// Messages (Better Messages). Column names checked, not assumed.
		$mm = $p . 'bm_message_messages';
		$mr = $p . 'bm_message_recipients';
		if ( self::exists( $mm ) && self::exists( $mr ) && $wpdb->get_var( "SHOW COLUMNS FROM {$mm} LIKE 'date_sent'" ) ) {
			$put( 'msgs_sent', $q( $wpdb->prepare( "SELECT sender_id uid, COUNT(*) v FROM {$mm} WHERE date_sent BETWEEN %s AND %s GROUP BY sender_id", $utc_a, $utc_b ) ) );
			$put( 'msgs_recv', $q( $wpdb->prepare(
				"SELECT r.user_id uid, COUNT(*) v FROM {$mm} m JOIN {$mr} r ON r.thread_id = m.thread_id AND r.user_id <> m.sender_id
				 WHERE m.date_sent BETWEEN %s AND %s GROUP BY r.user_id",
				$utc_a, $utc_b
			) ) );
		}

		// New profile viewers (csm_profile_views keeps first_at per pair).
		if ( self::exists( $p . 'csm_profile_views' ) ) {
			$put( 'views_recv', $q( $wpdb->prepare( "SELECT viewed_id uid, COUNT(*) v FROM {$p}csm_profile_views WHERE first_at BETWEEN %s AND %s GROUP BY viewed_id", $ist_a, $ist_b ) ) );
		}

		// Everyone, so tonight's state is recorded for quiet members too.
		$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		$closed = class_exists( '\CAShaadi\Modules\Settings\Closed' ) ? \CAShaadi\Modules\Settings\Closed::ids() : array();
		$genders = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$p}bp_xprofile_data WHERE field_id = %d", Config::FIELD_GENDER ) ) as $g ) {
			$genders[ (int) $g->user_id ] = 'Female' === $g->value ? 'F' : ( 'Male' === $g->value ? 'M' : '' );
		}
		$members = $with_state
			? array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ) )
			: array_keys( $rows );

		$n = 0;
		foreach ( $members as $uid ) {
			if ( in_array( $uid, $admins, true ) ) {
				continue;
			}
			$r = isset( $rows[ $uid ] ) ? $rows[ $uid ] : array();
			$row = array(
				'user_id'      => $uid,
				'day'          => $day,
				'active'       => (int) ! empty( $r['active'] ),
				'served'       => (int) ( $r['served'] ?? 0 ),
				'served_match' => isset( $r['served_match'] ) ? (float) $r['served_match'] : null,
				'served_pop'   => isset( $r['served_pop'] ) ? (float) $r['served_pop'] : null,
				'likes_sent'   => (int) ( $r['likes_sent'] ?? 0 ),
				'passes'       => (int) ( $r['passes'] ?? 0 ),
				'likes_recv'   => (int) ( $r['likes_recv'] ?? 0 ),
				'req_sent'     => (int) ( $r['req_sent'] ?? 0 ),
				'req_recv'     => (int) ( $r['req_recv'] ?? 0 ),
				'matches'      => (int) ( $r['matches'] ?? 0 ),
				'msgs_sent'    => (int) ( $r['msgs_sent'] ?? 0 ),
				'msgs_recv'    => (int) ( $r['msgs_recv'] ?? 0 ),
				'views_recv'   => (int) ( $r['views_recv'] ?? 0 ),
				'gender'       => isset( $genders[ $uid ] ) ? $genders[ $uid ] : '',
			);
			if ( $with_state && ! in_array( $uid, $closed, true ) ) {
				$photos          = get_user_meta( $uid, 'csm_photos', true );
				$row['premium']  = class_exists( '\CAShaadi\Core\Membership' ) && \CAShaadi\Core\Membership::is_premium( $uid ) ? 1 : 0;
				$row['quota']    = class_exists( '\CAShaadi\Modules\Discover\Discover' ) ? (int) \CAShaadi\Modules\Discover\Discover::quota_for( $uid ) : null;
				$row['photos']   = is_array( $photos ) ? min( 99, count( $photos ) ) : 0;
				$row['verified'] = 'approved' === (string) get_user_meta( $uid, 'csm_av_status', true ) ? 1 : 0;
				$row['paused']   = get_user_meta( $uid, 'csm_deactivated', true ) ? 1 : 0;
			} elseif ( ! $r ) {
				continue; // nothing happened and no state to record
			}
			$wpdb->replace( self::table(), $row );
			$n++;
		}
		return $n;
	}

	/* ---------------------------------------------------------- events */

	/** Totals of everything that happened to $uid so far. */
	public static function totals( $uid ) {
		global $wpdb;
		if ( ! self::exists( self::table() ) ) {
			return array();
		}
		$r = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) days_logged, SUM(active) days_active, SUM(served) served, AVG(served_match) avg_match, AVG(served_pop) avg_pop,
			        SUM(likes_sent) likes_sent, SUM(passes) passes, SUM(likes_recv) likes_recv, SUM(req_sent) req_sent, SUM(req_recv) req_recv,
			        SUM(matches) matches, SUM(msgs_sent) msgs_sent, SUM(msgs_recv) msgs_recv, SUM(views_recv) views_recv
			 FROM ' . self::table() . ' WHERE user_id = %d',
			(int) $uid
		), ARRAY_A );
		return $r ? array_map( function ( $v ) { return null === $v ? null : round( (float) $v, 2 ); }, $r ) : array();
	}

	public static function on_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'cashaadi' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_csm_journey_logged' ) ) {
			return;
		}
		$uid = (int) $order->get_user_id();
		if ( ! $uid ) {
			return;
		}
		$premium = false;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === (int) Config::WC_PREMIUM_PRODUCT ) {
				$premium = true;
			}
		}
		$u = get_userdata( $uid );
		cashaadi()->log_event( 'purchase', $uid, (int) $order_id, array(
			'total'        => (float) $order->get_total(),
			'premium'      => $premium ? 1 : 0,
			'days_since_signup' => $u ? (int) floor( ( time() - strtotime( $u->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) : null,
			'gender'       => (string) ( function_exists( 'cashaadi' ) ? cashaadi()->get_gender( $uid ) : '' ),
			'channel'      => (string) get_user_meta( $uid, 'csm_channel', true ),
			'pricing_src'  => (string) get_user_meta( $uid, 'csm_last_pricing_src', true ),
			'pricing_views'=> (int) get_user_meta( $uid, 'csm_pricing_views', true ),
			'journey'      => self::totals( $uid ),
		) );
		$order->update_meta_data( '_csm_journey_logged', 1 );
		$order->save();
	}

	public static function on_pricing_view() {
		if ( ! is_user_logged_in() || ! is_page( 'membership-pricing' ) || ! function_exists( 'cashaadi' ) ) {
			return;
		}
		$uid = get_current_user_id();
		if ( user_can( $uid, 'manage_options' ) ) {
			return;
		}
		$src = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $src ) {
			$ref = wp_get_referer();
			$src = $ref ? (string) wp_parse_url( $ref, PHP_URL_PATH ) : 'direct';
		}
		update_user_meta( $uid, 'csm_last_pricing_src', substr( $src, 0, 100 ) );
		update_user_meta( $uid, 'csm_pricing_views', (int) get_user_meta( $uid, 'csm_pricing_views', true ) + 1 );
		// At most one event an hour per member: a reload is not a new intent.
		if ( get_transient( 'csm_pv_' . $uid ) ) {
			return;
		}
		set_transient( 'csm_pv_' . $uid, 1, HOUR_IN_SECONDS );
		cashaadi()->log_event( 'pricing_view', $uid, 0, array( 'src' => $src, 'premium' => class_exists( '\CAShaadi\Core\Membership' ) && \CAShaadi\Core\Membership::is_premium( $uid ) ? 1 : 0 ) );
	}

	public static function on_match( $a, $b ) {
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'match_made', (int) $a, (int) $b, array() );
		}
	}
}
