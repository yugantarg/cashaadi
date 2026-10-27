<?php
/**
 * Members whose gallery has photos but who have no main picture file
 * (owner, 2026-10-27: Riya, gallery 1 photo, no avatar files). Their cards
 * showed a default picture to everyone, matches and Premium included, and a
 * private photo could not be blurred.
 *
 *   wp csm photos missing                  list them
 *   wp csm photos repair [--dry-run]       rebuild the main picture from the
 *                                          first gallery photo (Gallery::set_avatar)
 *
 * The repair keeps the member's last-active time as it was: set_avatar()
 * stamps it, and a repair is not the member being active. Watch reports any
 * such member daily.
 */

namespace CAShaadi\Modules\Photos;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AvatarRepair {

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm photos missing', function () {
				$ids = AvatarRepair::missing();
				\WP_CLI::log( count( $ids ) . ' members have gallery photos but no main picture file.' . ( $ids ? ' E.g. #' . implode( ', #', array_slice( $ids, 0, 20 ) ) : '' ) );
			} );
			\WP_CLI::add_command( 'csm photos repair', function ( $args, $assoc ) {
				$dry  = ! empty( $assoc['dry-run'] );
				$ok   = 0;
				$fail = array();
				foreach ( AvatarRepair::missing() as $uid ) {
					if ( $dry ) {
						$ok++;
						continue;
					}
					AvatarRepair::repair( $uid ) ? $ok++ : ( $fail[] = $uid );
				}
				\WP_CLI::success( ( $dry ? 'Would repair ' : 'Repaired ' ) . $ok . ( $fail ? '; could not: #' . implode( ', #', $fail ) : '' ) );
			} );
		}
	}

	/** The first gallery photo's attachment id, or 0. */
	public static function first_photo( $uid ) {
		$ids = maybe_unserialize( get_user_meta( (int) $uid, 'csm_photos', true ) );
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		return $ids ? $ids[0] : 0;
	}

	public static function has_avatar_file( $uid ) {
		if ( ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return true;
		}
		foreach ( (array) glob( bp_core_avatar_upload_path() . '/avatars/' . (int) $uid . '/*-bpfull.*' ) as $f ) {
			if ( $f && 0 !== strpos( basename( $f ), 'csm-blur-' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return int[] */
	public static function missing() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'csm_photos' AND meta_value NOT IN ( '', 'a:0:{}' )" ) as $uid ) {
			$uid = (int) $uid;
			if ( self::first_photo( $uid ) && ! self::has_avatar_file( $uid ) ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	public static function repair( $uid ) {
		$att = self::first_photo( $uid );
		if ( ! $att || ! class_exists( __NAMESPACE__ . '\\Gallery' ) ) {
			return false;
		}
		$last = function_exists( 'bp_get_user_meta' ) ? bp_get_user_meta( $uid, 'last_activity', true ) : '';
		$ok   = Gallery::set_avatar( $uid, $att );
		if ( function_exists( 'bp_update_user_meta' ) ) {
			'' === (string) $last ? bp_delete_user_meta( $uid, 'last_activity' ) : bp_update_user_meta( $uid, 'last_activity', $last );
		}
		return (bool) $ok;
	}
}
