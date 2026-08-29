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
