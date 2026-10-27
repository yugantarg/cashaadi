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

// The same exclusions csm_refill_tray() applies before ranking.
$csm = function_exists( 'cashaadi' ) ? cashaadi() : null;
$gid = (int) xprofile_get_field_id_from_name( 'Gender' );
$opp = $csm ? $csm->get_opposite_gender( $v->ID ) : '?';
$his = (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND user_id = %d", $gid, $p->ID ) );
WP_CLI::log( sprintf( 'Gender: wanted %s, his stored value %s → %s', var_export( $opp, true ), var_export( $his, true ), $his === $opp ? 'ok' : 'EXCLUDED' ) );
$checks = array();
if ( $csm && $csm->table_exists( 'likes' ) ) {
	$checks['in likes table'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $csm->table( 'likes' ) . ' WHERE viewer_id = %d AND profile_id = %d', $v->ID, $p->ID ) );
}
if ( class_exists( '\CAShaadi\Modules\Discover\Seen' ) ) {
	$seen = \CAShaadi\Modules\Discover\Seen::ids_for( $v->ID );
	$checks['held back as seen'] = is_array( $seen ) && in_array( $p->ID, array_map( 'intval', $seen ), true );
}
if ( function_exists( 'csm_bl_hidden_ids' ) ) {
	$checks['blocked'] = in_array( $p->ID, array_map( 'intval', (array) csm_bl_hidden_ids( $v->ID ) ), true );
}
if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
	$checks['paused'] = in_array( $p->ID, array_map( 'intval', (array) \CAShaadi\Modules\Settings\Deactivate::paused_ids() ), true );
}
if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) {
	$checks['photo held for review'] = \CAShaadi\Modules\Photos\PhotoHold::is_held( $p->ID );
	if ( $checks['photo held for review'] ) {
		WP_CLI::log( 'Hold: ' . wp_json_encode( \CAShaadi\Modules\Photos\PhotoHold::get( $p->ID ) ) );
	}
}
foreach ( $checks as $what => $yes ) {
	WP_CLI::log( sprintf( '  %-22s %s', $what, $yes ? 'YES → EXCLUDED' : 'no' ) );
}
WP_CLI::log( 'Gold filter in refill SQL: ' . ( '' !== Filters::where_gold( '', $v->ID ) ? 'on' : 'off' ) );
