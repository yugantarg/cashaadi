<?php
/**
 * Minimal PSR-4-style autoloader for the CAShaadi\ namespace.
 *
 * Maps a class like  CAShaadi\Core\Membership  to the file
 *   includes/core/Membership.php
 * (every namespace segment except the class name is lower-cased to match the
 * directory layout in docs/ARCHITECTURE.md).
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

	$relative = substr( $class, strlen( $prefix ) );      // e.g. "Core\Membership"
	$parts    = explode( '\\', $relative );
	$class_nm = array_pop( $parts );                       // "Membership"
	$dir      = array_map( 'strtolower', $parts );         // ["core"]

	$path = CASHAADI_UI_DIR . 'includes/'
		. ( $dir ? implode( '/', $dir ) . '/' : '' )
		. $class_nm . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );
