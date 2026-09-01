<?php
/**
 * Profile edit — one section at a time, as an app screen.
 *
 * Reached from the /profile/ hub: every "N left" row opens the section it names.
 * That is the whole mental model — change one section, come back — so this is
 * NOT a wizard. There is no chain through seven groups, no "Save & Next", no
 * step counter. Those belong to onboarding, and /welcome/ owns onboarding now.
 *
 * WHY THIS REPLACES THE DRAFT AJAX WIZARD
 * assets/js/profile-wizard.js tried to make the BuddyPress edit form a
 * no-reload chain. It shipped as an admitted "[STAGING DRAFT] ... NOT YET
 * LIVE-TESTED" and caused the bug reported three times — it advanced groups with
 * history.replaceState(), so Back left the flow entirely. v0.58.0 unhooked it.
 * This screen does the job the hub actually needs, with real history entries.
 *
 * SAVING IS PER FIELD, THROUGH BUDDYPRESS
 * Every write goes through xprofile_set_field_data(), the same call the native
 * form makes, so field visibility, the age-sync hook and everything else
 * downstream behave identically. Nothing is written by raw SQL, and only fields
 * that genuinely belong to the requested group can be written — a crafted field
 * id is refused rather than saved.
 *
 * PARTIAL SAVES ARE ALLOWED, deliberately. Snippet #11624 (and FieldLogic's
 * partial_save) exist because forcing every required field before saving
 * anything makes editing one detail impossible. Required fields are marked, and
 * the hub's completion counts already tell members what is outstanding.
 */

namespace CAShaadi\Modules\ProfileEdit;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileEditScreen {

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/** Our route, with the group in a query arg. */
	public static function url( $group_id = 0 ) {
		$u = home_url( '/profile/edit/' );
		return $group_id ? add_query_arg( 'g', (int) $group_id, $u ) : $u;
	}

	/* -------------------------------------------------------------- route */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'profile/edit' ) ) {
			return;
		}

		AppPage::assets();
		Assets::style( 'profile-edit-app', 'assets/css/profile-edit-app.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'profile-edit-app', 'assets/js/profile-edit-app.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-profile-edit-app', 'CSM_PEDIT', array(
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'get'    => rest_url( 'csm/v1/profile/group' ),
			'save'   => rest_url( 'csm/v1/profile/group' ),
			'hub'    => home_url( '/profile/' ),
			'group'  => isset( $_GET['g'] ) ? absint( $_GET['g'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
		) );

		AppPage::open( __( 'Edit profile', 'cashaadi-ui' ), 'profile' );
		echo '<div id="csm-pedit-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'profile' );
		exit;
	}

	/* --------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/profile/group', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => 'is_user_logged_in',
			),
		) );
	}

	/** All groups, so the screen can offer a section switcher without a reload. */
	private static function group_index() {
		$out = array();
		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return $out;
		}
		$groups = bp_xprofile_get_groups();
		$by_id  = array();
		foreach ( (array) $groups as $g ) {
			$by_id[ (int) $g->id ] = (string) $g->name;
		}
		$order = Config::GROUP_ORDER;
		foreach ( array_keys( $by_id ) as $gid ) {
			if ( ! in_array( $gid, $order, true ) ) {
				$order[] = $gid;
			}
		}
		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( isset( $by_id[ $gid ] ) ) {
				$out[] = array( 'id' => $gid, 'name' => $by_id[ $gid ] );
			}
		}
		return $out;
	}

	/** Options for an option-based field, via the field's own children. */
	private static function options_for( $field ) {
		$types = array( 'selectbox', 'radio', 'checkbox', 'multiselectbox' );
		if ( ! in_array( (string) $field->type, $types, true ) || ! class_exists( '\BP_XProfile_Field' ) ) {
			return array();
		}
		$obj = \BP_XProfile_Field::get_instance( (int) $field->id );
		if ( ! $obj ) {
			return array();
		}
		$out = array();
		foreach ( (array) $obj->get_children() as $child ) {
			// FieldLogic's filter drops the stray "Select" here too, so this screen
			// and the hub cannot disagree about the choices.
			if ( isset( $child->name ) && '' !== trim( (string) $child->name ) ) {
				$out[] = (string) $child->name;
			}
		}
		return $out;
	}

	public static function rest_get( $request ) {
		$uid = get_current_user_id();
		$gid = absint( $request->get_param( 'id' ) );

		$groups = function_exists( 'bp_xprofile_get_groups' )
			? bp_xprofile_get_groups( array( 'fetch_fields' => true ) )
			: array();

		$group = null;
		foreach ( (array) $groups as $g ) {
			if ( (int) $g->id === $gid ) {
				$group = $g;
				break;
			}
		}
		if ( ! $group ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That section does not exist.', 'cashaadi-ui' ) ), 200 );
		}

		$fields = array();
		foreach ( (array) $group->fields as $field ) {
			$raw = xprofile_get_field_data( $field->id, $uid );
			$multi = in_array( (string) $field->type, array( 'checkbox', 'multiselectbox' ), true );

			$fields[] = array(
				'id'       => (int) $field->id,
				'label'    => (string) $field->name,
				'help'     => wp_strip_all_tags( (string) $field->description ),
				'type'     => (string) $field->type,
				'required' => ! empty( $field->is_required ),
				'options'  => self::options_for( $field ),
				'multi'    => $multi,
				'value'    => $multi ? array_values( (array) $raw ) : ( is_array( $raw ) ? implode( ', ', $raw ) : (string) $raw ),
			);
		}

		return new \WP_REST_Response( array(
			'ok'     => true,
			'group'  => array( 'id' => $gid, 'name' => (string) $group->name ),
			'fields' => $fields,
			'index'  => self::group_index(),
		), 200 );
	}

	public static function rest_save( $request ) {
		$uid    = get_current_user_id();
		$gid    = absint( $request->get_param( 'id' ) );
		$values = $request->get_param( 'values' );

		if ( ! is_array( $values ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'Nothing to save.', 'cashaadi-ui' ) ), 200 );
		}

		// Only fields that actually belong to this group may be written.
		$allowed = array();
		$groups  = function_exists( 'bp_xprofile_get_groups' )
			? bp_xprofile_get_groups( array( 'fetch_fields' => true ) )
			: array();
		foreach ( (array) $groups as $g ) {
			if ( (int) $g->id !== $gid ) {
				continue;
			}
			foreach ( (array) $g->fields as $f ) {
				$allowed[ (int) $f->id ] = (string) $f->type;
			}
		}
		if ( ! $allowed ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That section does not exist.', 'cashaadi-ui' ) ), 200 );
		}

		$saved  = 0;
		$errors = array();

		foreach ( $values as $key => $val ) {
			$fid = absint( str_replace( 'field_', '', (string) $key ) );
			if ( ! isset( $allowed[ $fid ] ) ) {
				continue; // not part of this section — refused, not written
			}
			$type = $allowed[ $fid ];

			if ( is_array( $val ) ) {
				$clean = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $val ) ) ) );
			} elseif ( 'textarea' === $type ) {
				$clean = sanitize_textarea_field( (string) $val );
			} else {
				$clean = sanitize_text_field( (string) $val );
			}

			// datebox stores a datetime; the browser sends YYYY-MM-DD.
			if ( 'datebox' === $type && ! is_array( $clean ) && '' !== trim( $clean ) ) {
				if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $clean ), $m )
					&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					$clean = trim( $clean ) . ' 00:00:00';
				} else {
					$errors[ 'field_' . $fid ] = __( 'That date is not valid.', 'cashaadi-ui' );
					continue;
				}
			}

			/*
			 * An emptied field is a deletion, not a no-op: a member clearing their
			 * Company Name means to remove it. xprofile_delete_field_data is how
			 * BuddyPress represents "no answer", and leaves completion counts honest.
			 */
			$empty = is_array( $clean ) ? empty( $clean ) : ( '' === trim( $clean ) );
			if ( $empty ) {
				if ( function_exists( 'xprofile_delete_field_data' ) ) {
					xprofile_delete_field_data( $fid, $uid );
					$saved++;
				}
				continue;
			}

			if ( xprofile_set_field_data( $fid, $uid, $clean ) ) {
				$saved++;
			} else {
				$errors[ 'field_' . $fid ] = __( 'We could not save this one.', 'cashaadi-ui' );
			}
		}

		/*
		 * Let BuddyPress know a profile was updated, exactly as the native form
		 * does — FieldLogic's age-sync (#11611) hangs off this, and skipping it
		 * would leave Age stale after a DOB change.
		 */
		if ( $saved ) {
			do_action( 'xprofile_updated_profile', $uid, array( $gid ), array(), array(), array() );
		}

		return new \WP_REST_Response( array(
			'ok'      => empty( $errors ),
			'saved'   => $saved,
			'errors'  => $errors,
			'message' => empty( $errors ) ? '' : __( 'Some answers could not be saved.', 'cashaadi-ui' ),
		), 200 );
	}
}
