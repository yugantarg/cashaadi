<?php
/**
 * ZeptoMail transport.
 *
 * WHY REPLACE BREVO. Brevo does not filter wp_mail(), it REDEFINES it —
 * mailin/sendinblue.php declares the pluggable function itself. That is how a
 * real member came to be emailed from staging despite a pre_wp_mail
 * suppression: the filter never ran, because core's wp_mail() was never loaded.
 * Anything that wants to govern mail on this site has to win that race or
 * remove the other runner.
 *
 * So this uses pre_wp_mail — the supported short-circuit — and REQUIRES Brevo to
 * be deactivated. If Brevo is still active this class stands down rather than
 * pretending to be in charge, because a transport that silently does nothing is
 * worse than one that says it is not installed.
 *
 * THE TOKEN IS NEVER IN THE REPOSITORY. It is read from a wp-config constant the
 * owner sets themselves:
 *
 *     define( 'CASHAADI_ZEPTO_TOKEN', 'Zoho-enczapikey ...' );
 *
 * Without it this class does nothing at all, which is also what keeps staging
 * quiet: the constant is only defined on production.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zepto {

	/** Zoho's India data centre. Filterable for an account in another region. */
	const ENDPOINT = 'https://api.zeptomail.in/v1.1/email';

	public static function register() {
		if ( ! self::configured() ) {
			return;
		}
		/*
		 * Priority 5: ahead of anything else that might short-circuit, but the
		 * staging mail sink defines wp_mail() outright and therefore still wins.
		 * That ordering is deliberate — staging must never reach a live sender.
		 */
		add_filter( 'pre_wp_mail', array( __CLASS__, 'send' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'brevo_notice' ) );
	}

	public static function configured() {
		return defined( 'CASHAADI_ZEPTO_TOKEN' ) && '' !== trim( (string) CASHAADI_ZEPTO_TOKEN );
	}

	/** Brevo redefines wp_mail(), so the two cannot both be in charge. */
	public static function brevo_active() {
		return function_exists( 'is_plugin_active' )
			? is_plugin_active( 'mailin/sendinblue.php' )
			: in_array( 'mailin/sendinblue.php', (array) get_option( 'active_plugins', array() ), true );
	}

	public static function brevo_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::brevo_active() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>ZeptoMail is configured but not in control.</strong> '
			. 'Brevo (mailin) redefines wp_mail(), so ZeptoMail cannot take over until Brevo is deactivated.</p></div>';
	}

	/**
	 * Send one message through ZeptoMail's API.
	 *
	 * Returns null to let WordPress carry on when this cannot handle the message
	 * — never false, which would report a failure we did not actually have.
	 *
	 * @param null|bool $short  Short-circuit value from pre_wp_mail.
	 * @param array     $atts   to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public static function send( $short, $atts ) {
		if ( null !== $short ) {
			return $short;   // somebody earlier already handled it
		}
		if ( self::brevo_active() ) {
			return null;     // Brevo owns wp_mail(); do not pretend otherwise
		}

		$to = isset( $atts['to'] ) ? $atts['to'] : array();
		$to = is_array( $to ) ? $to : explode( ',', (string) $to );

		$recipients = array();
		foreach ( $to as $addr ) {
			$addr = trim( (string) $addr );
			// "Name <a@b.c>" as well as a bare address.
			if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) {
				$addr = trim( $m[1] );
			}
			if ( is_email( $addr ) ) {
				$recipients[] = array( 'email_address' => array( 'address' => $addr ) );
			}
		}
		if ( ! $recipients ) {
			return null;     // nothing we can send; let core try and log properly
		}

		/*
		 * Attachments are not handled here. Nothing this site sends has one, and
		 * returning null for those is honest — WordPress falls back to its own
		 * mailer rather than silently dropping the file.
		 */
		if ( ! empty( $atts['attachments'] ) ) {
			return null;
		}

		$body = array(
			'from'     => array(
				'address' => (string) apply_filters( 'csm_zepto_from', Config::SUPPORT_EMAIL ),
				'name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'to'       => $recipients,
			'subject'  => (string) ( $atts['subject'] ?? '' ),
			'htmlbody' => (string) ( $atts['message'] ?? '' ),
		);

		$response = wp_remote_post( (string) apply_filters( 'csm_zepto_endpoint', self::ENDPOINT ), array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => (string) CASHAADI_ZEPTO_TOKEN,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			self::record_error( $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			$raw = wp_remote_retrieve_body( $response );
			self::record_error( 'HTTP ' . $code . ': ' . substr( wp_strip_all_tags( (string) $raw ), 0, 160 ) );
			return false;
		}

		return true;
	}

	/**
	 * Queue::deliver() reads this transient to tell a real failure from a
	 * mailer that returned true without sending. Same contract the Brevo path
	 * used, so nothing downstream changes.
	 */
	private static function record_error( $message ) {
		set_transient( 'csm_remail_mail_error', 'zeptomail: ' . $message, 5 * MINUTE_IN_SECONDS );
	}
}
