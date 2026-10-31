<?php
/**
 * wp csm payers [--min-days=14]
 *
 * Who pays (owner, 2026-10-30: "do regression on existing users to find out
 * the biggest markers" for an ability-to-pay score). Read-only; prints only
 * totals, never a member.
 *
 * Every member who joined at least --min-days ago (admins and closed accounts
 * left out). Paid = a paid Premium or Gold membership, or a boost bought.
 * Markers: gender, "Created for" (self / parent / other family), age, CA
 * qualified, verified, photo, metro city, LinkedIn given, came from a paid
 * ad, and the first week's activity (days active, likes received, matches).
 * The first week is used, not all time, so activity that only follows paying
 * does not pass for a cause of it.
 *
 * Prints, per marker, the share who paid with and without it, then a
 * logistic regression of all markers together (L2-regularised, numbers scaled
 * so each effect is per "yes" or per one standard deviation), and how well
 * the combined score separates payers (AUC, top and bottom fifth).
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PayersCli {

	const METROS = array( 'mumbai', 'navi mumbai', 'thane', 'delhi', 'new delhi', 'gurgaon', 'gurugram', 'noida', 'ghaziabad', 'faridabad', 'bangalore', 'bengaluru', 'chennai', 'hyderabad', 'pune', 'kolkata', 'ahmedabad' );

	/**
	 * ## OPTIONS
	 *
	 * [--min-days=<n>]
	 * : Only members who joined at least this many days ago. Default 14.
	 */
	public function __invoke( $args, $assoc ) {
		global $wpdb;
		$min  = max( 0, (int) ( $assoc['min-days'] ?? 14 ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - $min * DAY_IN_SECONDS );
		$xp   = $wpdb->prefix . 'bp_xprofile_data';
		$cf   = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'Created for' ) : 0;

		$skip = array_flip( array_map( 'intval', get_users( array( 'role__in' => array( 'administrator' ), 'fields' => 'ID' ) ) ) );
		if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) {
			foreach ( (array) \CAShaadi\Modules\Settings\Closed::ids() as $id ) {
				$skip[ (int) $id ] = 1;
			}
		}
		$users = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_registered FROM {$wpdb->users} WHERE user_registered <= %s", $cut ), OBJECT_K );
		foreach ( $skip as $id => $_ ) {
			unset( $users[ $id ] );
		}
		if ( count( $users ) < 50 ) {
			\WP_CLI::error( 'Too few members to say anything.' );
		}

		// Profile fields, in one read.
		$fields = array( Config::FIELD_GENDER => 'gender', Config::FIELD_DOB => 'dob', Config::FIELD_QUALIFICATION => 'qual', Config::FIELD_CITY => 'city', Config::FIELD_LINKEDIN => 'linkedin' );
		if ( $cf ) {
			$fields[ $cf ] = 'cf';
		}
		$in  = implode( ',', array_map( 'intval', array_keys( $fields ) ) );
		$val = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT user_id, field_id, value FROM {$xp} WHERE field_id IN ( {$in} )" ) as $r ) {
			$val[ (int) $r->user_id ][ $fields[ (int) $r->field_id ] ] = (string) $r->value;
		}
		$meta = array();
		foreach ( $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ( 'csm_av_status', 'csm_photos', 'csm_channel' )" ) as $r ) {
			$meta[ (int) $r->user_id ][ $r->meta_key ] = (string) $r->meta_value;
		}

		// Paid: a paid plan, a boost bought, or any purchase with money in it.
		$paid = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id IN ( %d, %d ) AND initial_payment > 0", Config::PMPRO_PREMIUM_LEVEL, Config::PMPRO_GOLD_LEVEL ) ) as $id ) {
			$paid[ (int) $id ] = 1;
		}
		foreach ( $wpdb->get_col( "SELECT DISTINCT actor_id FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'boost_bought' OR ( event_type = 'purchase' AND CAST( JSON_EXTRACT( metadata, '$.total' ) AS DECIMAL(10,2) ) > 0 )" ) as $id ) {
			$paid[ (int) $id ] = 1;
		}

		// First week's activity (member_day is written nightly by Journey).
		$wk = array();
		$md = $wpdb->prefix . 'csm_member_day';
		if ( $md === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $md ) ) ) {
			foreach ( $wpdb->get_results( "SELECT d.user_id, SUM( d.active ) a, SUM( d.likes_recv ) l, SUM( d.matches ) m FROM {$md} d JOIN {$wpdb->users} u ON u.ID = d.user_id WHERE d.day < DATE( DATE_ADD( u.user_registered, INTERVAL 7 DAY ) ) GROUP BY d.user_id" ) as $r ) {
				$wk[ (int) $r->user_id ] = array( (int) $r->a, (int) $r->l, (int) $r->m );
			}
		}
		// phpcs:enable

		$names = array(
			'female'       => 'Woman',
			'parent'       => 'Created by a parent',
			'family'       => 'Created by other family / friend',
			'age'          => 'Age (per SD)',
			'ca'           => 'CA qualified (not Inter etc.)',
			'verified'     => 'Verified CA',
			'photo'        => 'Has a photo',
			'metro'        => 'Metro city',
			'linkedin'     => 'LinkedIn given',
			'paid_ad'      => 'Came from a paid ad',
			'active_wk1'   => 'Days active, first week (per SD)',
			'likes_wk1'    => 'Likes received, first week (per SD)',
			'matches_wk1'  => 'Matches, first week (per SD)',
		);
		$keys = array_keys( $names );
		$X = array();
		$y = array();
		$ages = array();
		$cities = array();
		foreach ( $users as $id => $u ) {
			$v   = $val[ $id ] ?? array();
			$m   = $meta[ $id ] ?? array();
			$c   = $v['cf'] ?? '';
			$dob = isset( $v['dob'] ) ? strtotime( $v['dob'] ) : 0;
			$age = $dob ? ( time() - $dob ) / ( 365.25 * DAY_IN_SECONDS ) : null;
			$age = null !== $age && $age >= 18 && $age <= 60 ? $age : null;
			$city = strtolower( trim( (string) ( $v['city'] ?? '' ) ) );
			$photos = isset( $m['csm_photos'] ) ? maybe_unserialize( $m['csm_photos'] ) : array();
			$w   = $wk[ $id ] ?? array( 0, 0, 0 );
			$row = array(
				'female'      => 'Female' === trim( (string) ( $v['gender'] ?? '' ) ) ? 1 : 0,
				'parent'      => in_array( $c, array( 'Son', 'Daughter' ), true ) ? 1 : 0,
				'family'      => in_array( $c, array( 'Brother', 'Sister', 'Relative', 'Friend' ), true ) ? 1 : 0,
				'age'         => $age,
				'ca'          => 'CA' === trim( (string) ( $v['qual'] ?? '' ) ) ? 1 : 0,
				'verified'    => 'approved' === ( $m['csm_av_status'] ?? '' ) ? 1 : 0,
				'photo'       => is_array( $photos ) && count( $photos ) ? 1 : 0,
				'metro'       => in_array( $city, self::METROS, true ) ? 1 : 0,
				'linkedin'    => '' !== trim( (string) ( $v['linkedin'] ?? '' ) ) ? 1 : 0,
				'paid_ad'     => in_array( $m['csm_channel'] ?? '', array( 'google_ads', 'meta_ads', 'linkedin_ads' ), true ) ? 1 : 0,
				'active_wk1'  => log( 1 + $w[0] ),
				'likes_wk1'   => log( 1 + $w[1] ),
				'matches_wk1' => log( 1 + $w[2] ),
			);
			if ( null !== $age ) {
				$ages[] = $age;
			}
			if ( '' !== $city ) {
				$cities[ $city ][0] = ( $cities[ $city ][0] ?? 0 ) + 1;
				$cities[ $city ][1] = ( $cities[ $city ][1] ?? 0 ) + ( isset( $paid[ $id ] ) ? 1 : 0 );
			}
			$X[] = $row;
			$y[] = isset( $paid[ $id ] ) ? 1 : 0;
		}
		$n  = count( $y );
		$np = array_sum( $y );
		\WP_CLI::log( sprintf( '%d members who joined %d+ days ago; %d paid (%.1f%%).', $n, $min, $np, 100 * $np / $n ) );
		if ( $np < 10 ) {
			\WP_CLI::error( 'Too few payers for a regression.' );
		}

		// 1. One marker at a time.
		$base = 100 * $np / $n;
		$tab  = array();
		foreach ( $keys as $k ) {
			if ( in_array( $k, array( 'age', 'active_wk1', 'likes_wk1', 'matches_wk1' ), true ) ) {
				continue;
			}
			$a = $b = $pa = $pb = 0;
			foreach ( $X as $i => $r ) {
				if ( $r[ $k ] ) {
					$a++;
					$pa += $y[ $i ];
				} else {
					$b++;
					$pb += $y[ $i ];
				}
			}
			$tab[] = array( 'marker' => $names[ $k ], 'members with' => $a, 'paid, with' => $a ? round( 100 * $pa / $a, 1 ) . '%' : '-', 'paid, without' => $b ? round( 100 * $pb / $b, 1 ) . '%' : '-' );
		}
		\WP_CLI::log( "\nOne marker at a time (all members: " . round( $base, 1 ) . '% paid):' );
		\WP_CLI\Utils\format_items( 'table', $tab, array( 'marker', 'members with', 'paid, with', 'paid, without' ) );

		$bands = array();
		foreach ( $X as $i => $r ) {
			$band = null === $r['age'] ? 'unknown' : ( $r['age'] < 26 ? 'under 26' : ( $r['age'] < 29 ? '26-28' : ( $r['age'] < 32 ? '29-31' : ( $r['age'] < 35 ? '32-34' : '35+' ) ) ) );
			$bands[ $band ][0] = ( $bands[ $band ][0] ?? 0 ) + 1;
			$bands[ $band ][1] = ( $bands[ $band ][1] ?? 0 ) + $y[ $i ];
		}
		$rows = array();
		foreach ( array( 'under 26', '26-28', '29-31', '32-34', '35+', 'unknown' ) as $band ) {
			if ( isset( $bands[ $band ] ) ) {
				$rows[] = array( 'age' => $band, 'members' => $bands[ $band ][0], 'paid' => round( 100 * $bands[ $band ][1] / $bands[ $band ][0], 1 ) . '%' );
			}
		}
		\WP_CLI::log( "\nBy age:" );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'age', 'members', 'paid' ) );

		uasort( $cities, function ( $p, $q ) { return $q[0] - $p[0]; } );
		$rows = array();
		foreach ( array_slice( $cities, 0, 12, true ) as $city => $c ) {
			$rows[] = array( 'city' => $city, 'members' => $c[0], 'paid' => round( 100 * $c[1] / $c[0], 1 ) . '%' );
		}
		\WP_CLI::log( "\nTop cities:" );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'city', 'members', 'paid' ) );

		// 2. All markers together.
		$mean_age = $ages ? array_sum( $ages ) / count( $ages ) : 28;
		foreach ( $X as &$r ) {
			if ( null === $r['age'] ) {
				$r['age'] = $mean_age;
			}
		}
		unset( $r );
		$cont = array( 'age', 'active_wk1', 'likes_wk1', 'matches_wk1' );
		foreach ( $cont as $k ) {
			$col = array_column( $X, $k );
			$mu  = array_sum( $col ) / $n;
			$sd  = sqrt( array_sum( array_map( function ( $v ) use ( $mu ) { return ( $v - $mu ) * ( $v - $mu ); }, $col ) ) / $n );
			foreach ( $X as &$r ) {
				$r[ $k ] = $sd > 0 ? ( $r[ $k ] - $mu ) / $sd : 0;
			}
			unset( $r );
		}
		$M = array();
		foreach ( $X as $r ) {
			$row = array( 1.0 );
			foreach ( $keys as $k ) {
				$row[] = (float) $r[ $k ];
			}
			$M[] = $row;
		}
		list( $beta, $cov ) = self::logit( $M, $y, 1.0 );

		$rows = array();
		foreach ( $keys as $j => $k ) {
			$b  = $beta[ $j + 1 ];
			$se = sqrt( max( 0, $cov[ $j + 1 ][ $j + 1 ] ) );
			$z  = $se > 0 ? $b / $se : 0;
			$rows[] = array(
				'marker'       => $names[ $k ],
				'odds of paying' => sprintf( '%sx', number_format( exp( $b ), 2 ) ),
				'z'            => round( $z, 1 ),
				'clear?'       => abs( $z ) >= 2 ? 'yes' : ( abs( $z ) >= 1.5 ? 'maybe' : '' ),
				'_abs'         => abs( $z ),
			);
		}
		usort( $rows, function ( $p, $q ) { return $q['_abs'] <=> $p['_abs']; } );
		foreach ( $rows as &$r ) {
			unset( $r['_abs'] );
		}
		unset( $r );
		\WP_CLI::log( "\nAll markers together (logistic regression; strongest first). Odds above 1x raise the chance of paying, below 1x lower it; 'clear' means |z| >= 2:" );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'marker', 'odds of paying', 'z', 'clear?' ) );

		// How well the combined score separates payers.
		$s = array();
		foreach ( $M as $i => $row ) {
			$t = 0;
			foreach ( $row as $j => $x ) {
				$t += $beta[ $j ] * $x;
			}
			$s[] = array( $t, $y[ $i ] );
		}
		usort( $s, function ( $p, $q ) { return $p[0] <=> $q[0]; } );
		$sum_pos = 0;
		foreach ( $s as $i => $p ) {
			if ( $p[1] ) {
				$sum_pos += $i + 1;
			}
		}
		$auc   = ( $sum_pos - $np * ( $np + 1 ) / 2 ) / ( $np * ( $n - $np ) );
		$fifth = (int) floor( $n / 5 );
		$low   = array_sum( array_column( array_slice( $s, 0, $fifth ), 1 ) );
		$high  = array_sum( array_column( array_slice( $s, -$fifth ), 1 ) );
		\WP_CLI::log( sprintf( "\nScore check: AUC %.2f (0.5 = no better than chance, 0.7+ = useful). Top fifth by score: %.1f%% paid; bottom fifth: %.1f%%.", $auc, 100 * $high / $fifth, 100 * $low / $fifth ) );
	}

	/**
	 * L2-regularised logistic regression by Newton's method (the intercept is
	 * not penalised). Returns the coefficients and their covariance.
	 */
	public static function logit( $X, $y, $lambda ) {
		$n = count( $X );
		$p = count( $X[0] );
		$b = array_fill( 0, $p, 0.0 );
		$H = array();
		for ( $it = 0; $it < 30; $it++ ) {
			$g = array_fill( 0, $p, 0.0 );
			$H = array_fill( 0, $p, array_fill( 0, $p, 0.0 ) );
			for ( $i = 0; $i < $n; $i++ ) {
				$t = 0;
				for ( $j = 0; $j < $p; $j++ ) {
					$t += $b[ $j ] * $X[ $i ][ $j ];
				}
				$mu = 1 / ( 1 + exp( -max( -30, min( 30, $t ) ) ) );
				$w  = $mu * ( 1 - $mu );
				for ( $j = 0; $j < $p; $j++ ) {
					$g[ $j ] += ( $y[ $i ] - $mu ) * $X[ $i ][ $j ];
					for ( $k = $j; $k < $p; $k++ ) {
						$H[ $j ][ $k ] += $w * $X[ $i ][ $j ] * $X[ $i ][ $k ];
					}
				}
			}
			for ( $j = 0; $j < $p; $j++ ) {
				for ( $k = 0; $k < $j; $k++ ) {
					$H[ $j ][ $k ] = $H[ $k ][ $j ];
				}
				if ( $j > 0 ) {
					$g[ $j ]      -= $lambda * $b[ $j ];
					$H[ $j ][ $j ] += $lambda;
				}
			}
			$step = self::solve( $H, $g );
			$max  = 0;
			for ( $j = 0; $j < $p; $j++ ) {
				$b[ $j ] += $step[ $j ];
				$max      = max( $max, abs( $step[ $j ] ) );
			}
			if ( $max < 1e-7 ) {
				break;
			}
		}
		return array( $b, self::inverse( $H ) );
	}

	private static function solve( $A, $v ) {
		$inv = self::inverse( $A );
		$out = array();
		foreach ( $inv as $row ) {
			$t = 0;
			foreach ( $row as $j => $x ) {
				$t += $x * $v[ $j ];
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Gauss-Jordan inverse with partial pivoting. */
	private static function inverse( $A ) {
		$n = count( $A );
		$I = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$I[ $i ] = array_merge( $A[ $i ], array_fill( 0, $n, 0.0 ) );
			$I[ $i ][ $n + $i ] = 1.0;
		}
		for ( $c = 0; $c < $n; $c++ ) {
			$piv = $c;
			for ( $r = $c + 1; $r < $n; $r++ ) {
				if ( abs( $I[ $r ][ $c ] ) > abs( $I[ $piv ][ $c ] ) ) {
					$piv = $r;
				}
			}
			$tmp = $I[ $c ];
			$I[ $c ] = $I[ $piv ];
			$I[ $piv ] = $tmp;
			$d = $I[ $c ][ $c ];
			if ( abs( $d ) < 1e-12 ) {
				$d = 1e-12;
			}
			for ( $j = 0; $j < 2 * $n; $j++ ) {
				$I[ $c ][ $j ] /= $d;
			}
			for ( $r = 0; $r < $n; $r++ ) {
				if ( $r !== $c && 0.0 !== (float) $I[ $r ][ $c ] ) {
					$f = $I[ $r ][ $c ];
					for ( $j = 0; $j < 2 * $n; $j++ ) {
						$I[ $r ][ $j ] -= $f * $I[ $c ][ $j ];
					}
				}
			}
		}
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = array_slice( $I[ $i ], $n );
		}
		return $out;
	}
}
