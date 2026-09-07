<?php
/**
 * Email open and click tracking.
 *
 * WHY THIS EXISTS RATHER THAN THE PROVIDER'S. ZeptoMail and Brevo both report
 * opens in their own dashboards, and that is fine for a headline number. It
 * cannot answer the question the owner actually asked — "who joined and who
 * unsubscribed after the announcement" — because the provider knows an address,
 * not a member. Recording the open against the queue row makes it a fact the
 * site can act on.
 *
 * WHAT AN OPEN ACTUALLY MEANS. Less than it appears. Gmail and most clients
 * proxy or block remote images, Apple Mail Privacy Protection pre-fetches them
 * whether or not a human looked, and a plain-text reader never loads one. Open
 * rates are a floor with noise on top, and clicks are the number worth
 * trusting. Both are recorded; only one of them is evidence.
 *
 * NO REWRITE RULES. The endpoints are matched off REQUEST_URI on `init`, so
 * nothing has to be flushed at activation — a flush that does not happen is a
 * tracking link that 404s, and it would not be noticed until a campaign was
 * already out.
 */

namespace CAShaadi\Modules\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tracking {

	/** URL prefix for both endpoints. Short, because it goes in every link. */
	const PREFIX = 'csm-e';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 0 );
	}

	/* ------------------------------------------------------------- tokens */

	/**
	 * The row's tracking token, created on first use.
	 *
	 * Random, not derived from the user id or the address: a token that encodes
	 * who it belongs to leaks that to anyone who sees the URL, and these URLs
	 * travel through mail servers, spam filters and forwarded messages.
	 */
	public static function token_for( $row_id ) {
		global $wpdb;
		$t   = Queue::table();
		$id  = (int) $row_id;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tok = (string) $wpdb->get_var( $wpdb->prepare( "SELECT track_token FROM {$t} WHERE id = %d", $id ) );
		if ( '' !== $tok ) {
			return $tok;
		}
		$tok = wp_generate_password( 24, false, false );
		$wpdb->update( $t, array( 'track_token' => $tok ), array( 'id' => $id ) );
		return $tok;
	}

	private static function row_by_token( $token ) {
		global $wpdb;
		$t = Queue::table();
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE track_token = %s LIMIT 1", $token ) );
	}

	/* -------------------------------------------------------------- links */

	public static function pixel_url( $token ) {
		return home_url( '/' . self::PREFIX . '/o/' . rawurlencode( $token ) . '.gif' );
	}

	public static function click_url( $token, $target ) {
		return add_query_arg(
			'u',
			rawurlencode( $target ),
			home_url( '/' . self::PREFIX . '/c/' . rawurlencode( $token ) )
		);
	}

	/**
	 * Rewrite an email's links through the click endpoint and append the pixel.
	 *
	 * Only OUR OWN links are rewritten. An unsubscribe link, a mailto:, or
	 * anything pointing off-site is left exactly as it was — see the redirect
	 * guard below for why that matters.
	 */
	public static function instrument( $html, $row_id ) {
		$token = self::token_for( $row_id );
		$home  = wp_parse_url( home_url(), PHP_URL_HOST );

		$html = preg_replace_callback(
			'/href=(["\'])(https?:\/\/[^"\']+)\1/i',
			function ( $m ) use ( $token, $home ) {
				$host = wp_parse_url( $m[2], PHP_URL_HOST );
				if ( ! $host || strtolower( $host ) !== strtolower( $home ) ) {
					return $m[0];   // not ours: leave it alone
				}
				if ( false !== strpos( $m[2], '/' . self::PREFIX . '/' ) ) {
					return $m[0];   // already instrumented
				}
				return 'href=' . $m[1] . esc_url_raw( self::click_url( $token, $m[2] ) ) . $m[1];
			},
			$html
		);

		$pixel = '<img src="' . esc_url( self::pixel_url( $token ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">';
		return $html . $pixel;
	}

	/* ----------------------------------------------------------- endpoints */

	public static function maybe_handle() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		if ( 0 !== strpos( $path, self::PREFIX . '/' ) ) {
			return;
		}

		$parts = explode( '/', $path );
		$kind  = isset( $parts[1] ) ? $parts[1] : '';
		$token = isset( $parts[2] ) ? preg_replace( '/\.gif$/', '', $parts[2] ) : '';

		if ( 'o' === $kind ) {
			self::record_open( $token );
			self::serve_pixel();
		}
		if ( 'c' === $kind ) {
			self::record_click( $token );
			self::redirect( isset( $_GET['u'] ) ? rawurldecode( wp_unslash( $_GET['u'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	private static function record_open( $token ) {
		$row = self::row_by_token( $token );
		if ( ! $row ) {
			return;
		}
		global $wpdb;
		$t = Queue::table();
		$data = array( 'open_count' => (int) $row->open_count + 1 );
		if ( empty( $row->opened_at ) ) {
			$data['opened_at'] = current_time( 'mysql' );
		}
		$wpdb->update( $t, $data, array( 'id' => (int) $row->id ) );
	}

	private static function record_click( $token ) {
		$row = self::row_by_token( $token );
		if ( ! $row ) {
			return;
		}
		global $wpdb;
		$t = Queue::table();
		$data = array( 'click_count' => (int) $row->click_count + 1 );
		if ( empty( $row->clicked_at ) ) {
			$data['clicked_at'] = current_time( 'mysql' );
		}
		// A click proves the open, even when the pixel never loaded — which is
		// most of the time on Gmail and Apple Mail.
		if ( empty( $row->opened_at ) ) {
			$data['opened_at']  = current_time( 'mysql' );
			$data['open_count'] = (int) $row->open_count + 1;
		}
		$wpdb->update( $t, $data, array( 'id' => (int) $row->id ) );
	}

	/** A 1x1 transparent GIF, uncacheable so repeat opens are seen. */
	private static function serve_pixel() {
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore
		exit;
	}

	/**
	 * Redirect a tracked click — to our own site ONLY.
	 *
	 * These links sit in inboxes carrying our domain. An endpoint that forwards
	 * anywhere is an open redirect, and an open redirect on a domain people
	 * trust is exactly what a phishing campaign wants. Anything that is not
	 * cashaadi.in goes to the home page instead of being followed.
	 */
	private static function redirect( $target ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $target ? wp_parse_url( $target, PHP_URL_HOST ) : '';
		$ok   = ( $host && strtolower( $host ) === strtolower( $home ) );

		wp_safe_redirect( $ok ? $target : home_url( '/' ), 302 );
		exit;
	}

	/* -------------------------------------------------------------- report */

	/** Opens and clicks for one campaign, for the admin screen. */
	public static function stats( $email_type ) {
		global $wpdb;
		$t = Queue::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) total,
			        SUM(status = 'sent') sent,
			        SUM(opened_at IS NOT NULL) opened,
			        SUM(clicked_at IS NOT NULL) clicked
			   FROM {$t} WHERE email_type = %s",
			$email_type
		) );
		$sent = (int) ( $r->sent ?? 0 );
		return array(
			'total'   => (int) ( $r->total ?? 0 ),
			'sent'    => $sent,
			'opened'  => (int) ( $r->opened ?? 0 ),
			'clicked' => (int) ( $r->clicked ?? 0 ),
			'open_rate'  => $sent ? round( 100 * (int) $r->opened / $sent, 1 ) : 0.0,
			'click_rate' => $sent ? round( 100 * (int) $r->clicked / $sent, 1 ) : 0.0,
		);
	}
}
