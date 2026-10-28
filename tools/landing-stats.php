<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: the numbers the new landing page could show (owner, 2026-10-28:
 * "show live data instead of 2000 CAs joined"), as they stand today, so the
 * owner can choose which go on the page and the floor for each.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/landing-stats.php
 *
 * Members: accounts that are not admins and not closed. Private numbers
 * (gender split, revenue) are printed under a separate heading, for the
 * owner only; they are not candidates for the page.
 */
use CAShaadi\Core\Config;

global $wpdb;
$closed = class_exists( '\CAShaadi\Modules\Settings\Closed' ) ? array_map( 'intval', (array) \CAShaadi\Modules\Settings\Closed::ids() ) : array();
$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
$skip   = array_unique( array_merge( array( 0 ), $closed, $admins ) );
$not    = 'AND u.ID NOT IN (' . implode( ',', $skip ) . ')';
$count  = function ( $sql ) use ( $wpdb ) { return (int) $wpdb->get_var( $sql ); }; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ago    = function ( $days ) { return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ); };
$act    = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = u.ID AND a.type = 'last_activity' AND a.date_recorded >= '%s' )";

$rows = array(
	'Members'                       => $count( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE 1=1 {$not}" ),
	'Members with a photo'          => $count( "SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'csm_photos' AND m.meta_value NOT IN ( '', 'a:0:{}' ) WHERE 1=1 {$not}" ),
	'ICAI-verified (CA / CA Inter)' => $count( "SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'csm_av_status' AND m.meta_value = 'approved' WHERE 1=1 {$not}" ),
	'Matches made (accepted)'       => $count( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_friends WHERE is_confirmed = 1" ),
	'Requests sent (all time, still on record)' => $count( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_friends" ),
	'Active in the last 7 days'     => $count( sprintf( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE {$act} {$not}", $ago( 7 ) ) ),
	'Active in the last 30 days'    => $count( sprintf( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE {$act} {$not}", $ago( 30 ) ) ),
	'Joined in the last 7 days'     => $count( sprintf( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.user_registered >= '%s' {$not}", $ago( 7 ) ) ),
	'Joined in the last 30 days'    => $count( sprintf( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.user_registered >= '%s' {$not}", $ago( 30 ) ) ),
	'Cities (as members typed them)' => $count( $wpdb->prepare( "SELECT COUNT( DISTINCT LOWER( TRIM( d.value ) ) ) FROM {$wpdb->prefix}bp_xprofile_data d JOIN {$wpdb->users} u ON u.ID = d.user_id WHERE d.field_id = %d AND d.value <> '' {$not}", Config::FIELD_CITY ) ),
	'City groups'                   => $count( "SELECT COUNT( DISTINCT m.meta_value ) FROM {$wpdb->usermeta} m JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE m.meta_key = 'csm_bucket_city' AND m.meta_value NOT IN ( '', 'a:0:{}' ) {$not}" ),
);
WP_CLI::log( 'Candidates for the landing page:' );
foreach ( $rows as $k => $v ) {
	WP_CLI::log( sprintf( '  %-42s %s', $k, number_format_i18n( $v ) ) );
}

WP_CLI::log( 'Owner only (not for the page):' );
foreach ( array( 'Male', 'Female' ) as $g ) {
	WP_CLI::log( sprintf( '  %-42s %s', $g, number_format_i18n( $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_xprofile_data d JOIN {$wpdb->users} u ON u.ID = d.user_id WHERE d.field_id = %d AND d.value = %s {$not}", Config::FIELD_GENDER, $g ) ) ) ) );
}
