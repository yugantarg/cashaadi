<?php
/**
 * Delete my account.
 *
 * The Settings screen already offered this, but only as a link to BuddyPress's
 * own /settings/delete-account/ — which drops the member out of the app, and
 * which is switched OFF on this site anyway (bp-disable-account-deletion = 1,
 * verified on staging2), so the row never appeared at all. Members had no way
 * to leave.
 *
 * This claims the same path first, so the URL a member may already have works
 * and the screen stays inside the app.
 *
 * TWO LOCKS, because this cannot be undone. The member types DELETE and enters
 * their password. The typed word stops a mis-tap; the password is what stops a
 * borrowed or hijacked session destroying somebody's account, which is the only
 * threat here that matters — every other Settings action is reversible.
 *
 * WHAT ACTUALLY GETS REMOVED. wp_delete_user() fires BuddyPress's own cleanup
 * (bp_core_remove_data_on_delete_user), which clears xProfile, friends,
 * messages, activity and notifications. It does NOT clear:
 *
 *   - the avatar files, because BuddyPress hooks that to wpmu_delete_user only
 *     — a multisite-only hook. On a single site the photos would simply stay on
 *     disk after the account was gone. Checked in the BuddyPress source rather
 *     than assumed.
 *   - this plugin's seven tables, which is how the site collected 550 orphan
 *     rows from earlier deletions.
 *   - gallery attachments, which are ordinary media items.
 *
 * So all three are handled here, on delete_user — which fires BEFORE the row
 * goes, so everything is still readable.
 */

namespace CAShaadi\Modules\Settings;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeleteAccount {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Runs for EVERY deletion — ours, wp-admin's, WP-CLI's — so the tables
		// stay clean however an account goes.
		add_action( 'delete_user', array( __CLASS__, 'purge' ) );
	}

	public static function url() {
		return home_url( '/settings/delete-account/' );
	}

	/* --------------------------------------------------------------- screen */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'settings/delete-account' ) ) {
			return;
		}

		AppPage::assets();
		Assets::style( 'settings-app', 'assets/css/settings-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'delete-account', 'assets/js/delete-account.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-delete-account', 'CSM_DELETE', array(
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'submit' => rest_url( 'csm/v1/settings/delete-account' ),
			'back'   => home_url( '/settings/' ),
			'home'   => home_url( '/' ),
			// The reversible alternative, offered ON this screen: most people
			// who get this far want to stop being seen, not to lose everything.
			'pause'  => ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) && Deactivate::offered() ) ? Deactivate::url() : '',
		) );

		AppPage::open( __( 'Delete my account', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-delete-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/settings/delete-account', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_delete' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_delete( $request ) {
		$uid  = get_current_user_id();
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'Please log in again.', 'cashaadi-ui' ) ), 200 );
		}

		/*
		 * Administrators are refused, not accommodated. bp_core_delete_account()
		 * refuses super admins too, but silently — a member would press the
		 * button and be told nothing. And an owner deleting their own account
		 * from the member UI is far more likely to be a mistake than an
		 * intention.
		 */
		if ( is_super_admin( $uid ) || user_can( $uid, 'manage_options' ) ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => __( 'Administrator accounts cannot be deleted here. Use the WordPress admin.', 'cashaadi-ui' ),
			), 200 );
		}

		$confirm  = strtoupper( trim( (string) $request->get_param( 'confirm' ) ) );
		$password = (string) $request->get_param( 'password' );

		if ( 'DELETE' !== $confirm ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'Type DELETE to confirm.', 'cashaadi-ui' ) ), 200 );
		}
		if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That password is not right.', 'cashaadi-ui' ) ), 200 );
		}

		/*
		 * Record the departure BEFORE it happens: after wp_delete_user() there
		 * is no user to attribute it to, and "how many people leave" is the one
		 * number this feature makes it possible to know.
		 */
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'account_deleted', $uid, 0, array( 'at' => current_time( 'mysql' ) ) );
		}

		/*
		 * Stop billing BEFORE the account goes.
		 *
		 * PMPro's own delete_user hook cancels active subscriptions only when
		 * $_REQUEST['pmpro_delete_active_subscriptions'] is set — a field on the
		 * wp-admin delete form, which obviously is not present on a REST call
		 * from the member's own phone. Read in cleanup.php rather than assumed.
		 * Left alone, someone could delete their account and keep being charged.
		 */
		add_filter( 'pmpro_user_deletion_cancel_active_subscriptions', '__return_true' );
		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			pmpro_changeMembershipLevel( 0, $uid );
		}

		/*
		 * Tell them it happened, while there is still an address to tell.
		 *
		 * Sent directly rather than through the queue: the queue would hold it
		 * behind the master switch and a daily cap, and by the time it released
		 * there would be no account for it to describe. It is also the alarm
		 * that matters most if the deletion was NOT them.
		 */
		self::farewell( $user );

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$done = false;
		if ( function_exists( 'bp_core_delete_account' ) ) {
			$done = (bool) bp_core_delete_account( $uid );
		}
		if ( ! $done ) {
			$done = (bool) wp_delete_user( $uid );
		}

		if ( ! $done ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => __( 'We could not delete the account. Please email support and we will do it for you.', 'cashaadi-ui' ),
			), 200 );
		}

		wp_logout();

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * "Your account has been deleted."
	 *
	 * Deliberately not through the email queue — see the call site. wp_mail() is
	 * pluggable and Brevo overrides it here, so this goes out the same way every
	 * other real message on this site does.
	 */
	private static function farewell( $user ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">'
			. '<p>Your ' . esc_html( $site ) . ' account has been deleted, along with your profile, photos and conversations.</p>'
			. '<p>There is nothing left to undo — we cannot restore it. You are welcome to sign up again at any time.</p>'
			. '<p style="color:#7a6f68;font-size:13px">If this was not you, reply to this email immediately.</p>'
			. '</div>';

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_type' ) );
		wp_mail( $user->user_email, 'Your ' . $site . ' account has been deleted', $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_type' ) );
	}

	public static function html_type() {
		return 'text/html';
	}

	/**
	 * Better Messages keeps its OWN tables and cleans up after nobody.
	 *
	 * Checked the plugin source: it hooks neither delete_user nor
	 * wpmu_delete_user anywhere. So without this, a deleted member's messages,
	 * thread membership, mentions and cached display name stay in
	 * wp_bm_* indefinitely — and their name keeps rendering in other people's
	 * inboxes, from wp_bm_user_index, long after the account is gone.
	 *
	 * Their sent messages go too. That does remove text from the other person's
	 * thread, which is a real cost — but it is the departing member's own words,
	 * and a deletion that leaves them in place is not a deletion.
	 */
	private static function purge_messages( $user_id ) {
		global $wpdb;

		$by_user = array(
			'bm_message_recipients' => 'user_id',
			'bm_mentions'           => 'user_id',
			'bm_moderation'         => 'user_id',
			'bm_user_roles_index'   => 'user_id',
			'bm_user_index'         => 'ID',
			'bm_message_messages'   => 'sender_id',
		);

		foreach ( $by_user as $table => $col ) {
			$full = $wpdb->prefix . $table;
			if ( $full !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$full} WHERE {$col} = %d", $user_id ) );
		}

		/*
		 * Threads nobody is left in. A one-to-one conversation loses its only
		 * other participant when an account goes, and an empty thread is a row
		 * that can never be opened again.
		 */
		$threads = $wpdb->prefix . 'bm_threads';
		$recips  = $wpdb->prefix . 'bm_message_recipients';
		if ( $threads === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $threads ) )
			&& $recips === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $recips ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE t FROM {$threads} t LEFT JOIN {$recips} r ON r.thread_id = t.id WHERE r.id IS NULL" );
		}
	}

	/* ---------------------------------------------------------------- purge */

	/**
	 * Everything this plugin knows about a member, in both directions.
	 *
	 * Both directions matters: deleting only the rows where they are the viewer
	 * leaves them sitting in other members' trays, seen-lists and block lists as
	 * a profile id that no longer resolves — which is exactly the orphan state
	 * that had to be cleaned up by hand earlier.
	 */
	public static function purge( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}

		$pairs = array(
			'csm_tray'          => array( 'viewer_id', 'profile_id' ),
			'csm_seen'          => array( 'viewer_id', 'profile_id' ),
			'csm_likes'         => array( 'viewer_id', 'profile_id' ),
			'csm_blocks'        => array( 'blocker_id', 'blocked_id' ),
			'csm_profile_views' => array( 'viewer_id', 'viewed_id' ),
			'csm_event_log'     => array( 'actor_id', 'target_id' ),
			'csm_email_queue'   => array( 'user_id' ),
		);

		foreach ( $pairs as $table => $cols ) {
			$full = $wpdb->prefix . $table;
			if ( $full !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) {
				continue;
			}
			foreach ( $cols as $col ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$full} WHERE {$col} = %d", $user_id ) );
			}
		}

		self::purge_messages( $user_id );

		// Gallery photos are ordinary attachments; nothing else removes them.
		$photos = get_user_meta( $user_id, 'csm_photos', true );
		foreach ( (array) ( is_array( $photos ) ? $photos : array() ) as $att_id ) {
			if ( (int) $att_id ) {
				wp_delete_attachment( (int) $att_id, true );
			}
		}

		/*
		 * The avatar files. BuddyPress hooks its own avatar cleanup to
		 * wpmu_delete_user, which never fires on a single site — so without this
		 * a deleted member's photographs stay on disk indefinitely.
		 */
		if ( function_exists( 'bp_core_delete_existing_avatar' ) ) {
			bp_core_delete_existing_avatar( array( 'item_id' => $user_id, 'object' => 'user' ) );
		}
		if ( function_exists( 'bp_core_avatar_upload_path' ) ) {
			$dir = bp_core_avatar_upload_path() . '/avatars/' . $user_id;
			if ( is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '/*' ) as $f ) {
					if ( is_file( $f ) ) {
						@unlink( $f ); // phpcs:ignore
					}
				}
				@rmdir( $dir ); // phpcs:ignore -- leaves history/ behind if BP made one
			}
		}
	}
}
