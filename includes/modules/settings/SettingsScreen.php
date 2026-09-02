<?php
/**
 * Settings — the grouped hub, as an app screen.
 *
 * The hub itself is not new: Settings.php has rendered these rows on
 * BuddyPress's settings page since v0.27. What was wrong is where it lived —
 * the hamburger and the profile hub both said "Settings" and dropped members
 * into BuddyX chrome, the same complaint that produced this rebuild.
 *
 * WHAT THIS DOES AND DOES NOT OWN
 * It owns the hub: the grouped list, the status values, and the way in to every
 * editor. It does NOT own the editors. Changing an email or a password runs
 * through BuddyPress's own settings forms, which handle re-authentication,
 * the email-change confirmation loop and password strength. Re-implementing
 * that over REST would be re-implementing account security, which is a bad
 * trade for visual consistency — so those rows link out, and AppShell's back
 * link covers them so they are not dead ends.
 *
 * Read-only by design. Every value here is displayed, never written.
 */

namespace CAShaadi\Modules\Settings;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsScreen {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	public static function url() {
		return home_url( '/settings/' );
	}

	/* -------------------------------------------------------------- route */

	public static function maybe_render() {
		if ( AppPage::claim( 'settings/blocked' ) ) {
			self::render_blocked();
			return;
		}
		if ( AppPage::claim( 'settings/notifications' ) ) {
			self::render_notifications();
			return;
		}
		if ( ! AppPage::claim( 'settings' ) ) {
			return;
		}

		AppPage::assets();
		Assets::style( 'settings-app', 'assets/css/settings-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'settings-app', 'assets/js/settings-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-settings-app', 'CSM_SETTINGS', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'get'   => rest_url( 'csm/v1/settings' ),
		) );

		AppPage::open( __( 'Settings', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-settings-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	/**
	 * Blocked members, in the app.
	 *
	 * Owner: "On clicking blocked members it is taking me to another settings
	 * screen." It did — BuddyPress's settings sub-tab, in BuddyX chrome, which is
	 * the seam this rebuild exists to remove.
	 *
	 * The Block module still owns the data and the unblock action. This only
	 * renders and calls it, so there is one definition of what "blocked" means and
	 * one code path that changes it.
	 */
	private static function render_blocked() {
		AppPage::assets();
		Assets::style( 'settings-app', 'assets/css/settings-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'blocked-app', 'assets/js/blocked-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-blocked-app', 'CSM_BLOCKED', array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'list'     => rest_url( 'csm/v1/settings/blocked' ),
			'settings' => self::url(),
		) );

		AppPage::open( __( 'Blocked members', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-blocked-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	public static function rest_blocked( $request ) {
		$uid = get_current_user_id();

		if ( 'POST' === $request->get_method() ) {
			$target = absint( $request->get_param( 'user_id' ) );
			if ( ! $target || ! class_exists( '\CAShaadi\Modules\Block\Block' ) ) {
				return new \WP_REST_Response( array( 'ok' => false ), 200 );
			}
			// The Block module owns unblocking; this never touches the table.
			\CAShaadi\Modules\Block\Block::do_unblock( $uid, $target );
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$rows = array();
		if ( method_exists( '\CAShaadi\Modules\Block\Block', 'blocked_ids' ) ) {
			// The Block module keeps its table name private and hands out ids.
			$ids = (array) \CAShaadi\Modules\Block\Block::blocked_ids( $uid );
			foreach ( $ids as $mid ) {
				$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $mid ) : '';
				$rows[] = array(
					'id'     => $mid,
					'name'   => $name ? $name : __( 'Member', 'cashaadi-ui' ),
					'avatar' => function_exists( 'bp_core_fetch_avatar' )
						? bp_core_fetch_avatar( array( 'item_id' => $mid, 'type' => 'thumb', 'html' => false ) )
						: get_avatar_url( $mid, array( 'size' => 120 ) ),
				);
			}
		}
		return new \WP_REST_Response( array( 'ok' => true, 'blocked' => $rows ), 200 );
	}

	/**
	 * Email notifications, in the app.
	 *
	 * The settings are DISCOVERED, not written down. BuddyPress has no API that
	 * lists them — each component prints its own rows on the
	 * `bp_notification_settings` action — so the rows are captured by buffering
	 * that action and reading the inputs back out. A component (or Better
	 * Messages) adding a preference later therefore appears here on its own,
	 * where a hardcoded list would silently omit it.
	 *
	 * That same capture is the security boundary on save: only keys BuddyPress
	 * just rendered can be written, so a crafted key cannot set arbitrary user
	 * meta.
	 */
	private static function notification_options() {
		if ( ! function_exists( 'bp_get_user_meta' ) ) {
			return array();
		}

		ob_start();
		do_action( 'bp_notification_settings' );
		$html = (string) ob_get_clean();

		$out = array();
		if ( ! preg_match_all( '#<tr[^>]*>(.*?)</tr>#is', $html, $rows ) ) {
			return $out;
		}

		foreach ( $rows[1] as $row ) {
			if ( ! preg_match( '#name=["\']notifications\[([a-z0-9_]+)\]["\']#i', $row, $m ) ) {
				continue;
			}
			$key = $m[1];

			// The label is the row's text minus the radio captions.
			$label = wp_strip_all_tags( $row );
			$label = preg_replace( '/\s+/', ' ', $label );
			$label = trim( str_replace(
				array( 'Yes, send email', 'No, do not send' ),
				'',
				$label
			) );

			$out[ $key ] = array(
				'key'   => $key,
				'label' => $label ? $label : $key,
				'on'    => 'no' !== bp_get_user_meta( get_current_user_id(), $key, true ),
			);
		}
		return $out;
	}

	private static function render_notifications() {
		AppPage::assets();
		Assets::style( 'settings-app', 'assets/css/settings-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'notify-app', 'assets/js/notify-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-notify-app', 'CSM_NOTIFY', array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'api'      => rest_url( 'csm/v1/settings/notifications' ),
			'settings' => self::url(),
		) );

		AppPage::open( __( 'Email notifications', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-notify-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	public static function rest_notifications( $request ) {
		$uid     = get_current_user_id();
		$options = self::notification_options();

		if ( 'POST' === $request->get_method() ) {
			$key = sanitize_key( (string) $request->get_param( 'key' ) );
			$on  = (bool) $request->get_param( 'on' );

			// Only a key BuddyPress itself just rendered may be written.
			if ( ! isset( $options[ $key ] ) ) {
				return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'Unknown setting.', 'cashaadi-ui' ) ), 200 );
			}
			bp_update_user_meta( $uid, $key, $on ? 'yes' : 'no' );
			return new \WP_REST_Response( array( 'ok' => true, 'key' => $key, 'on' => $on ), 200 );
		}

		return new \WP_REST_Response( array(
			'ok'      => true,
			'options' => array_values( $options ),
		), 200 );
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/settings/notifications', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( __CLASS__, 'rest_notifications' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'csm/v1', '/settings/blocked', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( __CLASS__, 'rest_blocked' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'csm/v1', '/settings', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_get( $request ) {
		unset( $request );
		$uid  = get_current_user_id();
		$user = get_userdata( $uid );

		$me = function_exists( 'bp_members_get_user_url' )
			? trailingslashit( bp_members_get_user_url( $uid ) )
			: home_url( '/' );

		$phone    = method_exists( '\CAShaadi\Core\Verification', 'user_phone' ) ? Verification::user_phone( $uid ) : '';
		$phone_ok = method_exists( '\CAShaadi\Core\Verification', 'phone_verified' ) && Verification::phone_verified( $uid );
		$ca_ok    = method_exists( '\CAShaadi\Core\Verification', 'ca_verified' ) && Verification::ca_verified( $uid );

		// Is phone verification actually available? Requires the OTP module AND the
		// MSG91 credentials it calls; without both, offering it is a dead end.
		$otp_live = class_exists( '\CAShaadi\Core\Config' ) && \CAShaadi\Core\Config::otp_enabled()
			&& class_exists( '\CAShaadi\Core\Secrets' ) && \CAShaadi\Core\Secrets::has( 'msg91_widget_id' );

		$photos = class_exists( '\CAShaadi\Modules\ProfileEdit\ProfileEditScreen' )
			? $me . 'profile/change-avatar/'
			: $me . 'profile/change-avatar/';

		$groups = array(
			array(
				'title' => __( 'Account', 'cashaadi-ui' ),
				'rows'  => array(
					array( 'label' => __( 'Email', 'cashaadi-ui' ), 'value' => $user ? $user->user_email : '', 'url' => $me . 'settings/general/' ),
					/*
					 * The phone row must not promise verification that cannot happen.
					 *
					 * Verification needs the OTP module (flag-gated OFF) or snippet
					 * #11618 (disabled here), plus MSG91 credentials that have not
					 * been supplied. With none of those, "Not verified" linked to
					 * BuddyPress's settings form — which has no verification UI — so
					 * a member tapping it found nothing and no explanation.
					 *
					 * While OTP is unavailable the row shows the number as a plain
					 * readout with no destination. It becomes a working link the
					 * moment the module is enabled.
					 */
					array(
						'label' => __( 'Phone number', 'cashaadi-ui' ),
						'value' => $otp_live
							? ( $phone_ok ? __( 'Verified', 'cashaadi-ui' ) : ( $phone ? __( 'Not verified', 'cashaadi-ui' ) : __( 'Add', 'cashaadi-ui' ) ) )
							: ( $phone ? $phone : __( 'Not added', 'cashaadi-ui' ) ),
						'ok'    => $otp_live ? $phone_ok : null,
						'url'   => $otp_live ? $me . 'settings/general/' : '',
					),
					array( 'label' => __( 'Password', 'cashaadi-ui' ), 'value' => '', 'url' => $me . 'settings/general/' ),
				),
			),
			array(
				'title' => __( 'Privacy & photos', 'cashaadi-ui' ),
				'rows'  => array(
					array( 'label' => __( 'Photos', 'cashaadi-ui' ), 'value' => '', 'url' => $photos ),
					array(
						'label' => __( 'Photo blur', 'cashaadi-ui' ),
						'value' => '1' === (string) get_user_meta( $uid, 'csm_photo_private', true )
							? __( 'On', 'cashaadi-ui' ) : __( 'Off', 'cashaadi-ui' ),
						'url'   => $photos,
					),
					// FIELD visibility, not profile visibility: every profile is visible
					// to everyone; this controls who sees each individual field.
					array( 'label' => __( 'Field visibility', 'cashaadi-ui' ), 'value' => '', 'url' => $me . 'settings/profile/' ),
					array( 'label' => __( 'Blocked members', 'cashaadi-ui' ), 'value' => '', 'url' => home_url( '/settings/blocked/' ) ),
				),
			),
			array(
				'title' => __( 'Account status', 'cashaadi-ui' ),
				'rows'  => array(
					array(
						'label' => __( 'ICAI verification', 'cashaadi-ui' ),
						'value' => $ca_ok ? __( 'Verified', 'cashaadi-ui' ) : __( 'In review', 'cashaadi-ui' ),
						'ok'    => $ca_ok,
						'url'   => '',
					),
					array(
						'label' => __( 'Membership', 'cashaadi-ui' ),
						'value' => ( class_exists( '\CAShaadi\Core\Membership' ) && Membership::is_premium( $uid ) )
							? __( 'Premium', 'cashaadi-ui' ) : __( 'Free', 'cashaadi-ui' ),
						'url'   => site_url( '/membership-pricing/' ),
					),
					array( 'label' => __( 'Email notifications', 'cashaadi-ui' ), 'value' => '', 'url' => home_url( '/settings/notifications/' ) ),
					array( 'label' => __( 'Help & support', 'cashaadi-ui' ), 'value' => Config::SUPPORT_EMAIL, 'url' => 'mailto:' . Config::SUPPORT_EMAIL ),
				),
			),
		);

		return new \WP_REST_Response( array(
			'ok'       => true,
			'groups'   => $groups,
			'logout'   => wp_logout_url( home_url( '/' ) ),
			// Only offered when BuddyPress actually allows it, or the row is a lie.
			'deleteUrl' => ( function_exists( 'bp_disable_account_deletion' ) && ! bp_disable_account_deletion() )
				? $me . 'settings/delete-account/' : '',
		), 200 );
	}
}
