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
	/*
	 * Avatar dimensions, 7:8 — the aspect the cropper frames at and every
	 * surface displays at.
	 *
	 * 896 was below what a modern phone shows. The Discover card is full-bleed,
	 * so at DPR 3 it needs ~1180-1290 device pixels and 896 was being stretched
	 * about 40% for everyone on a recent handset. For comparison Instagram
	 * serves feed photos at 1080 on the long edge, on a smaller, squarer tile.
	 *
	 * 1400x1600 covers DPR 3 with headroom. It roughly doubles each avatar file
	 * (~160KB to ~390KB), which against 13GB used of 200GB is not a number worth
	 * optimising, and it changes nothing about what is SERVED per page — the
	 * same one image, at the size the screen actually wants.
	 */
	const FULL_W  = 1400;
	const FULL_H  = 1600;
	const THUMB_W = 700;
	const THUMB_H = 800;

	public static function register() {
		// --- Local default avatar (#11617) — idempotent, always on ---
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'default_avatar_data' ), 20, 2 );
		add_filter( 'bp_core_default_avatar', array( __CLASS__, 'default_avatar_url' ), 20 );
		add_filter( 'bp_core_avatar_default', array( __CLASS__, 'default_avatar_url' ), 20 );
		add_filter( 'bp_core_avatar_default_thumb', array( __CLASS__, 'default_avatar_url' ), 20 );

		/*
		 * The filters above were never enough on their own.
		 *
		 * Measured on staging2 (2026-09-02): a member with no photo still rendered
		 *   //www.gravatar.com/avatar/<hash>?s=896&r=g&d=mm
		 * — Gravatar's own mystery-man, not the local default. Snippet #11617 had
		 * the same four filters and the same result, so "Local Default Avatar
		 * (Remove Gravatar)" had not actually removed Gravatar for either layer.
		 *
		 * Why: bp_core_fetch_avatar() only consults the default-avatar filters when
		 * it has decided NOT to use Gravatar. That decision is its own filter,
		 * bp_core_fetch_avatar_no_grav, which neither layer touched — so BuddyPress
		 * kept building a gravatar.com URL and the local default was never reached.
		 *
		 * This matters beyond appearance: every avatar render sent an MD5 of the
		 * member's email address to a third party, for every visitor, on a
		 * matrimonial site.
		 */
		add_filter( 'bp_core_fetch_avatar_no_grav', '__return_true', 20 );
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
			PhotoRequest::register();
			Nsfw::register();
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'gate_assets' ) );
			// Shim the global csm_photo_is_hidden() that the still-active
			// photo-request snippet (#11798) calls, so it keeps working until
			// #11798 is migrated too. (Harmless now that PhotoRequest is here.)
			require_once __DIR__ . '/compat.php';
		}
	}

	/** Front-end assets for the photo gates (member screens only). */
	public static function gate_assets() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		\CAShaadi\Core\Assets::style( 'photos', 'assets/css/photos.css' );
		\CAShaadi\Core\Assets::script( 'photos', 'assets/js/photos.js' );
		wp_add_inline_script(
			'cashaadi-photos',
			'window.CASHAADI_PR=' . wp_json_encode( array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'csm_pr_nonce' ),
			) ) . ';',
			'before'
		);
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

	/**
	 * How wide this member's stored avatar actually is, in pixels. 0 = none.
	 *
	 * BuddyPress crops uploads to whatever BP_AVATAR_FULL_WIDTH was AT THE TIME
	 * and deletes the original. This site has three generations on disk — 150px
	 * (BuddyPress's default), 350px, and 896px since the HD filter above — so
	 * 163 members' "full" avatars are 150 square. Rendered across a ~450px card
	 * that is a 3x upscale, which is why photos look blurred for members who
	 * never turned blur on.
	 *
	 * Cached in user meta: this reads the file header, and doing that on every
	 * avatar render on a directory page would be a stat storm. The cache is
	 * keyed on the file's mtime, so a re-upload invalidates it by itself.
	 */
	public static function avatar_width( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return 0;
		}

		$dir = bp_core_avatar_upload_path() . '/avatars/' . $user_id;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$src = '';
		foreach ( (array) glob( $dir . '/*-bpfull.*' ) as $file ) {
			if ( 0 !== strpos( basename( $file ), 'csm-blur-' ) ) {
				$src = $file;
				break;
			}
		}
		if ( '' === $src ) {
			return 0;
		}

		$stamp  = (int) @filemtime( $src ); // phpcs:ignore
		$cached = (array) get_user_meta( $user_id, 'csm_avatar_dims', true );
		if ( isset( $cached['stamp'], $cached['w'] ) && (int) $cached['stamp'] === $stamp ) {
			return (int) $cached['w'];
		}

		$size = @getimagesize( $src ); // phpcs:ignore
		$w    = ( $size && ! empty( $size[0] ) ) ? (int) $size[0] : 0;
		update_user_meta( $user_id, 'csm_avatar_dims', array( 'stamp' => $stamp, 'w' => $w ) );
		return $w;
	}

	/**
	 * Is this member's photo too small for the card to show it sharply?
	 *
	 * 300px, which is a card render (~450px) at 1.5x. Nothing brings back pixels
	 * thrown away at upload, so the only fix is a fresh photo — which is why
	 * this drives a prompt on the member's own profile rather than anything on
	 * the card.
	 *
	 * NOT FULL_W/2 (448), which the first version used. That reads as principled
	 * — "half of what we crop to today" — and it flagged 210 of the 285 members
	 * with a photo, because it swept in the whole 350px generation, which is a
	 * 1.3x upscale and looks perfectly fine. Prompting three quarters of the
	 * membership to re-upload an acceptable photo is nagging, and it buries the
	 * 163 whose photos genuinely are soft. 300 draws the line where the two
	 * generations actually differ.
	 */
	public static function avatar_is_low_res( $user_id ) {
		$w = self::avatar_width( $user_id );
		return ( $w > 0 && $w < (int) apply_filters( 'csm_avatar_lowres_below', 300 ) );
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
