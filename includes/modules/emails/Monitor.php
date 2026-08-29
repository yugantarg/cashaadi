<?php
/**
 * Reminder Email Monitor — admin screen.
 *
 * Migrates WPCode #11733 "Reminder Email Monitor (Admin)": the
 * Sales Dashboard > Reminder Emails page that shows every scheduled / sent /
 * cancelled / failed reminder, the master kill switch, the settings form, the
 * read-only "what would be sent" simulation, and the per-row operations
 * (cancel, test send, diagnose, stop member, replan, release, etc.).
 *
 * Faithful re-homing — no behaviour change vs the snippet. All queue logic lives
 * in Queue (from #11732); this class only renders + handles the admin POSTs, with
 * the same nonce action ('csm_remail_ops'), the same capability ('manage_options'),
 * the same parent menu ('csm-sales-dashboard', owned by the Sales Admin Dashboard
 * snippet) and page slug ('csm-reminder-emails'), and byte-identical copy/markup.
 *
 * The snippet has no <style>/<script> blocks and no JS — every style is a
 * per-element inline attribute whose colours are computed in PHP (status badges,
 * KPI tiles, the master-status strip), so they are kept inline verbatim to keep
 * the admin output identical; there is nothing to extract to an asset file.
 *
 * Gated behind Config::emails_enabled() (OFF by default), same as Queue.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monitor {

	public static function register() {
		if ( ! Config::emails_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
	}

	public static function menu() {
		if ( ! class_exists( __NAMESPACE__ . '\\Queue' ) ) {
			return;
		}
		add_submenu_page(
			'csm-sales-dashboard',
			'Reminder Emails',
			'Reminder Emails',
			'manage_options',
			'csm-reminder-emails',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function badge( $status ) {
		$map = array(
			'pending'   => array( '#1d4ed8', 'Scheduled' ),
			'deferred'  => array( '#92400e', 'Deferred' ),
			'waiting'   => array( '#7c3aed', 'Waiting' ),
			'sent'      => array( '#166534', 'Sent' ),
			'cancelled' => array( '#6b7280', 'Cancelled' ),
			'failed'    => array( '#b91c1c', 'Failed' ),
		);
		$c = isset( $map[ $status ] ) ? $map[ $status ] : array( '#374151', $status );
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:' . $c[0] . '">' . esc_html( $c[1] ) . '</span>';
	}

	public static function tile( $label, $value, $colour ) {
		echo '<div style="flex:0 0 auto;min-width:130px;padding:12px 16px;border:1px solid #dcdcde;border-left:4px solid ' . $colour . ';background:#fff;border-radius:4px">';
		echo '<div style="font-size:22px;font-weight:700;line-height:1.1">' . esc_html( $value ) . '</div>';
		echo '<div style="font-size:12px;color:#50575e;margin-top:2px">' . esc_html( $label ) . '</div>';
		echo '</div>';
	}

	public static function handle_post() {
		global $wpdb;
		$t      = Queue::table();
		$mysql  = current_time( 'mysql' );
		$notice = '';

		if ( ! isset( $_POST['csm_remail_action'] ) ) {
			return '';
		}
		check_admin_referer( 'csm_remail_ops' );
		$action = sanitize_text_field( wp_unslash( $_POST['csm_remail_action'] ) );

		if ( 'pause' === $action ) {
			update_option( 'csm_remail_master', '0' );
			$notice = 'Sending is now PAUSED. Nothing will go out until you switch it back on.';

		} elseif ( 'save' === $action ) {
			update_option( 'csm_remail_master', empty( $_POST['master'] ) ? '0' : '1' );
			update_option( 'csm_remail_dryrun', empty( $_POST['dryrun'] ) ? '0' : '1' );
			update_option( 'csm_remail_hourly_cap', max( 1, intval( $_POST['hourly_cap'] ) ) );
			update_option( 'csm_remail_window', max( 0, intval( $_POST['window'] ) ) );
			update_option( 'csm_remail_one_per_day', empty( $_POST['one_per_day'] ) ? '0' : '1' );
			$plan = array();
			foreach ( Queue::types() as $slug => $rule ) {
				$plan[ $slug ] = array(
					'days'    => isset( $_POST['days'][ $slug ] ) ? max( 0, intval( $_POST['days'][ $slug ] ) ) : $rule['days'],
					'enabled' => empty( $_POST['on'][ $slug ] ) ? 0 : 1,
				);
			}
			update_option( 'csm_remail_plan', $plan );
			$notice = 'Settings saved.';

		} elseif ( 'replan' === $action ) {
			$r      = Queue::replan( 1000 );
			$notice = sprintf( 'Re-planned. %d members scanned, %d newly scheduled, %d deferred, %d cleared. No email was sent.', $r['scanned'], $r['queued'], $r['deferred'], $r['cleared'] );

		} elseif ( 'runnow' === $action ) {
			$r      = Queue::process();
			$notice = sprintf( 'Queue run in %s mode. %d due, %d sent, %d simulated, %d cleared, %d failed.', $r['mode'], $r['due'], $r['sent'], $r['simulated'], $r['cleared'], $r['failed'] );

		} elseif ( 'cancel_all' === $action ) {
			$n      = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', note = %s, processed_at = %s WHERE status IN ( 'pending', 'deferred', 'waiting' )", 'cancelled by admin', $mysql ) );
			$notice = intval( $n ) . ' scheduled email(s) cancelled.';

		} elseif ( 'cancel_row' === $action ) {
			$wpdb->update( $t, array( 'status' => 'cancelled', 'note' => 'cancelled by admin', 'processed_at' => $mysql ), array( 'id' => intval( $_POST['row_id'] ) ) );
			$notice = 'That email was cancelled.';

		} elseif ( 'stop_user' === $action ) {
			$uid = intval( $_POST['row_user'] );
			update_user_meta( $uid, 'csm_remail_optout', 1 );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', note = %s, processed_at = %s WHERE user_id = %d AND status IN ( 'pending', 'deferred', 'waiting' )", 'member opted out', $mysql, $uid ) );
			$notice = 'All reminders stopped for that member.';

		} elseif ( 'diagnose' === $action ) {
			$notice = Queue::diagnose( intval( $_POST['row_id'] ) );

		} elseif ( 'send_one' === $action ) {
			$notice = Queue::send_one( intval( $_POST['row_id'] ) );

		} elseif ( 'release' === $action ) {
			$rows = $wpdb->get_results( "SELECT id FROM {$t} WHERE status = 'deferred' ORDER BY id ASC" );
			$cap  = Queue::hourly_cap();
			$i    = 0;
			foreach ( $rows as $r ) {
				$offset = ( intval( floor( $i / $cap ) ) + 1 ) * HOUR_IN_SECONDS;
				$wpdb->update( $t, array(
					'status'        => 'pending',
					'note'          => 'released by admin',
					'scheduled_for' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + $offset ) ),
				), array( 'id' => $r->id ) );
				$i++;
			}
			$notice = $i . ' deferred email(s) released into the queue, spread at ' . $cap . ' per hour.';
		}

		return $notice;
	}

	public static function render_page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}
		$t      = Queue::table();
		$notice = self::handle_post();
		$counts = Queue::counts();
		$plan   = Queue::plan();
		$types  = Queue::types();
		$live   = Queue::master_on() && ! Queue::dry_run();
		$next   = wp_next_scheduled( 'csm_remail_cron' );
		$err    = get_option( 'csm_remail_last_error' );
		$lastr  = get_option( 'csm_remail_last_run' );

		echo '<div class="wrap"><h1>Reminder Emails</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}

		// Master status strip.
		if ( ! Queue::master_on() ) {
			$state = 'PAUSED - nothing is being sent';
			$bg    = '#6b7280';
		} elseif ( ! $live ) {
			$state = 'DRY RUN - due emails are counted but not sent';
			$bg    = '#b45309';
		} else {
			$state = 'LIVE - due emails are being sent to members';
			$bg    = '#b91c1c';
		}
		echo '<div style="margin:16px 0;padding:14px 18px;border-radius:6px;color:#fff;background:' . $bg . ';display:flex;align-items:center;gap:18px;flex-wrap:wrap">';
		echo '<strong style="font-size:15px">' . esc_html( $state ) . '</strong>';
		echo '<form method="post" style="margin:0">';
		wp_nonce_field( 'csm_remail_ops' );
		echo '<input type="hidden" name="csm_remail_action" value="pause">';
		echo '<button type="submit" class="button" style="font-weight:600">Stop all sending now</button>';
		echo '</form>';
		echo '</div>';

		// Tiles.
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px">';
		self::tile( 'Due right now', $counts['due_now'], '#1d4ed8' );
		self::tile( 'Scheduled total', $counts['pending'], '#2271b1' );
		self::tile( 'Waiting on reminder 1', isset( $counts['waiting'] ) ? $counts['waiting'] : 0, '#7c3aed' );
		self::tile( 'Deferred', $counts['deferred'], '#b45309' );
		self::tile( 'Sent', $counts['sent'], '#166534' );
		self::tile( 'Sent last hour', $counts['sent_last_hour'], '#166534' );
		self::tile( 'Cancelled', $counts['cancelled'], '#6b7280' );
		self::tile( 'Failed', $counts['failed'], '#b91c1c' );
		echo '</div>';

		echo '<p style="color:#50575e">Hourly cron: ' . ( $next ? 'next run ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'j M Y, H:i' ) ) : '<strong>not scheduled</strong>' ) . '.';
		if ( is_array( $lastr ) ) {
			echo ' Last run ' . esc_html( $lastr['at'] ) . ' in ' . esc_html( $lastr['mode'] ) . ' mode: ' . intval( $lastr['sent'] ) . ' sent, ' . intval( $lastr['simulated'] ) . ' simulated.';
		}
		echo '</p>';

		// ------------------------------------------------------------------
		// Exactly what would be sent if the master switch were flipped on.
		// Read-only simulation - see Queue::preview() in the engine.
		// ------------------------------------------------------------------
		$pv     = Queue::preview( 2000 );
		$n_send = count( $pv['send'] );
		$per    = max( 1, intval( $pv['per_run'] ) );
		$runs   = (int) ceil( $n_send / $per );

		echo '<div style="background:#fff;border:2px solid #1d4ed8;border-radius:6px;padding:16px 18px;margin-bottom:22px">';
		echo '<h2 style="margin-top:0">If you flip the master switch, this is exactly what happens</h2>';
		echo '<p style="margin-top:0;color:#50575e">Nothing here has been sent. This is a live simulation of the sender, applying the same checks in the same order, recalculated every time you load this page.</p>';

		if ( ! $n_send ) {
			echo '<p><strong>No email would go out right now.</strong></p>';
		} else {
			$addrs = count( array_unique( wp_list_pluck( $pv['send'], 'email' ) ) );
			echo '<p style="font-size:15px"><strong>' . intval( $n_send ) . ' emails</strong> would be delivered, to ' . intval( $addrs ) . ' distinct addresses, in <strong>' . intval( $runs ) . '</strong> hourly batch' . ( 1 === $runs ? '' : 'es' ) . ' of up to ' . intval( $per ) . ' each.</p>';
			echo '<p style="color:#50575e">The cron fires hourly and the sender takes at most 25 rows per run, so the real ceiling is ' . intval( $per ) . ' per hour even though the cap is set to ' . intval( $pv['cap'] ) . '. Clearing the whole backlog would take roughly ' . intval( $runs ) . ' hour' . ( 1 === $runs ? '' : 's' ) . '.</p>';

			echo '<table class="widefat striped" style="max-width:520px;margin:12px 0"><thead><tr><th>Reminder</th><th>How many</th></tr></thead><tbody>';
			foreach ( $pv['by_type'] as $lab => $n ) {
				echo '<tr><td>' . esc_html( $lab ) . '</td><td><strong>' . intval( $n ) . '</strong></td></tr>';
			}
			echo '</tbody></table>';

			echo '<details open style="margin-top:14px"><summary style="cursor:pointer;font-weight:600">Every recipient, in send order (' . intval( $n_send ) . ')</summary>';
			echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Batch</th><th>Member</th><th>Email</th><th>Reminder</th><th>Why</th><th>Due since</th></tr></thead><tbody>';
			foreach ( $pv['send'] as $row ) {
				echo '<tr><td>' . intval( $row['run'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['email'] ) . '</td><td>' . esc_html( $row['tlabel'] ) . '</td><td>' . esc_html( $row['why'] ) . '</td><td>' . esc_html( $row['when'] ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}

		$groups = array(
			'held'   => 'Skipped and left pending - see the reason on each row',
			'cancel' => 'Would be cancelled at send time, not delivered',
			'spaced' => 'Held back so a member does not get two reminders in one day',
			'later'  => 'Queued for a later date, not due yet',
			'waiting' => 'Waiting for reminder 1 to be sent - no date until then',
		);
		foreach ( $groups as $gk => $gt ) {
			$gn = count( $pv[ $gk ] );
			echo '<details style="margin-top:10px"><summary style="cursor:pointer;font-weight:600">' . esc_html( $gt ) . ' (' . intval( $gn ) . ')</summary>';
			if ( ! $gn ) {
				echo '<p style="color:#50575e">None.</p>';
			} else {
				echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Member</th><th>Email</th><th>Reminder</th><th>Scheduled for</th><th>Reason</th></tr></thead><tbody>';
				foreach ( $pv[ $gk ] as $row ) {
					echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['email'] ) . '</td><td>' . esc_html( $row['tlabel'] ) . '</td><td>' . esc_html( $row['when'] ) . '</td><td>' . esc_html( $row['reason'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</details>';
		}
		echo '</div>';

		// Settings.
		echo '<form method="post" style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 18px;margin-bottom:22px">';
		wp_nonce_field( 'csm_remail_ops' );
		echo '<input type="hidden" name="csm_remail_action" value="save">';
		echo '<h2 style="margin-top:0">Controls</h2>';
		echo '<p><label><input type="checkbox" name="master" value="1" ' . checked( Queue::master_on(), true, false ) . '> <strong>Master switch: allow this queue to send email</strong></label><br>';
		echo '<span style="color:#50575e">Off means the queue keeps planning and stays visible, but nothing is delivered.</span></p>';
		echo '<p><label><input type="checkbox" name="dryrun" value="1" ' . checked( Queue::dry_run(), true, false ) . '> <strong>Dry run</strong> - process the queue without delivering anything</label></p>';
		echo '<p><label>Maximum sends per hour <input type="number" name="hourly_cap" min="1" max="500" value="' . esc_attr( Queue::hourly_cap() ) . '" style="width:90px"></label></p>';
		echo '<p><label>Only schedule members whose reminder came due in the last <input type="number" name="window" min="0" max="3650" value="' . esc_attr( Queue::window_days() ) . '" style="width:90px"> days</label><br>';
		echo '<span style="color:#50575e">Anything older is parked as Deferred so an old backlog cannot go out by accident. 0 disables the window.</span></p>';
		echo '<p><label><input type="checkbox" name="one_per_day" value="1" ' . checked( Queue::one_per_day(), true, false ) . '> <strong>Never send one member more than one reminder in 24 hours</strong></label><br>';
		echo '<span style="color:#50575e">Recommended. Members who registered a while ago are due for several reminders at once, so without this a single person can receive two emails within a couple of hours.</span></p>';

		echo '<table class="widefat striped" style="max-width:760px;margin:12px 0"><thead><tr><th>Reminder</th><th>Send</th><th>Days after</th><th>Applies to</th></tr></thead><tbody>';
		foreach ( $types as $slug => $rule ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $rule['label'] ) . '</strong><br><code style="font-size:11px">' . esc_html( $slug ) . '</code></td>';
			echo '<td><label><input type="checkbox" name="on[' . esc_attr( $slug ) . ']" value="1" ' . checked( ! empty( $plan[ $slug ]['enabled'] ), true, false ) . '> on</label></td>';
			echo '<td><input type="number" min="0" max="365" name="days[' . esc_attr( $slug ) . ']" value="' . esc_attr( $plan[ $slug ]['days'] ) . '" style="width:80px"><br><span style="font-size:11px;color:#50575e">' . ( empty( $rule['follows'] ) ? 'after they registered' : 'after reminder 1 was sent' ) . '</span></td>';
			echo '<td>' . ( 'activation' === $rule['family'] ? 'Members showing <em>Email pending</em>' : 'Members showing <em>Profile / Photo / SMS pending</em>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">Save settings</button></p>';
		echo '</form>';

		// Operations.
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">';
		$ops = array(
			'replan'     => array( 'Rebuild the schedule', 'button' ),
			'runnow'     => array( 'Process the queue now', 'button' ),
			'release'    => array( 'Release deferred into the queue', 'button' ),
			'cancel_all' => array( 'Cancel everything scheduled', 'button' ),
		);
		foreach ( $ops as $key => $op ) {
			echo '<form method="post" style="margin:0">';
			wp_nonce_field( 'csm_remail_ops' );
			echo '<input type="hidden" name="csm_remail_action" value="' . esc_attr( $key ) . '">';
			echo '<button type="submit" class="' . esc_attr( $op[1] ) . '">' . esc_html( $op[0] ) . '</button>';
			echo '</form>';
		}
		echo '</div>';

		// Scheduled queue.
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status IN ( 'pending', 'deferred' ) ORDER BY FIELD( status, 'pending', 'deferred' ), scheduled_for ASC LIMIT 300" );
		echo '<h2>Scheduled</h2>';
		if ( ! $rows ) {
			echo '<p>Nothing is scheduled. Use <em>Rebuild the schedule</em> to plan from the current member list.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Member</th><th>Email</th><th>Reminder</th><th>Scheduled for</th><th>Status</th><th>Why</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$label = isset( $types[ $r->email_type ]['label'] ) ? $types[ $r->email_type ]['label'] : $r->email_type;
				echo '<tr>';
				echo '<td><a href="' . esc_url( get_edit_user_link( $r->user_id ) ) . '">' . esc_html( Queue::display_name( $r->user_id ) ) . '</a></td>';
				echo '<td>' . esc_html( $r->user_email ) . '</td>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td>' . esc_html( $r->scheduled_for ) . '</td>';
				echo '<td>' . self::badge( $r->status ) . '</td>';
				echo '<td style="color:#50575e">' . esc_html( $r->note ) . '</td>';
				echo '<td style="white-space:nowrap">';
				echo '<form method="post" style="display:inline;margin:0">';
				wp_nonce_field( 'csm_remail_ops' );
				echo '<input type="hidden" name="csm_remail_action" value="cancel_row"><input type="hidden" name="row_id" value="' . intval( $r->id ) . '">';
				echo '<button type="submit" class="button button-small">Cancel</button></form> ';
				echo '<form method="post" style="display:inline;margin:0">';
				wp_nonce_field( 'csm_remail_ops' );
				echo '<input type="hidden" name="csm_remail_action" value="send_one"><input type="hidden" name="row_id" value="' . intval( $r->id ) . '">';
				echo '<button type="submit" class="button button-small">Send now (test)</button></form> ';
				echo '<form method="post" style="display:inline;margin:0">';
				wp_nonce_field( 'csm_remail_ops' );
				echo '<input type="hidden" name="csm_remail_action" value="diagnose"><input type="hidden" name="row_id" value="' . intval( $r->id ) . '">';
				echo '<button type="submit" class="button button-small">Diagnose</button></form> ';
				echo '<form method="post" style="display:inline;margin:0">';
				wp_nonce_field( 'csm_remail_ops' );
				echo '<input type="hidden" name="csm_remail_action" value="stop_user"><input type="hidden" name="row_user" value="' . intval( $r->user_id ) . '">';
				echo '<button type="submit" class="button button-small">Stop this member</button></form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// History.
		$hist = $wpdb->get_results( "SELECT * FROM {$t} WHERE status IN ( 'sent', 'failed', 'cancelled' ) ORDER BY processed_at DESC LIMIT 60" );
		echo '<h2 style="margin-top:28px">Recently processed</h2>';
		if ( ! $hist ) {
			echo '<p>Nothing has been processed yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Member</th><th>Email</th><th>Reminder</th><th>Status</th><th>When</th><th>Note</th></tr></thead><tbody>';
			foreach ( $hist as $r ) {
				$label = isset( $types[ $r->email_type ]['label'] ) ? $types[ $r->email_type ]['label'] : $r->email_type;
				echo '<tr>';
				echo '<td>' . esc_html( Queue::display_name( $r->user_id ) ) . '</td>';
				echo '<td>' . esc_html( $r->user_email ) . '</td>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td>' . self::badge( $r->status ) . '</td>';
				echo '<td>' . esc_html( $r->processed_at ) . '</td>';
				echo '<td style="color:#50575e">' . esc_html( $r->note ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
