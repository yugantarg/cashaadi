<?php
/**
 * Read-only: why a profile card shows (or hides) a phone number.
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/phone-check.php viewer@example.com "Profile name"
 *
 * Prints every input Profile::phone() decides on, and its answer.
 */
use CAShaadi\Core\Config;
use CAShaadi\Core\Profile;
use CAShaadi\Modules\Premium\Gold;
use CAShaadi\Modules\Settings\SettingsScreen;

global $wpdb, $wp_filter;
$v = get_user_by( 'email', $args[0] ?? '' );
if ( ! $v ) {
	WP_CLI::error( 'Give the viewer email first.' );
}
$like = '%' . $wpdb->esc_like( $args[1] ?? '' ) . '%';
$pids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = 1 AND value LIKE %s", $like ) );
if ( ! $pids ) {
	WP_CLI::error( 'No profile with that name.' );
}
wp_set_current_user( $v->ID );
WP_CLI::log( "Viewer {$v->ID} {$v->user_email}: premium=" . ( \CAShaadi\Core\Membership::is_premium( $v->ID ) ? 'yes' : 'no' )
	. ' gold=' . ( \CAShaadi\Core\Membership::is_gold( $v->ID ) ? 'yes' : 'no' )
	. ' female=' . ( Gold::is_female( $v->ID ) ? 'yes' : 'no' )
	. ' phone_live=' . ( Gold::phone_live( $v->ID ) ? 'yes' : 'no' ) );
$hooks = isset( $wp_filter['csm_phone_visible'] ) ? count( $wp_filter['csm_phone_visible']->callbacks ) : 0;
WP_CLI::log( "Hooks on csm_phone_visible: {$hooks}" );
foreach ( $pids as $pid ) {
	$pid = (int) $pid;
	$views = $wpdb->get_results( $wpdb->prepare( "SELECT viewed_at FROM {$wpdb->prefix}csm_phone_views WHERE viewer_id = %d AND profile_id = %d", $v->ID, $pid ) );
	$p = Profile::phone( $pid, $v->ID );
	WP_CLI::log( sprintf(
		'#%d %s | level=%s audience=%s | can_reveal=%s | opened=%s | friendship=%s | card state=%s',
		$pid,
		bp_core_get_user_displayname( $pid ),
		SettingsScreen::effective_level( Config::FIELD_PHONE, $pid ),
		SettingsScreen::phone_audience( $pid ),
		Gold::can_reveal( $v->ID, $pid ) ? 'yes' : 'no',
		$views ? implode( ', ', wp_list_pluck( $views, 'viewed_at' ) ) : 'never',
		function_exists( 'friends_check_friendship_status' ) ? friends_check_friendship_status( $v->ID, $pid ) : '?',
		$p['state']
	) );
}
