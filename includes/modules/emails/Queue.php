<?php
/**
 * Reminder Email Queue — engine.
 *
 * Migrates WPCode #11732 "Reminder Email Queue (Engine)": the visible queue
 * table, the hourly cron that plans + dispatches the four BuddyPress reminder
 * emails, per-member throttling, the master kill switch, and the read-only
 * preview/diagnose helpers used by the admin monitor (#11733 → Monitor).
 *
 * Faithful re-homing — no behaviour change vs the snippet:
 *   - Owns the wp_csm_email_queue table. The snippet's own dbDelta installer
 *     (admin_init csm_remail_install + CSM_REMAIL_DB_VER) is retired in favour
 *     of schema()/Migrator; the exact table name and columns are preserved.
 *   - Preserves the cron hook name 'csm_remail_cron' at the 'hourly' recurrence,
 *     scheduled on init when not already scheduled (there is no activation hook
 *     here), and the wp_mail_failed capture. Sending still goes through
 *     bp_send_email() exactly as the snippet did.
 *   - Every global csm_remail_* function becomes a static method here; option,
 *     user-meta, transient and token keys are byte-identical to the snippet.
 *
 * Gated behind Config::emails_enabled() (OFF by default). The WPCode snippet
 * stays active until a coordinated cutover, so this module stays dormant until
 * the owner flips the flag and disables #11732/#11733. Because register() is
 * gated, nothing is hooked — no double cron, no redeclare — while the flag is off.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Config;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Queue {

	public static function register() {
		if ( ! Config::emails_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// The table install is handled once, on version change, by the Migrator —
		// replacing the snippet's per-request admin_init dbDelta installer.
		Migrator::register( 'email_queue', array( __CLASS__, 'schema' ) );

		// One-time move to sequenced follow-ups (idempotent; option-guarded).
		add_action( 'admin_init', array( __CLASS__, 'migrate_sequence' ), 6 );

		// Hourly planner + dispatcher.
		add_action( 'csm_remail_cron', array( __CLASS__, 'cron_tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );

		// Capture the last transport-level mail failure so a test send can report why.
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
	}

	/**
	 * CREATE TABLE for the Migrator. Preserves the exact wp_csm_email_queue name
	 * and columns from #11732.
	 */
	public static function schema( $wpdb ) {
		$t = $wpdb->prefix . 'csm_email_queue';
		return "CREATE TABLE {$t} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 email_type VARCHAR(64) NOT NULL DEFAULT '',
 user_email VARCHAR(190) NOT NULL DEFAULT '',
 scheduled_for DATETIME NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 note VARCHAR(191) NOT NULL DEFAULT '',
 created_at DATETIME NULL,
 processed_at DATETIME NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY user_type (user_id,email_type),
 KEY status_sched (status,scheduled_for)
) " . $wpdb->get_charset_collate();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_email_queue';
	}

	public static function types() {
		return array(
			'csm-activation-reminder-1' => array( 'family' => 'activation', 'days' => 2, 'label' => 'Activation reminder 1' ),
			'csm-activation-reminder-2' => array( 'family' => 'activation', 'days' => 4, 'label' => 'Activation reminder 2', 'follows' => 'csm-activation-reminder-1' ),
			'csm-completion-reminder-1' => array( 'family' => 'completion', 'days' => 3, 'label' => 'Completion reminder 1' ),
			'csm-completion-reminder-2' => array( 'family' => 'completion', 'days' => 5, 'label' => 'Completion reminder 2', 'follows' => 'csm-completion-reminder-1' ),
		);
	}

	public static function plan() {
		$saved = get_option( 'csm_remail_plan', array() );
		$plan  = self::types();
		foreach ( $plan as $slug => $rule ) {
			$plan[ $slug ]['enabled'] = 1;
			if ( is_array( $saved ) && isset( $saved[ $slug ] ) ) {
				if ( isset( $saved[ $slug ]['days'] ) ) {
					$plan[ $slug ]['days'] = max( 0, intval( $saved[ $slug ]['days'] ) );
				}
				$plan[ $slug ]['enabled'] = empty( $saved[ $slug ]['enabled'] ) ? 0 : 1;
			}
		}
		return $plan;
	}

	public static function master_on() {
		return '1' === (string) get_option( 'csm_remail_master', '0' );
	}

	public static function dry_run() {
		return '1' === (string) get_option( 'csm_remail_dryrun', '1' );
	}

	public static function one_per_day() {
		return '1' === (string) get_option( 'csm_remail_one_per_day', '1' );
	}

	/** True if this member has already had a reminder delivered in the last N hours. */
	public static function member_sent_within( $uid, $hours = 24 ) {
		global $wpdb;
		$t     = self::table();
		$since = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - ( max( 1, intval( $hours ) ) * HOUR_IN_SECONDS ) ) );
		return intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND status = 'sent' AND processed_at >= %s", intval( $uid ), $since ) ) ) > 0;
	}

	public static function hourly_cap() {
		return max( 1, intval( get_option( 'csm_remail_hourly_cap', 40 ) ) );
	}

	public static function window_days() {
		return max( 0, intval( get_option( 'csm_remail_window', 30 ) ) );
	}

	/**
	 * One-time move to sequenced follow-ups. Reminder 2 is now measured from the
	 * moment reminder 1 was actually sent, so every reminder-2 row queued under the
	 * old registration-based rule is parked as 'waiting' until its predecessor has
	 * gone out. Nothing is deleted.
	 */
	public static function migrate_sequence() {
		if ( '2' === get_option( 'csm_remail_seq_mig' ) ) {
			return;
		}
		global $wpdb;
		$t     = self::table();
		$types = self::types();

		$saved = get_option( 'csm_remail_plan', array() );
		if ( is_array( $saved ) ) {
			foreach ( $types as $slug => $rule ) {
				if ( ! empty( $rule['follows'] ) && isset( $saved[ $slug ]['days'] ) ) {
					unset( $saved[ $slug ]['days'] );
				}
			}
			update_option( 'csm_remail_plan', $saved );
		}

		foreach ( $types as $slug => $rule ) {
			if ( empty( $rule['follows'] ) ) {
				continue;
			}
			$prev_label = isset( $types[ $rule['follows'] ]['label'] ) ? $types[ $rule['follows'] ]['label'] : $rule['follows'];
			$done       = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE email_type = %s AND status = 'sent'", $rule['follows'] ) );
			$skip       = $done ? ' AND user_id NOT IN ( ' . implode( ',', array_map( 'intval', $done ) ) . ' )' : '';
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$t} SET status = 'waiting', scheduled_for = NULL, note = %s WHERE email_type = %s AND status IN ( 'pending', 'deferred' )" . $skip,
				'waiting for ' . $prev_label . ' to be sent',
				$slug
			) );
		}

		update_option( 'csm_remail_seq_mig', '2' );
	}

	public static function family_for_label( $label ) {
		if ( 'Email pending' === $label ) {
			return 'activation';
		}
		if ( 'Profile pending' === $label || 'Photo pending' === $label || 'SMS pending' === $label ) {
			return 'completion';
		}
		return '';
	}

	public static function display_name( $uid ) {
		if ( function_exists( 'bp_core_get_user_displayname' ) ) {
			$n = bp_core_get_user_displayname( $uid );
			if ( $n ) {
				return $n;
			}
		}
		$u = get_userdata( $uid );
		return $u ? $u->display_name : '';
	}

	/**
	 * The member's existing activation key. BuddyPress activation keys do not
	 * expire, so the key already on file stays valid - nothing is minted and
	 * nothing is overwritten, which means any link the member already holds
	 * keeps working. Returns '' when there is genuinely no key on file.
	 */
	public static function activation_key( $uid ) {
		global $wpdb;
		$key = (string) get_user_meta( intval( $uid ), 'activation_key', true );
		if ( '' !== $key ) {
			return $key;
		}
		$u = get_userdata( intval( $uid ) );
		if ( ! $u ) {
			return '';
		}
		$sig = $wpdb->base_prefix . 'signups';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sig ) ) ) {
			$k = $wpdb->get_var( $wpdb->prepare( "SELECT activation_key FROM {$sig} WHERE user_email = %s AND active = 0 ORDER BY signup_id DESC LIMIT 1", $u->user_email ) );
			if ( $k ) {
				return (string) $k;
			}
		}
		return '';
	}

	public static function activation_url( $uid ) {
		$key = self::activation_key( $uid );
		if ( '' === $key || ! function_exists( 'bp_get_activation_page' ) ) {
			return '';
		}
		return trailingslashit( bp_get_activation_page() ) . rawurlencode( $key );
	}

	public static function profile_edit_url( $uid ) {
		if ( function_exists( 'bp_core_get_user_domain' ) && function_exists( 'bp_get_profile_slug' ) ) {
			$domain = bp_core_get_user_domain( intval( $uid ) );
			if ( $domain ) {
				return trailingslashit( trailingslashit( $domain ) . bp_get_profile_slug() . '/edit' );
			}
		}
		return home_url( '/' );
	}

	/**
	 * Tokens handed to every reminder email. BuddyPress only substitutes tokens
	 * the caller supplies, so anything the templates reference has to be listed
	 * here or it renders literally.
	 */
	public static function tokens( $uid, $label = '' ) {
		$name = self::display_name( $uid );
		return array(
			'csm.pending'      => $label,
			'csm.name'         => $name,
			'user.name'        => $name,
			'profile.edit.url'   => self::profile_edit_url( $uid ),
			'activate.fresh.url' => self::activation_url( $uid ),
		);
	}

	public static function replan( $limit = 1000 ) {
		global $wpdb;
		$t   = self::table();
		$res = array( 'scanned' => 0, 'queued' => 0, 'waiting' => 0, 'deferred' => 0, 'cleared' => 0 );

		if ( ! function_exists( 'csm_profile_pending_label' ) ) {
			update_option( 'csm_remail_last_error', 'csm_profile_pending_label() is not available - the Sales Admin Dashboard snippet must be active.' );
			return $res;
		}
		delete_option( 'csm_remail_last_error' );

		$plan   = self::plan();
		$window = self::window_days();
		$mysql  = current_time( 'mysql' );
		$now    = time();
		$users  = get_users( array(
			'number'  => $limit,
			'orderby' => 'ID',
			'order'   => 'DESC',
			'fields'  => array( 'ID', 'user_email', 'user_registered' ),
		) );

		foreach ( $users as $u ) {
			$res['scanned']++;
			$uid    = intval( $u->ID );
			$label  = csm_profile_pending_label( $uid );
			$family = self::family_for_label( $label );
			$optout = get_user_meta( $uid, 'csm_remail_optout', true );

			if ( '' === $family || '' === trim( (string) $u->user_email ) || $optout ) {
				$reason = $optout ? 'member opted out' : 'nothing outstanding';
				$n      = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', note = %s, processed_at = %s WHERE user_id = %d AND status IN ( 'pending', 'deferred', 'waiting' )", $reason, $mysql, $uid ) );
				$res['cleared'] += intval( $n );
				continue;
			}

			$reg = strtotime( $u->user_registered . ' +0000' );
			if ( ! $reg ) {
				continue;
			}

			foreach ( $plan as $slug => $rule ) {
				if ( $rule['family'] !== $family || empty( $rule['enabled'] ) ) {
					continue;
				}
				$follows = ! empty( $rule['follows'] ) ? $rule['follows'] : '';
				$row     = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE user_id = %d AND email_type = %s", $uid, $slug ) );
				$anchor  = $reg;

				if ( '' !== $follows ) {
					$prev_label = isset( $plan[ $follows ]['label'] ) ? $plan[ $follows ]['label'] : $follows;
					$prev       = $wpdb->get_row( $wpdb->prepare( "SELECT status, processed_at FROM {$t} WHERE user_id = %d AND email_type = %s", $uid, $follows ) );

					if ( ! $prev || 'sent' !== $prev->status || empty( $prev->processed_at ) ) {
						$hold = array(
							'status'        => 'waiting',
							'scheduled_for' => null,
							'note'          => 'waiting for ' . $prev_label . ' to be sent',
						);
						if ( ! $row ) {
							$wpdb->insert( $t, array_merge( $hold, array(
								'user_id'    => $uid,
								'email_type' => $slug,
								'user_email' => $u->user_email,
								'created_at' => $mysql,
							) ) );
							$res['waiting']++;
						} elseif ( 'pending' === $row->status || 'deferred' === $row->status ) {
							$wpdb->update( $t, $hold, array( 'id' => intval( $row->id ) ) );
							$res['waiting']++;
						}
						continue;
					}

					$anchor = strtotime( get_gmt_from_date( $prev->processed_at ) . ' +0000' );
					if ( ! $anchor ) {
						continue;
					}
				}

				if ( $row && 'waiting' !== $row->status ) {
					continue;
				}

				$due    = $anchor + ( intval( $rule['days'] ) * DAY_IN_SECONDS );
				$status = 'pending';
				$note   = $label;

				if ( '' === $follows && $window > 0 && $due < ( $now - ( $window * DAY_IN_SECONDS ) ) ) {
					$status = 'deferred';
					$note   = 'older than the ' . $window . '-day window';
				}

				$fields = array(
					'scheduled_for' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $due ) ),
					'status'        => $status,
					'note'          => $note,
				);

				if ( $row ) {
					$wpdb->update( $t, $fields, array( 'id' => intval( $row->id ) ) );
				} else {
					$wpdb->insert( $t, array_merge( $fields, array(
						'user_id'    => $uid,
						'email_type' => $slug,
						'user_email' => $u->user_email,
						'created_at' => $mysql,
					) ) );
				}

				if ( 'pending' === $status ) {
					$res['queued']++;
				} else {
					$res['deferred']++;
				}
			}
		}

		update_option( 'csm_remail_last_plan', array_merge( array( 'at' => $mysql ), $res ) );
		return $res;
	}

	public static function sent_last_hour() {
		global $wpdb;
		$t = self::table();
		$since = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		return intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'sent' AND processed_at >= %s", $since ) ) );
	}

	public static function due_rows( $limit = 25 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND scheduled_for <= %s ORDER BY scheduled_for ASC LIMIT %d", current_time( 'mysql' ), $limit ) );
	}

	public static function counts() {
		global $wpdb;
		$t   = self::table();
		$out = array( 'pending' => 0, 'waiting' => 0, 'deferred' => 0, 'sent' => 0, 'cancelled' => 0, 'failed' => 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status" );
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$out[ $r->status ] = intval( $r->n );
			}
		}
		$out['due_now']        = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending' AND scheduled_for <= %s", current_time( 'mysql' ) ) ) );
		$out['sent_last_hour'] = self::sent_last_hour();
		return $out;
	}

	public static function process() {
		global $wpdb;
		$t   = self::table();
		$out = array( 'due' => 0, 'sent' => 0, 'failed' => 0, 'simulated' => 0, 'cleared' => 0, 'held' => 0, 'mode' => 'paused' );
		$mysql = current_time( 'mysql' );

		if ( ! self::master_on() ) {
			$out['due']  = count( self::due_rows( 500 ) );
			$out['mode'] = 'paused';
			update_option( 'csm_remail_last_run', array_merge( array( 'at' => $mysql ), $out ) );
			return $out;
		}

		$live        = ! self::dry_run();
		$out['mode'] = $live ? 'live' : 'dry-run';
		$room        = self::hourly_cap() - self::sent_last_hour();

		if ( $live && $room < 1 ) {
			$out['mode'] = 'hourly cap reached';
			$out['due']  = count( self::due_rows( 500 ) );
			update_option( 'csm_remail_last_run', array_merge( array( 'at' => $mysql ), $out ) );
			return $out;
		}

		$batch = $live ? min( 25, $room ) : 25;
		$rows  = self::due_rows( $batch );
		$out['due'] = count( $rows );
		$types = self::types();
		$plan  = self::plan();

		foreach ( $rows as $r ) {
			$want  = isset( $types[ $r->email_type ] ) ? $types[ $r->email_type ]['family'] : '';
			$label = function_exists( 'csm_profile_pending_label' ) ? csm_profile_pending_label( $r->user_id ) : '';
			$now_f = self::family_for_label( $label );

			// A reminder that is switched off is skipped, not cancelled - the row stays
			// pending so it is still there if the reminder is switched back on.
			if ( empty( $plan[ $r->email_type ]['enabled'] ) ) {
				$out['held']++;
				continue;
			}

			if ( '' === $want || $now_f !== $want || get_user_meta( $r->user_id, 'csm_remail_optout', true ) ) {
				$wpdb->update( $t, array( 'status' => 'cancelled', 'note' => 'condition cleared before send', 'processed_at' => $mysql ), array( 'id' => $r->id ) );
				$out['cleared']++;
				continue;
			}

			// Never send an activation reminder whose link cannot be built.
			if ( 'activation' === $want && '' === self::activation_url( $r->user_id ) ) {
				$out['held']++;
				continue;
			}

			// One reminder per member per day, so nobody gets two emails hours apart.
			if ( self::one_per_day() && self::member_sent_within( $r->user_id, 24 ) ) {
				$out['held']++;
				continue;
			}

			if ( ! $live ) {
				$out['simulated']++;
				continue;
			}

			$ok = false;
			if ( function_exists( 'bp_send_email' ) ) {
				$sent = bp_send_email( $r->email_type, intval( $r->user_id ), array(
					'tokens' => self::tokens( $r->user_id, $label ),
				) );
				$ok = ! is_wp_error( $sent ) && false !== $sent;
			}

			if ( $ok ) {
				$wpdb->update( $t, array( 'status' => 'sent', 'note' => $label, 'processed_at' => $mysql ), array( 'id' => $r->id ) );
				$out['sent']++;
			} else {
				$wpdb->update( $t, array( 'status' => 'failed', 'note' => 'bp_send_email did not accept the message', 'processed_at' => $mysql ), array( 'id' => $r->id ) );
				$out['failed']++;
			}
		}

		update_option( 'csm_remail_last_run', array_merge( array( 'at' => $mysql ), $out ) );
		return $out;
	}

	public static function cron_tick() {
		// Re-planning walks every member, so do it at most once every 6 hours.
		$last = get_option( 'csm_remail_last_plan' );
		$age  = ( is_array( $last ) && ! empty( $last['at'] ) ) ? ( time() - strtotime( get_gmt_from_date( $last['at'] ) . ' +0000' ) ) : PHP_INT_MAX;
		if ( $age > ( 6 * HOUR_IN_SECONDS ) ) {
			self::replan( 1000 );
		}
		self::process();
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'csm_remail_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'csm_remail_cron' );
		}
	}

	// Capture the last transport-level mail failure so a test send can report why.
	public static function capture_mail_error( $e ) {
		if ( is_wp_error( $e ) ) {
			set_transient( 'csm_remail_mail_error', $e->get_error_message(), 300 );
		}
	}

	/**
	 * Send one specific queue row immediately. Used only by the explicit
	 * "Send now (test)" button in the monitor screen, one row per click.
	 * Deliberately independent of the hourly queue so a test never drains the batch.
	 */
	public static function send_one( $id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", intval( $id ) ) );
		if ( ! $r ) {
			return 'That queue row no longer exists.';
		}
		if ( 'sent' === $r->status ) {
			return 'That reminder was already sent.';
		}
		if ( ! function_exists( 'bp_send_email' ) ) {
			return 'bp_send_email() is not available - BuddyPress emails are not loaded.';
		}

		$label = function_exists( 'csm_profile_pending_label' ) ? csm_profile_pending_label( $r->user_id ) : '';
		$mysql = current_time( 'mysql' );
		delete_transient( 'csm_remail_mail_error' );

		$sent = bp_send_email( $r->email_type, intval( $r->user_id ), array(
			'tokens' => self::tokens( $r->user_id, $label ),
		) );

		$transport = get_transient( 'csm_remail_mail_error' );
		$failed    = is_wp_error( $sent ) || false === $sent || ! empty( $transport );

		if ( $failed ) {
			$why = is_wp_error( $sent ) ? $sent->get_error_message() : ( $transport ? $transport : 'bp_send_email() returned false' );
			$wpdb->update( $t, array( 'status' => 'failed', 'note' => 'test send: ' . substr( $why, 0, 150 ), 'processed_at' => $mysql ), array( 'id' => $r->id ) );
			return 'TEST SEND FAILED for ' . $r->user_email . ' - ' . $why;
		}

		$wpdb->update( $t, array( 'status' => 'sent', 'note' => 'manual test send (' . $label . ')', 'processed_at' => $mysql ), array( 'id' => $r->id ) );
		return 'Test send accepted by the mailer for ' . $r->user_email . ' (' . $r->email_type . ').';
	}

	/**
	 * Read-only diagnostic. Builds the BuddyPress email exactly as the sender would,
	 * inspects it, and compares it with a template that is known to arrive.
	 * Sends nothing.
	 */
	public static function diagnose( $id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", intval( $id ) ) );
		if ( ! $r ) {
			return 'Row not found.';
		}
		$out = array();

		if ( function_exists( 'wp_mail' ) ) {
			try {
				$ref = new \ReflectionFunction( 'wp_mail' );
				$fn  = $ref->getFileName();
				$out[] = 'wp_mail owner: ' . basename( dirname( $fn ) ) . '/' . basename( $fn );
			} catch ( \Exception $e ) {
				$out[] = 'wp_mail owner: unknown';
			}
		}

		if ( ! function_exists( 'bp_get_email' ) ) {
			return 'bp_get_email() is unavailable.';
		}

		$pairs = array( 'REMINDER ' . $r->email_type => $r->email_type, 'REFERENCE friends-request' => 'friends-request' );
		foreach ( $pairs as $tag => $type ) {
			$email = bp_get_email( $type );
			if ( is_wp_error( $email ) ) {
				$out[] = $tag . ': bp_get_email FAILED - ' . $email->get_error_message();
				continue;
			}
			$email->set_to( intval( $r->user_id ) );
			$email->set_tokens( self::tokens( $r->user_id, 'Profile pending' ) );

			$from = $email->get_from();
			$to   = $email->get_to();
			$out[] = $tag
				. ': from=' . ( is_object( $from ) ? $from->get_address() : 'none' )
				. ', recipients=' . ( is_array( $to ) ? count( $to ) : 0 )
				. ', subject=' . strlen( (string) $email->get_subject( 'replace-tokens' ) )
				. ', html=' . strlen( (string) $email->get_content_html( 'replace-tokens' ) )
				. ', plain=' . strlen( (string) $email->get_content_plaintext( 'replace-tokens' ) );

			$rendered = (string) $email->get_content_html( 'replace-tokens' );
			preg_match_all( '/\{\{+([a-z0-9._]+)\}\}+/i', $rendered, $m );
			$out[] = $tag . ': unresolved placeholders after token replacement = ' . count( $m[1] ) . ( empty( $m[1] ) ? '' : ' (' . implode( ', ', array_unique( $m[1] ) ) . ')' );

			$valid = $email->validate();
			$out[] = $tag . ': validate() ' . ( is_wp_error( $valid ) ? 'FAILED - ' . $valid->get_error_code() . ' - ' . $valid->get_error_message() : 'passed' );
		}

		// Where does this member's activation key actually live?
		$sig = $wpdb->base_prefix . 'signups';
		$has_sig = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sig ) ) ? 1 : 0;
		$u = get_userdata( $r->user_id );
		$out[] = 'ACT: user_status=' . ( $u ? intval( $u->user_status ) : -1 )
			. ', usermeta activation_key length=' . strlen( (string) get_user_meta( $r->user_id, 'activation_key', true ) )
			. ', signups table present=' . $has_sig;
		if ( $has_sig && $u ) {
			$srow = $wpdb->get_row( $wpdb->prepare( "SELECT active, activation_key, registered FROM {$sig} WHERE user_email = %s ORDER BY signup_id DESC LIMIT 1", $u->user_email ) );
			$out[] = 'ACT: signup row=' . ( $srow ? 'found, active=' . intval( $srow->active ) . ', key length=' . strlen( (string) $srow->activation_key ) . ', registered=' . $srow->registered : 'none' );
		}
		$out[] = 'ACT: activation page url length=' . ( function_exists( 'bp_get_activation_page' ) ? strlen( (string) bp_get_activation_page() ) : -1 );

		return implode( ' || ', $out );
	}

	/**
	 * Read-only simulation: exactly what process() would do if the master switch
	 * were on and dry run off. Sends nothing, writes nothing. Mirrors the sender's
	 * gates in the same order.
	 */
	public static function preview( $limit = 1500 ) {
		global $wpdb;
		$t     = self::table();
		$types = self::types();
		$plan  = self::plan();
		$now   = current_time( 'mysql' );

		$out = array(
			'send'      => array(),
			'later'     => array(),
			'held'      => array(),
			'cancel'    => array(),
			'waiting'   => array(),
			'spaced'    => array(),
			'by_type'   => array(),
			'per_run'   => min( 25, self::hourly_cap() ),
			'cap'       => self::hourly_cap(),
			'generated' => $now,
		);

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' ORDER BY scheduled_for ASC LIMIT %d", intval( $limit ) ) );
		if ( ! $rows ) {
			return $out;
		}

		$seen = array();

		foreach ( $rows as $r ) {
			$want  = isset( $types[ $r->email_type ]['family'] ) ? $types[ $r->email_type ]['family'] : '';
			$label = function_exists( 'csm_profile_pending_label' ) ? csm_profile_pending_label( $r->user_id ) : '';
			$now_f = self::family_for_label( $label );

			$item = array(
				'id'      => intval( $r->id ),
				'user_id' => intval( $r->user_id ),
				'name'    => self::display_name( $r->user_id ),
				'email'   => $r->user_email,
				'type'    => $r->email_type,
				'tlabel'  => isset( $types[ $r->email_type ]['label'] ) ? $types[ $r->email_type ]['label'] : $r->email_type,
				'when'    => $r->scheduled_for,
				'why'     => $label,
				'reason'  => '',
				'run'     => 0,
			);

			if ( empty( $plan[ $r->email_type ]['enabled'] ) ) {
				$item['reason'] = 'this reminder is switched off - the row is skipped and left pending';
				$out['held'][] = $item;
				continue;
			}

			if ( '' === $want || $now_f !== $want ) {
				$item['reason'] = 'no longer applies - member now reads: ' . ( '' === $label ? 'unknown' : $label );
				$out['cancel'][] = $item;
				continue;
			}


			if ( 'activation' === $want && '' === self::activation_url( $r->user_id ) ) {
				$item['reason'] = 'no activation key on file, so the activation link cannot be built';
				$out['held'][] = $item;
				continue;
			}

			if ( get_user_meta( $r->user_id, 'csm_remail_optout', true ) ) {
				$item['reason'] = 'member has opted out of reminders';
				$out['cancel'][] = $item;
				continue;
			}

			if ( $r->scheduled_for > $now ) {
				$item['reason'] = 'not due yet';
				$out['later'][] = $item;
				continue;
			}

			if ( self::one_per_day() && ( isset( $seen[ $r->user_id ] ) || self::member_sent_within( $r->user_id, 24 ) ) ) {
				$item['reason'] = 'held back so this member only receives one reminder per day';
				$out['spaced'][] = $item;
				continue;
			}

			$seen[ $r->user_id ] = 1;
			$out['send'][] = $item;
		}

		$per = max( 1, intval( $out['per_run'] ) );
		foreach ( $out['send'] as $i => $row ) {
			$out['send'][ $i ]['run'] = intval( floor( $i / $per ) ) + 1;
			$k = $row['tlabel'];
			if ( ! isset( $out['by_type'][ $k ] ) ) {
				$out['by_type'][ $k ] = 0;
			}
			$out['by_type'][ $k ]++;
		}

		$wait = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'waiting' ORDER BY id ASC LIMIT %d", intval( $limit ) ) );
		if ( $wait ) {
			foreach ( $wait as $w ) {
				$out['waiting'][] = array(
					'id'      => intval( $w->id ),
					'user_id' => intval( $w->user_id ),
					'name'    => self::display_name( $w->user_id ),
					'email'   => $w->user_email,
					'type'    => $w->email_type,
					'tlabel'  => isset( $types[ $w->email_type ]['label'] ) ? $types[ $w->email_type ]['label'] : $w->email_type,
					'when'    => '',
					'why'     => '',
					'reason'  => $w->note,
					'run'     => 0,
				);
			}
		}

		return $out;
	}
}
