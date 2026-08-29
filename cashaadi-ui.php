<?php
/**
 * Plugin Name:       CAShaadi UI
 * Plugin URI:        https://cashaadi.in
 * Description:       Premium member-area UI layer for CAShaadi — bottom-nav app shell, profile-completion wizard, and screen restyles. Progressive enhancement over BuddyPress; changes no data, validation, or completion logic.
 * Version:           0.17.0
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

use CAShaadi\Core\Assets;
use CAShaadi\Core\Migrator;
use CAShaadi\Modules\ProfileEdit\FieldLogic;
use CAShaadi\Modules\Analytics\Analytics;
use CAShaadi\Modules\AppShell\AppShell;
use CAShaadi\Modules\Site\Site;
use CAShaadi\Modules\Premium\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHAADI_UI_VER', '0.17.0' );
define( 'CASHAADI_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHAADI_UI_DIR', plugin_dir_path( __FILE__ ) );

// Core layer (see docs/ARCHITECTURE.md). Autoloading a class defines nothing on
// its own — core is a library that modules call; requiring it changes no behaviour.
require_once CASHAADI_UI_DIR . 'includes/autoload.php';

// Install/upgrade custom tables in one place. Inert until a module registers a
// schema (none yet), so this is a safe no-op today.
add_action( 'init', array( Migrator::class, 'run' ) );

// --- Modules ------------------------------------------------------------
// Profile-edit field logic & guards (partial-save, gender/email lock, bio
// plain, age-sync, height guard). Mirrors WPCode #11624/#11621/#11619/#11611/
// #11797/#11625; runs safely alongside them until those snippets are disabled.
// Guarded so a class-resolution problem degrades gracefully instead of fataling
// the whole site.
if ( class_exists( 'CAShaadi\\Modules\\ProfileEdit\\FieldLogic' ) ) {
	FieldLogic::register();
}

// Analytics & social meta (GA4, Meta Pixel, OG image, avatar alt). Gated OFF by
// default (Config::analytics_enabled) so it can't double-fire alongside the
// active WPCode analytics snippets; enable via wp-config in the same change that
// disables #12084/#12091/#12112/#12073/#11697.
if ( class_exists( 'CAShaadi\\Modules\\Analytics\\Analytics' ) ) {
	Analytics::register();
}

// App shell — new member-area UI (mobile bottom nav for now; additive, hidden on
// the focused wizard). Net-new, not a snippet migration.
if ( class_exists( 'CAShaadi\\Modules\\AppShell\\AppShell' ) ) {
	AppShell::register();
}

// Site-wide tweaks (comments off, WC greeting, noindex member pages, pricing
// redirect). Mirrors WPCode #11242/#11638/#11696/#11626; idempotent, so safe
// alongside them until those snippets are disabled.
if ( class_exists( 'CAShaadi\\Modules\\Site\\Site' ) ) {
	Site::register();
}

// Premium (upgrade button; gates/checkout to follow). Gated OFF by default
// (Config::premium_enabled) so it can't double the active premium snippets;
// enable via wp-config in the same change that disables them.
if ( class_exists( 'CAShaadi\\Modules\\Premium\\Premium' ) ) {
	Premium::register();
}

/**
 * Site-wide front-end fixes (all pages, not just member screens).
 * Currently: the BuddyX off-canvas mobile-menu fix migrated from WPCode #11641.
 */
add_action( 'wp_enqueue_scripts', function () {
	Assets::style( 'site', 'assets/css/site.css' );
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
		Assets::style( 'profile-edit', 'assets/css/profile-edit.css' );

		Assets::script( 'profile-wizard', 'assets/js/profile-wizard.js' );
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
		// Confirm the core layer autoloaded (walking-skeleton signal for the
		// migration; harmless if the classes are somehow missing).
		$core = class_exists( 'CAShaadi\\Core\\Config' ) ? 'core loaded' : 'core MISSING';
		echo '<div class="notice notice-info is-dismissible"><p><strong>CAShaadi UI</strong> is active — member-area UI is served from this plugin (v'
			. esc_html( CASHAADI_UI_VER ) . ' · ' . esc_html( $core ) . ').</p></div>';
	}
} );
