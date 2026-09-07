<?php
/**
 * MagicLink — "Open Discover" puts you back in, without a password.
 *
 * WHY. 517 members are being told the site was rebuilt. Landing them on a login
 * form is how that email gets ignored: most have not signed in for months and do
 * not remember a password they set once in 2024. This applies to every queued
 * email, not just the announcement — "see who viewed you" has exactly the same
 * problem.
 *
 * WHY IT IS NOT THE TRACKING TOKEN. Tracking's token is in the open pixel, and
 * the pixel is fetched by Gmail's image proxy, corporate mail gateways and
 * anything else that scans a message. A token handed to every scanner must never
 * grant a session. So this mints its OWN token, it appears only in the CTA links
 * inside the body, and only its HMAC is stored — the plaintext exists in the
 * email and nowhere else.
 *
 * WHY THERE IS A BUTTON IN THE WAY. Mail scanners prefetch links. A GET that
 * signs somebody in would be consumed by the scanner before the member ever
 * taps, and a single-use link would already be dead. So the GET only offers, and
 * the POST is what signs in. One extra tap; without it a real share of 517 links
 * would silently burn.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It never signs in an administrator, and the
 * session it creates is marked: changing an email address or password, or
 * deleting the account, still demands a real login. Email gets forwarded and
 * phones get picked up — a link in a mailbox should get you back to Discover,
 * not let whoever holds it delete the account.
 */

namespace CAShaadi\Modules\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MagicLink {

	/** Long enough that an email opened a fortnight late still works. */
	const TTL_DAYS = 14;

	/** Query arg carrying the plaintext token. */
	const ARG = 'k';

	/** Sessions that began from an email, per user. */
	const SESSION_META = 'csm_email_sessions';

	public static function register() {
		// Sensitive screens re-check who they are talking to.
		add_action( 'init', array( __CLASS__, 'guard' ), 5 );
	}

	/* ----------------------------------------------------------- minting */

	private static function hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	/**
	 * Mint a sign-in token for one queued row, or '' if it must not have one.
	 *
	 * Called at DELIVER time, so the token's life starts when the message
	 * actually goes out rather than when the row was written.
	 */
	public static function mint( $row ) {
		$uid = isset( $row->user_id ) ? (int) $row->user_id : 0;
		if ( ! $uid ) {
			return '';
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return '';
		}
		// Never hand an administrator's session to an email.
		if ( user_can( $user, 'manage_options' ) ) {
			return '';
		}

		$token = wp_generate_password( 32, false, false );

		global $wpdb;
		$wpdb->update(
			Queue::table(),
			array(
				'login_hash'    => self::hash( $token ),
				'login_expires' => gmdate( 'Y-m-d H:i:s', time() + ( self::TTL_DAYS * DAY_IN_SECONDS ) ),
				'login_used_at' => null,
			),
			array( 'id' => (int) $row->id )
		);

		return $token;
	}

	/* ---------------------------------------------------------- redeeming */

	/**
	 * The row this token signs in, or null.
	 *
	 * Compared with hash_equals against the stored HMAC, so a wrong guess costs
	 * the same as a right one.
	 */
	public static function claim( $row_id, $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return null;
		}

		global $wpdb;
		$t   = Queue::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", (int) $row_id ) );

		if ( ! $row || '' === (string) $row->login_hash ) {
			return null;
		}
		if ( ! hash_equals( (string) $row->login_hash, self::hash( $token ) ) ) {
			return null;
		}
		if ( ! empty( $row->login_used_at ) ) {
			return null;   // single use
		}
		if ( empty( $row->login_expires ) || strtotime( $row->login_expires . ' UTC' ) < time() ) {
			return null;
		}
		return $row;
	}

	/**
	 * Sign the member in and burn the token.
	 *
	 * The session token is recorded so guard() can tell an email-originated
	 * session from a real sign-in later.
	 */
	public static function redeem( $row ) {
		$uid  = (int) $row->user_id;
		$user = get_userdata( $uid );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return false;
		}

		global $wpdb;
		$wpdb->update( Queue::table(), array( 'login_used_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );

		wp_set_current_user( $uid, $user->user_login );
		wp_set_auth_cookie( $uid, true );

		$sessions = (array) get_user_meta( $uid, self::SESSION_META, true );
		$sessions[ wp_get_session_token() ] = time();
		// Keep it from growing without bound; old tokens are meaningless anyway.
		if ( count( $sessions ) > 20 ) {
			$sessions = array_slice( $sessions, -20, null, true );
		}
		update_user_meta( $uid, self::SESSION_META, $sessions );

		if ( function_exists( 'cashaadi' ) && method_exists( cashaadi(), 'log_event' ) ) {
			cashaadi()->log_event( 'email_signin', $uid, array( 'row' => (int) $row->id, 'type' => (string) $row->email_type ) );
		}
		return true;
	}

	/* ------------------------------------------------------------- guard */

	/** Was THIS session started by clicking a link in an email? */
	public static function is_email_session( $uid = 0 ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		$sessions = (array) get_user_meta( $uid, self::SESSION_META, true );
		return isset( $sessions[ wp_get_session_token() ] );
	}

	/**
	 * Paths an email-originated session may not reach.
	 *
	 * Deliberately short: this is about irreversible or identity-changing acts,
	 * not about making the session second-class everywhere.
	 */
	private static function protected_paths() {
		return array( 'settings/delete', 'settings/email', 'settings/password', 'delete-account' );
	}

	public static function guard() {
		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! self::is_email_session() ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );

		foreach ( self::protected_paths() as $p ) {
			if ( $path === $p || 0 === strpos( $path, $p . '/' ) ) {
				wp_safe_redirect( add_query_arg( 'csm_reauth', '1', wp_login_url( home_url( '/' . $path . '/' ) ) ) );
				exit;
			}
		}
	}
}
