<?php
/**
 * Block module.
 *
 * Migrates WPCode #11810 "CSM — Block User": any logged-in member can Block
 * another member from their profile header. A block is mutual-hiding — once A
 * blocks B, the pair disappear from each other's Discover / search / directory /
 * matches loops, cannot open each other's profile, and cannot message or send
 * match requests. Blocking also removes any existing match (friendship) and
 * pending request between them and clears any Discover-tray rows between them.
 * Members manage their list at Settings -> Blocked (and via [csm_blocked_list]).
 *
 * OWNS the custom table {$wpdb->prefix}csm_blocks (registered with the Migrator
 * under the handle 'block'; the snippet's own csm_bl_install/init dbDelta is
 * retired). Preserves the AJAX action wp_ajax_csm_bl_toggle + the csm_bl_nonce,
 * the button markup/classes, and every "hide blocked users" filter/guard.
 *
 * Gated behind Config::block_enabled() (off unless wp-config sets
 * CASHAADI_BLOCK_ENABLED = true). Because #11810 is still active in production
 * and defines the global helpers csm_bl_hidden_ids()/csm_bl_is_blocked_pair()
 * that other snippets (#11599 tray refill, #11821 view email / Premium module)
 * call by name, those globals are re-exposed from compat.php guarded with
 * function_exists — so both-active never fatals on redeclare.
 */

namespace CAShaadi\Modules\Block;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Block {

	public static function register() {
		if ( ! Config::block_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Owns wp_csm_blocks (retires the snippet's csm_bl_install on init).
		Migrator::register( 'block', array( __CLASS__, 'schema' ) );

		// Block / unblock over AJAX (nonce: csm_bl_nonce).
		add_action( 'wp_ajax_csm_bl_toggle', array( __CLASS__, 'ajax' ) );

		// Hide blocked ids from EVERY BP_User_Query (directory, BP search, and the
		// Premium Partner Search #11801, which also uses BP_User_Query). We set
		// query_vars['exclude'] on bp_pre_user_query_construct — which fires inside
		// BP_User_Query::__construct BEFORE prepare_user_ids_query() extract()s the
		// 'exclude' var and builds the WHERE. The original #11810 used the LATER
		// bp_pre_user_query action, where the query-var change lands too late for the
		// members directory (a latent gap in the snippet). The old hook is kept too
		// as belt-and-braces for any path that reads the var later.
		add_action( 'bp_pre_user_query_construct', array( __CLASS__, 'filter_user_query' ) );
		add_action( 'bp_pre_user_query', array( __CLASS__, 'filter_user_query' ) );

		// Redirect away from a blocked member's profile.
		add_action( 'bp_template_redirect', array( __CLASS__, 'guard_profile' ), 1 );

		// Undo any match request created between a blocked pair; deny messages.
		add_action( 'friends_friendship_requested', array( __CLASS__, 'guard_request' ), 10, 3 );
		add_filter( 'bp_better_messages_user_can_send_message', array( __CLASS__, 'guard_message' ), 10, 2 );

		// Profile-header Block/Unblock button.
		add_action( 'bp_member_header_actions', array( __CLASS__, 'header_button' ), 99 );

		// Settings -> Blocked screen + [csm_blocked_list] fallback.
		add_shortcode( 'csm_blocked_list', array( __CLASS__, 'shortcode' ) );
		add_action( 'bp_setup_nav', array( __CLASS__, 'settings_subnav' ), 20 );

		// Front-end CSS/JS (replaces the snippet's wp_footer inline style+script).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Re-expose the global helpers other still-active snippets call by name
		// (csm_bl_hidden_ids / csm_bl_is_blocked_pair). Loaded on wp_loaded — NOT
		// synchronously here — so that during a cutover window where the #11810
		// snippet is still active, the snippet (which runs by 'init' at the latest)
		// defines these globals FIRST and compat's function_exists guard then skips
		// them. Requiring compat at plugins_loaded would define them before the
		// snippet, making the snippet's UNGUARDED copies fatal on redeclare (this
		// took staging down once). wp_loaded is still well before the callers
		// (template_redirect / admin_init), so nothing that needs these globals
		// runs before they are defined.
		add_action( 'wp_loaded', array( __CLASS__, 'load_compat' ) );
	}

	/** Late-load the global-helper shims (see register()). */
	public static function load_compat() {
		require_once __DIR__ . '/compat.php';
	}

	/* ---- table ---------------------------------------------------------- */

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_blocks';
	}

	/**
	 * Members this user has blocked, newest first.
	 *
	 * Exists so the in-app Blocked members screen does not need the table name —
	 * that stays private here. Returns ids only; the caller decides what to show.
	 *
	 * @return int[]
	 */
	public static function blocked_ids( $owner_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT blocked_id FROM {$t} WHERE blocker_id = %d ORDER BY created_at DESC",
			(int) $owner_id
		) ) );
	}

	/** CREATE TABLE for the Migrator (exact schema from #11810). */
	public static function schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_blocks';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			blocker_id BIGINT UNSIGNED NOT NULL,
			blocked_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (blocker_id, blocked_id),
			KEY blocked_id (blocked_id)
		) {$charset};";
	}

	/* ---- helpers -------------------------------------------------------- */

	/** All user ids hidden for $uid: everyone $uid blocked + everyone who blocked $uid. */
	public static function hidden_ids( $uid ) {
		static $cache = array();
		$uid = (int) $uid;
		if ( ! $uid ) {
			return array();
		}
		if ( isset( $cache[ $uid ] ) ) {
			return $cache[ $uid ];
		}
		global $wpdb;
		$t   = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT blocked_id FROM $t WHERE blocker_id = %d
			 UNION
			 SELECT blocker_id FROM $t WHERE blocked_id = %d",
			$uid, $uid
		) );
		$cache[ $uid ] = array_map( 'intval', (array) $ids );
		return $cache[ $uid ];
	}

	/** True if either user has blocked the other. */
	public static function is_blocked_pair( $a, $b ) {
		$a = (int) $a; $b = (int) $b;
		if ( ! $a || ! $b ) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM $t WHERE (blocker_id = %d AND blocked_id = %d) OR (blocker_id = %d AND blocked_id = %d) LIMIT 1",
			$a, $b, $b, $a
		) );
	}

	/** True if $blocker has specifically blocked $blocked (directional). */
	private static function has_blocked( $blocker, $blocked ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM $t WHERE blocker_id = %d AND blocked_id = %d LIMIT 1", (int) $blocker, (int) $blocked
		) );
	}

	/* ---- block / unblock ------------------------------------------------ */

	private static function do_block( $blocker, $blocked ) {
		$blocker = (int) $blocker; $blocked = (int) $blocked;
		if ( ! $blocker || ! $blocked || $blocker === $blocked ) {
			return false;
		}
		if ( user_can( $blocked, 'manage_options' ) ) {
			return false; // never block admins/site accounts
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO " . self::table() . " (blocker_id, blocked_id, created_at) VALUES (%d, %d, %s)",
			$blocker, $blocked, current_time( 'mysql' )
		) );

		// Remove any match / pending request both directions.
		if ( function_exists( 'friends_remove_friend' ) ) {
			friends_remove_friend( $blocker, $blocked );
			friends_remove_friend( $blocked, $blocker );
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}bp_friends
			 WHERE (initiator_user_id = %d AND friend_user_id = %d) OR (initiator_user_id = %d AND friend_user_id = %d)",
			$blocker, $blocked, $blocked, $blocker
		) );

		// Clear any Discover-tray rows between the pair (both as viewer/profile).
		$tray = $wpdb->prefix . 'csm_tray';
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $tray ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $tray WHERE (viewer_id = %d AND profile_id = %d) OR (viewer_id = %d AND profile_id = %d)",
				$blocker, $blocked, $blocked, $blocker
			) );
		}
		do_action( 'csm_bl_blocked', $blocker, $blocked );
		return true;
	}

	/**
	 * Public so the in-app Blocked members screen can call it.
	 *
	 * That screen renders the list and nothing else — unblocking stays here, with
	 * the cache clearing and whatever else this method does, so there is exactly
	 * one code path that changes block state.
	 */
	public static function do_unblock( $blocker, $blocked ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table() . " WHERE blocker_id = %d AND blocked_id = %d",
			(int) $blocker, (int) $blocked
		) );
		return true;
	}

	/* ---- ajax ----------------------------------------------------------- */

	public static function ajax() {
		check_ajax_referer( 'csm_bl_nonce', 'nonce' );
		$uid    = (int) get_current_user_id();
		$target = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( ! $uid || ! $target ) {
			wp_send_json_error( array( 'message' => 'Invalid request.' ) );
		}
		if ( 'block' === $do ) {
			self::do_block( $uid, $target );
			wp_send_json_success( array( 'state' => 'blocked' ) );
		}
		if ( 'unblock' === $do ) {
			self::do_unblock( $uid, $target );
			wp_send_json_success( array( 'state' => 'unblocked' ) );
		}
		wp_send_json_error( array( 'message' => 'Unknown action.' ) );
	}

	/* ---- hide from every BP_User_Query ---------------------------------- */

	public static function filter_user_query( $query ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$hidden = self::hidden_ids( get_current_user_id() );
		if ( empty( $hidden ) ) {
			return;
		}
		$existing = isset( $query->query_vars['exclude'] ) ? $query->query_vars['exclude'] : array();
		if ( ! is_array( $existing ) ) {
			$existing = array_filter( array_map( 'trim', explode( ',', (string) $existing ) ) );
		}
		$query->query_vars['exclude'] = array_unique( array_merge( array_map( 'intval', $existing ), $hidden ) );
	}

	/* ---- block direct profile access ------------------------------------ */

	public static function guard_profile() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		$me      = (int) get_current_user_id();
		$display = (int) bp_displayed_user_id();
		if ( $display && $me !== $display && self::is_blocked_pair( $me, $display ) ) {
			$dest = function_exists( 'bp_members_get_user_url' ) ? bp_get_members_directory_permalink() : home_url( '/' );
			wp_safe_redirect( $dest );
			exit;
		}
	}

	/* ---- guard match requests / messages -------------------------------- */

	/** If a friend (match) request is created between a blocked pair, undo it at once. */
	public static function guard_request( $friendship_id, $initiator_user_id = 0, $friend_user_id = 0 ) {
		if ( $initiator_user_id && $friend_user_id && self::is_blocked_pair( $initiator_user_id, $friend_user_id ) ) {
			if ( function_exists( 'friends_remove_friend' ) ) {
				friends_remove_friend( $initiator_user_id, $friend_user_id );
			}
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bp_friends WHERE id = %d", (int) $friendship_id ) );
		}
	}

	/** Best-effort: deny Better Messages sends between a blocked pair (if the filter exists). */
	public static function guard_message( $can, $args = array() ) {
		// Signature varies by plugin version; be defensive.
		$sender    = isset( $args['sender_id'] ) ? (int) $args['sender_id'] : (int) get_current_user_id();
		$recipient = 0;
		if ( isset( $args['recipient_id'] ) ) {
			$recipient = (int) $args['recipient_id'];
		} elseif ( isset( $args['recipients'] ) && is_array( $args['recipients'] ) ) {
			$recipient = (int) reset( $args['recipients'] );
		}
		if ( $sender && $recipient && self::is_blocked_pair( $sender, $recipient ) ) {
			return false;
		}
		return $can;
	}

	/* ---- profile header Block button ------------------------------------ */

	public static function header_button() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		$me     = (int) get_current_user_id();
		$target = (int) bp_displayed_user_id();
		if ( ! $target || $me === $target || user_can( $target, 'manage_options' ) ) {
			return;
		}
		$blocked = self::has_blocked( $me, $target );
		$nonce   = wp_create_nonce( 'csm_bl_nonce' );
		$label   = $blocked ? 'Unblock' : 'Block';
		$next    = $blocked ? 'unblock' : 'block';
		printf(
			'<li class="generic-button csm-bl-wrap"><button type="button" class="button csm-bl-btn" data-target="%d" data-do="%s" data-nonce="%s">%s</button></li>',
			$target, esc_attr( $next ), esc_attr( $nonce ), esc_html( $label )
		);
	}

	/* ---- front-end assets ----------------------------------------------- */

	/** Enqueue the block CSS/JS and hand the small config to JS. */
	public static function assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		Assets::style( 'block', 'assets/css/block.css' );
		// csmConfirm/csmToast replace the browser dialogs this file used to call.
		Assets::style( 'app-screens', 'assets/css/app-screens.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'ui-dialog', 'assets/js/ui-dialog.js' );
		Assets::script( 'block', 'assets/js/block.js', array( 'cashaadi-ui-dialog' ) );
		$members = function_exists( 'bp_get_members_directory_permalink' ) ? bp_get_members_directory_permalink() : home_url( '/' );
		wp_add_inline_script(
			'cashaadi-block',
			'window.CASHAADI_BLOCK=' . wp_json_encode( array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'membersUrl' => $members,
			) ) . ';',
			'before'
		);
	}

	/* ---- Settings -> Blocked screen ------------------------------------- */

	public static function blocked_list_html( $owner_id ) {
		global $wpdb;
		$t   = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT blocked_id FROM $t WHERE blocker_id = %d ORDER BY created_at DESC", (int) $owner_id
		) ) );
		$nonce = wp_create_nonce( 'csm_bl_nonce' );
		ob_start();
		echo '<div class="csm-bl-list"><h2>Blocked members</h2>';
		if ( empty( $ids ) ) {
			echo '<p>You have not blocked anyone.</p></div>';
			return ob_get_clean();
		}
		foreach ( $ids as $mid ) {
			$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $mid ) : get_the_author_meta( 'display_name', $mid );
			$link = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $mid ) : '';
			echo '<div class="csm-bl-row">' . get_avatar( $mid, 44 );
			echo '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
			echo '<button type="button" class="button csm-bl-btn" data-target="' . (int) $mid . '" data-do="unblock" data-nonce="' . esc_attr( $nonce ) . '">Unblock</button></div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/** Shortcode fallback: [csm_blocked_list] on any page. */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return self::blocked_list_html( get_current_user_id() );
	}

	/** Settings -> Blocked sub-nav. */
	public static function settings_subnav() {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'settings' ) || ! is_user_logged_in() ) {
			return;
		}
		$slug        = function_exists( 'bp_get_settings_slug' ) ? bp_get_settings_slug() : 'settings';
		$user_url    = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : '';
		$parent_url  = $user_url ? trailingslashit( $user_url . $slug ) : '';
		bp_core_new_subnav_item( array(
			'name'            => 'Blocked',
			'slug'            => 'blocked',
			'parent_slug'     => $slug,
			'parent_url'      => $parent_url,
			'screen_function' => array( __CLASS__, 'settings_screen' ),
			'position'        => 50,
			'user_has_access' => bp_is_my_profile(),
		) );
	}

	public static function settings_screen() {
		add_action( 'bp_template_content', array( __CLASS__, 'settings_screen_content' ) );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	public static function settings_screen_content() {
		// Built from escaped values + trusted static markup in blocked_list_html().
		echo self::blocked_list_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
