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

	/* ---- option keys (owned by their modules; named here for one map) --- */
	const OPT_AV_OPTIONS = 'csm_av_options'; // AI doc-verify settings incl. OpenAI key (#11815)

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
