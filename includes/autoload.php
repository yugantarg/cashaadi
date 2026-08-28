<?php
/**
 * Minimal PSR-4-style autoloader for the CAShaadi\ namespace.
 *
 * Maps a class like  CAShaadi\Core\Membership  to the file
 *   includes/core/Membership.php
 * and  CAShaadi\Modules\ProfileEdit\FieldLogic  to
 *   includes/modules/profile-edit/FieldLogic.php
 * (every namespace segment except the class name is converted from CamelCase to
 * kebab-case to match the directory layout in docs/ARCHITECTURE.md).
 *
 * Core classes are plain libraries — requiring this file defines nothing and
 * changes no behaviour until a module actually calls a core method.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( function ( $class ) {
	$prefix = 'CAShaadi\\';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );      // e.g. "Modules\ProfileEdit\FieldLogic"
	$parts    = explode( '\\', $relative );
	$class_nm = array_pop( $parts );                       // "FieldLogic"
	$dir      = array_map(                                 // ["modules","profile-edit"]
		function ( $seg ) {
			// CamelCase -> kebab-case:  ProfileEdit -> profile-edit, Core -> core
			return strtolower( preg_replace( '/(?<!^)([A-Z])/', '-$1', $seg ) );
		},
		$parts
	);

	$path = CASHAADI_UI_DIR . 'includes/'
		. ( $dir ? implode( '/', $dir ) . '/' : '' )
		. $class_nm . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );
