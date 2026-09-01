<?php
/**
 * /welcome/ — onboarding as one route.
 *
 * See docs/WELCOME-SPEC.md. This file is the data layer: three REST endpoints
 * the client-side step machine talks to. No page loads, no BuddyPress templates.
 *
 * WHY THE STEP LIST IS DERIVED, NOT WRITTEN DOWN
 * The steps are computed from the xProfile fields themselves — every field whose
 * own `is_required` flag is set, in Config::GROUP_ORDER order, with the photo
 * bolted on the front. Hardcoding a list here would drift the first time someone
 * edits a field in wp-admin, and then onboarding would demand fields that are no
 * longer required (or skip ones that are) with nothing to tell us.
 *
 * WHY PROGRESS IS NOT STORED
 * "Which step am I on" is derived from the data: the first step whose answer is
 * still empty. Nothing records a position, so nothing can desynchronise from
 * reality — a refresh, a second device, or an answer saved through profile-edit
 * all land the member in the right place. The cost is one xProfile read per
 * step, which is cheap and always correct.
 *
 * SECURITY
 * Every endpoint acts on the CURRENT user and takes no user id, so there is no
 * object-reference to tamper with. Writes go through xprofile_set_field_data(),
 * never raw SQL, and only to fields that appear in the derived step list — a
 * crafted field_id for something outside onboarding is rejected rather than
 * written.
 */

namespace CAShaadi\Modules\Welcome;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Welcome {

	/** Marks the member as having finished onboarding (for conversion dedupe). */
	const DONE_META = 'csm_welcome_done';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		$auth = array( __CLASS__, 'can' );

		register_rest_route( 'csm/v1', '/welcome/state', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_state' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( 'csm/v1', '/welcome/step', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_step' ),
			'permission_callback' => $auth,
			'args'                => array(
				'key'   => array( 'required' => true ),
				'value' => array( 'required' => true ),
			),
		) );

		register_rest_route( 'csm/v1', '/welcome/complete', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_complete' ),
			'permission_callback' => $auth,
		) );
	}

	/**
	 * Logged in, and that is the whole check — every endpoint operates on the
	 * current user only and never accepts a user id.
	 */
	public static function can() {
		return is_user_logged_in();
	}

	/* -------------------------------------------------------------- steps */

	/** Does this member have at least one photo? */
	private static function has_photo( $uid ) {
		if ( class_exists( '\CAShaadi\Modules\Onboarding\PhotoOptions' ) ) {
			return \CAShaadi\Modules\Onboarding\PhotoOptions::has_photo( $uid );
		}
		$ids = get_user_meta( $uid, 'csm_photos', true );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return true;
		}
		return function_exists( 'bp_get_user_has_avatar' ) && bp_get_user_has_avatar( $uid );
	}

	/** A field's current value as a plain string (arrays flattened for emptiness). */
	private static function value_of( $field_id, $uid ) {
		if ( ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}
		$v = xprofile_get_field_data( $field_id, $uid );
		if ( is_array( $v ) ) {
			return implode( ', ', array_filter( array_map( 'strval', $v ) ) );
		}
		return trim( (string) $v );
	}

	/**
	 * The ordered step list for this member, each marked done or not.
	 *
	 * @return array<int,array>
	 */
	public static function steps( $uid ) {
		$steps = array(
			array(
				'key'      => 'photo',
				'type'     => 'photo',
				'label'    => __( 'Add a photo', 'cashaadi-ui' ),
				'help'     => __( 'Profiles with a photo get far more responses. You need at least one to continue.', 'cashaadi-ui' ),
				'value'    => '',
				'options'  => array(),
				'done'     => self::has_photo( $uid ),
			),
		);

		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return $steps;
		}

		$groups = bp_xprofile_get_groups( array( 'fetch_fields' => true ) );
		if ( empty( $groups ) ) {
			return $steps;
		}

		$by_id = array();
		foreach ( $groups as $g ) {
			$by_id[ (int) $g->id ] = $g;
		}

		$order = Config::GROUP_ORDER;
		foreach ( array_keys( $by_id ) as $gid ) {
			if ( ! in_array( $gid, $order, true ) ) {
				$order[] = $gid;
			}
		}

		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( empty( $by_id[ $gid ]->fields ) ) {
				continue;
			}
			foreach ( $by_id[ $gid ]->fields as $field ) {
				// Required-ness is read from the field, never from a list here.
				if ( empty( $field->is_required ) ) {
					continue;
				}
				$value = self::value_of( $field->id, $uid );
				$steps[] = array(
					'key'     => 'field_' . (int) $field->id,
					'type'    => (string) $field->type,
					'label'   => (string) $field->name,
					'help'    => (string) $field->description,
					'value'   => $value,
					'options' => self::options_for( $field ),
					'done'    => ( '' !== $value ),
				);
			}
		}

		return $steps;
	}

	/**
	 * Choices for option-based fields.
	 *
	 * Runs through the field's own get_children(), so FieldLogic's filter that
	 * drops the stray "Select" option applies here too — onboarding and
	 * profile-edit cannot disagree about what the choices are.
	 */
	private static function options_for( $field ) {
		$types = array( 'selectbox', 'radio', 'checkbox', 'multiselectbox' );
		if ( ! in_array( (string) $field->type, $types, true ) ) {
			return array();
		}
		if ( ! class_exists( '\BP_XProfile_Field' ) ) {
			return array();
		}
		$obj = \BP_XProfile_Field::get_instance( (int) $field->id );
		if ( ! $obj ) {
			return array();
		}
		$out = array();
		foreach ( (array) $obj->get_children() as $child ) {
			if ( isset( $child->name ) && '' !== trim( (string) $child->name ) ) {
				$out[] = (string) $child->name;
			}
		}
		return $out;
	}

	public static function rest_state( $request ) {
		unset( $request );
		$uid   = get_current_user_id();
		$steps = self::steps( $uid );

		$current = count( $steps ); // all done
		foreach ( $steps as $i => $s ) {
			if ( empty( $s['done'] ) ) {
				$current = $i;
				break;
			}
		}

		return new \WP_REST_Response( array(
			'ok'        => true,
			'steps'     => $steps,
			'current'   => $current,
			'total'     => count( $steps ),
			'complete'  => ( $current >= count( $steps ) ),
			'blurred'   => '1' === (string) get_user_meta( $uid, 'csm_photo_private', true ),
			'avatarUrl' => function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'full', 'html' => false ) )
				: '',
		), 200 );
	}

	/* --------------------------------------------------------------- save */

	public static function rest_step( $request ) {
		$uid = get_current_user_id();
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );

		// The blur toggle rides along with the photo step.
		if ( 'blur' === $key ) {
			if ( $request->get_param( 'value' ) ) {
				update_user_meta( $uid, 'csm_photo_private', '1' );
			} else {
				delete_user_meta( $uid, 'csm_photo_private' );
			}
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Only fields that are genuinely part of onboarding may be written. A
		// crafted key for some other field is refused, not saved.
		$allowed = array();
		foreach ( self::steps( $uid ) as $s ) {
			$allowed[ $s['key'] ] = $s;
		}
		if ( ! isset( $allowed[ $key ] ) || 'photo' === $key ) {
			return new \WP_REST_Response(
				array( 'ok' => false, 'message' => __( 'That is not part of onboarding.', 'cashaadi-ui' ) ),
				200
			);
		}

		$field_id = (int) substr( $key, strlen( 'field_' ) );
		$raw      = $request->get_param( 'value' );

		if ( is_array( $raw ) ) {
			$value = array_map( 'sanitize_text_field', array_map( 'strval', $raw ) );
		} else {
			// textarea keeps newlines; everything else is a single line.
			$value = ( 'textarea' === $allowed[ $key ]['type'] )
				? sanitize_textarea_field( (string) $raw )
				: sanitize_text_field( (string) $raw );
		}

		if ( ( is_array( $value ) && ! $value ) || ( ! is_array( $value ) && '' === trim( $value ) ) ) {
			return new \WP_REST_Response(
				array( 'ok' => false, 'message' => __( 'This one is required.', 'cashaadi-ui' ) ),
				200
			);
		}

		$saved = xprofile_set_field_data( $field_id, $uid, $value );

		return new \WP_REST_Response( array(
			'ok'      => (bool) $saved,
			'message' => $saved ? '' : __( 'We could not save that. Please try again.', 'cashaadi-ui' ),
		), 200 );
	}

	/* ----------------------------------------------------------- complete */

	public static function rest_complete( $request ) {
		unset( $request );
		$uid = get_current_user_id();

		// Trust the data, not the client: re-derive rather than believe a claim
		// that onboarding finished.
		foreach ( self::steps( $uid ) as $s ) {
			if ( empty( $s['done'] ) ) {
				return new \WP_REST_Response( array(
					'ok'      => false,
					'message' => __( 'Some details are still missing.', 'cashaadi-ui' ),
					'stepKey' => $s['key'],
				), 200 );
			}
		}

		/*
		 * Fire the conversion once per member, ever.
		 *
		 * The flag is set server-side and checked here, so a refresh, a back
		 * button or a second device cannot re-fire it — the failure mode that
		 * silently inflates the number Google Ads optimises against.
		 */
		$first_time = ! get_user_meta( $uid, self::DONE_META, true );
		if ( $first_time ) {
			update_user_meta( $uid, self::DONE_META, time() );
		}

		return new \WP_REST_Response( array(
			'ok'          => true,
			'fireEvents'  => $first_time,
			'redirect'    => home_url( '/discover/' ),
		), 200 );
	}
}
