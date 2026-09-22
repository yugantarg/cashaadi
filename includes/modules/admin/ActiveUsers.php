<?php
/**
 * Daily / weekly / monthly active users.
 *
 * "Active" = a logged-in request to the site on that day (IST) — any page,
 * the app screens, a magic-link sign-in, a REST call from the app. That is
 * what the owner asked for ("based on login or access"), and it is the same
 * signal BuddyPress uses for last-activity.
 *
 * BuddyPress keeps one timestamp per member, which gives today's numbers but
 * no history. This keeps one row per member per day (wp_csm_active_days) so
 * DAU can be charted and yesterday's figure never changes under us. Rows are
 * tiny (user id + date) and are pruned after 400 days.
 *
 * Admins are recorded but excluded from every count: the owner's test logins
 * are not members.
 */

namespace CAShaadi\Modules\Admin;

use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActiveUsers {

	const META = 'csm_active_day';

	public static function register() {
		Migrator::register( 'active_days', array( __CLASS__, 'schema' ) );
		// Late enough that the user is known; runs on every logged-in request
		// but does one usermeta read (cached) unless the day has changed.
		add_action( 'init', array( __CLASS__, 'touch' ), 50 );
		add_action( 'csm_active_days_prune', array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( 'csm_active_days_prune' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'csm_active_days_prune' );
		}
	}

	public static function schema( $wpdb ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
 day DATE NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY  (day, user_id),
 KEY user_id (user_id)
) " . $wpdb->get_charset_collate() . ';';
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_active_days';
	}

	/** Record today for the current member, once per day. */
	public static function touch() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		if ( (string) get_user_meta( $uid, self::META, true ) === $today ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (day, user_id) VALUES (%s, %d)', $today, $uid ) );
		update_user_meta( $uid, self::META, $today );
	}

	public static function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . self::table() . " WHERE day < DATE_SUB(CURDATE(), INTERVAL 400 DAY)" );
	}

	/* ------------------------------------------------------------ counts */

	/** Admin user ids, excluded from every count. */
	private static function admin_ids() {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		}
		return $ids;
	}

	private static function not_admin_sql( $col = 'user_id' ) {
		$ids = self::admin_ids();
		return $ids ? " AND {$col} NOT IN (" . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * Distinct members active in the $days days ending $end (inclusive), from
	 * our table. $end is a Y-m-d in site time; default today.
	 */
	public static function active( $days, $end = null ) {
		global $wpdb;
		$end   = $end ? $end : current_time( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( $end . ' -' . ( (int) $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(DISTINCT user_id) FROM ' . self::table() . ' WHERE day BETWEEN %s AND %s' . self::not_admin_sql(),
			$start, $end
		) );
	}

	/**
	 * The same three windows from BuddyPress's last-activity stamp. Correct for
	 * "now" from day one, before our table has history; used as the figure
	 * until the table has covered the window.
	 */
	public static function active_bp( $days ) {
		global $wpdb;
		if ( ! function_exists( 'bp_core_get_table_prefix' ) ) {
			return 0;
		}
		$t = bp_core_get_table_prefix() . 'bp_activity';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT user_id) FROM {$t} WHERE type = 'last_activity' AND date_recorded > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)" . self::not_admin_sql(),
			(int) $days
		) );
	}

	/** Days of history the table holds (distinct days recorded). */
	public static function history_days() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT day) FROM ' . self::table() );
	}

	/** DAU per day for the last $n days, oldest first: [ 'Y-m-d' => int ]. */
	public static function daily_series( $n = 30 ) {
		global $wpdb;
		$start = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( (int) $n - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT day, COUNT(*) n FROM ' . self::table() . ' WHERE day >= %s' . self::not_admin_sql() . ' GROUP BY day ORDER BY day',
			$start
		) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->day ] = (int) $r->n;
		}
		return $out;
	}

	/** Total non-admin accounts, for the "% of members" figure. */
	public static function members_total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE 1=1" . self::not_admin_sql( 'ID' ) );
	}

	/* --------------------------------------------------------------- card */

	/** The DAU / WAU / MAU card for the Sales Dashboard. */
	public static function card() {
		$hist  = self::history_days();
		$total = max( 1, self::members_total() );
		$rows  = array(
			array( 'DAU', 1,  'today' ),
			array( 'WAU', 7,  'last 7 days' ),
			array( 'MAU', 30, 'last 30 days' ),
		);

		$h  = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:15px 0">';
		foreach ( $rows as $r ) {
			list( $label, $days, $sub ) = $r;
			// Our table once it covers the window; BuddyPress's stamp until then.
			$from_table = $hist >= $days;
			$n          = $from_table ? self::active( $days ) : self::active_bp( $days );
			$pct        = round( 100 * $n / $total );
			$h .= '<div style="flex:1 1 150px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px">'
				. '<div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.04em">' . esc_html( $label ) . '</div>'
				. '<div style="font-size:28px;font-weight:600;line-height:1.2">' . number_format_i18n( $n ) . '</div>'
				. '<div style="font-size:12px;color:#666">' . esc_html( $sub ) . ' · ' . $pct . '% of members' . ( $from_table ? '' : ' · from BuddyPress last-activity' ) . '</div>'
				. '</div>';
		}
		$h .= '</div>';

		$series = self::daily_series( 30 );
		if ( count( $series ) >= 2 ) {
			$max = max( 1, max( $series ) );
			$h  .= '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin-bottom:15px">'
				. '<div style="font-size:12px;color:#666;margin-bottom:8px">Daily active members, last ' . count( $series ) . ' days</div>'
				. '<div style="display:flex;align-items:flex-end;gap:3px;height:60px">';
			foreach ( $series as $day => $n ) {
				$px = max( 2, round( 56 * $n / $max ) );
				$h .= '<div title="' . esc_attr( $day . ': ' . $n ) . '" style="flex:1;height:' . $px . 'px;background:#7a1220;border-radius:2px 2px 0 0"></div>';
			}
			$h .= '</div></div>';
		}
		return $h;
	}
}
