<?php
/**
 * Read-only: why a profile does not show in a viewer's Discover.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/why-missing.php viewer@example.com user_86116cfc1fea
 * (the second argument is the profile's username, as in /members/<username>/)
 *
 * Tests each saved filter on its own against the profile, prints the
 * profile's answer for it, and where the profile stands in the viewer's deck.
 */
use CAShaadi\Core\Buckets;
use CAShaadi\Modules\Discover\Filters;

global $wpdb;
$v = get_user_by( 'email', $args[0] ?? '' );
$p = get_user_by( 'slug', $args[1] ?? '' ) ?: get_user_by( 'login', $args[1] ?? '' );
if ( ! $v || ! $p ) {
	WP_CLI::error( 'Give the viewer email and the profile username.' );
}
$saved = Filters::get( $v->ID );
WP_CLI::log( "Viewer {$v->ID} · profile {$p->ID} " . bp_core_get_user_displayname( $p->ID ) . ' · registered ' . $p->user_registered );
WP_CLI::log( 'Saved filters: ' . wp_json_encode( $saved ) );

// Each filter alone: does it keep the profile?
$only = null;
add_filter( 'get_user_metadata', function ( $value, $uid, $key ) use ( &$only, $v ) {
	return ( null !== $only && (int) $uid === (int) $v->ID && Filters::META === $key ) ? array( $only ) : $value;
}, 10, 3 );
$groups = array( 'age' => array( 'age_min', 'age_max' ), 'height' => array( 'in_min', 'in_max' ) );
foreach ( array_keys( Filters::CHOICES ) as $k ) {
	$groups[ $k ] = array( $k );
}
foreach ( $groups as $name => $keys ) {
	$part = array_intersect_key( $saved, array_flip( $keys ) );
	if ( ! $part ) {
		continue;
	}
	$only = $part;
	wp_cache_delete( $v->ID, 'user_meta' );
	$kept = in_array( $p->ID, Filters::keep( $v->ID, array( $p->ID ) ), true );
	$only = null;
	$answer = '';
	if ( isset( Filters::CHOICES[ $name ] ) ) {
		$answer = Filters::own_value( $p->ID, $name );
		if ( Filters::bucket_kind( $name ) ) {
			$answer .= ' → groups: ' . ( implode( ', ', Buckets::get( $p->ID, $name ) ) ?: 'none (counts as Others)' );
		}
	}
	WP_CLI::log( sprintf( '  %-9s %s   profile: %s', $name, $kept ? 'passes' : 'DROPS HIM', $answer ) );
}
wp_cache_delete( $v->ID, 'user_meta' );
WP_CLI::log( 'All filters together: ' . ( in_array( $p->ID, Filters::keep( $v->ID, array( $p->ID ) ), true ) ? 'passes' : 'DROPS HIM' ) );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, week_assigned, assigned_at, acted_at FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND profile_id = %d ORDER BY id DESC LIMIT 1", $v->ID, $p->ID ) );
WP_CLI::log( 'In viewer\'s deck: ' . ( $row ? wp_json_encode( $row ) : 'no (never served)' ) );
WP_CLI::log( 'Friendship: ' . ( function_exists( 'friends_check_friendship_status' ) ? friends_check_friendship_status( $v->ID, $p->ID ) : '?' ) );
