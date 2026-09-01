<?php
/**
 * 4-digit email activation code.
 *
 * Replaces "click the link in your email" with "type the 4 digits we sent you",
 * so the member never leaves the activation screen (owner, 2026-09-01).
 *
 * DESIGNED TO FAIL SAFE. The code is injected into BuddyPress's OWN activation
 * email rather than suppressing it and sending our own, and the activation key
 * is never invalidated. So if the email filter or the form ever stops matching a
 * future BuddyPress release, the member still receives a working activation link
 * and the stock BuddyPress form still works — degraded, never locked out.
 *
 * Activation itself is unchanged: once a code is verified we hand the signup's
 * real activation_key to bp_core_activate_signup(), exactly as the link flow
 * does. Only the *credential* the member presents is different.
 *
 * SECURITY — a 4-digit code is only 10,000 combinations, so the rate limiting
 * here is load-bearing, not decoration:
 *   - MAX_TRIES attempts per issued code, then the code is destroyed and a new
 *     one must be requested
 *   - codes expire after TTL
 *   - codes are single-use and deleted the moment activation succeeds
 *   - the stored value is an HMAC, compared with hash_equals(), so neither the
 *     database nor comparison timing leaks the code
 *   - responses never reveal whether an address is registered
 *
 * Storage is a transient keyed by a hash of the email, so nothing is written to
 * the signups table and everything self-expires.
 */

namespace CAShaadi\Modules\Signup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivationCode {

	/** How long a code stays valid. */
	const TTL = 900; // 15 minutes

	/** Attempts allowed per issued code before it is destroyed. */
	const MAX_TRIES = 5;

	public static function register() {
		// Issue a code as soon as the signup row exists.
		add_action( 'bp_core_signup_user', array( __CLASS__, 'on_signup' ), 20, 5 );

		// Put the code into BuddyPress's own registration email.
		add_filter( 'bp_email_get_property', array( __CLASS__, 'inject_code' ), 20, 4 );

		// Handle a submitted code before anything renders.
		add_action( 'bp_template_redirect', array( __CLASS__, 'handle_post' ), 0 );

		// Render the code form on the activation screen.
		add_action( 'bp_before_activate_content', array( __CLASS__, 'render_form' ) );
	}

	/* ------------------------------------------------------------ storage */

	private static function key_for( $email ) {
		return 'csm_actcode_' . sha1( strtolower( trim( (string) $email ) ) );
	}

	/** HMAC so the stored value is not the code itself. */
	private static function hash( $code ) {
		return hash_hmac( 'sha256', (string) $code, wp_salt( 'auth' ) );
	}

	/**
	 * Generate, store and return a fresh 4-digit code for an address.
	 * Uses wp_rand() (CSPRNG-backed), never mt_rand().
	 */
	public static function issue( $email ) {
		$code = str_pad( (string) wp_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
		set_transient(
			self::key_for( $email ),
			array( 'hash' => self::hash( $code ), 'tries' => 0 ),
			self::TTL
		);
		return $code;
	}

	/**
	 * Check a submitted code.
	 *
	 * @return bool True only on an exact, in-date, non-exhausted match.
	 */
	public static function verify( $email, $submitted ) {
		$submitted = preg_replace( '/\D/', '', (string) $submitted );
		if ( 4 !== strlen( $submitted ) ) {
			return false;
		}

		$k    = self::key_for( $email );
		$data = get_transient( $k );
		if ( ! is_array( $data ) || empty( $data['hash'] ) ) {
			return false; // never issued, or expired
		}

		// Burn an attempt BEFORE comparing, so an abandoned request still counts
		// and the cap cannot be bypassed by dropping the connection.
		$data['tries'] = (int) $data['tries'] + 1;
		if ( $data['tries'] >= self::MAX_TRIES ) {
			delete_transient( $k ); // exhausted — force a fresh code
		} else {
			set_transient( $k, $data, self::TTL );
		}

		if ( ! hash_equals( (string) $data['hash'], self::hash( $submitted ) ) ) {
			return false;
		}

		delete_transient( $k ); // single use
		return true;
	}

	/* ------------------------------------------------------------- signup */

	public static function on_signup( $user_id, $user_login, $user_password, $user_email, $usermeta ) {
		if ( empty( $user_email ) ) {
			return;
		}
		$code = self::issue( $user_email );
		// Remembered for this request only, so the email filter below can pick it
		// up without re-issuing (which would invalidate the one just stored).
		$GLOBALS['csm_activation_code']       = $code;
		$GLOBALS['csm_activation_code_email'] = $user_email;
	}

	/**
	 * Inject the code into the registration email BuddyPress is about to send.
	 *
	 * Guarded on every side: only touches content properties, only when a code
	 * was issued in this same request, and returns the original value untouched
	 * otherwise — so a BuddyPress change can only cost us the injection, never
	 * the email.
	 */
	public static function inject_code( $value, $property, $transform, $email = null ) {
		if ( empty( $GLOBALS['csm_activation_code'] ) ) {
			return $value;
		}
		if ( ! in_array( $property, array( 'content_html', 'content_plaintext' ), true ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		$code = (string) $GLOBALS['csm_activation_code'];

		if ( 'content_plaintext' === $property ) {
			return "Your CA Shaadi verification code is: {$code}\n"
				. "It expires in 15 minutes. Enter it on the activation screen.\n\n"
				. $value;
		}

		$block = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#16212b;text-align:center;margin:0 0 22px">'
			. '<p style="margin:0 0 10px">Your verification code is</p>'
			. '<p style="margin:0 0 8px;font-size:34px;font-weight:700;letter-spacing:.22em">' . esc_html( $code ) . '</p>'
			. '<p style="margin:0;color:#5c6a76;font-size:13px">Enter this on the activation screen. It expires in 15 minutes.</p>'
			. '</div>';

		return $block . $value;
	}

	/* --------------------------------------------------------- activation */

	/** The signup's real activation key, mirroring Emails\Queue::activation_key(). */
	private static function activation_key_for( $email ) {
		global $wpdb;
		$table = $wpdb->base_prefix . 'signups';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT activation_key FROM {$table} WHERE user_email = %s AND active = 0 ORDER BY signup_id DESC LIMIT 1",
			$email
		) );
	}

	public static function handle_post() {
		if ( empty( $_POST['csm_act_code'] ) || empty( $_POST['csm_act_email'] ) ) {
			return;
		}
		if ( ! isset( $_POST['csm_act_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csm_act_nonce'] ) ), 'csm_act_code' ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( $_POST['csm_act_email'] ) );
		$code  = sanitize_text_field( wp_unslash( $_POST['csm_act_code'] ) );

		// Deliberately uniform failure: never reveal whether the address exists,
		// whether a code was issued, or whether attempts were exhausted.
		if ( ! $email || ! self::verify( $email, $code ) ) {
			$GLOBALS['csm_act_error'] = __( 'That code is not valid or has expired. Please check your email and try again.', 'cashaadi-ui' );
			return;
		}

		$key = self::activation_key_for( $email );
		if ( '' === $key ) {
			$GLOBALS['csm_act_error'] = __( 'That code is not valid or has expired. Please check your email and try again.', 'cashaadi-ui' );
			return;
		}

		$user_id = bp_core_activate_signup( $key );
		if ( is_wp_error( $user_id ) || empty( $user_id ) ) {
			$GLOBALS['csm_act_error'] = __( 'We could not activate that account. Please try the link in your email.', 'cashaadi-ui' );
			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			$GLOBALS['csm_act_error'] = __( 'We could not activate that account. Please try the link in your email.', 'cashaadi-ui' );
			return;
		}

		// Same auto-login as the link flow (#11583).
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( home_url( '/discover/' ) );
		exit;
	}

	/* -------------------------------------------------------------- form */

	public static function render_form() {
		// Prefill from ?email= (our own emails can carry it) without trusting it
		// for anything but display.
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$err   = ! empty( $GLOBALS['csm_act_error'] ) ? $GLOBALS['csm_act_error'] : '';
		?>
		<div class="csm-actcode">
			<h2><?php esc_html_e( 'Enter your verification code', 'cashaadi-ui' ); ?></h2>
			<p class="csm-actcode-help"><?php esc_html_e( 'We emailed you a 4-digit code. Enter it below to activate your account.', 'cashaadi-ui' ); ?></p>
			<?php if ( $err ) : ?>
				<p class="csm-actcode-err" role="alert"><?php echo esc_html( $err ); ?></p>
			<?php endif; ?>
			<form method="post" class="csm-actcode-form">
				<?php wp_nonce_field( 'csm_act_code', 'csm_act_nonce' ); ?>
				<label for="csm_act_email"><?php esc_html_e( 'Email address', 'cashaadi-ui' ); ?></label>
				<input type="email" id="csm_act_email" name="csm_act_email" value="<?php echo esc_attr( $email ); ?>" required autocomplete="email">

				<label for="csm_act_code"><?php esc_html_e( '4-digit code', 'cashaadi-ui' ); ?></label>
				<input type="text" id="csm_act_code" name="csm_act_code" class="csm-actcode-input"
					inputmode="numeric" pattern="[0-9]*" maxlength="4" required
					autocomplete="one-time-code" placeholder="0000">

				<button type="submit"><?php esc_html_e( 'Activate my account', 'cashaadi-ui' ); ?></button>
			</form>
		</div>
		<?php
	}
}
