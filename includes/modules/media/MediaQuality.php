<?php
/**
 * Photo quality.
 *
 * On a matrimonial site the photo IS the product, and it was being degraded
 * twice on the way in (measured on staging2, 2026-09-01: a 1000x1200 upload came
 * back as 896x1024):
 *
 *   1. BuddyPress downscales every avatar to bp_core_avatar_full_width/height.
 *   2. It then re-encodes as JPEG at WordPress's default quality of 82, because
 *      nothing on this site filtered it.
 *
 * THE COUPLING THAT MAKES THIS AWKWARD
 * BP_Attachment_Avatar::is_too_small() compares the upload against those SAME
 * full dimensions. So the storage size and the minimum accepted size are one
 * number: raising it for quality also rejects more photos, and lowering it to
 * accept more photos also throws away detail. They cannot both be tuned here.
 *
 * The split: keep the floor high for quality, and stop it rejecting anyone by
 * upscaling undersized images in the browser before upload (see welcome.js).
 * An upscaled photo is soft — but it displays at the same size either way, and
 * the alternative for that member was not being able to finish signing up.
 */

namespace CAShaadi\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaQuality {

	/**
	 * Stored avatar size. 4:5 to match the Discover card, and tall enough to stay
	 * sharp on a high-DPR phone: the card is full-bleed at ~375pt, which is
	 * ~1125px at 3x, so 896 was already visibly soft.
	 */
	const FULL_W  = 1080;
	const FULL_H  = 1350;
	const THUMB_W = 540;
	const THUMB_H = 675;

	/** JPEG quality for every resize WordPress performs. Default is 82. */
	const QUALITY = 92;

	public static function register() {
		add_filter( 'bp_core_avatar_full_width',   array( __CLASS__, 'full_w' ) );
		add_filter( 'bp_core_avatar_full_height',  array( __CLASS__, 'full_h' ) );
		add_filter( 'bp_core_avatar_thumb_width',  array( __CLASS__, 'thumb_w' ) );
		add_filter( 'bp_core_avatar_thumb_height', array( __CLASS__, 'thumb_h' ) );

		// Covers both the legacy hook and the one the modern image editors use.
		add_filter( 'jpeg_quality', array( __CLASS__, 'quality' ), 20 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), 20 );
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

	/** Exposed so the client knows the floor it has to clear. */
	public static function min_dimensions() {
		return array( 'w' => self::FULL_W, 'h' => self::FULL_H );
	}
}
