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

		// Profile Visitors (#11811): "who viewed me". Read-only over the
		// wp_csm_profile_views table (created/logged by #11807, which stays
		// active). Premium sees the full list; free sees a locked teaser + count.
		add_shortcode( 'csm_profile_visitors', array( __CLASS__, 'pv_shortcode' ) );
		add_action( 'bp_setup_nav', array( __CLASS__, 'pv_subnav' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'premium_assets' ) );
	}

	public static function premium_assets() {
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			Assets::style( 'premium', 'assets/css/premium.css' );
		}
	}

	/* ---- profile visitors (#11811) ------------------------------------- */

	private static function pv_view_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_profile_views';
	}

	/** Visitor rows for $uid (newest first), blocked users + admins excluded. */
	private static function pv_rows( $uid, $limit = 200 ) {
		global $wpdb;
		$t = self::pv_view_table();
		// The table is owned by #11807; guard in case it isn't present.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$t
		) );
		if ( ! $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT viewer_id, hits, last_at FROM {$t} WHERE viewed_id = %d ORDER BY last_at DESC LIMIT %d",
			(int) $uid,
			(int) $limit
		) );
		if ( empty( $rows ) ) {
			return array();
		}
		$hidden = function_exists( 'csm_bl_hidden_ids' ) ? array_flip( csm_bl_hidden_ids( $uid ) ) : array();
		$out    = array();
		foreach ( $rows as $r ) {
			$vid = (int) $r->viewer_id;
			if ( isset( $hidden[ $vid ] ) || user_can( $vid, 'manage_options' ) ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	public static function pv_list_html( $uid ) {
		$rows    = self::pv_rows( $uid );
		$premium = Membership::is_premium( $uid );

		$html = '<div class="csm-pv"><h2>Profile visitors</h2>';

		if ( ! $premium ) {
			return $html . self::pv_teaser_html( $rows ) . '</div>';
		}
		if ( empty( $rows ) ) {
			return $html . '<p class="csm-pv-empty">No one has viewed your profile yet. Complete your profile and start matching to get noticed.</p></div>';
		}

		$now = current_time( 'timestamp' );
		foreach ( $rows as $r ) {
			$vid   = (int) $r->viewer_id;
			$name  = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $vid ) : get_the_author_meta( 'display_name', $vid );
			$link  = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $vid ) : '';
			$av    = get_avatar( $vid, 56 );
			$ts    = strtotime( (string) $r->last_at );
			$ago   = $ts ? human_time_diff( $ts, $now ) . ' ago' : '';
			$exact = $ts ? esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : '';
			$hits  = (int) $r->hits;

			$html .= '<div class="csm-pv-row">';
			$html .= '<a href="' . esc_url( $link ) . '">' . $av . '</a>';
			$html .= '<div class="csm-pv-main">';
			$html .= '<a class="csm-pv-name" href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
			$html .= '<div class="csm-pv-when" title="' . $exact . '">Viewed you ' . esc_html( $ago ) . '</div>';
			if ( $hits > 1 ) {
				$html .= '<div class="csm-pv-count">Visited ' . (int) $hits . ' times</div>';
			}
			$html .= '</div></div>';
		}
		return $html . '</div>';
	}

	/** Blurred teaser for free members: real count + timing, identities masked. */
	private static function pv_teaser_html( $rows ) {
		$count = count( $rows );
		if ( 0 === $count ) {
			return '<p class="csm-pv-empty">No one has viewed your profile yet. Complete your profile and start matching to get noticed.</p>';
		}
		$upgrade = site_url( '/membership-pricing/' );
		$now     = current_time( 'timestamp' );
		$show    = array_slice( $rows, 0, 6 );

		$h = '<div class="csm-pv-lock"><div class="csm-pv-lock-blur">';
		foreach ( $show as $r ) {
			$vid  = (int) $r->viewer_id;
			$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $vid ) : get_the_author_meta( 'display_name', $vid );
			$init = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			if ( '' === $init ) {
				$init = 'C';
			}
			$ts  = strtotime( (string) $r->last_at );
			$ago = $ts ? human_time_diff( $ts, $now ) . ' ago' : '';
			$h  .= '<div class="csm-pv-row"><span class="csm-pv-ph" aria-hidden="true"></span><div class="csm-pv-main">'
				. '<div class="csm-pv-mask">' . esc_html( $init ) . '&bull;&bull;&bull;&bull;&bull;&bull;</div>'
				. '<div class="csm-pv-when">Viewed you ' . esc_html( $ago ) . '</div></div></div>';
		}
		$h .= '</div><div class="csm-pv-lock-cta"><div class="csm-pv-lock-icon">&#128064;</div>'
			. '<div class="big">' . (int) $count . '</div>'
			. '<h3>' . ( 1 === $count ? 'member viewed your profile' : 'members viewed your profile' ) . '</h3>'
			. '<p>Upgrade to Premium to see exactly who visited you, their photos, and when.</p>'
			. '<a class="csm-pv-upgrade" href="' . esc_url( $upgrade ) . '">Upgrade to Premium</a></div></div>';
		return $h;
	}

	public static function pv_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return self::pv_list_html( get_current_user_id() );
	}

	public static function pv_subnav() {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'friends' ) || ! is_user_logged_in() ) {
			return;
		}
		$slug       = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';
		$user_url   = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : '';
		$parent_url = $user_url ? trailingslashit( $user_url . $slug ) : '';
		bp_core_new_subnav_item( array(
			'name'            => 'Visitors',
			'slug'            => 'visitors',
			'parent_slug'     => $slug,
			'parent_url'      => $parent_url,
			'screen_function' => array( __CLASS__, 'pv_screen' ),
			'position'        => 40,
			'user_has_access' => bp_is_my_profile(),
		) );
	}

	public static function pv_screen() {
		add_action( 'bp_template_content', array( __CLASS__, 'pv_screen_content' ) );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	public static function pv_screen_content() {
		// Built from escaped values + trusted static markup above.
		echo self::pv_list_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
