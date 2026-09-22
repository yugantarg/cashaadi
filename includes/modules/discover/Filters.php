<?php
/**
 * Discover filters — age and height, for premium women.
 *
 * Owner, 2026-09-22: paid female members get up to 50 profiles a week and can
 * narrow them by age and height. The narrowing is deliberately coarse — at
 * least a 5-year and 5-inch span — because a tighter filter on a pool of ~640
 * men would empty the tray and make the larger quota worthless.
 *
 * The filters change WHO is assigned, not how many: the weekly grant is still
 * counted by week_assigned in csm_refill_tray(), so re-filtering mid-week
 * cannot mint extra profiles.
 *
 * Age is filtered on DATE OF BIRTH, not the computed Age field: age is a
 * derived integer that only re-syncs when a profile is saved, so a member who
 * has not edited their profile since their birthday carries a stale one. DOB
 * is what the wizard collects and never drifts.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Filters {

	const META = 'csm_discover_filters';

	/** The quota a premium woman gets, and the floor/ceiling for each filter. */
	const QUOTA_PREMIUM_FEMALE = 50;
	const AGE_MIN      = 18;
	const AGE_MAX      = 70;
	const AGE_SPAN_MIN = 5;   // years
	const IN_MIN       = 48;  // 4'0"
	const IN_MAX       = 84;  // 7'0"
	const IN_SPAN_MIN  = 5;   // inches

	public static function register() {
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 10, 2 );
		add_filter( 'csm_refill_extra_where', array( __CLASS__, 'where' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* ------------------------------------------------------------ policy */

	/** Premium AND female. Both are required; either alone keeps the old tray. */
	public static function eligible( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! Membership::is_premium( $uid ) ) {
			return false;
		}
		if ( ! function_exists( 'cashaadi' ) ) {
			return false;
		}
		return 'Female' === trim( (string) cashaadi()->get_gender( $uid ) );
	}

	/** 50 a week for an eligible member; anyone else keeps the engine's number. */
	public static function tray_size( $size, $uid ) {
		return self::eligible( $uid )
			? (int) apply_filters( 'csm_tray_size_premium_female', self::QUOTA_PREMIUM_FEMALE )
			: $size;
	}

	/* ------------------------------------------------------- stored values */

	/**
	 * This member's filters, or an empty array when unset or not eligible.
	 *
	 * @return array{age_min?:int,age_max?:int,in_min?:int,in_max?:int}
	 */
	public static function get( $uid ) {
		if ( ! self::eligible( $uid ) ) {
			return array();
		}
		$raw = get_user_meta( (int) $uid, self::META, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Validate and store. Returns the stored array, or a WP_Error naming the
	 * rule that was broken so the screen can say it in words.
	 *
	 * A pair is kept only if BOTH ends are present and the span is wide enough;
	 * a missing or cleared pair means "no filter on this", which is how the
	 * member turns one off.
	 */
	public static function save( $uid, $in ) {
		if ( ! self::eligible( $uid ) ) {
			return new \WP_Error( 'csm_not_eligible', 'Filters are a Premium feature.' );
		}
		$out = array();

		$has_age = self::filled( $in, 'age_min' ) && self::filled( $in, 'age_max' );
		if ( $has_age ) {
			$lo = self::clamp( (int) $in['age_min'], self::AGE_MIN, self::AGE_MAX );
			$hi = self::clamp( (int) $in['age_max'], self::AGE_MIN, self::AGE_MAX );
			if ( $lo > $hi ) {
				list( $lo, $hi ) = array( $hi, $lo );
			}
			if ( ( $hi - $lo ) < self::AGE_SPAN_MIN ) {
				return new \WP_Error(
					'csm_age_span',
					sprintf( 'Choose an age range of at least %d years.', self::AGE_SPAN_MIN )
				);
			}
			$out['age_min'] = $lo;
			$out['age_max'] = $hi;
		}

		$has_ht = self::filled( $in, 'in_min' ) && self::filled( $in, 'in_max' );
		if ( $has_ht ) {
			$lo = self::clamp( (int) $in['in_min'], self::IN_MIN, self::IN_MAX );
			$hi = self::clamp( (int) $in['in_max'], self::IN_MIN, self::IN_MAX );
			if ( $lo > $hi ) {
				list( $lo, $hi ) = array( $hi, $lo );
			}
			if ( ( $hi - $lo ) < self::IN_SPAN_MIN ) {
				return new \WP_Error(
					'csm_height_span',
					sprintf( 'Choose a height range of at least %d inches.', self::IN_SPAN_MIN )
				);
			}
			$out['in_min'] = $lo;
			$out['in_max'] = $hi;
		}

		if ( $out ) {
			update_user_meta( (int) $uid, self::META, $out );
		} else {
			delete_user_meta( (int) $uid, self::META );
		}
		return $out;
	}

	private static function filled( $in, $k ) {
		return isset( $in[ $k ] ) && '' !== $in[ $k ] && null !== $in[ $k ];
	}

	private static function clamp( $n, $lo, $hi ) {
		return max( $lo, min( $hi, (int) $n ) );
	}

	/* ------------------------------------------------------------- the SQL */

	/**
	 * Extra WHERE for csm_refill_tray()'s candidate query.
	 *
	 * EXISTS sub-selects rather than joins: a candidate with no DOB or no
	 * height is excluded by a filter that mentions that field (we cannot claim
	 * they match), while leaving the other filter independent.
	 *
	 * Height is compared in centimetres, the unit the field stores. Values are
	 * cast with +0 so the string column compares numerically; every row was
	 * normalised to cm in v1.58.0.
	 */
	public static function where( $sql, $uid ) {
		$f = self::get( $uid );
		if ( ! $f ) {
			return $sql;
		}
		global $wpdb;
		$t   = $wpdb->prefix . 'bp_xprofile_data';
		$add = '';

		if ( isset( $f['age_min'], $f['age_max'] ) ) {
			// age N means born between (today - (N+1) years + 1 day) and (today - N years).
			$newest = gmdate( 'Y-m-d', strtotime( '-' . (int) $f['age_min'] . ' years', (int) current_time( 'timestamp' ) ) );
			$oldest = gmdate( 'Y-m-d', strtotime( '-' . ( (int) $f['age_max'] + 1 ) . ' years +1 day', (int) current_time( 'timestamp' ) ) );
			$add   .= $wpdb->prepare(
				" AND EXISTS ( SELECT 1 FROM {$t} dob WHERE dob.user_id = xp.user_id AND dob.field_id = %d
				   AND dob.value <> '' AND DATE(dob.value) BETWEEN %s AND %s )",
				Config::FIELD_DOB,
				$oldest,
				$newest
			);
		}

		if ( isset( $f['in_min'], $f['in_max'] ) ) {
			// Inclusive of the whole inch at each end: 60in..65in is 152.4cm..167.6cm.
			$cm_lo = floor( $f['in_min'] * 2.54 );
			$cm_hi = ceil( ( $f['in_max'] + 1 ) * 2.54 ) - 1;
			$add  .= $wpdb->prepare(
				" AND EXISTS ( SELECT 1 FROM {$t} ht WHERE ht.user_id = xp.user_id AND ht.field_id = %d
				   AND ht.value <> '' AND ht.value + 0 BETWEEN %d AND %d )",
				Config::FIELD_HEIGHT,
				$cm_lo,
				$cm_hi
			);
		}

		return $sql . $add;
	}

	/** How many candidates the current filters would allow. For the UI hint. */
	public static function pool_size( $uid ) {
		global $wpdb;
		if ( ! function_exists( 'cashaadi' ) ) {
			return 0;
		}
		$opposite = cashaadi()->get_opposite_gender( $uid );
		if ( ! $opposite ) {
			return 0;
		}
		$where = self::where( '', $uid );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bp_xprofile_data xp
			 WHERE xp.field_id = %d AND xp.value = %s AND xp.user_id <> %d" . $where,
			Config::FIELD_GENDER,
			$opposite,
			(int) $uid
		) );
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/discover/filters', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_set' ),
				'permission_callback' => 'is_user_logged_in',
			),
		) );
	}

	/** Everything the filter sheet needs to draw itself. */
	public static function state( $uid ) {
		$f = self::get( $uid );
		return array(
			'ok'       => true,
			'eligible' => self::eligible( $uid ),
			'filters'  => (object) $f,
			'pool'     => self::eligible( $uid ) ? self::pool_size( $uid ) : 0,
			'bounds'   => array(
				'ageMin'  => self::AGE_MIN,
				'ageMax'  => self::AGE_MAX,
				'ageSpan' => self::AGE_SPAN_MIN,
				'inMin'   => self::IN_MIN,
				'inMax'   => self::IN_MAX,
				'inSpan'  => self::IN_SPAN_MIN,
			),
			'quota'    => self::QUOTA_PREMIUM_FEMALE,
		);
	}

	public static function rest_get( $request ) {
		unset( $request );
		return new \WP_REST_Response( self::state( get_current_user_id() ), 200 );
	}

	public static function rest_set( $request ) {
		$uid = get_current_user_id();
		$in  = array(
			'age_min' => $request->get_param( 'age_min' ),
			'age_max' => $request->get_param( 'age_max' ),
			'in_min'  => $request->get_param( 'in_min' ),
			'in_max'  => $request->get_param( 'in_max' ),
		);
		$res = self::save( $uid, $in );
		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ), 200 );
		}

		/*
		 * Drop profiles this member has not acted on yet, so the new filters
		 * take effect now rather than next Monday. The weekly grant is counted
		 * by week_assigned, which these rows keep on their way out, so this
		 * cannot be used to mint extra profiles — only to re-aim the ones left.
		 */
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'csm_tray', array( 'viewer_id' => $uid, 'status' => 'pending' ) );
		if ( function_exists( 'csm_refill_tray' ) ) {
			csm_refill_tray( $uid );
		}

		$state            = self::state( $uid );
		$state['applied'] = true;
		return new \WP_REST_Response( $state, 200 );
	}
}
