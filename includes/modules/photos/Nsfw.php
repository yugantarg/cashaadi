<?php
/**
 * Photo Moderation (NSFW) — migrated from WPCode #12119.
 *
 * Background cron sweep moderates profile avatars + member-uploaded images via
 * OpenAI's omni-moderation endpoint (same key as CA Verify, read through Secrets).
 * Enforcement (hiding photos + emailing) is OFF until option csm_pm_enforce is set;
 * while OFF the sweep still records verdicts so the review queue fills. Hiding is
 * reversible (avatars masked to default via a display filter, media set private).
 *
 * The mask filter runs at priority 21 — AFTER Privacy's blur (20) — so an
 * NSFW-hidden photo is masked to the default even over a blur or an approved
 * photo-request reveal (NSFW is absolute). Reuses the same meta/option keys as the
 * snippet, so existing verdicts + the cron schedule carry over.
 *
 * Registered only when Config::photos_enabled().
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nsfw {

	const CRON = 'csm_pm_sweep_event';

	public static function register() {
		add_filter( 'bp_core_fetch_avatar_url', array( __CLASS__, 'mask_avatar_url' ), 21, 2 );
		add_action( self::CRON, array( __CLASS__, 'sweep' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_tools' ) );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/* ---- config -------------------------------------------------------- */

	private static function batch() {
		return (int) apply_filters( 'csm_pm_batch', 12 );
	}
	private static function threshold() {
		return (float) apply_filters( 'csm_pm_threshold', 0.6 );
	}
	private static function enforce() {
		return (bool) get_option( 'csm_pm_enforce' );
	}

	/* ---- moderation ---------------------------------------------------- */

	private static function moderate( $image_url ) {
		$key = Secrets::openai_api_key();
		if ( '' === $key ) {
			return array( 'ok' => false, 'error' => 'no_api_key' );
		}
		if ( empty( $image_url ) ) {
			return array( 'ok' => false, 'error' => 'no_url' );
		}
		$resp = wp_remote_post( 'https://api.openai.com/v1/moderations', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model' => 'omni-moderation-latest',
				'input' => array(
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url ) ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== (int) $code || ! is_array( $data ) || empty( $data['results'][0] ) ) {
			$msg = ( is_array( $data ) && isset( $data['error']['message'] ) ) ? $data['error']['message'] : ( 'http_' . $code );
			return array( 'ok' => false, 'error' => $msg );
		}
		return array( 'ok' => true, 'result' => $data['results'][0] );
	}

	private static function decide( $r ) {
		$cats   = isset( $r['categories'] ) && is_array( $r['categories'] ) ? $r['categories'] : array();
		$scores = isset( $r['category_scores'] ) && is_array( $r['category_scores'] ) ? $r['category_scores'] : array();
		$sexual = isset( $scores['sexual'] ) ? (float) $scores['sexual'] : 0.0;
		$minorS = isset( $scores['sexual/minors'] ) ? (float) $scores['sexual/minors'] : 0.0;
		$minor  = ( ! empty( $cats['sexual/minors'] ) || $minorS >= 0.2 );
		$flag   = ( ! empty( $cats['sexual'] ) || ! empty( $cats['sexual/minors'] ) || $sexual >= self::threshold() || $minor );
		$reason = $minor ? 'sexual/minors' : ( $flag ? 'sexual' : 'clean' );
		return array( 'flagged' => $flag, 'reason' => $reason, 'minor' => $minor, 'score' => round( max( $sexual, $minorS ), 3 ) );
	}

	/* ---- avatars ------------------------------------------------------- */

	private static function avatar_url( $uid ) {
		if ( ! function_exists( 'bp_get_user_has_avatar' ) || ! bp_get_user_has_avatar( $uid ) ) {
			return '';
		}
		return bp_core_fetch_avatar( array( 'item_id' => $uid, 'object' => 'user', 'type' => 'full', 'html' => false ) );
	}

	private static function pending_avatar_ids( $limit ) {
		$q = new \WP_User_Query( array(
			'number'     => $limit * 4,
			'orderby'    => 'ID',
			'fields'     => 'ID',
			'meta_query' => array( array( 'key' => 'csm_pm_av_checked', 'compare' => 'NOT EXISTS' ) ),
		) );
		$out = array();
		foreach ( (array) $q->get_results() as $uid ) {
			$uid = (int) $uid;
			if ( user_can( $uid, 'manage_options' ) ) {
				update_user_meta( $uid, 'csm_pm_av_checked', 1 );
				continue;
			}
			if ( '' === self::avatar_url( $uid ) ) {
				update_user_meta( $uid, 'csm_pm_av_checked', 1 );
				continue;
			}
			$out[] = $uid;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	private static function process_avatar( $uid, $dry ) {
		$url = self::avatar_url( $uid );
		if ( '' === $url ) {
			return 'no_avatar';
		}
		$m = self::moderate( $url );
		if ( empty( $m['ok'] ) ) {
			if ( ! $dry ) {
				update_user_meta( $uid, 'csm_pm_av_error', isset( $m['error'] ) ? $m['error'] : 'err' );
			}
			return 'error';
		}
		$d = self::decide( $m['result'] );
		if ( $dry ) {
			return 'dry:' . $d['reason'] . ':' . $d['score'];
		}
		update_user_meta( $uid, 'csm_pm_av_checked', 1 );
		update_user_meta( $uid, 'csm_pm_av_time', time() );
		update_user_meta( $uid, 'csm_pm_av_reason', $d['reason'] );
		update_user_meta( $uid, 'csm_pm_av_score', $d['score'] );

		if ( $d['flagged'] ) {
			update_user_meta( $uid, 'csm_pm_av_status', 'flagged' );
			update_user_meta( $uid, 'csm_pm_av_url', $url );
			if ( $d['minor'] ) {
				update_user_meta( $uid, 'csm_pm_av_minor', 1 );
			}
			if ( self::enforce() ) {
				update_user_meta( $uid, 'csm_pm_av_hidden', 1 );
				self::notify( $uid, 'profile photo' );
			}
			return $d['minor'] ? 'flagged_minor' : 'flagged';
		}
		return 'clean';
	}

	/** Display filter: mask a hidden avatar to the local default. Priority 21. */
	public static function mask_avatar_url( $url, $params = array() ) {
		$uid = 0;
		if ( is_array( $params ) && ! empty( $params['item_id'] ) && ( empty( $params['object'] ) || 'user' === $params['object'] ) ) {
			$uid = (int) $params['item_id'];
		}
		if ( $uid && get_user_meta( $uid, 'csm_pm_av_hidden', true ) ) {
			if ( function_exists( 'bp_core_avatar_default' ) ) {
				return bp_core_avatar_default( 'local' );
			}
		}
		return $url;
	}

	/* ---- member media -------------------------------------------------- */

	private static function pending_media_ids( $limit ) {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit * 3,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_csm_pm_checked', 'compare' => 'NOT EXISTS' ) ),
		) );
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$pid    = (int) $pid;
			$author = (int) get_post_field( 'post_author', $pid );
			if ( ! $author || user_can( $author, 'manage_options' ) ) {
				update_post_meta( $pid, '_csm_pm_checked', 1 );
				continue;
			}
			$out[] = $pid;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	private static function process_media( $pid, $dry ) {
		$url = wp_get_attachment_url( $pid );
		if ( ! $url ) {
			return 'no_url';
		}
		$m = self::moderate( $url );
		if ( empty( $m['ok'] ) ) {
			if ( ! $dry ) {
				update_post_meta( $pid, '_csm_pm_error', isset( $m['error'] ) ? $m['error'] : 'err' );
			}
			return 'error';
		}
		$d = self::decide( $m['result'] );
		if ( $dry ) {
			return 'dry:' . $d['reason'] . ':' . $d['score'];
		}
		update_post_meta( $pid, '_csm_pm_checked', 1 );
		update_post_meta( $pid, '_csm_pm_time', time() );
		update_post_meta( $pid, '_csm_pm_reason', $d['reason'] );
		update_post_meta( $pid, '_csm_pm_score', $d['score'] );

		if ( $d['flagged'] ) {
			update_post_meta( $pid, '_csm_pm_status', 'flagged' );
			if ( $d['minor'] ) {
				update_post_meta( $pid, '_csm_pm_minor', 1 );
			}
			if ( self::enforce() ) {
				update_post_meta( $pid, '_csm_pm_prev_status', get_post_status( $pid ) );
				wp_update_post( array( 'ID' => $pid, 'post_status' => 'private' ) );
				self::notify( (int) get_post_field( 'post_author', $pid ), 'uploaded photo' );
			}
			return $d['minor'] ? 'flagged_minor' : 'flagged';
		}
		return 'clean';
	}

	/* ---- notify -------------------------------------------------------- */

	private static function notify( $uid, $what ) {
		$user = get_userdata( $uid );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$name = $user->display_name ? $user->display_name : $user->user_login;
		$body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.55">'
			. '<p>Hi ' . esc_html( $name ) . ',</p>'
			. '<p>Your ' . esc_html( $what ) . ' on <strong>CA Shaadi</strong> was hidden because our automated check found it may not meet our photo guidelines. '
			. 'CA Shaadi is a matrimonial community and profile photos need to be clear, appropriate pictures of you.</p>'
			. '<p>If you believe this was a mistake, please reply to this email or contact support and we will review it. '
			. 'You can also upload a different photo from your profile.</p>'
			. '<p style="color:#888;font-size:12px;margin-top:24px">This is an automated message from CA Shaadi.</p></div>';
		wp_mail( $user->user_email, 'About your photo on CA Shaadi', $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ---- sweep + schedule ---------------------------------------------- */

	public static function sweep() {
		$n  = self::batch();
		$av = self::pending_avatar_ids( $n );
		foreach ( $av as $uid ) {
			self::process_avatar( $uid, false );
		}
		$left = $n - count( $av );
		if ( $left > 0 ) {
			foreach ( self::pending_media_ids( $left ) as $pid ) {
				self::process_media( $pid, false );
			}
		}
		$remaining = count( self::pending_avatar_ids( 1 ) ) + count( self::pending_media_ids( 1 ) );
		self::reschedule( $remaining > 0 ? 'hourly' : 'daily' );
	}

	private static function reschedule( $rec ) {
		if ( 'hourly' !== $rec && 'daily' !== $rec ) {
			$rec = 'daily';
		}
		if ( wp_get_schedule( self::CRON ) === $rec ) {
			return;
		}
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
		wp_schedule_event( time() + 300, $rec, self::CRON );
	}

	/* ---- admin queue --------------------------------------------------- */

	public static function admin_menu() {
		add_menu_page( 'Photo Moderation', 'Photo Moderation', 'manage_options', 'csm-photo-mod', array( __CLASS__, 'page' ), 'dashicons-visibility', 58 );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Photo Moderation</h1>';
		echo '<p>Enforcement is <strong>' . ( self::enforce() ? 'ON (flagged photos are hidden)' : 'OFF (detection only, nothing hidden yet)' ) . '</strong>. ';
		$toggle = wp_nonce_url( admin_url( 'admin.php?page=csm-photo-mod&csm_pm_toggle=1' ), 'csm_pm' );
		echo '<a class="button" href="' . esc_url( $toggle ) . '">' . ( self::enforce() ? 'Turn enforcement OFF' : 'Turn enforcement ON' ) . '</a></p>';

		$users = get_users( array( 'meta_key' => 'csm_pm_av_status', 'meta_value' => 'flagged', 'fields' => array( 'ID', 'display_name' ) ) );
		echo '<h2>Flagged profile photos (' . count( $users ) . ')</h2><table class="widefat striped"><thead><tr><th>Member</th><th>Reason</th><th>Score</th><th>Photo</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$reason = get_user_meta( $u->ID, 'csm_pm_av_reason', true );
			$minor  = get_user_meta( $u->ID, 'csm_pm_av_minor', true );
			$score  = get_user_meta( $u->ID, 'csm_pm_av_score', true );
			$hidden = get_user_meta( $u->ID, 'csm_pm_av_hidden', true );
			$raw    = get_user_meta( $u->ID, 'csm_pm_av_url', true );
			if ( ! $raw ) {
				$raw = self::avatar_url( $u->ID );
			}
			$base = 'admin.php?page=csm-photo-mod&uid=' . (int) $u->ID;
			echo '<tr><td>' . esc_html( $u->display_name ) . ' (#' . (int) $u->ID . ')</td>';
			echo '<td>' . esc_html( $reason ) . ( $minor ? ' <strong style="color:#b00">[MINORS]</strong>' : '' ) . '</td>';
			echo '<td>' . esc_html( $score ) . '</td>';
			echo '<td><a href="' . esc_url( $raw ) . '" target="_blank" rel="noopener">open photo</a> ' . ( $hidden ? '(hidden)' : '(visible)' ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( wp_nonce_url( admin_url( $base . '&csm_pm_restore=1' ), 'csm_pm' ) ) . '">Restore (not explicit)</a> ';
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( $base . '&csm_pm_confirm=1' ), 'csm_pm' ) ) . '">Confirm remove</a></td></tr>';
		}
		echo '</tbody></table>';

		$posts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 200, 'meta_key' => '_csm_pm_status', 'meta_value' => 'flagged', 'fields' => 'ids' ) );
		echo '<h2>Flagged uploaded media (' . count( $posts ) . ')</h2><table class="widefat striped"><thead><tr><th>Attachment</th><th>Owner</th><th>Reason</th><th>Score</th><th>Photo</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $posts as $pid ) {
			$author = (int) get_post_field( 'post_author', $pid );
			$ud     = get_userdata( $author );
			$reason = get_post_meta( $pid, '_csm_pm_reason', true );
			$minor  = get_post_meta( $pid, '_csm_pm_minor', true );
			$score  = get_post_meta( $pid, '_csm_pm_score', true );
			$base   = 'admin.php?page=csm-photo-mod&pid=' . (int) $pid;
			echo '<tr><td>#' . (int) $pid . '</td><td>' . esc_html( $ud ? $ud->display_name : $author ) . '</td>';
			echo '<td>' . esc_html( $reason ) . ( $minor ? ' <strong style="color:#b00">[MINORS]</strong>' : '' ) . '</td>';
			echo '<td>' . esc_html( $score ) . '</td>';
			echo '<td><a href="' . esc_url( wp_get_attachment_url( $pid ) ) . '" target="_blank" rel="noopener">open photo</a></td>';
			echo '<td><a class="button" href="' . esc_url( wp_nonce_url( admin_url( $base . '&csm_pm_restore=1' ), 'csm_pm' ) ) . '">Restore</a> ';
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( $base . '&csm_pm_confirm=1' ), 'csm_pm' ) ) . '">Confirm remove</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function enforce_existing() {
		$users = get_users( array( 'meta_key' => 'csm_pm_av_status', 'meta_value' => 'flagged', 'fields' => 'ID' ) );
		foreach ( (array) $users as $uid ) {
			$uid = (int) $uid;
			if ( ! get_user_meta( $uid, 'csm_pm_av_hidden', true ) ) {
				update_user_meta( $uid, 'csm_pm_av_hidden', 1 );
				self::notify( $uid, 'profile photo' );
			}
		}
		$posts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 500, 'meta_key' => '_csm_pm_status', 'meta_value' => 'flagged', 'fields' => 'ids' ) );
		foreach ( (array) $posts as $pid ) {
			$pid = (int) $pid;
			if ( 'private' !== get_post_status( $pid ) ) {
				update_post_meta( $pid, '_csm_pm_prev_status', get_post_status( $pid ) );
				wp_update_post( array( 'ID' => $pid, 'post_status' => 'private' ) );
				self::notify( (int) get_post_field( 'post_author', $pid ), 'uploaded photo' );
			}
		}
	}

	public static function admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_GET['page'] ) || 'csm-photo-mod' !== $_GET['page'] ) {
			return;
		}
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'csm_pm' ) ) {
			return;
		}
		if ( ! empty( $_GET['csm_pm_toggle'] ) ) {
			$now = self::enforce() ? 0 : 1;
			update_option( 'csm_pm_enforce', $now );
			if ( $now ) {
				self::enforce_existing();
			}
			wp_safe_redirect( admin_url( 'admin.php?page=csm-photo-mod' ) );
			exit;
		}
		$uid = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
		$pid = isset( $_GET['pid'] ) ? (int) $_GET['pid'] : 0;

		if ( $uid ) {
			if ( ! empty( $_GET['csm_pm_restore'] ) ) {
				delete_user_meta( $uid, 'csm_pm_av_status' );
				delete_user_meta( $uid, 'csm_pm_av_hidden' );
				delete_user_meta( $uid, 'csm_pm_av_minor' );
			} elseif ( ! empty( $_GET['csm_pm_confirm'] ) ) {
				if ( function_exists( 'bp_core_delete_existing_avatar' ) ) {
					bp_core_delete_existing_avatar( array( 'item_id' => $uid, 'object' => 'user' ) );
				}
				update_user_meta( $uid, 'csm_pm_av_status', 'removed' );
				delete_user_meta( $uid, 'csm_pm_av_hidden' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=csm-photo-mod' ) );
			exit;
		}
		if ( $pid ) {
			if ( ! empty( $_GET['csm_pm_restore'] ) ) {
				$prev = get_post_meta( $pid, '_csm_pm_prev_status', true );
				wp_update_post( array( 'ID' => $pid, 'post_status' => $prev ? $prev : 'inherit' ) );
				delete_post_meta( $pid, '_csm_pm_status' );
				delete_post_meta( $pid, '_csm_pm_minor' );
			} elseif ( ! empty( $_GET['csm_pm_confirm'] ) ) {
				wp_update_post( array( 'ID' => $pid, 'post_status' => 'private' ) );
				update_post_meta( $pid, '_csm_pm_status', 'removed' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=csm-photo-mod' ) );
			exit;
		}
	}

	public static function admin_tools() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_GET['csm_pm_dryrun'] ) ) {
			$lines = array();
			foreach ( self::pending_avatar_ids( 2 ) as $uid ) {
				$lines[] = 'avatar#' . $uid . ' => ' . self::process_avatar( $uid, true );
			}
			foreach ( self::pending_media_ids( 2 ) as $pid ) {
				$lines[] = 'media#' . $pid . ' => ' . self::process_media( $pid, true );
			}
			wp_die( 'csm_pm dryrun (no writes): ' . esc_html( empty( $lines ) ? 'nothing pending' : implode( ' | ', $lines ) ) );
		}
		if ( ! empty( $_GET['csm_pm_runnow'] ) ) {
			self::sweep();
			wp_die( 'csm_pm sweep done. ' . esc_html( 'enforce=' . ( self::enforce() ? 'on' : 'off' ) ) );
		}
		/*
		 * TEMPORARY (v0.71.3) — threshold test harness. Remove after verification.
		 *
		 * The flag branch has never been exercised, and the responsible way to do
		 * that is to move the THRESHOLD, not to obtain explicit material: a benign
		 * photo scored against a threshold of ~0 takes the identical path through
		 * decide(), the meta writes, the review queue, enforcement and the
		 * exclusion in Core\Profile::photos(). Testing sexual/minors with real
		 * content is illegal and this is the only legitimate way to cover it.
		 *
		 * Targets one attachment id directly, deliberately bypassing
		 * pending_media_ids() so the test can run against an ADMIN-owned photo and
		 * never writes a moderation verdict onto a real member's uploads.
		 *
		 *   ?csm_pm_test=<attachment id>&t=<threshold>[&write=1]
		 */
		if ( ! empty( $_GET['csm_pm_test'] ) ) {
			$pid = (int) $_GET['csm_pm_test'];
			$t   = isset( $_GET['t'] ) ? (float) $_GET['t'] : 0.0001;
			$fn  = function () use ( $t ) { return $t; };
			add_filter( 'csm_pm_threshold', $fn );
			$res = self::process_media( $pid, empty( $_GET['write'] ) );
			remove_filter( 'csm_pm_threshold', $fn );
			wp_die( 'csm_pm test: pid=' . (int) $pid . ' threshold=' . esc_html( (string) $t )
				. ' mode=' . ( empty( $_GET['write'] ) ? 'dry' : 'WRITE' )
				. ' result=' . esc_html( (string) $res )
				. ' status_meta=' . esc_html( (string) get_post_meta( $pid, '_csm_pm_status', true ) )
				. ' post_status=' . esc_html( (string) get_post_status( $pid ) )
				. ' enforce=' . ( self::enforce() ? 'on' : 'off' ) );
		}
		if ( ! empty( $_GET['csm_pm_status'] ) ) {
			$sched = wp_get_schedule( self::CRON );
			$next  = wp_next_scheduled( self::CRON );
			wp_die( 'csm_pm status: pending_avatars=' . count( self::pending_avatar_ids( 100000 ) )
				. ' pending_media=' . count( self::pending_media_ids( 100000 ) )
				. ' enforce=' . ( self::enforce() ? 'on' : 'off' )
				. ' key=' . ( '' === Secrets::openai_api_key() ? 'MISSING' : 'set' )
				. ' schedule=' . ( $sched ? $sched : 'none' )
				. ' next=' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'n/a' ) );
		}
	}
}
