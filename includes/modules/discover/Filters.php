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

	/*
	 * Pick-list filters (v1.85.0). The dimensions that move like rates most in
	 * the 24 Oct research: community (+13 pts for men, +16 for women), mother
	 * tongue, religion, diet; income and CA/Inter matter mainly to women.
	 * key => array( xprofile field name, label ). Qualification is by id.
	 */
	const CHOICES = array(
		'community' => array( 'Community', 'Community' ),
		'tongue'    => array( 'Language (Mother Tongue)', 'Mother tongue' ),
		'religion'  => array( 'Religion', 'Religion' ),
		'diet'      => array( 'Diet', 'Diet' ),
		'income'    => array( 'Annual Income', 'Annual income' ),
		'qual'      => array( '', 'Qualification' ),
	);

	public static function register() {
		add_filter( 'csm_tray_size', array( __CLASS__, 'tray_size' ), 10, 2 );
		/*
		 * Filters narrow the profiles already in the member's tray; they do
		 * not choose which profiles fill it (owner, 2026-10-25: "the filter
		 * should only work on the profiles in my tray"). So no
		 * csm_refill_extra_where hook: the weekly set is drawn as for anyone.
		 */
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* ------------------------------------------------------------ policy */

	/** Filters are for every Premium member (v1.85.0; until then Premium women only). */
	public static function eligible( $uid ) {
		$uid = (int) $uid;
		return $uid && Membership::is_premium( $uid );
	}

	/** The 50-a-week quota stays a Premium-women rule. */
	public static function premium_female( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! Membership::is_premium( $uid ) || ! function_exists( 'cashaadi' ) ) {
			return false;
		}
		return 'Female' === trim( (string) cashaadi()->get_gender( $uid ) );
	}

	public static function choice_field( $key ) {
		if ( 'qual' === $key ) {
			return (int) Config::FIELD_QUALIFICATION;
		}
		return isset( self::CHOICES[ $key ] ) && function_exists( 'xprofile_get_field_id_from_name' )
			? (int) xprofile_get_field_id_from_name( self::CHOICES[ $key ][0] )
			: 0;
	}

	/** Community and mother tongue filter on groups (Core\Buckets), not raw text. */
	public static function bucket_kind( $key ) {
		return in_array( $key, array( 'community', 'tongue' ), true ) && class_exists( '\\CAShaadi\\Core\\Buckets' ) ? $key : '';
	}

	/** The options a field offers, in the field's own order. Cached per request. */
	public static function choice_options( $key ) {
		static $cache = array();
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		if ( self::bucket_kind( $key ) ) {
			return $cache[ $key ] = \CAShaadi\Core\Buckets::labels( $key );
		}
		$out = array();
		$fid = self::choice_field( $key );
		if ( $fid && class_exists( '\BP_XProfile_Field' ) ) {
			$f = \BP_XProfile_Field::get_instance( $fid );
			foreach ( (array) ( $f ? $f->get_children() : array() ) as $c ) {
				$name = trim( (string) $c->name );
				if ( '' !== $name && ! \CAShaadi\Core\Profile::is_placeholder( $name ) ) {
					$out[] = $name;
				}
			}
		}
		return $cache[ $key ] = $out;
	}

	/** 50 a week for an eligible member; anyone else keeps the engine's number. */
	public static function tray_size( $size, $uid ) {
		return self::premium_female( $uid )
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

		/*
		 * Open-ended ranges are allowed (owner, 2026-10-25): one end left on
		 * "Any" means no limit that way. The minimum span only applies when
		 * both ends are chosen.
		 */
		$has_age = self::filled( $in, 'age_min' ) || self::filled( $in, 'age_max' );
		$both    = self::filled( $in, 'age_min' ) && self::filled( $in, 'age_max' );
		if ( $has_age ) {
			$lo = self::filled( $in, 'age_min' ) ? self::clamp( (int) $in['age_min'], self::AGE_MIN, self::AGE_MAX ) : self::AGE_MIN;
			$hi = self::filled( $in, 'age_max' ) ? self::clamp( (int) $in['age_max'], self::AGE_MIN, self::AGE_MAX ) : self::AGE_MAX;
			if ( $lo > $hi ) {
				list( $lo, $hi ) = array( $hi, $lo );
			}
			if ( $both && ( $hi - $lo ) < self::AGE_SPAN_MIN ) {
				return new \WP_Error(
					'csm_age_span',
					sprintf( 'Choose an age range of at least %d years.', self::AGE_SPAN_MIN )
				);
			}
			$out['age_min'] = $lo;
			$out['age_max'] = $hi;
		}

		$has_ht = self::filled( $in, 'in_min' ) || self::filled( $in, 'in_max' );
		$both   = self::filled( $in, 'in_min' ) && self::filled( $in, 'in_max' );
		if ( $has_ht ) {
			$lo = self::filled( $in, 'in_min' ) ? self::clamp( (int) $in['in_min'], self::IN_MIN, self::IN_MAX ) : self::IN_MIN;
			$hi = self::filled( $in, 'in_max' ) ? self::clamp( (int) $in['in_max'], self::IN_MIN, self::IN_MAX ) : self::IN_MAX;
			if ( $lo > $hi ) {
				list( $lo, $hi ) = array( $hi, $lo );
			}
			if ( $both && ( $hi - $lo ) < self::IN_SPAN_MIN ) {
				return new \WP_Error(
					'csm_height_span',
					sprintf( 'Choose a height range of at least %d inches.', self::IN_SPAN_MIN )
				);
			}
			$out['in_min'] = $lo;
			$out['in_max'] = $hi;
		}

		/*
		 * Free-text fields (Community on this install) have no option list, so
		 * they offer "same as mine" instead: $in[ $k ] === 'same'.
		 */
		foreach ( array_keys( self::CHOICES ) as $k ) {
			if ( isset( $in[ $k ] ) && 'same' === $in[ $k ] && ! self::choice_options( $k ) && '' !== self::own_value( $uid, $k ) ) {
				$out[ $k ] = 'same';
				continue;
			}
			if ( empty( $in[ $k ] ) || ! is_array( $in[ $k ] ) ) {
				continue;
			}
			$pick = array_values( array_intersect( self::choice_options( $k ), array_map( 'strval', $in[ $k ] ) ) );
			if ( $pick ) {
				$out[ $k ] = $pick;
			}
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

		/*
		 * Pick lists: a profile that has not answered is KEPT (we cannot say it
		 * does not match, and dropping every blank would empty most pools).
		 */
		foreach ( array_keys( self::CHOICES ) as $k ) {
			if ( isset( $f[ $k ] ) && 'same' === $f[ $k ] ) {
				$fid  = self::choice_field( $k );
				$mine = self::norm( self::own_value( $uid, $k ) );
				if ( $fid && '' !== $mine ) {
					// Typed values compared ignoring case and outer spaces; blanks kept.
					$add .= $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						" AND ( NOT EXISTS ( SELECT 1 FROM {$t} c{$fid} WHERE c{$fid}.user_id = xp.user_id AND c{$fid}.field_id = %d AND c{$fid}.value <> '' )
						   OR EXISTS ( SELECT 1 FROM {$t} d{$fid} WHERE d{$fid}.user_id = xp.user_id AND d{$fid}.field_id = %d AND LOWER(TRIM(d{$fid}.value)) = %s ) )",
						$fid, $fid, $mine
					);
				}
				continue;
			}
			if ( empty( $f[ $k ] ) || ! is_array( $f[ $k ] ) ) {
				continue;
			}
			if ( self::bucket_kind( $k ) ) {
				/*
				 * Ticked groups only (owner, 2026-10-24). "Others" is everyone
				 * with no group: an answer that fits none, or no answer.
				 */
				$mk     = \CAShaadi\Core\Buckets::meta_key( $k );
				$um     = $wpdb->usermeta;
				$named  = array_values( array_diff( $f[ $k ], array( \CAShaadi\Core\Buckets::OTHERS ) ) );
				$others = in_array( \CAShaadi\Core\Buckets::OTHERS, $f[ $k ], true );
				$ors    = array();
				if ( $named ) {
					$in    = implode( ',', array_fill( 0, count( $named ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ors[] = $wpdb->prepare( "EXISTS ( SELECT 1 FROM {$um} b{$k}2 WHERE b{$k}2.user_id = xp.user_id AND b{$k}2.meta_key = %s AND b{$k}2.meta_value IN ({$in}) )", array_merge( array( $mk ), $named ) );
				}
				if ( $others ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ors[] = $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$um} b{$k}1 WHERE b{$k}1.user_id = xp.user_id AND b{$k}1.meta_key = %s )", $mk );
				}
				if ( $ors ) {
					$add .= ' AND ( ' . implode( ' OR ', $ors ) . ' )';
				}
				continue;
			}
			$fid = self::choice_field( $k );
			if ( ! $fid ) {
				continue;
			}
			$in   = implode( ',', array_fill( 0, count( $f[ $k ] ), '%s' ) );
			$add .= $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				" AND ( NOT EXISTS ( SELECT 1 FROM {$t} c{$fid} WHERE c{$fid}.user_id = xp.user_id AND c{$fid}.field_id = %d AND c{$fid}.value <> '' )
				   OR EXISTS ( SELECT 1 FROM {$t} d{$fid} WHERE d{$fid}.user_id = xp.user_id AND d{$fid}.field_id = %d AND d{$fid}.value IN ({$in}) ) )",
				array_merge( array( $fid, $fid ), $f[ $k ] )
			);
		}

		return $sql . $add;
	}

	/**
	 * Of these profile IDs, the ones this member's filters let through, in
	 * the same order. All of them when no filter is set.
	 */
	public static function keep( $uid, array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$where = $ids ? self::where( '', $uid ) : '';
		if ( '' === $where ) {
			return $ids;
		}
		global $wpdb;
		$in = implode( ',', $ids ); // every value intval'd
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT xp.user_id FROM {$wpdb->prefix}bp_xprofile_data xp
			 WHERE xp.field_id = %d AND xp.user_id IN ({$in})" . $where,
			Config::FIELD_GENDER
		) ) );
		return array_values( array_intersect( $ids, $ok ) );
	}

	/** How many undecided profiles in the tray the current filters let through. For the UI hint. */
	public static function pool_size( $uid ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT profile_id FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND status = 'pending'",
			(int) $uid
		) );
		return count( self::keep( $uid, (array) $ids ) );
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
			'quota'    => class_exists( __NAMESPACE__ . '\\Discover' ) ? Discover::quota_for( $uid ) : self::QUOTA_PREMIUM_FEMALE,
			'choices'  => self::choices_for_ui( $uid ),
		);
	}

	/**
	 * Every filter dimension for the sheets: a pick list where the field has
	 * options, else "same as mine" (only offered when the member filled it in).
	 */
	public static function choices_for_ui( $uid = 0 ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		$out = array();
		foreach ( self::CHOICES as $k => $def ) {
			if ( ! self::choice_field( $k ) ) {
				continue;
			}
			$opts = self::choice_options( $k );
			if ( $opts ) {
				// 'tick': shown as a list of tick boxes (groups), not chips.
				$out[] = array( 'key' => $k, 'label' => $def[1], 'options' => $opts, 'tick' => (bool) self::bucket_kind( $k ) );
			} elseif ( '' !== self::own_value( $uid, $k ) ) {
				$out[] = array( 'key' => $k, 'label' => $def[1], 'same' => true, 'mine' => self::own_value( $uid, $k ) );
			}
		}
		return $out;
	}

	/** The member's own answer for a dimension, as typed. */
	public static function own_value( $uid, $k ) {
		$fid = self::choice_field( $k );
		if ( ! $fid || ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}
		$v = xprofile_get_field_data( $fid, (int) $uid );
		return trim( wp_strip_all_tags( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) );
	}

	/** Lower-case, single-spaced: how free-text answers are compared. */
	public static function norm( $v ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $v ) ) );
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
		foreach ( array_keys( self::CHOICES ) as $k ) {
			$in[ $k ] = $request->get_param( $k );
		}
		$res = self::save( $uid, $in );
		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ), 200 );
		}

		// The tray is left as it is: the queue hides what the filters exclude.
		$state            = self::state( $uid );
		$state['applied'] = true;
		return new \WP_REST_Response( $state, 200 );
	}
}
