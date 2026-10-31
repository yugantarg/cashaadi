<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file tools/member.php "<name or email>"
/*
 * Read-only: one member's payments and membership, to answer "paid but shows
 * cancelled" (owner, 2026-10-31). Changes nothing.
 */
global $wpdb;
$q = trim( (string) ( $args[0] ?? '' ) );
if ( '' === $q ) {
	WP_CLI::error( 'Usage: wp eval-file tools/member.php "<name or email>"' );
}
$like  = '%' . $wpdb->esc_like( $q ) . '%';
$users = $wpdb->get_results( $wpdb->prepare(
	"SELECT ID, user_email, display_name, user_registered FROM {$wpdb->users} WHERE user_email LIKE %s OR display_name LIKE %s OR user_login LIKE %s LIMIT 10",
	$like, $like, $like
) );
if ( ! $users ) {
	WP_CLI::error( 'No member found.' );
}
foreach ( $users as $u ) {
	$uid = (int) $u->ID;
	WP_CLI::log( "\n=== #{$uid} {$u->display_name} <{$u->user_email}> joined {$u->user_registered}" );

	$lvl = function_exists( 'pmpro_getMembershipLevelForUser' ) ? pmpro_getMembershipLevelForUser( $uid ) : null;
	WP_CLI::log( 'Membership now: ' . ( $lvl ? "{$lvl->name} (level {$lvl->id}), ends " . ( $lvl->enddate ? wp_date( 'Y-m-d H:i', (int) $lvl->enddate ) : 'never' ) : 'NONE' ) );

	WP_CLI::log( "\nMembership history (newest first):" );
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT membership_id, status, initial_payment, startdate, enddate, modified FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d ORDER BY id DESC LIMIT 10", $uid ) ) as $r ) {
		WP_CLI::log( "  level {$r->membership_id}  {$r->status}  ₹{$r->initial_payment}  start {$r->startdate}  end {$r->enddate}  changed {$r->modified}" );
	}

	if ( function_exists( 'wc_get_orders' ) ) {
		WP_CLI::log( "\nShop orders (newest first):" );
		$orders = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
		$email  = wc_get_orders( array( 'billing_email' => $u->user_email, 'limit' => 10 ) );
		$seen   = array();
		foreach ( array_merge( $orders, $email ) as $o ) {
			if ( isset( $seen[ $o->get_id() ] ) ) {
				continue;
			}
			$seen[ $o->get_id() ] = 1;
			$items = array();
			foreach ( $o->get_items() as $it ) {
				$items[] = $it->get_name() . ' (product ' . $it->get_product_id() . ')';
			}
			WP_CLI::log( sprintf( '  #%d  %s  %s  total ₹%s  paid %s  via %s  coupons [%s]  %s',
				$o->get_id(), $o->get_status(), $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '-',
				$o->get_total(), $o->get_date_paid() ? $o->get_date_paid()->date( 'Y-m-d H:i' ) : 'no',
				$o->get_payment_method_title(), implode( ',', $o->get_coupon_codes() ), implode( '; ', $items ) ) );
			foreach ( array_slice( wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 8 ) ), 0, 8 ) as $n ) {
				WP_CLI::log( '      note ' . $n->date_created->date( 'Y-m-d H:i' ) . ': ' . wp_strip_all_tags( $n->content ) );
			}
		}
		if ( ! $seen ) {
			WP_CLI::log( '  none' );
		}
	}

	WP_CLI::log( "\nPurchase events:" );
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT created_at, event_type, metadata FROM {$wpdb->prefix}csm_event_log WHERE actor_id = %d AND ( event_type LIKE %s OR event_type LIKE %s OR event_type LIKE %s ) ORDER BY id DESC LIMIT 10", $uid, '%purchase%', '%refund%', '%membership%' ) ) as $e ) {
		WP_CLI::log( "  {$e->created_at}  {$e->event_type}  " . substr( (string) $e->metadata, 0, 160 ) );
	}
}
