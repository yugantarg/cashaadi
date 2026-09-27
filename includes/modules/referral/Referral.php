<?php
/**
 * Referrals and CA Shaadi cash (owner, 2026-09-27).
 *
 * Rules, as decided by the owner:
 *   - Every member has a link; whoever registers through it is tied to them.
 *   - When that new member FINISHES their profile (the /welcome/ wizard
 *     completes), both are credited: 500 each if the new profile is a woman's,
 *     200 each if a man's. Gender is the PROFILE's (field 299), which is
 *     locked after signup, so it cannot be switched to claim more.
 *   - 1 cash = Rs 1. It pays for up to 100% of any purchase and never expires.
 *   - No emails.
 *
 * The owner accepted that crediting on profile completion (rather than on CA
 * verification) is easier to game with fake accounts. The mitigations here do
 * not change the rules: no self-referral, one credit per pair (a UNIQUE key,
 * not a check-then-write), the new member's signup IP is kept for the admin
 * page, and every credit can be reversed there.
 *
 * Money is a ledger, not a balance column: every credit and spend is a row, the
 * balance is their sum, and nothing is ever edited — a mistake is corrected
 * with a reversing row. That keeps the history honest and makes double-credits
 * impossible at the database level.
 */

namespace CAShaadi\Modules\Referral;

use CAShaadi\Core\Config;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Referral {

	const COOKIE       = 'csm_ref';
	const CODE_META    = 'csm_ref_code';
	const BY_META      = 'csm_referred_by';
	const IP_META      = 'csm_signup_ip';
	const USE_META     = 'csm_cash_use';     // 'no' = keep my cash at checkout
	const FEE_NAME     = 'CA Shaadi cash';
	const ORDER_META   = '_csm_cash_used';
	const CLEANUP_HOOK = 'csm_cash_cleanup';

	/** Credit per referral, by the NEW profile's gender. Same for both sides. */
	public static function amount_for_gender( $gender ) {
		$amounts = (array) apply_filters( 'csm_referral_amounts', array( 'female' => 500, 'male' => 200 ) );
		$g       = strtolower( trim( (string) $gender ) );
		return isset( $amounts[ $g ] ) ? (int) $amounts[ $g ] : 0;
	}

	public static function register() {
		Migrator::register( 'cash_ledger', array( __CLASS__, 'schema' ) );

		// Capture ?ref= in the browser: most pages are served from LiteSpeed's
		// cache, where PHP never runs, so a server-set cookie would be lost.
		add_action( 'wp_head', array( __CLASS__, 'capture_script' ), 2 );

		// Tie the new account to its referrer the moment it is created.
		add_action( 'user_register', array( __CLASS__, 'attach' ), 20 );
		add_action( 'bp_core_signup_user', array( __CLASS__, 'attach' ), 20 );

		// Credit both sides when the new member finishes their profile.
		add_action( 'csm_onboarding_completed', array( __CLASS__, 'award' ) );

		// Spend at checkout.
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fee' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'record_spend' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'record_spend_classic' ), 20, 3 );
		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $s ) {
			add_action( 'woocommerce_order_status_' . $s, array( __CLASS__, 'restore_spend' ) );
		}
		add_filter( 'the_content', array( __CLASS__, 'pricing_note' ), 25 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_unpaid' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/* ------------------------------------------------------------ ledger */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_cash_ledger';
	}

	public static function schema( $wpdb ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL,
 amount INT NOT NULL,
 kind VARCHAR(20) NOT NULL,
 ref_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 note VARCHAR(191) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY once (user_id, kind, ref_user_id, order_id),
 KEY user_id (user_id),
 KEY ref_user_id (ref_user_id)
) " . $wpdb->get_charset_collate() . ';';
	}

	/**
	 * Add one ledger row. INSERT IGNORE against the UNIQUE key makes every
	 * credit idempotent: a retried request or a double-fired hook cannot pay
	 * twice. Returns true only if a row was actually written.
	 */
	public static function post( $uid, $amount, $kind, $ref_user = 0, $order = 0, $note = '' ) {
		global $wpdb;
		$uid    = (int) $uid;
		$amount = (int) $amount;
		if ( ! $uid || 0 === $amount ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (user_id, amount, kind, ref_user_id, order_id, note, created_at) VALUES (%d, %d, %s, %d, %d, %s, %s)',
			$uid, $amount, substr( (string) $kind, 0, 20 ), (int) $ref_user, (int) $order, substr( (string) $note, 0, 191 ), current_time( 'mysql', true )
		) );
		return 1 === (int) $n;
	}

	public static function balance( $uid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . ' WHERE user_id = %d', (int) $uid ) ) );
	}

	/** @return array<int,object> newest first */
	public static function history( $uid, $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', (int) $uid, (int) $limit ) );
	}

	/* ------------------------------------------------------------- codes */

	/** This member's referral code, created on first use. */
	public static function code_for( $uid ) {
		$uid  = (int) $uid;
		$code = (string) get_user_meta( $uid, self::CODE_META, true );
		if ( '' !== $code ) {
			return $code;
		}
		// No 0/O, 1/I/L: codes are read aloud and typed from WhatsApp.
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		for ( $try = 0; $try < 20; $try++ ) {
			$code = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			if ( ! self::user_by_code( $code ) ) {
				update_user_meta( $uid, self::CODE_META, $code );
				return $code;
			}
		}
		return '';
	}

	public static function user_by_code( $code ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( '' === $code ) {
			return 0;
		}
		$ids = get_users( array( 'meta_key' => self::CODE_META, 'meta_value' => $code, 'fields' => 'ID', 'number' => 1 ) );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function link_for( $uid ) {
		$code = self::code_for( $uid );
		return $code ? add_query_arg( 'ref', $code, home_url( '/register/' ) ) : home_url( '/register/' );
	}

	/* ----------------------------------------------------------- capture */

	public static function capture_script() {
		if ( is_user_logged_in() ) {
			return; // members are not being referred
		}
		?>
<script>(function(){try{var m=/[?&]ref=([A-Za-z0-9]{4,12})(?:&|$)/.exec(location.search);if(m){document.cookie='<?php echo esc_js( self::COOKIE ); ?>='+m[1].toUpperCase()+';path=/;max-age=2592000;samesite=lax;secure';}}catch(e){}})();</script>
		<?php
	}

	/** Record who referred a new account. Runs once; never overwrites. */
	public static function attach( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || get_user_meta( $uid, self::BY_META, true ) ) {
			return;
		}
		$code = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$ref = self::user_by_code( $code );
		if ( ! $ref || $ref === $uid ) {
			return; // unknown code, or referring yourself
		}
		update_user_meta( $uid, self::BY_META, $ref );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $ip ) {
			update_user_meta( $uid, self::IP_META, $ip );
		}
	}

	/* ------------------------------------------------------------ credit */

	public static function award( $uid ) {
		$uid = (int) $uid;
		$ref = (int) get_user_meta( $uid, self::BY_META, true );
		if ( ! $uid || ! $ref || $ref === $uid || ! get_userdata( $ref ) ) {
			return;
		}
		$gender = class_exists( 'BP_XProfile_ProfileData' )
			? (string) \BP_XProfile_ProfileData::get_value_byid( Config::FIELD_GENDER, $uid )
			: '';
		$amt = self::amount_for_gender( $gender );
		if ( ! $amt ) {
			return;
		}
		self::post( $ref, $amt, 'referral', $uid, 0, 'Referral: member #' . $uid . ' completed their profile' );
		self::post( $uid, $amt, 'welcome', $ref, 0, 'Welcome credit: joined through a referral' );
	}

	/** On the pricing page, tell a member with cash that it will be used. */
	public static function pricing_note( $html ) {
		if ( is_admin() || ! is_user_logged_in() || ! in_the_loop() || ! is_main_query() || ! is_page( 'membership-pricing' ) ) {
			return $html;
		}
		$uid = get_current_user_id();
		$bal = self::balance( $uid );
		if ( $bal <= 0 ) {
			return $html;
		}
		$msg = self::wants_to_use( $uid )
			? sprintf( 'You have <strong>₹%s CA Shaadi cash</strong>. It comes off automatically at checkout.', esc_html( number_format_i18n( $bal ) ) )
			: sprintf( 'You have <strong>₹%s CA Shaadi cash</strong>. It is switched off for checkout — <a href="%s">turn it on</a>.', esc_html( number_format_i18n( $bal ) ), esc_url( home_url( '/refer/' ) ) );
		return '<p style="background:#fbf3ee;border:1px solid #efd9cc;border-radius:10px;padding:12px 14px;text-align:center">' . $msg . '</p>' . $html;
	}

	/* ------------------------------------------------------------- spend */

	public static function wants_to_use( $uid ) {
		return 'no' !== get_user_meta( (int) $uid, self::USE_META, true );
	}

	/** Take the balance off the cart, up to the whole amount due. */
	public static function apply_fee( $cart ) {
		if ( ! is_object( $cart ) || ! is_user_logged_in() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! self::wants_to_use( $uid ) ) {
			return;
		}
		$bal = self::balance( $uid );
		if ( $bal <= 0 ) {
			return;
		}
		$due = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		$use = (int) min( $bal, floor( $due ) );
		if ( $use > 0 ) {
			$cart->add_fee( self::FEE_NAME, -$use, false );
		}
	}

	public static function record_spend_classic( $order_id, $posted = null, $order = null ) {
		unset( $posted );
		self::record_spend( $order ? $order : wc_get_order( $order_id ) );
	}

	/** Deduct at order creation, so the same cash cannot be spent on two orders. */
	public static function record_spend( $order ) {
		if ( ! is_object( $order ) || $order->get_meta( self::ORDER_META ) ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		if ( ! $uid ) {
			return;
		}
		$used = 0;
		foreach ( $order->get_fees() as $fee ) {
			if ( self::FEE_NAME === $fee->get_name() ) {
				$used += (int) round( abs( (float) $fee->get_total() ) );
			}
		}
		if ( $used <= 0 ) {
			return;
		}
		$take = min( $used, self::balance( $uid ) );
		if ( $take > 0 && self::post( $uid, -$take, 'redeem', 0, $order->get_id(), 'Used on order #' . $order->get_id() ) ) {
			$order->update_meta_data( self::ORDER_META, $take );
			$order->add_order_note( sprintf( 'CA Shaadi cash used: Rs %d.', $take ) );
			$order->save();
		}
	}

	/** Give it back if the order does not go through. Once per order. */
	public static function restore_spend( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$take = (int) $order->get_meta( self::ORDER_META );
		$uid  = (int) $order->get_customer_id();
		if ( $take > 0 && $uid && self::post( $uid, $take, 'restore', 0, $order->get_id(), 'Returned from order #' . $order->get_id() ) ) {
			$order->add_order_note( sprintf( 'CA Shaadi cash returned: Rs %d.', $take ) );
			$order->save();
		}
	}

	/** Orders that used cash but were never paid are cancelled after 2 hours, which returns the cash. */
	public static function cleanup_unpaid() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$orders = wc_get_orders( array(
			'status'       => array( 'pending' ),
			'date_created' => '<' . ( time() - 2 * HOUR_IN_SECONDS ),
			'meta_key'     => self::ORDER_META,
			'meta_compare' => 'EXISTS',
			'limit'        => 50,
		) );
		foreach ( $orders as $o ) {
			$o->update_status( 'cancelled', 'Unpaid for 2 hours; CA Shaadi cash returned.' );
		}
	}
}
