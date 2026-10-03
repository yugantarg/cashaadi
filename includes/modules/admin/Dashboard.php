<?php
/**
 * Sales Admin Dashboard module.
 *
 * Faithful migration of WPCode #11688 ("CSM — Sales Admin Dashboard"): a
 * read-only admin menu page listing every user with activation status,
 * profile-completion status, phone, email, membership, acted-count and last
 * active — sortable and filterable, for the sales team to follow up.
 *
 * Read-only: it never writes to the database. It reads WP users, BuddyPress
 * xProfile fields, the multisite wp_signups table, PMPro membership levels, and
 * the wp_csm_tray table (owned by the discover module). It creates no tables.
 *
 * Gated behind Config::admin_enabled() (OFF unless wp-config defines
 * CASHAADI_ADMIN_ENABLED === true) so it can't run alongside the still-active
 * #11688 snippet. The snippet exposes csm_profile_pending_label() as a global,
 * which the reminder-email engine (#11732) calls by that exact name; when this
 * module is enabled it re-declares that one global (guarded, delegating here)
 * so #11732 keeps working after #11688 is disabled at cutover.
 */

namespace CAShaadi\Modules\Admin;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard {

	public static function register() {
		if ( ! Config::admin_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Keep the one cross-snippet global (#11732 calls csm_profile_pending_label
		// by name). Guarded so it never collides with the active #11688 snippet.
		require_once __DIR__ . '/global-functions.php';

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/** Register the top-level "Sales Dashboard" admin menu page (#11688). */
	public static function menu() {
		add_menu_page(
			'Sales Dashboard',
			'Sales Dashboard',
			'manage_options',
			'csm-sales-dashboard',
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			3
		);
	}

	public static function profile_fields_complete( $user_id ) {
		if ( function_exists( 'cashaadi_has_missing_required_fields' ) ) {
			return ! cashaadi_has_missing_required_fields( $user_id );
		}
		if ( function_exists( 'bpprocn_get_completion_percent' ) ) {
			return intval( bpprocn_get_completion_percent( $user_id ) ) >= 100;
		}
		if ( function_exists( 'bp_xprofile_get_groups' ) ) {
			$groups = bp_xprofile_get_groups( array( 'fetch_fields' => true ) );
			foreach ( $groups as $g ) {
				if ( empty( $g->fields ) ) continue;
				foreach ( $g->fields as $f ) {
					if ( ! empty( $f->is_required ) ) {
						$val = xprofile_get_field_data( $f->id, $user_id );
						if ( $val === '' || $val === null || $val === false ) return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Single pending-signal for the Sales Dashboard "Complete" column.
	 * Returns the FIRST outstanding onboarding step, in gate order, so the
	 * dashboard always agrees with the Discover gate (snippets 11618 / 11620).
	 * $is_pending may be passed in to avoid re-querying pending signup emails.
	 */
	public static function profile_pending_label( $user_id, $is_pending = null ) {
		$user_id = intval( $user_id );
		if ( ! $user_id ) { return 'Unknown'; }
		$u = get_userdata( $user_id );
		if ( ! $u ) { return 'Unknown'; }

		// 1) Account / email activation still outstanding.
		if ( intval( $u->user_status ) !== 0 ) { return 'Email pending'; }
		if ( null === $is_pending ) {
			$list = self::pending_signup_emails();
			$is_pending = is_array( $list ) && in_array( strtolower( $u->user_email ), $list, true );
		}
		if ( $is_pending ) { return 'Email pending'; }

		// 2) Required profile fields.
		if ( ! self::profile_fields_complete( $user_id ) ) { return 'Profile pending'; }

		// 3) Profile photo.
		if ( function_exists( 'bp_get_user_has_avatar' ) && ! bp_get_user_has_avatar( $user_id ) ) {
			return 'Photo pending';
		}

		// 4) Phone OTP - the Discover gate condition the dashboard used to ignore.
		if ( function_exists( 'csm_phone_is_verified' ) && ! csm_phone_is_verified( $user_id ) ) {
			return 'SMS pending';
		}

		return 'Complete';
	}

	/**
	 * "Sign-ups by channel" for a date range (IST dates; default last 30 days).
	 * Members from before attribution existed count as Unknown.
	 */
	public static function channel_summary() {
		if ( ! class_exists( '\\CAShaadi\\Modules\\Tracking\\Attribution' ) ) {
			return '';
		}
		global $wpdb;
		$tz   = wp_timezone();
		$to   = isset( $_GET['sto'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['sto'] ) ? $_GET['sto'] : wp_date( 'Y-m-d' );
		$from = isset( $_GET['sfrom'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['sfrom'] ) ? $_GET['sfrom'] : wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		$a    = ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$b    = ( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(m.meta_value,''),'unknown') ch, COUNT(*) n
			 FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'csm_channel'
			 WHERE u.user_registered BETWEEN %s AND %s
			 GROUP BY ch ORDER BY n DESC",
			$a,
			$b
		) );
		$total  = 0;
		foreach ( (array) $rows as $r ) {
			$total += (int) $r->n;
		}
		$labels = \CAShaadi\Modules\Tracking\Attribution::channels();

		// Collapsed until asked for (owner, 2026-10-03); stays open after a
		// date range is submitted, so the result is not hidden.
		$open = isset( $_GET['sfrom'] ) || isset( $_GET['sto'] ) ? ' open' : '';
		$h  = '<details' . $open . ' style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin:15px 0">';
		$h .= '<summary style="cursor:pointer;font-weight:600">Sign-ups by channel</summary>';
		$h .= '<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0">';
		$h .= '<input type="hidden" name="page" value="csm-sales-dashboard">';
		$h .= '<input type="date" name="sfrom" value="' . esc_attr( $from ) . '"> to <input type="date" name="sto" value="' . esc_attr( $to ) . '"> <button class="button">Show</button></form>';
		if ( ! $total ) {
			return $h . '<p style="margin:0;color:#666">No sign-ups in this range.</p></details>';
		}
		$h .= '<table class="widefat striped" style="max-width:480px"><tbody>';
		foreach ( (array) $rows as $r ) {
			$h .= sprintf(
				'<tr><td>%s</td><td style="text-align:right">%d</td><td style="text-align:right;color:#666">%d%%</td></tr>',
				esc_html( isset( $labels[ $r->ch ] ) ? $labels[ $r->ch ] : $r->ch ),
				(int) $r->n,
				(int) round( 100 * $r->n / $total )
			);
		}
		$h .= sprintf( '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>%d</strong></td><td></td></tr>', $total );
		return $h . '</tbody></table></details>';
	}

	/** Map an already-computed label to its rank (no recomputation). */
	public static function pending_rank_from_label( $label ) {
		$map = array(
			'Unknown'         => 0,
			'Email pending'   => 1,
			'Profile pending' => 2,
			'Photo pending'   => 3,
			'SMS pending'     => 4,
			'Complete'        => 5,
		);
		return isset( $map[ $label ] ) ? $map[ $label ] : 0;
	}

	/** Numeric rank so the 'complete' column sorts worst-blocked first. */
	public static function profile_pending_rank( $user_id, $is_pending = null ) {
		$map = array(
			'Unknown'         => 0,
			'Email pending'   => 1,
			'Profile pending' => 2,
			'Photo pending'   => 3,
			'SMS pending'     => 4,
			'Complete'        => 5,
		);
		$label = self::profile_pending_label( $user_id, $is_pending );
		return isset( $map[ $label ] ) ? $map[ $label ] : 0;
	}

	/** Back-compat wrapper: TRUE only when every step (incl. phone OTP) is done. */
	public static function is_profile_complete( $user_id ) {
		return ( 'Complete' === self::profile_pending_label( $user_id ) );
	}

	public static function pending_signup_emails() {
		global $wpdb;
		static $pending = null;
		if ( $pending !== null ) return $pending;
		$pending = array();
		$table = $wpdb->base_prefix . 'signups';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists ) {
			// A row with active = 0 is a signup that was never activated. A user who
			// abandoned one attempt and then completed a second keeps BOTH rows, so an
			// open row on its own does not prove the email is unverified. Subtract every
			// address that also owns an activated row. Fixes false "Email pending".
			$open = $wpdb->get_col( "SELECT user_email FROM {$table} WHERE active = 0" );
			if ( $open ) {
				$open = array_map( 'strtolower', $open );
				$done = $wpdb->get_col( "SELECT user_email FROM {$table} WHERE active = 1" );
				if ( $done ) {
					$done    = array_map( 'strtolower', $done );
					$pending = array_values( array_diff( $open, $done ) );
				} else {
					$pending = $open;
				}
			}
		}
		return $pending;
	}

	public static function membership_label( $user_id ) {
		if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			$lvl = pmpro_getMembershipLevelForUser( $user_id );
			if ( $lvl && intval( $lvl->id ) === Config::PMPRO_PREMIUM_LEVEL ) return 'Premium';
			if ( $lvl && ! empty( $lvl->id ) ) return esc_html( $lvl->name );
		}
		if ( function_exists( 'csm_lead_is_pending' ) && csm_lead_is_pending( $user_id ) ) {
			return 'Lead';
		}
		return 'Free';
	}

	public static function acted_count( $uid ) {
		global $wpdb;
		$t = $wpdb->prefix . 'csm_tray';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE viewer_id = %d AND status IN ('liked','passed')", $uid ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$f_pending  = isset( $_GET['fpending'] )  ? sanitize_text_field( $_GET['fpending'] )  : '';
		$f_member   = isset( $_GET['fmember'] )   ? sanitize_text_field( $_GET['fmember'] )   : '';
		$f_source   = isset( $_GET['fsrc'] )      ? sanitize_key( $_GET['fsrc'] )             : '';
		$search     = isset( $_GET['s'] )         ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby    = isset( $_GET['orderby'] )   ? sanitize_text_field( $_GET['orderby'] )   : 'registered';
		$order      = ( isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' ) ? 'asc' : 'desc';

		$pending_emails = self::pending_signup_emails();

		$args = array(
			'number'  => -1,
			'fields'  => array( 'ID', 'user_login', 'user_email', 'user_registered', 'display_name' ),
		);
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$users = get_users( $args );

		$rows = array();
		foreach ( $users as $u ) {
			$uid   = $u->ID;
			$name  = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : $u->display_name;
			if ( ! $name ) $name = $u->display_name;
			$phone_raw = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( Config::FIELD_PHONE, $uid ) : '';
			// Phone Number field type returns an HTML tel: anchor; strip to a clean number.
			$phone = trim( wp_strip_all_tags( (string) $phone_raw ) );
			if ( $phone === '' && $phone_raw ) { $phone = preg_replace( '/[^0-9+]/', '', (string) $phone_raw ); }
			$is_pending  = in_array( strtolower( $u->user_email ), $pending_emails, true );
			$pending    = self::profile_pending_label( $uid, $is_pending );
			$member      = self::membership_label( $uid );
			$gender = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( Config::FIELD_GENDER, $uid ) : '';
			$acted  = self::acted_count( $uid );
			$last_active = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $uid ) : get_user_meta( $uid, 'last_activity', true );
			$last_ts     = $last_active ? strtotime( $last_active ) : 0;

			if ( $f_pending !== '' && $pending !== $f_pending ) continue;
			if ( $f_member === 'premium' && strtolower( $member ) !== 'premium' ) continue;
			if ( $f_member === 'free'    && strtolower( $member ) !== 'free' ) continue;
			if ( $f_member === 'lead'    && strtolower( $member ) !== 'lead' ) continue;

			// Where they came from (v1.65.4). Members from before then read "Unknown".
			$src = class_exists( '\\CAShaadi\\Modules\\Tracking\\Attribution' )
				? \CAShaadi\Modules\Tracking\Attribution::describe( $uid )
				: array( 'key' => 'unknown', 'label' => 'Unknown', 'campaign' => '' );
			if ( $f_source !== '' && $src['key'] !== $f_source ) continue;

			$rows[] = array(
				'id'         => $uid,
				'name'       => $name,
				'email'      => $u->user_email,
				'phone'      => $phone,
				'complete'   => $pending,
				'complete_rank' => self::pending_rank_from_label( $pending ),
				'member'     => $member,
				'gender'     => $gender,
				'acted'      => $acted,
				// Shifted to site time: date_i18n() below treats the stamp as local.
				'registered' => strtotime( get_date_from_gmt( $u->user_registered ) ),
				'last_ts'    => $last_ts,
				// $last_ts is UTC, so compare with UTC now — current_time('timestamp')
				// is site time and made every "ago" 5½ hours too old.
				'last_human' => $last_ts ? human_time_diff( $last_ts, time() ) . ' ago' : 'Never',
				'source'     => $src['label'],
				'campaign'   => $src['campaign'],
			);
		}

		$sort_map = array(
			'name'       => 'name',
			'complete'   => 'complete_rank',
			'member'     => 'member',
			'acted'      => 'acted',
			'registered' => 'registered',
			'last'       => 'last_ts',
			'phone'      => 'phone',
		);
		$key = isset( $sort_map[ $orderby ] ) ? $sort_map[ $orderby ] : 'registered';
		usort( $rows, function ( $a, $b ) use ( $key, $order ) {
			$va = $a[ $key ]; $vb = $b[ $key ];
			$cmp = is_numeric( $va ) && is_numeric( $vb ) ? ( $va <=> $vb ) : strcasecmp( (string) $va, (string) $vb );
			return $order === 'asc' ? $cmp : -$cmp;
		} );

		$total = count( $rows );

		$base = admin_url( 'admin.php?page=csm-sales-dashboard' );
		$qs   = array( 'fpending' => $f_pending, 'fmember' => $f_member, 'fsrc' => $f_source, 's' => $search );
		$sort_link = function ( $col, $label ) use ( $base, $qs, $orderby, $order ) {
			$new_order = ( $orderby === $col && $order === 'asc' ) ? 'desc' : 'asc';
			$url = add_query_arg( array_merge( $qs, array( 'orderby' => $col, 'order' => $new_order ) ), $base );
			$arrow = $orderby === $col ? ( $order === 'asc' ? ' ▲' : ' ▼' ) : '';
			return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $arrow . '</a>';
		};

		echo '<div class="wrap"><h1>Sales Dashboard</h1>';
		echo '<p style="font-size:13px;color:#555;">Review new users, their activation & profile status, and phone numbers so the sales team can reach out and guide them.</p>';
		if ( class_exists( __NAMESPACE__ . '\\ActiveUsers' ) ) {
			echo ActiveUsers::card(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo self::channel_summary(); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<form method="get" style="margin:15px 0;padding:12px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">';
		echo '<input type="hidden" name="page" value="csm-sales-dashboard" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search name / email / username" style="min-width:220px;" /> ';

		$csm_pending_opts = array( 'Complete', 'Email pending', 'Profile pending', 'Photo pending', 'SMS pending' );
		echo ' <select name="fpending"><option value="">Any status</option>';
		foreach ( $csm_pending_opts as $csm_opt ) {
			echo '<option value="' . esc_attr( $csm_opt ) . '"' . selected( $f_pending, $csm_opt, false ) . '>' . esc_html( $csm_opt ) . '</option>';
		}
		echo '</select>';

		echo ' <select name="fmember"><option value="">Any membership</option>';
		echo '<option value="free"'    . selected( $f_member, 'free',    false ) . '>Free</option>';
		echo '<option value="lead"'    . selected( $f_member, 'lead',    false ) . '>Lead</option>';
		echo '<option value="premium"' . selected( $f_member, 'premium', false ) . '>Premium</option></select>';

		if ( class_exists( '\\CAShaadi\\Modules\\Tracking\\Attribution' ) ) {
			echo ' <select name="fsrc"><option value="">Any source</option>';
			foreach ( \CAShaadi\Modules\Tracking\Attribution::channels() as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $f_source, $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}

		echo ' <button class="button button-primary">Filter</button> ';
		echo '<a class="button" href="' . esc_url( $base ) . '">Reset</a>';
		echo '</form>';

		echo '<p><strong>' . intval( $total ) . '</strong> user(s) match.</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . $sort_link( 'name', 'Name' ) . '</th>';
		echo '<th>' . $sort_link( 'phone', 'Phone' ) . '</th>';
		echo '<th>Email</th>';
		echo '<th>' . $sort_link( 'complete', 'Profile' ) . '</th>';
		echo '<th>' . $sort_link( 'member', 'Membership' ) . '</th>';
		echo '<th>Gender</th>';
		echo '<th>' . $sort_link( 'acted', 'Acted' ) . '</th>';
		echo '<th>' . $sort_link( 'registered', 'Registered' ) . '</th>';
		echo '<th>' . $sort_link( 'last', 'Last active' ) . '</th>';
		echo '<th>Source</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="10">No users match the current filters.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$comp_color   = $r['complete'] === 'Complete' ? '#1a7f37' : '#996800';
			$profile_url  = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $r['id'] ) : get_edit_user_link( $r['id'] );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $profile_url ) . '">' . esc_html( $r['name'] ) . '</a></td>';
			$phone_disp = $r['phone'] !== '' ? $r['phone'] : '—';
			$phone_digits = preg_replace( '/[^0-9+]/', '', $r['phone'] );
			if ( $phone_digits !== '' ) {
				echo '<td><a href="tel:' . esc_attr( $phone_digits ) . '">' . esc_html( $phone_disp ) . '</a></td>';
			} else {
				echo '<td>—</td>';
			}
			echo '<td><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a></td>';
			echo '<td style="color:' . $comp_color . ';font-weight:600;">' . esc_html( $r['complete'] ) . '</td>';
			echo '<td>' . esc_html( $r['member'] ) . '</td>';
			echo '<td>' . esc_html( $r['gender'] ) . '</td>';
			echo '<td>' . esc_html( $r['acted'] ) . '</td>';
			echo '<td>' . esc_html( $r['registered'] ? date_i18n( 'j M Y', $r['registered'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['last_human'] ) . '</td>';
			echo '<td>' . esc_html( $r['source'] ) . ( '' !== $r['campaign'] ? '<br><small style="color:#666">' . esc_html( $r['campaign'] ) . '</small>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}
