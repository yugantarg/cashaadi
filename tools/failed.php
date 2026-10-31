<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file tools/failed.php [days]
/*
 * Failed and cancelled orders (owner, 2026-10-31, after PhonePe was found
 * rejecting payments as "Invalid Merchant"). Per person: how many tries,
 * whether PhonePe's error was the cause, and whether they have paid since.
 * Read-only.
 */
$days   = max( 1, (int) ( $args[0] ?? 30 ) );
$orders = wc_get_orders( array(
	'status'       => array( 'failed', 'cancelled' ),
	'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
	'limit'        => 500,
) );
$people = array();
foreach ( $orders as $o ) {
	$email = strtolower( (string) $o->get_billing_email() );
	if ( '' === $email || false !== strpos( $email, 'yugantargupta' ) ) {
		continue;
	}
	$pp = false;
	foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) as $n ) {
		if ( false !== stripos( $n->content, 'Invalid Merchant' ) || false !== stripos( $n->content, 'PhonePe Payment Solutions: Payment' ) ) {
			$pp = true;
			break;
		}
	}
	$p = $people[ $email ] ?? array( 'tries' => 0, 'phonepe' => 0, 'last' => '', 'uid' => (int) $o->get_customer_id() );
	$p['tries']++;
	$p['phonepe'] += $pp ? 1 : 0;
	$d = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '';
	$p['last'] = max( $p['last'], $d );
	$people[ $email ] = $p;
}
$rows = array();
foreach ( $people as $email => $p ) {
	$uid = $p['uid'] ?: ( ( $u = get_user_by( 'email', $email ) ) ? (int) $u->ID : 0 );
	$paid = wc_get_orders( array( 'billing_email' => $email, 'status' => array( 'processing', 'completed' ), 'limit' => 1 ) );
	$plan = $uid && class_exists( '\CAShaadi\Core\Membership' ) ? ( \CAShaadi\Core\Membership::is_gold( $uid ) ? 'Gold' : ( \CAShaadi\Core\Membership::is_premium( $uid ) ? 'Premium' : 'free' ) ) : '-';
	$rows[] = array(
		'email'        => $email,
		'tries'        => $p['tries'],
		'phonepe error' => $p['phonepe'] ? 'yes' : '',
		'last try'     => $p['last'],
		'paid since'   => $paid ? 'yes' : 'no',
		'plan now'     => $plan,
	);
}
usort( $rows, function ( $a, $b ) { return strcmp( $b['last try'], $a['last try'] ); } );
WP_CLI::log( "Last {$days} days, " . count( $rows ) . ' people:' );
WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ?? array( 'email' => '' ) ) );
