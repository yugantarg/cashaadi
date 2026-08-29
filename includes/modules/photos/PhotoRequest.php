<?php
/**
 * Photo Request — ask & approve (migrated from WPCode #11798).
 *
 * A viewer who cannot see a hidden photo can ASK the owner; the owner approves
 * or denies from an inbox; an approved request reveals that photo for that one
 * viewer only. Rather than the snippet's separate priority-999 "un-blur" filter,
 * the reveal composes into the Privacy decision: this hooks the
 * csm_photo_is_hidden filter and returns "not hidden" for an approved requester,
 * so Privacy simply never blurs for them (one decision point, not three stacked
 * filters). Owns the wp_csm_photo_requests table via the Migrator.
 *
 * Registered only when Config::photos_enabled().
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoRequest {

	public static function register() {
		Migrator::register( 'photo_requests', array( __CLASS__, 'schema' ) );

		// Compose the reveal into Privacy's decision.
		add_filter( 'csm_photo_is_hidden', array( __CLASS__, 'reveal_for_approved' ), 10, 3 );

		add_action( 'bp_member_header_actions', array( __CLASS__, 'header_button' ) );
		add_action( 'wp_ajax_csm_pr_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_csm_pr_act', array( __CLASS__, 'ajax_act' ) );
		add_shortcode( 'csm_photo_requests', array( __CLASS__, 'inbox_shortcode' ) );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_photo_requests';
	}

	public static function schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_photo_requests';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			requester_id BIGINT UNSIGNED NOT NULL,
			owner_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			acted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY req_owner (requester_id, owner_id),
			KEY owner_status (owner_id, status)
		) {$charset};";
	}

	/* ---- state --------------------------------------------------------- */

	private static function status( $requester_id, $owner_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$t} WHERE requester_id = %d AND owner_id = %d",
			$requester_id,
			$owner_id
		) );
	}

	public static function has_approved( $viewer_id, $owner_id ) {
		if ( ! $viewer_id || ! $owner_id ) {
			return false;
		}
		return 'approved' === self::status( $viewer_id, $owner_id );
	}

	private static function can_request( $viewer_id, $owner_id ) {
		$viewer_id = (int) $viewer_id;
		$owner_id  = (int) $owner_id;
		if ( ! $viewer_id || ! $owner_id || $viewer_id === $owner_id ) {
			return false;
		}
		if ( ! Privacy::is_hidden( $owner_id, $viewer_id ) ) {
			return false; // nothing hidden to reveal
		}
		$status = self::status( $viewer_id, $owner_id );
		if ( 'pending' === $status || 'approved' === $status ) {
			return false;
		}
		return true;
	}

	/** Compose: an approved requester is never hidden. */
	public static function reveal_for_approved( $hidden, $owner_id, $viewer_id ) {
		if ( $hidden && self::has_approved( (int) $viewer_id, (int) $owner_id ) ) {
			return false;
		}
		return $hidden;
	}

	/* ---- profile button ------------------------------------------------ */

	public static function header_button() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		$owner_id  = (int) bp_displayed_user_id();
		$viewer_id = (int) get_current_user_id();

		if ( self::can_request( $viewer_id, $owner_id ) ) {
			echo '<div class="csm-pr-wrap"><button type="button" class="csm-pr-btn" data-owner="' . esc_attr( $owner_id ) . '">Request Photo</button> <span class="csm-pr-msg"></span></div>';
		} elseif ( 'pending' === self::status( $viewer_id, $owner_id ) ) {
			echo '<div class="csm-pr-wrap"><span class="csm-pr-pending">Photo request sent</span></div>';
		}
	}

	/* ---- ajax ---------------------------------------------------------- */

	public static function ajax_submit() {
		check_ajax_referer( 'csm_pr_nonce', 'nonce' );
		$viewer_id = (int) get_current_user_id();
		$owner_id  = isset( $_POST['owner'] ) ? (int) $_POST['owner'] : 0;

		if ( ! self::can_request( $viewer_id, $owner_id ) ) {
			wp_send_json_error( array( 'message' => 'Cannot request.' ) );
		}
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (requester_id, owner_id, status, created_at)
			 VALUES (%d, %d, 'pending', %s)
			 ON DUPLICATE KEY UPDATE status = 'pending', created_at = %s, acted_at = NULL",
			$viewer_id,
			$owner_id,
			$now,
			$now
		) );
		self::notify_owner( $owner_id, $viewer_id );
		wp_send_json_success( array( 'message' => 'Photo request sent' ) );
	}

	public static function ajax_act() {
		check_ajax_referer( 'csm_pr_nonce', 'nonce' );
		$owner_id     = (int) get_current_user_id();
		$requester_id = isset( $_POST['requester'] ) ? (int) $_POST['requester'] : 0;
		$decision     = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( ! $owner_id || ! $requester_id || ! in_array( $decision, array( 'approve', 'deny' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request.' ) );
		}
		$status = ( 'approve' === $decision ) ? 'approved' : 'denied';

		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => $status, 'acted_at' => current_time( 'mysql' ) ),
			array( 'owner_id' => $owner_id, 'requester_id' => $requester_id ),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => 'Update failed.' ) );
		}
		if ( 'approved' === $status ) {
			self::notify_requester( $requester_id, $owner_id );
		}
		$label = ( 'approved' === $status ) ? 'Approved' : 'Denied';
		wp_send_json_success( array( 'message' => $label, 'status' => $status ) );
	}

	/* ---- owner inbox --------------------------------------------------- */

	public static function inbox_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to view your photo requests.</p>';
		}
		$owner_id = (int) get_current_user_id();

		global $wpdb;
		$t    = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT requester_id, created_at FROM {$t} WHERE owner_id = %d AND status = 'pending' ORDER BY created_at DESC",
			$owner_id
		) );

		$h = '<div class="csm-pr-inbox">';
		if ( empty( $rows ) ) {
			$h .= '<p>No pending photo requests.</p>';
		} else {
			foreach ( $rows as $row ) {
				$rid  = (int) $row->requester_id;
				$name = bp_core_get_user_displayname( $rid );
				$link = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $rid ) : '';
				$av   = get_avatar( $rid, 64 );
				$h   .= '<div class="csm-pr-item" data-requester="' . esc_attr( $rid ) . '">';
				$h   .= '<span class="csm-pr-av">' . $av . '</span>';
				$h   .= '<a class="csm-pr-name" href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
				$h   .= '<button type="button" class="csm-pr-approve">Approve</button>';
				$h   .= '<button type="button" class="csm-pr-deny">Deny</button>';
				$h   .= '</div>';
			}
		}
		return $h . '</div>';
	}

	/* ---- notifications ------------------------------------------------- */

	private static function notify_owner( $owner_id, $viewer_id ) {
		$owner = get_userdata( $owner_id );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$viewer_name = bp_core_get_user_displayname( $viewer_id );
		wp_mail(
			$owner->user_email,
			'New photo request on CAShaadi',
			$viewer_name . ' has requested to view your photo. Log in to CAShaadi to approve or deny the request.'
		);
	}

	private static function notify_requester( $requester_id, $owner_id ) {
		$req = get_userdata( $requester_id );
		if ( ! $req || ! is_email( $req->user_email ) ) {
			return;
		}
		$owner_name = bp_core_get_user_displayname( $owner_id );
		wp_mail(
			$req->user_email,
			'Your photo request was approved',
			$owner_name . ' approved your photo request. You can now view their photo on CAShaadi.'
		);
	}
}
