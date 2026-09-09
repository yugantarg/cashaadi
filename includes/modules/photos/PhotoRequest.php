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

		// The app screens are REST, not admin-ajax. Same table, same rules.
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/photo-request', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_request' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** Ask this member for a photo. Same gate as the ajax path. */
	public static function rest_request( $request ) {
		$viewer_id = get_current_user_id();
		$owner_id  = absint( $request->get_param( 'owner' ) );

		$kind = self::kind( $viewer_id, $owner_id );
		if ( '' === $kind || ! self::can_request( $viewer_id, $owner_id ) ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => __( 'That request cannot be sent.', 'cashaadi-ui' ),
			), 200 );
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

		self::notify_owner( $owner_id, $viewer_id, $kind );

		return new \WP_REST_Response( array(
			'ok'      => true,
			'state'   => 'pending',
			'message' => ( 'upload' === $kind )
				? __( 'Asked — we have let them know.', 'cashaadi-ui' )
				: __( 'Photo request sent.', 'cashaadi-ui' ),
		), 200 );
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

	/**
	 * What a request would be asking FOR, or '' if it cannot be made.
	 *
	 * Two different asks share this table, and the difference matters to
	 * everything downstream:
	 *
	 *   'reveal' — the owner HAS a photo and it is blurred. Approving shows it
	 *              to this one viewer. The original behaviour.
	 *   'upload' — the owner has NO photo. There is nothing to approve; the
	 *              request is a note asking them to add one, and it resolves
	 *              when they do (see resolve_on_upload()).
	 *
	 * The second exists because a photo is no longer mandatory (owner,
	 * 2026-09-09), so profiles without one are now a normal state rather than
	 * an unfinished signup.
	 */
	public static function kind( $viewer_id, $owner_id ) {
		$viewer_id = (int) $viewer_id;
		$owner_id  = (int) $owner_id;
		if ( ! $viewer_id || ! $owner_id || $viewer_id === $owner_id ) {
			return '';
		}
		if ( ! get_userdata( $owner_id ) ) {
			return '';
		}
		// A block in either direction hides the member entirely; do not offer to
		// contact them through a photo request.
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $viewer_id, $owner_id ) ) {
			return '';
		}

		$has_photo = class_exists( '\CAShaadi\Modules\Onboarding\PhotoOptions' )
			&& \CAShaadi\Modules\Onboarding\PhotoOptions::has_photo( $owner_id );

		if ( ! $has_photo ) {
			return 'upload';
		}
		if ( Privacy::is_hidden( $owner_id, $viewer_id ) ) {
			return 'reveal';
		}
		return '';   // they have a photo and this viewer can already see it
	}

	private static function can_request( $viewer_id, $owner_id ) {
		if ( '' === self::kind( $viewer_id, $owner_id ) ) {
			return false;
		}
		$status = self::status( (int) $viewer_id, (int) $owner_id );
		if ( 'pending' === $status || 'approved' === $status ) {
			return false;
		}
		return true;
	}

	/**
	 * The state the UI should show: 'can', 'pending', or ''.
	 *
	 * One call, so the app screen and the BuddyPress header cannot disagree
	 * about whether a button belongs on the page.
	 */
	public static function ui_state( $viewer_id, $owner_id ) {
		if ( self::can_request( $viewer_id, $owner_id ) ) {
			return 'can';
		}
		if ( 'pending' === self::status( (int) $viewer_id, (int) $owner_id ) ) {
			return 'pending';
		}
		return '';
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

		$state = self::ui_state( $viewer_id, $owner_id );

		if ( 'can' === $state ) {
			// "Ask for a photo" when there is none, "Request photo" when it is
			// merely blurred — the two are different asks and the button should
			// not claim otherwise.
			$label = ( 'upload' === self::kind( $viewer_id, $owner_id ) )
				? __( 'Ask for a photo', 'cashaadi-ui' )
				: __( 'Request photo', 'cashaadi-ui' );
			echo '<div class="csm-pr-wrap"><button type="button" class="csm-pr-btn" data-owner="' . esc_attr( $owner_id ) . '">' . esc_html( $label ) . '</button> <span class="csm-pr-msg"></span></div>';
		} elseif ( 'pending' === $state ) {
			echo '<div class="csm-pr-wrap"><span class="csm-pr-pending">' . esc_html__( 'Asked', 'cashaadi-ui' ) . '</span></div>';
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
		self::notify_owner( $owner_id, $viewer_id, self::kind( $viewer_id, $owner_id ) );
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

	private static function notify_owner( $owner_id, $viewer_id, $kind = 'reveal' ) {
		$owner = get_userdata( $owner_id );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$viewer_name = bp_core_get_user_displayname( $viewer_id );
		$site        = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		/*
		 * The name is withheld on purpose.
		 *
		 * "Somebody" rather than "Priya asked you for a photo": naming the
		 * requester in an inbox tells the owner who is interested before they
		 * have decided anything, and on a matrimony site that is a disclosure,
		 * not a courtesy. Who asked is visible on the site, to a member who is
		 * logged in.
		 */
		if ( 'upload' === $kind ) {
			$subject = 'Someone asked to see your photo on ' . $site;
			$html    = '<p>Somebody looking at your profile on ' . esc_html( $site )
				. ' would like to see a photo — you have not added one yet.</p>'
				. '<p>Profiles with a photo get far more responses. Adding one takes a few seconds from your phone.</p>';
			$cta     = 'Add a photo';
			$url     = home_url( '/profile/edit/?g=10' );
		} else {
			$subject = 'New photo request on ' . $site;
			$html    = '<p>Somebody has asked to see your photo on ' . esc_html( $site ) . '.</p>'
				. '<p>Your photo is blurred for members you have not matched with. You can approve or decline this request.</p>';
			$cta     = 'View the request';
			$url     = home_url( '/profile/' );
		}
		unset( $viewer_name );

		$body = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">'
			. $html
			. '<p style="margin:26px 0"><a href="' . esc_url( $url )
			. '" style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">'
			. esc_html( $cta ) . '</a></p>'
			. '<p style="color:#7a6f68;font-size:13px">You can turn these emails off in Settings → Email notifications.</p>'
			. '</div>';

		// Queued so the master switch, the caps and the quiet window all govern
		// it. The type carries the requester, so a repeated request from the
		// same person cannot mail the owner twice.
		if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Queue' ) ) {
			\CAShaadi\Modules\Emails\Queue::notify( $owner_id, 'csm-photo-req-' . (int) $viewer_id, $subject, $body );
			return;
		}
		wp_mail( $owner->user_email, $subject, $body );
	}

	/**
	 * A member added a photo: every 'upload' request against them is answered.
	 *
	 * Without this the requests would sit pending forever and the asker would
	 * never be told, because there is no approval step for an upload request —
	 * the upload IS the answer.
	 */
	public static function resolve_on_upload( $owner_id ) {
		$owner_id = (int) $owner_id;
		if ( ! $owner_id ) {
			return;
		}
		global $wpdb;
		$t = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$waiting = $wpdb->get_col( $wpdb->prepare( "SELECT requester_id FROM {$t} WHERE owner_id = %d AND status = 'pending'", $owner_id ) );
		if ( ! $waiting ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'approved', acted_at = %s WHERE owner_id = %d AND status = 'pending'", current_time( 'mysql' ), $owner_id ) );

		foreach ( $waiting as $rid ) {
			self::notify_requester( (int) $rid, $owner_id );
		}
	}

	private static function notify_requester( $requester_id, $owner_id ) {
		$req = get_userdata( $requester_id );
		if ( ! $req || ! is_email( $req->user_email ) ) {
			return;
		}
		$owner_name = bp_core_get_user_displayname( $owner_id );
		$subject    = 'Your photo request was approved';
		$body       = $owner_name . ' approved your photo request. You can now view their photo on CAShaadi.';

		if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Queue' ) ) {
			\CAShaadi\Modules\Emails\Queue::notify( $requester_id, 'csm-photo-ok-' . (int) $owner_id, $subject, $body );
			return;
		}
		wp_mail( $req->user_email, $subject, $body );
	}
}
