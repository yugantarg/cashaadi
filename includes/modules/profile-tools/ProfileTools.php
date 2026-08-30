<?php
/**
 * Profile Tools module.
 *
 * Two still-active WPCode snippets that touch the member's own xProfile data:
 *   #11760 Monthly Age refresh (cron) + DOB visibility admin tools
 *   #11812 "Created for" xProfile field (self/son/daughter/…)
 *
 * NOTE: #11560 Profile Completion Meter is intentionally NOT migrated. Per the
 * owner (2026-08-30) the app now forces completion through the onboarding wizard
 * (the pending step on login; skip only for non-mandatory fields), so the separate
 * completion meter — and the #11620 profile blur gate — are being RETIRED, not
 * migrated. Do not re-introduce the meter here.
 *
 * Gated behind Config::profile_tools_enabled() (off unless wp-config sets
 * CASHAADI_PROFILE_TOOLS_ENABLED = true). The snippets stay live in WPCode until a
 * coordinated cutover: flip the flag ON in the SAME change that disables
 * #11760/#11812 (and, separately, retire #11560/#11620). While gated OFF this
 * module does nothing — in particular it does NOT schedule the age-refresh cron,
 * so it can't double-run alongside the still-active #11760.
 */

namespace CAShaadi\Modules\ProfileTools;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileTools {

	/** Cron hook fired monthly to recompute Age from DOB (#11760). */
	const CRON_HOOK = 'csm_age_refresh';

	/** Custom cron recurrence key registered by this module (#11760). */
	const CRON_RECURRENCE = 'csm_monthly';

	public static function register() {
		if ( ! Config::profile_tools_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		/* ---- #11760 age refresh cron + DOB visibility tools ----------- */
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedule' ) );
		add_action( 'init', array( __CLASS__, 'cron_setup' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'age_refresh_run' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dob_vis_tool' ) );
		add_action( 'admin_notices', array( __CLASS__, 'age_backfill_tool' ) );

		/* ---- #11812 "Created for" field ------------------------------- */
		add_action( 'bp_init', array( __CLASS__, 'created_for_install' ), 20 );
	}

	/* ===================================================================
	 * #11760 — Monthly Age refresh (cron) + DOB visibility admin tools
	 * =================================================================== */

	/** Admin-only DOB visibility inspector/migrator: ?csmdob=report | apply */
	public static function dob_vis_tool() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( empty( $_GET['csmdob'] ) ) { return; }
		$mode = sanitize_text_field( wp_unslash( $_GET['csmdob'] ) );
		global $wpdb;
		$fid = Config::FIELD_DOB;
		$ids = $wpdb->get_col( "SELECT ID FROM " . $wpdb->users );
		$def = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM " . $wpdb->prefix . "bp_xprofile_meta WHERE object_type = 'field' AND object_id = %d AND meta_key = 'default_visibility'", $fid ) );
		$nometa  = 0;
		$vals    = array();
		$changed = 0;
		foreach ( (array) $ids as $uid ) {
			$uid = (int) $uid;
			$v = get_user_meta( $uid, 'bp_xprofile_visibility_levels', true );
			if ( ! is_array( $v ) ) {
				$v = array();
				$nometa++;
			}
			if ( isset( $v[ $fid ] ) ) {
				$k = (string) $v[ $fid ];
			} else {
				$k = 'inherit';
			}
			$vals[ $k ] = isset( $vals[ $k ] ) ? $vals[ $k ] + 1 : 1;
			if ( 'apply' === $mode ) {
				if ( ! isset( $v[ $fid ] ) || 'public' !== $v[ $fid ] ) {
					$prev = isset( $v[ $fid ] ) ? (string) $v[ $fid ] : 'inherit';
					if ( '' === (string) get_user_meta( $uid, 'csm_dob_vis_prev', true ) ) {
						update_user_meta( $uid, 'csm_dob_vis_prev', $prev );
					}
					$v[ $fid ] = 'public';
					update_user_meta( $uid, 'bp_xprofile_visibility_levels', $v );
					$changed++;
				}
			}
		}
		$out = 'CSMDOB mode=' . $mode . ' users=' . count( (array) $ids ) . ' nometa=' . $nometa . ' default=' . ( $def ? $def : 'NONE' ) . ' changed=' . $changed . ' |';
		foreach ( $vals as $k => $c ) {
			$out .= ' ' . $k . '=' . $c;
		}
		if ( 'apply' === $mode ) {
			update_option( 'csm_dob_vis_mig', gmdate( 'c' ) . ' changed=' . $changed );
		}
		echo '<div class="notice notice-info"><p>' . esc_html( $out ) . '</p></div>';
	}

	/**
	 * Shared: recompute Age (xprofile field 286) from Date of birth (field 586).
	 * Reads the date straight from the profile data table because
	 * xprofile_get_field_data() is filtered on this site and returns 'N years old'.
	 */
	private static function age_backfill_run( $apply ) {
		global $wpdb;
		$bp  = function_exists( 'buddypress' ) ? buddypress() : null;
		$tbl = ( $bp && isset( $bp->profile->table_name_data ) ) ? $bp->profile->table_name_data : $wpdb->prefix . 'bp_xprofile_data';
		$dob = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM " . $tbl . " WHERE field_id = %d", Config::FIELD_DOB ), ARRAY_A );
		$age = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM " . $tbl . " WHERE field_id = %d", Config::FIELD_AGE ), ARRAY_A );
		$amap = array();
		foreach ( (array) $age as $a ) {
			$amap[ (int) $a['user_id'] ] = trim( (string) $a['value'] );
		}
		$today = (int) gmdate( 'Ymd' );
		$res = array(
			'dobrows' => count( (array) $dob ),
			'agerows' => count( $amap ),
			'missing' => 0,
			'stale'   => 0,
			'ok'      => 0,
			'bad'     => 0,
			'written' => 0,
		);
		foreach ( (array) $dob as $d ) {
			$uid = (int) $d['user_id'];
			$raw = trim( (string) $d['value'] );
			if ( '' === $raw ) { continue; }
			$ts = strtotime( $raw );
			if ( ! $ts ) { $res['bad']++; continue; }
			$born = (int) gmdate( 'Ymd', $ts );
			if ( $born > $today ) { $res['bad']++; continue; }
			$years = (int) floor( ( $today - $born ) / 10000 );
			if ( $years < 1 || $years > 120 ) { $res['bad']++; continue; }
			$cur = isset( $amap[ $uid ] ) ? $amap[ $uid ] : '';
			if ( '' === $cur ) {
				$res['missing']++;
			} elseif ( (string) $years !== $cur ) {
				$res['stale']++;
			} else {
				$res['ok']++;
			}
			if ( $apply && (string) $years !== $cur ) {
				xprofile_set_field_data( Config::FIELD_AGE, $uid, $years );
				$res['written']++;
			}
		}
		return $res;
	}

	private static function age_stats_line( $res ) {
		$out = '';
		foreach ( (array) $res as $k => $v ) {
			$out .= ' ' . $k . '=' . $v;
		}
		return trim( $out );
	}

	/** Monthly schedule (30 days). */
	public static function cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_RECURRENCE ] ) ) {
			$schedules[ self::CRON_RECURRENCE ] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => 'Once a month (CAShaadi)',
			);
		}
		return $schedules;
	}

	public static function cron_setup() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	public static function age_refresh_run() {
		$res = self::age_backfill_run( true );
		update_option( 'csm_age_refresh_last', gmdate( 'Y-m-d H:i:s' ) . ' UTC ' . self::age_stats_line( $res ) );
	}

	/** Admin-only manual tool: ?csmage=status | report | apply */
	public static function age_backfill_tool() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( empty( $_GET['csmage'] ) ) { return; }
		$mode = sanitize_text_field( wp_unslash( $_GET['csmage'] ) );
		$next = wp_next_scheduled( self::CRON_HOOK );
		$msg  = 'CSMAGE mode=' . $mode . ' next=' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'NOT SCHEDULED' );
		$msg .= ' last=[' . (string) get_option( 'csm_age_refresh_last', 'never' ) . ']';
		if ( 'status' !== $mode ) {
			$res  = self::age_backfill_run( 'apply' === $mode );
			$msg .= ' | ' . self::age_stats_line( $res );
		}
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/* ===================================================================
	 * #11812 — "Created for" xProfile field (self/son/daughter/…)
	 * =================================================================== */

	/**
	 * Creates the native BuddyPress selectbox "Created for" field once (guarded by
	 * an option + a name check), OPTIONAL so it never blocks existing members.
	 * SAFE: creates one field the first time it runs, then no-ops. Never edits or
	 * deletes existing fields/data.
	 */
	public static function created_for_install() {
		if ( '1' === get_option( 'csm_cf_created_for_installed' ) ) {
			return;
		}
		if ( ! function_exists( 'xprofile_insert_field' ) || ! function_exists( 'xprofile_get_field_id_from_name' ) ) {
			return; // BuddyPress xProfile not loaded yet.
		}

		// Already exists? mark done and stop.
		if ( xprofile_get_field_id_from_name( 'Created for' ) ) {
			update_option( 'csm_cf_created_for_installed', '1' );
			return;
		}

		// Resolve the "Basic Details" group id by name, fall back to group 1.
		$group_id = 1;
		if ( function_exists( 'bp_xprofile_get_groups' ) ) {
			$groups = bp_xprofile_get_groups( array( 'fetch_fields' => false ) );
			if ( ! empty( $groups ) ) {
				foreach ( $groups as $g ) {
					if ( isset( $g->name ) && 0 === strcasecmp( trim( $g->name ), 'Basic Details' ) ) {
						$group_id = (int) $g->id;
						break;
					}
				}
			}
		}

		// Create the parent selectbox field.
		$field_id = xprofile_insert_field( array(
			'field_group_id' => $group_id,
			'parent_id'      => 0,
			'type'           => 'selectbox',
			'name'           => 'Created for',
			'description'    => 'Who is this profile being created for?',
			'is_required'    => false,
			'can_delete'     => true,
		) );

		if ( ! $field_id ) {
			return; // try again next load
		}

		// Create the options as child fields (order preserved; Self is default).
		$options = array( 'Self', 'Son', 'Daughter', 'Brother', 'Sister', 'Relative', 'Friend' );
		$order   = 1;
		foreach ( $options as $opt ) {
			xprofile_insert_field( array(
				'field_group_id'    => $group_id,
				'parent_id'         => $field_id,
				'type'              => 'option',
				'name'              => $opt,
				'option_order'      => $order,
				'is_default_option' => ( 'Self' === $opt ),
			) );
			$order++;
		}

		update_option( 'csm_cf_created_for_installed', '1' );
	}
}
