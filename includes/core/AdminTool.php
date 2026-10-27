<?php
/**
 * Confirmation step for the admin URL tools (?csmdob=apply, ?csm_avc_runnow ...).
 *
 * They are typed into the address bar, so they had no nonce, and a page an
 * administrator happened to load could trigger them (CSRF; security audit,
 * 2026-10-27). A tool that writes now stops at a "Confirm and run" link
 * carrying a nonce; the link runs it.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminTool {

	const ACTION = 'csm_admin_tool';

	/** Returns when confirmed; otherwise ends the request with the confirmation link. */
	public static function confirm( $what ) {
		$nonce = isset( $_GET['_csmtool'] ) ? sanitize_text_field( wp_unslash( $_GET['_csmtool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $nonce && wp_verify_nonce( $nonce, self::ACTION ) ) {
			return;
		}
		$url = add_query_arg( '_csmtool', wp_create_nonce( self::ACTION ) );
		wp_die(
			'<p>' . esc_html( $what ) . ' changes data.</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">Confirm and run</a></p>',
			'Confirm',
			array( 'response' => 200 )
		);
	}
}
