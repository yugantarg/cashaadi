<?php
defined( 'ABSPATH' ) || exit; // run with wp eval-file only
/**
 * Read-only: every distinct answer in the free-text filter fields (Community
 * on this install), with how many members gave it. For grouping spellings.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/text-values.php > ~/text-values.txt
 */
global $wpdb;
$f = '\CAShaadi\Modules\Discover\Filters';
foreach ( array( 'community', 'tongue', 'religion', 'diet', 'income', 'qual' ) as $k ) {
	$fid = $f::choice_field( $k );
	if ( ! $fid || $f::choice_options( $k ) ) {
		continue; // not a field here, or it has fixed options
	}
	echo "== {$k} (field {$fid})\n";
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT LOWER(TRIM(value)) v, COUNT(*) n FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> '' GROUP BY v ORDER BY n DESC, v",
		$fid
	) );
	foreach ( $rows as $r ) {
		echo $r->n, "\t", $r->v, "\n";
	}
}
