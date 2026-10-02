<?php
/**
 * Closed accounts: what "delete my account" now does.
 *
 * Owner, 2026-10-02: "You should continue to keep user data, just the account
 * is deleted ... Full data deletion can be requested by email. Suppose the
 * same user registers again, we don't want a clash with the old data."
 *
 * Closing keeps every row — profile, photos, likes, matches, messages,
 * payments, impressions — and does four things:
 *
 *   1. HIDDEN. The account is paused (Deactivate: out of Discover, trays, the
 *      directory, every email) and treated as blocked by everyone
 *      (Block::hidden_ids / is_blocked_pair, which Matches, Requests, member
 *      pages, messaging, photo requests and match emails already honour).
 *   2. LOCKED. Sessions destroyed, roles removed, login refused.
 *   3. IDENTITY FREED. user_email, user_login and the profile slug are
 *      replaced by tombstones, and any wp_signups rows with the old email are
 *      renamed too, so the same person can register again with the same
 *      email and get a brand-new account. Nothing of the old one is reused.
 *      The originals are kept in user meta (csm_closed_*), so the two
 *      accounts can still be linked for analysis or a deletion request.
 *   4. RECORDED. account_deleted event with the reason (DeleteAccount).
 *
 * Full erasure (on request by email) is an admin deleting the user in
 * wp-admin; DeleteAccount::purge still runs on delete_user for that.
 */

namespace CAShaadi\Modules\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Closed {

	const META = 'csm_closed';

	public static function register() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'refuse_login' ), 99 );
	}

	public static function is_closed( $uid ) {
		return (int) $uid > 0 && in_array( (int) $uid, self::ids(), true );
	}

	/** Every closed account id. One query per request. */
	public static function ids() {
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

	public static function refuse_login( $user ) {
		if ( $user instanceof \WP_User && get_user_meta( $user->ID, self::META, true ) ) {
			return new \WP_Error( 'csm_closed', __( 'This account has been closed. You are welcome to sign up again.', 'cashaadi-ui' ) );
		}
		return $user;
	}

	/**
	 * Close $uid. Returns true on success. Idempotent: closing a closed account
	 * changes nothing.
	 */
	public static function close( $uid ) {
		global $wpdb;
		$uid  = (int) $uid;
		$user = get_userdata( $uid );
		if ( ! $user || get_user_meta( $uid, self::META, true ) ) {
			return (bool) $user;
		}

		// Keep who they were, for linking and for a later erasure request.
		update_user_meta( $uid, 'csm_closed_email', $user->user_email );
		update_user_meta( $uid, 'csm_closed_login', $user->user_login );
		update_user_meta( $uid, 'csm_closed_nicename', $user->user_nicename );
		update_user_meta( $uid, 'csm_closed_roles', array_values( (array) $user->roles ) );

		// 1. Hidden: pause first (trays, directory, queued email), then flag.
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			Deactivate::pause( $uid );
		}
		update_user_meta( $uid, self::META, current_time( 'mysql' ) );

		// 2. Locked.
		if ( class_exists( '\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $uid )->destroy_all();
		}
		$user->set_role( '' );

		// 3. Identity freed. user_login cannot be changed through
		// wp_update_user, so the row is written directly.
		$tag   = $uid . '-' . time();
		$tomb  = 'closed-' . $tag . '@closed.invalid';
		$wpdb->update(
			$wpdb->users,
			array(
				'user_email'    => $tomb,
				'user_login'    => 'closed_' . str_replace( '-', '_', $tag ),
				'user_nicename' => 'closed-' . $tag,
			),
			array( 'ID' => $uid )
		);
		$signups = $wpdb->prefix . 'signups';
		if ( $signups === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $signups ) ) ) {
			$wpdb->update( $signups, array( 'user_email' => $tomb, 'user_login' => 'closed_' . str_replace( '-', '_', $tag ) ), array( 'user_email' => $user->user_email ) );
		}
		clean_user_cache( $uid );

		return true;
	}
}
