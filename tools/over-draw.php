<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: who got more Discover cards this week than their own allowance,
 * and on which day the extra cards came.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/over-draw.php
 *
 * The allowance is the engine's own (Discover::quota_for: plan, women's
 * sizes, the photo / verified extras). Gold has none and is skipped. Each
 * over-drawn viewer is shown with the cards per day, so cards drawn before
 * the refill lock (v1.89.98, 2026-10-27) can be told from new ones.
 */
use CAShaadi\Core\Membership;
use CAShaadi\Modules\Discover\Discover;

global $wpdb;
$week = cashaadi()->get_week_id();
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT viewer_id, DATE( assigned_at ) d, COUNT(*) n FROM {$wpdb->prefix}csm_tray WHERE week_assigned = %s GROUP BY viewer_id, DATE( assigned_at )",
	$week
) );
$by = array();
foreach ( $rows as $r ) {
	$by[ (int) $r->viewer_id ][ $r->d ] = (int) $r->n;
}
$over  = array();
$days  = array();
$extra = 0;
foreach ( $by as $uid => $per_day ) {
	if ( Membership::is_gold( $uid ) ) {
		continue;
	}
	$quota = Discover::quota_for( $uid );
	$total = array_sum( $per_day );
	if ( $total <= $quota ) {
		continue;
	}
	ksort( $per_day );
	// The extra cards are the ones past the allowance, in the order drawn.
	$seen = 0;
	foreach ( $per_day as $d => $n ) {
		$past = max( 0, min( $n, $seen + $n - $quota ) );
		$days[ $d ] = ( $days[ $d ] ?? 0 ) + $past;
		$seen += $n;
	}
	$extra += $total - $quota;
	$over[] = array( $uid, $total, $quota, $per_day );
}
ksort( $days );
WP_CLI::log( "Week {$week}: " . count( $over ) . " viewer(s) over their own allowance, {$extra} extra cards." );
WP_CLI::log( 'Extra cards by the day they were drawn: ' . wp_json_encode( $days ) );
usort( $over, function ( $a, $b ) { return $b[1] - $b[2] - ( $a[1] - $a[2] ); } );
foreach ( array_slice( $over, 0, 15 ) as $o ) {
	WP_CLI::log( sprintf( '  #%-6d %3d of %-3d %-8s %s', $o[0], $o[1], $o[2], Membership::is_premium( $o[0] ) ? 'premium' : 'free', wp_json_encode( $o[3] ) ) );
}
