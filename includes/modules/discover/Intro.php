<?php
/**
 * New member introductions (owner, 2026-10-27).
 *
 * Discover is drawn once a week (Monday), so a member who joined on a
 * Wednesday reached nobody's Discover until the next Monday, except other
 * newcomers'. Now, when a member opens Discover mid-week, they may get one
 * newcomer as an extra card at the front, marked NEW:
 *
 *   - at most 1 a day and 3 a week per viewer, on top of their weekly cards
 *     (tray week 'intro', so it does not use up the week's profiles);
 *   - newcomers: joined in the last 7 days, with a photo, of the viewer's
 *     other gender, never shown to this viewer, not blocked / paused / held,
 *     and passing the viewer's own filters;
 *   - each newcomer is introduced to at most 20 women (a new man) or 40 men
 *     (a new woman): enough for a first week, not a free boost;
 *   - relevance: the newcomer this viewer scores highest with (the ranker's
 *     own score), compared among newcomers only. No bar against the
 *     viewer's usual cards: a newcomer has few details and no history, so
 *     they rarely beat established profiles (owner, 2026-10-27).
 *
 * Logged as intro_shown (newcomer, viewer, score).
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Intro {

	const WEEK_ROW  = 'intro';           // tray week_assigned for an introduced newcomer
	const DAY       = 'csm_intro_day';   // viewer: date of their last intro
	const WEEK      = 'csm_intro_week';  // viewer: array( week id, count )
	const REACHED   = 'csm_intro_reach'; // newcomer: how many it was introduced to
	const PER_WEEK  = 3;
	const NEW_DAYS  = 7;
	const CAP_MALE  = 20;                // a new man, introduced to this many women
	const CAP_WOMAN = 40;                // a new woman, to this many men

	public static function cap_for( $uid ) {
		return 'Female' === trim( (string) cashaadi()->get_gender( (int) $uid ) ) ? self::CAP_WOMAN : self::CAP_MALE;
	}

	public static function deliver( $viewer ) {
		$viewer = (int) $viewer;
		$today  = wp_date( 'Y-m-d' );
		if ( ! $viewer || ! function_exists( 'cashaadi' ) || get_user_meta( $viewer, self::DAY, true ) === $today ) {
			return;
		}
		$week = cashaadi()->get_week_id();
		$wk   = get_user_meta( $viewer, self::WEEK, true );
		$used = ( is_array( $wk ) && isset( $wk[0], $wk[1] ) && $wk[0] === $week ) ? (int) $wk[1] : 0;
		if ( $used >= self::PER_WEEK ) {
			return;
		}
		$opp = cashaadi()->get_opposite_gender( $viewer );
		if ( ! $opp ) {
			return;
		}
		global $wpdb;
		// Newcomers of the other gender, with a photo, still under their reach.
		$since = gmdate( 'Y-m-d H:i:s', time() - self::NEW_DAYS * DAY_IN_SECONDS );
		$new   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT u.ID FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value = %s
			 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ( '', 'a:0:{}' )
			 WHERE u.user_registered >= %s",
			Config::FIELD_GENDER, $opp, $since
		) ) );
		if ( ! $new ) {
			return;
		}
		$exclude = array( $viewer );
		$seen    = Seen::ids_for( $viewer );
		$exclude = array_merge( $exclude, is_array( $seen ) ? $seen : array() );
		$exclude = array_merge( $exclude, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT profile_id FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d", $viewer ) ) ) );
		$exclude = array_merge( $exclude, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT profile_id FROM {$wpdb->prefix}csm_seen WHERE viewer_id = %d", $viewer ) ) ) );
		if ( function_exists( 'csm_bl_hidden_ids' ) ) {
			$exclude = array_merge( $exclude, (array) csm_bl_hidden_ids( $viewer ) );
		}
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			$exclude = array_merge( $exclude, \CAShaadi\Modules\Settings\Deactivate::paused_ids() );
		}
		if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) {
			$exclude = array_merge( $exclude, \CAShaadi\Modules\Photos\PhotoHold::held_ids() );
		}
		$exclude = array_flip( array_map( 'intval', $exclude ) );
		$cands   = array();
		foreach ( $new as $n ) {
			if ( ! isset( $exclude[ $n ] ) && (int) get_user_meta( $n, self::REACHED, true ) < self::cap_for( $n ) ) {
				$cands[] = $n;
			}
		}
		// The viewer's own filters (Premium / Gold) apply: an intro is a match, not an ad.
		if ( $cands && class_exists( __NAMESPACE__ . '\\Filters' ) ) {
			$cands = Filters::keep( $viewer, $cands );
		}
		if ( ! $cands || ! class_exists( '\CAShaadi\Modules\Premium\Spotlight' ) ) {
			return;
		}
		$pick = 0;
		$best = -1;
		foreach ( $cands as $n ) {
			$score = \CAShaadi\Modules\Premium\Spotlight::score_for( $viewer, $n );
			if ( $score > $best ) {
				$best = $score;
				$pick = $n;
			}
		}
		if ( ! $pick ) {
			return;
		}
		$ok = $wpdb->insert( $wpdb->prefix . 'csm_tray', array(
			'viewer_id'     => $viewer,
			'profile_id'    => $pick,
			'week_assigned' => self::WEEK_ROW, // not this week's: does not use up the viewer's profiles
			'status'        => 'pending',
			'assigned_at'   => current_time( 'mysql' ),
		) );
		if ( ! $ok ) {
			return;
		}
		update_user_meta( $viewer, self::DAY, $today );
		update_user_meta( $viewer, self::WEEK, array( $week, $used + 1 ) );
		update_user_meta( $pick, self::REACHED, (int) get_user_meta( $pick, self::REACHED, true ) + 1 );
		Seen::record_served( $viewer, $pick, self::WEEK_ROW );
		cashaadi()->log_event( 'intro_shown', $pick, $viewer, array( 'score' => round( $best, 2 ), 'of' => count( $cands ) ) );
	}
}
