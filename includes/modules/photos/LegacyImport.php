<?php
/**
 * Import legacy avatar + cover into the photo library (migrated from WPCode #11861).
 *
 * Existing members have a BuddyPress avatar and sometimes a cover image that
 * predate the multi-photo library (user meta 'csm_photos'). The View/Edit gallery
 * only lists library photos, so those legacy images never appear. This performs a
 * ONE-TIME, idempotent back-fill per member: it copies the existing avatar (full)
 * and cover image into the Media Library and adds them to the photo library
 * (avatar first = main), guarded by the per-user meta 'csm_ph_legacy_imported'.
 *
 * It runs when a member opens their own profile, or an administrator opens the
 * member's profile (so the owner can back-fill by browsing). Non-destructive: it
 * never changes the existing avatar/cover files. Registered only when
 * Config::photos_enabled().
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LegacyImport {

	public static function register() {
		if ( ! Config::photos_enabled() ) {
			return;
		}
		add_action( 'bp_template_redirect', array( __CLASS__, 'maybe_import' ), 5 );
	}

	/** Trigger on member profile pages for the owner or an admin viewer. */
	public static function maybe_import() {
		if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		$uid = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;
		if ( ! $uid ) {
			return;
		}
		$viewer   = (int) get_current_user_id();
		$is_owner = ( $viewer === $uid );
		$is_admin = current_user_can( 'manage_options' );
		if ( ! $is_owner && ! $is_admin ) {
			return;
		}
		self::import_legacy( $uid );
	}

	/** Copy a local image file into the Media Library; return new attachment ID (0 on failure). */
	private static function sideload_local( $src_path, $owner_uid, $title ) {
		if ( ! $src_path || ! file_exists( $src_path ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$name = wp_unique_filename( $upload['path'], basename( $src_path ) );
		$dest = trailingslashit( $upload['path'] ) . $name;
		if ( ! @copy( $src_path, $dest ) ) { // phpcs:ignore
			return 0;
		}

		$type = wp_check_filetype( $dest, null );
		if ( empty( $type['type'] ) ) {
			@unlink( $dest ); // phpcs:ignore
			return 0;
		}

		$att = array(
			'post_mime_type' => $type['type'],
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => (int) $owner_uid,
		);
		$att_id = wp_insert_attachment( $att, $dest );
		if ( ! $att_id || is_wp_error( $att_id ) ) {
			return 0;
		}

		$meta = wp_generate_attachment_metadata( $att_id, $dest );
		wp_update_attachment_metadata( $att_id, $meta );
		return (int) $att_id;
	}

	/** Find the member's existing avatar full-size file path ('' if none/default). */
	private static function legacy_avatar_path( $uid ) {
		if ( ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return '';
		}
		$dir = bp_core_avatar_upload_path() . '/avatars/' . (int) $uid;
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		$hits = glob( $dir . '/*-bpfull.*' );
		if ( empty( $hits ) ) {
			$hits = array_values( array_filter( (array) glob( $dir . '/*' ), function ( $f ) {
				return preg_match( '/\.(jpe?g|png|gif|webp)$/i', $f ) && false === strpos( $f, '-bpthumb.' );
			} ) );
		}
		return ! empty( $hits ) ? $hits[0] : '';
	}

	/** Find the member's existing cover image file path ('' if none). */
	private static function legacy_cover_path( $uid ) {
		if ( ! function_exists( 'bp_attachments_uploads_dir_get' ) ) {
			return '';
		}
		$base = bp_attachments_uploads_dir_get( 'dir' );
		if ( ! $base ) {
			return '';
		}
		$dir = trailingslashit( $base ) . 'members/' . (int) $uid . '/cover-image';
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		$hits = array_values( array_filter( (array) glob( $dir . '/*' ), function ( $f ) {
			return preg_match( '/\.(jpe?g|png|gif|webp)$/i', $f );
		} ) );
		return ! empty( $hits ) ? $hits[0] : '';
	}

	/** One-time import of avatar + cover into the photo library for a member. */
	public static function import_legacy( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid ) {
			return;
		}
		if ( get_user_meta( $uid, 'csm_ph_legacy_imported', true ) ) {
			return;
		}
		if ( ! class_exists( 'CAShaadi\\Modules\\Photos\\Gallery' ) ) {
			return;
		}

		// Mark first so a slow copy can't trigger a concurrent double-import.
		update_user_meta( $uid, 'csm_ph_legacy_imported', 1 );

		$ids = array_values( array_filter( array_map( 'intval', (array) Gallery::get( $uid ) ) ) );

		// Avatar -> main (front). Only when the library has no photos yet, because
		// a member who used the new uploader already has their avatar in the library.
		if ( empty( $ids ) ) {
			$avatar = self::legacy_avatar_path( $uid );
			if ( $avatar ) {
				$aid = self::sideload_local( $avatar, $uid, 'Profile photo' );
				if ( $aid ) {
					$ids[] = $aid;
				}
			}
		}

		// Cover -> appended.
		$cover = self::legacy_cover_path( $uid );
		if ( $cover ) {
			$cid = self::sideload_local( $cover, $uid, 'Cover photo' );
			if ( $cid ) {
				$ids[] = $cid;
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$max = Gallery::max();
		if ( count( $ids ) > $max ) {
			$ids = array_slice( $ids, 0, $max );
		}

		if ( ! empty( $ids ) ) {
			Gallery::save( $uid, $ids );
		}
	}
}
