<?php
/**
 * Plugin Name:       CAShaadi UI
 * Plugin URI:        https://cashaadi.in
 * Description:       Premium member-area UI layer for CAShaadi — bottom-nav app shell, profile-completion wizard, and screen restyles. Progressive enhancement over BuddyPress; changes no data, validation, or completion logic.
 * Version:           0.59.0
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
use CAShaadi\Modules\Photos\Photos;
use CAShaadi\Modules\Photos\Gallery;
use CAShaadi\Modules\Photos\PhotoOnboarding;
use CAShaadi\Modules\Photos\LegacyImport;
use CAShaadi\Modules\Verification\Verification;
use CAShaadi\Modules\Discover\Discover;
use CAShaadi\Modules\Matches\Matches;
use CAShaadi\Modules\Block\Block;
use CAShaadi\Modules\Emails\Queue;
use CAShaadi\Modules\Emails\Monitor;
use CAShaadi\Modules\Admin\Dashboard;
use CAShaadi\Modules\CaVerify\CaVerify;
use CAShaadi\Modules\CaVerify\CaCron;
use CAShaadi\Modules\Otp\Otp;
use CAShaadi\Modules\ProfileTools\ProfileTools;
use CAShaadi\Modules\Signup\Signup;
use CAShaadi\Modules\Settings\Settings;
use CAShaadi\Modules\ProfileScreen\ProfileScreen;
use CAShaadi\Modules\Onboarding\PhotoOptions;
use CAShaadi\Modules\Welcome\Welcome;
use CAShaadi\Modules\Media\MediaQuality;
use CAShaadi\Modules\Tracking\TrackingSettings;
use CAShaadi\Modules\Discover\DiscoverScreen;
use CAShaadi\Modules\Requests\RequestsScreen;
use CAShaadi\Modules\ProfileScreen\ProfileApp;
use CAShaadi\Modules\ProfileEdit\ProfileEditScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHAADI_UI_VER', '0.59.0' );
define( 'CASHAADI_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHAADI_UI_DIR', plugin_dir_path( __FILE__ ) );

// Core layer (see docs/ARCHITECTURE.md). Autoloading a class defines nothing on
// its own — core is a library that modules call; requiring it changes no behaviour.
require_once CASHAADI_UI_DIR . 'includes/autoload.php';

// Install/upgrade custom tables in one place. Schemas are registered by the
// table-owning modules (premium, photos, block, emails) only when their flag is
// on, and each handle installs independently — so this is a no-op until a gated
// module is enabled, then installs just that module's table(s).
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

// Settings screen — the app-style grouped settings hub from the approved design
// (Account / Privacy & photos / Account status, then Log out + Delete account).
// Net-new UI, so ungated — but it only ADDS markup on the member's own settings
// screen and is shown on mobile only; it saves nothing and every row points at
// an existing verified screen, so it cannot strand anyone.
if ( class_exists( 'CAShaadi\\Modules\\Settings\\Settings' ) ) {
	Settings::register();
}

// Profile section rows — one row per xProfile group with its completion state,
// replacing the View/Edit/Change-Photo tab strip on the member's own profile.
// Same conservative shape as the Settings hub: markup only, mobile only, every
// row points at a real /profile/edit/group/{id}/ screen.
if ( class_exists( 'CAShaadi\\Modules\\ProfileScreen\\ProfileScreen' ) ) {
	ProfileScreen::register();
}

// Blur choice on the avatar screen. This no longer gates anything: the "photo is
// mandatory" requirement moves into the /welcome/ state machine as step 1, so it
// costs no page reload.
if ( class_exists( 'CAShaadi\\Modules\\Onboarding\\PhotoOptions' ) ) {
	PhotoOptions::register();
}

// /welcome/ — onboarding as one client-rendered route. Endpoints only for now;
// the page template follows. See docs/WELCOME-SPEC.md.
if ( class_exists( 'CAShaadi\\Modules\\Welcome\\Welcome' ) ) {
	Welcome::register();
}

// Photo resolution. Plugin-side mirror of WPCode #11813 (HD Avatars), which
// already sets these; identical values at a lower priority, so the two agree
// while both are live and nothing changes when the snippet is retired.
if ( class_exists( 'CAShaadi\\Modules\\Media\\MediaQuality' ) ) {
	MediaQuality::register();
}

// Tracking credentials, editable at Settings -> CA Shaadi Tracking rather than
// in wp-config. Constants still win where defined.
if ( class_exists( 'CAShaadi\\Modules\\Tracking\\TrackingSettings' ) ) {
	TrackingSettings::register();
}

// Discover as a full scrollable profile card, rendered as its own document.
// Presentation only: the tray, the weekly refill and like/pass still belong to
// the Discover module and #11600.
if ( class_exists( 'CAShaadi\\Modules\\Discover\\DiscoverScreen' ) ) {
	DiscoverScreen::register();
}

// Requests: received, sent and profile viewers on one screen. The viewers list
// is premium-gated ON THE SERVER — free members are never sent an identity.
if ( class_exists( 'CAShaadi\\Modules\\Requests\\RequestsScreen' ) ) {
	RequestsScreen::register();
}

// Profile hub at /profile/ — completion first, editors and the public view
// linked from it.
if ( class_exists( 'CAShaadi\\Modules\\ProfileScreen\\ProfileApp' ) ) {
	ProfileApp::register();
}

// Profile edit as an app screen — one section at a time, reached from the hub.
// Replaces the draft AJAX wizard unhooked in v0.58.0.
if ( class_exists( 'CAShaadi\\Modules\\ProfileEdit\\ProfileEditScreen' ) ) {
	ProfileEditScreen::register();
}

// Site-wide tweaks (noindex member pages, pricing redirect, support footer).
// Mirrors WPCode #11696/#11626/#11691; idempotent, so safe alongside them until
// those snippets are disabled. (#11242/#11638 were NOT migrated — already off.)
if ( class_exists( 'CAShaadi\\Modules\\Site\\Site' ) ) {
	Site::register();
}

// Premium (upgrade button; gates/checkout to follow). Gated OFF by default
// (Config::premium_enabled) so it can't double the active premium snippets;
// enable via wp-config in the same change that disables them.
if ( class_exists( 'CAShaadi\\Modules\\Premium\\Premium' ) ) {
	Premium::register();
}

// Photos: plain avatar filters (local default #11617, HD sizes #11813) — idempotent,
// safe alongside their snippets. The privacy/request/NSFW resolver (#11770/#11798/
// #12119) is gated by Config::photos_enabled() and added later.
if ( class_exists( 'CAShaadi\\Modules\\Photos\\Photos' ) ) {
	Photos::register();
}

// Verification display: verified-CA badge + OTP checklist item. Gated OFF
// (Config::verification_enabled) so it can't double the active #11701/#11682.
if ( class_exists( 'CAShaadi\\Modules\\Verification\\Verification' ) ) {
	Verification::register();
}

// Photo gallery / onboarding / legacy import (#11822/#11771, #11838/#11690,
// #11861). Each gated by Config::photos_enabled(); dormant until cutover.
if ( class_exists( 'CAShaadi\\Modules\\Photos\\Gallery' ) ) {
	Gallery::register();
}
if ( class_exists( 'CAShaadi\\Modules\\Photos\\PhotoOnboarding' ) ) {
	PhotoOnboarding::register();
}
if ( class_exists( 'CAShaadi\\Modules\\Photos\\LegacyImport' ) ) {
	LegacyImport::register();
}

// Discover — weekly like/pass tray + engine (#11599/#11600/#11601/#11602/#11605/
// #11630/#11675/#11681/#11680). Depends on the cashaadi() mu-plugin. Gated OFF
// (Config::discover_enabled); enable in the same change that disables those.
if ( class_exists( 'CAShaadi\\Modules\\Discover\\Discover' ) ) {
	Discover::register();
}

// Matches — Requests-Sent sub-tab (#11637) + match emails (#11694). Gated OFF
// (Config::matches_enabled); enable alongside Discover at cutover.
if ( class_exists( 'CAShaadi\\Modules\\Matches\\Matches' ) ) {
	Matches::register();
}

// Block user (#11810): mutual-hiding block list + guards; owns wp_csm_blocks.
// Gated OFF (Config::block_enabled); enable in the same change that disables #11810.
if ( class_exists( 'CAShaadi\\Modules\\Block\\Block' ) ) {
	Block::register();
}

// Reminder email queue (#11732 engine + #11733 monitor); owns wp_csm_email_queue
// + the hourly csm_remail_cron event. Gated OFF (Config::emails_enabled); enable
// in the same change that disables #11732/#11733. Needs the Admin dashboard
// (csm_profile_pending_label) active.
if ( class_exists( 'CAShaadi\\Modules\\Emails\\Queue' ) ) {
	Queue::register();
}
if ( class_exists( 'CAShaadi\\Modules\\Emails\\Monitor' ) ) {
	Monitor::register();
}

// Sales admin dashboard (#11688), read-only. Gated OFF (Config::admin_enabled);
// enable in the same change that disables #11688. Provides the global
// csm_profile_pending_label the reminder engine calls by name.
if ( class_exists( 'CAShaadi\\Modules\\Admin\\Dashboard' ) ) {
	Dashboard::register();
}

// CA document AI verification (#11815 engine/admin + #12113 cron/email). Gated
// OFF (Config::ca_verify_enabled); OpenAI key via Secrets (falls back to the
// csm_av_options option). Enable in the same change that disables #11815/#12113.
if ( class_exists( 'CAShaadi\\Modules\\CaVerify\\CaVerify' ) ) {
	CaVerify::register();
}
if ( class_exists( 'CAShaadi\\Modules\\CaVerify\\CaCron' ) ) {
	CaCron::register();
}

// Phone OTP verification (#11618). Gated OFF (Config::otp_enabled) AND needs the
// MSG91 constants in wp-config (Core\Secrets). Enable in the same change that
// disables #11618.
if ( class_exists( 'CAShaadi\\Modules\\Otp\\Otp' ) ) {
	Otp::register();
}

// Profile tools — completion meter (#11560), monthly age refresh (#11760),
// "Created For" field (#11812). Gated OFF (Config::profile_tools_enabled).
if ( class_exists( 'CAShaadi\\Modules\\ProfileTools\\ProfileTools' ) ) {
	ProfileTools::register();
}

// Signup — email activation + auto-login (#11583), skip username (#11842). Gated
// OFF (Config::signup_enabled).
if ( class_exists( 'CAShaadi\\Modules\\Signup\\Signup' ) ) {
	Signup::register();
}

/**
 * Site-wide front-end fixes (all pages, not just member screens).
 * Currently: the BuddyX off-canvas mobile-menu fix migrated from WPCode #11641.
 */
add_action( 'wp_enqueue_scripts', function () {
	Assets::style( 'site', 'assets/css/site.css' );
	// Mobile-menu double-toggle fix (#11674). Idempotent JS, safe site-wide
	// alongside the still-active snippet until it is disabled.
	Assets::script( 'site-menu', 'assets/js/site-menu.js' );
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

		/*
		 * profile-wizard.js is NOT enqueued.
		 *
		 * It is the Phase-3 "AJAX no-reload wizard", and its own header says
		 * "[STAGING DRAFT] ... NOT YET LIVE-TESTED", with two // VERIFY markers
		 * still unresolved. It was running anyway, and it had taken the form over
		 * (verified live: form carried data-csm-ajax="1").
		 *
		 * It is also the cause of a bug reported three times: "when the backend
		 * group changes I am not able to go back". Line 186 advanced groups with
		 * history.replaceState() rather than pushState(), so moving 1 -> 2 -> 3
		 * REPLACED the single history entry each time and Back left profile-edit
		 * altogether. Exactly the defect /welcome/ hit, where the fix was to seed
		 * real history entries.
		 *
		 * It is not repaired here because the flow no longer wants it. /welcome/
		 * owns onboarding now; reaching an editor from the /profile/ hub means
		 * "change one section and come back", which the native form plus the
		 * "Back to profile" link already do. A no-reload chain through all seven
		 * groups solves a problem this app no longer has.
		 *
		 * The file stays in the repo. To resurrect it, fix the history handling
		 * first and clear both // VERIFY markers.
		 */
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

		// Cutover-flag status — shows exactly which module flags PHP actually sees,
		// so a wp-config flag that didn't take is obvious at a glance.
		$flags = array(
			'ANALYTICS', 'PREMIUM', 'PHOTOS', 'VERIFICATION', 'DISCOVER', 'MATCHES',
			'BLOCK', 'EMAILS', 'ADMIN', 'CA_VERIFY', 'OTP', 'PROFILE_TOOLS', 'SIGNUP',
		);
		$on = array();
		foreach ( $flags as $f ) {
			$c = 'CASHAADI_' . $f . '_ENABLED';
			if ( defined( $c ) && constant( $c ) ) {
				$on[] = strtolower( $f );
			}
		}
		$flag_txt = $on ? ( 'flags ON: ' . implode( ', ', $on ) ) : 'no cutover flags set';

		echo '<div class="notice notice-info is-dismissible"><p><strong>CAShaadi UI</strong> is active — member-area UI is served from this plugin (v'
			. esc_html( CASHAADI_UI_VER ) . ' · ' . esc_html( $core ) . ' · ' . esc_html( $flag_txt ) . ').</p></div>';
	}
} );
