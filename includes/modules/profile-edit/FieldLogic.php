<?php
/**
 * Profile-edit field logic & guards.
 *
 * Consolidates six WPCode snippets into one module that leans on Core\Config:
 *   #11624 Allow Partial Profile Save   (bp_xprofile_is_required_field)
 *   #11621 Lock Gender After Signup      (bp_xprofile_set_field_data_pre_validate + UX)
 *   #11619 Bio Plain Textarea            (bp_xprofile_is_richtext_enabled_for_field + chrome)
 *   #11611 Sync Age from DOB             (xprofile_updated_profile; live sync only)
 *   #11797 Height Input Guard            (front-end validation on field 228)
 *   #11625 Lock Account Email            (settings/general UX)
 *
 * Server-side rules are logically identical to the snippets, so running them
 * alongside the still-active snippets is idempotent (same result). The front-end
 * UX moves out of PHP-echoed <script>/<style> into real assets that self-guard
 * against double-injection, so the transition window is safe. The one-time age
 * backfill from #11611 is intentionally NOT ported — it is a completed migration
 * (a tool), not runtime code.
 */

namespace CAShaadi\Modules\ProfileEdit;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldLogic {

	public static function register() {
		add_filter( 'bp_xprofile_is_required_field', array( __CLASS__, 'partial_save' ), 10, 2 );
		add_filter( 'bp_xprofile_set_field_data_pre_validate', array( __CLASS__, 'gender_lock' ), 5, 3 );
		add_filter( 'bp_xprofile_is_richtext_enabled_for_field', array( __CLASS__, 'bio_plain' ), 99, 2 );
		add_filter( 'bp_xprofile_field_get_children', array( __CLASS__, 'drop_select_option' ), 10, 1 );
		add_filter( 'bp_xprofile_get_hidden_fields_for_user', array( __CLASS__, 'hide_phone_from_others' ), 10, 3 );
		// Age auto-syncs from DOB on every profile-update: the classic form, the
		// app editor, and the onboarding wizard all fire xprofile_updated_profile
		// (the wizard was taught to, in Welcome::rest_step). xprofile_set_field_data
		// alone does NOT fire xprofile_data_after_save in BP 14, so hooking that was
		// dead — this single hook covers every real write path.
		add_action( 'xprofile_updated_profile', array( __CLASS__, 'sync_age' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 21 );
	}

	/* ---- #11624 — allow partial save on the edit POST (gender stays required) */
	public static function partial_save( $is_required, $field_id ) {
		if ( Config::FIELD_GENDER === (int) $field_id ) {
			return $is_required;
		}
		if ( ! empty( $_POST['profile-group-edit-submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return $is_required;
	}

	/* ---- #11621 — server-side gender lock (tamper-proof) ------------------ */
	public static function gender_lock( $value, $field, $field_type_obj ) {
		if ( ! is_object( $field ) || (int) $field->id !== Config::FIELD_GENDER ) {
			return $value;
		}
		$uid = 0;
		if ( isset( $field->data ) && isset( $field->data->user_id ) ) {
			$uid = (int) $field->data->user_id;
		}
		if ( ! $uid && isset( $_POST['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$uid = (int) $_POST['user_id'];
		}
		if ( ! $uid ) {
			$uid = bp_displayed_user_id() ? bp_displayed_user_id() : get_current_user_id();
		}
		if ( ! $uid ) {
			return $value;
		}
		$existing = xprofile_get_field_data( Config::FIELD_GENDER, $uid );
		if ( is_array( $existing ) ) {
			$existing = reset( $existing );
		}
		if ( '' !== $existing && null !== $existing && false !== $existing ) {
			return $existing; // keep the locked value
		}
		return $value;
	}

	/** Does the user already have a stored gender? */
	public static function gender_is_locked( $uid = 0 ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( ! $uid || ! function_exists( 'xprofile_get_field_data' ) ) {
			return false;
		}
		$g = xprofile_get_field_data( Config::FIELD_GENDER, $uid );
		if ( is_array( $g ) ) {
			$g = reset( $g );
		}
		return ( '' !== $g && null !== $g && false !== $g );
	}

	/* ---- #11619 — disable the rich editor for the Bio field -------------- */
	public static function bio_plain( $enabled, $field_id ) {
		/*
		 * Widened from Bio alone to EVERY field (owner, 2026-09-01: "the text
		 * editor should be simple").
		 *
		 * #11619 only ever unhooked TinyMCE from Bio, so Family Details still
		 * rendered a full WordPress editor — bold/italic/quote/strikethrough,
		 * lists, alignment, undo/redo, link, fullscreen, a Visual/Code tab pair
		 * and a character counter — to collect a sentence about someone's family,
		 * on a phone. Nothing downstream renders that markup as rich text.
		 *
		 * $field_id is unused now; the signature stays because it is a filter.
		 */
		unset( $field_id );
		return false;
	}

	/**
	 * Hide the stray "Select" choice (owner, 2026-09-01: "retire the 'select'
	 * option that comes as a separate option in a lot of places").
	 *
	 * Three fields were authored with a literal option called "Select" —
	 * Occupation Status (418), Religion (302), Nuclear/Joint (405) — on top of
	 * BuddyPress's own "----" placeholder. So the dropdown offered TWO
	 * placeholders, and unlike "----" this one is a real, selectable value that
	 * saves as the member's answer.
	 *
	 * Hidden at render, NOT deleted from the field. Deleting options edits the
	 * xProfile schema, which FIELD-INVENTORY.md forbids: it is irreversible and
	 * the same code will run against production. Filtering costs nothing and can
	 * be undone by removing one line.
	 *
	 * Note for later: any member who already saved "Select" as their answer still
	 * has it stored, and profile-completion counts it as filled. That is a data
	 * cleanup, deliberately separate from this presentational fix.
	 */
	public static function drop_select_option( $children ) {
		if ( empty( $children ) || ! is_array( $children ) ) {
			return $children;
		}
		foreach ( $children as $i => $child ) {
			if ( isset( $child->name ) && 'select' === strtolower( trim( (string) $child->name ) ) ) {
				unset( $children[ $i ] );
			}
		}
		return array_values( $children );
	}

	/**
	 * A member's phone number is never shown to anyone but themselves.
	 *
	 * Core\Profile already withheld it from the screens this rebuild owns
	 * (Discover, the preview), but that was only half the surface: BuddyPress's
	 * OWN member page renders the xProfile loop, and it was still printing the
	 * number in full to any logged-in member who opened the profile.
	 *
	 * Hooking bp_xprofile_get_hidden_fields_for_user fixes it everywhere at once,
	 * because that is the function BuddyPress consults in its loop AND the one
	 * Core\Profile filters through — so there is a single answer to "may this
	 * viewer see this field".
	 *
	 * Per-field visibility could express this per member, but a safe default must
	 * not depend on every member having configured it. Contact details belong
	 * behind a match, shared when the member chooses.
	 *
	 * @param int[] $hidden  Field ids already hidden.
	 * @param int   $shown   Whose profile is being viewed.
	 * @param int   $viewer  Who is looking.
	 */
	public static function hide_phone_from_others( $hidden, $shown = 0, $viewer = 0 ) {
		$hidden = (array) $hidden;

		// Looking at your own profile: you may see your own number.
		if ( $shown && $viewer && (int) $shown === (int) $viewer ) {
			return $hidden;
		}

		$hidden[] = (int) Config::FIELD_PHONE;
		return array_values( array_unique( array_map( 'intval', $hidden ) ) );
	}

	/* ---- #11611 — recompute Age (286) from DOB (586) on every profile update */
	public static function sync_age( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! function_exists( 'xprofile_set_field_data' ) ) {
			return;
		}
		$age = self::calc_age( self::raw_dob( $user_id ) );
		if ( null === $age ) {
			return;
		}
		xprofile_set_field_data( Config::FIELD_AGE, $user_id, $age );
	}

	private static function raw_dob( $user_id ) {
		if ( ! class_exists( 'BP_XProfile_ProfileData' ) ) {
			return null;
		}
		$raw = \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_DOB, $user_id );
		return empty( $raw ) ? null : $raw;
	}

	private static function calc_age( $dob_raw ) {
		if ( empty( $dob_raw ) ) {
			return null;
		}
		$ts = strtotime( $dob_raw );
		if ( false === $ts || $ts <= 0 ) {
			return null;
		}
		try {
			$dob = new \DateTime( '@' . $ts );
			$now = new \DateTime( 'now' );
			$age = (int) $now->diff( $dob )->y;
		} catch ( \Exception $e ) {
			return null;
		}
		if ( $age < 18 || $age > 100 ) {
			return null;
		}
		return $age;
	}

	/* ---- front-end UX assets (height guard, gender lock, email lock, bio) - */
	public static function enqueue() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		$on_edit     = function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit();
		$on_settings = function_exists( 'bp_is_user_settings_general' ) && bp_is_user_settings_general();
		if ( ! $on_edit && ! $on_settings ) {
			return;
		}

		Assets::style( 'profile-forms', 'assets/css/profile-forms.css' );
		Assets::script( 'profile-forms', 'assets/js/profile-forms.js' );

		$cfg = array(
			'gender'       => Config::FIELD_GENDER,
			'height'       => Config::FIELD_HEIGHT,
			'heightGuard'  => $on_edit ? 1 : 0,
			'genderLocked' => ( $on_edit && self::gender_is_locked() ) ? 1 : 0,
			'emailLock'    => $on_settings ? 1 : 0,
		);
		wp_add_inline_script(
			'cashaadi-profile-forms',
			'window.CASHAADI_FORMS=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}
}
