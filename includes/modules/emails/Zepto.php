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
 * THE TOKEN IS NEVER IN THE REPOSITORY. Owner: "I dont want to edit wp config.
 * All cred should be entered in front end." So it is entered on the Email
 * delivery admin screen and stored in an option, with a wp-config constant still
 * honoured as an override for anyone who prefers it:
 *
 *     define( 'CASHAADI_ZEPTO_TOKEN', 'Zoho-enczapikey ...' );   // optional
 *
 * The option is stored with autoload = false, so a secret is not loaded into
 * memory on every page view of a site that serves millions of requests it is
 * irrelevant to. It is never echoed back in full — the screen shows the last
 * four characters only, which is enough to tell two keys apart and useless to
 * anyone reading over a shoulder.
 *
 * Without a token this class does nothing at all, which is also what keeps
 * staging quiet.
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
		// The settings screen loads whether or not a token exists — it is where
		// the token gets entered, so gating it on having one is a locked door
		// with the key inside.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_post_csm_zepto_save', array( __CLASS__, 'handle' ) );

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

	const OPTION_TOKEN = 'csm_zepto_token';
	const OPTION_FROM  = 'csm_zepto_from';

	/**
	 * The API token: the admin screen first, then a wp-config constant.
	 *
	 * The screen wins so that changing it does not require a deploy or SSH —
	 * which is the whole point of entering it in the front end.
	 */
	public static function token() {
		$stored = trim( (string) get_option( self::OPTION_TOKEN, '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}
		return defined( 'CASHAADI_ZEPTO_TOKEN' ) ? trim( (string) CASHAADI_ZEPTO_TOKEN ) : '';
	}

	/** Where mail says it is from. */
	public static function from() {
		$stored = trim( (string) get_option( self::OPTION_FROM, '' ) );
		$from   = ( '' !== $stored && is_email( $stored ) ) ? $stored : Config::SUPPORT_EMAIL;
		return (string) apply_filters( 'csm_zepto_from', $from );
	}

	public static function configured() {
		return '' !== self::token();
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
				'address' => self::from(),
				'name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'to'       => $recipients,
			'subject'  => (string) ( $atts['subject'] ?? '' ),
			'htmlbody' => (string) ( $atts['message'] ?? '' ),
		);

		$response = wp_remote_post( (string) apply_filters( 'csm_zepto_endpoint', self::ENDPOINT ), array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => self::token(),
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

	/* ------------------------------------------------------------ the screen */

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['csm-sales-dashboard'] ) ? 'csm-sales-dashboard' : 'tools.php';
		add_submenu_page(
			$parent,
			'Email delivery',
			'Email delivery',
			'manage_options',
			'csm-email-delivery',
			array( __CLASS__, 'render' )
		);
	}

	private static function url() {
		return admin_url( 'admin.php?page=csm-email-delivery' );
	}

	/** Last four characters only. Enough to tell two keys apart, useless to steal. */
	private static function masked() {
		$t = self::token();
		if ( '' === $t ) {
			return '';
		}
		return str_repeat( "\u{2022}", 12 ) . substr( $t, -4 );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'csm_zepto_save' );

		$msg = '';
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';

		if ( 'clear' === $do ) {
			delete_option( self::OPTION_TOKEN );
			$msg = 'API key removed. ZeptoMail is no longer in use.';
		} elseif ( 'test' === $do ) {
			$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
			if ( ! is_email( $to ) ) {
				$msg = 'That is not a valid email address.';
			} elseif ( ! self::configured() ) {
				$msg = 'Add the API key first.';
			} elseif ( self::brevo_active() ) {
				$msg = 'Deactivate Brevo first — it redefines wp_mail(), so ZeptoMail cannot take over.';
			} else {
				delete_transient( 'csm_remail_mail_error' );
				add_filter( 'wp_mail_content_type', function () { return 'text/html'; } );
				$ok  = wp_mail( $to, 'ZeptoMail test from ' . get_bloginfo( 'name' ), '<p>If this arrived, the transport works.</p>' );
				$err = get_transient( 'csm_remail_mail_error' );
				$msg = ( $ok && ! $err )
					? 'Sent to ' . $to . '. Check the inbox — and the spam folder.'
					: 'Failed: ' . ( $err ? $err : 'wp_mail returned false' );
			}
		} else {
			/*
			 * An empty key field means "leave it alone", not "delete it" — the
			 * field renders masked, so submitting the form without retyping the
			 * secret must not wipe it. Clearing has its own button.
			 */
			$key = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
			if ( '' !== $key && 0 !== strpos( $key, "\u{2022}" ) ) {
				// autoload false: a secret has no business in every page load.
				update_option( self::OPTION_TOKEN, $key, false );
				$msg = 'API key saved. ';
			}
			$from = isset( $_POST['from'] ) ? sanitize_email( wp_unslash( $_POST['from'] ) ) : '';
			if ( is_email( $from ) ) {
				update_option( self::OPTION_FROM, $from, false );
				$msg .= 'From address set to ' . $from . '.';
			}
			if ( '' === $msg ) {
				$msg = 'Nothing changed.';
			}
		}

		wp_safe_redirect( add_query_arg( 'csm_msg', rawurlencode( $msg ), self::url() ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$brevo  = self::brevo_active();
		$has    = self::configured();
		$master = class_exists( '\CAShaadi\Modules\Emails\Queue' ) && Queue::master_on();

		echo '<div class="wrap"><h1>Email delivery</h1>';

		if ( ! empty( $_GET['csm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['csm_msg'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<table class="widefat striped" style="max-width:720px;margin-bottom:20px"><tbody>';
		printf( '<tr><td style="width:220px"><strong>ZeptoMail API key</strong></td><td>%s</td></tr>',
			$has ? '<span style="color:#046b2d">set (' . esc_html( self::masked() ) . ')</span>' : '<span style="color:#a00">not set</span>' );
		printf( '<tr><td><strong>From address</strong></td><td>%s</td></tr>', esc_html( self::from() ) );
		printf( '<tr><td><strong>Brevo (mailin)</strong></td><td>%s</td></tr>',
			$brevo
				? '<span style="color:#a00">active — ZeptoMail cannot take over until this is deactivated</span>'
				: '<span style="color:#046b2d">deactivated</span>' );
		printf( '<tr><td><strong>In control of wp_mail()</strong></td><td>%s</td></tr>',
			( $has && ! $brevo ) ? '<span style="color:#046b2d">ZeptoMail</span>' : 'not ZeptoMail' );
		printf( '<tr><td><strong>Sending master switch</strong></td><td>%s</td></tr>',
			$master ? '<span style="color:#a00">ON — queued mail goes out</span>' : 'off (csm_remail_master = 0)' );
		printf( '<tr><td><strong>Daily cap</strong></td><td>%d</td></tr>',
			class_exists( '\CAShaadi\Modules\Emails\Queue' ) ? (int) Queue::daily_cap() : 0 );
		echo '</tbody></table>';

		// --- settings form
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:720px">';
		wp_nonce_field( 'csm_zepto_save' );
		echo '<input type="hidden" name="action" value="csm_zepto_save">';
		echo '<input type="hidden" name="do" value="save">';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th scope="row"><label for="csm-zepto-token">API key</label></th><td>'
			. '<input type="password" id="csm-zepto-token" name="token" class="regular-text" autocomplete="off" placeholder="%s">'
			. '<p class="description">From ZeptoMail &rarr; Mail Agents &rarr; SMTP &amp; API. Starts with <code>Zoho-enczapikey</code>. '
			. 'Leave blank to keep the current key.</p></td></tr>',
			$has ? esc_attr( self::masked() ) : 'Zoho-enczapikey …'
		);
		printf(
			'<tr><th scope="row"><label for="csm-zepto-from">From address</label></th><td>'
			. '<input type="email" id="csm-zepto-from" name="from" class="regular-text" value="%s">'
			. '<p class="description">Must be on a domain verified in ZeptoMail.</p></td></tr>',
			esc_attr( self::from() )
		);
		echo '</tbody></table>';
		submit_button( 'Save' );
		echo '</form>';

		// --- test
		echo '<hr><h2>Send a test</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'csm_zepto_save' );
		echo '<input type="hidden" name="action" value="csm_zepto_save">';
		echo '<input type="hidden" name="do" value="test">';
		printf( '<input type="email" name="test_to" class="regular-text" value="%s" required> ', esc_attr( wp_get_current_user()->user_email ) );
		echo '<button class="button">Send test email</button>';
		echo '<p class="description">Goes out immediately, bypassing the queue and the master switch — it is a transport check, not a campaign.</p>';
		echo '</form>';

		// --- remove
		if ( $has ) {
			echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'csm_zepto_save' );
			echo '<input type="hidden" name="action" value="csm_zepto_save">';
			echo '<input type="hidden" name="do" value="clear">';
			echo '<button class="button">Remove the API key</button>';
			echo '</form>';
		}

		echo '</div>';
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
