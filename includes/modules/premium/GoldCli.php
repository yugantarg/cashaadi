<?php
/**
 * WP-CLI for the Gold launch. See GoldLaunch.
 */

namespace CAShaadi\Modules\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoldCli {

	/**
	 * Set the launch date, queue the notice email, show the banner.
	 *
	 * ## OPTIONS
	 *
	 * --launch=<date>
	 * : Launch date, Y-m-d (IST). At least 3 days away.
	 *
	 * [--dry-run]
	 * : Count who would be emailed; change nothing.
	 */
	public function notice( $args, $assoc ) {
		$d = (string) ( $assoc['launch'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			\WP_CLI::error( 'Give --launch=YYYY-MM-DD.' );
		}
		$days = (int) floor( ( strtotime( $d . ' 00:00:00' ) - strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00' ) ) / DAY_IN_SECONDS );
		if ( $days < 3 ) {
			\WP_CLI::error( 'The launch date must be at least 3 days away.' );
		}
		$who = GoldLaunch::audience();
		$cap = \CAShaadi\Modules\Emails\Queue::daily_cap();
		\WP_CLI::log( count( $who ) . " members will be told. At the current limit of {$cap} emails a day this takes about " . (int) ceil( count( $who ) / max( 1, $cap ) ) . ' days.' );
		if ( isset( $assoc['dry-run'] ) ) {
			\WP_CLI::success( 'Dry run: nothing changed.' );
			return;
		}
		update_option( GoldLaunch::DATE, $d );
		$n = 0;
		foreach ( $who as $uid ) {
			if ( \CAShaadi\Modules\Emails\Queue::notify( $uid, 'csm-phone-notice', 'Who can see your phone number on CA Shaadi', GoldLaunch::email_body( $uid ) ) ) {
				$n++;
			}
		}
		\WP_CLI::success( "Launch date {$d}. Banner on. {$n} notice emails queued." );
	}

	/** Switch on the pricing page, Gold sales and Gold phone access now. */
	public function launch() {
		GoldLaunch::launch();
		\WP_CLI::success( 'Gold is live: new pricing page, Gold on sale, phone access on.' );
	}

	/** Where things stand. */
	public function status() {
		\WP_CLI::log( 'Launch date:   ' . ( get_option( GoldLaunch::DATE ) ?: 'not set' ) );
		\WP_CLI::log( 'Pricing page:  ' . ( get_option( 'csm_pricing_v2' ) ? 'new' : 'old' ) );
		\WP_CLI::log( 'Gold on sale:  ' . ( get_option( 'csm_gold_on_sale' ) ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Phone access:  ' . ( get_option( 'csm_gold_phone_live' ) ? 'on' : 'off' ) );
		\WP_CLI::log( 'Gold members:  ' . Gold::count() . ' (cap ' . (int) get_option( 'csm_gold_cap', 25 ) . ')' );
		global $wpdb;
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}csm_email_queue WHERE email_type = 'csm-phone-notice' GROUP BY status" ) as $r ) {
			\WP_CLI::log( "Notice emails {$r->status}: {$r->n}" );
		}
	}
}
