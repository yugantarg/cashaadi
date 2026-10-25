<?php
/**
 * Read-only: why does this member not show as Gold?
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/gold-check.php someone@example.com
 * (an email or a user ID)
 */
use CAShaadi\Core\Config;

$arg = $args[0] ?? '';
$u   = is_numeric( $arg ) ? get_userdata( (int) $arg ) : get_user_by( 'email', $arg );
if ( ! $u ) {
	WP_CLI::error( 'No such member: ' . $arg );
}
$uid = (int) $u->ID;
WP_CLI::line( 'Member ' . $uid . ' ' . $u->user_email );

// What PMPro says.
$levels = function_exists( 'pmpro_getMembershipLevelsForUser' ) ? (array) pmpro_getMembershipLevelsForUser( $uid ) : array();
WP_CLI::line( 'PMPro levels now: ' . ( $levels ? implode( ', ', array_map( function ( $l ) {
	return $l->id . ' ' . $l->name . ' (ends ' . ( $l->enddate ? wp_date( 'Y-m-d', (int) $l->enddate ) : 'never' ) . ')';
}, $levels ) ) : 'none' ) );

// What the Gold product is set to give.
foreach ( array( 'Premium' => Config::WC_PREMIUM_PRODUCT, 'Gold' => Config::WC_GOLD_PRODUCT ) as $name => $pid ) {
	WP_CLI::line( $name . ' product ' . $pid . ' gives PMPro level: ' . wp_json_encode( get_post_meta( $pid, '_membership_product_level', true ) ) );
}

// Their recent orders.
if ( function_exists( 'wc_get_orders' ) ) {
	$orders = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
	foreach ( $orders as $o ) {
		if ( ! $o instanceof \WC_Order ) {
			continue;
		}
		$items = array();
		foreach ( $o->get_items() as $it ) {
			$items[] = $it->get_product_id() . ' ' . $it->get_name();
		}
		WP_CLI::line( sprintf( 'Order %d | %s | %s | ₹%s | %s', $o->get_id(), $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '', $o->get_status(), $o->get_total(), implode( '; ', $items ) ) );
	}
}
