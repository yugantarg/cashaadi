<?php
/**
 * wp csm matches-why [--min-days=7]
 *
 * What makes a request turn into a match (owner, 2026-10-31: "what actually
 * causes matches, can we run regression"). Read-only; prints only totals.
 *
 * One row per request sent from Discover (csm_seen action 'liked') at least
 * --min-days ago, so the other side had time to answer. Matched = the two are
 * matched now. Requests sent some other way (from Viewed me, Declined) and
 * requests withdrawn before today are not in it.
 *
 * Markers, about the person ASKED (did she or he accept), the person ASKING,
 * and the pair. Premium is today's state, not the state when asked; "came
 * back after" is whether the person asked was active at any time after the
 * request, the simplest test of "did they even see it".
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Modules\Discover\Ranker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MatchesCli {

	/**
	 * ## OPTIONS
	 *
	 * [--min-days=<n>]
	 * : Only requests at least this many days old. Default 7.
	 */
	public function __invoke( $args, $assoc ) {
		global $wpdb;
		$min = max( 0, (int) ( $assoc['min-days'] ?? 7 ) );
		$cut = wp_date( 'Y-m-d H:i:s', time() - $min * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reqs = $wpdb->get_results( $wpdb->prepare(
			"SELECT viewer_id s, profile_id r, acted_at t FROM {$wpdb->prefix}csm_seen WHERE action = 'liked' AND acted_at IS NOT NULL AND acted_at <= %s",
			$cut
		) );
		$skip = array_flip( array_map( 'intval', get_users( array( 'role__in' => array( 'administrator' ), 'fields' => 'ID' ) ) ) );
		if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) {
			foreach ( (array) \CAShaadi\Modules\Settings\Closed::ids() as $id ) {
				$skip[ (int) $id ] = 1;
			}
		}
		$matched = array();
		foreach ( $wpdb->get_results( "SELECT initiator_user_id a, friend_user_id b FROM {$wpdb->prefix}bp_friends WHERE is_confirmed = 1" ) as $f ) {
			$matched[ min( $f->a, $f->b ) . '-' . max( $f->a, $f->b ) ] = 1;
		}
		$last = array();
		foreach ( $wpdb->get_results( "SELECT user_id, MAX( date_recorded ) t FROM {$wpdb->prefix}bp_activity WHERE type = 'last_activity' GROUP BY user_id" ) as $r ) {
			$last[ (int) $r->user_id ] = strtotime( $r->t . ' UTC' );
		}
		$notes = array();
		$nt    = $wpdb->prefix . 'csm_request_notes';
		if ( $nt === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $nt ) ) ) {
			foreach ( $wpdb->get_results( "SELECT sender_id s, receiver_id r FROM {$nt} WHERE note <> ''" ) as $r ) {
				$notes[ $r->s . '-' . $r->r ] = 1;
			}
		}
		$recv = array();
		foreach ( $wpdb->get_results( "SELECT profile_id u, COUNT(*) n FROM {$wpdb->prefix}csm_seen WHERE action = 'liked' GROUP BY profile_id" ) as $r ) {
			$recv[ (int) $r->u ] = (int) $r->n;
		}
		// phpcs:enable

		$uids = array();
		foreach ( $reqs as $q ) {
			$uids[ (int) $q->s ] = 1;
			$uids[ (int) $q->r ] = 1;
		}
		$uids  = array_keys( $uids );
		$attr  = array();
		$trust = array();
		foreach ( array_chunk( $uids, 2000 ) as $chunk ) {
			$attr  += Ranker::attributes( $chunk );
			$trust += Ranker::trust( $chunk );
		}
		$prem = array();
		foreach ( $uids as $u ) {
			$prem[ $u ] = Membership::is_premium( $u ) ? 1 : 0;
		}
		$P = array( 'tongue' => 1, 'community' => 1, 'religion' => 1, 'city' => 1, 'diet' => 1, 'age_fit' => 2, 'age_near' => 1 );

		$names = array(
			'r_active'    => 'Asked: came back after the request',
			'r_photo'     => 'Asked: has a photo',
			'r_verified'  => 'Asked: verified CA',
			'r_premium'   => 'Asked: Premium or Gold',
			'r_parent'    => 'Asked: profile made by a parent',
			'r_female'    => 'Asked: is a woman',
			'r_popular'   => 'Asked: requests received (per SD, log)',
			's_photo'     => 'Asking: has a photo',
			's_verified'  => 'Asking: verified CA',
			's_premium'   => 'Asking: Premium or Gold',
			's_parent'    => 'Asking: profile made by a parent',
			's_note'      => 'Asking: wrote a note',
			's_popular'   => 'Asking: requests received (per SD, log)',
			'same_tongue' => 'Same mother tongue',
			'same_comm'   => 'Same community',
			'same_rel'    => 'Same religion',
			'same_city'   => 'Same city',
			'same_diet'   => 'Same diet',
			'age_fit'     => 'Age fits (man 1 younger to 5 older)',
			'age_near'    => 'Age near (man 6-8 older)',
		);
		$keys = array_keys( $names );
		$X    = array();
		$y    = array();
		foreach ( $reqs as $q ) {
			$s = (int) $q->s;
			$r = (int) $q->r;
			if ( isset( $skip[ $s ] ) || isset( $skip[ $r ] ) ) {
				continue;
			}
			$as = $attr[ $s ] ?? array();
			$ar = $attr[ $r ] ?? array();
			$mp = Ranker::match_parts( $as, $ar, $P );
			$cf = function ( $a ) {
				return in_array( ucfirst( (string) ( $a['created_for'] ?? '' ) ), array( 'Son', 'Daughter' ), true ) ? 1 : 0;
			};
			$X[] = array(
				'r_active'    => ( $last[ $r ] ?? 0 ) > strtotime( $q->t ) + HOUR_IN_SECONDS ? 1 : 0,
				'r_photo'     => ! empty( $trust[ $r ]['photo'] ) ? 1 : 0,
				'r_verified'  => ! empty( $trust[ $r ]['verified'] ) ? 1 : 0,
				'r_premium'   => $prem[ $r ],
				'r_parent'    => $cf( $ar ),
				'r_female'    => 'Female' === ( $ar['gender'] ?? '' ) ? 1 : 0,
				'r_popular'   => log( 1 + ( $recv[ $r ] ?? 0 ) ),
				's_photo'     => ! empty( $trust[ $s ]['photo'] ) ? 1 : 0,
				's_verified'  => ! empty( $trust[ $s ]['verified'] ) ? 1 : 0,
				's_premium'   => $prem[ $s ],
				's_parent'    => $cf( $as ),
				's_note'      => isset( $notes[ $s . '-' . $r ] ) ? 1 : 0,
				's_popular'   => log( 1 + ( $recv[ $s ] ?? 0 ) ),
				'same_tongue' => $mp['tongue'] ? 1 : 0,
				'same_comm'   => $mp['community'] ? 1 : 0,
				'same_rel'    => $mp['religion'] ? 1 : 0,
				'same_city'   => $mp['city'] ? 1 : 0,
				'same_diet'   => $mp['diet'] ? 1 : 0,
				'age_fit'     => 2 === $mp['age'] ? 1 : 0,
				'age_near'    => 1 === $mp['age'] ? 1 : 0,
			);
			$y[] = isset( $matched[ min( $s, $r ) . '-' . max( $s, $r ) ] ) ? 1 : 0;
		}
		$n  = count( $y );
		$nm = array_sum( $y );
		\WP_CLI::log( sprintf( '%d requests sent from Discover %d+ days ago; %d became matches (%.1f%%).', $n, $min, $nm, $n ? 100 * $nm / $n : 0 ) );
		if ( $nm < 20 ) {
			\WP_CLI::error( 'Too few matches for a regression.' );
		}

		// One marker at a time.
		$tab = array();
		foreach ( $keys as $k ) {
			if ( in_array( $k, array( 'r_popular', 's_popular' ), true ) ) {
				continue;
			}
			$a = $b = $pa = $pb = 0;
			foreach ( $X as $i => $row ) {
				if ( $row[ $k ] ) {
					$a++;
					$pa += $y[ $i ];
				} else {
					$b++;
					$pb += $y[ $i ];
				}
			}
			$tab[] = array( 'marker' => $names[ $k ], 'requests with' => $a, 'matched, with' => $a ? round( 100 * $pa / $a, 1 ) . '%' : '-', 'matched, without' => $b ? round( 100 * $pb / $b, 1 ) . '%' : '-' );
		}
		\WP_CLI::log( "\nOne marker at a time (all requests: " . round( 100 * $nm / $n, 1 ) . '% matched):' );
		\WP_CLI\Utils\format_items( 'table', $tab, array( 'marker', 'requests with', 'matched, with', 'matched, without' ) );

		// All together.
		foreach ( array( 'r_popular', 's_popular' ) as $k ) {
			$col = array_column( $X, $k );
			$mu  = array_sum( $col ) / $n;
			$sd  = sqrt( array_sum( array_map( function ( $v ) use ( $mu ) { return ( $v - $mu ) * ( $v - $mu ); }, $col ) ) / $n );
			foreach ( $X as &$row ) {
				$row[ $k ] = $sd > 0 ? ( $row[ $k ] - $mu ) / $sd : 0;
			}
			unset( $row );
		}
		$M = array();
		foreach ( $X as $row ) {
			$m = array( 1.0 );
			foreach ( $keys as $k ) {
				$m[] = (float) $row[ $k ];
			}
			$M[] = $m;
		}
		list( $beta, $cov ) = PayersCli::logit( $M, $y, 1.0 );
		$rows = array();
		foreach ( $keys as $j => $k ) {
			$b  = $beta[ $j + 1 ];
			$se = sqrt( max( 0, $cov[ $j + 1 ][ $j + 1 ] ) );
			$z  = $se > 0 ? $b / $se : 0;
			$rows[] = array( 'marker' => $names[ $k ], 'odds of a match' => sprintf( '%sx', number_format( exp( $b ), 2 ) ), 'z' => round( $z, 1 ), 'clear?' => abs( $z ) >= 2 ? 'yes' : ( abs( $z ) >= 1.5 ? 'maybe' : '' ), '_abs' => abs( $z ) );
		}
		usort( $rows, function ( $p, $q ) { return $q['_abs'] <=> $p['_abs']; } );
		foreach ( $rows as &$row ) {
			unset( $row['_abs'] );
		}
		unset( $row );
		\WP_CLI::log( "\nAll markers together (logistic regression; strongest first). Above 1x raises the chance of a match, below 1x lowers it; 'clear' means |z| >= 2:" );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'marker', 'odds of a match', 'z', 'clear?' ) );

		$s = array();
		foreach ( $M as $i => $row ) {
			$t = 0;
			foreach ( $row as $j => $x ) {
				$t += $beta[ $j ] * $x;
			}
			$s[] = array( $t, $y[ $i ] );
		}
		usort( $s, function ( $p, $q ) { return $p[0] <=> $q[0]; } );
		$sum = 0;
		foreach ( $s as $i => $p ) {
			if ( $p[1] ) {
				$sum += $i + 1;
			}
		}
		$auc   = ( $sum - $nm * ( $nm + 1 ) / 2 ) / ( $nm * ( $n - $nm ) );
		$fifth = (int) floor( $n / 5 );
		\WP_CLI::log( sprintf( "\nScore check: AUC %.2f (0.5 = chance, 0.7+ = useful). Top fifth: %.1f%% matched; bottom fifth: %.1f%%.", $auc, 100 * array_sum( array_column( array_slice( $s, -$fifth ), 1 ) ) / $fifth, 100 * array_sum( array_column( array_slice( $s, 0, $fifth ), 1 ) ) / $fifth ) );
	}
}
