<?php
/**
 * Customer list for Meta (lookalike seed), printed as CSV on stdout so it can
 * go straight to the owner's Mac without being stored on the server:
 *
 *   ssh cashaadi '... wp csm audience [--exclude=12,34]' > ~/Downloads/cashaadi_meta.csv
 *
 * Columns use Meta's customer-list names (email, phone, fn, ln, ct, country,
 * gen, doby); Meta hashes them on upload. Every registered member except
 * admins, closed (deleted) accounts and any --exclude IDs.
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AudienceCli {

	/**
	 * Print the Meta customer list.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude=<ids>]
	 * : Comma-separated user IDs to leave out (e.g. test accounts).
	 */
	public function __invoke( $args, $assoc ) {
		global $wpdb;
		$skip = array_filter( array_map( 'intval', explode( ',', (string) ( $assoc['exclude'] ?? '' ) ) ) );
		if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) {
			$skip = array_merge( $skip, (array) \CAShaadi\Modules\Settings\Closed::ids() );
		}
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->users} ORDER BY ID" ) );
		$ids = array_diff( $ids, array_map( 'intval', $skip ) );

		$xp = function ( $uid, $fid ) {
			if ( ! function_exists( 'xprofile_get_field_data' ) ) {
				return '';
			}
			$v = xprofile_get_field_data( $fid, $uid, 'comma' );
			return is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
		};

		$out = fopen( 'php://stdout', 'w' );
		fputcsv( $out, array( 'email', 'phone', 'fn', 'ln', 'ct', 'country', 'gen', 'doby' ) );
		$n = 0;
		foreach ( $ids as $uid ) {
			if ( user_can( $uid, 'manage_options' ) ) {
				continue;
			}
			$u = get_userdata( $uid );
			if ( ! $u || ! is_email( $u->user_email ) ) {
				continue;
			}
			$phone = preg_replace( '/\D/', '', $xp( $uid, Config::FIELD_PHONE ) );
			if ( 10 === strlen( $phone ) ) {
				$phone = '91' . $phone;
			} elseif ( 11 === strlen( $phone ) && '0' === $phone[0] ) {
				$phone = '91' . substr( $phone, 1 );
			}
			$fn = trim( (string) $u->first_name );
			$ln = trim( (string) $u->last_name );
			if ( '' === $fn ) {
				$parts = preg_split( '/\s+/', trim( (string) $u->display_name ) );
				$fn    = (string) array_shift( $parts );
				$ln    = $ln ? $ln : implode( ' ', $parts );
			}
			$g    = strtolower( substr( $xp( $uid, Config::FIELD_GENDER ), 0, 1 ) );
			$dob  = $xp( $uid, Config::FIELD_DOB );
			$year = preg_match( '/\b(19|20)\d{2}\b/', $dob, $m ) ? $m[0] : '';
			fputcsv( $out, array(
				strtolower( trim( $u->user_email ) ),
				$phone,
				strtolower( $fn ),
				strtolower( $ln ),
				strtolower( $xp( $uid, Config::FIELD_CITY ) ),
				'in',
				in_array( $g, array( 'm', 'f' ), true ) ? $g : '',
				$year,
			) );
			$n++;
		}
		fclose( $out );
		fwrite( STDERR, $n . " members written.\n" ); // stderr, so it stays out of the file
	}
}
