<?php
/**
 * A withdrawn request puts the profile back in the sender's Discover
 * (owner, 2026-10-28: a Premium member withdrew a request by accident and
 * could not find her again; "it should come back into Discover. That should
 * be the standard behaviour").
 *
 * BuddyPress deletes the request, but Discover still held the card as
 * 'liked', so it never came back and nothing could send the request again.
 * On withdrawal the card returns to the sender's deck, undecided, as part of
 * this week (it was theirs already, so it is not a free extra), and the old
 * like is cleared from csm_likes so a later like from the other side is not
 * taken for a match.
 *
 * Not restored when the pair is blocked, or the profile is paused or held
 * for review: Discover would not show it then either.
 */

namespace CAShaadi\Modules\Discover;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Withdrawn {

	public static function register() {
		// Fired before BuddyPress deletes the request.
		add_action( 'friends_friendship_withdrawn', array( __CLASS__, 'on_withdraw' ), 10, 2 );
	}

	const WEEK_ROW = 'restored';

	public static function on_withdraw( $friendship_id, $friendship = null ) {
		unset( $friendship_id );
		if ( is_object( $friendship ) && ! empty( $friendship->initiator_user_id ) ) {
			self::restore( (int) $friendship->initiator_user_id, (int) $friendship->friend_user_id );
		}
	}

	/** Put $profile back, undecided, in $viewer's deck. True when it was. */
	public static function restore( $viewer, $profile ) {
		$viewer  = (int) $viewer;
		$profile = (int) $profile;
		if ( ! $viewer || ! $profile || $viewer === $profile || ! get_userdata( $profile ) || ! function_exists( 'cashaadi' ) ) {
			return false;
		}
		if ( ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $viewer, $profile ) )
			|| ( class_exists( '\\CAShaadi\\Modules\\Settings\\Deactivate' ) && \CAShaadi\Modules\Settings\Deactivate::is_paused( $profile ) )
			|| ( class_exists( '\\CAShaadi\\Modules\\Photos\\PhotoHold' ) && \CAShaadi\Modules\Photos\PhotoHold::is_held( $profile ) ) ) {
			return false;
		}
		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';
		$wpdb->delete( $tray, array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		$wpdb->delete( $wpdb->prefix . 'csm_likes', array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		return (bool) $wpdb->insert( $tray, array(
			'viewer_id'     => $viewer,
			'profile_id'    => $profile,
			// Its own label, not this week's (v1.90.98): a card coming back is
			// not a new draw, so it does not count against the weekly allowance
			// (it showed as #2779 51/50 in the exposure check).
			'week_assigned' => self::WEEK_ROW,
			'status'        => 'pending',
			'assigned_at'   => current_time( 'mysql' ),
		) );
	}
}
