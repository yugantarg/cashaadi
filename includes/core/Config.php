<?php
/**
 * Config — the single source of truth for CAShaadi identifiers.
 *
 * Every field id, group order, membership level and option key that today is
 * hardcoded across ~20 WPCode snippets lives here instead. Change it once.
 *
 * Values are verified against the WPCode export (2026-08-29):
 *   - premium level/product ...... #11795
 *   - xProfile field ids ......... #11611, #11621, #11701, #11815, #11618, wizard #12132
 *   - group edit order ........... #11629 / wizard #12132
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {

	/* ---- xProfile field ids -------------------------------------------- */
	const FIELD_PHONE         = 277; // tel — drives OTP (#11618)
	const FIELD_AGE           = 286; // auto-computed from DOB (#11611)
	const FIELD_GENDER        = 299; // locked after signup (#11621)
	const FIELD_HEIGHT        = 228; // cm, slider in wizard (#11797/#12132)
	const FIELD_BIO           = 496; // plain textarea (#11619)
	const FIELD_DOB           = 586; // datebox (#11611/#11641)
	const FIELD_CA_DOC        = 484; // ICAI document upload (#11701/#11815)
	const FIELD_QUALIFICATION = 571; // "CA" / "CA Inter" (#11701)

	/* ---- xProfile group edit order (photo step handled separately) ----- */
	const GROUP_ORDER = array( 1, 7, 6, 4, 9, 8, 10 );

	/* ---- membership / commerce ----------------------------------------- */
	const PMPRO_PREMIUM_LEVEL = 2;     // PMPro premium level id (#11795)
	const WC_PREMIUM_PRODUCT  = 11566; // WooCommerce "Premium Membership (1 Year)"

	/* ---- analytics (public ids) ---------------------------------------- */
	const FB_PIXEL_ID     = '942856688093538';          // Meta "CA Shaadi Website Pixel" (#12084)
	const GA4_MEASUREMENT = 'G-VJW0VMS7KC';             // loaded by Site Kit; events push to dataLayer (#12112)
	// Google Ads conversion tracking (shared by the owner 2026-09-01). The Google
	// tag itself is already on the site via Site Kit, so we only register the Ads
	// conversion ID against it — we do NOT add the G-2EYMW7BYEJ property that came
	// with the tag, which would collect in parallel with GA4_MEASUREMENT above.
	const GADS_CONVERSION_ID = 'AW-1014629759';
	const GADS_LEAD_LABEL    = 'AW-1014629759/rXtbCPaksbsDEP-K6OMD'; // "Submit lead form"
	const OG_IMAGE_ID     = 12072;                       // default social share image attachment (#12073)
	const OG_IMAGE_URL    = 'https://staging.cashaadi.in/wp-content/uploads/2026/08/cashaadi-og-share.png';

	/* ---- misc site config ---------------------------------------------- */
	const SUPPORT_EMAIL = 'support@cashaadi.in'; // shown in the support footer (#11691)

	/* ---- photos -------------------------------------------------------- */
	const DEFAULT_AVATAR_ID  = 11616; // local default avatar attachment (#11617)
	const DEFAULT_AVATAR_URL = 'https://staging.cashaadi.in/wp-content/uploads/2026/06/abstract-user-flat-4.png';

	/**
	 * Photos "hard gates" master switch (private-photo blur / photo-request /
	 * NSFW mask — the three that filter bp_core_fetch_avatar_url). OFF unless
	 * wp-config sets CASHAADI_PHOTOS_ENABLED === true. Flip it on in the SAME
	 * change that disables #11770/#11798/#12119. The plain avatar filters
	 * (default avatar #11617, HD sizes #11813) are NOT gated by this.
	 */
	public static function photos_enabled() {
		return defined( 'CASHAADI_PHOTOS_ENABLED' ) && CASHAADI_PHOTOS_ENABLED;
	}

	/**
	 * Verification display master switch (verified-CA badge #11701 + OTP checklist
	 * item #11682). OFF unless wp-config sets CASHAADI_VERIFICATION_ENABLED === true.
	 * Flip on in the SAME change that disables #11701/#11682 (they inject via
	 * JS/REST, so both-active would double). The OTP snippet #11618 (MSG91) is NOT
	 * part of this — it stays in WPCode until the key moves to a wp-config constant.
	 */
	public static function verification_enabled() {
		return defined( 'CASHAADI_VERIFICATION_ENABLED' ) && CASHAADI_VERIFICATION_ENABLED;
	}

	/** Local default avatar URL, resolved from the attachment (per-env) with a fallback. */
	public static function default_avatar_url() {
		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( self::DEFAULT_AVATAR_ID ) : '';
		return $url ? $url : self::DEFAULT_AVATAR_URL;
	}

	/* ---- option keys (owned by their modules; named here for one map) --- */
	const OPT_AV_OPTIONS = 'csm_av_options'; // AI doc-verify settings incl. OpenAI key (#11815)

	/**
	 * Analytics master switch. OFF unless wp-config defines
	 * CASHAADI_ANALYTICS_ENABLED === true. Keeps the plugin's pixels/events from
	 * double-firing alongside the still-active WPCode analytics snippets — flip
	 * this on in the SAME change that disables #12084/#12091/#12112/#12073/#11697.
	 */
	public static function analytics_enabled() {
		return defined( 'CASHAADI_ANALYTICS_ENABLED' ) && CASHAADI_ANALYTICS_ENABLED;
	}

	/**
	 * Premium module master switch. OFF unless wp-config defines
	 * CASHAADI_PREMIUM_ENABLED === true. The premium snippets inject buttons and
	 * gates, so running the plugin's versions alongside the active snippets would
	 * double them — flip this on in the SAME change that disables the migrated
	 * premium snippets.
	 */
	public static function premium_enabled() {
		return defined( 'CASHAADI_PREMIUM_ENABLED' ) && CASHAADI_PREMIUM_ENABLED;
	}

	/**
	 * Cutover flags for the larger feature modules. Each is OFF unless wp-config
	 * defines the matching constant === true. Every module below is a faithful
	 * migration of still-active WPCode snippets, so its flag must be flipped ON in
	 * the SAME change that disables those snippets (otherwise both run and double
	 * up — or, for function-defining snippets, fatal on redeclare). The modules
	 * also guard with function_exists()/class_exists() as a belt-and-braces.
	 *
	 * discover     #11599/#11600/#11601/#11602/#11605/#11630/#11675/#11681
	 * matches      #11637 (requests-sent tab) + #11694 (match emails)
	 * block        #11810 (block user + own table)
	 * emails       #11732 (reminder queue engine) + #11733 (admin monitor)
	 * admin        #11688 (sales dashboard)
	 * ca_verify    #11815 + #12113 (AI CA-document verification, OpenAI)
	 * otp          #11618 (phone OTP) — ALSO needs CASHAADI_MSG91_AUTHKEY set
	 * signup       #11583 (email activation/auto-login) + #11842 (skip username)
	 * profile_tools#11560 (completion meter) + #11760 (age refresh) + #11812 (created-for)
	 */
	public static function discover_enabled() {
		return defined( 'CASHAADI_DISCOVER_ENABLED' ) && CASHAADI_DISCOVER_ENABLED;
	}
	public static function matches_enabled() {
		return defined( 'CASHAADI_MATCHES_ENABLED' ) && CASHAADI_MATCHES_ENABLED;
	}
	public static function block_enabled() {
		return defined( 'CASHAADI_BLOCK_ENABLED' ) && CASHAADI_BLOCK_ENABLED;
	}
	public static function emails_enabled() {
		return defined( 'CASHAADI_EMAILS_ENABLED' ) && CASHAADI_EMAILS_ENABLED;
	}
	public static function admin_enabled() {
		return defined( 'CASHAADI_ADMIN_ENABLED' ) && CASHAADI_ADMIN_ENABLED;
	}
	public static function ca_verify_enabled() {
		return defined( 'CASHAADI_CA_VERIFY_ENABLED' ) && CASHAADI_CA_VERIFY_ENABLED;
	}
	public static function otp_enabled() {
		return defined( 'CASHAADI_OTP_ENABLED' ) && CASHAADI_OTP_ENABLED;
	}
	public static function signup_enabled() {
		return defined( 'CASHAADI_SIGNUP_ENABLED' ) && CASHAADI_SIGNUP_ENABLED;
	}
	public static function profile_tools_enabled() {
		return defined( 'CASHAADI_PROFILE_TOOLS_ENABLED' ) && CASHAADI_PROFILE_TOOLS_ENABLED;
	}

	/** Resolve the OG image URL from the attachment (per-environment), with a fallback. */
	public static function og_image_url() {
		$url = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( self::OG_IMAGE_ID, 'full' ) : '';
		return $url ? $url : self::OG_IMAGE_URL;
	}

	/**
	 * All field ids keyed by short name — handy for iteration / debugging.
	 *
	 * @return array<string,int>
	 */
	public static function fields() {
		return array(
			'phone'         => self::FIELD_PHONE,
			'age'           => self::FIELD_AGE,
			'gender'        => self::FIELD_GENDER,
			'height'        => self::FIELD_HEIGHT,
			'bio'           => self::FIELD_BIO,
			'dob'           => self::FIELD_DOB,
			'ca_doc'        => self::FIELD_CA_DOC,
			'qualification' => self::FIELD_QUALIFICATION,
		);
	}
}
