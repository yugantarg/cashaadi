<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Security audit part B fixes that live outside the plugin (2026-10-27).
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/harden.php          (shows what it would do)
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/harden.php apply
 *
 *  1. The site's PHP error_log was downloadable at /error_log. It is moved
 *     next to public_html (kept, not deleted), and the root .htaccess denies
 *     error_log, *.log, readme.html, license.txt and wp-config-sample.php.
 *  2. uploads/ refuses to run PHP: an uploaded script can never execute.
 *
 * Rules go in between "# BEGIN CA Shaadi security" markers with WordPress's
 * insert_with_markers(), so running it again replaces them rather than
 * adding a copy. Each .htaccess is backed up first.
 */
require_once ABSPATH . 'wp-admin/includes/misc.php';

$apply = 'apply' === ( $args[0] ?? '' );
$deny  = array(
	'<IfModule mod_authz_core.c>',
	'	Require all denied',
	'</IfModule>',
	'<IfModule !mod_authz_core.c>',
	'	Order allow,deny',
	'	Deny from all',
	'</IfModule>',
);
$jobs = array(
	ABSPATH . '.htaccess' => array_merge( array( '<FilesMatch "(^error_log|\.log|^readme\.html|^license\.txt|^wp-config-sample\.php)$">' ), $deny, array( '</FilesMatch>' ) ),
	wp_get_upload_dir()['basedir'] . '/.htaccess' => array_merge( array( '<FilesMatch "\.(php\d?|phtml|phar)$">' ), $deny, array( '</FilesMatch>' ) ),
);

$log  = ABSPATH . 'error_log';
$dest = dirname( untrailingslashit( ABSPATH ) ) . '/logs';
if ( file_exists( $log ) ) {
	$to = $dest . '/error_log-' . gmdate( 'Ymd-His' );
	WP_CLI::log( ( $apply ? 'Moving' : 'Would move' ) . " error_log ({$log}, " . size_format( filesize( $log ) ) . ") to {$to}" );
	if ( $apply ) {
		wp_mkdir_p( $dest );
		rename( $log, $to ) || WP_CLI::warning( 'Could not move error_log.' );
	}
}

foreach ( $jobs as $file => $lines ) {
	WP_CLI::log( ( $apply ? 'Writing' : 'Would write' ) . " to {$file}:\n  " . implode( "\n  ", $lines ) );
	if ( ! $apply ) {
		continue;
	}
	if ( file_exists( $file ) ) {
		copy( $file, $file . '.bak-' . gmdate( 'Ymd-His' ) );
	}
	insert_with_markers( $file, 'CA Shaadi security', $lines ) || WP_CLI::warning( "Could not write {$file}." );
}
WP_CLI::success( $apply ? 'Done. Check the site loads, then the curl checks.' : 'Nothing changed. Add "apply" to do it.' );
