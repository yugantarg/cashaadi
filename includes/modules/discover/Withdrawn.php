<?php
/**
 * A withdrawn request.
 *
 * The profile goes to the sender's More → Passed, marked "Request withdrawn"
 * (owner, 2026-10-31: "it should go back in the more tab"), not back into
 * Discover. From there "Send request" works as for any passed profile.
 * Until v1.90.98 it went back into the Discover deck.
 *
 * BuddyPress deletes the request, but Discover still held the card as
 * 'liked'. On withdrawal the card is marked passed (keeping the week it was
 * drawn in, so nothing new is counted against the allowance), the old like is
 * cleared from csm_likes so a later like from the other side is not taken for
 * a match, and the time is kept in user meta for the label.
 *
 * restore() puts a profile back in the deck, undecided; More → Passed's
 * "Send request" uses it before sending the request.
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

	public static function on_withdraw( $friendship_id, $friendship = null ) {
		unset( $friendship_id );
		if ( is_object( $friendship ) && ! empty( $friendship->initiator_user_id ) ) {
			self::to_passed( (int) $friendship->initiator_user_id, (int) $friendship->friend_user_id );
		}
	}

	const META = 'csm_withdrawn'; // profile id => time withdrawn

	/** $viewer withdrew a request to $profile: into More → Passed. */
	public static function to_passed( $viewer, $profile ) {
		$viewer  = (int) $viewer;
		$profile = (int) $profile;
		if ( ! $viewer || ! $profile || $viewer === $profile ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'csm_likes', array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		$wpdb->update( $wpdb->prefix . 'csm_tray', array( 'status' => 'passed', 'acted_at' => current_time( 'mysql' ) ), array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		Seen::record_action( $viewer, $profile, 'passed' );
		$map             = (array) get_user_meta( $viewer, self::META, true );
		$map[ $profile ] = time();
		update_user_meta( $viewer, self::META, $map );
	}

	/** Profile ids $viewer withdrew a request to. */
	public static function ids( $viewer ) {
		return array_map( 'intval', array_keys( array_filter( (array) get_user_meta( (int) $viewer, self::META, true ) ) ) );
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
		$map = (array) get_user_meta( $viewer, self::META, true );
		if ( isset( $map[ $profile ] ) ) { // no longer a withdrawn one
			unset( $map[ $profile ] );
			update_user_meta( $viewer, self::META, $map );
		}
		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';
		$wpdb->delete( $wpdb->prefix . 'csm_likes', array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		/*
		 * The card goes back under the week it was first drawn in (v1.90.98),
		 * so coming back is not a new draw and does not count against this
		 * week's allowance (it showed as #2779 51/50 in the exposure check).
		 * A free member's deck is reset each week, so for them a card from an
		 * earlier week still comes back as this week's, as before.
		 */
		$week = $wpdb->get_var( $wpdb->prepare( "SELECT week_assigned FROM {$tray} WHERE viewer_id = %d AND profile_id = %d ORDER BY id DESC LIMIT 1", $viewer, $profile ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $tray, array( 'viewer_id' => $viewer, 'profile_id' => $profile ) );
		return (bool) $wpdb->insert( $tray, array(
			'viewer_id'     => $viewer,
			'profile_id'    => $profile,
			'week_assigned' => ( $week && \CAShaadi\Core\Membership::is_premium( $viewer ) ) ? $week : cashaadi()->get_week_id(),
			'status'        => 'pending',
			'assigned_at'   => current_time( 'mysql' ),
		) );
	}
}
