<?php
/**
 * MediaPrivacy — stop the whole photo library being readable by strangers.
 *
 * WHAT WAS OPEN. WordPress publishes /wp-json/wp/v2/media to anybody, logged in
 * or not. On a blog that is reasonable: attachments belong to posts. Here every
 * attachment is a member's photograph, and the endpoint returned 468 of them
 * with `author` and `source_url` on each — enough for anyone on the internet to
 * walk the list, tie each photograph to a member id, and download the
 * uncropped master.
 *
 * THAT ALSO DEFEATED THE BLUR. A member who chooses to blur their photo is
 * making a decision about who sees their face. Privacy renders a blurred
 * derivative for the card, but the original stayed openly readable, so the
 * choice was cosmetic to anyone who looked past the UI.
 *
 * ATTACHMENT PAGES did the same thing more directly: /photo1/ 301'd straight to
 * the full-size file, so a guessable slug was a link to somebody's photograph.
 *
 * WHAT THIS DOES NOT FIX. A direct file URL still works if you already have it.
 * Closing that means serving uploads through PHP with a capability check, which
 * is a much larger change and a real performance cost. This removes the two
 * ways to FIND the URLs; it does not make a known URL secret. Worth doing next
 * if member photographs are considered sensitive enough — see docs.
 */

namespace CAShaadi\Modules\Photos;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaPrivacy {

	public static function register() {
		// 1. The enumeration endpoint.
		add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_media_routes' ), 20 );

		/*
		 * 2. Attachment permalinks, which lead straight to the file.
		 *
		 * Hooked to BOTH `wp` and `template_redirect`, at priority 0. Yoast's
		 * "redirect attachment URLs" runs before template_redirect, so a
		 * template_redirect handler alone never got the chance — the page had
		 * already 301'd to the photograph. `wp` fires once the query is parsed,
		 * which is early enough to answer first and late enough for
		 * is_attachment() to be meaningful.
		 */
		add_action( 'wp', array( __CLASS__, 'block_attachment_pages' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'block_attachment_pages' ), 0 );

		// 3. Keep them out of sitemaps and feeds, which is another way to find them.
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'drop_attachment_sitemap' ) );
	}

	/**
	 * Who may read the media collection.
	 *
	 * upload_files rather than is_user_logged_in(): an ordinary member reading
	 * the list would still bypass every privacy and blur decision the app makes
	 * on the cards. Staff keep it, so wp-admin's media library is unaffected.
	 */
	private static function may_browse() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Remove the media routes for everyone who may not browse.
	 *
	 * Removing the route rather than filtering the query: an empty result set
	 * still confirms the endpoint exists and still leaks the total in the
	 * X-WP-Total header.
	 *
	 * @param array $routes All registered REST routes.
	 */
	public static function restrict_media_routes( $routes ) {
		if ( self::may_browse() ) {
			return $routes;
		}
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/media' ) ) {
				unset( $routes[ $route ] );
			}
		}
		return $routes;
	}

	/**
	 * An attachment page is a link to the file. Send it away.
	 *
	 * 404 rather than a redirect: a redirect to the home page is what 404-to-301
	 * used to do site-wide, and it is exactly the behaviour that hid three dead
	 * links for weeks. If a URL should not resolve, it should say so.
	 */
	public static function block_attachment_pages() {
		if ( ! is_attachment() || self::may_browse() ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();

		/*
		 * Clear the queried object as well. Yoast decides whether to redirect
		 * from the attachment it finds on the query, so leaving it in place
		 * lets the redirect happen anyway on a request we have already marked
		 * as not found.
		 */
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
		$wp_query->is_attachment     = false;
		$wp_query->is_single         = false;
		$wp_query->is_singular       = false;

		status_header( 404 );
		nocache_headers();
	}

	/** Attachments do not belong in the sitemap. */
	public static function drop_attachment_sitemap( $post_types ) {
		unset( $post_types['attachment'] );
		return $post_types;
	}
}
