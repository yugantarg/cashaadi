<?php
/**
 * Member photo gallery — multi-upload, no crop (migrated from WPCode #11822)
 * plus the photo lightbox + privacy notice (migrated from WPCode #11771).
 *
 * A friction-light uploader: a member adds up to CSM_PH_MAX (5) photos at once,
 * no interactive crop. The FIRST photo (index 0) becomes their BuddyPress avatar
 * (rendered into the avatar dir at the site's configured dims, respecting the
 * bp_core_avatar_* size filters from Photos); the rest form a gallery under the
 * member header. Any photo can be made main or removed. Storage is the ordered
 * user-meta array `csm_photos` of attachment IDs — NO custom table.
 *
 * Rendered by shortcode [csm_photos], auto-shown under the member header, with a
 * full-screen lightbox for gallery thumbnails (#11822) and, from #11771, an
 * avatar-zoom overlay + a gendered "why is this blurred" notice that composes
 * with Privacy (a blurred viewer sees the blurred file, never the original).
 *
 * The former global helpers (csm_ph_get/save/set_avatar/uploader_html/grid_html)
 * are now static methods; only #11822 and #11861 referenced them and both are
 * migrated here / in LegacyImport. Registered only when Config::photos_enabled().
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gallery {

	/** Maximum photos per member (was the CSM_PH_MAX constant in #11822). */
	const MAX = 5;

	public static function register() {
		if ( ! Config::photos_enabled() ) {
			return;
		}

		// AJAX (unchanged actions + the csm_ph nonce).
		add_action( 'wp_ajax_csm_ph_upload', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_csm_ph_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_csm_ph_main', array( __CLASS__, 'ajax_main' ) );
		// Re-crop an existing photo. Possible at all only because the master is
		// now the whole picture rather than the crop.
		add_action( 'wp_ajax_csm_ph_crop', array( __CLASS__, 'ajax_crop' ) );

		// Uploader shortcode + profile gallery under the member header.
		add_shortcode( 'csm_photos', array( __CLASS__, 'uploader_html' ) );
		add_action( 'bp_after_member_header', array( __CLASS__, 'profile_gallery' ) );

		// Privacy notice under the header (#11771).
		add_action( 'bp_after_member_header', array( __CLASS__, 'profile_notice' ) );

		// Assets (lightbox, uploader/gallery JS+CSS, notice zoom data).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* --------------------------------------------------------- data helpers */

	public static function max() {
		// Honour a pre-existing CSM_PH_MAX override if one is defined (e.g. by a
		// still-active snippet before cutover); otherwise the class default.
		return defined( 'CSM_PH_MAX' ) ? (int) CSM_PH_MAX : self::MAX;
	}

	public static function get( $uid ) {
		$ids = get_user_meta( (int) $uid, 'csm_photos', true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id && 'attachment' === get_post_type( $id ) && wp_get_attachment_image_url( $id, 'medium' ) ) {
				$out[] = $id;
			}
		}
		// self-heal if any were deleted
		if ( count( $out ) !== count( $ids ) ) {
			update_user_meta( (int) $uid, 'csm_photos', $out );
		}
		return $out;
	}

	public static function save( $uid, $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$ids = array_slice( $ids, 0, self::max() );
		update_user_meta( (int) $uid, 'csm_photos', $ids );
		return $ids;
	}

	/* ---------------------------------------- set the main photo as BP avatar */

	/**
	 * The stored crop for an attachment, or null.
	 *
	 * Fractions of the source, never pixels — see the note in cropper.js. The
	 * master can be re-encoded or capped at a different size and the rectangle
	 * still points at the same part of the picture.
	 */
	public static function crop_rect( $att_id ) {
		$r = get_post_meta( (int) $att_id, '_csm_crop', true );
		if ( ! is_array( $r ) || ! isset( $r['x'], $r['y'], $r['w'], $r['h'] ) ) {
			return null;
		}
		$w = min( 1.0, max( 0.0, (float) $r['w'] ) );
		$h = min( 1.0, max( 0.0, (float) $r['h'] ) );
		if ( $w <= 0 || $h <= 0 ) {
			return null;
		}
		return array(
			'x' => min( 1.0, max( 0.0, (float) $r['x'] ) ),
			'y' => min( 1.0, max( 0.0, (float) $r['y'] ) ),
			'w' => $w,
			'h' => $h,
		);
	}

	/** Store a crop, validating it as a fraction of the image. */
	public static function set_crop_rect( $att_id, $raw ) {
		$r = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $r ) || ! isset( $r['x'], $r['y'], $r['w'], $r['h'] ) ) {
			return false;
		}
		$clean = array(
			'x' => min( 1.0, max( 0.0, (float) $r['x'] ) ),
			'y' => min( 1.0, max( 0.0, (float) $r['y'] ) ),
			'w' => min( 1.0, max( 0.0, (float) $r['w'] ) ),
			'h' => min( 1.0, max( 0.0, (float) $r['h'] ) ),
		);
		if ( $clean['w'] <= 0 || $clean['h'] <= 0 ) {
			return false;
		}
		// A rectangle running off the edge would crop outside the file.
		$clean['w'] = min( $clean['w'], 1.0 - $clean['x'] );
		$clean['h'] = min( $clean['h'], 1.0 - $clean['y'] );

		/*
		 * Round before storing. These arrive as full-precision floats from the
		 * browser, so two visually identical crops differ in the fifteenth
		 * decimal place — enough to defeat the comparison below and enough to
		 * make the stored value churn for no reason. Five places is ~0.005% of
		 * the image's width: far finer than anybody can drag.
		 */
		foreach ( $clean as $k => $v ) {
			$clean[ $k ] = round( (float) $v, 5 );
		}

		/*
		 * "Nothing changed" is SUCCESS, not failure.
		 *
		 * update_post_meta() returns false when the new value equals the stored
		 * one. So a member who opened Adjust crop, moved nothing (or moved and
		 * came back to the same place) and pressed Save was told "That crop
		 * could not be saved" — for a save that had nothing to do and was in no
		 * way wrong. That is the occasional error: not a fault, a
		 * misinterpretation of a documented return value.
		 */
		$existing = get_post_meta( (int) $att_id, '_csm_crop', true );
		if ( is_array( $existing ) ) {
			$same = true;
			foreach ( $clean as $k => $v ) {
				if ( ! isset( $existing[ $k ] ) || abs( (float) $existing[ $k ] - $v ) > 0.000001 ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return true;
			}
		}

		return false !== update_post_meta( (int) $att_id, '_csm_crop', $clean );
	}

	/**
	 * Narrow an editor to the member's chosen region, in place.
	 *
	 * Silent no-op without a rectangle, which is what an older photo or an
	 * uncropped upload has — those keep the centre crop resize() gives them.
	 */
	private static function apply_crop( $editor, $crop ) {
		if ( ! $crop || is_wp_error( $editor ) ) {
			return;
		}
		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			return;
		}
		$w = (int) round( $size['width'] * $crop['w'] );
		$h = (int) round( $size['height'] * $crop['h'] );
		if ( $w < 8 || $h < 8 ) {
			return; // nonsense rectangle: better the whole photo than a sliver
		}
		$editor->crop(
			(int) round( $size['width'] * $crop['x'] ),
			(int) round( $size['height'] * $crop['y'] ),
			$w,
			$h
		);
	}

	public static function set_avatar( $uid, $att_id ) {
		$file = get_attached_file( (int) $att_id );
		if ( ! $file || ! file_exists( $file ) || ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return false;
		}
		$dir = bp_core_avatar_upload_path() . '/avatars/' . (int) $uid;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Clear any existing avatar files so BuddyPress picks the new one.
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			if ( is_file( $f ) ) {
				@unlink( $f ); // phpcs:ignore
			}
		}

		$fw = (int) apply_filters( 'bp_core_avatar_full_width', 150 );
		$fh = (int) apply_filters( 'bp_core_avatar_full_height', 150 );
		$tw = (int) apply_filters( 'bp_core_avatar_thumb_width', 50 );
		$th = (int) apply_filters( 'bp_core_avatar_thumb_height', 50 );
		$ts = time();

		$crop = self::crop_rect( (int) $att_id );

		$full = wp_get_image_editor( $file );
		if ( is_wp_error( $full ) ) {
			return false;
		}
		self::apply_crop( $full, $crop );
		$full->resize( $fw, $fh, true );
		$full->set_quality( 92 );
		$full->save( $dir . '/' . $ts . '-bpfull.jpg' );

		$thumb = wp_get_image_editor( $file );
		if ( ! is_wp_error( $thumb ) ) {
			self::apply_crop( $thumb, $crop );
			$thumb->resize( $tw, $th, true );
			$thumb->set_quality( 92 );
			$thumb->save( $dir . '/' . $ts . '-bpthumb.jpg' );
		}

		if ( function_exists( 'bp_update_user_meta' ) ) {
			bp_update_user_meta( $uid, 'last_activity', bp_core_current_time() );
		}
		return true;
	}

	/* ------------------------------------------------------------- AJAX: add */

	/**
	 * Change the crop on a photo the member already uploaded.
	 *
	 * Only meaningful now that the master is the whole photo: before, the stored
	 * file WAS the crop, so there was nothing outside it to move the frame into.
	 *
	 * Ownership is checked against the member's own photo list, not merely
	 * against the attachment's post_author — an attachment id is guessable, and
	 * media uploaded elsewhere on the site must not be re-cropped from here.
	 */
	public static function ajax_crop() {
		check_ajax_referer( 'csm_ph', 'nonce' );
		$uid = get_current_user_id();
		if ( ! $uid ) {
			wp_send_json_error( array( 'message' => 'Please log in.' ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$in = isset( $_POST['rect'] ) ? wp_unslash( $_POST['rect'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in set_crop_rect()

		$mine = self::get( $uid );
		if ( ! $id || ! in_array( $id, array_map( 'intval', $mine ), true ) ) {
			wp_send_json_error( array( 'message' => 'That photo is not yours.' ) );
		}
		if ( ! self::set_crop_rect( $id, $in ) ) {
			wp_send_json_error( array( 'message' => 'That crop could not be saved.' ) );
		}

		// Only the main photo feeds the avatar, so only that one needs redrawing.
		if ( ! empty( $mine ) && (int) $mine[0] === $id ) {
			self::set_avatar( $uid, $id );
		}

		wp_send_json_success( array( 'html' => self::grid_html( $uid ) ) );
	}

	public static function ajax_upload() {
		check_ajax_referer( 'csm_ph', 'nonce' );
		$uid = get_current_user_id();
		if ( ! $uid ) {
			wp_send_json_error( array( 'message' => 'Please log in.' ) );
		}

		if ( empty( $_FILES['photos'] ) ) {
			wp_send_json_error( array( 'message' => 'No file received.' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$existing = self::get( $uid );
		$room     = self::max() - count( $existing );
		if ( $room <= 0 ) {
			wp_send_json_error( array( 'message' => 'You already have the maximum of ' . self::max() . ' photos.' ) );
		}

		$files   = $_FILES['photos']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rects   = isset( $_POST['rects'] ) ? (array) $_POST['rects'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in set_crop_rect()
		$added   = array();
		$errors  = array();
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic' );
		$count   = is_array( $files['name'] ) ? count( $files['name'] ) : 0;

		for ( $i = 0; $i < $count && count( $added ) < $room; $i++ ) {
			if ( empty( $files['name'][ $i ] ) || (int) $files['error'][ $i ] !== 0 ) {
				continue;
			}
			$type = isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '';
			if ( $type && ! in_array( $type, $allowed, true ) && 0 !== strpos( (string) $type, 'image/' ) ) {
				$errors[] = 'Skipped a non-image file.';
				continue;
			}
			$single = array(
				'name'     => $files['name'][ $i ],
				'type'     => $type,
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
			$_FILES = array( 'csm_ph_file' => $single );
			$att_id = media_handle_upload( 'csm_ph_file', 0 );
			if ( is_wp_error( $att_id ) ) {
				$errors[] = $att_id->get_error_message();
				continue;
			}
			/*
			 * The crop the member chose, kept BESIDE the photo rather than baked
			 * into it. rects[] is positional and always the same length as
			 * photos[], so an empty entry means "no crop" and one photo's
			 * rectangle can never be applied to another.
			 */
			if ( isset( $rects[ $i ] ) && '' !== $rects[ $i ] ) {
				self::set_crop_rect( (int) $att_id, wp_unslash( $rects[ $i ] ) );
			}
			$added[] = (int) $att_id;
		}

		if ( empty( $added ) ) {
			wp_send_json_error( array( 'message' => $errors ? implode( ' ', array_slice( $errors, 0, 2 ) ) : 'Upload failed.' ) );
		}

		$was_empty = empty( $existing );
		$all       = self::save( $uid, array_merge( $existing, $added ) );

		// If they had no photos, the first newly added becomes the avatar.
		if ( $was_empty && ! empty( $all ) ) {
			self::set_avatar( $uid, $all[0] );
		}

		wp_send_json_success( array( 'html' => self::grid_html( $uid ), 'count' => count( $all ) ) );
	}

	/* ---------------------------------------------------------- AJAX: delete */

	public static function ajax_delete() {
		check_ajax_referer( 'csm_ph', 'nonce' );
		$uid = get_current_user_id();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $uid || ! $id ) {
			wp_send_json_error( array( 'message' => 'Bad request.' ) );
		}

		$ids      = self::get( $uid );
		$was_main = ( isset( $ids[0] ) && (int) $ids[0] === $id );
		$ids      = array_values( array_diff( $ids, array( $id ) ) );
		self::save( $uid, $ids );

		// Only delete the attachment if it belongs to this user.
		$att = get_post( $id );
		if ( $att && (int) $att->post_author === (int) $uid ) {
			wp_delete_attachment( $id, true );
		}

		// If the main was removed, promote the next photo to avatar.
		if ( $was_main && ! empty( $ids ) ) {
			self::set_avatar( $uid, $ids[0] );
		}

		wp_send_json_success( array( 'html' => self::grid_html( $uid ), 'count' => count( $ids ) ) );
	}

	/* -------------------------------------------------------- AJAX: set main */

	public static function ajax_main() {
		check_ajax_referer( 'csm_ph', 'nonce' );
		$uid = get_current_user_id();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $uid || ! $id ) {
			wp_send_json_error( array( 'message' => 'Bad request.' ) );
		}

		$ids = self::get( $uid );
		if ( ! in_array( $id, $ids, true ) ) {
			wp_send_json_error( array( 'message' => 'Photo not found.' ) );
		}

		$ids = array_merge( array( $id ), array_values( array_diff( $ids, array( $id ) ) ) );
		self::save( $uid, $ids );
		self::set_avatar( $uid, $id );

		wp_send_json_success( array( 'html' => self::grid_html( $uid ), 'count' => count( $ids ) ) );
	}

	/* -------------------------------------------------------------- renderers */

	public static function grid_html( $uid ) {
		$ids  = self::get( $uid );
		$max  = self::max();
		$html = '<div class="csm-ph-grid">';
		foreach ( $ids as $idx => $id ) {
			$src  = wp_get_attachment_image_url( $id, 'medium' );
			$main = ( 0 === $idx );

			/*
			 * The MAIN photo is shown as the avatar, not as the attachment.
			 *
			 * The attachment is the master — the whole picture, uncropped. The
			 * avatar is the derivative set_avatar() renders THROUGH the stored
			 * crop, and it is what every other member actually sees. Showing the
			 * master here made "Adjust crop" look like it had done nothing: the
			 * owner cropped their face out, the profile obeyed, and this grid
			 * went on displaying the original.
			 *
			 * set_avatar() names the file with time(), so a re-crop changes the
			 * URL and no cache-busting is needed.
			 */
			if ( $main && function_exists( 'bp_core_fetch_avatar' ) ) {
				$avatar = (string) bp_core_fetch_avatar( array(
					'item_id' => $uid,
					'object'  => 'user',
					'type'    => 'full',
					'html'    => false,
				) );
				if ( '' !== $avatar ) {
					$src = $avatar;
				}
			}
			$html .= '<div class="csm-ph-item' . ( $main ? ' is-main' : '' ) . '" data-id="' . (int) $id . '">';
			// The lightbox still opens the MASTER: the thumbnail answers "what do
			// others see", the lightbox answers "what did I upload", and both are
			// worth being able to check.
			$html .= '<a class="csm-ph-lb" href="' . esc_url( wp_get_attachment_url( $id ) ) . '"><img src="' . esc_url( $src ) . '" alt=""></a>';
			if ( $main ) {
				$html .= '<span class="csm-ph-badge">Main</span>';
				/*
				 * Only on the main photo: it is the one that becomes the avatar,
				 * so it is the only one whose crop is visible to anybody.
				 */
				$html .= '<button type="button" class="csm-ph-crop" data-id="' . (int) $id . '"'
					. ' data-src="' . esc_url( wp_get_attachment_url( $id ) ) . '">Adjust crop</button>';
			} else {
				$html .= '<button type="button" class="csm-ph-setmain" data-id="' . (int) $id . '">Make main</button>';
			}
			$html .= '<button type="button" class="csm-ph-del" data-id="' . (int) $id . '" aria-label="Remove">&times;</button>';
			$html .= '</div>';
		}
		$remaining = $max - count( $ids );
		if ( $remaining > 0 ) {
			$html .= '<label class="csm-ph-add"><input type="file" accept="image/*" multiple hidden><span>+</span><small>Add photo' . ( $remaining > 1 ? 's' : '' ) . '</small></label>';
		}
		$html .= '</div>';
		return $html;
	}

	public static function uploader_html() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$uid   = get_current_user_id();
		$nonce = wp_create_nonce( 'csm_ph' );
		$ajax  = admin_url( 'admin-ajax.php' );

		// Ensure the gallery assets are present wherever the shortcode renders.
		self::enqueue();

		ob_start();
		?>
		<div class="csm-ph" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>" data-max="<?php echo (int) self::max(); ?>">
			<div class="csm-ph-head">
				<h3>Your photos</h3>
				<p>Add up to <?php echo (int) self::max(); ?> photos. The first one is your main profile picture &mdash; make any photo the main one anytime. Please crop your photo before uploading for the best fit (portrait works best).</p>
			</div>
			<div class="csm-ph-body"><?php echo self::grid_html( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="csm-ph-msg" role="status"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------- profile gallery (under header) */

	public static function profile_gallery() {
		if ( ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		// Not on the Photo step (the uploader already shows the grid there).
		if ( function_exists( 'bp_is_user_change_avatar' ) && bp_is_user_change_avatar() ) {
			return;
		}
		$uid = (int) bp_displayed_user_id();
		if ( ! $uid ) {
			return;
		}
		$ids = self::get( $uid );
		if ( empty( $ids ) ) {
			return;
		}

		$is_owner = function_exists( 'bp_is_my_profile' ) && bp_is_my_profile();
		$viewer   = get_current_user_id();

		// Respect the private-photo setting for non-owners.
		if ( ! $is_owner && Privacy::is_hidden( $uid, $viewer ) ) {
			return;
		}

		// Render at most once per request: some themes (BuddyX here) fire
		// bp_after_member_header twice, which would otherwise print two galleries.
		// Guarded AFTER the bail-outs so a first call that legitimately returns
		// early doesn't suppress a later real render.
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		$nonce = $is_owner ? wp_create_nonce( 'csm_ph' ) : '';
		echo '<div class="csm-ph-gallery" data-nonce="' . esc_attr( $nonce ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		echo '<h4>Photos</h4><div class="csm-ph-gwrap">';
		foreach ( $ids as $idx => $id ) {
			$full = wp_get_attachment_url( $id );
			$thmb = wp_get_attachment_image_url( $id, 'medium' );
			$main = ( 0 === $idx );
			echo '<div class="csm-ph-gitem' . ( $main ? ' is-main' : '' ) . '" data-id="' . (int) $id . '">';
			echo '<a class="csm-ph-lb" href="' . esc_url( $full ) . '"><img src="' . esc_url( $thmb ) . '" alt="" loading="lazy"></a>';
			if ( $main ) {
				echo '<span class="csm-ph-gbadge">Main</span>';
			} elseif ( $is_owner ) {
				echo '<button type="button" class="csm-ph-gmain" data-id="' . (int) $id . '">Set as main</button>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( $is_owner && function_exists( 'bp_members_get_user_url' ) ) {
			$edit = trailingslashit( bp_members_get_user_url( $uid ) ) . 'profile/change-avatar/';
			echo '<a class="csm-ph-gmanage" href="' . esc_url( $edit ) . '">Add or remove photos</a>';
		}
		echo '</div>';
	}

	/* ------------------------------------------- privacy notice (#11771) */

	public static function profile_notice() {
		if ( ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		$owner = (int) bp_displayed_user_id();
		if ( ! $owner ) {
			return;
		}
		$viewer = (int) get_current_user_id();
		if ( ! self::pn_is_hidden( $owner, $viewer ) ) {
			return;
		}
		// Render at most once (bp_after_member_header can fire twice — see profile_gallery).
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;
		echo '<div class="csm-photo-notice"><span>';
		echo esc_html( self::pn_notice_text( $owner, $viewer ) );
		echo '</span> <a class="csm-photo-notice-link" href="' . esc_url( self::pn_pricing_url() ) . '">Upgrade to Premium';
		echo '</a></div>';
	}

	/* ---- privacy-notice helpers (#11771) ------------------------------ */

	private static function pn_gender_field_id() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}
		$id = 0;
		if ( function_exists( 'xprofile_get_field_id_from_name' ) ) {
			$id = (int) xprofile_get_field_id_from_name( 'Gender' );
		}
		if ( ! $id ) {
			$id = Config::FIELD_GENDER;
		}
		return $id;
	}

	/** NOTE: female is tested BEFORE male, because "male" is a substring of "female". */
	private static function pn_pronouns( $owner_id ) {
		static $cache = array();
		$owner_id = (int) $owner_id;
		if ( isset( $cache[ $owner_id ] ) ) {
			return $cache[ $owner_id ];
		}
		$g = '';
		if ( function_exists( 'xprofile_get_field_data' ) ) {
			$g = strtolower( trim( (string) xprofile_get_field_data( self::pn_gender_field_id(), $owner_id ) ) );
		}
		if ( 'f' === $g || strpos( $g, 'female' ) !== false || strpos( $g, 'woman' ) !== false || strpos( $g, 'bride' ) !== false ) {
			$p = array( 'sub' => 'she', 'obj' => 'her', 'pos' => 'her' );
		} elseif ( 'm' === $g || strpos( $g, 'male' ) !== false || strpos( $g, 'man' ) !== false || strpos( $g, 'groom' ) !== false ) {
			$p = array( 'sub' => 'he', 'obj' => 'him', 'pos' => 'his' );
		} else {
			$p = array( 'sub' => 'they', 'obj' => 'them', 'pos' => 'their' );
		}
		$cache[ $owner_id ] = $p;
		return $p;
	}

	private static function pn_is_hidden( $owner_id, $viewer_id = null ) {
		return (bool) Privacy::is_hidden( $owner_id, $viewer_id );
	}

	private static function pn_full_url( $owner_id ) {
		if ( ! function_exists( 'bp_core_fetch_avatar' ) ) {
			return '';
		}
		return (string) bp_core_fetch_avatar( array(
			'item_id' => $owner_id,
			'object'  => 'user',
			'type'    => 'full',
			'html'    => false,
		) );
	}

	private static function pn_first_name( $owner_id ) {
		$name = '';
		if ( function_exists( 'bp_core_get_user_displayname' ) ) {
			$name = (string) bp_core_get_user_displayname( $owner_id );
		}
		$first = trim( (string) strtok( $name, ' ' ) );
		if ( '' === $first ) {
			$first = 'This member';
		}
		return $first;
	}

	/**
	 * The blur explanation, for callers outside this class.
	 *
	 * Discover's reveal button says the same thing as the profile gallery's
	 * notice, from the same function — gendered, and aware of whether a request
	 * is already in flight. Two hand-written copies of "match or upgrade" would
	 * have gone out of step the first time either changed.
	 */
	public static function blur_notice( $owner_id, $viewer_id ) {
		return self::pn_notice_text( (int) $owner_id, (int) $viewer_id );
	}

	/** Where "See Premium" goes. */
	public static function blur_upgrade_url() {
		return self::pn_pricing_url();
	}

	private static function pn_notice_text( $owner_id, $viewer_id ) {
		$p      = self::pn_pronouns( $owner_id );
		$first  = self::pn_first_name( $owner_id );
		$out    = $first . ' has chosen to blur ' . $p['pos'] . ' photo, so you are seeing a blurred version.';
		$status = '';
		if ( function_exists( 'friends_check_friendship_status' ) && $viewer_id ) {
			$status = (string) friends_check_friendship_status( (int) $viewer_id, (int) $owner_id );
		}
		if ( 'pending' === $status ) {
			$out .= ' Wait for ' . $p['obj'] . ' to accept your match request, or upgrade to Premium to see it now.';
		} elseif ( 'awaiting_response' === $status ) {
			$out .= ' Accept ' . $p['pos'] . ' match request, or upgrade to Premium to see it now.';
		} else {
			$out .= ' Send ' . $p['obj'] . ' a match request and wait for ' . $p['obj'] . ' to accept it, or upgrade to Premium to see it now.';
		}
		return $out;
	}

	private static function pn_pricing_url() {
		return site_url( '/pricing/' );
	}

	/* ------------------------------------------------------------- assets */

	/** Enqueue the gallery CSS/JS. Idempotent (WP dedupes by handle). */
	public static function enqueue() {
		Assets::style( 'photos-gallery', 'assets/css/photos-gallery.css' );
		// csmConfirm replaces the browser confirm() on photo delete.
		Assets::style( 'app-screens', 'assets/css/app-screens.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'ui-dialog', 'assets/js/ui-dialog.js' );
		// "Adjust crop" re-frames an existing photo, so the cropper has to be
		// here too — not only on the onboarding step that first uploads one.
		// The cropper's stylesheet, not only its script — see cropper.css.
		Assets::style( 'cropper', 'assets/css/cropper.css' );
		Assets::script( 'cropper', 'assets/js/cropper.js' );
		Assets::script( 'app-screens', 'assets/js/app-screens.js', array( 'cashaadi-ui-dialog' ) );
		Assets::script( 'photos-gallery', 'assets/js/photos-gallery.js', array( 'cashaadi-ui-dialog', 'cashaadi-cropper', 'cashaadi-app-screens' ) );
	}

	/**
	 * Front-end assets + the privacy-notice zoom config (window.CSM_PN), which
	 * #11771 printed in wp_footer. Computed here so it lands before the script.
	 */
	public static function assets() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		self::enqueue();

		if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		$owner = (int) bp_displayed_user_id();
		if ( ! $owner ) {
			return;
		}
		$viewer = (int) get_current_user_id();
		$url    = self::pn_full_url( $owner );
		if ( '' === $url ) {
			return;
		}
		$hidden = self::pn_is_hidden( $owner, $viewer );
		$cfg    = array(
			'url'     => $url,
			'hidden'  => $hidden ? 1 : 0,
			'notice'  => $hidden ? self::pn_notice_text( $owner, $viewer ) : '',
			'pricing' => $hidden ? self::pn_pricing_url() : '',
			'cta'     => 'Upgrade to Premium',
		);
		wp_add_inline_script( 'cashaadi-photos-gallery', 'window.CSM_PN=' . wp_json_encode( $cfg ) . ';', 'before' );
	}
}
