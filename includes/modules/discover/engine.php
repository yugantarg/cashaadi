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
		$tray_size = 5;
		if ( function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( 2, $viewer_id ) ) {
			$tray_size = 10;
		}
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
		 *   - EXPOSURE (negative): each past impression costs a point, capped, so
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
		$w_exposure = (float) apply_filters( 'csm_rank_weight_exposure', 1.0 );
		$w_cap      = (int) apply_filters( 'csm_rank_exposure_cap', 10 );
		// Not a weight: activity is a hard tier. Kept as a filter so a site with a
		// tiny pool can collapse the tiers if dormant profiles must be reachable.
		$tier_active = (bool) apply_filters( 'csm_rank_active_tier', true );
		$w_new      = (float) apply_filters( 'csm_rank_weight_new', 2.0 );
		$w_jitter   = (float) apply_filters( 'csm_rank_weight_jitter', 1.5 );
		$days_active = (int) apply_filters( 'csm_rank_active_days', 30 );
		$days_new    = (int) apply_filters( 'csm_rank_new_days', 30 );

		// Cutoffs in PHP for the same reason as Seen::ids_for() — the rows are
		// written in IST and the database server's clock may not be.
		$now         = (int) current_time( 'timestamp' );
		$cut_active  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days_active . ' days', $now ) );
		$cut_new     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days_new . ' days', $now ) );

		$seen_tbl = $wpdb->prefix . 'csm_seen';
		$act_tbl  = $wpdb->prefix . 'bp_activity';

		$sql = $wpdb->prepare(
			"SELECT xp.user_id
			 FROM   {$wpdb->prefix}bp_xprofile_data xp
			 LEFT JOIN ( SELECT profile_id, COUNT(*) shown FROM {$seen_tbl} GROUP BY profile_id ) sn
			        ON sn.profile_id = xp.user_id
			 LEFT JOIN ( SELECT user_id, MAX(date_recorded) seen_at FROM {$act_tbl} WHERE type = 'last_activity' GROUP BY user_id ) ac
			        ON ac.user_id = xp.user_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = xp.user_id
			 WHERE  xp.field_id = %d
			   AND  xp.value    = %s
			   AND  xp.user_id NOT IN ({$exclude_csv})
			 ORDER  BY
			        IF( %d = 1 AND ac.seen_at IS NOT NULL AND ac.seen_at > %s, 1, 0 ) DESC,
			        (
			          ( - LEAST( COALESCE( sn.shown, 0 ), %d ) * %f )
			        + IF( u.user_registered IS NOT NULL AND u.user_registered > %s, %f, 0 )
			        + ( RAND() * %f )
			        ) DESC
			 LIMIT  %d",
			$gender_field_id,
			$opposite,
			$tier_active ? 1 : 0,
			$cut_active,
			$w_cap,
			$w_exposure,
			$cut_new,
			$w_new,
			$w_jitter,
			$slots
		);
		$eligible = $wpdb->get_col( $sql );

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
