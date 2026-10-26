<?php
/**
 * Astro details (owner, 2026-10-26): a wizard step before verification with
 * date of birth (shown, not editable: it was given at sign-up), time of birth
 * and place of birth, and one Show / Hide switch for all three.
 *
 * The group and its two fields are made by `wp csm astro setup`, which also
 * adds Native Place to Family Details; their ids are kept in options. The
 * switch is user meta, not field visibility: date of birth keeps its own
 * rules everywhere else (withheld in favour of Age), and only the Astro
 * section of the profile card shows it, when the member chose to.
 * Unset counts as hidden: members who never saw the step share nothing new.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Astro {

	const GROUP  = 'csm_astro_group';  // option: xprofile group id
	const FIELDS = 'csm_astro_fields'; // option: array( 'time' => id, 'place' => id )
	const SHOW   = 'csm_astro_show';   // user meta: '1' shown, '0' hidden

	const GROUP_NAME = 'Astro Details';
	const NATIVE     = 'Native Place';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm astro', __NAMESPACE__ . '\\AstroCli' );
		}
	}

	public static function group_id() {
		return (int) get_option( self::GROUP, 0 );
	}

	public static function field_ids() {
		return array_map( 'intval', array_filter( (array) get_option( self::FIELDS, array() ) ) );
	}

	/** Group ids in screen order: the Astro group just before Verification (10). */
	public static function group_order() {
		$order = Config::GROUP_ORDER;
		$gid   = self::group_id();
		if ( $gid && ! in_array( $gid, $order, true ) ) {
			$at = array_search( 10, $order, true );
			array_splice( $order, false === $at ? count( $order ) : $at, 0, array( $gid ) );
		}
		return $order;
	}

	public static function shown( $uid ) {
		return '1' === (string) get_user_meta( (int) $uid, self::SHOW, true );
	}

	/** The switch as the step should show it: on until the member turns it off. */
	public static function switch_state( $uid ) {
		return '0' !== (string) get_user_meta( (int) $uid, self::SHOW, true );
	}

	public static function set_shown( $uid, $show ) {
		update_user_meta( (int) $uid, self::SHOW, $show ? '1' : '0' );
	}

	/** Date of birth as "12 Mar 1996", '' when unset. */
	public static function dob_label( $uid ) {
		if ( ! class_exists( '\BP_XProfile_ProfileData' ) ) {
			return '';
		}
		$raw = (string) \BP_XProfile_ProfileData::get_value_byid( (int) Config::FIELD_DOB, (int) $uid );
		$ts  = $raw ? strtotime( substr( $raw, 0, 10 ) . ' 00:00:00 UTC' ) : false;
		return $ts ? gmdate( 'j M Y', $ts ) : '';
	}

	/**
	 * What the wizard and profile editor need on the Astro step. The wizard
	 * starts the switch on, since Continue saves it; the editor saves nothing
	 * until the switch is flipped, so it shows the real state (v1.89.73: it
	 * showed on while the profile kept the section hidden).
	 */
	public static function step_extra( $uid, $wizard = false ) {
		return array(
			'dob'  => self::dob_label( $uid ),
			'show' => $wizard ? self::switch_state( $uid ) : self::shown( $uid ),
			'save' => rest_url( 'csm/v1/astro/show' ),
		);
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/astro/show', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_show' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_show( $request ) {
		self::set_shown( get_current_user_id(), (bool) $request->get_param( 'show' ) );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
