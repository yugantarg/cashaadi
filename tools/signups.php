<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: when signups happen and where they come from (owner, 2026-10-28:
 * "my registrations fell sharply today").
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/signups.php [days]
 *
 * Signups per day (IST) with their channel, signups per hour for the last
 * three days, so a drop can be pinned to the hour it began, and the time
 * each plugin folder last changed (updates), to line the two up.
 */
global $wpdb;
$days = max( 3, (int) ( $args[0] ?? 10 ) );
$ist  = 'DATE_ADD( u.user_registered, INTERVAL 330 MINUTE )';
$from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
$now  = wp_date( 'Y-m-d H:i' );
WP_CLI::log( "Now (IST): {$now}" );

WP_CLI::log( 'Signups per day (IST), by channel:' );
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT DATE( {$ist} ) d, COALESCE( NULLIF( m.meta_value, '' ), '(none)' ) ch, COUNT(*) n
	 FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'csm_channel'
	 WHERE u.user_registered >= %s GROUP BY d, ch ORDER BY d, n DESC",
	$from
) );
$by = array();
foreach ( $rows as $r ) {
	$by[ $r->d ][ $r->ch ] = (int) $r->n;
}
foreach ( $by as $d => $chs ) {
	$parts = array();
	foreach ( $chs as $c => $n ) {
		$parts[] = "{$c} {$n}";
	}
	WP_CLI::log( sprintf( '  %s  %4d   %s', $d, array_sum( $chs ), implode( ', ', $parts ) ) );
}

WP_CLI::log( 'Signups per hour (IST), last 3 days:' );
$h = $wpdb->get_results( $wpdb->prepare(
	"SELECT DATE( {$ist} ) d, HOUR( {$ist} ) hr, COUNT(*) n FROM {$wpdb->users} u WHERE u.user_registered >= %s GROUP BY d, hr ORDER BY d, hr",
	gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
) );
$grid = array();
foreach ( $h as $r ) {
	$grid[ $r->d ][ (int) $r->hr ] = (int) $r->n;
}
WP_CLI::log( '  hour        ' . implode( ' ', array_map( function ( $x ) { return sprintf( '%2d', $x ); }, range( 0, 23 ) ) ) );
foreach ( $grid as $d => $hrs ) {
	$line = '';
	for ( $i = 0; $i < 24; $i++ ) {
		$line .= sprintf( '%3s', isset( $hrs[ $i ] ) ? $hrs[ $i ] : '.' );
	}
	WP_CLI::log( '  ' . $d . $line );
}

WP_CLI::log( 'Started but did not finish signup today (accounts made, profile not complete):' );
$today = gmdate( 'Y-m-d H:i:s', strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 Asia/Kolkata' ) );
$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_registered >= %s", $today ) );
$done  = 0;
foreach ( $ids as $id ) {
	if ( function_exists( 'csm_user_profile_is_complete' ) && csm_user_profile_is_complete( (int) $id ) ) {
		$done++;
	}
}
WP_CLI::log( sprintf( '  %d accounts today, %d with a complete profile', count( $ids ), $done ) );

WP_CLI::log( 'Plugin folders changed in the last 2 days (IST):' );
foreach ( glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
	$t = filemtime( $dir );
	if ( $t > time() - 2 * DAY_IN_SECONDS ) {
		WP_CLI::log( sprintf( '  %s  %s', wp_date( 'Y-m-d H:i', $t ), basename( $dir ) ) );
	}
}
WP_CLI::log( '  WordPress itself: ' . wp_date( 'Y-m-d H:i', filemtime( ABSPATH . 'wp-includes/version.php' ) ) );
