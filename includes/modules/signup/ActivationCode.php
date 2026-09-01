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

	/** Seconds a member must wait between resend requests. */
	const RESEND_COOLDOWN = 60;

	/** Resends allowed per address per hour. */
	const RESEND_MAX = 5;

	public static function register() {
		// Issue a code as soon as the signup row exists.
		add_action( 'bp_core_signup_user', array( __CLASS__, 'on_signup' ), 20, 5 );

		// Put the code into BuddyPress's own registration email.
		add_filter( 'bp_email_get_property', array( __CLASS__, 'inject_code' ), 20, 4 );

		// Handle a submitted code before anything renders.
		add_action( 'bp_template_redirect', array( __CLASS__, 'handle_post' ), 0 );

		// Same check without a page load (see render_form).
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 24 );

		// Render the code form on the activation screen...
		add_action( 'bp_before_activate_content', array( __CLASS__, 'render_form' ) );

		// ...and immediately after signup, so the member never has to leave the
		// screen at all. Without this they land on BuddyPress's "check your email
		// to activate" page, which is exactly the link-hunting the code replaces.
		add_action( 'bp_after_registration_confirmed', array( __CLASS__, 'render_form_after_signup' ) );
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

		// Send our OWN code email as well.
		//
		// The first live test (2026-09-01) produced an activation email with
		// neither the link nor the code, and BuddyPress's bp-email templates are
		// not even editable on this install ("not allowed to edit posts in this
		// post type") — with 154 accounts sitting unactivated. The injection
		// filter alone therefore cannot be relied on: if BuddyPress's template
		// renders empty, so does anything we prepend to it.
		//
		// This mail is plain wp_mail() and depends on no BuddyPress template, so
		// the member gets a usable code even when that path is broken.
		self::send_code_email( $user_email, $code );
	}

	/**
	 * Minimal, template-free code email. Deliberately independent of BuddyPress's
	 * email system so a broken template cannot block activation.
	 */
	private static function send_code_email( $email, $code ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: 4-digit code, 2: site name. */
			__( '%1$s is your %2$s verification code', 'cashaadi-ui' ),
			$code,
			$site
		);

		$msg  = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#16212b;max-width:460px;margin:0 auto;text-align:center">';
		$msg .= '<p style="margin:0 0 16px">Welcome to ' . esc_html( $site ) . '. Enter this code to activate your account:</p>';
		$msg .= '<p style="margin:0 0 14px;font-size:36px;font-weight:700;letter-spacing:.22em">' . esc_html( $code ) . '</p>';
		$msg .= '<p style="margin:0;color:#5c6a76;font-size:13px">This code expires in 15 minutes. If you did not sign up, you can ignore this email.</p>';
		$msg .= '</div>';

		$ct = function () { return 'text/html'; };
		add_filter( 'wp_mail_content_type', $ct );
		wp_mail( $email, $subject, $msg );
		remove_filter( 'wp_mail_content_type', $ct );
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

	/**
	 * Verify a code and, on success, activate + log the member in.
	 *
	 * Shared by the form POST and the REST endpoint so the two can never drift
	 * apart on the security-relevant details (uniform failure messages, attempt
	 * burning, single use, auto-login).
	 *
	 * @return array{ok:bool,message:string,redirect:string}
	 */
	private static function attempt( $email, $code ) {
		$fail = array(
			'ok'       => false,
			'message'  => __( 'That code is not valid or has expired. Please check your email and try again.', 'cashaadi-ui' ),
			'redirect' => '',
		);

		// Deliberately uniform failure: never reveal whether the address exists,
		// whether a code was issued, or whether attempts were exhausted.
		if ( ! $email || ! self::verify( $email, $code ) ) {
			return $fail;
		}

		$key = self::activation_key_for( $email );
		if ( '' === $key ) {
			return $fail;
		}

		$user_id = bp_core_activate_signup( $key );
		if ( is_wp_error( $user_id ) || empty( $user_id ) ) {
			$fail['message'] = __( 'We could not activate that account. Please try the link in your email.', 'cashaadi-ui' );
			return $fail;
		}

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			$fail['message'] = __( 'We could not activate that account. Please try the link in your email.', 'cashaadi-ui' );
			return $fail;
		}

		// Same auto-login as the link flow (#11583).
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'wp_login', $user->user_login, $user );

		/*
		 * Hand off to onboarding, NOT to Discover.
		 *
		 * This used to send a freshly activated member straight to /discover/. That
		 * was survivable only while the photo gate existed to bounce them back into
		 * setting up a profile; with the gate removed (v0.42.0) it would drop a
		 * brand-new member into the app with no photo and no details, having never
		 * been asked for either.
		 *
		 * As of v0.49.0 that means /welcome/ — the onboarding route, which holds
		 * the member until a photo and every required field is done, and cannot be
		 * walked out of the way the old group-by-group wizard could.
		 *
		 * To fall back to the old wizard, point this at
		 * bp_members_get_user_url( $user_id ) . 'profile/edit/group/1/' — it still
		 * works, it is just no longer where new members are sent.
		 */
		$next = home_url( '/welcome/' );

		return array(
			'ok'       => true,
			'message'  => '',
			'redirect' => $next,
		);
	}

	public static function handle_post() {
		if ( empty( $_POST['csm_act_code'] ) || empty( $_POST['csm_act_email'] ) ) {
			return;
		}
		if ( ! isset( $_POST['csm_act_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csm_act_nonce'] ) ), 'csm_act_code' ) ) {
			return;
		}

		$res = self::attempt(
			sanitize_email( wp_unslash( $_POST['csm_act_email'] ) ),
			sanitize_text_field( wp_unslash( $_POST['csm_act_code'] ) )
		);

		if ( ! $res['ok'] ) {
			$GLOBALS['csm_act_error'] = $res['message'];
			return;
		}

		wp_safe_redirect( $res['redirect'] );
		exit;
	}

	/* ------------------------------------------------------------ resend */

	/**
	 * Issue and send a fresh code.
	 *
	 * Needed because MAX_TRIES destroys the code after five wrong guesses, which
	 * previously left the member with no way forward at all.
	 *
	 * Deliberately NOT BuddyPress's own signup/resend endpoint: that resends
	 * BuddyPress's activation email, which is the template that arrived empty on
	 * this install and is not even editable here. Reissuing through our own
	 * template-free send_code_email() is the path already proven to deliver.
	 *
	 * TWO ABUSE CONCERNS, both handled:
	 *   - email bombing — a cooldown plus an hourly cap, applied to the address
	 *     *before* we look at whether it exists, so the limit cannot be sidestepped
	 *   - address enumeration — the reply is identical whether or not the address
	 *     is awaiting activation, so this cannot be used to test who is registered
	 *
	 * @return array{ok:bool,message:string}
	 */
	private static function resend( $email ) {
		$generic = array(
			'ok'      => true,
			'message' => __( 'If that address is awaiting activation, a new code is on its way.', 'cashaadi-ui' ),
		);

		if ( ! $email ) {
			return $generic;
		}

		// Rate limit FIRST — before any lookup — so the response timing and the
		// limit itself are the same for real and made-up addresses.
		$k    = 'csm_actrs_' . sha1( strtolower( trim( $email ) ) );
		$rate = get_transient( $k );
		$now  = time();

		if ( is_array( $rate ) ) {
			if ( $now - (int) $rate['last'] < self::RESEND_COOLDOWN ) {
				return array(
					'ok'      => false,
					'message' => __( 'Please wait a minute before asking for another code.', 'cashaadi-ui' ),
				);
			}
			if ( (int) $rate['count'] >= self::RESEND_MAX ) {
				return array(
					'ok'      => false,
					'message' => __( 'Too many codes requested. Please try again later.', 'cashaadi-ui' ),
				);
			}
			$rate['count'] = (int) $rate['count'] + 1;
			$rate['last']  = $now;
		} else {
			$rate = array( 'count' => 1, 'last' => $now );
		}
		// Rolling one-hour window: the TTL is pushed out on every accepted request,
		// so the hour runs from the LAST send, not the first. That is stricter than
		// a fixed window — continuing to request extends the block rather than
		// ageing it out — which is the right direction for a limit whose job is to
		// stop email bombing.
		set_transient( $k, $rate, HOUR_IN_SECONDS );

		// Only actually send for an address with a pending signup — but say the
		// same thing either way.
		if ( '' !== self::activation_key_for( $email ) ) {
			self::send_code_email( $email, self::issue( $email ) );
		}

		return $generic;
	}

	/* -------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route(
			'csm/v1',
			'/resend-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_resend' ),
				'permission_callback' => '__return_true', // member is logged out
				'args'                => array(
					'email' => array( 'required' => true ),
					'nonce' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			'csm/v1',
			'/activate-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_activate' ),
				// Necessarily public: the member is logged out until this succeeds.
				// The 4-digit code is the credential, and verify() caps attempts.
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array( 'required' => true ),
					'code'  => array( 'required' => true ),
					'nonce' => array( 'required' => true ),
				),
			)
		);
	}

	public static function rest_activate( $request ) {
		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'csm_act_code' ) ) {
			// Not a security leak — says nothing about the code or the address.
			return new \WP_REST_Response(
				array( 'ok' => false, 'message' => __( 'This page has expired. Please refresh and try again.', 'cashaadi-ui' ) ),
				200
			);
		}

		$res = self::attempt(
			sanitize_email( (string) $request->get_param( 'email' ) ),
			sanitize_text_field( (string) $request->get_param( 'code' ) )
		);

		// Always HTTP 200: the JS reads `ok`, and a 4xx here would be logged as a
		// server problem when a mistyped code is entirely expected.
		return new \WP_REST_Response( $res, 200 );
	}

	public static function rest_resend( $request ) {
		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'csm_act_code' ) ) {
			return new \WP_REST_Response(
				array( 'ok' => false, 'message' => __( 'This page has expired. Please refresh and try again.', 'cashaadi-ui' ) ),
				200
			);
		}

		return new \WP_REST_Response(
			self::resend( sanitize_email( (string) $request->get_param( 'email' ) ) ),
			200
		);
	}

	/* -------------------------------------------------------------- form */

	/**
	 * Straight after signup the address is known for this request, so prefill it
	 * and let the member type the code without navigating anywhere.
	 */
	public static function render_form_after_signup() {
		$email = ! empty( $GLOBALS['csm_activation_code_email'] )
			? sanitize_email( $GLOBALS['csm_activation_code_email'] )
			: '';
		self::render_form( $email );
	}

	/** The activation screen — the one page that always hosts this form. */
	private static function activate_url() {
		if ( function_exists( 'bp_get_activation_page' ) ) {
			return bp_get_activation_page();
		}
		return home_url( '/activate/' );
	}

	public static function assets() {
		$on = ( function_exists( 'bp_is_register_page' ) && bp_is_register_page() )
			|| ( function_exists( 'bp_is_activation_page' ) && bp_is_activation_page() );
		if ( ! $on ) {
			return;
		}
		\CAShaadi\Core\Assets::script( 'activation-code', 'assets/js/activation-code.js' );
	}

	public static function render_form( $prefill = '' ) {
		/*
		 * Don't ask for a code that is no longer needed.
		 *
		 * The link flow still exists and still works (that redundancy is deliberate
		 * — see the fail-safe note at the top). But bp_before_activate_content fires
		 * on the activation page regardless of outcome, so someone arriving via
		 * /activate/{key} would be shown "Enter your verification code" directly
		 * above BuddyPress's own "account activated" message, and anyone already
		 * logged in would be asked to activate an account they are using.
		 */
		if ( is_user_logged_in() ) {
			return;
		}
		if ( function_exists( 'bp_account_was_activated' ) && bp_account_was_activated() ) {
			return;
		}

		// Prefill from the just-completed signup, else from ?email= — neither is
		// trusted for anything but display; the code is what authenticates.
		$email = $prefill ? $prefill : ( isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '' );
		$err   = ! empty( $GLOBALS['csm_act_error'] ) ? $GLOBALS['csm_act_error'] : '';
		?>
		<div class="csm-actcode">
			<h2><?php esc_html_e( 'Enter your verification code', 'cashaadi-ui' ); ?></h2>
			<p class="csm-actcode-help"><?php esc_html_e( 'We emailed you a 4-digit code. Enter it below to activate your account.', 'cashaadi-ui' ); ?></p>

			<?php
			/*
			 * The error region is ALWAYS rendered, not conditionally.
			 *
			 * It used to be printed only when an error existed, which meant the JS
			 * had nothing to write into and a screen reader announced nothing. It is
			 * empty and hidden by CSS until it has text.
			 */
			?>
			<p class="csm-actcode-err" role="alert" aria-live="polite"><?php echo esc_html( $err ); ?></p>

			<?php
			/*
			 * action="" posted back to whatever page hosted the form. Straight after
			 * signup that is /register/, where this form is rendered by
			 * bp_after_registration_confirmed — a hook that ONLY fires in the request
			 * where a signup just completed. So a wrong code posted back to a page
			 * that could no longer render the form: the field vanished and the error
			 * had nowhere to appear (reported live, 2026-09-01: "came back to a blank
			 * sign up page").
			 *
			 * Posting to the activation page fixes that: bp_before_activate_content
			 * fires there on every request, so the form and its error always survive
			 * a failed attempt. The JS below means it is normally never used.
			 */
			?>
			<form method="post" class="csm-actcode-form"
				action="<?php echo esc_url( self::activate_url() ); ?>"
				data-endpoint="<?php echo esc_url( rest_url( 'csm/v1/activate-code' ) ); ?>">
				<?php wp_nonce_field( 'csm_act_code', 'csm_act_nonce' ); ?>
				<label for="csm_act_email"><?php esc_html_e( 'Email address', 'cashaadi-ui' ); ?></label>
				<input type="email" id="csm_act_email" name="csm_act_email" value="<?php echo esc_attr( $email ); ?>" required autocomplete="email">

				<label for="csm_act_code"><?php esc_html_e( '4-digit code', 'cashaadi-ui' ); ?></label>
				<input type="text" id="csm_act_code" name="csm_act_code" class="csm-actcode-input"
					inputmode="numeric" pattern="[0-9]*" maxlength="4" required
					autocomplete="one-time-code" placeholder="0000">

				<button type="submit"><?php esc_html_e( 'Activate my account', 'cashaadi-ui' ); ?></button>
			</form>

			<?php
			/*
			 * Without this a member who mistyped five times had no way forward at
			 * all: MAX_TRIES destroys the code, and every later attempt fails with
			 * the same "not valid or expired" message and no remedy.
			 */
			?>
			<p class="csm-actcode-resend">
				<button type="button" class="csm-actcode-resend-btn"
					data-endpoint="<?php echo esc_url( rest_url( 'csm/v1/resend-code' ) ); ?>">
					<?php esc_html_e( 'Send me a new code', 'cashaadi-ui' ); ?>
				</button>
				<span class="csm-actcode-resend-msg" role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}
}
