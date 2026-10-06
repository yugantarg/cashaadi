<?php
/**
 * Discover engine — global functions migrated verbatim from the WPCode tray
 * snippets, kept as GLOBAL functions (not class methods) because the mu-plugin
 * engine and sibling snippets call them by name:
 *
 *   csm_refill_tray()        #11599  — fill a viewer's tray up to quota
 *   csm_maybe_weekly_reset() #11600  — lazy weekly reset on page load
 *   csm_check_mutual_like()  #11600  — mutual-like detection + match log
 *   csm_log_event()          #11630  — routes a "like" into a BuddyPress
 *                                       friendship (relabelled "Match")
 *
 * Every function is function_exists()-guarded so this file is inert if the
 * original snippet is still active. Required by Discover::register() only when
 * Config::discover_enabled() is true. Depends on the `cashaadi()` mu-plugin
 * (tables wp_csm_tray / wp_csm_likes, get_week_id(), get_opposite_gender(),
 * log_event()) — that engine stays where it is; this is only the WPCode layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================================
 * #11599 — Tray Refill Engine
 * ==========================================================================*/
if ( ! function_exists( 'csm_refill_tray' ) ) {
	/**
	 * Fill a viewer's tray up to their quota.
	 *
	 * @param  int    $viewer_id User whose tray to fill.
	 * @param  string $week_id   IST week string, e.g. "2026-W25". Auto if empty.
	 * @return int[]  Profile IDs newly inserted.
	 */
	function csm_refill_tray( $viewer_id, $week_id = '' ) {

		global $wpdb;

		if ( ! function_exists( 'cashaadi' ) ) {
			return array();
		}
		$csm = cashaadi();

		if ( ! function_exists( 'xprofile_get_field_id_from_name' ) ) {
			return array(); // BuddyPress not loaded yet
		}
		if ( ! $csm->table_exists( 'tray' ) ) {
			return array(); // Table not created yet
		}
		if ( empty( $week_id ) ) {
			$week_id = $csm->get_week_id();
		}

		// v3 quota: uniform 5/week base; Premium (PMPro level 2) gets 2x = 10.
		// Filterable since v1.60.0, where premium women get 50 (Discover\Filters).
		$tray_size = 5;
		if ( function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( 2, $viewer_id ) ) {
			$tray_size = 10;
		}
		$tray_size = max( 1, (int) apply_filters( 'csm_tray_size', $tray_size, $viewer_id ) );
		$opposite = $csm->get_opposite_gender( $viewer_id );
		if ( empty( $opposite ) ) {
			return array(); // Gender not set — can't match
		}

		$tray_tbl = $csm->table( 'tray' );

		/* --- How many slots are free? (tighter of weekly grant / open stock) --- */
		$served_week = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray_tbl} WHERE viewer_id = %d AND week_assigned = %s",
			$viewer_id, $week_id
		) );
		$open_pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray_tbl} WHERE viewer_id = %d AND status = 'pending'",
			$viewer_id
		) );
		$pending = $open_pending;

		$slots = max( 0, min(
			$tray_size - $served_week,
			$tray_size - $open_pending
		) );
		if ( $slots <= 0 ) {
			return array();
		}

		/* --- Exclusion list: self, in-tray, already-liked --- */
		$exclude = array( (int) $viewer_id );

		$in_tray = $wpdb->get_col( $wpdb->prepare(
			"SELECT profile_id FROM {$tray_tbl} WHERE viewer_id = %d AND status = 'pending'",
			$viewer_id
		) );
		if ( $in_tray ) {
			$exclude = array_merge( $exclude, array_map( 'intval', $in_tray ) );
		}

		if ( $csm->table_exists( 'likes' ) ) {
			$liked = $wpdb->get_col( $wpdb->prepare(
				"SELECT profile_id FROM " . $csm->table( 'likes' ) . " WHERE viewer_id = %d",
				$viewer_id
			) );
			if ( $liked ) {
				$exclude = array_merge( $exclude, array_map( 'intval', $liked ) );
			}
		}

		/*
		 * Everyone this viewer has ALREADY been shown.
		 *
		 * The two lists above cannot answer that. $in_tray only sees 'pending',
		 * so a profile drops out of it the instant someone likes or passes; the
		 * likes list is written by the weekly reset, which deletes passes
		 * outright and does not run on the app screens at all (they claim
		 * template_redirect at priority 1 and exit before it). Between those two
		 * gaps, acted-on profiles were invisible to the exclusion and got served
		 * again — 8 duplicated pairs on staging2, one of them three times.
		 *
		 * wp_csm_seen is append-only and never cleared, so this holds whether or
		 * not the reset has run. The lists above are kept as a belt-and-braces
		 * fallback for the window before the backfill lands.
		 */
		if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
			$seen = \CAShaadi\Modules\Discover\Seen::ids_for( $viewer_id );
			if ( false === $seen ) {
				/*
				 * The seen record is unavailable, so we cannot tell who this member
				 * has already been shown. Refilling now would re-serve people they
				 * have already judged — invisibly. Abort instead: an empty tray is
				 * obvious and recoverable, duplicate servings are neither.
				 * Health reports the missing table.
				 */
				return array();
			}
			if ( $seen ) {
				$exclude = array_merge( $exclude, $seen );
			}
		}

		// Blocked pairs — hide anyone this viewer has blocked (or been blocked by).
		// Mirrors the original #11599 refill. Guarded so it works whether the global
		// comes from the #11810 snippet or the Block module's compat.php (either may
		// be the live source during/after the Block cutover); no-op if neither.
		if ( function_exists( 'csm_bl_hidden_ids' ) ) {
			$blocked = csm_bl_hidden_ids( $viewer_id );
			if ( ! empty( $blocked ) && is_array( $blocked ) ) {
				$exclude = array_merge( $exclude, array_map( 'intval', $blocked ) );
			}
		}

		/*
		 * Paused profiles are not offered to anybody. Pausing also clears the
		 * pending rows they are already in (Deactivate::pause), so this stops
		 * them coming back on the next refill rather than being the only guard.
		 */
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			$paused = \CAShaadi\Modules\Settings\Deactivate::paused_ids();
			if ( $paused ) {
				$exclude = array_merge( $exclude, array_map( 'intval', $paused ) );
			}
		}

		$exclude     = array_unique( $exclude );
		$exclude_csv = implode( ',', $exclude ); // safe — every value intval'd

		$gender_field_id = xprofile_get_field_id_from_name( 'Gender' );
		if ( ! $gender_field_id ) {
			return array();
		}

		/* --- Rank the eligible pool ------------------------------------------
		 *
		 * This used to be ORDER BY RAND(), which is not neutral — it is only
		 * neutral per draw, and exposure compounds. On staging2 it produced a 22x
		 * spread (min 1, max 22 impressions) and, worse, left 177 of 416 men never
		 * shown to anyone at all while the average woman had been shown 10.9 times.
		 *
		 * Some of that is structural: 416 men and 127 women means every woman is
		 * seen by many men and most men by almost no one. RAND() cannot fix the
		 * ratio, but it made it worse by never remembering who had already had
		 * exposure. wp_csm_seen now records that, so the pool can be ranked:
		 *
		 *   - EXPOSURE (negative): each past impression costs something (see below), so
		 *     the least-shown profiles surface first. This is the "balanced
		 *     proportions" mechanism — within any group of equally-boosted
		 *     profiles, whoever has been shown least goes first.
		 *   - ACTIVE: seen in the last 30 days. This is a TIER, not a bonus —
		 *     every active profile is ranked above every dormant one, and the
		 *     balancing above happens WITHIN each tier. Written additively first,
		 *     which was wrong: with exposure costing a point an active profile
		 *     fell behind dormant ones after three impressions, so "active
		 *     members are shown more" quietly stopped being true. Showing dormant
		 *     profiles also wastes a member's 5 weekly picks on people who will
		 *     never reply, and the tier is what makes the fortnightly "log in to
		 *     be shown more" email TRUE rather than a claim we do not honour.
		 *   - NEW: registered recently, so a new member is not stuck behind a
		 *     year of accumulated exposure on their first week. Deliberately
		 *     smaller than the activity boost — "slightly more", per the owner.
		 *   - JITTER: a small random term. Without it the ranking is fully
		 *     deterministic and the same faces surface in the same order for
		 *     everyone, which is its own kind of unfair.
		 *
		 * Every weight is filterable: this is a product judgement, not a constant,
		 * and it should be tunable from the data once it is running.
		 */
		/*
		 * POINTS (owner, 2026-10-02): profiles are scored per viewer by
		 * Discover\Ranker -- shared language, community, religion, age bracket,
		 * city and diet, plus newness, few past showings, popularity and
		 * popular-to-popular, plus a random 0-5. See Ranker for the points.
		 *
		 * Kept from v1.67.0: the weekly ceilings, as a tier under the active
		 * tier. A profile over this week's showings or requests ceiling goes
		 * behind everyone who is not, so no amount of points puts one woman in
		 * every man's tray (the top 5 of 112 women had 32% of 30 days' requests
		 * under v1.52.0). A tier, not an exclusion: trays still fill.
		 *
		 * The pool is fetched with its stats and ranked in PHP: the match
		 * points compare the viewer's own profile with each candidate's, and
		 * every part of the score is logged by Discover\Impressions.
		 */
		$tier_active = (bool) apply_filters( 'csm_rank_active_tier', true );
		$days_active = (int) apply_filters( 'csm_rank_active_days', 30 );

		// Cutoffs in PHP for the same reason as Seen::ids_for() — the rows are
		// written in IST and the database server's clock may not be.
		$now         = (int) current_time( 'timestamp' );
		$cut_active  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days_active . ' days', $now ) );
		$cut_week    = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', $now ) );     // csm_seen: IST
		$cut_week_gm = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );      // bp_friends: UTC

		$seen_tbl  = $wpdb->prefix . 'csm_seen';
		$act_tbl   = $wpdb->prefix . 'bp_activity';
		$xp_tbl    = $wpdb->prefix . 'bp_xprofile_data';
		$fr_tbl    = $wpdb->prefix . 'bp_friends';

		/*
		 * WEEKLY CEILINGS.
		 * Showings: csm_rank_weekly_ceiling, or when 0 (default) automatic --
		 * csm_rank_weekly_ceiling_mult (2) x this gender's average showings in
		 * the last 7 days, never below 10. Automatic so it follows the size of
		 * the pool instead of needing retuning as the site grows.
		 * Requests: csm_rank_weekly_request_cap (default 6; 0 = off) requests
		 * received in the last 7 days.
		 */
		$ceiling = (int) get_option( 'csm_rank_weekly_ceiling', 0 );
		if ( $ceiling <= 0 ) {
			$mult       = (float) get_option( 'csm_rank_weekly_ceiling_mult', 2 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$week_shows = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$seen_tbl} s
				 JOIN {$xp_tbl} g ON g.user_id = s.profile_id AND g.field_id = %d AND g.value = %s
				 WHERE s.last_seen_at > %s",
				$gender_field_id, $opposite, $cut_week
			) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pool_n     = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$xp_tbl} WHERE field_id = %d AND value = %s",
				$gender_field_id, $opposite
			) );
			$ceiling    = max( 10, (int) ceil( $mult * $week_shows / max( 1.0, $pool_n ) ) );
		}
		$req_cap = (int) get_option( 'csm_rank_weekly_request_cap', 6 );
		$req_cap = $req_cap > 0 ? $req_cap : PHP_INT_MAX;

		/*
		 * Member-chosen narrowing (age, height), added by Discover\Filters for
		 * the members entitled to it. Empty for everyone else, so the query is
		 * byte-identical to what it was for them.
		 */
		$extra_where = (string) apply_filters( 'csm_refill_extra_where', '', $viewer_id );

		$sql = $wpdb->prepare(
			"SELECT xp.user_id,
			        COALESCE( sn.shown, 0 )    shown,
			        COALESCE( sn.shown_wk, 0 ) shown_wk,
			        COALESCE( sn.liked, 0 )    liked,
			        COALESCE( rq.req_wk, 0 )   req_wk,
			        IF( ac.seen_at IS NOT NULL AND ac.seen_at > %s, 1, 0 ) active,
			        u.user_registered          registered
			 FROM   {$xp_tbl} xp
			 LEFT JOIN ( SELECT profile_id, COUNT(*) shown, SUM( last_seen_at > %s ) shown_wk, SUM( action = 'liked' ) liked
			             FROM {$seen_tbl} GROUP BY profile_id ) sn
			        ON sn.profile_id = xp.user_id
			 LEFT JOIN ( SELECT friend_user_id, COUNT(*) req_wk FROM {$fr_tbl} WHERE date_created > %s GROUP BY friend_user_id ) rq
			        ON rq.friend_user_id = xp.user_id
			 LEFT JOIN ( SELECT user_id, MAX(date_recorded) seen_at FROM {$act_tbl} WHERE type = 'last_activity' GROUP BY user_id ) ac
			        ON ac.user_id = xp.user_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = xp.user_id
			 WHERE  xp.field_id = %d
			   AND  xp.value    = %s
			   AND  xp.user_id NOT IN ({$exclude_csv})
			        {$extra_where}",
			$cut_active,
			$cut_week,
			$cut_week_gm,
			$gender_field_id,
			$opposite
		);
		$pool     = (array) $wpdb->get_results( $sql );

		/*
		 * First week: at least 4 (men) / 8 (women) of the week's profiles have a
		 * photo (owner, 2026-10-06). Count the ones already served this week and
		 * ask the ranker for the rest.
		 */
		$photo_need = 0;
		$floor      = \CAShaadi\Modules\Discover\Ranker::photo_floor( $viewer_id );
		if ( $floor > 0 ) {
			$week_ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT profile_id FROM {$tray_tbl} WHERE viewer_id = %d AND week_assigned = %s",
				$viewer_id, $week_id
			) ) );
			$have       = count( array_filter( \CAShaadi\Modules\Discover\Ranker::trust( $week_ids ), function ( $t ) { return ! empty( $t['photo'] ); } ) );
			$photo_need = max( 0, $floor - $have );
		}
		$ranked   = '' === $wpdb->last_error
			? \CAShaadi\Modules\Discover\Ranker::rank( $viewer_id, $pool, $slots, $tier_active, $ceiling, $req_cap, $photo_need )
			: array();
		$eligible = wp_list_pluck( $ranked, 'id' );

		// A ranking query that fails must not empty every tray: log it and
		// serve the same eligible pool unranked.
		if ( empty( $eligible ) && '' !== $wpdb->last_error ) {
			error_log( '[cashaadi] Discover ranking query failed: ' . $wpdb->last_error );
			$eligible = $wpdb->get_col( $wpdb->prepare(
				"SELECT xp.user_id FROM {$xp_tbl} xp
				 WHERE xp.field_id = %d AND xp.value = %s
				   AND xp.user_id NOT IN ({$exclude_csv}) {$extra_where}
				 ORDER BY RAND() LIMIT %d",
				$gender_field_id, $opposite, $slots
			) );
		}

		if ( empty( $eligible ) ) {
			$csm->log_event( 'pool_exhausted', $viewer_id, 0, array(
				'week_id'        => $week_id,
				'slots_needed'   => $slots,
				'pool_size'      => 0,
				'excluded_count' => count( $exclude ),
			) );
			return array();
		}

		$now      = current_time( 'mysql' ); // IST (matches WP timezone)
		$inserted = array();

		foreach ( $eligible as $pid ) {
			$pid = (int) $pid;
			$ok  = $wpdb->insert(
				$tray_tbl,
				array(
					'viewer_id'     => $viewer_id,
					'profile_id'    => $pid,
					'assigned_at'   => $now,
					'week_assigned' => $week_id,
					'status'        => 'pending',
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			if ( false !== $ok ) {
				$inserted[] = $pid;

				/*
				 * The impression record. log_event() below writes to
				 * wp_csm_event_log, which does not exist on this install — the
				 * mu-plugin's table_exists() guard turns every call into a silent
				 * no-op, so no impression has ever been recorded despite the code
				 * reading as though it were. Kept (harmless, and correct if that
				 * table is ever created); wp_csm_seen is the real record.
				 */
				if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
					\CAShaadi\Modules\Discover\Seen::record_served( $viewer_id, $pid, $week_id );
				}

				$csm->log_event( 'profile_served', $viewer_id, $pid, array(
					'week_id' => $week_id,
					'source'  => ( 0 === $pending ) ? 'initial' : 'refill',
				) );
			}
		}

		// Why each one was served, and who both sides were at the time.
		if ( $inserted && $ranked && class_exists( '\CAShaadi\Modules\Discover\Impressions' ) ) {
			$served = array_values( array_filter( $ranked, function ( $r ) use ( $inserted ) {
				return in_array( (int) $r['id'], $inserted, true );
			} ) );
			\CAShaadi\Modules\Discover\Impressions::record( $viewer_id, $served, $pool, $week_id, $now );
		}

		if ( ! empty( $inserted ) ) {
			$csm->log_event( 'tray_refill_complete', $viewer_id, 0, array(
				'week_id'         => $week_id,
				'slots_requested' => $slots,
				'profiles_added'  => count( $inserted ),
				'carried_forward' => $pending,
				'profile_ids'     => $inserted,
			) );
		}

		return $inserted;
	}
}

/* ============================================================================
 * #11600 — Weekly Reset Trigger (lazy, on template_redirect) + mutual check
 * ==========================================================================*/
if ( ! function_exists( 'csm_maybe_weekly_reset' ) ) {
	function csm_maybe_weekly_reset() {

		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}
		if ( ! function_exists( 'cashaadi' ) ) {
			return; // mu-plugin not loaded
		}
		$csm = cashaadi();
		if ( ! $csm->table_exists( 'tray' ) ) {
			return;
		}

		$viewer_id    = get_current_user_id();
		$current_week = $csm->get_week_id();
		$last_week    = get_user_meta( $viewer_id, '_csm_last_reset_week', true );

		// Hot path: same week, nothing to do.
		if ( $last_week === $current_week ) {
			return;
		}

		// First time ever: stamp + fill.
		if ( empty( $last_week ) ) {
			update_user_meta( $viewer_id, '_csm_last_reset_week', $current_week );
			if ( function_exists( 'csm_refill_tray' ) ) {
				csm_refill_tray( $viewer_id, $current_week );
			}
			return;
		}

		/* New week detected — process the full reset. */
		global $wpdb;
		$tray_tbl = $csm->table( 'tray' );

		$acted = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, profile_id, status, assigned_at, acted_at
			 FROM   {$tray_tbl}
			 WHERE  viewer_id = %d AND status IN ('liked', 'passed', 'expired')",
			$viewer_id
		) );

		$liked_count = $passed_count = $expired_count = 0;

		foreach ( $acted as $item ) {
			$pid = (int) $item->profile_id;
			switch ( $item->status ) {
				case 'liked':
					if ( $csm->table_exists( 'likes' ) ) {
						$is_mutual = csm_check_mutual_like( $viewer_id, $pid );
						$wpdb->replace(
							$csm->table( 'likes' ),
							array(
								'viewer_id'  => $viewer_id,
								'profile_id' => $pid,
								'liked_at'   => ! empty( $item->acted_at ) ? $item->acted_at : current_time( 'mysql' ),
								'is_mutual'  => $is_mutual ? 1 : 0,
							),
							array( '%d', '%d', '%s', '%d' )
						);
					}
					$liked_count++;
					break;
				case 'passed':
					$passed_count++;
					break;
				case 'expired':
					$expired_count++;
					break;
			}
			$wpdb->delete( $tray_tbl, array( 'id' => $item->id ), array( '%d' ) );
		}

		$carried = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray_tbl} WHERE viewer_id = %d AND status = 'pending'",
			$viewer_id
		) );

		$csm->log_event( 'weekly_reset_processed', $viewer_id, 0, array(
			'week_id'         => $current_week,
			'prev_week'       => $last_week,
			'liked_cleared'   => $liked_count,
			'passed_cleared'  => $passed_count,
			'expired_cleared' => $expired_count,
			'carried_forward' => $carried,
		) );

		update_user_meta( $viewer_id, '_csm_last_reset_week', $current_week );

		if ( function_exists( 'csm_refill_tray' ) ) {
			csm_refill_tray( $viewer_id, $current_week );
		}
	}
}

if ( ! function_exists( 'csm_check_mutual_like' ) ) {
	/**
	 * If $profile_id has already liked $viewer_id, mark both sides mutual.
	 *
	 * @return bool True if mutual.
	 */
	function csm_check_mutual_like( $viewer_id, $profile_id ) {
		global $wpdb;
		if ( ! function_exists( 'cashaadi' ) ) {
			return false;
		}
		$csm = cashaadi();
		if ( ! $csm->table_exists( 'likes' ) ) {
			return false;
		}
		$likes_tbl = $csm->table( 'likes' );

		$reverse_exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$likes_tbl} WHERE viewer_id = %d AND profile_id = %d",
			$profile_id, $viewer_id
		) );

		if ( $reverse_exists > 0 ) {
			$wpdb->update(
				$likes_tbl,
				array( 'is_mutual' => 1 ),
				array( 'viewer_id' => $profile_id, 'profile_id' => $viewer_id ),
				array( '%d' ),
				array( '%d', '%d' )
			);
			$csm->log_event( 'match_created', $viewer_id, $profile_id, array(
				'initiated_by' => $viewer_id,
			) );
			return true;
		}
		return false;
	}
}

/* ============================================================================
 * #11630 — Like -> BuddyPress Match Request (routing)
 * Implements the dormant csm_log_event() the AJAX like/pass handler calls.
 * ==========================================================================*/
if ( ! function_exists( 'csm_log_event' ) ) {
	function csm_log_event( $event, $viewer_id, $profile_id ) {
		$viewer_id  = (int) $viewer_id;
		$profile_id = (int) $profile_id;

		if ( 'like' !== $event ) { return; }            // pass = no-op here
		if ( $viewer_id < 1 || $profile_id < 1 ) { return; }
		if ( $viewer_id === $profile_id ) { return; }   // no self-request

		if ( ! function_exists( 'friends_add_friend' )
			|| ! function_exists( 'friends_check_friendship_status' ) ) {
			return; // fail safe — like is still recorded by the AJAX handler
		}

		$status = friends_check_friendship_status( $viewer_id, $profile_id );

		if ( 'is_friend' === $status || 'pending' === $status ) {
			return; // already matched or outgoing request exists
		}

		if ( 'awaiting_response' === $status ) {
			// The liked user requested us first: liking back ACCEPTS it.
			if ( function_exists( 'friends_accept_friendship' )
				&& function_exists( 'friends_get_friendship_id' ) ) {
				$friendship_id = friends_get_friendship_id( $profile_id, $viewer_id );
				if ( $friendship_id ) {
					friends_accept_friendship( (int) $friendship_id );
				}
			}
			return;
		}

		// No prior relationship: pending match request (viewer -> profile).
		friends_add_friend( $viewer_id, $profile_id, false );
	}
}
