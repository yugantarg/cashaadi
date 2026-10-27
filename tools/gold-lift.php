<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: how much more is a Gold profile shown?
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/gold-lift.php someone@example.com [days]
 *
 * Two comparisons, both from wp_csm_impressions (one row per time a profile
 * was put into someone's Discover set):
 *   1. This member: showings per day before Gold vs since Gold.
 *   2. Every Gold profile vs ordinary profiles of the same gender that are
 *      active and have a photo, over the last [days] (default 7).
 */
use CAShaadi\Core\Config;

global $wpdb;
$arg  = $args[0] ?? '';
$days = max( 1, (int) ( $args[1] ?? 7 ) );
$imp  = $wpdb->prefix . 'csm_impressions';
$xp   = $wpdb->prefix . 'bp_xprofile_data';

$u = is_numeric( $arg ) ? get_userdata( (int) $arg ) : get_user_by( 'email', $arg );
if ( ! $u ) {
	WP_CLI::error( 'No such member: ' . $arg );
}
$uid    = (int) $u->ID;
$gender = (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$xp} WHERE user_id = %d AND field_id = %d", $uid, Config::FIELD_GENDER ) );

// 1. Before and since Gold, from the first serving that carried the Gold bonus.
$since = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN( served_at ) FROM {$imp} WHERE profile_id = %d AND parts LIKE %s", $uid, '%"gold"%' ) );
$first = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN( served_at ) FROM {$imp} WHERE profile_id = %d", $uid ) );
$all   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$imp} WHERE profile_id = %d", $uid ) );
WP_CLI::line( "Member {$uid} {$u->user_email} ({$gender}): shown {$all} times in total." );
if ( '' === $since ) {
	WP_CLI::line( 'No showing with the Gold bonus yet.' );
} else {
	$now    = current_time( 'mysql' );
	$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$imp} WHERE profile_id = %d AND served_at < %s", $uid, $since ) );
	$after  = $all - $before;
	$d_b    = $first && $first < $since ? max( 1, ( strtotime( $since ) - strtotime( $first ) ) / DAY_IN_SECONDS ) : 0;
	$d_a    = max( 1, ( strtotime( $now ) - strtotime( $since ) ) / DAY_IN_SECONDS );
	WP_CLI::line( sprintf( 'Before Gold: %d showings over %.1f days = %.1f a day.', $before, $d_b, $d_b ? $before / $d_b : 0 ) );
	WP_CLI::line( sprintf( 'Since Gold (%s): %d showings over %.1f days = %.1f a day.', $since, $after, $d_a, $after / $d_a ) );
	if ( $d_b && $before ) {
		WP_CLI::line( sprintf( 'Lift for this member: %.1fx.', ( $after / $d_a ) / ( $before / $d_b ) ) );
	}
}

// 2. Gold vs comparable ordinary profiles, same gender, last $days days.
$from  = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
$act   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
$gold  = \CAShaadi\Modules\Premium\Gold::ids_among( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$xp} WHERE field_id = %d AND value = %s", Config::FIELD_GENDER, $gender ) ) ) );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$peers = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
	"SELECT g.user_id FROM {$xp} g
	 JOIN {$wpdb->usermeta} ph ON ph.user_id = g.user_id AND ph.meta_key = 'csm_photos' AND ph.meta_value LIKE %s AND ph.meta_value <> 'a:0:{}'
	 WHERE g.field_id = %d AND g.value = %s
	   AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = g.user_id AND a.type = 'last_activity' AND a.date_recorded > %s )",
	'a:%', Config::FIELD_GENDER, $gender, $act
) ) );
$peers = array_values( array_diff( $peers, $gold ) );

$avg = function ( array $ids ) use ( $wpdb, $imp, $from ) {
	if ( ! $ids ) {
		return 0;
	}
	$in = implode( ',', array_map( 'intval', $ids ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$imp} WHERE served_at >= %s AND profile_id IN ({$in})", $from ) );
	return $n / count( $ids );
};
$g = $avg( $gold );
$p = $avg( $peers );
WP_CLI::line( '' );
WP_CLI::line( sprintf( 'Last %d days, %s profiles:', $days, $gender ) );
WP_CLI::line( sprintf( '  Gold (%d profiles): %.1f showings each.', count( $gold ), $g ) );
WP_CLI::line( sprintf( '  Ordinary, active with a photo (%d profiles): %.1f showings each.', count( $peers ), $p ) );
if ( $p > 0 && $gold ) {
	WP_CLI::line( sprintf( '  Gold is shown %.1fx as often.', $g / $p ) );
}
WP_CLI::line( 'Test accounts are counted as Gold too; with only a few Gold profiles the figure is rough.' );
