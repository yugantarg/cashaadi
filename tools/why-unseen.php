<?php
/**
 * Read-only: why are some active members shown to nobody? Low scores, or
 * bad luck?
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/why-unseen.php [Male|Female] [days]
 *
 * Takes the active members of that gender with a photo who were shown to
 * nobody in the period, and a sample of viewers who were shown cards. For
 * each viewer it scores the unseen members with Discover's own ranker and
 * compares them with the LOWEST score among the cards that viewer actually
 * got. Then it compares the average score parts of unseen and shown members.
 */
use CAShaadi\Core\Config;
use CAShaadi\Modules\Discover\Ranker;

global $wpdb;
$gender = in_array( $args[0] ?? '', array( 'Male', 'Female' ), true ) ? $args[0] : 'Male';
$other  = 'Male' === $gender ? 'Female' : 'Male';
$days   = max( 1, (int) ( $args[1] ?? 7 ) );
$since  = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
$imp    = $wpdb->prefix . 'csm_impressions';
$ev     = $wpdb->prefix . 'csm_event_log';

// Active members of this gender with a photo, joined over a week ago.
$members = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
	"SELECT u.ID FROM {$wpdb->users} u
	 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value = %s
	 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ( '', 'a:0:{}' )
	 WHERE u.user_registered < %s
	   AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = u.ID AND a.type = 'last_activity' AND a.date_recorded >= %s )",
	Config::FIELD_GENDER, $gender, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS )
) ) );
$shown = array_flip( array_map( 'intval', array_merge(
	$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT profile_id FROM {$imp} WHERE served_at >= %s", $since ) ),
	$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT actor_id FROM {$ev} WHERE event_type IN ( 'boost_shown', 'intro_shown' ) AND created_at >= %s", $since ) )
) ) );
$unseen = array_values( array_filter( $members, function ( $id ) use ( $shown ) { return ! isset( $shown[ $id ] ); } ) );
WP_CLI::log( sprintf( '%d active %s with a photo; %d shown to nobody in %d days.', count( $members ), 'Male' === $gender ? 'men' : 'women', count( $unseen ), $days ) );
if ( ! $unseen ) {
	return;
}
shuffle( $unseen );
$sample = array_slice( $unseen, 0, 150 );

// Viewers of the other gender who were drawn cards in the period, and the lowest score they got.
$viewers = $wpdb->get_results( $wpdb->prepare(
	"SELECT i.viewer_id, MIN( i.score ) low, COUNT(*) n FROM {$imp} i
	 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = i.viewer_id AND g.field_id = %d AND g.value = %s
	 WHERE i.served_at >= %s GROUP BY i.viewer_id ORDER BY RAND() LIMIT 60",
	Config::FIELD_GENDER, $other, $since
) );
WP_CLI::log( count( $viewers ) . ' sample viewers; each unseen member scored for each of them.' );

$rows = array();
foreach ( $sample as $id ) {
	$u = get_userdata( $id );
	$rows[] = (object) array( 'user_id' => $id, 'shown' => 0, 'shown_wk' => 0, 'liked' => 0, 'req_wk' => 0, 'active' => 1, 'registered' => $u ? $u->user_registered : '' );
}
$pairs = 0;
$beat  = 0;
$beats = array();
$parts = array();
$n     = 0;
foreach ( $viewers as $v ) {
	$ranked = Ranker::rank( (int) $v->viewer_id, $rows, count( $rows ), false, PHP_INT_MAX, PHP_INT_MAX );
	foreach ( $ranked as $r ) {
		$pairs++;
		if ( $r['score'] >= (float) $v->low ) {
			$beat++;
			$beats[ $r['id'] ] = true;
		}
		foreach ( $r['parts'] as $k => $p ) {
			$parts[ $k ] = ( $parts[ $k ] ?? 0 ) + $p;
		}
		$n++;
	}
}
WP_CLI::log( sprintf( 'Unseen member vs a viewer\'s lowest card: scores at least as high in %d%% of %d pairs; %d of %d sampled unseen members beat at least one viewer\'s lowest card.',
	$pairs ? round( 100 * $beat / $pairs ) : 0, $pairs, count( $beats ), count( $sample ) ) );

// Average score parts: unseen (as scored above) vs the cards actually shown.
$shown_parts = array();
$m = 0;
foreach ( $wpdb->get_col( $wpdb->prepare(
	"SELECT i.parts FROM {$imp} i JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = i.profile_id AND g.field_id = %d AND g.value = %s WHERE i.served_at >= %s AND i.parts IS NOT NULL LIMIT 5000",
	Config::FIELD_GENDER, $gender, $since
) ) as $json ) {
	$p = json_decode( (string) $json, true );
	if ( ! is_array( $p ) ) {
		continue;
	}
	foreach ( $p as $k => $val ) {
		$shown_parts[ $k ] = ( $shown_parts[ $k ] ?? 0 ) + (float) $val;
	}
	$m++;
}
WP_CLI::log( 'Average score parts (unseen as scored for the sample viewers | cards actually shown):' );
$keys = array_unique( array_merge( array_keys( $parts ), array_keys( $shown_parts ) ) );
$tot_u = 0;
$tot_s = 0;
foreach ( $keys as $k ) {
	$a = $n ? ( $parts[ $k ] ?? 0 ) / $n : 0;
	$b = $m ? ( $shown_parts[ $k ] ?? 0 ) / $m : 0;
	$tot_u += $a;
	$tot_s += $b;
	WP_CLI::log( sprintf( '  %-14s %6.2f | %6.2f', $k, $a, $b ) );
}
WP_CLI::log( sprintf( '  %-14s %6.2f | %6.2f', 'TOTAL', $tot_u, $tot_s ) );
