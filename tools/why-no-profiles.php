<?php
/**
 * Read-only: why is this member not getting new profiles?
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/why-no-profiles.php someone@example.com
 * (an email or a user ID)
 */
global $wpdb;
$arg = $args[0] ?? '';
$u   = is_numeric( $arg ) ? get_userdata( (int) $arg ) : get_user_by( 'email', $arg );
if ( ! $u ) {
	WP_CLI::error( 'No such member: ' . $arg );
}
$uid  = (int) $u->ID;
$csm  = cashaadi();
$week = $csm->get_week_id();
$tray = $csm->table( 'tray' );
$gender   = trim( (string) $csm->get_gender( $uid ) );
$opposite = $csm->get_opposite_gender( $uid );

$premium = function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( 2, $uid );
$level   = function_exists( 'pmpro_getMembershipLevelForUser' ) ? pmpro_getMembershipLevelForUser( $uid ) : null;
$quota   = \CAShaadi\Modules\Discover\Discover::quota_for( $uid );
$served  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND week_assigned = %s", $uid, $week ) );
$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND status = 'pending'", $uid ) );
$seen    = \CAShaadi\Modules\Discover\Seen::ids_for( $uid );
$seen    = is_array( $seen ) ? $seen : array();

// Opposite-gender members who exist, and how many of them this member has not seen yet.
$pool = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
	"SELECT user_id FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value = %s",
	\CAShaadi\Core\Config::FIELD_GENDER, $opposite
) ) );
$hidden = array();
if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) { $hidden = array_merge( $hidden, \CAShaadi\Modules\Settings\Closed::ids() ); }
if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) { $hidden = array_merge( $hidden, \CAShaadi\Modules\Settings\Deactivate::paused_ids() ); }
if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) { $hidden = array_merge( $hidden, \CAShaadi\Modules\Photos\PhotoHold::held_ids() ); }
if ( function_exists( 'csm_bl_hidden_ids' ) ) { $hidden = array_merge( $hidden, (array) csm_bl_hidden_ids( $uid ) ); }
$unseen = array_diff( $pool, $seen, array_map( 'intval', $hidden ) );

$filters = class_exists( '\CAShaadi\Modules\Discover\Filters' ) ? \CAShaadi\Modules\Discover\Filters::get( $uid ) : array();
$last    = (string) get_user_meta( $uid, 'csm_active_day', true );

WP_CLI::log( "Member:            #{$uid} {$u->display_name} ({$gender})" );
WP_CLI::log( 'Premium (level 2): ' . ( $premium ? 'YES' : 'NO' ) . ( $level ? " - level {$level->id} {$level->name}" . ( ! empty( $level->enddate ) ? ', ends ' . wp_date( 'j M Y', (int) $level->enddate ) : '' ) : '' ) );
WP_CLI::log( "Weekly quota:      {$quota}" );
WP_CLI::log( "This week ({$week}): {$served} served, {$pending} still unanswered" );
WP_CLI::log( 'Already seen ever: ' . count( $seen ) );
WP_CLI::log( "{$opposite} members:   " . count( $pool ) . ', of which not yet shown to them and not hidden: ' . count( $unseen ) );
WP_CLI::log( 'Filters:           ' . ( $filters ? wp_json_encode( $filters ) : 'none' ) );
WP_CLI::log( 'Last active:       ' . ( '' !== $last ? $last : 'unknown' ) );
WP_CLI::log( '' );
if ( ! $premium ) {
	WP_CLI::warning( 'Not Premium in PMPro: check their WooCommerce order is Completed.' );
} elseif ( $served >= $quota ) {
	WP_CLI::log( "=> They have used this week's {$quota}. New ones arrive on Monday." );
} elseif ( $pending >= $quota ) {
	WP_CLI::log( "=> They still have {$pending} profiles waiting unanswered; new ones come as they like or pass." );
} elseif ( count( $unseen ) < 1 ) {
	WP_CLI::log( '=> They have been shown everyone available. Only new sign-ups can be added.' );
} else {
	WP_CLI::log( '=> Nothing obvious: ' . ( $quota - $served ) . ' slots free and ' . count( $unseen ) . ' unseen members. Ask them to open Discover again, then re-run this.' );
}
