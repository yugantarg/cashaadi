<?php
/**
 * Seen — the permanent record of who was shown to whom, and what came of it.
 *
 * The tray cannot answer that question. It is a working queue: rows leave the
 * "pending" exclusion the moment someone acts on them, and the weekly reset
 * DELETES every acted row, archiving likes only. So passes were forgotten every
 * week, and a profile someone had already acted on could be served to them
 * again — which is exactly what happened (8 duplicated pairs in staging2, one
 * profile served to the same viewer three times inside a single week).
 *
 * This table is append-only and never cleared. It is the answer to three
 * separate questions that previously had none:
 *
 *   1. "Has this viewer already seen this profile?"  — the refill exclusion,
 *      which no longer depends on tray status or on the weekly reset having run.
 *   2. "How many impressions did this profile get?"  — times_served. The old
 *      `profile_served` event went to cashaadi()->log_event(), which writes to
 *      wp_csm_event_log — a table that does not exist, so every call has been a
 *      silent no-op since day one and no impression history survives.
 *   3. "What did they decide?"                        — action/acted_at, kept
 *      for passes as well as likes.
 *
 * NOTE ON "IMPRESSION": a row is written when a profile is placed in a tray,
 * which is when it becomes visible to that member. Discover ships the whole
 * tray in one payload and advances client-side, so the server cannot tell which
 * cards were actually scrolled to. times_served is therefore "was made
 * available", not "was looked at" — an honest ceiling, not a view count.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seen {

	/** Registered with the Migrator by Discover::register(). */
	public static function schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_seen';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			viewer_id BIGINT UNSIGNED NOT NULL,
			profile_id BIGINT UNSIGNED NOT NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			times_served INT UNSIGNED NOT NULL DEFAULT 1,
			week_first VARCHAR(10) NOT NULL DEFAULT '',
			action VARCHAR(10) NOT NULL DEFAULT '',
			acted_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (viewer_id, profile_id),
			KEY profile_id (profile_id),
			KEY action (action)
		) {$charset};";
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_seen';
	}

	/** False when the table hasn't been installed yet — every caller degrades. */
	private static function ready() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$t  = self::table();
			$ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		}
		return $ok;
	}

	/**
	 * A profile has been placed in this viewer's tray — i.e. shown to them.
	 *
	 * Idempotent per pair: the first serving creates the row, later ones bump
	 * times_served. Never overwrites the action, so re-serving cannot erase a
	 * recorded decision.
	 */
	public static function record_served( $viewer_id, $profile_id, $week_id = '' ) {
		if ( ! self::ready() ) {
			return;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$t   = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (viewer_id, profile_id, first_seen_at, last_seen_at, times_served, week_first)
			 VALUES (%d, %d, %s, %s, 1, %s)
			 ON DUPLICATE KEY UPDATE times_served = times_served + 1, last_seen_at = VALUES(last_seen_at)",
			(int) $viewer_id, (int) $profile_id, $now, $now, (string) $week_id
		) );
	}

	/**
	 * Record the decision. Upserts, so an action can never be lost because the
	 * serving row went missing.
	 */
	public static function record_action( $viewer_id, $profile_id, $action ) {
		if ( ! self::ready() ) {
			return;
		}
		$action = in_array( $action, array( 'liked', 'passed' ), true ) ? $action : '';
		if ( '' === $action ) {
			return;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$t   = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (viewer_id, profile_id, first_seen_at, last_seen_at, times_served, action, acted_at)
			 VALUES (%d, %d, %s, %s, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE action = VALUES(action), acted_at = VALUES(acted_at)",
			(int) $viewer_id, (int) $profile_id, $now, $now, $action, $now
		) );
	}

	/**
	 * Every profile this viewer may not be served again.
	 *
	 * Deliberately includes profiles with no decision yet: one sitting unacted in
	 * the tray must not be served twice either, and that used to depend on the
	 * pending-status query agreeing with reality.
	 *
	 * PASSES EXPIRE, LIKES DO NOT (owner decision, 2026-09-04). A pass older than
	 * the window drops out of this list and the profile can be served again — the
	 * member may well have changed their photos or details since. A like never
	 * expires: that pair already has a pending match request, and re-serving
	 * someone you have asked to match with reads as a bug, not a second chance.
	 *
	 * The window is a filter rather than a constant because the owner chose one
	 * month, which is short for a 5-a-week tray: if members start seeing repeats,
	 * this wants raising without a deploy.
	 *
	 *   add_filter( 'csm_pass_reshow_months', fn() => 6 );
	 *
	 * Returning 0 (or less) disables expiry entirely — passes become permanent.
	 *
	 * FAILS CLOSED. If the table is missing this returns FALSE, not an empty
	 * array, and the refill aborts. An empty array would read as "this member has
	 * seen nobody", which is the most dangerous possible answer: the exclusion
	 * silently disappears and everyone starts being re-served people they have
	 * already judged. Serving nothing is a visible, harmless failure; serving
	 * duplicates is an invisible, damaging one.
	 *
	 * @return int[]|false Profile ids, or false when the record is unavailable.
	 */
	public static function ids_for( $viewer_id ) {
		if ( ! self::ready() ) {
			return false;
		}
		global $wpdb;
		$t      = self::table();
		$months = (int) apply_filters( 'csm_pass_reshow_months', 1 );

		if ( $months < 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT profile_id FROM {$t} WHERE viewer_id = %d",
				(int) $viewer_id
			) );
			return array_map( 'intval', (array) $ids );
		}

		/*
		 * Cutoff computed in PHP, not with MySQL's NOW(): acted_at is written with
		 * current_time( 'mysql' ), which is IST, while NOW() is whatever the
		 * database server is set to. Mixing the two silently shifts the window.
		 */
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', (int) current_time( 'timestamp' ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT profile_id FROM {$t}
			 WHERE viewer_id = %d
			   AND NOT ( action = 'passed' AND acted_at IS NOT NULL AND acted_at < %s )",
			(int) $viewer_id,
			$cutoff
		) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * One-time import of the history that already exists.
	 *
	 * Without this the new exclusion starts empty and every member is re-shown
	 * people they have already judged. The tray holds the live rows (pending and
	 * acted) and wp_csm_likes holds what past weekly resets archived; both are
	 * folded in, tray last so a recorded action wins over a bare like row.
	 *
	 * Runs once, guarded by an option. Safe to re-run: every write is an upsert.
	 */
	public static function backfill() {
		if ( ! self::ready() ) {
			return;
		}
		if ( get_option( 'csm_seen_backfilled' ) ) {
			return;
		}

		global $wpdb;
		$t     = self::table();
		$tray  = $wpdb->prefix . 'csm_tray';
		$likes = $wpdb->prefix . 'csm_likes';

		// Archived likes first — oldest, least specific.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $likes ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT INTO {$t} (viewer_id, profile_id, first_seen_at, last_seen_at, times_served, action, acted_at)
				 SELECT viewer_id, profile_id, liked_at, liked_at, 1, 'liked', liked_at
				 FROM {$likes}
				 ON DUPLICATE KEY UPDATE action = 'liked', acted_at = VALUES(acted_at)"
			);
		}

		// Then the tray, which carries the assignment week and the real outcome.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tray ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT INTO {$t} (viewer_id, profile_id, first_seen_at, last_seen_at, times_served, week_first, action, acted_at)
				 SELECT viewer_id, profile_id, MIN(assigned_at), MAX(assigned_at), COUNT(*),
				        MIN(week_assigned),
				        COALESCE(MAX(NULLIF(IF(status IN ('liked','passed'), status, ''), '')), ''),
				        MAX(acted_at)
				 FROM {$tray}
				 GROUP BY viewer_id, profile_id
				 ON DUPLICATE KEY UPDATE
				    times_served = VALUES(times_served),
				    week_first   = VALUES(week_first),
				    action       = IF( VALUES(action) = '', action, VALUES(action) ),
				    acted_at     = COALESCE( VALUES(acted_at), acted_at )"
			);
		}

		update_option( 'csm_seen_backfilled', current_time( 'mysql' ), false );
	}
}
