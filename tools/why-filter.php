<?php
/**
 * Read-only: are this member's Discover filters being applied?
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/why-filter.php someone@example.com
 * (an email or a user ID)
 */
use CAShaadi\Core\Buckets;
use CAShaadi\Modules\Discover\Filters;

global $wpdb;
$arg = $args[0] ?? '';
if ( '' === $arg ) {
	// No member named: check everyone who has a community filter saved.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", Filters::META, '%community%' ) );
	if ( ! $ids ) {
		WP_CLI::error( 'Nobody has a community filter saved.' );
	}
	$users = array_filter( array_map( 'get_userdata', array_map( 'intval', $ids ) ) );
} else {
	$u = is_numeric( $arg ) ? get_userdata( (int) $arg ) : get_user_by( 'email', $arg );
	if ( ! $u ) {
		WP_CLI::error( 'No such member: ' . $arg );
	}
	$users = array( $u );
}
foreach ( $users as $u ) {
$uid = (int) $u->ID;
WP_CLI::line( '' );
WP_CLI::line( '==========' );

WP_CLI::line( 'Member ' . $uid . ' ' . $u->user_email );
WP_CLI::line( 'Eligible for filters (Premium/Gold): ' . ( Filters::eligible( $uid ) ? 'yes' : 'NO' ) );
WP_CLI::line( 'Raw saved filters: ' . wp_json_encode( get_user_meta( $uid, Filters::META, true ) ) );
WP_CLI::line( 'Filters in use:    ' . wp_json_encode( Filters::get( $uid ) ) );
WP_CLI::line( 'Community field id: ' . Filters::choice_field( 'community' ) );
WP_CLI::line( 'Members with a community group: ' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", Buckets::meta_key( 'community' ) ) ) );
WP_CLI::line( 'Extra WHERE: ' . trim( preg_replace( '/\s+/', ' ', Filters::where( '', $uid ) ) ) );
WP_CLI::line( 'Pool the filters allow: ' . Filters::pool_size( $uid ) );

$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT profile_id, status, week_assigned, assigned_at FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND status = 'pending' ORDER BY assigned_at ASC",
	$uid
) );
WP_CLI::line( '' );
WP_CLI::line( 'Pending tray (' . count( $rows ) . '): id | assigned | community typed | groups' );
$fid = Filters::choice_field( 'community' );
foreach ( $rows as $r ) {
	$typed = $fid ? xprofile_get_field_data( $fid, (int) $r->profile_id ) : '';
	WP_CLI::line( sprintf(
		'%d | %s | %s | %s',
		$r->profile_id,
		$r->assigned_at,
		is_array( $typed ) ? implode( ', ', $typed ) : $typed,
		implode( ', ', Buckets::get( (int) $r->profile_id, 'community' ) ) ?: '(Others)'
	) );
}
}
