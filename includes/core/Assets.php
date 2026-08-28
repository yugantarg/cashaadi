<?php
/**
 * Assets — the ONE way this plugin puts CSS/JS on a page.
 *
 * Modules call Assets::style()/script() with a path relative to the plugin's
 * assets/ tree; versioning (filemtime while WP_DEBUG, else the plugin version)
 * is handled here. This replaces the ~15 snippets that echo inline
 * <style>/<script> from PHP — those become real, cacheable, versioned files.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	/** Absolute URL for a plugin-relative path. */
	public static function url( $rel ) {
		return CASHAADI_UI_URL . ltrim( $rel, '/' );
	}

	/**
	 * Cache-busting version: file mtime while debugging so a deploy is never
	 * masked by a cached asset; the static plugin version in production.
	 */
	public static function version( $rel ) {
		$abs = CASHAADI_UI_DIR . ltrim( $rel, '/' );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $abs ) ) {
			return (string) filemtime( $abs );
		}
		return CASHAADI_UI_VER;
	}

	/**
	 * Enqueue a stylesheet. Handle is auto-prefixed with "cashaadi-".
	 *
	 * @param string   $handle Short handle, e.g. "profile-edit".
	 * @param string   $rel    Path under the plugin root, e.g. "assets/css/profile-edit.css".
	 * @param string[] $deps   Dependencies.
	 * @param string   $media  Media attribute.
	 */
	public static function style( $handle, $rel, $deps = array(), $media = 'all' ) {
		wp_enqueue_style( 'cashaadi-' . $handle, self::url( $rel ), $deps, self::version( $rel ), $media );
	}

	/**
	 * Enqueue a script. Handle is auto-prefixed with "cashaadi-".
	 *
	 * @param string   $handle    Short handle, e.g. "profile-wizard".
	 * @param string   $rel       Path under the plugin root.
	 * @param string[] $deps      Dependencies.
	 * @param bool     $in_footer Print before </body> (default true).
	 */
	public static function script( $handle, $rel, $deps = array(), $in_footer = true ) {
		wp_enqueue_script( 'cashaadi-' . $handle, self::url( $rel ), $deps, self::version( $rel ), $in_footer );
	}
}
