<?php
/**
 * Photo hold (owner, 2026-10-12): "a temporary hold on profiles that look
 * fake — any non human photos, or male photo on female profile. They should
 * get an email to fix it, and their profile isn't shown to others in the
 * interim."
 *
 * An admin puts a member on hold from the Sales Dashboard or the member's
 * profile page, with a reason. While held:
 *   - not offered in Discover (and pulled from unacted trays they are in now),
 *   - not in the members directory,
 *   - the member sees a banner asking for a real photo of themselves.
 * Existing matches and conversations stay, as with a pause.
 *
 * The member gets one email when held. When they upload a new photo the hold
 * is marked "new photo — review" at the top of the dashboard; it stays on
 * until an admin releases it, because a fake profile would just upload
 * another fake. Release sends a short "you're visible again" email.
 *
 * One user meta, csm_photo_hold: array( at, reason, by, fixed_at ).
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Modules\Emails\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoHold {

	const META = 'csm_photo_hold';

	const REASONS = array(
		'nonhuman' => 'Photo is not of a person',
		'gender'   => 'Photo does not match the profile\'s gender',
		'other'    => 'Photo does not look genuine',
	);

	public static function register() {
		add_action( 'admin_post_csm_photo_hold', array( __CLASS__, 'admin_hold' ) );
		add_action( 'admin_post_csm_photo_release', array( __CLASS__, 'admin_release' ) );
		add_filter( 'bp_after_has_members_parse_args', array( __CLASS__, 'directory_exclude' ) );
		add_action( 'added_user_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'bp_members_avatar_uploaded', array( __CLASS__, 'on_avatar' ), 10, 1 );
		add_action( 'bp_after_member_header', array( __CLASS__, 'profile_controls' ), 5 );
	}

	/* ------------------------------------------------------------- state */

	public static function get( $uid ) {
		$h = get_user_meta( (int) $uid, self::META, true );
		return is_array( $h ) && ! empty( $h['at'] ) ? $h : null;
	}

	public static function is_held( $uid ) {
		return null !== self::get( $uid );
	}

	/** Every held member. Cached per request. */
	public static function held_ids() {
		static $ids = null;
		if ( null === $ids ) {
			global $wpdb;
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META
			) ) );
		}
		return $ids;
	}

	public static function hold( $uid, $reason, $by ) {
		$uid    = (int) $uid;
		$reason = isset( self::REASONS[ $reason ] ) ? $reason : 'other';
		update_user_meta( $uid, self::META, array( 'at' => time(), 'reason' => $reason, 'by' => (int) $by, 'fixed_at' => 0 ) );

		// Out of the trays they already sit in (unacted rows only), as a pause does.
		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';
		if ( $tray === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tray ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tray} WHERE profile_id = %d AND status = 'pending'", $uid ) );
		}

		self::email( $uid, 'held', $reason );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'photo_hold', (int) $by, $uid, array( 'reason' => $reason ) );
		}
	}

	public static function release( $uid, $by ) {
		$uid = (int) $uid;
		$h   = self::get( $uid );
		if ( ! $h ) {
			return;
		}
		delete_user_meta( $uid, self::META );
		self::email( $uid, 'released', $h['reason'] );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'photo_release', (int) $by, $uid, array( 'reason' => $h['reason'], 'held_at' => (int) $h['at'], 'new_photo' => ! empty( $h['fixed_at'] ) ) );
		}
	}

	/** A new photo while held: flag it for review (the hold stays). */
	private static function mark_fixed( $uid ) {
		$h = self::get( $uid );
		if ( $h && empty( $h['fixed_at'] ) ) {
			$h['fixed_at'] = time();
			update_user_meta( (int) $uid, self::META, $h );
		}
	}

	public static function on_meta( $meta_id, $uid, $key ) {
		if ( 'csm_photos' === $key && self::is_held( $uid ) ) {
			self::mark_fixed( $uid );
		}
	}

	public static function on_avatar( $uid ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( $uid && self::is_held( $uid ) ) {
			self::mark_fixed( $uid );
		}
	}

	public static function directory_exclude( $args ) {
		$ids = self::held_ids();
		if ( $ids ) {
			$args['exclude'] = empty( $args['exclude'] ) ? $ids : array_merge( (array) $args['exclude'], $ids );
		}
		return $args;
	}

	/* ------------------------------------------------------------ member */

	public static function photo_url( $uid ) {
		return function_exists( 'bp_members_get_user_url' )
			? trailingslashit( bp_members_get_user_url( (int) $uid ) ) . 'profile/change-avatar/'
			: home_url( '/profile/' );
	}

	/** Banner for the held member's own app screens; '' otherwise. */
	public static function banner( $uid ) {
		$h = self::get( $uid );
		if ( ! $h ) {
			return '';
		}
		$msg = empty( $h['fixed_at'] )
			? 'Your profile is hidden until you add a clear photo of yourself.'
			: 'Thanks for the new photo. We\'re checking it, and your profile will be visible again soon.';
		return '<div class="csm-app-paused"><span>' . esc_html( $msg ) . '</span>'
			. ( empty( $h['fixed_at'] ) ? '<a href="' . esc_url( self::photo_url( $uid ) ) . '">Update photo</a>' : '' )
			. '</div>';
	}

	private static function email( $uid, $kind, $reason ) {
		if ( ! class_exists( '\CAShaadi\Modules\Emails\Queue' ) || ! class_exists( '\CAShaadi\Modules\Emails\Engagement' ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$hi   = \CAShaadi\Modules\Emails\Engagement::greeting( $uid );
		if ( 'held' === $kind ) {
			$why = array(
				'nonhuman' => 'your profile photo does not show a person',
				'gender'   => 'your profile photo does not appear to match your profile',
				'other'    => 'your profile photo does not look like a genuine photo of you',
			);
			$body = \CAShaadi\Modules\Emails\Engagement::wrap(
				$hi,
				'<p>We have temporarily hidden your ' . esc_html( $site ) . ' profile because ' . esc_html( $why[ $reason ] ?? $why['other'] ) . '.</p>'
				. '<p>Members trust ' . esc_html( $site ) . ' because every profile is a real person. Please add a clear photo of yourself, and we will make your profile visible again as soon as we have checked it.</p>'
				. '<p>Your matches and conversations are not affected.</p>',
				self::photo_url( $uid ),
				'Update my photo'
			);
			Queue::notify( $uid, 'csm-photohold-' . time(), 'Action needed: update your profile photo', $body );
		} else {
			$body = \CAShaadi\Modules\Emails\Engagement::wrap(
				$hi,
				'<p>Thanks for updating your photo. Your ' . esc_html( $site ) . ' profile is visible to members again.</p>',
				home_url( '/discover/' ),
				'Open ' . $site
			);
			Queue::notify( $uid, 'csm-photorelease-' . time(), 'Your profile is visible again', $body );
		}
	}

	/* ------------------------------------------------------------- admin */

	private static function action_url( $action, $uid, $reason = '' ) {
		$args = array( 'action' => $action, 'uid' => (int) $uid );
		if ( '' !== $reason ) {
			$args['reason'] = $reason;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action . '_' . (int) $uid );
	}

	/** Small inline controls: "Hold: not a person · wrong gender · not genuine" or "Release". */
	public static function controls( $uid ) {
		if ( ! current_user_can( 'manage_options' ) || user_can( $uid, 'manage_options' ) ) {
			return '';
		}
		$h = self::get( $uid );
		if ( $h ) {
			return '<span style="color:#b32d2e;font-weight:600">On hold</span> · <a href="' . esc_url( self::action_url( 'csm_photo_release', $uid ) ) . '">Release</a>';
		}
		$short = array( 'nonhuman' => 'not a person', 'gender' => 'wrong gender', 'other' => 'not genuine' );
		$links = array();
		foreach ( $short as $k => $label ) {
			$links[] = '<a href="' . esc_url( self::action_url( 'csm_photo_hold', $uid, $k ) ) . '" onclick="return confirm(\'Hide this profile and email them to fix their photo?\')">' . esc_html( $label ) . '</a>';
		}
		return 'Hold: ' . implode( ' · ', $links );
	}

	public static function admin_hold() {
		$uid = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
		check_admin_referer( 'csm_photo_hold_' . $uid );
		if ( ! current_user_can( 'manage_options' ) || ! $uid || user_can( $uid, 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( ! self::is_held( $uid ) ) {
			self::hold( $uid, sanitize_key( (string) ( $_GET['reason'] ?? 'other' ) ), get_current_user_id() );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=csm-sales-dashboard' ) );
		exit;
	}

	public static function admin_release() {
		$uid = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
		check_admin_referer( 'csm_photo_release_' . $uid );
		if ( ! current_user_can( 'manage_options' ) || ! $uid ) {
			wp_die( 'Not allowed.' );
		}
		self::release( $uid, get_current_user_id() );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=csm-sales-dashboard' ) );
		exit;
	}

	/** On a member's profile page, for admins. */
	public static function profile_controls() {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		$c = self::controls( bp_displayed_user_id() );
		if ( '' !== $c ) {
			echo '<div style="margin:8px 0;padding:8px 12px;background:#fff8e5;border:1px solid #f0d58c;border-radius:6px;font-size:14px">Admin · ' . $c . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** "Photo holds" panel for the Sales Dashboard; new photos first. */
	public static function summary() {
		$ids = self::held_ids();
		if ( ! $ids ) {
			return '';
		}
		$rows = array();
		foreach ( $ids as $uid ) {
			$h = self::get( $uid );
			if ( $h ) {
				$rows[] = array( $uid, $h );
			}
		}
		usort( $rows, function ( $a, $b ) {
			return ( (int) ! empty( $b[1]['fixed_at'] ) <=> (int) ! empty( $a[1]['fixed_at'] ) ) ?: ( $b[1]['at'] <=> $a[1]['at'] );
		} );
		$review = count( array_filter( $rows, function ( $r ) { return ! empty( $r[1]['fixed_at'] ); } ) );
		$h  = '<details' . ( $review ? ' open' : '' ) . ' style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin:15px 0">';
		$h .= '<summary style="cursor:pointer;font-weight:600">Photo holds: ' . count( $rows ) . ( $review ? ' (<span style="color:#b32d2e">' . $review . ' new photo' . ( 1 === $review ? '' : 's' ) . ' to review</span>)' : '' ) . '</summary>';
		$h .= '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Member</th><th>Photo</th><th>Reason</th><th>Held</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			list( $uid, $hold ) = $r;
			$u   = get_userdata( $uid );
			$url = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : get_edit_user_link( $uid );
			$img = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'thumb', 'width' => 48, 'height' => 48, 'html' => true ) ) : '';
			$h  .= '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</a></td>'
				. '<td>' . $img . '</td>'
				. '<td>' . esc_html( self::REASONS[ $hold['reason'] ] ?? $hold['reason'] ) . '</td>'
				. '<td>' . esc_html( wp_date( 'j M H:i', (int) $hold['at'] ) ) . '</td>'
				. '<td>' . ( ! empty( $hold['fixed_at'] ) ? '<strong style="color:#b32d2e">New photo ' . esc_html( wp_date( 'j M H:i', (int) $hold['fixed_at'] ) ) . ': review</strong>' : 'Waiting for a new photo' ) . '</td>'
				. '<td><a class="button" href="' . esc_url( self::action_url( 'csm_photo_release', $uid ) ) . '">Release</a></td></tr>';
		}
		return $h . '</tbody></table></details>';
	}
}
