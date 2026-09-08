<?php
/**
 * PasswordPolicy — one rule at signup: at least 8 characters.
 *
 * WHAT WAS THERE. BuddyPress's registration form shipped three things nobody
 * asked for: a pre-generated password in `data-pw` that browsers offer to fill,
 * the zxcvbn strength meter, and a strength gate that can refuse an account
 * outright. Between them a new member was told their own password was not good
 * enough, and nudged towards one they would never remember.
 *
 * Chrome and Safari already offer to generate and store a password, and they do
 * it better than a form can — the browser can actually remember the result.
 * Competing with that only produces a field the member fights with.
 *
 * WHAT REPLACES IT. Eight characters. No classes, no symbols, no score. Length
 * is the property that matters and the only one a person can act on without
 * being taught a notation.
 *
 * THE GATE DISABLES ITSELF. bp_members_user_pass_required_strength() is only
 * consulted when the form posts `_password_strength_score`, which only the
 * meter's JavaScript supplies. Dequeuing the meter removes the score, so the
 * gate is skipped — the filter below is belt and braces in case a future
 * BuddyPress posts it from elsewhere.
 */

namespace CAShaadi\Modules\Signup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PasswordPolicy {

	/** The whole policy. */
	const MIN_LENGTH = 8;

	public static function register() {
		// 1. Stop the browser being handed a generated password to offer.
		add_filter( 'bp_get_form_field_attributes', array( __CLASS__, 'strip_generated' ), 10, 2 );

		// 2. Drop the strength meter on registration.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_meter' ), 100 );

		// 3. Never refuse an account for being "not strong enough".
		add_filter( 'bp_members_user_pass_required_strength', '__return_zero', 100 );

		// 4. The one rule we do keep.
		add_filter( 'bp_members_validate_user_password', array( __CLASS__, 'validate' ), 10, 3 );
	}

	/**
	 * Remove the suggested password from the field.
	 *
	 * BuddyPress puts wp_generate_password(12) into data-pw, which its own
	 * JavaScript and some password managers read as "use this". Removing the
	 * attribute leaves an ordinary empty password box — which is exactly what
	 * lets Chrome and Safari make their own suggestion, in their own UI, where
	 * it will actually be saved.
	 *
	 * @param array  $attributes The attributes about to be printed.
	 * @param string $name       The field type BuddyPress asked for.
	 */
	public static function strip_generated( $attributes, $name ) {
		if ( 'password' !== $name || ! is_array( $attributes ) ) {
			return $attributes;
		}

		unset( $attributes['data-pw'] );

		/*
		 * BuddyPress sets autocomplete="off", which is the attribute that stops
		 * Chrome and Safari offering to generate and save a password. Removing
		 * the pre-filled one and then telling the browser to stay out would
		 * leave the member with no help at all. "new-password" is the value
		 * that invites the browser's own suggestion — which is the point:
		 * theirs is remembered, ours would not be.
		 */
		$attributes['autocomplete'] = 'new-password';

		return $attributes;
	}

	/**
	 * Dequeue the strength meter on the registration screen.
	 *
	 * 'user-profile' is the handle BuddyPress enqueues; it is what pulls in
	 * password-strength-meter and zxcvbn (a ~800KB dictionary, on a mobile
	 * signup form) and what renders the "confirm use of weak password"
	 * checkbox.
	 */
	public static function drop_meter() {
		if ( ! function_exists( 'bp_is_register_page' ) || ! bp_is_register_page() ) {
			return;
		}
		wp_dequeue_script( 'user-profile' );
		wp_dequeue_script( 'password-strength-meter' );
		wp_dequeue_script( 'zxcvbn-async' );
	}

	/**
	 * Length, and nothing else.
	 *
	 * BuddyPress has already checked that both boxes were filled and that they
	 * match; those errors are left alone. This only adds the length rule, and
	 * only when there is a password to measure — otherwise a blank form would
	 * report "too short" on top of "please enter it twice".
	 *
	 * @param \WP_Error $errors  Errors so far.
	 * @param string    $pass    The password.
	 * @param string    $confirm The confirmation.
	 */
	public static function validate( $errors, $pass, $confirm ) {
		unset( $confirm );

		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}
		$pass = (string) $pass;
		if ( '' === $pass ) {
			return $errors;   // "enter it twice" already covers this
		}
		if ( mb_strlen( $pass ) < self::MIN_LENGTH ) {
			$errors->add(
				'password_too_short',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'Please use at least %d characters.', 'cashaadi-ui' ),
					self::MIN_LENGTH
				)
			);
		}
		return $errors;
	}
}
