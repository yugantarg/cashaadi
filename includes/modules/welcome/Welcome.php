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

	/**
	 * Field ids pushed to the end of the flow, whatever order they sit in.
	 *
	 * Phone (277) is the highest-friction question in onboarding and was being
	 * asked third — before the member has invested anything and is therefore
	 * cheapest to lose. Asked last, the same question is answered by someone who
	 * has already done the work (owner decision, 2026-09-01).
	 */
	const ASK_LAST = array( 277 );

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	/* ------------------------------------------------------------- route */

	/**
	 * Is this a request for /welcome/?
	 *
	 * Matched off the request path rather than registered as a rewrite rule.
	 * A rewrite needs flush_rewrite_rules() to take effect, which means an
	 * activation hook — and this plugin is already active on staging2, so the
	 * rule would silently never fire until someone re-saved permalinks. Matching
	 * the path has no such trap and cannot be broken by another plugin's rules.
	 */
	private static function is_welcome_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		return 'welcome' === strtolower( $path );
	}

	public static function maybe_render() {
		if ( ! self::is_welcome_request() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/welcome/' ) ) );
			exit;
		}

		/*
		 * WordPress has already decided this is a 404 (there is no page with this
		 * slug). Say otherwise before anything renders, or the page returns 200-
		 * looking content under a 404 status — which search engines, uptime checks
		 * and, more to the point, analytics all treat as an error.
		 */
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		nocache_headers();

		self::enqueue();
		self::render();
		exit;
	}

	private static function enqueue() {
		\CAShaadi\Core\Assets::style( 'welcome', 'assets/css/welcome.css', array( 'cashaadi-tokens' ) );
		\CAShaadi\Core\Assets::style( 'tokens', 'assets/css/tokens.css' );

		/*
		 * Tracking loads BEFORE welcome.js and is its dependency, so window.csmTrack
		 * exists by the time the first step is drawn. Otherwise the very first step
		 * view — the top of the funnel, the number that matters most — is the one
		 * event that never gets sent.
		 *
		 * Claiming happens here, at render: reaching this page IS the activation
		 * and the start of onboarding. Each is claimed once per member, ever.
		 */
		if ( class_exists( '\CAShaadi\Modules\Tracking\Events' ) ) {
			\CAShaadi\Core\Assets::script( 'tracking', 'assets/js/tracking.js' );
			wp_localize_script(
				'cashaadi-tracking',
				'CSM_TRACK',
				\CAShaadi\Modules\Tracking\Events::config(
					get_current_user_id(),
					array(
						\CAShaadi\Modules\Tracking\Events::SIGNUP,
						\CAShaadi\Modules\Tracking\Events::ONBOARDING_START,
					)
				)
			);
			\CAShaadi\Core\Assets::script( 'cropper', 'assets/js/cropper.js' );
			\CAShaadi\Core\Assets::script( 'welcome', 'assets/js/welcome.js', array( 'cashaadi-tracking', 'cashaadi-cropper' ) );
		} else {
			\CAShaadi\Core\Assets::script( 'cropper', 'assets/js/cropper.js' );
			\CAShaadi\Core\Assets::script( 'welcome', 'assets/js/welcome.js', array( 'cashaadi-cropper' ) );
		}

		wp_localize_script(
			'cashaadi-welcome',
			'CSM_WELCOME',
			array(
				/*
				 * Cookie-authenticated REST returns 401 without this header —
				 * found the hard way while testing the endpoints (v0.47.0).
				 */
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'state'    => rest_url( 'csm/v1/welcome/state' ),
				'step'     => rest_url( 'csm/v1/welcome/step' ),
				'complete' => rest_url( 'csm/v1/welcome/complete' ),
				'avatar'   => rest_url( 'buddypress/v1/members/' . get_current_user_id() . '/avatar' ),
				'minPhoto' => class_exists( '\\CAShaadi\\Modules\\Media\\MediaQuality' )
					? \CAShaadi\Modules\Media\MediaQuality::min_dimensions()
					: array( 'w' => 1080, 'h' => 1350 ),
				'fallback' => function_exists( 'bp_members_get_user_url' )
					? trailingslashit( bp_members_get_user_url( get_current_user_id() ) ) . 'profile/change-avatar/'
					: '',
				// Extra photos go to the gallery (the main one is the avatar). Same
				// admin-ajax uploader the profile gallery uses.
				'photoAjax'  => admin_url( 'admin-ajax.php' ),
				'photoNonce' => wp_create_nonce( 'csm_ph' ),
				'photoMax'   => class_exists( '\CAShaadi\Modules\Photos\Gallery' )
					? \CAShaadi\Modules\Photos\Gallery::max() : 6,
				// Portrait crop that clears the avatar floor (1080x1350 = 4:5).
				'cropAspect' => 0.8,
				'cropOutW'   => 1080,
			)
		);
	}

	/**
	 * Our own document, not a theme template.
	 *
	 * The whole point of this screen is to stop fighting BuddyX and BuddyPress
	 * for control of the markup — every layout bug this rebuild has hit came from
	 * that fight. So the page is rendered directly.
	 *
	 * wp_head()/wp_footer() are still called deliberately: analytics and the
	 * advertising tags live there, and onboarding is exactly where the conversion
	 * events need to fire. Dropping them would make the funnel unmeasurable.
	 */
	private static function render() {
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php esc_html_e( 'Set up your profile', 'cashaadi-ui' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="csm-welcome-page">
	<div class="csm-w" id="csm-welcome">
		<header class="csm-w-top">
			<?php $csm_w_logo = \CAShaadi\Core\AppPage::logo_src(); ?>
			<?php if ( $csm_w_logo ) : ?>
				<img class="csm-w-logo" src="<?php echo esc_url( $csm_w_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php else : ?>
				<span class="csm-w-brand"><?php bloginfo( 'name' ); ?></span>
			<?php endif; ?>
			<div class="csm-w-bar" aria-hidden="true"><span class="csm-w-bar-fill" id="csm-w-progress"></span></div>
		</header>

		<main class="csm-w-main" id="csm-w-main">
			<p class="csm-w-loading"><?php esc_html_e( 'Loading…', 'cashaadi-ui' ); ?></p>
		</main>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
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
				'key' => array( 'required' => true ),
				/*
				 * 'value' is NOT required. Single-value steps (the blur choice)
				 * send it; grouped steps send 'fields' instead. Declaring it
				 * required was left over from the one-field-per-step design and
				 * made WordPress reject every group save with 400 "Missing
				 * parameter(s): value" before the callback ever ran — the wizard
				 * could not save anything from v0.87.0 until this was found by
				 * hand-testing a real signup.
				 */
				'value' => array( 'required' => false ),
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

	/**
	 * A multi-value field's current answers, as a flat array.
	 *
	 * BuddyPress serialises checkbox answers, so the stored value is a string
	 * until unserialised. Returns [] rather than [''] for an unanswered field, so
	 * "is this empty" stays a simple test.
	 */
	private static function values_of( $fid, $uid ) {
		if ( ! class_exists( '\BP_XProfile_ProfileData' ) ) {
			return array();
		}
		$raw = \BP_XProfile_ProfileData::get_value_byid( (int) $fid, (int) $uid );
		$val = maybe_unserialize( $raw );
		if ( ! is_array( $val ) ) {
			$val = ( '' === trim( (string) $val ) ) ? array() : array( (string) $val );
		}
		return array_values( array_filter( array_map( 'strval', $val ), function ( $v ) {
			return '' !== trim( $v );
		} ) );
	}

	/** A field's current value as a plain string (arrays flattened for emptiness). */

	private static function value_of( $field_id, $uid ) {
		/*
		 * RAW value, never xprofile_get_field_data(): that applies display filters,
		 * and two of them broke the wizard —
		 *   telephone -> <a href="tel://...">NUMBER</a>  (shown literally in the
		 *                phone step's input),
		 *   datebox   -> a formatted date string that a native <input type="date">
		 *                cannot parse, so DOB looked blank even when it was set.
		 * get_value_byid() returns exactly what is stored, which is what an editable
		 * control needs.
		 */
		if ( class_exists( '\BP_XProfile_ProfileData' ) ) {
			$v = \BP_XProfile_ProfileData::get_value_byid( (int) $field_id, (int) $uid );
		} elseif ( function_exists( 'xprofile_get_field_data' ) ) {
			$v = xprofile_get_field_data( $field_id, $uid );
		} else {
			return '';
		}
		if ( function_exists( 'maybe_unserialize' ) ) {
			$v = maybe_unserialize( $v );
		}
		if ( is_array( $v ) ) {
			return implode( ', ', array_filter( array_map( 'strval', $v ) ) );
		}
		$v = trim( wp_strip_all_tags( (string) $v ) );
		// datebox stores "YYYY-MM-DD 00:00:00"; the date input wants "YYYY-MM-DD".
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}/', $v, $m ) ) {
			$v = $m[1];
		}
		return $v;
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

		/*
		 * One step per profile SECTION, not per field (owner: "22 steps seems too
		 * long ... subsume under fewer groups"). Each group step carries all its
		 * askable fields; Continue requires the mandatory ones and lets the rest be
		 * left blank. A group is "done" once its required fields are answered.
		 */
		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( empty( $by_id[ $gid ]->fields ) ) {
				continue;
			}

			$fields = array();
			foreach ( $by_id[ $gid ]->fields as $field ) {
				$fid = (int) $field->id;

				if ( $fid === Config::FIELD_AGE ) {
					continue; // auto-derived from DOB
				}
				if ( in_array( $fid, Config::SIGNUP_FIELDS, true ) ) {
					continue; // collected at sign-up
				}
				if ( in_array( (string) $field->name, \CAShaadi\Core\Profile::UNCOUNTED_FIELDS, true ) ) {
					continue; // hidden everywhere
				}
				if ( in_array( (string) $field->type, array( 'file', 'image' ), true ) ) {
					continue; // uploaded elsewhere
				}

				/*
				 * Hobbies and Interests is a checkbox field with twelve options,
				 * and the wizard was rendering every option list as an exclusive
				 * choice — so a member could save exactly one hobby out of twelve.
				 * The profile editor already sent this flag and handled it
				 * correctly; the wizard simply never did.
				 */
				$multi = in_array( (string) $field->type, array( 'checkbox', 'multiselectbox' ), true );

				$fields[] = array(
					'id'       => $fid,
					'key'      => 'field_' . $fid,
					'type'     => (string) $field->type,
					'label'    => (string) $field->name,
					'help'     => (string) $field->description,
					'value'    => $multi ? self::values_of( $fid, $uid ) : self::value_of( $fid, $uid ),
					'options'  => self::options_for( $field ),
					'multi'    => $multi,
					'required' => ! empty( $field->is_required ),
				);
			}

			if ( empty( $fields ) ) {
				continue;
			}

			$done = true;
			foreach ( $fields as $f ) {
				$empty = is_array( $f['value'] ) ? empty( $f['value'] ) : ( '' === $f['value'] );
				if ( $f['required'] && $empty ) {
					$done = false;
					break;
				}
			}

			$steps[] = array(
				'key'    => 'group_' . $gid,
				'type'   => 'group',
				'label'  => (string) $by_id[ $gid ]->name,
				'help'   => '',
				'fields' => $fields,
				'done'   => $done,
			);
		}

		return array_values( $steps );
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

		// A group step carries several fields; save each. Required fields must be
		// answered; optional ones may be left blank (that is the "skip").
		$group  = $allowed[ $key ];
		$posted = $request->get_param( 'fields' );
		$posted = is_array( $posted ) ? $posted : array();

		if ( empty( $group['fields'] ) || 'group' !== $group['type'] ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That is not part of onboarding.', 'cashaadi-ui' ) ), 200 );
		}

		$saved_ids = array();
		foreach ( $group['fields'] as $f ) {
			$fid = (int) $f['id'];
			$raw = array_key_exists( (string) $fid, $posted ) ? $posted[ (string) $fid ]
				: ( array_key_exists( 'field_' . $fid, $posted ) ? $posted[ 'field_' . $fid ] : '' );

			if ( is_array( $raw ) ) {
				$value = array_values( array_filter(
					array_map( 'sanitize_text_field', array_map( 'strval', $raw ) ),
					function ( $v ) { return '' !== trim( (string) $v ); }
				) );
			} else {
				$value = ( 'textarea' === $f['type'] )
					? sanitize_textarea_field( (string) $raw )
					: sanitize_text_field( (string) $raw );
			}

			$is_empty = ( is_array( $value ) && ! $value ) || ( ! is_array( $value ) && '' === trim( (string) $value ) );
			if ( $is_empty ) {
				if ( ! empty( $f['required'] ) ) {
					return new \WP_REST_Response( array(
						'ok'      => false,
						'message' => sprintf( __( '%s is required.', 'cashaadi-ui' ), $f['label'] ),
						'field'   => $fid,
					), 200 );
				}
				continue; // optional + blank: nothing to save
			}

			if ( 'datebox' === $f['type'] && ! is_array( $value ) ) {
				if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $m ) ) {
					if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
						return new \WP_REST_Response( array( 'ok' => false, 'message' => __( 'That date is not valid.', 'cashaadi-ui' ), 'field' => $fid ), 200 );
					}
					$value = trim( $value ) . ' 00:00:00';
				} else {
					return new \WP_REST_Response( array( 'ok' => false, 'message' => sprintf( __( 'Please pick a valid date for %s.', 'cashaadi-ui' ), $f['label'] ), 'field' => $fid ), 200 );
				}
			}

			if ( xprofile_set_field_data( $fid, $uid, $value ) ) {
				$saved_ids[] = $fid;
			}
		}

		/*
		 * Fire the canonical profile-update hook once for the whole group, as the
		 * classic form and editor do — xprofile_set_field_data() does not fire it
		 * in BP 14, and the derived Age depends on it (sync_age reads only $uid).
		 */
		if ( $saved_ids ) {
			do_action( 'xprofile_updated_profile', $uid, $saved_ids, array(), array(), array() );
		}

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
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
		 * Claim the completion event once per member, ever.
		 *
		 * Claimed here rather than on the page, because completion is the one
		 * milestone the server can genuinely confirm — every required answer is
		 * present, checked immediately above. A refresh, a back button or a second
		 * device cannot produce a second copy.
		 */
		$first_time = ! get_user_meta( $uid, self::DONE_META, true );
		if ( $first_time ) {
			update_user_meta( $uid, self::DONE_META, time() );
		}

		$events = array();
		if ( class_exists( '\CAShaadi\Modules\Tracking\Events' )
			&& \CAShaadi\Modules\Tracking\Events::claim( $uid, \CAShaadi\Modules\Tracking\Events::ONBOARDING_DONE ) ) {
			$events[] = \CAShaadi\Modules\Tracking\Events::ONBOARDING_DONE;
		}

		return new \WP_REST_Response( array(
			'ok'         => true,
			'fireEvents' => $first_time,
			'events'     => $events,
			'redirect'   => home_url( '/discover/' ),
		), 200 );
	}
}
