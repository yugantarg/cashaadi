<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: security audit part B (server and WordPress settings).
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/audit-b.php
 *
 * Prints settings and lists, never a secret: wp-config values are shown only
 * for the switches below, and snippets only by title, status and size.
 */
global $wpdb;
$out = function ( $k, $v ) { WP_CLI::log( sprintf( '  %-34s %s', $k, is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : $v ) ); };

WP_CLI::log( 'Versions' );
$out( 'WordPress', get_bloginfo( 'version' ) );
$out( 'PHP', PHP_VERSION );
$core = get_site_transient( 'update_core' );
$out( 'core update offered', isset( $core->updates[0]->current ) ? $core->updates[0]->current : '?' );

WP_CLI::log( 'wp-config switches' );
foreach ( array( 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED', 'WP_ENVIRONMENT_TYPE' ) as $c ) {
	$out( $c, defined( $c ) ? var_export( constant( $c ), true ) : '(not set)' );
}
$out( 'display_errors (php)', ini_get( 'display_errors' ) );
$out( 'table prefix is wp_', 'wp_' === $wpdb->prefix );
$salts = 0;
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $c ) {
	$salts += ( defined( $c ) && strlen( constant( $c ) ) >= 32 && false === strpos( constant( $c ), 'put your unique phrase' ) ) ? 1 : 0;
}
$out( 'strong salts (of 8)', $salts );

WP_CLI::log( 'Files' );
$cfg = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
$out( 'wp-config.php mode', file_exists( $cfg ) ? substr( sprintf( '%o', fileperms( $cfg ) ), -4 ) : 'missing' );
$out( '.htaccess mode', file_exists( ABSPATH . '.htaccess' ) ? substr( sprintf( '%o', fileperms( ABSPATH . '.htaccess' ) ), -4 ) : 'missing' );
foreach ( array( WP_CONTENT_DIR . '/debug.log', ABSPATH . 'error_log', WP_CONTENT_DIR . '/error_log' ) as $f ) {
	$out( str_replace( ABSPATH, '', $f ), file_exists( $f ) ? size_format( filesize( $f ) ) : 'none' );
}
foreach ( array( 'readme.html', 'license.txt', 'wp-config-sample.php' ) as $f ) {
	$out( $f, file_exists( ABSPATH . $f ) ? 'present' : 'none' );
}
$up   = wp_get_upload_dir()['basedir'];
$php  = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $up, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( preg_match( '/\.(php\d?|phtml|phar)$/i', $f->getFilename() ) ) {
		$php[] = str_replace( $up, 'uploads', $f->getPathname() );
	}
}
$out( 'PHP files in uploads', count( $php ) );
foreach ( array_slice( $php, 0, 15 ) as $f ) {
	WP_CLI::log( '      ' . $f );
}
$ww = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( $f->isFile() && ( $f->getPerms() & 0002 ) ) {
		$ww[] = str_replace( ABSPATH, '', $f->getPathname() );
	}
}
$out( 'world-writable files', count( $ww ) );
foreach ( array_slice( $ww, 0, 10 ) as $f ) {
	WP_CLI::log( '      ' . $f );
}

WP_CLI::log( 'Users' );
$out( 'anyone can register', (bool) get_option( 'users_can_register' ) );
$out( 'default role', get_option( 'default_role' ) );
foreach ( get_users( array( 'role__in' => array( 'administrator', 'editor', 'shop_manager' ), 'fields' => array( 'ID', 'user_login', 'user_registered' ) ) ) as $u ) {
	$roles = implode( ',', get_userdata( $u->ID )->roles );
	$last  = $wpdb->get_var( $wpdb->prepare( "SELECT MAX( date_recorded ) FROM {$wpdb->prefix}bp_activity WHERE user_id = %d AND type = 'last_activity'", $u->ID ) );
	WP_CLI::log( sprintf( '      #%d %-24s %-22s joined %s, last active %s', $u->ID, $u->user_login, $roles, substr( $u->user_registered, 0, 10 ), $last ? substr( $last, 0, 10 ) : 'never' ) );
}

WP_CLI::log( 'Plugins (active / inactive, update?)' );
wp_update_plugins();
$upd = get_site_transient( 'update_plugins' );
foreach ( get_plugins() as $file => $p ) {
	$new = isset( $upd->response[ $file ] ) ? ' → ' . $upd->response[ $file ]->new_version : '';
	WP_CLI::log( sprintf( '      %-8s %-40s %s%s', is_plugin_active( $file ) ? 'active' : 'off', substr( $p['Name'], 0, 40 ), $p['Version'], $new ) );
}
WP_CLI::log( 'Themes' );
foreach ( wp_get_themes() as $slug => $t ) {
	WP_CLI::log( sprintf( '      %-8s %-30s %s', get_stylesheet() === $slug || get_template() === $slug ? 'in use' : 'off', $slug, $t->get( 'Version' ) ) );
}

WP_CLI::log( 'WPCode snippets (title, status, type, size; no code)' );
foreach ( $wpdb->get_results( "SELECT ID, post_title, post_status, LENGTH( post_content ) n FROM {$wpdb->posts} WHERE post_type = 'wpcode' ORDER BY post_status, ID" ) as $s ) {
	$terms = wp_get_object_terms( (int) $s->ID, 'wpcode_type', array( 'fields' => 'slugs' ) );
	WP_CLI::log( sprintf( '      #%-5d %-8s %-5s %6d  %s', $s->ID, $s->post_status, is_array( $terms ) ? implode( ',', $terms ) : '', $s->n, $s->post_title ) );
}
