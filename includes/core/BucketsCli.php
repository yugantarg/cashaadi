<?php
/**
 * WP-CLI for community / mother-tongue groups (Core\Buckets).
 *
 *   wp csm buckets rebuild     recompute every member's groups
 *   wp csm buckets report      members per group, and answers that fit none
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BucketsCli {

	/** Recompute every member's groups from their current answers. */
	public function rebuild() {
		global $wpdb;
		$n = 0;
		foreach ( array_keys( Buckets::FIELDS ) as $kind ) {
			$fid = Buckets::field_id( $kind );
			if ( ! $fid ) {
				\WP_CLI::warning( "No field for {$kind}." );
				continue;
			}
			$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => Buckets::meta_key( $kind ) ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> ''", $fid ) );
			foreach ( $rows as $r ) {
				foreach ( Buckets::of( $kind, maybe_unserialize( $r->value ) ) as $g ) {
					$wpdb->insert( $wpdb->usermeta, array( 'user_id' => (int) $r->user_id, 'meta_key' => Buckets::meta_key( $kind ), 'meta_value' => $g ) );
				}
				$n++;
			}
		}
		wp_cache_flush();
		\WP_CLI::success( "Grouped {$n} answers." );
	}

	/** Members per group, and the answers that fit no group. */
	public function report() {
		global $wpdb;
		foreach ( array_keys( Buckets::FIELDS ) as $kind ) {
			\WP_CLI::log( "== {$kind}" );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_value g, COUNT(*) n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY g ORDER BY n DESC", Buckets::meta_key( $kind ) ) ) as $r ) {
				\WP_CLI::log( sprintf( '%5d  %s', $r->n, $r->g ) );
			}
			$fid  = Buckets::field_id( $kind );
			$left = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(TRIM(value)) v, COUNT(*) n FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> '' GROUP BY v", $fid ) ) as $r ) {
				if ( ! Buckets::of( $kind, $r->v ) ) {
					$left[ $r->v ] = (int) $r->n;
				}
			}
			arsort( $left );
			\WP_CLI::log( '-- no group: ' . array_sum( $left ) . ' members' );
			foreach ( array_slice( $left, 0, 80, true ) as $v => $n ) {
				\WP_CLI::log( sprintf( '%5d  %s', $n, $v ) );
			}
		}
	}
}
