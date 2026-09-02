<?php
/**
 * Private Photo — blur for non-matches (migrated from WPCode #11770).
 *
 * Owner opts in via user meta csm_photo_private = '1'. The photo stays CLEAR for
 * the owner, admins, premium (PMPro level 2), confirmed matches, and anyone the
 * OWNER has liked (asymmetric). Everyone else gets a cached blurred derivative
 * (detail destroyed, not softened). Fails SAFE: if a blur can't be produced the
 * default avatar is shown, never the real photo.
 *
 * Reuses the same meta key and csm-blur-* cache files as the snippet, so existing
 * opt-ins and cached blurs carry over. Registered only when Config::photos_enabled().
 * Keeps the apply_filters('csm_photo_is_hidden', …) extension point so the
 * photo-request / NSFW components can compose with this decision.
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {

	public static function register() {
		add_filter( 'bp_core_fetch_avatar_url', array( __CLASS__, 'filter_url' ), 20, 2 );
		add_filter( 'bp_core_fetch_avatar', array( __CLASS__, 'filter_html' ), 20, 2 );
		add_filter( 'get_avatar_url', array( __CLASS__, 'filter_wp_url' ), 20, 2 );

		// Member-facing control on the Change Profile Photo screen.
		add_action( 'bp_after_profile_avatar_upload_content', array( __CLASS__, 'privacy_control' ) );
		add_action( 'bp_after_member_avatar_upload_content', array( __CLASS__, 'privacy_control' ) );
		add_shortcode( 'csm_photo_privacy', array( __CLASS__, 'privacy_shortcode' ) );
		add_action( 'bp_actions', array( __CLASS__, 'privacy_save' ) );

		// WP admin user-profile control.
		add_action( 'show_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'admin_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'admin_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'admin_save' ) );
	}

	/* ---- decision ------------------------------------------------------ */

	public static function enabled( $owner_id ) {
		return '1' === (string) get_user_meta( (int) $owner_id, 'csm_photo_private', true );
	}

	/** Did the OWNER like the VIEWER? (asymmetric reveal) */
	public static function owner_liked_viewer( $owner_id, $viewer_id ) {
		global $wpdb;
		static $table_ok = null;

		$owner_id  = (int) $owner_id;
		$viewer_id = (int) $viewer_id;
		$table     = $wpdb->prefix . 'csm_likes';

		if ( null === $table_ok ) {
			$table_ok = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		if ( $table_ok ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE viewer_id = %d AND profile_id = %d', $owner_id, $viewer_id ) );
			if ( $found > 0 ) {
				return true;
			}
		}
		if ( function_exists( 'friends_check_friendship_status' ) ) {
			// 'pending' = the owner sent the request → reveal.
			if ( 'pending' === friends_check_friendship_status( $owner_id, $viewer_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** The single decision point. */
	public static function is_hidden( $owner_id, $viewer_id = null ) {
		static $memo = array();

		$owner_id = (int) $owner_id;
		if ( $owner_id < 1 ) {
			return false;
		}
		if ( null === $viewer_id ) {
			$viewer_id = get_current_user_id();
		}
		$viewer_id = (int) $viewer_id;

		$key = $owner_id . ':' . $viewer_id;
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$hidden = true;
		if ( ! self::enabled( $owner_id ) ) {
			$hidden = false;
		} elseif ( $viewer_id === $owner_id ) {
			$hidden = false;
		} elseif ( $viewer_id < 1 ) {
			$hidden = true;
		} elseif ( user_can( $viewer_id, 'manage_options' ) ) {
			$hidden = false;
		} elseif ( Membership::is_premium( $viewer_id ) ) {
			$hidden = false;
		} elseif ( function_exists( 'friends_check_friendship' ) && friends_check_friendship( $owner_id, $viewer_id ) ) {
			$hidden = false;
		} elseif ( self::owner_liked_viewer( $owner_id, $viewer_id ) ) {
			$hidden = false;
		}

		$hidden = (bool) apply_filters( 'csm_photo_is_hidden', $hidden, $owner_id, $viewer_id );

		$memo[ $key ] = $hidden;
		return $hidden;
	}

	/* ---- blur derivative ----------------------------------------------- */

	private static function source_file( $owner_id, $type = 'full' ) {
		if ( ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return '';
		}
		$dir = bp_core_avatar_upload_path() . '/avatars/' . (int) $owner_id;
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		$needle = ( 'thumb' === $type ) ? '-bpthumb.' : '-bpfull.';
		$files  = glob( $dir . '/*' );
		if ( empty( $files ) ) {
			return '';
		}
		foreach ( $files as $file ) {
			$base = basename( $file );
			if ( 0 === strpos( $base, 'csm-blur-' ) ) {
				continue;
			}
			if ( false !== strpos( $base, $needle ) ) {
				return $file;
			}
		}
		return '';
	}

	private static function make_blur( $src, $dest, $type = 'full' ) {
		$tiny = ( 'thumb' === $type ) ? 16 : 24;
		$out  = ( 'thumb' === $type ) ? 150 : 450;

		if ( class_exists( 'Imagick' ) ) {
			try {
				$img = new \Imagick( $src );
				$img->setImageFormat( 'jpeg' );
				$img->thumbnailImage( $tiny, 0 );
				$img->resizeImage( $out, 0, \Imagick::FILTER_GAUSSIAN, 1 );
				$img->gaussianBlurImage( 10, 5 );
				$img->setImageCompressionQuality( 82 );
				$img->stripImage();
				$written = $img->writeImage( $dest );
				$img->clear();
				$img->destroy();
				if ( $written ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// fall through to GD
			}
		}

		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return false;
		}
		$data = @file_get_contents( $src ); // phpcs:ignore
		if ( empty( $data ) ) {
			return false;
		}
		$orig = @imagecreatefromstring( $data ); // phpcs:ignore
		if ( ! $orig ) {
			return false;
		}
		$ow = imagesx( $orig );
		$oh = imagesy( $orig );
		if ( $ow < 1 || $oh < 1 ) {
			imagedestroy( $orig );
			return false;
		}
		$tw    = $tiny;
		$th    = max( 1, (int) round( $oh * ( $tiny / $ow ) ) );
		$small = imagecreatetruecolor( $tw, $th );
		imagecopyresampled( $small, $orig, 0, 0, 0, 0, $tw, $th, $ow, $oh );

		$bw  = $out;
		$bh  = max( 1, (int) round( $th * ( $out / $tw ) ) );
		$big = imagecreatetruecolor( $bw, $bh );
		imagecopyresampled( $big, $small, 0, 0, 0, 0, $bw, $bh, $tw, $th );

		for ( $i = 0; $i < 3; $i++ ) {
			imagefilter( $big, IMG_FILTER_GAUSSIAN_BLUR );
		}
		$ok = imagejpeg( $big, $dest, 82 );

		imagedestroy( $orig );
		imagedestroy( $small );
		imagedestroy( $big );
		return (bool) $ok;
	}

	private static function blurred_url( $owner_id, $type = 'full' ) {
		$owner_id = (int) $owner_id;
		$type     = ( 'thumb' === $type ) ? 'thumb' : 'full';

		$src = self::source_file( $owner_id, $type );
		if ( '' === $src || ! function_exists( 'bp_core_avatar_url' ) ) {
			return '';
		}
		$stamp = (int) @filemtime( $src ); // phpcs:ignore
		$name  = 'csm-blur-' . $type . '-' . substr( md5( basename( $src ) . '|' . $stamp ), 0, 12 ) . '.jpg';
		$dest  = dirname( $src ) . '/' . $name;

		if ( ! file_exists( $dest ) ) {
			if ( ! self::make_blur( $src, $dest, $type ) ) {
				return '';
			}
		}
		return bp_core_avatar_url() . '/avatars/' . $owner_id . '/' . $name;
	}

	/**
	 * Fail-safe: blurred derivative, else default avatar, never the real photo.
	 *
	 * Public because Core\Profile needs it for the "how others see me" preview.
	 * The bp_core_fetch_avatar filters cannot serve that case: they ask
	 * is_hidden() with no viewer, which resolves to the CURRENT user — and in the
	 * preview the current user is the owner, who is never hidden from themselves.
	 * So the preview received the real photograph while telling the member it was
	 * showing them a stranger's view. Callers that already know the answer to
	 * "may this viewer see it" ask for the display URL directly.
	 */
	public static function display_url( $owner_id, $type, $original ) {
		$blur = self::blurred_url( $owner_id, $type );
		if ( '' !== $blur ) {
			return $blur;
		}
		if ( function_exists( 'bp_core_avatar_default' ) ) {
			$default = bp_core_avatar_default( 'local' );
			if ( ! empty( $default ) ) {
				return $default;
			}
		}
		return $original;
	}

	/* ---- param helpers + filters --------------------------------------- */

	private static function params_user( $params ) {
		if ( ! is_array( $params ) ) {
			return 0;
		}
		if ( isset( $params['object'] ) && 'user' !== $params['object'] ) {
			return 0;
		}
		return isset( $params['item_id'] ) ? (int) $params['item_id'] : 0;
	}

	private static function params_type( $params ) {
		if ( is_array( $params ) && isset( $params['type'] ) && 'thumb' === $params['type'] ) {
			return 'thumb';
		}
		return 'full';
	}

	public static function filter_url( $url, $params = array() ) {
		$owner = self::params_user( $params );
		if ( $owner < 1 || ! self::is_hidden( $owner ) ) {
			return $url;
		}
		return self::display_url( $owner, self::params_type( $params ), $url );
	}

	public static function filter_html( $html, $params = array() ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$owner = self::params_user( $params );
		if ( $owner < 1 || ! self::is_hidden( $owner ) ) {
			return $html;
		}
		$new = self::display_url( $owner, self::params_type( $params ), '' );
		if ( '' === $new ) {
			return $html;
		}
		$html = preg_replace( '/ src="[^"]*"/', ' src="' . esc_url( $new ) . '"', $html, 1 );
		$html = preg_replace( '/ srcset="[^"]*"/', '', $html, 1 );
		$html = preg_replace( '/ class="/', ' class="csm-photo-blurred ', $html, 1 );
		return $html;
	}

	public static function filter_wp_url( $url, $id_or_email ) {
		$owner = 0;
		if ( is_numeric( $id_or_email ) ) {
			$owner = (int) $id_or_email;
		} elseif ( $id_or_email instanceof \WP_User ) {
			$owner = (int) $id_or_email->ID;
		} elseif ( $id_or_email instanceof \WP_Comment && ! empty( $id_or_email->user_id ) ) {
			$owner = (int) $id_or_email->user_id;
		}
		if ( $owner < 1 || ! self::is_hidden( $owner ) ) {
			return $url;
		}
		return self::display_url( $owner, 'full', $url );
	}

	/* ---- member + admin controls --------------------------------------- */

	public static function privacy_shortcode() {
		ob_start();
		self::privacy_control( true );
		return ob_get_clean();
	}

	public static function privacy_control( $force = false ) {
		static $done = false;
		if ( $done || ! is_user_logged_in() ) {
			return;
		}
		if ( ! $force && ( ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) ) {
			return;
		}
		$uid = get_current_user_id();
		$on  = self::enabled( $uid );
		$done = true;

		echo '<div class="csm-photo-privacy" style="margin:24px 0;padding:16px 18px;border:1px solid #e2e4e7;border-radius:8px;background:#fafafa;">';
		echo '<h4 style="margin:0 0 10px;">Photo privacy</h4>';
		echo '<form method="post">';
		wp_nonce_field( 'csm_photo_privacy', 'csm_photo_privacy_nonce' );
		echo '<label style="display:block;margin:0 0 8px;"><input type="checkbox" name="csm_photo_private" value="1" ' . checked( $on, true, false ) . ' /> Blur my photo for people I have not matched with</label>';
		echo '<p style="margin:0 0 12px;color:#555;font-size:13px;">Your matches always see your photo. Premium members, and anyone you have liked, can also see it clearly.</p>';
		echo '<button type="submit" class="button">Save photo privacy</button>';
		echo '</form></div>';
	}

	public static function privacy_save() {
		if ( empty( $_POST['csm_photo_privacy_nonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csm_photo_privacy_nonce'] ) ), 'csm_photo_privacy' ) ) {
			return;
		}
		// Never write a member preference while switched into their account.
		if ( function_exists( 'current_user_switched' ) && current_user_switched() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! empty( $_POST['csm_photo_private'] ) ) {
			update_user_meta( $uid, 'csm_photo_private', '1' );
		} else {
			delete_user_meta( $uid, 'csm_photo_private' );
		}
		if ( function_exists( 'bp_core_add_message' ) ) {
			bp_core_add_message( 'Photo privacy saved.' );
		}
	}

	public static function admin_field( $user ) {
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}
		$on = ( '1' === get_user_meta( $user->ID, 'csm_photo_private', true ) );
		echo '<h2>CAShaadi Photo Privacy</h2>';
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th><label for="csm_photo_private_admin">Blur photo for non-matches</label></th>';
		echo '<td><label><input type="checkbox" name="csm_photo_private" id="csm_photo_private_admin" value="1" ' . ( $on ? 'checked="checked"' : '' ) . ' /> ';
		echo 'Blur this photo for people this member has not matched with</label>';
		echo '<p class="description">Matches always see the photo. Premium members, and anyone this member has liked, can also see it clearly. Administrators always see it clearly.</p>';
		echo '</td></tr></table>';
		wp_nonce_field( 'csm_photo_privacy_admin', 'csm_photo_privacy_admin_nonce' );
	}

	public static function admin_save( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( function_exists( 'current_user_switched' ) && current_user_switched() ) {
			return;
		}
		if ( empty( $_POST['csm_photo_privacy_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csm_photo_privacy_admin_nonce'] ) ), 'csm_photo_privacy_admin' ) ) {
			return;
		}
		if ( ! empty( $_POST['csm_photo_private'] ) ) {
			update_user_meta( $user_id, 'csm_photo_private', '1' );
		} else {
			delete_user_meta( $user_id, 'csm_photo_private' );
		}
	}
}
