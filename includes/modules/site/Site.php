<?php
/**
 * Site-wide tweaks module.
 *
 * Consolidates small, low-risk site snippets. All hooks here are idempotent
 * (they return the same result if the original snippet is still active), so this
 * runs safely alongside them until they are disabled in WPCode:
 *   #11696 Noindex PMPro member pages (Yoast)
 *   #11626 Redirect /pricing/ -> /membership-pricing/
 *   #11691 Support-email footer
 * (#11242 disable-comments and #11638 WC-greeting are NOT migrated — both were
 *  already disabled on the live site.)
 *
 * The CSS-only site snippets (#11612 hide sidebar, #11582 caps-lock warning) are
 * migrated into assets/css/site.css instead.
 */

namespace CAShaadi\Modules\Site;

use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site {

	/** PMPro member page IDs to noindex (#11696). */
	const NOINDEX_PAGE_IDS = array( 11574, 11567, 11568, 11569, 11572, 11575, 11570, 11571, 11573 );

	public static function register() {
		// --- Noindex PMPro member pages (#11696) ---
		add_filter( 'wpseo_robots', array( __CLASS__, 'noindex_robots' ) );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'noindex_robots_array' ) );

		// --- /pricing/ -> /membership-pricing/ (#11626) ---
		add_action( 'template_redirect', array( __CLASS__, 'pricing_redirect' ), 1 );

		// --- gendered rectangular placeholder when a member has no photo ---
		// Priority 30: AFTER Privacy's blur (20) and NSFW's mask (21), so a member
		// who HAS a photo but has it withheld still gets the blur/mask, and only a
		// genuinely photo-less member gets the silhouette.
		add_filter( 'bp_core_fetch_avatar_url', array( __CLASS__, 'gendered_placeholder_url' ), 30, 2 );
		add_filter( 'bp_core_fetch_avatar', array( __CLASS__, 'gendered_placeholder_html' ), 30, 2 );

		/*
		 * Make the theme's logo work everywhere.
		 *
		 * The site has a logo in the media library but `custom_logo` was never set,
		 * so BuddyX fell back to the text wordmark in every header. AppPage worked
		 * around that for the app screens, which left BuddyPress screens still
		 * showing words — the same inconsistency, one layer down.
		 *
		 * Filtering theme_mod_custom_logo makes WordPress behave as though the logo
		 * were configured, without writing the option: the theme, the app and the
		 * marketing pages all pick it up, and removing this line reverts everything.
		 * Setting it properly in the Customiser makes this filter redundant.
		 */
		add_filter( 'theme_mod_custom_logo', array( __CLASS__, 'custom_logo' ) );

		// --- Checkout styling (#11581) + BuddyX menu-toggle fix (#11674) ---
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 25 );

		// --- Support-email footer (#11691) ---
		// Priority 98 (before the snippet's 99) so that while both are active the
		// plugin's footer renders FIRST and the snippet's duplicate is hidden by
		// the `.csm-support-footer ~ .csm-support-footer` rule in site.css.
		add_action( 'wp_footer', array( __CLASS__, 'support_footer' ), 98 );
	}

	/* ---- noindex member pages (#11696) --------------------------------- */

	private static function is_noindex_page() {
		return is_singular() && in_array( (int) get_queried_object_id(), self::NOINDEX_PAGE_IDS, true );
	}

	public static function noindex_robots( $robots ) {
		return self::is_noindex_page() ? 'noindex, follow' : $robots;
	}

	public static function noindex_robots_array( $robots ) {
		if ( self::is_noindex_page() ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}

	/* ---- pricing redirect (#11626) ------------------------------------- */

	public static function pricing_redirect() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = trim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( 'pricing' === strtolower( $path ) ) {
			wp_safe_redirect( home_url( '/membership-pricing/' ), 301 );
			exit;
		}
	}

	/* ---- support footer (#11691) --------------------------------------- */

	public static function support_footer() {
		// Home / Discover / member profile pages only (same scope as #11691).
		$show = is_front_page() || is_home()
			|| ( function_exists( 'is_page' ) && is_page( 'discover' ) )
			|| ( function_exists( 'bp_is_user' ) && bp_is_user() );
		if ( ! $show ) {
			return;
		}
		$email = Config::SUPPORT_EMAIL;
		printf(
			'<div class="csm-support-footer">Need help? Email us at <a class="csm-support-email" href="mailto:%s">%s</a></div>',
			esc_attr( $email ),
			esc_html( $email )
		);
	}

	/**
	 * The logo attachment, found by SLUG.
	 *
	 * Not by id: attachment ids differ between staging and production, so a
	 * hardcoded one would 404 on the other environment. Cached for a day because
	 * this runs on every header render.
	 */
	public static function custom_logo( $id ) {
		if ( $id ) {
			return $id; // a real Customiser setting always wins
		}
		$cached = get_transient( 'csm_logo_id' );
		if ( false === $cached ) {
			$att    = get_page_by_path( 'cashaadi-logo', OBJECT, 'attachment' );
			$cached = $att ? (int) $att->ID : 0;
			set_transient( 'csm_logo_id', $cached, DAY_IN_SECONDS );
		}
		return $cached ? (int) $cached : $id;
	}

	/* ---- checkout styling (#11581) + menu-toggle fix (#11674) ------------ */

	/**
	 * Two migrated assets, each scoped to where it is actually needed.
	 *
	 * The checkout CSS only loads on WooCommerce cart/checkout, so it costs
	 * nothing on the 99% of pages that are not commerce. The menu-toggle fix
	 * only loads where BuddyX renders its header — the app screens own their own
	 * menu and must not be touched by it.
	 */
	public static function assets() {
		if ( function_exists( 'is_checkout' ) && function_exists( 'is_cart' ) && ( is_checkout() || is_cart() ) ) {
			Assets::style( 'checkout', 'assets/css/checkout.css' );
		}

		// jQuery is the whole mechanism here; without it the script no-ops anyway.
		if ( wp_script_is( 'jquery', 'registered' ) || wp_script_is( 'jquery', 'enqueued' ) ) {
			Assets::script( 'menu-toggle-fix', 'assets/js/menu-toggle-fix.js', array( 'jquery' ) );
		}
	}

	/* ---- gendered no-photo placeholder ---------------------------------- */

	/**
	 * A member's photo display area is a portrait rectangle, so an empty one
	 * should show a rectangular gendered silhouette, not a circular mystery-man
	 * (owner: "the image shown in case of no image shouldnt be circle. It should
	 * be a male/female avatar").
	 *
	 * @param int|string $item_id User id from avatar params.
	 * @return string  Placeholder URL, or '' when we should not swap.
	 */
	private static function placeholder_for( $item_id ) {
		$uid = (int) $item_id;
		if ( ! $uid ) {
			return '';
		}
		// The caller only reaches here when the current avatar URL is already a
		// placeholder (is_default_avatar), so there is no real photo to protect —
		// resolve the member's gender and hand back the matching silhouette.
		$raw = '';
		if ( class_exists( '\BP_XProfile_ProfileData' ) ) {
			$raw = (string) \BP_XProfile_ProfileData::get_value_byid( \CAShaadi\Core\Config::FIELD_GENDER, $uid );
		}
		$raw = strtolower( $raw );
		if ( false !== strpos( $raw, 'female' ) ) {
			$slug = 'female';
		} elseif ( false !== strpos( $raw, 'male' ) ) {
			$slug = 'male';
		} else {
			return ''; // gender unknown — leave BuddyPress's own default
		}
		return CASHAADI_UI_URL . 'assets/img/avatar-' . $slug . '.svg';
	}

	/** Only swap when the current URL is a default/placeholder, not a real photo. */
	private static function is_default_avatar( $url ) {
		// mystery-man / gravatar default, AND this site's shared stand-in image
		// (abstract-user-flat-*.png) that the member import set on everyone who
		// arrived without a photo — the actual "no photo" case here.
		return ( '' === $url )
			|| (bool) preg_match( '#(gravatar\.com|mystery-?man|/buddypress/images/|/bp-core/images/|/mystery|abstract-user-flat)#i', (string) $url );
	}

	public static function gendered_placeholder_url( $url, $params = array() ) {
		if ( ! is_array( $params ) || ( ! empty( $params['object'] ) && 'user' !== $params['object'] ) ) {
			return $url;
		}
		if ( ! self::is_default_avatar( $url ) ) {
			return $url;
		}
		$ph = self::placeholder_for( isset( $params['item_id'] ) ? $params['item_id'] : 0 );
		return $ph ? $ph : $url;
	}

	public static function gendered_placeholder_html( $html, $params = array() ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( ! is_array( $params ) || ( ! empty( $params['object'] ) && 'user' !== $params['object'] ) ) {
			return $html;
		}
		// Only when the current src is a default; pull the src out to check.
		if ( preg_match( '/ src="([^"]*)"/', $html, $m ) && ! self::is_default_avatar( $m[1] ) ) {
			return $html;
		}
		$ph = self::placeholder_for( isset( $params['item_id'] ) ? $params['item_id'] : 0 );
		if ( ! $ph ) {
			return $html;
		}
		$html = preg_replace( '/ src="[^"]*"/', ' src="' . esc_url( $ph ) . '"', $html, 1 );
		$html = preg_replace( '/ srcset="[^"]*"/', '', $html, 1 );
		return $html;
	}
}
