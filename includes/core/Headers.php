<?php
/**
 * Browser security headers on every front-end page (security audit,
 * 2026-10-27: the site sent none beyond LiteSpeed's upgrade-insecure-requests).
 *
 *   - HSTS: browsers only ever use https for cashaadi.in (a year; subdomains
 *     left out, so nothing on them can break).
 *   - nosniff: an uploaded file is never run as something it is not.
 *   - SAMEORIGIN: no other site can frame these pages (clickjacking).
 *   - Referrer: other sites see only the domain, never a member's page.
 *   - Permissions: the site never uses the camera, microphone or location.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Headers {

	public static function register() {
		add_action( 'send_headers', array( __CLASS__, 'send' ) );
	}

	public static function send() {
		if ( headers_sent() ) {
			return;
		}
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000' );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	}
}
