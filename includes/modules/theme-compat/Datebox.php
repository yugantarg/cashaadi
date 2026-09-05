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
 * THE BUG THIS FIXES. The theme declares `My_Custom_Datebox_Field` inside a
 * function hooked to `bp_init` with no class_exists() guard, so anything firing
 * bp_init twice takes the site down with a fatal redeclare.
 *
 * WHY THIS WAITS FOR THE THEME. A first attempt registered this immediately and
 * took staging2 down with 500s across every page. The reasoning was "whichever
 * declares first wins, the other skips" — true only if BOTH are guarded, and the
 * theme's is not. Ours declared the class at bp_init:20, the theme's ran at the
 * same priority straight after, and redeclared it.
 *
 * So this stays dormant while the theme's function exists, and takes over the
 * moment it is deleted. Unlike the hooks in ThemeCompat, which can be unhooked by
 * name and swapped safely, a class declaration cannot coexist with an unguarded
 * twin: theme and plugin must change in the same step.
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

		/*
		 * Stand down while the theme still declares this. Its copy is unguarded,
		 * so if we declare first it will redeclare and fatal the whole site —
		 * which is exactly what happened when this was first deployed.
		 */
		if ( function_exists( 'cashaadi_register_custom_datebox' ) ) {
			return;
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
	 * Only reached once the theme's copy is gone, so there is no competing
	 * mapping — but idempotent regardless: the same key, the same class name.
	 */
	public static function map_type( $types ) {
		$types['datebox'] = 'My_Custom_Datebox_Field';
		return $types;
	}
}
