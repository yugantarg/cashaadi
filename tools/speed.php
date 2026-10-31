<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file tools/speed.php <member email>
/*
 * Read-only speed check (owner, 2026-10-31). Times the main app calls as one
 * member, with their database query counts, and the usual site-wide slowdowns.
 * Note: the Discover queue may deliver that member's newcomer or bonus card,
 * exactly as opening Discover would.
 */
global $wpdb;
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}
$email = $args[0] ?? '';
$u     = ( $email && false !== strpos( $email, '@' ) ) ? get_user_by( 'email', $email ) : false;
if ( ! $u ) {
	// No email given: the Premium member with the most requests received, a heavy case.
	foreach ( (array) $wpdb->get_col( "SELECT profile_id FROM {$wpdb->prefix}csm_seen WHERE action = 'liked' GROUP BY profile_id ORDER BY COUNT(*) DESC LIMIT 50" ) as $id ) {
		if ( \CAShaadi\Core\Membership::is_premium( (int) $id ) && ! user_can( (int) $id, 'manage_options' ) ) {
			$u = get_userdata( (int) $id );
			break;
		}
	}
}
if ( ! $u ) {
	WP_CLI::error( 'Usage: wp eval-file tools/speed.php [member email]' );
}
wp_set_current_user( $u->ID );

$run = function ( $label, $fn ) use ( $wpdb ) {
	$q0 = $wpdb->num_queries;
	$wpdb->queries = array();
	wp_cache_flush(); // every run cold, as a real visit is without an object cache
	$t0 = microtime( true );
	$fn();
	$ms = round( 1000 * ( microtime( true ) - $t0 ) );
	$qs = (array) $wpdb->queries;
	$qt = 0;
	$slow = array();
	foreach ( $qs as $q ) {
		$qt += $q[1];
		$k = substr( preg_replace( '/\d+/', 'N', preg_replace( '/\s+/', ' ', $q[0] ) ), 0, 90 );
		$slow[ $k ] = ( $slow[ $k ] ?? 0 ) + $q[1];
	}
	arsort( $slow );
	WP_CLI::log( sprintf( '%-34s %6d ms  %5d queries  %5d ms in queries', $label, $ms, $wpdb->num_queries - $q0, round( 1000 * $qt ) ) );
	foreach ( array_slice( $slow, 0, 3, true ) as $k => $t ) {
		WP_CLI::log( sprintf( '      %5d ms  %s', round( 1000 * $t ), $k ) );
	}
};

$rest = function ( $route ) {
	return function () use ( $route ) {
		$req = new WP_REST_Request( 'GET', $route );
		rest_do_request( $req );
	};
};

WP_CLI::log( "As #{$u->ID}.\n" );
$only = function ( $tab ) {
	return function () use ( $tab ) {
		$req = new WP_REST_Request( 'GET', '/csm/v1/requests/list' );
		$req->set_param( 'only', $tab );
		rest_do_request( $req );
	};
};
$run( 'Requests: Received tab', $only( 'received' ) );
$run( 'Requests: Matches tab', $only( 'matches' ) );
$run( 'Requests: all tabs (old way)', $rest( '/csm/v1/requests/list' ) );
$run( 'Discover queue', $rest( '/csm/v1/discover/queue' ) );

$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT profile_id FROM {$wpdb->prefix}csm_seen WHERE viewer_id = %d LIMIT 24", $u->ID ) ) );
if ( $ids && class_exists( '\CAShaadi\Core\Profile' ) ) {
	$run( '24 profile cards (Profile::full)', function () use ( $ids, $u ) {
		foreach ( $ids as $id ) {
			\CAShaadi\Core\Profile::full( $id, $u->ID );
		}
	} );
	$run( '24 avatars only', function () use ( $ids ) {
		foreach ( $ids as $id ) {
			bp_core_fetch_avatar( array( 'item_id' => $id, 'type' => 'full', 'html' => false ) );
		}
	} );
}

WP_CLI::log( '' );
$auto = $wpdb->get_row( "SELECT COUNT(*) n, SUM( LENGTH( option_value ) ) b FROM {$wpdb->options} WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' )" );
WP_CLI::log( sprintf( 'Autoloaded options: %d, %.1f MB (loaded on every page; above ~1 MB slows everything).', $auto->n, $auto->b / 1048576 ) );
foreach ( $wpdb->get_results( "SELECT option_name, LENGTH( option_value ) b FROM {$wpdb->options} WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' ) ORDER BY b DESC LIMIT 8" ) as $r ) {
	WP_CLI::log( sprintf( '      %7.0f KB  %s', $r->b / 1024, $r->option_name ) );
}
$tr = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
WP_CLI::log( "Transients in the options table: {$tr}." );
WP_CLI::log( 'Object cache: ' . ( wp_using_ext_object_cache() ? 'yes (' . get_class( $GLOBALS['wp_object_cache'] ) . ')' : 'NO - every page reads everything from the database' ) );
WP_CLI::log( 'OPcache: ' . ( function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ? 'on' : 'off or not visible from the command line' ) );
WP_CLI::log( 'Active plugins: ' . count( (array) get_option( 'active_plugins' ) ) );
foreach ( array( 'csm_event_log', 'csm_seen', 'csm_tray', 'csm_impressions', 'csm_email_queue', 'csm_profile_views', 'bp_activity', 'bp_notifications', 'usermeta', 'options' ) as $t ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT TABLE_ROWS r, ROUND( ( DATA_LENGTH + INDEX_LENGTH ) / 1048576 ) mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . $t ) );
	if ( $row ) {
		WP_CLI::log( sprintf( '  %-22s %10d rows %6d MB', $t, $row->r, $row->mb ) );
	}
}
