<?php
/**
 * Photos module.
 *
 * Foundation first: the two plain, idempotent avatar filters, which are safe to
 * run alongside their still-active snippets:
 *   #11617 Local Default Avatar (remove Gravatar)
 *   #11813 Photo Resolution (HD avatar sizes)
 *
 * Still to migrate here, GATED behind Config::photos_enabled() (they all filter
 * bp_core_fetch_avatar_url, so running them beside the snippets would stack):
 *   #11770 Private Photo (blur for non-matches)
 *   #11798 Photo Request (ask & approve)
 *   #12119 Photo Moderation (NSFW mask)
 * A single Core-style resolver will compose those in one filter instead of three.
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Photos {

	/* HD avatar sizes (#11813): portrait 7:8, long edge 1024/512. */
	const FULL_W  = 896;
	const FULL_H  = 1024;
	const THUMB_W = 448;
	const THUMB_H = 512;

	public static function register() {
		// --- Local default avatar (#11617) — idempotent, always on ---
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'default_avatar_data' ), 20, 2 );
		add_filter( 'bp_core_default_avatar', array( __CLASS__, 'default_avatar_url' ), 20 );
		add_filter( 'bp_core_avatar_default', array( __CLASS__, 'default_avatar_url' ), 20 );
		add_filter( 'bp_core_avatar_default_thumb', array( __CLASS__, 'default_avatar_url' ), 20 );
		add_filter( 'gettext', array( __CLASS__, 'reword_avatar_help' ), 20, 2 );

		// --- HD avatar sizes (#11813) — pure filters, always on ---
		add_filter( 'bp_core_avatar_full_width', array( __CLASS__, 'full_w' ) );
		add_filter( 'bp_core_avatar_full_height', array( __CLASS__, 'full_h' ) );
		add_filter( 'bp_core_avatar_thumb_width', array( __CLASS__, 'thumb_w' ) );
		add_filter( 'bp_core_avatar_thumb_height', array( __CLASS__, 'thumb_h' ) );
		add_filter( 'bp_core_avatar_original_max_width', array( __CLASS__, 'original_max_width' ) );
		add_filter( 'bp_core_avatar_original_max_filesize', array( __CLASS__, 'original_max_filesize' ) );
		add_filter( 'jpeg_quality', array( __CLASS__, 'jpeg_quality' ) );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'jpeg_quality' ) );

		// --- Hard gates (gated by CASHAADI_PHOTOS_ENABLED) ---
		// Private-photo blur (#11770). Photo-request (#11798) + NSFW mask (#12119)
		// will register here too and compose via the csm_photo_is_hidden filter.
		if ( Config::photos_enabled() ) {
			Privacy::register();
			// Shim the global csm_photo_is_hidden() that the still-active
			// photo-request snippet (#11798) calls, so it keeps working until
			// #11798 is migrated too.
			require_once __DIR__ . '/compat.php';
		}
	}

	/* ---- default avatar (#11617) --------------------------------------- */

	public static function default_avatar_url() {
		return Config::default_avatar_url();
	}

	public static function reword_avatar_help( $translated, $text ) {
		if ( is_string( $translated ) && false !== strpos( $translated, 'associated with your account email we will use that' ) ) {
			return 'Your profile photo will be used on your profile and throughout the site. You can upload an image from your computer.';
		}
		return $translated;
	}

	public static function default_avatar_data( $args, $id_or_email ) {
		$default          = Config::default_avatar_url();
		$args['default']  = $default;
		if ( empty( $args['url'] ) ) {
			$args['url'] = $default;
		}
		return $args;
	}

	/* ---- HD sizes (#11813) --------------------------------------------- */

	public static function full_w() {
		return self::FULL_W;
	}
	public static function full_h() {
		return self::FULL_H;
	}
	public static function thumb_w() {
		return self::THUMB_W;
	}
	public static function thumb_h() {
		return self::THUMB_H;
	}
	public static function original_max_width() {
		return 2400;
	}
	public static function original_max_filesize( $bytes ) {
		$min = 8 * 1024 * 1024; // at least 8 MB
		return ( (int) $bytes < $min ) ? $min : $bytes;
	}
	public static function jpeg_quality( $q ) {
		return ( (int) $q < 92 ) ? 92 : $q;
	}
}
