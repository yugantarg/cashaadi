<?php
/**
 * Our own sign-up screen (owner, 2026-10-28: "why can't we make this a part of
 * the plugin/app and not go through BuddyPress").
 *
 * That day signups stopped from 1 pm: an update changed BuddyPress's register
 * page so its confirm-password box was hidden AND required (v1.90.38). The
 * page is BuddyPress's template, styles and scripts under the theme's, with
 * our patches on top, and any of the three can rearrange it. This screen
 * depends on none of them.
 *
 * WHAT IS OURS: the page (its own document, like the landing page), the form,
 * the checks and their messages, the request that submits it.
 *
 * WHAT STAYS BUDDYPRESS'S: the account itself. The form's answers go to
 * bp_core_signup_user(), the same function BuddyPress's form ends in, so the
 * signups row, the activation key, the 4-digit code (ActivationCode hooks it),
 * referral attach, the funnel and everything after activation (welcome steps,
 * Meta / Google sign-up events, attribution) are unchanged. The code step
 * reuses ActivationCode's endpoints.
 *
 *   /join/       always available (noindex until it is the sign-up page);
 *   /register/   shows this screen to logged-out visitors once csm_join_live
 *                is 1, at the same address, so Meta, Google and every link
 *                keep tracking it. Set it back to 0 and BuddyPress's form is back.
 */

namespace CAShaadi\Modules\Signup;

use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Join {

	const PATH     = 'join';
	const LIVE     = 'csm_join_live';
	const MIN_AGE  = 18;
	const THROTTLE = 8; // submissions per address (IP) per 10 minutes

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function here() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return strtolower( trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' ) );
	}

	public static function url() {
		return get_option( self::LIVE ) ? home_url( '/register/' ) : home_url( '/' . self::PATH . '/' );
	}

	public static function maybe_render() {
		$here = self::here();
		/*
		 * Live: this screen IS /register/ (owner, 2026-10-28: "keep register as
		 * the registration page, it is the one tracked by Meta and others"), in
		 * place of BuddyPress's form, for logged-out visitors. /join/ is the
		 * preview before that, and afterwards just another way in.
		 */
		$live = get_option( self::LIVE ) && 'register' === $here && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && ! is_user_logged_in();
		if ( self::PATH !== $here && ! $live ) {
			return;
		}
		if ( is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/discover/' ) );
			exit;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		// The page carries nonces, so it must never be served from cache.
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'csm join: carries nonces' );
		if ( ! get_option( self::LIVE ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			add_filter( 'wpseo_robots', function () { return 'noindex, nofollow'; } );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 999 );
		// Drawn last, so Site Kit's and other tags are registered (as the landing page).
		add_action( 'template_redirect', array( __CLASS__, 'draw' ), PHP_INT_MAX );
	}

	public static function assets() {
		global $wp_styles, $wp_scripts;
		foreach ( (array) $wp_styles->queue as $h ) {
			if ( 0 !== strpos( $h, 'cashaadi-' ) && 'admin-bar' !== $h ) {
				wp_dequeue_style( $h );
			}
		}
		foreach ( (array) $wp_scripts->queue as $h ) {
			if ( 0 === strpos( $h, 'elementor' ) ) {
				wp_dequeue_script( $h );
			}
		}
		wp_enqueue_style( 'cashaadi-landing-fonts', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		Assets::style( 'join', 'assets/css/join.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'join', 'assets/js/join.js' );
		wp_localize_script( 'cashaadi-join', 'CSM_JOIN', array(
			'submit'   => esc_url_raw( rest_url( 'csm/v1/join' ) ),
			'nonce'    => wp_create_nonce( 'csm_join' ),
			'activate' => esc_url_raw( rest_url( 'csm/v1/activate-code' ) ),
			'resend'   => esc_url_raw( rest_url( 'csm/v1/resend-code' ) ),
			'codeNonce'=> wp_create_nonce( 'csm_act_code' ),
			'welcome'  => esc_url_raw( home_url( '/welcome/' ) ),
		) );
	}

	/* ------------------------------------------------------------ fields */

	/** Gender choices as BuddyPress stores them. */
	private static function genders() {
		$out = array();
		if ( function_exists( 'xprofile_get_field' ) ) {
			$f = xprofile_get_field( Config::FIELD_GENDER, null, false );
			if ( $f && method_exists( $f, 'get_children' ) ) {
				foreach ( (array) $f->get_children() as $c ) {
					$out[] = (string) $c->name;
				}
			}
		}
		return $out ? $out : array( 'Male', 'Female' );
	}

	/* -------------------------------------------------------------- REST */

	public static function routes() {
		register_rest_route( 'csm/v1', '/join', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_join' ),
			'permission_callback' => '__return_true', // the visitor has no account yet
		) );
	}

	private static function fail( $errors, $msg = '' ) {
		return new \WP_REST_Response( array( 'ok' => false, 'errors' => (object) $errors, 'message' => $msg ), 200 );
	}

	public static function rest_join( $request ) {
		$p = (array) $request->get_json_params();
		if ( ! wp_verify_nonce( (string) ( $p['nonce'] ?? '' ), 'csm_join' ) ) {
			return self::fail( array(), 'This page has expired. Please refresh it and try again.' );
		}
		// Bots fill every field, people never see this one.
		if ( ! empty( $p['cj_trap'] ) ) {
			return self::fail( array(), 'Something went wrong. Please try again.' );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$tk  = 'csm_join_ip_' . md5( $ip );
		$hit = (int) get_transient( $tk );
		if ( $hit >= self::THROTTLE ) {
			return self::fail( array(), 'Too many tries. Please wait a few minutes and try again.' );
		}
		set_transient( $tk, $hit + 1, 10 * MINUTE_IN_SECONDS );

		$email  = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$pass   = (string) ( $p['password'] ?? '' );
		$name   = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $p['name'] ?? '' ) ) ) );
		$gender = sanitize_text_field( (string) ( $p['gender'] ?? '' ) );
		$phone  = sanitize_text_field( (string) ( $p['phone'] ?? '' ) );
		$agree  = ! empty( $p['agree'] );

		$fkey = class_exists( '\\CAShaadi\\Modules\\Analytics\\Funnel' ) ? \CAShaadi\Modules\Analytics\Funnel::email_key( $email ) : '';
		if ( $fkey ) {
			\CAShaadi\Modules\Analytics\Funnel::record( 'form_submit', $fkey, 0, 'join' );
		}

		$err = array();
		if ( ! is_email( $email ) ) {
			$err['email'] = 'Please enter a valid email address.';
		}
		if ( function_exists( 'bp_members_validate_user_password' ) ) {
			$pe = bp_members_validate_user_password( $pass, $pass );
			if ( is_wp_error( $pe ) && $pe->has_errors() ) {
				$err['password'] = wp_strip_all_tags( $pe->get_error_message() );
			}
		} elseif ( strlen( $pass ) < PasswordPolicy::MIN_LENGTH ) {
			$err['password'] = sprintf( 'Use at least %d characters.', PasswordPolicy::MIN_LENGTH );
		}
		if ( mb_strlen( $name ) < 2 ) {
			$err['name'] = 'Please enter your name.';
		}
		if ( ! in_array( $gender, self::genders(), true ) ) {
			$err['gender'] = 'Please choose one.';
		}
		$digits = class_exists( '\\CAShaadi\\Core\\Verification' ) ? \CAShaadi\Core\Verification::normalize_phone( $phone ) : preg_replace( '/\D+/', '', $phone );
		if ( strlen( $digits ) < 10 || strlen( $digits ) > 13 ) {
			$err['phone'] = 'Please enter a valid mobile number.';
		}
		if ( ! $agree ) {
			$err['agree'] = 'Please accept the terms and privacy policy.';
		}

		// BuddyPress's own email and username checks (banned domains, taken, pending).
		$login = 'u' . strtolower( wp_generate_password( 14, false ) );
		if ( empty( $err['email'] ) && function_exists( 'bp_core_validate_user_signup' ) ) {
			$v = bp_core_validate_user_signup( $login, $email );
			if ( ! empty( $v['errors'] ) && is_wp_error( $v['errors'] ) && $v['errors']->get_error_message( 'user_email' ) ) {
				// Already signed up but never entered the code: go straight to the code step.
				if ( self::pending( $email ) ) {
					return new \WP_REST_Response( array( 'ok' => true, 'pending' => true, 'email' => $email ), 200 );
				}
				$err['email'] = wp_strip_all_tags( $v['errors']->get_error_message( 'user_email' ) );
			}
			if ( ! empty( $v['user_name'] ) ) {
				$login = $v['user_name'];
			}
		}

		// Each answer through its own xProfile field type's check, as BuddyPress would.
		$values = array(
			Config::FIELD_NAME   => $name,
			Config::FIELD_GENDER => $gender,
			Config::FIELD_PHONE  => $phone,
		);
		// Date of birth and city are asked in the setup wizard (owner, 2026-10-28).
		$keys = array( Config::FIELD_NAME => 'name', Config::FIELD_GENDER => 'gender', Config::FIELD_PHONE => 'phone' );
		if ( function_exists( 'xprofile_get_field' ) ) {
			foreach ( $values as $fid => $val ) {
				$f = xprofile_get_field( $fid, null, false );
				if ( empty( $err[ $keys[ $fid ] ] ) && $f && isset( $f->type_obj ) && is_object( $f->type_obj ) && ! $f->type_obj->is_valid( $val ) ) {
					$err[ $keys[ $fid ] ] = 'Please check this.';
				}
			}
		}

		if ( $err ) {
			if ( $fkey ) {
				\CAShaadi\Modules\Analytics\Funnel::record( 'validation_error', $fkey, 0, implode( ',', array_keys( $err ) ), implode( "\n", $err ) );
			}
			return self::fail( $err );
		}

		// The usermeta BuddyPress's form would have built.
		$usermeta = array();
		foreach ( $values as $fid => $val ) {
			$usermeta[ 'field_' . $fid ] = $val;
			$vis = Config::DEFAULT_VISIBILITY[ $fid ] ?? ( function_exists( 'bp_xprofile_get_meta' ) ? bp_xprofile_get_meta( $fid, 'field', 'default_visibility' ) : '' );
			$usermeta[ 'field_' . $fid . '_visibility' ] = $vis ? $vis : 'public';
		}
		$usermeta['profile_field_ids'] = implode( ',', array_keys( $values ) );
		$usermeta['password']          = wp_hash_password( $pass );
		$usermeta                      = apply_filters( 'bp_signup_usermeta', $usermeta );

		/*
		 * Only a WP_Error is a failure. Since BuddyPress 14 no user is created
		 * until activation, so success returns FALSE (the user id it does not
		 * have yet); treating that as failure showed "Something went wrong"
		 * after a signup that had worked and emailed its code (owner, 2026-10-28).
		 */
		$res = bp_core_signup_user( $login, $pass, $email, $usermeta );
		if ( is_wp_error( $res ) ) {
			return self::fail( array(), wp_strip_all_tags( $res->get_error_message() ) );
		}
		do_action( 'bp_complete_signup' );
		delete_transient( $tk );
		return new \WP_REST_Response( array( 'ok' => true, 'email' => $email ), 200 );
	}

	/** A signup that exists but was never activated. */
	private static function pending( $email ) {
		if ( ! class_exists( '\\BP_Signup' ) ) {
			return false;
		}
		$s = \BP_Signup::get( array( 'usersearch' => $email, 'active' => 0, 'number' => 1 ) );
		foreach ( (array) ( $s['signups'] ?? array() ) as $row ) {
			if ( strtolower( (string) $row->user_email ) === strtolower( $email ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------- page */

	public static function draw() {
		$genders = self::genders();
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body class="cj">
<header class="cj-nav">
	<a class="cj-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="CA Shaadi" height="32">' : 'CA Shaadi'; ?></a>
	<a class="cj-login" href="<?php echo esc_url( home_url( '/login/' ) ); ?>">Log in</a>
</header>

<main class="cj-main">
	<section class="cj-card" id="cj-step-form">
		<p class="cj-kicker">India’s exclusive CA matrimony</p>
		<h1>Create your free profile</h1>

		<form id="cj-form" novalidate>
			<div class="cj-field">
				<label for="cj-name">Name</label>
				<input id="cj-name" name="name" type="text" autocomplete="name" required>
				<p class="cj-err" data-for="name"></p>
			</div>

			<div class="cj-field">
				<span class="cj-label">Bride or groom?</span>
				<div class="cj-choices">
					<?php foreach ( $genders as $i => $g ) : ?>
						<label class="cj-choice"><input type="radio" name="gender" value="<?php echo esc_attr( $g ); ?>"><span><?php echo esc_html( 'Male' === $g ? 'Groom' : ( 'Female' === $g ? 'Bride' : $g ) ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<p class="cj-hint" id="cj-gender-hint" aria-live="polite">Who is this profile for?</p>
				<p class="cj-err" data-for="gender"></p>
			</div>


			<div class="cj-field">
				<label for="cj-phone">Mobile number</label>
				<input id="cj-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="10-digit mobile" required>
				<p class="cj-hint">You choose who can see it.</p>
				<p class="cj-err" data-for="phone"></p>
			</div>

			<div class="cj-field">
				<label for="cj-email">Email</label>
				<input id="cj-email" name="email" type="email" autocomplete="email" inputmode="email" required>
				<p class="cj-hint">We send a 4-digit code here to confirm it.</p>
				<p class="cj-err" data-for="email"></p>
			</div>

			<div class="cj-field">
				<label for="cj-pass">Password</label>
				<div class="cj-pass">
					<input id="cj-pass" name="password" type="password" autocomplete="new-password" minlength="<?php echo (int) PasswordPolicy::MIN_LENGTH; ?>" required>
					<button type="button" class="cj-eye" aria-label="Show password">Show</button>
				</div>
				<p class="cj-hint">At least <?php echo (int) PasswordPolicy::MIN_LENGTH; ?> characters.</p>
				<p class="cj-err" data-for="password"></p>
			</div>

			<div class="cj-hp" aria-hidden="true"><label>Leave this empty <input name="cj_trap" type="text" tabindex="-1" autocomplete="off"></label></div>

			<label class="cj-agree"><input type="checkbox" name="agree"> <span>I accept the <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>" target="_blank" rel="noopener">terms</a> and <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank" rel="noopener">privacy policy</a>.</span></label>
			<p class="cj-err" data-for="agree"></p>

			<p class="cj-err cj-err-top" data-for="_" role="alert"></p>
			<button type="submit" class="cj-btn">Create my profile</button>
			<p class="cj-small">Already a member? <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">Log in</a></p>
		</form>
	</section>

	<section class="cj-card" id="cj-step-code" hidden>
		<h1>Check your email</h1>
		<p class="cj-lead">We sent a 4-digit code to <b id="cj-sent-to"></b>. Enter it to finish.</p>
		<form id="cj-code-form" novalidate>
			<input id="cj-code" class="cj-code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" placeholder="0000" aria-label="4-digit code">
			<p class="cj-err" data-for="code" role="alert"></p>
			<button type="submit" class="cj-btn">Confirm</button>
		</form>
		<p class="cj-small">No email? Check spam, or <button type="button" class="cj-link" id="cj-resend">send a new code</button>. <span id="cj-resend-msg" role="status"></span></p>
		<p class="cj-small"><button type="button" class="cj-link" id="cj-change">Use a different email</button></p>
	</section>
</main>
<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}
}
