<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file tools/gateways.php [off <gateway id>]
/*
 * Payment gateways (owner, 2026-10-31: "disable the phonepe gateway, it's
 * inactive"). Lists every WooCommerce gateway with its state. With
 * "off <id>" it switches that one off, but only if another gateway stays on,
 * so checkout is never left with no way to pay.
 */
if ( ! function_exists( 'WC' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}
$all = WC()->payment_gateways()->payment_gateways();
$on  = array();
foreach ( $all as $id => $g ) {
	$state = 'yes' === $g->enabled ? 'ON ' : 'off';
	if ( 'yes' === $g->enabled ) {
		$on[] = $id;
	}
	WP_CLI::log( sprintf( '%s  %-28s %s', $state, $id, wp_strip_all_tags( $g->get_title() ) ) );
}
if ( 'off' !== ( $args[0] ?? '' ) ) {
	return;
}
$target = (string) ( $args[1] ?? '' );
if ( ! isset( $all[ $target ] ) ) {
	WP_CLI::error( "No gateway with id '{$target}'." );
}
if ( 'yes' !== $all[ $target ]->enabled ) {
	WP_CLI::success( "{$target} is already off." );
	return;
}
if ( count( array_diff( $on, array( $target ) ) ) < 1 ) {
	WP_CLI::error( "{$target} is the only gateway switched on; turning it off would leave checkout with no way to pay. Nothing changed." );
}
$all[ $target ]->update_option( 'enabled', 'no' );
WP_CLI::success( "{$target} switched off. Still on: " . implode( ', ', array_diff( $on, array( $target ) ) ) );
