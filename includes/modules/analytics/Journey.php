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
 * 1. wp_csm_member_day — what HAPPENED: one row per member per day (IST)
 *    on which anything happened, written nightly for the day before: active,
 *    profiles served and their average match points and popularity (from
 *    wp_csm_impressions), likes and passes given, likes received, requests
 *    sent/received, matches, messages sent/received, new profile viewers.
 *    Quiet days have no row. Rebuild past days with
 *    `wp csm journey backfill --days=90`.
 * 1b. wp_csm_member_state — what they WERE, as a change history: a row only
 *    when something changes (owner, 2026-10-04: "only when there is an edit,
 *    otherwise we create a lot of data"). Gender, age, premium, quota,
 *    photos, verified, paused, closed, details left. Written after a profile
 *    edit, a photo/verification/pause/close change or a membership change,
 *    and checked nightly for changes nothing announces (premium expiry).
 * 1c. wp_csm_convo_day — message history per conversation: one row per pair
 *    per day they messaged, with each side's count. Kept even if Better
 *    Messages' own rows are later deleted.
 * 2. Events in wp_csm_event_log (the whole log is kept permanently, v1.74.1):
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
		Migrator::register( 'member_state', array( __CLASS__, 'state_schema' ) );
		Migrator::register( 'convo_day', array( __CLASS__, 'convo_schema' ) );

		// State changes: queued during the request, written once at shutdown.
		add_action( 'xprofile_updated_profile', function ( $uid ) { Journey::touch( $uid, 'profile' ); }, 20, 1 );
		add_action( 'pmpro_after_change_membership_level', function ( $level, $uid ) { Journey::touch( $uid, 'membership' ); }, 20, 2 );
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_meta' ), 20, 3 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush_state' ) );

		add_action( self::CRON, array( __CLASS__, 'nightly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order' ) );
		add_action( 'template_redirect', array( __CLASS__, 'on_pricing_view' ), 5 );
		add_action( 'csm_mutual_match', array( __CLASS__, 'on_match' ), 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm journey', __NAMESPACE__ . '\\JourneyCli' );
			\WP_CLI::add_command( 'csm audience', __NAMESPACE__ . '\\AudienceCli' );
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
			PRIMARY KEY  (user_id, day),
			KEY day (day)
		) {$charset};";
	}

	public static function state_schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_member_state';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			changed_at DATETIME NOT NULL,
			cause VARCHAR(24) NOT NULL DEFAULT '',
			gender CHAR(1) NOT NULL DEFAULT '',
			age TINYINT UNSIGNED NULL,
			premium TINYINT UNSIGNED NOT NULL DEFAULT 0,
			quota SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			photos TINYINT UNSIGNED NOT NULL DEFAULT 0,
			verified TINYINT UNSIGNED NOT NULL DEFAULT 0,
			paused TINYINT UNSIGNED NOT NULL DEFAULT 0,
			closed TINYINT UNSIGNED NOT NULL DEFAULT 0,
			details_left TINYINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id, changed_at)
		) {$charset};";
	}

	public static function convo_schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_convo_day';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			user_a BIGINT UNSIGNED NOT NULL,
			user_b BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			msgs_a SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			msgs_b SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (user_a, user_b, day),
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

	/** Yesterday's activity, and a sweep for state changes nothing announced. */
	public static function nightly() {
		$day = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
		self::compute_day( $day );
		self::sweep_state();
	}

	/* ------------------------------------------------------- state history */

	private static $dirty = array();

	/** Meta keys whose change changes "what they are". */
	const STATE_META = array( 'csm_photos', 'csm_av_status', 'csm_deactivated', 'csm_closed' );

	public static function on_meta( $meta_id, $uid, $key ) {
		if ( in_array( $key, self::STATE_META, true ) ) {
			self::touch( $uid, str_replace( 'csm_', '', $key ) );
		}
	}

	public static function touch( $uid, $cause ) {
		$uid = (int) $uid;
		if ( $uid ) {
			self::$dirty[ $uid ] = (string) $cause;
		}
	}

	public static function flush_state() {
		foreach ( self::$dirty as $uid => $cause ) {
			self::record_state( $uid, $cause );
		}
		self::$dirty = array();
	}

	/** What $uid is right now. */
	public static function current_state( $uid ) {
		$uid    = (int) $uid;
		$photos = get_user_meta( $uid, 'csm_photos', true );
		$g      = function_exists( 'cashaadi' ) ? trim( (string) cashaadi()->get_gender( $uid ) ) : '';
		$age    = null;
		if ( class_exists( '\BP_XProfile_ProfileData' ) ) {
			$dob = strtotime( (string) \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_DOB, $uid ) );
			$age = $dob ? (int) floor( ( time() - $dob ) / ( 365.25 * DAY_IN_SECONDS ) ) : null;
		}
		$left = null;
		if ( class_exists( '\CAShaadi\Core\Profile' ) ) {
			$c    = \CAShaadi\Core\Profile::completion( $uid );
			$left = is_array( $c ) && isset( $c['outstanding'] ) ? min( 255, (int) $c['outstanding'] ) : null;
		}
		return array(
			'gender'       => 'Female' === $g ? 'F' : ( 'Male' === $g ? 'M' : '' ),
			'age'          => $age,
			'premium'      => class_exists( '\CAShaadi\Core\Membership' ) && \CAShaadi\Core\Membership::is_premium( $uid ) ? 1 : 0,
			'quota'        => class_exists( '\CAShaadi\Modules\Discover\Discover' ) ? (int) \CAShaadi\Modules\Discover\Discover::quota_for( $uid ) : 0,
			'photos'       => is_array( $photos ) ? min( 255, count( $photos ) ) : 0,
			'verified'     => 'approved' === (string) get_user_meta( $uid, 'csm_av_status', true ) ? 1 : 0,
			'paused'       => get_user_meta( $uid, 'csm_deactivated', true ) ? 1 : 0,
			'closed'       => get_user_meta( $uid, 'csm_closed', true ) ? 1 : 0,
			'details_left' => $left,
		);
	}

	/** Insert a row only if something differs from the member's last row. */
	public static function record_state( $uid, $cause ) {
		global $wpdb;
		$t = $wpdb->prefix . 'csm_member_state';
		if ( ! $uid || ! self::exists( $t ) || user_can( (int) $uid, 'manage_options' ) || ! get_userdata( (int) $uid ) ) {
			return;
		}
		$now  = self::current_state( $uid );
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT gender, age, premium, quota, photos, verified, paused, closed, details_left FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
			(int) $uid
		), ARRAY_A );
		if ( $last ) {
			$same = true;
			foreach ( $now as $k => $v ) {
				if ( (string) $v !== (string) $last[ $k ] ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return;
			}
		}
		$wpdb->insert( $t, array_merge( array(
			'user_id'    => (int) $uid,
			'changed_at' => current_time( 'mysql' ),
			'cause'      => $last ? substr( (string) $cause, 0, 24 ) : 'first',
		), $now ) );
	}

	/**
	 * Nightly: members active in the last 2 days, plus everyone whose premium
	 * could have lapsed, checked for a change. Writes only differences.
	 */
	public static function sweep_state() {
		global $wpdb;
		$ids = array();
		$ad  = $wpdb->prefix . 'csm_active_days';
		if ( self::exists( $ad ) ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$ad} WHERE day >= %s", wp_date( 'Y-m-d', strtotime( '-2 days' ) ) ) ) );
		}
		$mu = $wpdb->prefix . 'pmpro_memberships_users';
		if ( self::exists( $mu ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$mu} WHERE modified >= DATE_SUB(NOW(), INTERVAL 2 DAY)" ) ) );
		}
		foreach ( array_unique( $ids ) as $uid ) {
			self::record_state( $uid, 'nightly' );
		}
	}

	/* -------------------------------------------------------- one day */

	/**
	 * Write $day (IST, Y-m-d): a row for each member something happened to,
	 * and the day's conversations.
	 *
	 * @return int rows written
	 */
	public static function compute_day( $day ) {
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
			self::convo_day( $day, $utc_a, $utc_b );
		}

		// New profile viewers (csm_profile_views keeps first_at per pair).
		if ( self::exists( $p . 'csm_profile_views' ) ) {
			$put( 'views_recv', $q( $wpdb->prepare( "SELECT viewed_id uid, COUNT(*) v FROM {$p}csm_profile_views WHERE first_at BETWEEN %s AND %s GROUP BY viewed_id", $ist_a, $ist_b ) ) );
		}

		$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		$genders = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$p}bp_xprofile_data WHERE field_id = %d", Config::FIELD_GENDER ) ) as $g ) {
			$genders[ (int) $g->user_id ] = 'Female' === $g->value ? 'F' : ( 'Male' === $g->value ? 'M' : '' );
		}
		$members = array_keys( $rows );

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
			$wpdb->replace( self::table(), $row );
			$n++;
		}
		return $n;
	}

	/**
	 * One row per pair per day they messaged: each side's count. One-to-one
	 * threads only (a group thread has no single "other side").
	 */
	private static function convo_day( $day, $utc_a, $utc_b ) {
		global $wpdb;
		$t  = $wpdb->prefix . 'csm_convo_day';
		$mm = $wpdb->prefix . 'bm_message_messages';
		$mr = $wpdb->prefix . 'bm_message_recipients';
		if ( ! self::exists( $t ) ) {
			return;
		}
		$counts = $wpdb->get_results( $wpdb->prepare(
			"SELECT thread_id, sender_id, COUNT(*) n FROM {$mm} WHERE date_sent BETWEEN %s AND %s GROUP BY thread_id, sender_id",
			$utc_a, $utc_b
		) );
		$by_thread = array();
		foreach ( (array) $counts as $c ) {
			$by_thread[ (int) $c->thread_id ][ (int) $c->sender_id ] = (int) $c->n;
		}
		foreach ( $by_thread as $thread => $senders ) {
			$people = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$mr} WHERE thread_id = %d", $thread ) ) );
			$people = array_values( array_unique( array_merge( $people, array_keys( $senders ) ) ) );
			if ( 2 !== count( $people ) ) {
				continue;
			}
			sort( $people );
			list( $a, $b ) = $people;
			$wpdb->replace( $t, array(
				'user_a' => $a,
				'user_b' => $b,
				'day'    => $day,
				'msgs_a' => $senders[ $a ] ?? 0,
				'msgs_b' => $senders[ $b ] ?? 0,
			) );
		}
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
