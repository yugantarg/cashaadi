<?php
/**
 * Watch: a daily look for the quiet failures (owner, 2026-10-27: "these are
 * silent and I won't come to know").
 *
 * Each check is a bug class found by hand on 26-27 Oct, looked for in the
 * data so a recurrence is noticed without anyone stumbling on a profile:
 *
 *   groups        a member's stored community / language / state groups no
 *                 longer match their answer (a save that missed regrouping;
 *                 filters then skip them). Runs before the 03:10 regroup.
 *   regroup       the nightly regroup has not run in the last two days.
 *   unseen        members who joined 2-14 days ago and are in nobody's
 *                 Discover at all (left out of the pool).
 *   phone         numbers opened in the last 7 days with no request between
 *                 the two (opening a number must send one).
 *   cache         the app's API answered from LiteSpeed's cache (old lists
 *                 after a filter change).
 *   exposure      how often members are shown, against the rules and the
 *                 expected shape (Discover\Exposure).
 *
 * Results: option csm_watch_last, a line on CA Shaadi > Health, a red admin
 * notice, and an email to the site admin when anything fails. Quiet when all
 * is well. `wp csm watch` runs it now.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Watch {

	const LAST = 'csm_watch_last';
	const HOOK = 'csm_watch_daily';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// 02:40 server time: before the 03:10 regroup hides any drift.
			wp_schedule_event( strtotime( 'tomorrow 02:40' ), 'daily', self::HOOK );
		}
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm watch', function () {
				$r = Watch::run();
				foreach ( $r['checks'] as $c ) {
					\WP_CLI::log( sprintf( '%-4s %-8s %s', strtoupper( $c['ok'] ? 'ok' : 'FAIL' ), $c['key'], $c['text'] ) );
				}
			} );
		}
	}

	public static function daily() {
		$r = self::run();
		if ( ! $r['ok'] ) {
			self::email( $r );
		}
	}

	/** @return array{ok:bool,at:string,checks:array} */
	public static function run() {
		$checks = array(
			self::groups(),
			self::regroup(),
			self::unseen(),
			self::phone(),
			self::cache(),
			self::exposure(),
			self::avatars(),
		);
		/*
		 * Only what the owner must act on raises the alarm (owner, 2026-10-28:
		 * "only disturb when there is something for me to fix", and short). A
		 * failing check with a `fix` line alerts; the rest are for `wp csm watch`.
		 */
		$ok = true;
		foreach ( $checks as $c ) {
			if ( ! $c['ok'] && '' !== $c['fix'] ) {
				$ok = false;
			}
		}
		$r  = array( 'ok' => $ok, 'at' => current_time( 'mysql' ), 'checks' => $checks );
		update_option( self::LAST, $r, false );
		return $r;
	}

	/** $fix: one short line saying what to do; '' when a failure is for information only. */
	private static function row( $key, $ok, $text, $fix = '' ) {
		return array( 'key' => $key, 'ok' => (bool) $ok, 'text' => $text, 'fix' => $ok ? '' : $fix );
	}

	private static function names( array $ids ) {
		return implode( ', ', array_map( function ( $id ) {
			$u = get_userdata( (int) $id );
			return $u ? $u->user_email . ' (#' . (int) $id . ')' : '#' . (int) $id;
		}, array_slice( $ids, 0, 5 ) ) );
	}

	/** Stored groups that no longer match the answer (own choices respected). */
	private static function groups() {
		global $wpdb;
		$bad = array();
		foreach ( array_keys( Buckets::FIELDS ) as $kind ) {
			$fid = Buckets::field_id( $kind );
			if ( ! $fid ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> ''", $fid ) );
			foreach ( $rows as $r ) {
				$uid = (int) $r->user_id;
				$val = maybe_unserialize( $r->value );
				$val = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
				$set = get_user_meta( $uid, sprintf( Buckets::SET, $kind ), true );
				if ( is_array( $set ) && isset( $set['text'] ) && Buckets::norm( $set['text'] ) === Buckets::norm( $val ) ) {
					continue; // their own choice stands
				}
				$want = Buckets::of( $kind, $val );
				$have = Buckets::get( $uid, $kind );
				sort( $want );
				sort( $have );
				if ( $want !== $have ) {
					$bad[ $uid ] = $kind;
				}
			}
		}
		return self::row( 'groups', ! $bad, $bad
			? count( $bad ) . ' members have groups that do not match their answers, so filters can miss them (fixed tonight by the regroup; if this repeats, saves are not regrouping). E.g. ' . self::names( array_keys( $bad ) )
			: 'Every member\'s groups match their answers.' );
	}

	private static function regroup() {
		$last = (int) get_option( 'csm_buckets_nightly_at', 0 );
		// Never run yet is the first day, not a fault.
		$ok   = ! $last || time() - $last < 2 * DAY_IN_SECONDS;
		return self::row( 'regroup', $ok, $ok
			? ( $last ? 'Nightly regroup ran ' . human_time_diff( $last ) . ' ago.' : 'Nightly regroup has not run yet (first day).' )
			: 'Nightly regroup last ran ' . human_time_diff( $last ) . ' ago.',
			'The nightly job has not run for 2 days. Send this to Claude.' );
	}

	/*
	 * Joined 2-14 days ago, gender set, not paused or held, and never shown to
	 * anyone (csm_seen: the permanent record; tray rows are cleared weekly).
	 * Only members WITH a photo fail the check: the ranking puts a profile
	 * without one far down on purpose, so those are counted, not alarmed.
	 */
	private static function unseen() {
		global $wpdb;
		$from = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$gid  = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'Gender' ) : 0;
		if ( ! $gid ) {
			return self::row( 'unseen', true, 'Skipped: no Gender field.' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID id,
			        EXISTS ( SELECT 1 FROM {$wpdb->usermeta} ph WHERE ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ( '', 'a:0:{}' ) ) photo
			 FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value IN ( 'Male', 'Female' )
			 WHERE u.user_registered BETWEEN %s AND %s
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}csm_seen s WHERE s.profile_id = u.ID )
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id = u.ID AND m.meta_key IN ( 'csm_deactivated', 'csm_photo_hold' ) AND m.meta_value <> '' )",
			$gid, $from, $to
		) );
		$with = array();
		$without = 0;
		foreach ( (array) $rows as $r ) {
			if ( (int) $r->photo ) {
				$with[] = (int) $r->id;
			} else {
				$without++;
			}
		}
		$note = $without ? " ({$without} without a photo have not been shown either; the ranking puts those last on purpose.)" : '';
		return self::row( 'unseen', ! $with, $with
			? count( $with ) . ' members with a photo who joined 2-14 days ago have never been shown to anyone. E.g. ' . self::names( $with ) . $note
			: 'Every member with a photo who joined in the last two weeks has been shown.' . $note );
	}

	/** How often members are shown, against the rules and the expected shape (Discover\Exposure). */
	private static function exposure() {
		if ( ! class_exists( '\\CAShaadi\\Modules\\Discover\\Exposure' ) ) {
			return self::row( 'exposure', true, 'Skipped.' );
		}
		$r    = \CAShaadi\Modules\Discover\Exposure::audit( 7 );
		$bad  = array_merge(
			array_map( function ( $s ) { return 'RULE: ' . $s; }, $r['rules'] ),
			array_map( function ( $s ) { return 'SHAPE: ' . $s; }, $r['shape'] )
		);
		$stat = implode( ' ', $r['stats'] );
		// A broken rule is a bug to fix; an unusual shape is for information.
		return self::row( 'exposure', ! $bad, $bad
			? 'Showings (last 7 days): ' . implode( ' | ', $bad ) . ' — ' . $stat . ' Details: wp csm exposure; one member: wp csm exposure --who=<email>.'
			: 'Showings match the rules and look normal. ' . $stat,
			$r['rules'] ? 'Discover broke one of its own rules. Send this to Claude.' : '' );
	}

	/** Gallery photos but no main picture: shown with a default picture to everyone. */
	private static function avatars() {
		if ( ! class_exists( '\\CAShaadi\\Modules\\Photos\\AvatarRepair' ) ) {
			return self::row( 'avatars', true, 'Skipped.' );
		}
		$ids = \CAShaadi\Modules\Photos\AvatarRepair::missing();
		return self::row( 'avatars', ! $ids, $ids
			? count( $ids ) . ' members have gallery photos but no main picture, so everyone sees a default picture. Fix with: wp csm photos repair. E.g. ' . self::names( $ids )
			: 'Every member with gallery photos has a main picture.',
			count( $ids ) . ' members\' photos are not showing. Run: wp csm photos repair' );
	}

	/** Numbers opened with no request either way. */
	private static function phone() {
		global $wpdb;
		$t = $wpdb->prefix . 'csm_phone_views';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			return self::row( 'phone', true, 'No phone views yet.' );
		}
		$since = current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT v.viewer_id FROM {$t} v
			 WHERE v.viewed_at >= %s
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_friends f
			                    WHERE ( f.initiator_user_id = v.viewer_id AND f.friend_user_id = v.profile_id )
			                       OR ( f.initiator_user_id = v.profile_id AND f.friend_user_id = v.viewer_id ) )",
			wp_date( 'Y-m-d H:i:s', $since )
		) );
		return self::row( 'phone', ! $rows, $rows
			? count( $rows ) . ' phone numbers were opened in the last 7 days with no request between the two (a withdrawn or declined request also shows here). Viewers: ' . self::names( array_unique( $rows ) )
			: 'Every number opened in the last 7 days came with a request.' );
	}

	/** The app's API must never be served from LiteSpeed's cache. */
	private static function cache() {
		$url = rest_url( 'csm/v1/buckets/suggest' );
		$hit = '';
		for ( $i = 0; $i < 2; $i++ ) {
			$res = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false ) );
			if ( is_wp_error( $res ) ) {
				return self::row( 'cache', true, 'Skipped: the site could not reach itself (' . $res->get_error_message() . ').' );
			}
			$hit = strtolower( (string) wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) );
			$cc  = strtolower( (string) wp_remote_retrieve_header( $res, 'cache-control' ) );
		}
		$ok = 'hit' !== $hit && false !== strpos( $cc, 'no-store' );
		return self::row( 'cache', $ok, $ok
			? 'The app\'s API is not cached.'
			: 'The app\'s API is being cached (LiteSpeed: ' . ( $hit ?: 'none' ) . ', Cache-Control: ' . ( $cc ?: 'none' ) . '). Members may see old lists until they reload.',
			'Members may see out-of-date lists. Send this to Claude.' );
	}

	private static function fixes( array $r ) {
		$out = array();
		foreach ( (array) $r['checks'] as $c ) {
			if ( empty( $c['ok'] ) && ! empty( $c['fix'] ) ) {
				$out[] = $c['fix'];
			}
		}
		return $out;
	}

	/** Short: one line per thing to do. Details stay in `wp csm watch`. */
	private static function email( array $r ) {
		$fix = self::fixes( $r );
		if ( ! $fix ) {
			return;
		}
		wp_mail(
			get_option( 'admin_email' ),
			'CA Shaadi: ' . count( $fix ) . ( 1 === count( $fix ) ? ' thing' : ' things' ) . ' to fix',
			'• ' . implode( "\n• ", $fix ) . "\n\n(Details: wp csm watch)"
		);
	}

	/** In wp-admin, only while there is something to do. */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$r = get_option( self::LAST );
		if ( ! is_array( $r ) || ! empty( $r['ok'] ) ) {
			return;
		}
		$fix = self::fixes( $r );
		if ( ! $fix ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>CA Shaadi: to fix</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $fix as $f ) {
			echo '<li>' . esc_html( $f ) . '</li>';
		}
		echo '</ul></div>';
	}
}
