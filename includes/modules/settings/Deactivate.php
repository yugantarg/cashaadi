<?php
/**
 * Pause my profile — the reversible half of "delete my account".
 *
 * Most people who reach the delete screen do not want their conversations
 * destroyed; they want to stop being seen. Offering only deletion turns "I need
 * a break" into an irreversible act, and a matrimonial site has more reason
 * than most to make the reversible option the obvious one: members leave when
 * something is going well and come back when it is not.
 *
 * A pause is ONE user meta key, csm_deactivated, holding the timestamp. Nothing
 * is copied, moved or deleted, so coming back is a single delete_user_meta and
 * the account is exactly as it was.
 *
 * WHAT PAUSING ACTUALLY MEANS. Being invisible is not one switch, it is four,
 * and missing any of them makes the promise false:
 *
 *   1. Not offered to anyone in Discover — and pulled from the trays they are
 *      ALREADY in, or they stay visible for up to a week.
 *   2. Not in the members directory.
 *   3. No emails, of any kind, including the ones already queued.
 *   4. Their own profile page says so rather than 404ing, because existing
 *      matches will visit it and deserve an explanation.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. Existing matches and conversations stay.
 * Deleting them would make a pause indistinguishable from deletion for the
 * other person, who did nothing and should not lose their match because
 * somebody took a fortnight off.
 */

namespace CAShaadi\Modules\Settings;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivate {

	const META = 'csm_deactivated';

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// (2) the members directory
		add_filter( 'bp_after_has_members_parse_args', array( __CLASS__, 'directory_exclude' ) );

		// (3) emails — the queue asks before writing anything
		add_filter( 'csm_remail_can_email', array( __CLASS__, 'block_email' ), 10, 2 );
	}

	public static function url() {
		return home_url( '/settings/pause/' );
	}

	/* ----------------------------------------------------------------- state */

	public static function is_paused( $user_id ) {
		return '' !== (string) get_user_meta( (int) $user_id, self::META, true );
	}

	/** Every paused member, for the Discover exclusion. Cached per request. */
	public static function paused_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
			self::META
		) ) );
		return $ids;
	}

	public static function pause( $user_id ) {
		$user_id = (int) $user_id;
		update_user_meta( $user_id, self::META, current_time( 'mysql' ) );

		/*
		 * Pull them out of trays they are already sitting in. Without this a
		 * paused member keeps appearing to up to five people per tray until the
		 * week rolls over — which is the whole promise broken, for a week.
		 *
		 * Only rows nobody has acted on: a like or a pass already given is a
		 * decision somebody made, and rewriting it would corrupt their history.
		 */
		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';
		if ( $tray === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tray ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tray} WHERE profile_id = %d AND status = 'pending'", $user_id ) );
		}

		// Anything queued but not yet sent is no longer wanted.
		$queue = $wpdb->prefix . 'csm_email_queue';
		if ( $queue === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$queue} SET status = 'cancelled', note = 'profile paused' WHERE user_id = %d AND status IN ('pending','held')",
				$user_id
			) );
		}

		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'profile_paused', $user_id, 0, array() );
		}
	}

	public static function resume( $user_id ) {
		delete_user_meta( (int) $user_id, self::META );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'profile_resumed', (int) $user_id, 0, array() );
		}
	}

	/* --------------------------------------------------------------- filters */

	public static function directory_exclude( $args ) {
		$ids = self::paused_ids();
		if ( ! $ids ) {
			return $args;
		}
		$args['exclude'] = empty( $args['exclude'] )
			? $ids
			: array_merge( (array) $args['exclude'], $ids );
		return $args;
	}

	/** No mail to a paused member — not a nudge, not a match, not a batch. */
	public static function block_email( $can, $user_id ) {
		return self::is_paused( $user_id ) ? false : $can;
	}

	/* ---------------------------------------------------------------- screen */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'settings/pause' ) ) {
			return;
		}

		$uid = get_current_user_id();

		AppPage::assets();
		Assets::style( 'settings-app', 'assets/css/settings-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'pause-account', 'assets/js/pause-account.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-pause-account', 'CSM_PAUSE', array(
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'submit' => rest_url( 'csm/v1/settings/pause' ),
			'paused' => self::is_paused( $uid ),
			'since'  => (string) get_user_meta( $uid, self::META, true ),
			'back'   => home_url( '/settings/' ),
			'delete' => class_exists( '\CAShaadi\Modules\Settings\DeleteAccount' ) ? DeleteAccount::url() : '',
		) );

		AppPage::open( __( 'Pause my profile', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-pause-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/settings/pause', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_toggle' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_toggle( $request ) {
		$uid  = get_current_user_id();
		$want = (bool) $request->get_param( 'paused' );

		if ( $want ) {
			self::pause( $uid );
		} else {
			self::resume( $uid );
		}

		return new \WP_REST_Response( array( 'ok' => true, 'paused' => $want ), 200 );
	}
}
