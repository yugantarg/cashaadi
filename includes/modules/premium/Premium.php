<?php
/**
 * Premium module.
 *
 * The premium snippets inject buttons/gates, so running them alongside the
 * active WPCode versions would double them. The whole module is therefore gated
 * behind Config::premium_enabled() (off unless wp-config sets
 * CASHAADI_PREMIUM_ENABLED = true) — deploying it changes nothing until a
 * coordinated cutover (flip the flag + disable the migrated premium snippets).
 *
 * This first increment migrates:
 *   #11579 Upgrade to Premium button (own profile + members directory)
 *
 * Still to migrate here (later, still behind this flag): #11795 checkout
 * hygiene, #11620 profile gate, #11614 contact gate, #11807/#11811/#11821, etc.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premium {

	public static function register() {
		if ( ! Config::premium_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Upgrade button (#11579): on the member's own profile header and the
		// members directory.
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'upgrade_on_profile' ) );
		add_action( 'bp_before_directory_members_content', array( __CLASS__, 'upgrade_on_directory' ) );

		// Checkout hygiene (#11795): stop existing premium members re-buying, and
		// keep the cart to just the premium product for everyone else.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_add_to_cart' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'bounce_premium' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_assets' ) );

		// Contact gate (#11614): phone + email visible only to owner / admin /
		// premium; everyone else sees an upgrade nudge.
		add_filter( 'bp_get_the_profile_field_value', array( __CLASS__, 'gate_phone_field' ), 20, 3 );
		add_action( 'bp_after_member_header', array( __CLASS__, 'contact_email_row' ) );
	}

	/* ---- contact gate (#11614) ----------------------------------------- */

	/** Owner, admin, or premium member may see another member's contact info. */
	private static function can_see_contact( $displayed_user_id ) {
		$viewer = get_current_user_id();
		if ( ! $viewer ) {
			return false;
		}
		if ( (int) $viewer === (int) $displayed_user_id ) {
			return true; // owner
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true; // admin
		}
		return Membership::is_premium( $viewer ); // premium
	}

	private static function contact_nudge() {
		return '<span class="csm-contact-locked">🔒 <a href="'
			. esc_url( home_url( '/membership-pricing/' ) )
			. '">Upgrade to Premium to view contact details</a></span>';
	}

	/** Gate the phone xProfile field (277) value in place. */
	public static function gate_phone_field( $value, $type, $field_id ) {
		if ( (int) $field_id !== Config::FIELD_PHONE ) {
			return $value;
		}
		$displayed = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : 0;
		return self::can_see_contact( $displayed ) ? $value : self::contact_nudge();
	}

	/** Inject a gated Email row into the member header. */
	public static function contact_email_row() {
		if ( ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		$displayed = bp_displayed_user_id();
		if ( ! $displayed ) {
			return;
		}
		if ( self::can_see_contact( $displayed ) ) {
			$user = get_userdata( $displayed );
			if ( ! $user || empty( $user->user_email ) ) {
				return;
			}
			$email_html = '<a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>';
		} else {
			$email_html = self::contact_nudge();
		}
		// $email_html is built from esc_attr/esc_html + a trusted static nudge.
		echo '<div class="csm-contact-email-row"><span class="csm-contact-label">Email</span><span class="csm-contact-value">' . $email_html . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ---- checkout hygiene (#11795) ------------------------------------- */

	/**
	 * Gate the add-to-cart for the premium product: block a duplicate purchase by
	 * an existing premium member; otherwise keep the cart to just this one item.
	 */
	public static function guard_add_to_cart( $passed, $product_id ) {
		if ( (int) $product_id !== (int) Config::WC_PREMIUM_PRODUCT ) {
			return $passed;
		}
		if ( ! function_exists( 'WC' ) || ! ( WC()->cart instanceof \WC_Cart ) ) {
			return $passed;
		}
		if ( Membership::is_premium( get_current_user_id() ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'You are already a Premium member — no need to buy again.', 'notice' );
			}
			return false; // block the duplicate purchase
		}
		WC()->cart->empty_cart();
		return $passed;
	}

	/** Belt-and-braces: bounce a premium member off the premium add-to-cart URL. */
	public static function bounce_premium() {
		if ( is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['add-to-cart'] ) || (int) $_GET['add-to-cart'] !== (int) Config::WC_PREMIUM_PRODUCT ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! Membership::is_premium( get_current_user_id() ) ) {
			return;
		}
		$dest = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		wp_safe_redirect( $dest );
		exit;
	}

	/**
	 * On the pricing page, replace the premium add-to-cart link with a label for
	 * members who are already premium (JS reads window.CASHAADI_PREMIUM).
	 */
	public static function checkout_assets() {
		if ( is_admin() || ! is_user_logged_in() || ! Membership::is_premium() ) {
			return;
		}
		Assets::script( 'premium', 'assets/js/premium.js' );
		wp_add_inline_script(
			'cashaadi-premium',
			'window.CASHAADI_PREMIUM=' . wp_json_encode( array(
				'isPremium' => true,
				'productId' => (int) Config::WC_PREMIUM_PRODUCT,
			) ) . ';',
			'before'
		);
	}

	public static function upgrade_on_profile() {
		if ( function_exists( 'bp_displayed_user_id' ) && function_exists( 'bp_loggedin_user_id' )
			&& bp_displayed_user_id() === bp_loggedin_user_id() ) {
			self::upgrade_button( 'profile' );
		}
	}

	public static function upgrade_on_directory() {
		self::upgrade_button( 'directory' );
	}

	/**
	 * Render an Upgrade-to-Premium button for non-premium users. On the member's
	 * own profile, if the profile is still incomplete, show a single
	 * "complete your profile" CTA instead (mirrors #11579). Styles live in
	 * assets/css/site.css.
	 */
	private static function upgrade_button( $context ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Incomplete profile → single "complete profile" CTA (own profile only).
		if ( 'profile' === $context
			&& function_exists( 'cashaadi_has_missing_required_fields' )
			&& cashaadi_has_missing_required_fields( get_current_user_id() ) ) {
			$edit = function_exists( 'bp_loggedin_user_url' )
				? trailingslashit( bp_loggedin_user_url() . 'profile/edit' )
				: home_url( '/' );
			printf(
				'<div class="cashaadi-complete-profile-wrap"><a class="cashaadi-complete-profile-btn" href="%s">Complete your profile to browse other profiles &rarr;</a></div>',
				esc_url( $edit )
			);
			return;
		}

		if ( Membership::is_premium() ) {
			return; // already premium — no upsell
		}

		printf(
			'<div class="cashaadi-upgrade-wrap"><a class="cashaadi-upgrade-btn" href="%s">Upgrade to Premium</a></div>',
			esc_url( home_url( '/membership-pricing/' ) )
		);
	}
}
