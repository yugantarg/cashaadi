<?php
/**
 * WP-CLI for sign-ups.
 *
 *   wp csm signup recover --since="2026-10-08 00:00" --dry-run
 *   wp csm signup recover --since="2026-10-08 00:00"
 *
 * Sends a fresh activation code to everyone who signed up in the window
 * (IST) and has not activated. Each person is emailed once, however many
 * times they tried. Addresses that already have an account are skipped.
 */

namespace CAShaadi\Modules\Signup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SignupCli {

	/**
	 * Re-send activation codes to people stuck at the code step.
	 *
	 * ## OPTIONS
	 *
	 * --since=<datetime>
	 * : Start of the window, IST (e.g. "2026-10-08 00:00").
	 *
	 * [--until=<datetime>]
	 * : End of the window, IST. Default now.
	 *
	 * [--hours=<n>]
	 * : How long the new codes stay valid. Default 48.
	 *
	 * [--dry-run]
	 * : List who would be emailed; send nothing.
	 */
	public function recover( $args, $assoc ) {
		global $wpdb;
		$tz  = wp_timezone();
		$utc = new \DateTimeZone( 'UTC' );
		try {
			$a = ( new \DateTimeImmutable( $assoc['since'], $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			$b = ( new \DateTimeImmutable( isset( $assoc['until'] ) ? $assoc['until'] : 'now', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'Could not read --since / --until.' );
		}
		$ttl = max( 1, (int) ( isset( $assoc['hours'] ) ? $assoc['hours'] : 48 ) ) * HOUR_IN_SECONDS;
		$dry = isset( $assoc['dry-run'] );

		$s = $wpdb->base_prefix . 'signups';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.user_email email, COUNT(*) tries, MAX(s.registered) last
			 FROM {$s} s
			 WHERE s.registered BETWEEN %s AND %s AND s.active = 0
			   AND NOT EXISTS ( SELECT 1 FROM {$s} x WHERE x.user_email = s.user_email AND x.active = 1 )
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.user_email = s.user_email )
			 GROUP BY s.user_email ORDER BY last",
			$a,
			$b
		) );
		if ( ! $rows ) {
			\WP_CLI::success( 'Nobody is stuck in that window.' );
			return;
		}

		$sent = 0;
		$fail = 0;
		foreach ( $rows as $r ) {
			$line = sprintf( '%s  (tried %d times, last %s IST)', $r->email, (int) $r->tries, get_date_from_gmt( $r->last, 'j M H:i' ) );
			if ( $dry ) {
				\WP_CLI::log( 'would send: ' . $line );
				continue;
			}
			$res = ActivationCode::recover( $r->email, $ttl );
			\WP_CLI::log( $res . ': ' . $line );
			if ( 'sent' === $res ) {
				$sent++;
			} elseif ( 'failed' === $res ) {
				$fail++;
				if ( $fail >= 3 && ! $sent ) {
					\WP_CLI::error( 'The first 3 sends failed; stopping. Check the ZeptoMail credits.' );
				}
			}
			usleep( 300000 );
		}
		if ( $dry ) {
			\WP_CLI::success( count( $rows ) . ' people would be emailed. Run again without --dry-run to send.' );
		} else {
			\WP_CLI::success( sprintf( 'Sent %d, failed %d, of %d.', $sent, $fail, count( $rows ) ) );
		}
	}
}
