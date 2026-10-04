<?php
/**
 * WP-CLI for the journey rails (Analytics\Journey).
 *
 *   wp csm journey backfill --days=90     rebuild past days of activity and conversations
 *   wp csm journey nightly                run last night's job now
 *   wp csm journey export member_day [--since=2026-10-01] > member_day.csv
 *   wp csm journey export events     [--since=...]        > events.csv
 *   wp csm journey export impressions [--since=...]       > impressions.csv
 *   wp csm journey export member_state | convo_day [--since=...]
 */

namespace CAShaadi\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JourneyCli {

	/**
	 * Rebuild member_day for past days from existing tables.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : How many days back from yesterday. Default 90.
	 */
	public function backfill( $args, $assoc ) {
		$days = max( 1, (int) ( $assoc['days'] ?? 90 ) );
		for ( $i = $days; $i >= 1; $i-- ) {
			$day = wp_date( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
			$n   = Journey::compute_day( $day );
			\WP_CLI::log( $day . ': ' . $n . ' rows' );
		}
		\WP_CLI::success( 'Backfilled ' . $days . ' days. Run `wp csm journey states` once to record everyone\'s starting state.' );
	}

	/** Record every member's current state once (a baseline; later rows are changes only). */
	public function states() {
		global $wpdb;
		$n = 0;
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ) as $uid ) {
			Journey::record_state( (int) $uid, 'baseline' );
			$n++;
		}
		\WP_CLI::success( 'Checked ' . $n . ' members.' );
	}

	/** Run the nightly job now (yesterday's activity + state sweep). */
	public function nightly() {
		Journey::nightly();
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Print a table as CSV.
	 *
	 * ## OPTIONS
	 *
	 * <what>
	 * : member_day, member_state, convo_day, events or impressions.
	 *
	 * [--since=<date>]
	 * : Only rows on or after this date (Y-m-d).
	 */
	public function export( $args, $assoc ) {
		global $wpdb;
		$what  = $args[0];
		$since = isset( $assoc['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $assoc['since'] ) ? $assoc['since'] : '2000-01-01';
		$map   = array(
			'member_day'  => array( $wpdb->prefix . 'csm_member_day', 'day' ),
			'events'      => array( $wpdb->prefix . 'csm_event_log', 'created_at' ),
			'impressions' => array( $wpdb->prefix . 'csm_impressions', 'served_at' ),
			'member_state'=> array( $wpdb->prefix . 'csm_member_state', 'changed_at' ),
			'convo_day'   => array( $wpdb->prefix . 'csm_convo_day', 'day' ),
		);
		if ( ! isset( $map[ $what ] ) ) {
			\WP_CLI::error( 'Use member_day, member_state, convo_day, events or impressions.' );
		}
		list( $t, $col ) = $map[ $what ];
		$out   = fopen( 'php://stdout', 'w' );
		$first = true;
		$off   = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$col} >= %s ORDER BY {$col} LIMIT %d, 5000", $since, $off ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				if ( $first ) {
					fputcsv( $out, array_keys( $r ) );
					$first = false;
				}
				fputcsv( $out, $r );
			}
			$off += 5000;
		} while ( $rows && count( $rows ) === 5000 );
	}
}
