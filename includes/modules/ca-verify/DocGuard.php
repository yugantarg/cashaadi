<?php
/**
 * ICAI documents are not public files (security audit, 2026-10-27).
 *
 * They were stored under wp-content/uploads/bpxcftr-profile-uploads/<uid>/
 * and uploads/csm-ca-extra/<uid>/ with predictable names, so anyone who
 * guessed a URL could download a member's certificate. Now:
 *
 *   - both folders carry a deny-all .htaccess (LiteSpeed and Apache honour
 *     it), written on admin_init when missing;
 *   - documents are served through admin-post.php?action=csm_doc, which
 *     streams the file only to an administrator or to the member it belongs
 *     to. url() builds that link; the CA Verify queue, the extra pages and
 *     the member's own link in Edit profile use it.
 *
 * The AI check reads the files from disk, so it is unaffected.
 */

namespace CAShaadi\Modules\CaVerify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DocGuard {

	const DIRS = array( 'bpxcftr-profile-uploads', 'csm-ca-extra' );

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'protect' ) );
		add_action( 'admin_post_csm_doc', array( __CLASS__, 'serve' ) );
	}

	/** Deny-all .htaccess in each document folder (and the folder, if missing). */
	public static function protect() {
		$up = wp_get_upload_dir();
		foreach ( self::DIRS as $d ) {
			$dir  = trailingslashit( $up['basedir'] ) . $d;
			$file = $dir . '/.htaccess';
			if ( file_exists( $file ) ) {
				continue;
			}
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $file, "# ICAI documents: served only through admin-post.php?action=csm_doc (CA Shaadi).\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" );
		}
	}

	/** The gated link for one of a member's documents: 'doc', or an extra page's file name. */
	public static function url( $uid, $which = 'doc' ) {
		return add_query_arg( array( 'action' => 'csm_doc', 'uid' => (int) $uid, 'f' => rawurlencode( (string) $which ) ), admin_url( 'admin-post.php' ) );
	}

	public static function serve() {
		$me  = get_current_user_id();
		$uid = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$f   = isset( $_GET['f'] ) ? sanitize_file_name( rawurldecode( wp_unslash( $_GET['f'] ) ) ) : 'doc'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $me || ! $uid || ( $me !== $uid && ! current_user_can( 'manage_options' ) ) ) {
			wp_die( 'Not available.', 403 );
		}
		$path = '';
		if ( 'doc' === $f ) {
			$doc  = CaVerify::doc( $uid );
			$path = $doc && ! empty( $doc['path'] ) ? $doc['path'] : '';
		} else {
			foreach ( CaVerify::extra_files( $uid ) as $x ) {
				if ( $x['name'] === $f ) {
					$path = $x['path'];
				}
			}
		}
		$real = $path ? realpath( $path ) : false;
		$base = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) ) {
			wp_die( 'Not found.', 404 );
		}
		$type = wp_check_filetype( $real );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $real ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
