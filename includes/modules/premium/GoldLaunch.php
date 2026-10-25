<?php
/**
 * Gold launch (owner, 2026-10-24/25): members are told 3 days before Gold
 * members can see phone numbers, by email and by a banner in the app; on
 * launch day the pricing page, Gold sales and Gold phone access all switch on.
 *
 *   wp csm gold notice --launch=2026-11-01 [--dry-run]
 *       stores the launch date, queues the notice email to every member whose
 *       number is not "Only me", and shows the banner until launch.
 *   wp csm gold launch      switch everything on now (also happens by itself
 *                           on the launch date)
 *   wp csm gold status
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoldLaunch {

	const DATE = 'csm_gold_launch_date'; // Y-m-d, IST

	const INTERIM_DAYS = 7;
	const INTERIM_META = '_csm_interim_week'; // on the order, once granted

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_launch' ), 30 );
		/*
		 * Gold on sale before phone numbers open (owner, 2026-10-25: "launch
		 * everything about gold with the exception that phone numbers are
		 * visible from 28th Oct; give 1 week extra membership to those who
		 * register in the interim"). Late, after PMPro has set the level.
		 */
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'interim_week' ), 99, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'interim_week' ), 99, 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm gold', __NAMESPACE__ . '\\GoldCli' );
		}
	}

	public static function launched() {
		return (bool) get_option( 'csm_gold_on_sale' ) && (bool) get_option( 'csm_gold_phone_live' );
	}

	public static function launch() {
		update_option( 'csm_pricing_v2', 1 );
		update_option( 'csm_gold_on_sale', 1 );
		update_option( 'csm_gold_phone_live', 1 );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'gold_launched', 0, 0, array() );
		}
	}

	/** Sales are open now; only phone viewing waits for the launch date. */
	public static function open_sales() {
		update_option( 'csm_pricing_v2', 1 );
		update_option( 'csm_gold_on_sale', 1 );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'gold_sales_opened', 0, 0, array() );
		}
	}

	/**
	 * A plan with phone numbers (Gold; Premium for a woman) bought before
	 * phone viewing opens gets INTERIM_DAYS more, once per order.
	 */
	public static function interim_week( $order_id ) {
		if ( get_option( 'csm_gold_phone_live' ) ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || $order->get_meta( self::INTERIM_META ) ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		if ( ! $uid ) {
			return;
		}
		$level = 0;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( (int) Config::WC_GOLD_PRODUCT === $pid ) {
				$level = (int) Config::PMPRO_GOLD_LEVEL;
			} elseif ( (int) Config::WC_PREMIUM_PRODUCT === $pid && ! $level && Gold::is_female( $uid ) ) {
				$level = (int) Config::PMPRO_PREMIUM_LEVEL;
			}
		}
		if ( ! $level || ! function_exists( 'pmpro_hasMembershipLevel' ) || ! pmpro_hasMembershipLevel( $level, $uid ) ) {
			return; // not a phone plan, or the level is not set yet (the other status hook retries)
		}
		global $wpdb;
		$t = $wpdb->pmpro_memberships_users;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET enddate = DATE_ADD( enddate, INTERVAL %d DAY )
			 WHERE user_id = %d AND membership_id = %d AND status = 'active' AND enddate IS NOT NULL AND enddate <> '0000-00-00 00:00:00'",
			self::INTERIM_DAYS, $uid, $level
		) );
		if ( ! $n ) {
			return;
		}
		$order->update_meta_data( self::INTERIM_META, current_time( 'mysql' ) );
		$order->save();
		$order->add_order_note( 'CA Shaadi: ' . self::INTERIM_DAYS . ' extra days added (bought before phone numbers opened).' );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'interim_week', 0, $uid, array( 'order' => (int) $order_id, 'level' => $level ) );
		}
	}

	/** On the launch date, switch on by itself. */
	public static function maybe_launch() {
		$d = (string) get_option( self::DATE, '' );
		if ( '' !== $d && ! self::launched() && wp_date( 'Y-m-d' ) >= $d ) {
			self::launch();
		}
	}

	/** Members told: a phone number, not "Only me", account open. */
	public static function audience() {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> '' AND value <> '-'",
			Config::FIELD_PHONE
		) ) );
		$closed = class_exists( '\CAShaadi\Modules\Settings\Closed' ) ? array_flip( \CAShaadi\Modules\Settings\Closed::ids() ) : array();
		$out    = array();
		foreach ( $ids as $uid ) {
			if ( isset( $closed[ $uid ] ) || user_can( $uid, 'manage_options' ) ) {
				continue;
			}
			$level = class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' )
				? \CAShaadi\Modules\Settings\SettingsScreen::phone_audience( $uid )
				: 'friends';
			if ( 'adminsonly' !== $level ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	private static function nice_date() {
		$d = (string) get_option( self::DATE, '' );
		return '' === $d ? 'soon' : wp_date( 'j F', strtotime( $d . ' 12:00:00' ) );
	}

	/*
	 * A man's number is opened by Premium and Gold women; a woman's by Gold
	 * men, or Premium too if she allows it (Gold::can_reveal).
	 */
	private static function who_sees( $uid ) {
		$box = 'with Call and WhatsApp buttons. When someone views it, you receive a match request from them, so you always know who has it.';
		if ( \CAShaadi\Modules\Premium\Gold::is_female( $uid ) ) {
			return '<ul><li><strong>Gold members</strong> will be able to see your number, ' . $box . '</li>'
				. '<li>You can also let <strong>Premium members</strong> see it, or hide it from everyone, in Privacy settings.</li></ul>';
		}
		return '<ul><li><strong>Premium and Gold members</strong> will be able to see your number, ' . $box . '</li>'
			. '<li>You can hide it from everyone in Privacy settings.</li></ul>';
	}

	public static function email_body( $uid ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return \CAShaadi\Modules\Emails\Engagement::wrap(
			\CAShaadi\Modules\Emails\Engagement::greeting( $uid ),
			'<p>A change to who can see your phone number on ' . esc_html( $site ) . ', from <strong>' . esc_html( self::nice_date() ) . '</strong>:</p>'
			. self::who_sees( $uid )
			. '<p>Members who have already chosen "Only me" do not need to do anything.</p>',
			\CAShaadi\Modules\Settings\SettingsScreen::url(),
			'Open Privacy settings'
		);
	}

	/** Banner until launch, for members in the audience. */
	public static function banner( $uid ) {
		$d = (string) get_option( self::DATE, '' );
		if ( '' === $d || self::launched() || ! $uid ) {
			return '';
		}
		$level = class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' )
			? \CAShaadi\Modules\Settings\SettingsScreen::phone_audience( $uid )
			: 'friends';
		if ( 'adminsonly' === $level ) {
			return '';
		}
		return '<div class="csm-app-paused"><span>' . esc_html( 'From ' . self::nice_date() . ', ' . ( \CAShaadi\Modules\Premium\Gold::is_female( $uid ) ? 'Gold members' : 'Premium and Gold members' ) . ' can see your phone number.' ) . '</span>'
			. '<a href="' . esc_url( \CAShaadi\Modules\Settings\SettingsScreen::url() ) . '">Privacy settings</a></div>';
	}
}
