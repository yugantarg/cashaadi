<?php
/**
 * Secrets — the ONE accessor for third-party credentials.
 *
 * Reads from wp-config.php constants first (the target state), then falls back
 * to where the value lives today so nothing breaks mid-migration:
 *   - MSG91 (OTP, #11618) is currently hardcoded in the snippet — once this
 *     plugin owns OTP, define the constants below and the literal disappears.
 *   - OpenAI (#11815/#12119) currently lives in the csm_av_options DB option.
 *
 * This file contains NO secret values. To set the target state, add to
 * wp-config.php (above the "stop editing" line):
 *
 *   define( 'CASHAADI_MSG91_AUTHKEY',    '...' );
 *   define( 'CASHAADI_MSG91_WIDGET_ID',  '...' );
 *   define( 'CASHAADI_MSG91_TOKEN_AUTH', '...' );
 *   define( 'CASHAADI_OPENAI_API_KEY',   '...' );
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Secrets {

	private static function constant( $name ) {
		return ( defined( $name ) && '' !== (string) constant( $name ) ) ? (string) constant( $name ) : '';
	}

	public static function msg91_authkey() {
		return self::constant( 'CASHAADI_MSG91_AUTHKEY' );
	}

	public static function msg91_widget_id() {
		return self::constant( 'CASHAADI_MSG91_WIDGET_ID' );
	}

	public static function msg91_token_auth() {
		return self::constant( 'CASHAADI_MSG91_TOKEN_AUTH' );
	}

	/**
	 * OpenAI key: constant first, else the legacy csm_av_options['api_key'].
	 */
	public static function openai_api_key() {
		$c = self::constant( 'CASHAADI_OPENAI_API_KEY' );
		if ( '' !== $c ) {
			return $c;
		}
		$opts = get_option( Config::OPT_AV_OPTIONS, array() );
		return isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';
	}

	/**
	 * Whether a given secret is configured — for admin health checks, without
	 * ever exposing the value.
	 *
	 * @param string $which msg91_authkey|msg91_widget_id|msg91_token_auth|openai
	 */
	public static function has( $which ) {
		switch ( $which ) {
			case 'msg91_authkey':    return '' !== self::msg91_authkey();
			case 'msg91_widget_id':  return '' !== self::msg91_widget_id();
			case 'msg91_token_auth': return '' !== self::msg91_token_auth();
			case 'openai':           return '' !== self::openai_api_key();
		}
		return false;
	}
}
