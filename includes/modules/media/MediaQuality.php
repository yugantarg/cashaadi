<?php
/**
 * Photo resolution — plugin-side mirror of WPCode snippet #11813 "Photo
 * Resolution (HD Avatars)".
 *
 * READ THIS BEFORE CHANGING THE NUMBERS.
 *
 * Photo quality on this site is ALREADY handled, and handled well, by #11813.
 * BuddyPress ships 150x150 avatars, which is what made photos look degraded in
 * older builds. That snippet raised them and set the values below. This class
 * exists to be the migration target for that snippet — same values, so the two
 * agree while both are live — not to change anything.
 *
 * WHY 896 x 1024 AND NOT SOMETHING BIGGER
 * It is 7:8 portrait, chosen to match the framing of photos already on the site
 * (stored 350x400 / 280x320). A "nicer" number like 1080x1350 is 4:5, and would
 * crop every new member's photo differently from everyone else's. The ratio is
 * the constraint; the resolution is secondary.
 *
 * These dimensions are also the MINIMUM upload BuddyPress accepts —
 * BP_Attachment_Avatar::is_too_small() measures against them — which is why
 * welcome.js upscales anything smaller rather than letting a member be blocked
 * on a step they cannot skip.
 *
 * Verified live 2026-09-01: a 1600x2000 upload stores as 896x1024, and the
 * originals are retained up to 2400px wide by #11813 so the crop is always a
 * real downscale, never an upscale of a small source.
 */

namespace CAShaadi\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaQuality {

	/** 7:8 portrait — must match #11813 exactly. */
	const FULL_W  = 896;
	const FULL_H  = 1024;
	const THUMB_W = 448;
	const THUMB_H = 512;

	/** #11813 already sets this; WordPress's own default is 82. */
	const QUALITY = 92;

	public static function register() {
		/*
		 * Registered at a LOW priority on purpose.
		 *
		 * #11813 is still active and adds the same filters at the default
		 * priority. Ours run first and are simply overwritten by the snippet's
		 * identical values, so the two cannot disagree while both are live. When
		 * #11813 is retired, these keep the same behaviour with nothing to change.
		 */
		add_filter( 'bp_core_avatar_full_width',   array( __CLASS__, 'full_w' ), 5 );
		add_filter( 'bp_core_avatar_full_height',  array( __CLASS__, 'full_h' ), 5 );
		add_filter( 'bp_core_avatar_thumb_width',  array( __CLASS__, 'thumb_w' ), 5 );
		add_filter( 'bp_core_avatar_thumb_height', array( __CLASS__, 'thumb_h' ), 5 );

		add_filter( 'jpeg_quality', array( __CLASS__, 'quality' ), 5 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), 5 );
	}

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

	public static function quality() {
		return self::QUALITY;
	}

	/**
	 * The floor the client has to clear.
	 *
	 * Asks BuddyPress rather than returning our own constant: the constant is
	 * what we requested, while is_too_small() measures against whatever the full
	 * filter chain actually resolved to — including #11813. Returning our own
	 * number would let the client upscale to a size the server still rejects,
	 * which is exactly the bug this call caught on 2026-09-01.
	 */
	public static function min_dimensions() {
		$w = function_exists( 'bp_core_avatar_full_width' ) ? (int) bp_core_avatar_full_width() : 0;
		$h = function_exists( 'bp_core_avatar_full_height' ) ? (int) bp_core_avatar_full_height() : 0;
		return array(
			'w' => $w > 0 ? $w : self::FULL_W,
			'h' => $h > 0 ? $h : self::FULL_H,
		);
	}
}
