<?php
/**
 * Global functions the rest of the site calls by name.
 *
 * These were defined by the `cashaadi()` mu-plugin. They stay GLOBAL because
 * their callers are outside this plugin and cannot be changed in the same
 * breath: the child theme calls csm_user_profile_is_complete() from
 * functions.php and from two BuddyPress template overrides, and the Discover
 * engine calls cashaadi() by name in seven places.
 *
 * BOTH are function_exists-guarded, which is what makes this cutover safe.
 * Mu-plugins load before regular plugins, so while
 * wp-content/mu-plugins/cashaadi-discovery.php is still present ITS definitions
 * win and everything here is inert — no redeclare, no behaviour change. Deleting
 * that file is what promotes these. See docs/BUILD-ORDER.md for the ordering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cashaadi' ) ) {
	/**
	 * Accessor for the tray/likes engine: cashaadi()->get_week_id() etc.
	 *
	 * @return \CAShaadi\Core\Engine
	 */
	function cashaadi() {
		return \CAShaadi\Core\Engine::get_instance();
	}
}

if ( ! function_exists( 'csm_user_profile_is_complete' ) ) {
	/**
	 * Canonical profile-completion check — TRUE when the profile is complete.
	 *
	 * Wraps the child theme's cashaadi_has_missing_required_fields() so the
	 * answer stays byte-for-byte what it was. Returns true when that helper is
	 * absent, matching the mu-plugin: a missing helper must not lock members out
	 * of screens gated on completion.
	 *
	 * @param  int $user_id Defaults to the current user.
	 * @return bool
	 */
	function csm_user_profile_is_complete( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return false;
		}
		if ( function_exists( 'cashaadi_has_missing_required_fields' ) ) {
			return ! cashaadi_has_missing_required_fields( $user_id );
		}
		return true;
	}
}
