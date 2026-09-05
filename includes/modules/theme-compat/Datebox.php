<?php
/**
 * Datebox — the child theme's replacement for BuddyPress's date field.
 *
 * Ported from buddyx-child/functions.php (cashaadi_register_custom_datebox).
 *
 * WHAT IT ACTUALLY DOES. The original's comments claim it replaces the three
 * selects with a single `<input type="date">`. It does not — it renders the same
 * three selects, wrapped in labelled `.datebox-field` divs with visible Day /
 * Month / Year labels, and relabels the type as "Date (picker)" in wp-admin. The
 * markup wrapper is the real change and the theme's CSS targets it, so the port
 * follows the code rather than the comment.
 *
 * THE BUG THIS FIXES. The theme declared `My_Custom_Datebox_Field` inside a
 * function hooked to `bp_init` with no class_exists() guard. bp_init normally
 * fires once, so it never surfaced — but anything firing it twice took the whole
 * site down with a fatal redeclare. It was reproduced accidentally while testing
 * an unrelated change. The guard below is the fix, and it is also what lets this
 * coexist with the theme's copy during the migration: whichever declares first
 * wins, the other skips, and the class is identical either way.
 */

namespace CAShaadi\Modules\ThemeCompat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Datebox {

	public static function register() {
		add_action( 'bp_init', array( __CLASS__, 'install' ), 20 );
	}

	public static function install() {
		if ( ! class_exists( 'BP_XProfile_Field_Type_Datebox' ) ) {
			return; // BuddyPress inactive, or xProfile switched off
		}

		// The guard the theme was missing. Without it a second bp_init is fatal.
		if ( ! class_exists( 'My_Custom_Datebox_Field', false ) ) {
			require_once __DIR__ . '/class-my-custom-datebox-field.php';
		}

		add_filter( 'bp_xprofile_get_field_types', array( __CLASS__, 'map_type' ) );
	}

	/**
	 * Point the 'datebox' type at our class.
	 *
	 * Idempotent: the theme's copy sets the same key to the same class name, so
	 * both running during the migration is harmless.
	 */
	public static function map_type( $types ) {
		$types['datebox'] = 'My_Custom_Datebox_Field';
		return $types;
	}
}
