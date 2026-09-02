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
			'upload' => rest_url( 'csm/v1/profile/file' ),
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

		// In-app upload for File / Image xProfile fields (e.g. the ICAI document,
		// field 484). Multipart, so it is its own route rather than part of the
		// JSON group save.
		register_rest_route( 'csm/v1', '/profile/file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_upload' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/**
	 * Upload a file to a File/Image xProfile field, in-app.
	 *
	 * The ICAI document is a `file` field owned by bp-xprofile-custom-field-types,
	 * whose storage format (a relative upload path under bpxcftr-profile-uploads/)
	 * this screen has no business reproducing. So it does not: the file is handed
	 * to the SAME code the classic form uses. bpxcftr hooks
	 * xprofile_data_before_save and, when $_FILES['field_<id>'] is present, moves
	 * the upload into place and rewrites the field value itself — so triggering an
	 * ordinary field save with the file staged in $_FILES produces byte-identical
	 * storage to the old uploader, and the profile display, the classic form and
	 * CaVerify all keep reading it exactly as before. We only replace the UI.
	 *
	 * The heavy lifting (extension whitelist pdf/jpg/jpeg/png, size limit) lives in
	 * bpxcftr's handle_upload(); this method adds an outer guard that the target is
	 * genuinely a File/Image field, so it can never be pointed at a text field to
	 * smuggle markup in.
	 */
	public static function rest_upload( $request ) {
		$uid   = get_current_user_id();
		$field = (int) $request->get_param( 'field' );

		if ( ! $uid || ! $field || ! class_exists( '\BP_XProfile_Field' ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Bad request.' ), 400 );
		}

		$obj = \BP_XProfile_Field::get_instance( $field );
		if ( ! $obj || ! in_array( (string) $obj->type, array( 'file', 'image' ), true ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Not an uploadable field.' ), 400 );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'No file received.' ), 400 );
		}

		/*
		 * Stage the upload where bpxcftr expects it and trigger a save. The '-' is
		 * bpxcftr's own placeholder value; its hook overwrites it with the stored
		 * relative path once the file is moved into place.
		 */
		$_FILES[ 'field_' . $field ] = $files['file'];
		if ( ! isset( $_POST['action'] ) ) {
			$_POST['action'] = 'wp_handle_upload';
		}
		xprofile_set_field_data( $field, $uid, '-' );
		unset( $_FILES[ 'field_' . $field ] );

		// Confirm something actually landed, and hand back a URL to show.
		$doc = class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' )
			? \CAShaadi\Modules\CaVerify\CaVerify::doc( $uid )
			: null;

		if ( empty( $doc['url'] ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'The upload did not save. Please try a PDF, JPG or PNG under the size limit.' ), 200 );
		}

		return new \WP_REST_Response( array(
			'ok'   => true,
			'url'  => $doc['url'],
			'name' => basename( parse_url( $doc['url'], PHP_URL_PATH ) ),
		), 200 );
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

	/**
	 * A displayable URL for a File/Image field's current value, or ''.
	 *
	 * bpxcftr stores the value as a path relative to the uploads dir; older data
	 * may hold a full URL or an anchor. For the ICAI field CaVerify::doc() already
	 * resolves every one of those shapes (and falls back to scanning the upload
	 * folder), so reuse it there; otherwise build the URL from the relative path.
	 */
	private static function file_url( $field_id, $uid, $raw ) {
		if ( (int) $field_id === (int) \CAShaadi\Core\Config::FIELD_CA_DOC
			&& class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) ) {
			$doc = \CAShaadi\Modules\CaVerify\CaVerify::doc( (int) $uid );
			if ( ! empty( $doc['url'] ) ) {
				return (string) $doc['url'];
			}
		}
		$val = is_array( $raw ) ? reset( $raw ) : (string) $raw;
		$val = trim( (string) $val );
		if ( '' === $val || '-' === $val ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $val ) ) {
			return $val;
		}
		if ( preg_match( '/href=["\']([^"\']+)["\']/i', $val, $m ) ) {
			return $m[1];
		}
		$up = wp_get_upload_dir();
		return trailingslashit( $up['baseurl'] ) . ltrim( $val, '/' );
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

		/*
		 * Fields hidden from the editor (owner: "we can remove the additional docs
		 * field - it is irrelevant"). HIDDEN, never deleted: deleting an xProfile
		 * field deletes every member's answers for it, and this same code will run
		 * against production. See FIELD-INVENTORY.md.
		 */
		$hide = array( 'Other relevant documents' );

		$fields = array();
		foreach ( (array) $group->fields as $field ) {
			if ( in_array( (string) $field->name, $hide, true ) ) {
				continue;
			}
			/*
			 * RAW values, not xprofile_get_field_data().
			 *
			 * That function applies DISPLAY filters, which is right for showing a
			 * profile and catastrophic for editing one. Verified live on staging2:
			 *   Phone Number -> <a href="tel://08697222644" rel="nofollow">086972...</a>
			 *   Date of birth -> "27 years old"   (the Age filter, not a date)
			 * Putting those in the inputs means the next save writes the anchor
			 * markup back into the phone field and a non-date into the DOB field —
			 * silent data corruption on a screen whose whole job is editing.
			 *
			 * BP_XProfile_ProfileData::get_value_byid() returns what is actually
			 * stored.
			 */
			$raw = class_exists( '\BP_XProfile_ProfileData' )
				? \BP_XProfile_ProfileData::get_value_byid( $field->id, $uid )
				: xprofile_get_field_data( $field->id, $uid );

			// Stored multi-values are serialised; unserialise before sending.
			if ( is_string( $raw ) && is_serialized( $raw ) ) {
				$raw = maybe_unserialize( $raw );
			}

			$multi = in_array( (string) $field->type, array( 'checkbox', 'multiselectbox' ), true );

			$fields[] = array(
				'id'       => (int) $field->id,
				'label'    => (string) $field->name,
				'help'     => wp_strip_all_tags( (string) $field->description ),
				'type'     => (string) $field->type,
				'required' => ! empty( $field->is_required ),
				'options'  => self::options_for( $field ),
				'multi'    => $multi,
				/*
				 * `file` is a custom field type (bp-xprofile-custom-field-types), not
				 * core BuddyPress. Its upload widget and storage format belong to that
				 * plugin, so this screen does NOT try to reproduce them — the renderer
				 * fell through to a plain text box, which is why ICAI ID looked like
				 * one. Marked so the client offers the native editor for that group
				 * instead of a control that cannot work.
				 */
				'native'   => in_array( (string) $field->type, array( 'file', 'image' ), true ),
				// The classic-form URL is kept as a secondary escape hatch only; the
				// screen now uploads in place. See rest_upload().
				'nativeUrl' => function_exists( 'bp_members_get_user_url' )
					? trailingslashit( bp_members_get_user_url( $uid ) ) . 'profile/edit/group/' . $gid . '/'
					: '',
				'currentUrl' => in_array( (string) $field->type, array( 'file', 'image' ), true )
					? self::file_url( $field->id, $uid, $raw )
					: '',
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
