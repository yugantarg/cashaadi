<?php
/**
 * Fewer resized copies of member photos (owner, 2026-10-30: "cashaadi seems
 * to be consuming too many inodes").
 *
 * WordPress made about 17 copies of every photo a member uploads: its own
 * sizes plus the theme's, Elementor's and WooCommerce's (100x100, 300x250,
 * 320x240, 350x250, 450x300, 800x500, 900x515 ...). The app only ever shows a
 * member photo at thumbnail, medium, large or full size, so for uploads by
 * members only those three copies are made now. An admin's upload (pages,
 * products, posts) still gets every size, as the theme expects.
 *
 * `wp csm image-sizes slim [--yes]` removes the unused copies already made for
 * member photos (the attachments in csm_photos) and drops them from each
 * photo's metadata. Without --yes it only counts.
 */

namespace CAShaadi\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageSizes {

	const KEEP = array( 'thumbnail', 'medium', 'large' );

	public static function register() {
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'only_used' ), 99 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Synopsis given here: WP-CLI did not read it from the docblock (v1.90.69).
			\WP_CLI::add_command( 'csm image-sizes', array( __CLASS__, 'cli' ), array(
				'synopsis' => array(
					array( 'type' => 'positional', 'name' => 'action', 'optional' => false ),
					array( 'type' => 'flag', 'name' => 'yes', 'optional' => true ),
				),
			) );
		}
	}

	public static function only_used( $sizes ) {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return $sizes;
		}
		return array_intersect_key( (array) $sizes, array_flip( self::KEEP ) );
	}

	/**
	 * wp csm image-sizes slim [--yes]
	 *
	 * ## OPTIONS
	 * <action>
	 * [--yes]
	 * : Delete. Without it, only counts.
	 */
	public static function cli( $args, $assoc ) {
		if ( 'slim' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Usage: wp csm image-sizes slim [--yes]' );
		}
		global $wpdb;
		$go  = ! empty( $assoc['yes'] );
		$ids = array();
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'csm_photos'" ) as $v ) {
			foreach ( (array) maybe_unserialize( $v ) as $id ) {
				if ( (int) $id > 0 ) {
					$ids[ (int) $id ] = 1;
				}
			}
		}
		$keep  = array_flip( self::KEEP );
		$files = 0;
		$bytes = 0;
		$done  = 0;
		foreach ( array_keys( $ids ) as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$main = get_attached_file( $id );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! $main ) {
				continue;
			}
			$dir   = trailingslashit( dirname( $main ) );
			$still = array();
			foreach ( $meta['sizes'] as $name => $s ) {
				if ( isset( $keep[ $name ] ) || empty( $s['file'] ) ) {
					$still[ $name ] = $s;
					continue;
				}
				// A file another kept size still uses (same dimensions) stays.
				$shared = false;
				foreach ( $meta['sizes'] as $n2 => $s2 ) {
					if ( isset( $keep[ $n2 ] ) && ( $s2['file'] ?? '' ) === $s['file'] ) {
						$shared = true;
					}
				}
				$path = $dir . $s['file'];
				if ( ! $shared && file_exists( $path ) && $path !== $main ) {
					$files++;
					$bytes += (int) filesize( $path );
					if ( $go ) {
						wp_delete_file( $path );
					}
				}
			}
			if ( $go && count( $still ) !== count( $meta['sizes'] ) ) {
				$meta['sizes'] = $still;
				wp_update_attachment_metadata( $id, $meta );
				$done++;
			}
		}
		$msg = sprintf( '%d member photos; %d unused copies (%.0f MB).', count( $ids ), $files, $bytes / 1048576 );
		if ( $go ) {
			\WP_CLI::success( $msg . " Deleted; {$done} photos updated." );
		} else {
			\WP_CLI::log( $msg . ' Nothing deleted. Run again with --yes to delete them.' );
		}
	}
}
