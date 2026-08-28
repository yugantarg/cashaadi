<?php
/**
 * Plugin Name:       CAShaadi UI
 * Plugin URI:        https://cashaadi.in
 * Description:       Premium member-area UI layer for CAShaadi — bottom-nav app shell, profile-completion wizard, and screen restyles. Progressive enhancement over BuddyPress; changes no data, validation, or completion logic.
 * Version:           0.2.0
 * Author:            CAShaadi
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       cashaadi-ui
 *
 * NOTE: This plugin only ENHANCES the existing BuddyPress UI. It never writes to
 * the database and never alters saving, validation, the completion gate, age-sync,
 * or membership logic — those stay in BuddyPress / PMPro / the existing helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHAADI_UI_VER', '0.2.0' );
define( 'CASHAADI_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHAADI_UI_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Asset version — file mtime in debug so cache never masks a deploy; static VER in prod.
 */
function cashaadi_ui_asset_ver( $rel_path ) {
	$abs = CASHAADI_UI_DIR . ltrim( $rel_path, '/' );
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $abs ) ) {
		return (string) filemtime( $abs );
	}
	return CASHAADI_UI_VER;
}

/**
 * Site-wide front-end fixes (all pages, not just member screens).
 * Currently: the BuddyX off-canvas mobile-menu fix migrated from WPCode #11641.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'cashaadi-site',
		CASHAADI_UI_URL . 'assets/css/site.css',
		array(),
		cashaadi_ui_asset_ver( 'assets/css/site.css' )
	);
}, 20 );

/**
 * Front-end assets, scoped to BuddyPress member screens only.
 */
add_action( 'wp_enqueue_scripts', function () {

	// Bail unless we're on a BuddyPress member page.
	if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
		return;
	}

	// --- Profile-completion wizard (xProfile edit only) ---------------------
	// Progressive enhancement of the native edit form: the JS self-injects its
	// own styles + fonts and no-ops if the form isn't present, so it is safe
	// site-wide but we scope it tightly anyway.
	if ( function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit() ) {

		// Mobile fixes for the edit form (DOB selects + hide auto-computed Age).
		// Migrated from WPCode #11641. The wizard self-injects its own look on
		// top of this; these are plain fixes that also apply if the JS no-ops.
		wp_enqueue_style(
			'cashaadi-profile-edit',
			CASHAADI_UI_URL . 'assets/css/profile-edit.css',
			array(),
			cashaadi_ui_asset_ver( 'assets/css/profile-edit.css' )
		);

		wp_enqueue_script(
			'cashaadi-profile-wizard',
			CASHAADI_UI_URL . 'assets/js/profile-wizard.js',
			array(),
			cashaadi_ui_asset_ver( 'assets/js/profile-wizard.js' ),
			true // footer
		);
	}

	// Future increments enqueue here:
	//   - assets/css/app-shell.css   (bottom nav + member layout)
	//   - assets/css/screens.css     (Matches / Messages / Settings / Profile)
	//   - assets/js/discover.js      (browse/like/pass)

}, 20 );

/**
 * A tiny admin notice confirming the plugin is the active UI source (walking
 * skeleton signal). Remove once the migration off WPCode is complete.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'plugins' === $screen->id ) {
		echo '<div class="notice notice-info is-dismissible"><p><strong>CAShaadi UI</strong> is active — member-area UI is served from this plugin (v' . esc_html( CASHAADI_UI_VER ) . ').</p></div>';
	}
} );
