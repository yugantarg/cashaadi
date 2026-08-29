<?php
/**
 * Site-wide tweaks module.
 *
 * Consolidates small, low-risk site snippets. All hooks here are idempotent
 * (they return the same result if the original snippet is still active), so this
 * runs safely alongside them until they are disabled in WPCode:
 *   #11242 Completely Disable Comments
 *   #11638 WC My-Account greeting reword
 *   #11696 Noindex PMPro member pages (Yoast)
 *   #11626 Redirect /pricing/ -> /membership-pricing/
 *
 * The CSS-only site snippets (#11612 hide sidebar, #11582 caps-lock warning) are
 * migrated into assets/css/site.css instead.
 */

namespace CAShaadi\Modules\Site;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site {

	/** PMPro member page IDs to noindex (#11696). */
	const NOINDEX_PAGE_IDS = array( 11574, 11567, 11568, 11569, 11572, 11575, 11570, 11571, 11573 );

	public static function register() {
		// --- Comments off (#11242) ---
		add_action( 'admin_init', array( __CLASS__, 'comments_admin_init' ) );
		add_filter( 'comments_open', '__return_false', 20, 2 );
		add_filter( 'pings_open', '__return_false', 20, 2 );
		add_filter( 'comments_array', '__return_empty_array', 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_comments_menu' ) );
		add_action( 'init', array( __CLASS__, 'remove_comments_adminbar' ) );

		// --- WC My-Account greeting reword (#11638) ---
		add_filter( 'gettext', array( __CLASS__, 'wc_greeting' ), 20, 3 );

		// --- Noindex PMPro member pages (#11696) ---
		add_filter( 'wpseo_robots', array( __CLASS__, 'noindex_robots' ) );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'noindex_robots_array' ) );

		// --- /pricing/ -> /membership-pricing/ (#11626) ---
		add_action( 'template_redirect', array( __CLASS__, 'pricing_redirect' ), 1 );

		// --- Support-email footer (#11691) ---
		// Priority 98 (before the snippet's 99) so that while both are active the
		// plugin's footer renders FIRST and the snippet's duplicate is hidden by
		// the `.csm-support-footer ~ .csm-support-footer` rule in site.css.
		add_action( 'wp_footer', array( __CLASS__, 'support_footer' ), 98 );
	}

	/* ---- comments (#11242) --------------------------------------------- */

	public static function comments_admin_init() {
		global $pagenow;
		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	public static function remove_comments_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	public static function remove_comments_adminbar() {
		if ( is_admin_bar_showing() ) {
			remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
		}
	}

	/* ---- WC greeting (#11638) ------------------------------------------ */

	public static function wc_greeting( $translated, $text, $domain ) {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}
		if ( 'Hello %1$s (not %1$s? %2$s)' === $text ) {
			return 'Hello %1$s — not you? %2$s';
		}
		return $translated;
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
}
