<?php
/**
 * wp csm spotlight product   create the "Profile boosts" pack (3 for ₹799)
 * wp csm spotlight give <email> [--n=1]   add boosts to a member
 * wp csm spotlight status    running boosts and their reach
 */

namespace CAShaadi\Modules\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SpotlightCli {

	/** Create the pack as a hidden, virtual WooCommerce product (once). */
	public function product() {
		$id = Spotlight::product_id();
		if ( $id && function_exists( 'wc_get_product' ) && wc_get_product( $id ) ) {
			\WP_CLI::success( "Already exists: product {$id}." );
			return;
		}
		if ( ! class_exists( '\WC_Product_Simple' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}
		$p = new \WC_Product_Simple();
		$p->set_name( Spotlight::PACK . ' Profile Boosts' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( (string) Spotlight::PRICE );
		$p->set_virtual( true );
		$p->set_sold_individually( false );
		$p->set_description( Spotlight::PACK . ' profile boosts. Each puts your profile at the front of Discover for 24 hours. They never expire.' );
		$id = $p->save();
		update_option( Spotlight::PRODUCT, (int) $id, false );
		\WP_CLI::success( "Created product {$id} at ₹" . Spotlight::PRICE . '.' );
	}

	/**
	 * ## OPTIONS
	 * <member>
	 * [--n=<n>]
	 */
	public function give( $args, $assoc ) {
		$u = is_numeric( $args[0] ) ? get_userdata( (int) $args[0] ) : get_user_by( 'email', $args[0] );
		if ( ! $u ) {
			\WP_CLI::error( 'No such member.' );
		}
		$n = max( 1, (int) ( $assoc['n'] ?? 1 ) );
		update_user_meta( $u->ID, Spotlight::CREDITS, Spotlight::credits( $u->ID ) + $n );
		\WP_CLI::success( "{$u->user_email} now has " . Spotlight::credits( $u->ID ) . ' bought boosts.' );
	}

	public function status() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > %d", Spotlight::UNTIL, time() ) );
		\WP_CLI::log( 'Pack product: ' . ( Spotlight::product_id() ?: 'not created (wp csm spotlight product)' ) );
		\WP_CLI::log( count( $rows ) . ' boosts running.' );
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r->user_id );
			\WP_CLI::log( sprintf( '  %s · reached %d of %d · ends %s', $u ? $u->user_email : $r->user_id, (int) get_user_meta( $r->user_id, Spotlight::REACHED, true ), Spotlight::cap_for( $r->user_id ), wp_date( 'j M H:i', (int) $r->meta_value ) ) );
		}
	}
}
