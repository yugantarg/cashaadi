<?php
/**
 * Exposure audit (owner, 2026-10-27): how often members are shown, and
 * whether that is what the rules intend.
 *
 * A showing is a card put in someone's Discover, by any of three routes:
 *   draw   the weekly draw            (wp_csm_impressions, one row each)
 *   boost  a running profile boost    (event boost_shown, actor = profile)
 *   intro  a new member introduction  (event intro_shown, actor = profile)
 *
 * Two kinds of anomaly:
 *
 *   RULE  something the rules forbid happened: a boost or intro past its
 *         cap, an intro outside its 24 hours, a viewer given more than
 *         1 boost or 1 intro a day or 3 intros a week, a viewer drawn more
 *         than their weekly allowance, a liked profile shown again, a passed
 *         one shown inside its 30 days, a paused or held member shown.
 *
 *   SHAPE the rules held but the result looks wrong: active members with a
 *         photo shown to nobody, a few profiles taking an outsized share,
 *         profiles far above the typical count, newcomers who got almost
 *         nothing from their introduction, members finding Discover empty.
 *         Profiles boosted in the period are left out of the shape checks:
 *         they are meant to be shown more. Gold is not (owner, 2026-10-27:
 *         Gold is shown more only through its boost).
 *
 *   wp csm exposure [--days=7]            the audit
 *   wp csm exposure --who=<email or id>   one member: showings by route and day
 *
 * Watch runs the audit daily and emails what it finds.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Modules\Premium\Spotlight;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Exposure {

	/* Thresholds for the SHAPE checks. */
	const STARVED_SHARE = 0.10; // >10% of active members with a photo shown to nobody
	const TOP_SHARE     = 0.15; // top 1% of profiles taking >15% of showings
	const OVER_MEDIAN   = 5;    // a profile shown >5x the median for its gender
	const NEW_MIN       = 5;    // a newcomer past their 24 hours shown to <5 people
	const EMPTY_SHARE   = 0.05; // >5% of viewers finding Discover empty
	const BOOST_MIN     = 0.5;  // a finished boost that reached under half its cap

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm exposure', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Run the audit.
	 *
	 * @return array{rules:array,shape:array,stats:array}
	 */
	public static function audit( $days = 7 ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$since = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ev    = $wpdb->prefix . 'csm_event_log';
		$imp   = $wpdb->prefix . 'csm_impressions';
		$seen  = $wpdb->prefix . 'csm_seen';
		$rules = array();
		$shape = array();
		$stats = array();

		/* ---------------------------------------------------------- RULES */

		// Boost and intro reach above their caps.
		foreach ( array(
			'boost' => array( Spotlight::REACHED, array( Spotlight::class, 'cap_for' ) ),
			'intro' => array( Intro::REACHED, array( Intro::class, 'cap_for' ) ),
		) as $what => $def ) {
			$over = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value n FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > 0", $def[0] ) ) as $r ) {
				$cap = (int) call_user_func( $def[1], (int) $r->user_id );
				if ( (int) $r->n > $cap ) {
					$over[] = '#' . $r->user_id . ' ' . (int) $r->n . '/' . $cap;
				}
			}
			if ( $over ) {
				$rules[] = sprintf( '%d %s(s) went past their cap: %s', count( $over ), $what, implode( ', ', array_slice( $over, 0, 5 ) ) );
			}
		}

		// Intros outside the newcomer's 24 hours.
		$late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE event_type = 'intro_shown' AND created_at >= %s AND CAST( JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.hour' ) ) AS DECIMAL(6,1) ) > %d", $since, Intro::HOURS ) );
		if ( $late ) {
			$rules[] = "{$late} intro(s) were shown more than " . Intro::HOURS . ' hours into the newcomer\'s window.';
		}

		// Per viewer: more than one boost or intro a day; more than 3 intros a week.
		foreach ( array( 'boost_shown' => 'boost', 'intro_shown' => 'intro' ) as $type => $what ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT target_id FROM {$ev} WHERE event_type = %s AND created_at >= %s GROUP BY target_id, DATE( created_at ) HAVING COUNT(*) > 1 ) t", $type, $since ) );
			if ( $n ) {
				$rules[] = "{$n} time(s) a viewer got more than one {$what} card in a day.";
			}
		}
		$wk = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT target_id FROM {$ev} WHERE event_type = 'intro_shown' AND created_at >= %s GROUP BY target_id, YEARWEEK( created_at, 3 ) HAVING COUNT(*) > %d ) t", $since, Intro::PER_WEEK ) );
		if ( $wk ) {
			$rules[] = "{$wk} viewer(s) got more than " . Intro::PER_WEEK . ' intros in a week.';
		}

		/*
		 * Weekly draw above the viewer's allowance (this week; Gold has none).
		 * Counted as the week's cards still in their list: a card removed
		 * because its member paused, was photo-held or left is replaced, by
		 * design, and the replacement is not extra.
		 */
		$week = function_exists( 'cashaadi' ) ? cashaadi()->get_week_id() : '';
		$over = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT viewer_id, COUNT(*) n FROM {$wpdb->prefix}csm_tray WHERE week_assigned = %s GROUP BY viewer_id HAVING n > 5", $week ) ) as $r ) {
			$uid = (int) $r->viewer_id;
			if ( Membership::is_gold( $uid ) ) {
				continue;
			}
			$size = (int) apply_filters( 'csm_tray_size', Membership::is_premium( $uid ) ? 10 : 5, $uid );
			if ( (int) $r->n > $size ) {
				/*
				 * Only if an extra card was drawn in the last day (owner,
				 * 2026-10-28: "this keeps showing"). Extras from earlier in the
				 * week were already reported, and the 26 October ones came from
				 * the refill race fixed on the 27th; repeating them every
				 * morning until Sunday hid whether anything new had happened.
				 */
				$times = $wpdb->get_col( $wpdb->prepare(
					"SELECT assigned_at FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND week_assigned = %s ORDER BY assigned_at ASC, id ASC",
					$uid, $week
				) );
				$extra = array_slice( $times, $size );
				if ( $extra && strtotime( max( $extra ) ) >= current_time( 'timestamp' ) - DAY_IN_SECONDS ) { // assigned_at is site time, as current_time()
					$over[] = '#' . $uid . ' ' . (int) $r->n . '/' . $size;
				}
			}
		}
		if ( $over ) {
			$rules[] = sprintf( '%d viewer(s) were drawn more than their weekly allowance in the last day: %s', count( $over ), implode( ', ', array_slice( $over, 0, 5 ) ) );
		}

		// Liked shown again; passed shown again inside its window.
		$liked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$seen} WHERE action = 'liked' AND acted_at IS NOT NULL AND last_seen_at > DATE_ADD( acted_at, INTERVAL 1 MINUTE ) AND last_seen_at >= %s", $since ) );
		if ( $liked ) {
			$rules[] = "{$liked} profile(s) were shown again to someone who had already sent them a request.";
		}
		$pass_days = (int) get_option( 'csm_pass_reshow_days', 30 );
		$passed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$seen} WHERE action = 'passed' AND acted_at IS NOT NULL AND last_seen_at > DATE_ADD( acted_at, INTERVAL 1 MINUTE ) AND last_seen_at < DATE_ADD( acted_at, INTERVAL %d DAY ) AND last_seen_at >= %s", $pass_days, $since ) );
		if ( $passed ) {
			$rules[] = "{$passed} passed profile(s) were shown again within {$pass_days} days.";
		}

		// Paused or held members shown after their pause or hold began.
		$off   = array();
		$began = array();
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value v FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", \CAShaadi\Modules\Settings\Deactivate::META ) ) as $r ) {
				$off[]                          = (int) $r->user_id;
				$began[ (int) $r->user_id ] = (string) $r->v; // site time, Y-m-d H:i:s
			}
		}
		if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) {
			foreach ( \CAShaadi\Modules\Photos\PhotoHold::held_ids() as $id ) {
				$h     = \CAShaadi\Modules\Photos\PhotoHold::get( $id );
				$off[] = (int) $id;
				if ( $h && ! empty( $h['at'] ) ) {
					$at = wp_date( 'Y-m-d H:i:s', (int) $h['at'] );
					$began[ (int) $id ] = isset( $began[ (int) $id ] ) ? min( $began[ (int) $id ], $at ) : $at;
				}
			}
		}
		$off   = array_values( array_unique( $off ) );
		$after = array();
		foreach ( $began as $id => $at ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$seen} WHERE profile_id = %d AND last_seen_at > %s LIMIT 1", $id, $at ) ) ) {
				$after[] = '#' . $id;
			}
		}
		if ( $after ) {
			$rules[] = count( $after ) . ' paused or photo-held member(s) were shown after their pause or hold began: ' . implode( ', ', array_slice( $after, 0, 5 ) );
		}

		/* ---------------------------------------------------------- SHAPE */

		// Showings per profile in the period, all routes.
		$count = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT profile_id id, COUNT(*) n FROM {$imp} WHERE served_at >= %s GROUP BY profile_id", $since ) ) as $r ) {
			$count[ (int) $r->id ] = (int) $r->n;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT actor_id id, COUNT(*) n FROM {$ev} WHERE event_type IN ( 'boost_shown', 'intro_shown' ) AND created_at >= %s GROUP BY actor_id", $since ) ) as $r ) {
			$count[ (int) $r->id ] = ( $count[ (int) $r->id ] ?? 0 ) + (int) $r->n;
		}
		$boosted = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT actor_id FROM {$ev} WHERE event_type = 'boost_started' AND created_at >= %s", $since ) ) ) );

		// Who counts: gender set, a photo, active in the last 14 days, joined
		// more than 7 days ago, not paused or held, not boosted.
		$active_since = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		$joined_by    = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$members = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID id, g.value gender FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value IN ( 'Male', 'Female' )
			 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ( '', 'a:0:{}' )
			 WHERE u.user_registered < %s
			   AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = u.ID AND a.type = 'last_activity' AND a.date_recorded >= %s )",
			Config::FIELD_GENDER, $joined_by, $active_since
		) );
		$offset = array_flip( $off );
		$by     = array( 'Male' => array(), 'Female' => array() );
		foreach ( (array) $members as $m ) {
			$id = (int) $m->id;
			if ( isset( $offset[ $id ] ) || isset( $boosted[ $id ] ) ) {
				continue;
			}
			$by[ $m->gender ][ $id ] = $count[ $id ] ?? 0;
		}

		foreach ( $by as $gender => $list ) {
			$n = count( $list );
			if ( ! $n ) {
				continue;
			}
			$vals = array_values( $list );
			sort( $vals );
			$median = $vals[ (int) floor( ( $n - 1 ) / 2 ) ];
			$p90    = $vals[ (int) floor( 0.9 * ( $n - 1 ) ) ];
			$max    = end( $vals );
			$total  = array_sum( $vals );
			$zero   = array_keys( $list, 0, true );
			$label  = 'Male' === $gender ? 'men' : 'women';
			$stats[] = sprintf( '%s (active, with a photo, %d): shown median %d, 90th percentile %d, most %d times in %d days; %d shown to nobody.', ucfirst( $label ), $n, $median, $p90, $max, $days, count( $zero ) );

			if ( count( $zero ) / $n > self::STARVED_SHARE ) {
				$shape[] = sprintf( '%d of %d active %s with a photo (%d%%) were shown to nobody in %d days. E.g. %s', count( $zero ), $n, $label, round( 100 * count( $zero ) / $n ), $days, implode( ', ', array_map( function ( $id ) { return '#' . $id; }, array_slice( $zero, 0, 5 ) ) ) );
			}
			if ( $total > 0 ) {
				$top_n = max( 1, (int) ceil( $n / 100 ) );
				$top   = array_sum( array_slice( array_reverse( $vals ), 0, $top_n ) );
				if ( $top / $total > self::TOP_SHARE ) {
					$shape[] = sprintf( 'The top %d %s took %d%% of all showings of %s (expected about 1-%d%%).', $top_n, $label, round( 100 * $top / $total ), $label, round( 100 * self::TOP_SHARE ) );
				}
			}
			if ( $median > 0 ) {
				arsort( $list );
				$high = array();
				foreach ( $list as $id => $c ) {
					if ( $c <= self::OVER_MEDIAN * $median ) {
						break;
					}
					$high[] = '#' . $id . ' ' . $c;
				}
				if ( $high ) {
					$shape[] = sprintf( '%d %s were shown more than %dx the median (%d): %s', count( $high ), $label, self::OVER_MEDIAN, $median, implode( ', ', array_slice( $high, 0, 5 ) ) );
				}
			}
		}

		// Newcomers whose 24 hours are over, shown to fewer than NEW_MIN people.
		$low = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value t FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) BETWEEN %d AND %d", Intro::START, time() - $days * DAY_IN_SECONDS, time() - Intro::HOURS * HOUR_IN_SECONDS ) ) as $r ) {
			$c = $count[ (int) $r->user_id ] ?? 0;
			if ( $c < self::NEW_MIN ) {
				$low[] = '#' . $r->user_id . ' ' . $c;
			}
		}
		if ( $low ) {
			$shape[] = sprintf( '%d newcomer(s) were shown to fewer than %d people in their first days: %s', count( $low ), self::NEW_MIN, implode( ', ', array_slice( $low, 0, 5 ) ) );
		}

		/*
		 * Boosts: each finished boost against what it was sold as, close to
		 * its cap within its 24 hours and never past it. Under half is
		 * reported with how many members of the other gender used Discover
		 * in those hours, which says whether there was anyone to reach.
		 */
		$boosts = $wpdb->get_results( $wpdb->prepare(
			"SELECT actor_id, created_at, metadata FROM {$ev} WHERE event_type = 'boost_started' AND created_at >= %s AND created_at <= %s",
			$since, wp_date( 'Y-m-d H:i:s', time() - Spotlight::HOURS * HOUR_IN_SECONDS )
		) );
		$fills = array();
		foreach ( (array) $boosts as $b ) {
			$uid  = (int) $b->actor_id;
			$meta = json_decode( (string) $b->metadata, true );
			$cap  = isset( $meta['cap'] ) ? (int) $meta['cap'] : Spotlight::cap_for( $uid );
			$end  = wp_date( 'Y-m-d H:i:s', strtotime( $b->created_at ) + Spotlight::HOURS * HOUR_IN_SECONDS );
			$got  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE event_type = 'boost_shown' AND actor_id = %d AND created_at BETWEEN %s AND %s", $uid, $b->created_at, $end ) );
			$fills[] = $cap ? $got / $cap : 0;
			if ( $got > $cap ) {
				$rules[] = sprintf( 'Boost of #%d (%s) reached %d people, past its cap of %d.', $uid, wp_date( 'j M H:i', strtotime( $b->created_at ) ), $got, $cap );
			} elseif ( $cap && $got < self::BOOST_MIN * $cap ) {
				$opp = 'Female' === trim( (string) cashaadi()->get_gender( $uid ) ) ? 'Male' : 'Female';
				$pool = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT( DISTINCT s.viewer_id ) FROM {$seen} s JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = s.viewer_id AND g.field_id = %d AND g.value = %s WHERE s.last_seen_at BETWEEN %s AND %s",
					Config::FIELD_GENDER, $opp, $b->created_at, $end
				) );
				$shape[] = sprintf( 'Boost of #%d (%s) reached only %d of %d in its 24 hours; %d %s used Discover in that time.', $uid, wp_date( 'j M H:i', strtotime( $b->created_at ) ), $got, $cap, $pool, 'Male' === $opp ? 'men' : 'women' );
			}
		}
		if ( $fills ) {
			sort( $fills );
			$stats[] = sprintf( 'Boosts finished: %d; reach as a share of the cap: median %d%%, lowest %d%%, highest %d%%.', count( $fills ), round( 100 * $fills[ (int) floor( ( count( $fills ) - 1 ) / 2 ) ] ), round( 100 * $fills[0] ), round( 100 * end( $fills ) ) );
		}

		// Members who opened Discover to nothing.
		$empty   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT actor_id ) FROM {$ev} WHERE event_type = 'pool_exhausted' AND created_at >= %s", $since ) );
		$viewers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT viewer_id ) FROM {$imp} WHERE served_at >= %s", $since ) );
		if ( $viewers && $empty / max( 1, $viewers + $empty ) > self::EMPTY_SHARE ) {
			$shape[] = sprintf( '%d members found nobody left to show in Discover in %d days (%d were shown cards).', $empty, $days, $viewers );
		}

		return array( 'rules' => $rules, 'shape' => $shape, 'stats' => $stats );
	}

	/** One member: showings by route and day, against their caps. */
	public static function member( $uid, $days = 14 ) {
		global $wpdb;
		$uid   = (int) $uid;
		$since = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT DATE( served_at ) d, COUNT(*) n FROM {$wpdb->prefix}csm_impressions WHERE profile_id = %d AND served_at >= %s GROUP BY d", $uid, $since ) ) as $r ) {
			$rows[ $r->d ]['draw'] = (int) $r->n;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT DATE( created_at ) d, event_type t, COUNT(*) n FROM {$wpdb->prefix}csm_event_log WHERE actor_id = %d AND event_type IN ( 'boost_shown', 'intro_shown' ) AND created_at >= %s GROUP BY d, t", $uid, $since ) ) as $r ) {
			$rows[ $r->d ][ 'boost_shown' === $r->t ? 'boost' : 'intro' ] = (int) $r->n;
		}
		ksort( $rows );
		return $rows;
	}

	public static function cli( $args, $assoc ) {
		$days = max( 1, (int) ( $assoc['days'] ?? 7 ) );
		if ( ! empty( $assoc['who'] ) ) {
			$w = $assoc['who'];
			$u = is_numeric( $w ) ? get_userdata( (int) $w ) : get_user_by( 'email', $w );
			if ( ! $u ) {
				\WP_CLI::error( 'No such member.' );
			}
			$g = function_exists( 'cashaadi' ) ? cashaadi()->get_gender( $u->ID ) : '';
			\WP_CLI::log( "#{$u->ID} {$u->user_email} ({$g}), joined {$u->user_registered}" . ( Membership::is_gold( $u->ID ) ? ', Gold' : '' ) );
			$t = array( 'draw' => 0, 'boost' => 0, 'intro' => 0 );
			foreach ( self::member( $u->ID, $days ) as $d => $r ) {
				\WP_CLI::log( sprintf( '  %s  draw %3d  boost %3d  intro %3d', $d, $r['draw'] ?? 0, $r['boost'] ?? 0, $r['intro'] ?? 0 ) );
				foreach ( $t as $k => $v ) {
					$t[ $k ] += $r[ $k ] ?? 0;
				}
			}
			\WP_CLI::log( sprintf( 'Last %d days: %d shown in the weekly draw, %d by boost, %d as a newcomer.', $days, $t['draw'], $t['boost'], $t['intro'] ) );
			\WP_CLI::log( sprintf( 'Boost reach %d of %d · intro reach %d of %d%s',
				(int) get_user_meta( $u->ID, Spotlight::REACHED, true ), Spotlight::cap_for( $u->ID ),
				(int) get_user_meta( $u->ID, Intro::REACHED, true ), Intro::cap_for( $u->ID ),
				get_user_meta( $u->ID, Intro::START, true ) ? ', intro window began ' . wp_date( 'j M H:i', (int) get_user_meta( $u->ID, Intro::START, true ) ) : ''
			) );
			return;
		}
		$r = self::audit( $days );
		foreach ( $r['stats'] as $s ) {
			\WP_CLI::log( $s );
		}
		\WP_CLI::log( $r['rules'] ? 'RULE anomalies:' : 'Rules: all held.' );
		foreach ( $r['rules'] as $s ) {
			\WP_CLI::log( '  - ' . $s );
		}
		\WP_CLI::log( $r['shape'] ? 'SHAPE anomalies:' : 'Shape: nothing unusual.' );
		foreach ( $r['shape'] as $s ) {
			\WP_CLI::log( '  - ' . $s );
		}
	}
}
