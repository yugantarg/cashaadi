<?php
/**
 * The impression log: one row per profile served, with WHY and WHO.
 *
 * Owner, 2026-10-02: "I want a full data analytics and experimentation to
 * understand how people like and whom people match with ... I want the data
 * to be in place when I do a data driven algo 6 months later."
 *
 * wp_csm_seen answers "has A been shown B" and keeps one row per pair. This
 * keeps every serving, and freezes three things a later analysis cannot
 * reconstruct after the fact:
 *   - the score and each of its parts, and the algorithm/experiment arm;
 *   - a snapshot of BOTH members as they were at that moment (profiles are
 *     edited; today's value says nothing about what was liked in March);
 *   - the slot position and pool size.
 * Outcomes are NOT duplicated here — they already have homes, keyed by the
 * same (viewer, profile) pair: wp_csm_seen.action/acted_at (like/pass),
 * wp_bp_friends (request, accepted), wp_csm_rejections (declines),
 * wp_csm_profile_views (full profile opened), Better Messages (messages),
 * wp_csm_blocks. Analytics joins them on the pair and served_at.
 *
 * Append-only, read-only for the product: nothing ranks off this table.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Impressions {

	public static function schema( $wpdb ) {
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			viewer_id BIGINT UNSIGNED NOT NULL,
			profile_id BIGINT UNSIGNED NOT NULL,
			served_at DATETIME NOT NULL,
			week_id VARCHAR(10) NOT NULL DEFAULT '',
			slot SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			pool_size INT UNSIGNED NOT NULL DEFAULT 0,
			algo VARCHAR(24) NOT NULL DEFAULT '',
			experiment VARCHAR(32) NOT NULL DEFAULT '',
			arm VARCHAR(16) NOT NULL DEFAULT '',
			tier TINYINT UNSIGNED NOT NULL DEFAULT 0,
			score FLOAT NOT NULL DEFAULT 0,
			parts TEXT NULL,
			viewer_snap TEXT NULL,
			profile_snap TEXT NULL,
			PRIMARY KEY  (id),
			KEY pair (viewer_id, profile_id),
			KEY profile_id (profile_id),
			KEY served_at (served_at)
		) {$charset};";
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_impressions';
	}

	private static function ready() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
		}
		return $ok;
	}

	/**
	 * Log one refill's servings. $ranked is Ranker::rank()'s output, in slot
	 * order; $pool is the stats rows it ranked. Never throws: losing a log
	 * row must never cost a member their tray.
	 */
	public static function record( $viewer_id, array $ranked, array $pool, $week_id, $served_at ) {
		if ( empty( $ranked ) || ! self::ready() ) {
			return;
		}
		global $wpdb;
		try {
			$stats = array();
			foreach ( $pool as $r ) {
				$stats[ (int) $r->user_id ] = $r;
			}
			$ids   = array_merge( array( (int) $viewer_id ), wp_list_pluck( $ranked, 'id' ) );
			$attrs = Ranker::attributes( $ids );
			$rate  = Ranker::site_rate();
			$P     = Ranker::points();
			$v     = wp_json_encode( self::snapshot( $viewer_id, $attrs, Ranker::own_stats( $viewer_id ), $rate, $P ) );

			foreach ( array_values( $ranked ) as $i => $row ) {
				$pid = (int) $row['id'];
				$st  = isset( $stats[ $pid ] ) ? $stats[ $pid ] : Ranker::own_stats( $pid );
				$wpdb->insert( self::table(), array(
					'viewer_id'    => (int) $viewer_id,
					'profile_id'   => $pid,
					'served_at'    => $served_at,
					'week_id'      => (string) $week_id,
					'slot'         => $i + 1,
					'pool_size'    => count( $pool ),
					'algo'         => Ranker::ALGO,
					'experiment'   => '',
					'arm'          => '',
					'tier'         => (int) $row['tier'],
					'score'        => (float) $row['score'],
					'parts'        => wp_json_encode( $row['parts'] ),
					'viewer_snap'  => $v,
					'profile_snap' => wp_json_encode( self::snapshot( $pid, $attrs, $st, $rate, $P ) ),
				) );
			}
		} catch ( \Throwable $e ) {
			error_log( '[cashaadi] impression log failed: ' . $e->getMessage() );
		}
	}

	/** Who this member was at this moment. Short keys: rows add up. */
	private static function snapshot( $uid, array $attrs, $stats, $rate, array $P ) {
		$a      = isset( $attrs[ $uid ] ) ? $attrs[ $uid ] : array();
		$u      = get_userdata( $uid );
		$photos = get_user_meta( $uid, 'csm_photos', true );
		$last   = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $uid ) : '';
		$snap   = array(
			'g'      => isset( $a['gender'] ) ? $a['gender'] : '',
			'age'    => isset( $a['age'] ) ? $a['age'] : null,
			'ht'     => isset( $a['height'] ) ? $a['height'] : null,
			'city'   => isset( $a['city'] ) ? $a['city'] : '',
			'tongue' => isset( $a['tongue'] ) ? $a['tongue'] : '',
			'comm'   => isset( $a['community'] ) ? $a['community'] : '',
			'rel'    => isset( $a['religion'] ) ? $a['religion'] : '',
			'diet'   => isset( $a['diet'] ) ? $a['diet'] : '',
			'drink'  => isset( $a['drinking'] ) ? $a['drinking'] : '',
			'smoke'  => isset( $a['smoking'] ) ? $a['smoking'] : '',
			'qual'   => isset( $a['qual'] ) ? $a['qual'] : '',
			'occ'    => isset( $a['occupation'] ) ? $a['occupation'] : '',
			'inc'    => isset( $a['income'] ) ? $a['income'] : '',
			'for'    => isset( $a['created_for'] ) ? $a['created_for'] : '',
			'fam'    => isset( $a['family_type'] ) ? $a['family_type'] : '',
			'photos' => is_array( $photos ) ? count( $photos ) : 0,
			'priv'   => '1' === (string) get_user_meta( $uid, 'csm_photo_private', true ) ? 1 : 0,
			'ver'    => (string) get_user_meta( $uid, 'csm_av_status', true ),
			'prem'   => class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid ) ? 1 : 0,
			'reg_d'  => $u ? (int) floor( ( time() - strtotime( $u->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) : null,
			'act_d'  => $last ? (int) floor( ( time() - strtotime( $last . ' UTC' ) ) / DAY_IN_SECONDS ) : null,
			'shown'  => (int) $stats->shown,
			'liked'  => (int) $stats->liked,
			'pop'    => round( Ranker::popularity( $stats, $rate, $P ), 3 ),
		);
		if ( class_exists( '\CAShaadi\Core\Profile' ) ) {
			$c = \CAShaadi\Core\Profile::completion( $uid );
			$snap['left'] = is_array( $c ) && isset( $c['outstanding'] ) ? (int) $c['outstanding'] : null; // details left to fill
		}
		return $snap;
	}
}
