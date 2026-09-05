<?php
/**
 * ThemeCompat — the child theme's logic, moved into the plugin.
 *
 * `buddyx-child/functions.php` accumulated ~650 lines that have nothing to do
 * with presentation: username hashing at registration, login redirects, BuddyPress
 * navigation renames, a members-directory filter. None of it is theme work, and
 * living in a theme means it is invisible to version control here, breaks if the
 * theme is ever switched, and cannot be reviewed alongside the code that depends
 * on it — Core\globals.php already calls one of its functions by name.
 *
 * WHAT STAYS IN THE THEME, deliberately:
 *   - buddyx_child_enqueue_styles() and style.css — actual theme work.
 *   - The five BuddyPress template overrides. Templates belong to themes; moving
 *     them would mean this plugin taking over template resolution.
 *
 * MIGRATION ORDER. These are class methods, not global functions, so there is no
 * function_exists trick available: if the theme's copies stayed hooked, BOTH would
 * run and every filter would apply twice. So register() UNHOOKS the theme's
 * versions by name first — they are all named functions, so remove_filter reaches
 * them — and installs these in their place. One behaviour at a time, and
 * reversible by deleting this module.
 *
 * Every port below is byte-faithful to what the theme did. That is not pedantry:
 * a first draft of this file guessed at five of them and got all five wrong —
 * "Register" instead of "Create Your CAShaadi Account", an HTML asterisk instead
 * of a bare one, a stubbed submit button, a stubbed notice filter, and button
 * labels that read "Match" where the site says "Add Match".
 *
 * NOT YET MOVED, and why (see docs/BUILD-ORDER.md):
 *   - The bp_pre_user_query_construct gender filter is an anonymous closure. It
 *     cannot be guarded or unhooked by name, so it needs the theme edit and the
 *     plugin change in one step rather than two.
 *   - cashaadi_register_custom_datebox() declares a 140-line field-type class.
 *     Worth its own pass, with the missing class_exists() guard added.
 *   - cashaadi_friends_screen_notification_settings() is already dead: the
 *     Engagement module unhooks it. It should be deleted, not ported.
 */

namespace CAShaadi\Modules\ThemeCompat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ThemeCompat {

	public static function register() {
		/*
		 * TIMING IS THE WHOLE TRICK. Plugins load BEFORE the theme, so at plugin
		 * load the theme has not added its filters yet and remove_filter would
		 * find nothing to remove — leaving both copies hooked and every filter
		 * applying twice.
		 *
		 * after_setup_theme fires once functions.php has run, so by then the
		 * theme's hooks exist and can be taken out. Priority 1 because one of the
		 * functions being replaced (hide_admin_bar_for_subscribers) is itself on
		 * after_setup_theme at the default 10 — we must unhook it before it fires.
		 */
		add_action( 'after_setup_theme', array( __CLASS__, 'takeover' ), 1 );
	}

	/** Unhook the theme's versions, install ours. */
	public static function takeover() {
		/*
		 * Retire the theme's copies first. Named functions, so remove_filter can
		 * reach them; if a future theme edit renames one, its filter simply stays
		 * and doubles up — which is why the ports below are all idempotent.
		 */
		remove_filter( 'wp_pre_insert_user_data', 'cashaadi_set_hashed_username', 10 );
		remove_filter( 'woocommerce_login_redirect', 'cashaadi_check_user_profile_completion_and_redirect', 10 );
		remove_action( 'wp_logout', 'cashaadi_redirect_after_logout' );
		remove_filter( 'bp_get_title_parts', 'filter_bp_title_parts_for_registration_page' );
		remove_filter( 'bp_get_the_profile_field_required_label', 'change_field_required_sign_from_text_to_symbol', 10 );
		remove_filter( 'bp_nouveau_get_submit_button', 'rename_register_page_submit_button_text' );
		remove_filter( 'bp_nouveau_feedback_messages', 'filter_buddypress_template_notices_for_register_page' );
		remove_filter( 'bp_nouveau_get_user_feedback', 'cashaadi_rename_loading_friends_to_matches' );
		remove_filter( 'bp_get_add_friend_button', 'cashaadi_rename_friend_with_match' );
		remove_action( 'bp_friends_setup_nav', 'cashaadi_hide_friends_if_not_self' );
		remove_action( 'bp_setup_nav', 'cashaadi_bp_rename_friends_tab', 100 );
		remove_action( 'after_setup_theme', 'hide_admin_bar_for_subscribers' );
		remove_filter( 'bp_get_last_activity', 'cashaadi_coarse_last_active', 99 );
		remove_filter( 'bp_member_last_active', 'cashaadi_coarse_last_active', 99 );
		remove_filter( 'bp_after_has_members_parse_args', 'exclude_admins_and_current_loggedin_user_from_members_directory' );

		/*
		 * The gender filter on bp_pre_user_query_construct is an ANONYMOUS
		 * CLOSURE in the theme, so there is no name to remove it by. Ours is
		 * added alongside it and both run until the theme copy is deleted.
		 *
		 * That is safe because the operation is idempotent: the exclude list is
		 * array_unique'd, and a second identical xprofile_query clause ANDs a
		 * condition with itself. Deliberately different from every other hook
		 * here, which are unhooked rather than duplicated.
		 */

		// Registration and login.
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'hashed_username' ), 10, 4 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'logout_redirect' ) );

		// BuddyPress copy: "friends" is called "matches" throughout this site.
		add_filter( 'bp_get_title_parts', array( __CLASS__, 'register_title' ) );
		add_filter( 'bp_get_the_profile_field_required_label', array( __CLASS__, 'required_symbol' ), 10, 2 );
		add_filter( 'bp_nouveau_get_submit_button', array( __CLASS__, 'submit_button_text' ) );
		add_filter( 'bp_nouveau_feedback_messages', array( __CLASS__, 'register_notices' ) );
		add_filter( 'bp_nouveau_get_user_feedback', array( __CLASS__, 'matches_copy' ) );
		add_filter( 'bp_get_add_friend_button', array( __CLASS__, 'match_button' ) );

		// Members directory.
		add_filter( 'bp_after_has_members_parse_args', array( __CLASS__, 'directory_exclude' ) );
		add_action( 'bp_pre_user_query_construct', array( __CLASS__, 'directory_gender_filter' ), 20 );

		// Navigation.
		add_action( 'bp_friends_setup_nav', array( __CLASS__, 'hide_friends_if_not_self' ) );
		add_action( 'bp_setup_nav', array( __CLASS__, 'rename_friends_tab' ), 100 );
		add_action( 'after_setup_theme', array( __CLASS__, 'hide_admin_bar' ) );

		// Privacy: never show an exact last-seen time.
		add_filter( 'bp_get_last_activity', array( __CLASS__, 'coarse_last_active' ), 99 );
		add_filter( 'bp_member_last_active', array( __CLASS__, 'coarse_last_active' ), 99 );
	}

	/* ------------------------------------------------ registration & login */

	/**
	 * Deterministic, anonymised username: a salted SHA-256 of the email.
	 *
	 * Set at wp_pre_insert_user_data so WordPress writes the exact value with no
	 * uniqueness suffix (-2). The same email always yields the same handle, and
	 * the salt stops it being a plain email hash anyone could reverse from a
	 * known address. Ported verbatim — members' existing logins depend on this
	 * producing byte-identical output.
	 */
	public static function hashed_username( $data, $update, $user_id, $userdata ) {
		unset( $user_id, $userdata );

		if ( $update ) {
			return $data; // never rewrite an existing user's handle
		}
		if ( empty( $data['user_email'] ) ) {
			return $data;
		}

		$hash   = substr( hash( 'sha256', strtolower( trim( $data['user_email'] ) ) . wp_salt() ), 0, 12 );
		$handle = 'user_' . $hash;

		$data['user_login']    = $handle;
		$data['user_nicename'] = $handle;

		return $data;
	}

	/** Incomplete profiles land on the editor; everyone else on their profile. */
	public static function login_redirect( $redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! is_a( $user, 'WP_User' ) ) {
			return $redirect_to;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return $redirect_to;
		}
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'xprofile' ) || ! function_exists( 'bp_core_get_user_domain' ) ) {
			return $redirect_to;
		}

		$home = bp_core_get_user_domain( $user->ID );
		if ( function_exists( 'csm_user_profile_is_complete' ) && ! csm_user_profile_is_complete( $user->ID ) ) {
			return $home . 'profile/edit/';
		}
		return $home;
	}

	public static function logout_redirect() {
		wp_safe_redirect( home_url() );
		exit;
	}

	/* ------------------------------------------------------------- wording */

	public static function register_title( $parts ) {
		if ( function_exists( 'bp_is_register_page' ) && bp_is_register_page()
			&& function_exists( 'bp_get_membership_requests_required' ) && ! bp_get_membership_requests_required() ) {
			$parts = array( 'Create Your CAShaadi Account' );
		}
		return $parts;
	}

	/**
	 * A bare asterisk, not markup.
	 *
	 * BuddyPress prints this label inside its own element; returning HTML here
	 * would nest markup the templates do not expect. The theme returned '*' and
	 * so does this.
	 */
	public static function required_symbol( $required_text, $field_id ) {
		unset( $required_text, $field_id );
		return '*';
	}

	/** "Continue", not "Complete Sign Up" — registration is step one of several. */
	public static function submit_button_text( $buttons ) {
		if ( isset( $buttons['register']['attributes'] ) ) {
			$buttons['register']['attributes']['value'] = 'Continue';
		}
		return $buttons;
	}

	/** Drop BuddyPress's "request details" notice from the register page. */
	public static function register_notices( $messages ) {
		unset( $messages['request-details'] );
		return $messages;
	}

	/**
	 * "Friends" is a dating-app word; this site matches people.
	 *
	 * Matched on the exact English strings BuddyPress emits, which is fragile by
	 * nature — a BuddyPress rewording silently restores "friends". Health checks
	 * the plugin's own suppressions, not these; a translation file would be the
	 * durable fix if the copy ever matters more than it does today.
	 */
	public static function matches_copy( $messages ) {
		if ( ! isset( $messages['message'] ) ) {
			return $messages;
		}
		$map = array(
			'Loading your friends. Please wait.'      => 'Loading your matches. Please wait.',
			'You have no pending friendship requests.' => 'You have no pending match requests.',
			'Sorry, no members were found.'            => 'Sorry, no matches were found.',
		);
		if ( isset( $map[ $messages['message'] ] ) ) {
			$messages['message'] = $map[ $messages['message'] ];
		}
		return $messages;
	}

	/**
	 * The friend button, in this site's language.
	 *
	 * Exact-match mapping rather than a str_replace, and link_title is set
	 * alongside link_text, because that is what the theme did — a looser
	 * replacement produced "Match" where the site says "Add Match".
	 */
	public static function match_button( $args ) {
		$map = array(
			'Add Friend'                => array( 'Add Match', 'Add Match' ),
			'Cancel Friendship Request' => array( 'Cancel Match Request', 'Cancel Match Requested' ),
			'Friendship Requested'      => array( 'Match Request Sent', 'Match Request Sent' ),
			'Cancel Friendship'         => array( 'Withdraw Match Request', 'Withdraw Match Request' ),
		);
		if ( isset( $args['link_text'], $map[ $args['link_text'] ] ) ) {
			$pair               = $map[ $args['link_text'] ];
			$args['link_text']  = $pair[0];
			$args['link_title'] = $pair[1];
		}
		return $args;
	}

	/* --------------------------------------------------- members directory */

	/** Admins and the viewer themselves never appear in the directory. */
	public static function directory_exclude( $args ) {
		if ( ! function_exists( 'bp_is_members_directory' ) || ! bp_is_members_directory() ) {
			return $args;
		}

		$exclude = (array) get_users( array( 'role' => 'Administrator', 'fields' => 'ID' ) );
		if ( is_user_logged_in() ) {
			$exclude[] = get_current_user_id();
		}
		if ( ! $exclude ) {
			return $args;
		}

		$args['exclude'] = empty( $args['exclude'] )
			? $exclude
			: array_merge( (array) $args['exclude'], $exclude );

		return $args;
	}

	/**
	 * Show only opposite-gender members, and never admins or yourself.
	 *
	 * Runs on bp_pre_user_query_construct, which hands over the BP_User_Query
	 * before it executes — the only point where an xprofile_query clause can be
	 * added to the directory.
	 *
	 * Two deliberate escape hatches, both ported as-is: an INCOMPLETE profile
	 * sees everyone (otherwise a member who has not set their own gender would
	 * face an empty directory and no way to understand why), and admins and
	 * logged-out visitors are never filtered.
	 *
	 * Idempotent on purpose — see the note in takeover() about the theme's
	 * closure, which cannot be unhooked and so runs alongside this until the
	 * theme file is stripped.
	 */
	public static function directory_gender_filter( $query ) {
		if ( ! function_exists( 'bp_is_members_directory' ) || ! bp_is_members_directory() ) {
			return; // widgets and profile tabs are unaffected
		}

		$exclude = isset( $query->query_vars['exclude'] ) ? (array) $query->query_vars['exclude'] : array();
		$exclude = array_merge( $exclude, (array) get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) ) );
		if ( is_user_logged_in() && function_exists( 'bp_loggedin_user_id' ) ) {
			$exclude[] = bp_loggedin_user_id();
		}
		$query->query_vars['exclude'] = array_unique( array_map( 'intval', $exclude ) );

		if ( ! is_user_logged_in() || current_user_can( 'administrator' ) ) {
			return;
		}

		// An incomplete profile sees everyone.
		if ( function_exists( 'csm_user_profile_is_complete' ) && ! csm_user_profile_is_complete( get_current_user_id() ) ) {
			return;
		}

		if ( ! function_exists( 'xprofile_get_field_data' ) ) {
			return;
		}
		$gender = strtolower( (string) xprofile_get_field_data( 'Gender', bp_loggedin_user_id() ) );
		if ( 'male' === $gender ) {
			$want = 'Female';
		} elseif ( 'female' === $gender ) {
			$want = 'Male';
		} else {
			return; // unset or unrecognised → show everyone
		}

		$xq   = isset( $query->query_vars['xprofile_query'] ) ? $query->query_vars['xprofile_query'] : array();
		$xq[] = array(
			'field'   => 'Gender',
			'value'   => $want,
			'compare' => '=',
		);
		$query->query_vars['xprofile_query'] = $xq;
	}

	/* ---------------------------------------------------------- navigation */

	/** A member's match list is their own business. */
	public static function hide_friends_if_not_self() {
		if ( ! function_exists( 'bp_is_my_profile' ) || bp_is_my_profile() || is_super_admin() ) {
			return;
		}
		if ( function_exists( 'bp_core_remove_nav_item' ) ) {
			bp_core_remove_nav_item( 'friends' );
		}
	}

	public static function rename_friends_tab() {
		global $bp;
		if ( ! isset( $bp->members->nav ) ) {
			return;
		}
		$bp->members->nav->edit_nav( array( 'name' => __( 'Matches', 'cashaadi-ui' ) ), 'friends' );
		$bp->members->nav->edit_nav( array( 'name' => __( 'My Matches', 'cashaadi-ui' ) ), 'my-friends', 'friends' );

		// Export Data is a GDPR tool that confuses members here.
		if ( function_exists( 'bp_core_remove_subnav_item' ) ) {
			bp_core_remove_subnav_item( 'settings', 'data' );
		}
	}

	public static function hide_admin_bar() {
		if ( current_user_can( 'subscriber' ) ) {
			show_admin_bar( false );
		}
	}

	/* ------------------------------------------------------------- privacy */

	/**
	 * Never publish an exact last-seen time.
	 *
	 * "Active today" or nothing. On a matrimonial site an exact timestamp tells
	 * anyone watching a profile when that person is online, which is a safety
	 * matter rather than a preference.
	 */
	public static function coarse_last_active( $string ) {
		unset( $string );

		$user_id = 0;
		if ( function_exists( 'bp_get_member_user_id' ) && bp_get_member_user_id() ) {
			$user_id = bp_get_member_user_id();
		} elseif ( function_exists( 'bp_displayed_user_id' ) && bp_displayed_user_id() ) {
			$user_id = bp_displayed_user_id();
		}
		if ( ! $user_id || ! function_exists( 'bp_get_user_last_activity' ) ) {
			return '';
		}

		$last = bp_get_user_last_activity( $user_id );
		if ( empty( $last ) ) {
			return '';
		}
		$ts = strtotime( $last );
		return ( $ts && ( time() - $ts ) < DAY_IN_SECONDS ) ? __( 'Active today', 'cashaadi-ui' ) : '';
	}
}
