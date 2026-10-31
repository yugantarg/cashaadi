<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file
global $wpdb;
$gid  = (int) xprofile_get_field_id_from_name( 'Gender' );
$from = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
$to   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
$ids  = $wpdb->get_col( $wpdb->prepare(
	"SELECT u.ID FROM {$wpdb->users} u
	 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value IN ('Male','Female')
	 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ('', 'a:0:{}')
	 WHERE u.user_registered BETWEEN %s AND %s
	   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}csm_seen s WHERE s.profile_id = u.ID )
	   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id = u.ID AND m.meta_key IN ('csm_deactivated','csm_photo_hold') AND m.meta_value <> '' )",
	$gid, $from, $to ) );
echo "id\tgender\tjoined\tintro_start\treach\tlast_active\tcity\tclosed\tin_trays\n";
foreach ( $ids as $id ) {
	$u     = get_userdata( $id );
	$start = (int) get_user_meta( $id, 'csm_intro_start', true );
	$last  = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $id ) : '';
	$trays = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_tray WHERE profile_id = %d", $id ) );
	echo implode( "\t", array(
		$id,
		cashaadi()->get_gender( $id ),
		$u->user_registered,
		$start ? wp_date( 'Y-m-d H:i', $start ) : '-',
		(int) get_user_meta( $id, 'csm_intro_reach', true ),
		$last ?: '-',
		(string) xprofile_get_field_data( 'City', $id ),
		get_user_meta( $id, 'csm_closed', true ) ? 'yes' : '',
		$trays,
	) ) . "\n";
}
echo "\nIntros given per day (last 10 days), by newcomer gender:\n";
foreach ( $wpdb->get_results(
	"SELECT DATE(e.created_at) d, g.value gender, COUNT(*) n, COUNT(DISTINCT e.actor_id) newcomers
	 FROM {$wpdb->prefix}csm_event_log e
	 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = e.actor_id AND g.field_id = {$gid}
	 WHERE e.event_type = 'intro_shown' AND e.created_at >= NOW() - INTERVAL 10 DAY
	 GROUP BY d, gender ORDER BY d" ) as $r ) {
	echo "{$r->d}\t{$r->gender}\t{$r->n} intros\t{$r->newcomers} newcomers\n";
}
echo "\nNew members with a photo per day (last 10 days):\n";
foreach ( $wpdb->get_results(
	"SELECT DATE(u.user_registered) d, g.value gender, COUNT(*) n FROM {$wpdb->users} u
	 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = {$gid}
	 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ('', 'a:0:{}')
	 WHERE u.user_registered >= NOW() - INTERVAL 10 DAY GROUP BY d, gender ORDER BY d" ) as $r ) {
	echo "{$r->d}\t{$r->gender}\t{$r->n}\n";
}
