<?php
/**
 * Discover ranking: a points score per (viewer, profile) pair.
 *
 * Owner, 2026-10-02: "Same language, community, age bracket etc should
 * contribute points towards someone getting shown to someone. And so does
 * newness, low impressions in past, and popularity." Popular profiles get a
 * direct boost AND are matched to popular viewers. Random spread 0-5.
 *
 * Order of a refill (engine.php hands this the eligible pool with stats):
 *   1. active tier   — seen in the last 30 days, ahead of everyone dormant.
 *   2. ceiling tier  — over this week's showings or requests ceiling goes
 *                      behind everyone who is not (v1.67.0). A tier, not an
 *                      exclusion: trays still fill when everyone is over.
 *   3. points        — the sum of parts() below, highest first.
 *
 * Profile data is read fresh on every refill, so an edited profile counts
 * from the viewer's next refill; trays already filled are left alone.
 * Popularity is history (showings and likes), so editing does not reset it.
 *
 * Points are an option (csm_rank_points) so they can be tuned without a
 * deploy; every part is returned by parts() so Impressions can log WHY each
 * profile was served, which is what a data-driven version will be fitted on.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;
use CAShaadi\Core\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ranker {

	const ALGO = 'points-v1';

	/** Field labels, resolved to ids by name (the ids differ per install). */
	const FIELDS = array(
		'tongue'      => 'Language (Mother Tongue)',
		'community'   => 'Community',
		'religion'    => 'Religion',
		'diet'        => 'Diet',
		'drinking'    => 'Drinking',
		'smoking'     => 'Smoking',
		'occupation'  => 'Occupation Status',
		'income'      => 'Annual Income',
		'created_for' => 'Created for',
		'family_type' => 'Nuclear/Joint',
	);

	public static function points() {
		$defaults = array(
			'tongue'       => 4,   // same mother tongue
			'community'    => 4,   // same community (both filled)
			'religion'     => 3,   // same religion (both filled)
			'age_fit'      => 3,   // man 1 younger .. 5 older than the woman
			'age_near'     => 1,   // man 6-8 older
			'city'         => 2,   // same city
			'diet'         => 2,   // same diet (both filled)
			'new'          => 5,   // registered in the last new_days
			'fresh'        => 5,   // never shown = all of it, most shown in pool = 0
			'popular'      => 3,   // own like-rate, as a multiple of the site's, capped
			'pop_match'    => 3,   // viewer and profile equally popular
			'random'       => 5,   // uniform 0..random
			'new_days'     => 30,
			'popular_max'  => 4,   // like-rate multiple that earns the full 'popular'
			'smoothing'    => 8,   // showings before a like-rate counts
		);
		$opt = get_option( 'csm_rank_points', array() );
		return array_merge( $defaults, is_array( $opt ) ? array_map( 'floatval', $opt ) : array() );
	}

	/**
	 * Rank $pool for $viewer_id and return the top $slots, best first.
	 *
	 * @param array $pool rows: user_id, shown, shown_wk, req_wk, liked, active, registered
	 * @return array of [ 'id', 'score', 'tier', 'parts' ]
	 */
	public static function rank( $viewer_id, array $pool, $slots, $tier_active, $ceiling, $req_cap ) {
		if ( empty( $pool ) ) {
			return array();
		}
		$P     = self::points();
		$ids   = array_map( 'intval', wp_list_pluck( $pool, 'user_id' ) );
		$attrs = self::attributes( array_merge( $ids, array( (int) $viewer_id ) ) );
		$rate  = self::site_rate();
		$me    = isset( $attrs[ $viewer_id ] ) ? $attrs[ $viewer_id ] : array();
		$me_p  = self::popularity( self::own_stats( $viewer_id ), $rate, $P );

		$max_shown = 0;
		foreach ( $pool as $r ) {
			$max_shown = max( $max_shown, (int) $r->shown );
		}
		$cut_new = gmdate( 'Y-m-d H:i:s', time() - (int) $P['new_days'] * DAY_IN_SECONDS );

		$out = array();
		foreach ( $pool as $r ) {
			$id    = (int) $r->user_id;
			$a     = isset( $attrs[ $id ] ) ? $attrs[ $id ] : array();
			$parts = self::match_parts( $me, $a, $P );

			$parts['new']   = ( $r->registered && $r->registered > $cut_new ) ? $P['new'] : 0;
			$parts['fresh'] = $max_shown > 0
				? $P['fresh'] * ( 1 - log( 1 + (int) $r->shown ) / log( 1 + $max_shown ) )
				: $P['fresh'];
			$pp = self::popularity( $r, $rate, $P );
			$parts['popular']   = $P['popular'] * min( $pp, $P['popular_max'] ) / max( 1, $P['popular_max'] );
			$parts['pop_match'] = self::pop_match( $me_p, $pp, $P );
			$parts['random']    = $P['random'] * ( wp_rand( 0, 10000 ) / 10000 );

			$over = ( (int) $r->shown_wk >= $ceiling ) || ( (int) $r->req_wk >= $req_cap );
			$tier = ( $tier_active && ! (int) $r->active ? 0 : 2 ) + ( $over ? 0 : 1 );

			$out[] = array(
				'id'    => $id,
				'score' => round( array_sum( $parts ), 3 ),
				'tier'  => $tier,
				'parts' => array_map( function ( $v ) { return round( $v, 3 ); }, $parts ),
			);
		}
		usort( $out, function ( $x, $y ) {
			return $y['tier'] <=> $x['tier'] ?: $y['score'] <=> $x['score'];
		} );
		return array_slice( $out, 0, (int) $slots );
	}

	/** Points earned by being alike. Blank on either side earns nothing. */
	public static function match_parts( array $me, array $a, array $P ) {
		$same = function ( $k ) use ( $me, $a ) {
			return ! empty( $me[ $k ] ) && ! empty( $a[ $k ] ) && $me[ $k ] === $a[ $k ];
		};
		$parts = array(
			'tongue'    => $same( 'tongue' ) ? $P['tongue'] : 0,
			'community' => $same( 'community' ) ? $P['community'] : 0,
			'religion'  => $same( 'religion' ) ? $P['religion'] : 0,
			'city'      => $same( 'city' ) ? $P['city'] : 0,
			'diet'      => $same( 'diet' ) ? $P['diet'] : 0,
			'age'       => 0,
		);
		if ( ! empty( $me['age'] ) && ! empty( $a['age'] ) && ! empty( $me['gender'] ) ) {
			// Years the man is older than the woman, whichever side is viewing.
			$d = 'Male' === $me['gender'] ? $me['age'] - $a['age'] : $a['age'] - $me['age'];
			if ( $d >= -1 && $d <= 5 ) {
				$parts['age'] = $P['age_fit'];
			} elseif ( $d >= 6 && $d <= 8 ) {
				$parts['age'] = $P['age_near'];
			}
		}
		return $parts;
	}

	/** Like-rate as a multiple of the site's, smoothed toward 1.0. */
	public static function popularity( $r, $rate, array $P ) {
		$k = max( 1, $P['smoothing'] );
		return ( ( (int) $r->liked + $rate * $k ) / ( (int) $r->shown + $k ) ) / max( 0.001, $rate );
	}

	/** Full points when equally popular; none at a 16x gap (log scale). */
	public static function pop_match( $a, $b, array $P ) {
		$a = min( 4, max( 0.25, $a ) );
		$b = min( 4, max( 0.25, $b ) );
		return $P['pop_match'] * max( 0, 1 - abs( log( $a ) - log( $b ) ) / log( 16 ) );
	}

	public static function site_rate() {
		global $wpdb;
		static $rate = null;
		if ( null === $rate ) {
			$t     = $wpdb->prefix . 'csm_seen';
			$seen  = (float) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			$liked = (float) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE action = 'liked'" );
			$rate  = max( 0.001, $seen > 0 ? $liked / $seen : 0.05 );
		}
		return $rate;
	}

	/** The viewer's own showings and likes, as a profile others were shown. */
	public static function own_stats( $uid ) {
		global $wpdb;
		$t = $wpdb->prefix . 'csm_seen';
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) shown, COALESCE( SUM( action = 'liked' ), 0 ) liked FROM {$t} WHERE profile_id = %d",
			(int) $uid
		) );
		return $r ? $r : (object) array( 'shown' => 0, 'liked' => 0 );
	}

	/** Field ids by key, resolved once per request. */
	public static function field_ids() {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array();
			if ( function_exists( 'xprofile_get_field_id_from_name' ) ) {
				foreach ( self::FIELDS as $k => $label ) {
					$id = (int) xprofile_get_field_id_from_name( $label );
					if ( $id ) {
						$ids[ $k ] = $id;
					}
				}
			}
			$ids['city']   = Config::FIELD_CITY;
			$ids['gender'] = Config::FIELD_GENDER;
			$ids['dob']    = Config::FIELD_DOB;
			$ids['height'] = Config::FIELD_HEIGHT;
			$ids['qual']   = Config::FIELD_QUALIFICATION;
		}
		return $ids;
	}

	/**
	 * Stored xProfile values for many members in one query, normalised for
	 * comparison: trimmed, lower-cased, city without a trailing "city".
	 *
	 * @return array uid => [ key => value ]
	 */
	public static function attributes( array $uids ) {
		global $wpdb;
		$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ) ) ) );
		if ( empty( $uids ) ) {
			return array();
		}
		$ids  = self::field_ids();
		$by   = array_flip( $ids );
		$rows = $wpdb->get_results(
			"SELECT user_id, field_id, value FROM {$wpdb->prefix}bp_xprofile_data
			 WHERE user_id IN (" . implode( ',', $uids ) . ')
			   AND field_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')'
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$k = $by[ (int) $r->field_id ];
			$v = trim( wp_strip_all_tags( (string) maybe_unserialize( $r->value ) ) );
			if ( '' === $v || '-' === $v ) {
				continue;
			}
			$out[ (int) $r->user_id ][ $k ] = $v;
		}
		foreach ( $out as $uid => &$a ) {
			foreach ( array( 'tongue', 'community', 'religion', 'diet', 'drinking', 'smoking' ) as $k ) {
				if ( isset( $a[ $k ] ) ) {
					$a[ $k ] = strtolower( $a[ $k ] );
				}
			}
			if ( isset( $a['city'] ) ) {
				$a['city'] = trim( preg_replace( '/\s+city$/', '', strtolower( $a['city'] ) ) );
			}
			if ( isset( $a['dob'] ) && ( $t = strtotime( $a['dob'] ) ) ) {
				$a['age'] = (int) floor( ( time() - $t ) / ( 365.25 * DAY_IN_SECONDS ) );
			}
			if ( isset( $a['height'] ) && class_exists( '\CAShaadi\Core\Profile' ) ) {
				$a['height'] = Profile::height_cm( $a['height'] );
			}
		}
		unset( $a );
		return $out;
	}
}
