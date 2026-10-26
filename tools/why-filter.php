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
// Everyone of the other gender the filters allow, and how many of them this member has not been shown yet.
$opp   = cashaadi()->get_opposite_gender( $uid );
$match = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
	"SELECT xp.user_id FROM {$wpdb->prefix}bp_xprofile_data xp WHERE xp.field_id = %d AND xp.value = %s" . Filters::where( '', $uid ),
	\CAShaadi\Core\Config::FIELD_GENDER, $opp
) ) );
$seen  = \CAShaadi\Modules\Discover\Seen::ids_for( $uid );
$seen  = is_array( $seen ) ? array_map( 'intval', $seen ) : array();
WP_CLI::line( 'Members the filters allow: ' . count( $match ) . ' | already shown to this member: ' . count( array_intersect( $match, $seen ) ) . ' | not yet shown: ' . count( array_diff( $match, $seen ) ) );
$qf = \CAShaadi\Core\Config::FIELD_QUALIFICATION;
$qs = $wpdb->get_results( $wpdb->prepare(
	"SELECT COALESCE( NULLIF( q.value, '' ), '(blank)' ) v, COUNT(*) n FROM {$wpdb->prefix}bp_xprofile_data g
	 LEFT JOIN {$wpdb->prefix}bp_xprofile_data q ON q.user_id = g.user_id AND q.field_id = %d
	 WHERE g.field_id = %d AND g.value = %s GROUP BY v ORDER BY n DESC",
	$qf, \CAShaadi\Core\Config::FIELD_GENDER, $opp
) );
WP_CLI::line( 'Qualification among ' . $opp . ' members: ' . implode( ', ', array_map( function ( $r ) { return $r->v . ' ' . $r->n; }, $qs ) ) );
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
