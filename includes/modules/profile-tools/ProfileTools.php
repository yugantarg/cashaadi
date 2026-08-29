<?php
/**
 * Profile Tools module.
 *
 * Consolidates three still-active WPCode snippets, all touching the member's own
 * profile / xProfile data:
 *   #11560 Profile Completion Meter (weighted, grouped) — the %-meter + checklist
 *   #11760 Monthly Age refresh (cron) + DOB visibility admin tools
 *   #11812 "Created for" xProfile field (self/son/daughter/…)
 *
 * Gated behind Config::profile_tools_enabled() (off unless wp-config sets
 * CASHAADI_PROFILE_TOOLS_ENABLED = true). The snippets stay live in WPCode until
 * a coordinated cutover: flip the flag ON in the SAME change that disables
 * #11560/#11760/#11812. While gated OFF this module does nothing — in particular
 * it does NOT schedule the age-refresh cron, so it can't double-run alongside the
 * still-active #11760.
 *
 * Note on the completion meter markup: the Verification module (#11682, via
 * verification.js) injects an OTP item into this meter's checklist and re-reads
 * its percentage by the class names .csm-pc-wrap / .csm-pc-pct / .csm-pc-bar-full
 * / .csm-pc-msg / .csm-pc-missing — those class names are preserved verbatim.
 */

namespace CAShaadi\Modules\ProfileTools;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;

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

		/* ---- #11560 completion meter ---------------------------------- */
		add_action( 'bp_before_member_body', array( __CLASS__, 'render_meter' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'meter_assets' ) );

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
	 * #11560 — Profile Completion Meter (weighted, grouped)
	 * =================================================================== */

	/** Enqueue the meter stylesheet (and inline-script host) on member pages. */
	public static function meter_assets() {
		if ( is_admin() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		Assets::style( 'profile-tools', 'assets/css/profile-tools.css' );
		Assets::script( 'profile-tools', 'assets/js/profile-tools.js' );
	}

	/** Higher weight = bigger contribution to the percentage. */
	private static function pc_group_weight( $group_name ) {
		$name = strtolower( trim( $group_name ) );
		if ( strpos( $name, 'verification' ) !== false ) { return 3; } // ICAI ID
		if ( strpos( $name, 'basic' ) !== false )        { return 2; } // sign-up core
		return 1;
	}

	private static function pc_field_filled( $field_id, $user_id ) {
		$val = xprofile_get_field_data( $field_id, $user_id );
		if ( is_array( $val ) ) { return ! empty( $val ); }
		return ( $val !== '' && $val !== null && $val !== false );
	}

	public static function completion_data( $user_id ) {
		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) { return false; }

		$groups = bp_xprofile_get_groups( array(
			'fetch_fields'     => true,
			'hide_empty_groups' => false,
		) );
		if ( empty( $groups ) ) { return false; }

		$base = trailingslashit( bp_core_get_user_domain( $user_id ) ) . 'profile/edit/group/';

		$out          = array();
		$score        = 0.0;  // weighted points earned
		$score_max    = 0.0;  // weighted points possible

		// --- Profile photo as its own weighted item (weight 3, like Verification). ---
		$photo_weight = 3;
		$has_avatar   = function_exists( 'bp_get_user_has_avatar' ) ? bp_get_user_has_avatar( $user_id ) : false;
		$score_max   += $photo_weight;
		if ( $has_avatar ) { $score += $photo_weight; }

		foreach ( $groups as $group ) {
			if ( empty( $group->fields ) ) { continue; }

			$g_weight       = self::pc_group_weight( $group->name );
			$g_total        = 0;   // all fields in group
			$g_filled       = 0;   // all filled fields
			$missing_req    = array(); // required-but-empty (shown when in this group)

			foreach ( $group->fields as $field ) {
				// Additional/optional documents must not count toward the meter.
				if ( in_array( (int) $field->id, array( 579, 485 ), true ) ) { continue; }
				$g_total++;
				$filled = self::pc_field_filled( $field->id, $user_id );
				if ( $filled ) { $g_filled++; }

				$is_required = false;
				if ( isset( $field->is_required ) ) { $is_required = (bool) $field->is_required; }
				if ( $is_required && ! $filled ) {
					$missing_req[] = array( 'name' => $field->name, 'id' => (int) $field->id );
				}
			}

			if ( $g_total < 1 ) { continue; }

			// Weighted contribution: fraction filled * group weight.
			$frac        = $g_filled / $g_total;
			$score      += $frac * $g_weight;
			$score_max  += $g_weight;

			// Option A: skip the generic Verification group row in the checklist.
			// (It still counts toward $pct above; the optional badge item below
			//  is the single, clear representation of CA verification.)
			if ( strtolower( trim( (string) $group->name ) ) === 'verification' ) { continue; }
			$out[] = array(
				'id'       => (int) $group->id,
				'name'     => $group->name,
				'url'      => $base . (int) $group->id . '/',
				'complete' => empty( $missing_req ),
				'missing'  => $missing_req,
			);
		}

		// Add the Profile Photo pseudo-group so it appears in the checklist.
		array_unshift( $out, array(
			'id'       => 0,
			'name'     => 'Profile Photo',
			'url'      => trailingslashit( bp_core_get_user_domain( $user_id ) ) . 'profile/change-avatar/',
			'complete' => (bool) $has_avatar,
			'missing'  => $has_avatar ? array() : array( array( 'name' => 'Profile Photo', 'id' => 0 ) ),
		) );

		// Sign-up fields guarantee a baseline: if the core Basic Details (sign-up)
		// fields are all filled, the meter never reads below 30%.
		$basic_done = true;
		foreach ( $out as $go ) {
			if ( strtolower( $go['name'] ) === 'basic details' && ! empty( $go['missing'] ) ) { $basic_done = false; }
		}
		$pct = ( $score_max > 0 ) ? (int) round( ( $score / $score_max ) * 100 ) : 0;
		if ( $basic_done && $pct < 30 ) { $pct = 30; }
		if ( $pct > 100 ) { $pct = 100; }
		// Optional, non-blocking prompt: shows how to earn the Verified CA badge.
		// Purely informational — does NOT affect $pct or any gate.
		if ( function_exists( 'csm_user_is_verified_ca' ) ) {
			$csm_ca_verified = csm_user_is_verified_ca( $user_id );
			$out[] = array(
				'id'       => 0,
				'name'     => $csm_ca_verified
					? 'Verified CA badge earned'
					: 'Get your Verified CA badge (optional) — upload your ICAI details',
				'url'      => bp_loggedin_user_url( bp_members_get_path_chunks( array( bp_get_profile_slug(), 'edit', 'group', 10 ) ) ),
				'complete' => (bool) $csm_ca_verified,
				'missing'  => array(),
			);
		}
		// GA4: fire profile_complete once per user when 100% reached
		if ( $pct >= 100 && get_current_user_id() ) {
			$csm_uid = get_current_user_id();
			if ( ! get_user_meta( $csm_uid, 'csm_pc_ga_fired', true ) ) {
				update_user_meta( $csm_uid, 'csm_pc_ga_fired', 1 );
				// Emitted via the enqueued profile-tools handle (no inline <script>
				// echoed from PHP); same one-time dataLayer push as #11560.
				wp_add_inline_script(
					'cashaadi-profile-tools',
					"window.dataLayer=window.dataLayer||[];dataLayer.push({event:'profile_complete'});"
				);
			}
		}

		return array(
			'pct'      => $pct,
			'groups'   => $out,
		);
	}

	private static function current_edit_group_id() {
		if ( function_exists( 'bp_get_current_profile_group_id' ) ) {
			$gid = (int) bp_get_current_profile_group_id();
			if ( $gid > 0 ) { return $gid; }
		}
		if ( function_exists( 'bp_action_variable' ) ) {
			$gid = (int) bp_action_variable( 1 );
			if ( $gid > 0 ) { return $gid; }
		}
		return 0;
	}

	/** Render the meter — only on the profile owner's own profile (Issue 9). */
	public static function render_meter() {
		if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		// CSM: only render the completion meter on the profile owner's own profile (Issue 9)
		if ( ! is_user_logged_in() ) { return; }
		if ( function_exists( 'bp_displayed_user_id' ) && bp_displayed_user_id() && bp_displayed_user_id() !== bp_loggedin_user_id() ) { return; }
		$user_id = bp_displayed_user_id();
		if ( ! $user_id ) { $user_id = get_current_user_id(); }
		if ( ! $user_id ) { return; }

		$data = self::completion_data( $user_id );
		if ( ! $data ) { return; }

		$pct        = (int) $data['pct'];
		$complete   = ( $pct >= 100 );
		$bar_class  = $complete ? 'csm-pc-bar-full' : 'csm-pc-bar';
		$cur_group  = self::current_edit_group_id();

		echo '<div class="csm-pc-wrap">';
		echo '<div class="csm-pc-head">';
		echo '<strong>Profile completion</strong>';
		echo '<span class="csm-pc-pct">' . intval( $pct ) . '%</span>';
		echo '</div>';

		echo '<div class="csm-pc-track"><div class="' . esc_attr( $bar_class ) . '" style="width:' . intval( $pct ) . '%"></div></div>';

		if ( $complete ) {
			echo '<p class="csm-pc-msg">Your profile is complete — great job!</p>';
			echo '</div>';
			return;
		}

		echo '<p class="csm-pc-msg">Complete the sections below to unlock browsing.</p>';
		echo '<ul class="csm-pc-groups">';
		foreach ( $data['groups'] as $g ) {
			$is_done   = ! empty( $g['complete'] );
			$li_class  = $is_done ? 'csm-pc-g done' : 'csm-pc-g todo';
			echo '<li class="' . esc_attr( $li_class ) . '">';
			$icon = $is_done ? '✓' : '○';
			echo '<a class="csm-pc-g-link" href="' . esc_url( $g['url'] ) . '"><span class="csm-pc-ic">' . $icon . '</span> ' . esc_html( $g['name'] ) . '</a>';

			// Only expand missing required fields for the group currently being viewed.
			if ( ! $is_done && (int) $g['id'] === (int) $cur_group && ! empty( $g['missing'] ) ) {
				echo '<ul class="csm-pc-missing">';
				foreach ( $g['missing'] as $m ) {
					echo '<li>' . esc_html( $m['name'] ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
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
