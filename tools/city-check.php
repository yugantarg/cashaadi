<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: why a profile card shows no city.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/city-check.php viewer@example.com
 *
 * Takes three profiles from the viewer's Discover tray and prints, for each,
 * the stored City value, what the card code reads, and whether the field is
 * hidden from this viewer.
 */
use CAShaadi\Core\Config;
use CAShaadi\Core\Profile;

global $wpdb;
$arg = $args[0] ?? '';
$v   = is_numeric( $arg ) ? get_userdata( (int) $arg ) : get_user_by( 'email', $arg );
if ( ! $v ) {
	WP_CLI::error( 'Give the viewer email.' );
}
wp_set_current_user( $v->ID );
$fid  = (int) Config::FIELD_CITY;
$f    = xprofile_get_field( $fid );
WP_CLI::log( 'Field ' . $fid . ': ' . ( $f ? '"' . $f->name . '" (' . $f->type . ')' : 'NOT FOUND' ) . ' · by name "City": ' . (int) xprofile_get_field_id_from_name( 'City' ) );

$pids = $wpdb->get_col( $wpdb->prepare( "SELECT profile_id FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d ORDER BY id DESC LIMIT 3", $v->ID ) );
foreach ( $pids as $pid ) {
	$pid    = (int) $pid;
	$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND user_id = %d", $fid, $pid ) );
	$hidden = Profile::hidden_for( $pid, $v->ID );
	$p      = Profile::full( $pid, $v->ID );
	WP_CLI::log( sprintf(
		"#%d stored=%s | get_field_data=%s | hidden=%s | card city=%s | cityLine=%s | visibility=%s",
		$pid,
		var_export( $raw, true ),
		var_export( xprofile_get_field_data( $fid, $pid ), true ),
		in_array( $fid, (array) $hidden, true ) ? 'YES' : 'no',
		var_export( $p['city'] ?? null, true ),
		var_export( $p['cityLine'] ?? null, true ),
		xprofile_get_field_visibility_level( $fid, $pid )
	) );
}
