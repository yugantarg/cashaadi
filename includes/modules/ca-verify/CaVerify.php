<?php
/**
 * CA Document AI Verification — engine + admin review UI.
 *
 * Migrates WPCode #11815 ("CSM — CA Document AI Verification"):
 *   - resolves a member's uploaded ICAI / CA proof document (xProfile "File"
 *     field 484 = Config::FIELD_CA_DOC, stored by the bpxcftr plugin),
 *   - sends it to the OpenAI vision API and gets a structured verdict judged
 *     against the member's CLAIMED qualification (field 571 =
 *     Config::FIELD_QUALIFICATION),
 *   - shows an admin "CA Verify" Queue + Settings screen where a human Approves
 *     or Rejects (AI recommends, human decides).
 *
 * Per-user meta: csm_av_status (approved|rejected|pending), csm_av_result
 * (last AI JSON), csm_av_time. Site option: csm_av_options (Config::OPT_AV_OPTIONS)
 * holds model/threshold (+ the legacy OpenAI key). Adds no tables.
 *
 * Gated behind Config::ca_verify_enabled() — dormant until the coordinated
 * cutover (flip the flag + disable #11815/#12113). The OpenAI key is read ONLY
 * via Core\Secrets::openai_api_key() (wp-config constant first, else the
 * csm_av_options option) — never hard-coded, never read directly here.
 */

namespace CAShaadi\Modules\CaVerify;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaVerify {

	public static function register() {
		if ( ! Config::ca_verify_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_ajax_csm_av_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_csm_av_decide', array( __CLASS__, 'ajax_decide' ) );
	}

	/* ------------------------------------------------------------ options */

	public static function opts() {
		$o = get_option( Config::OPT_AV_OPTIONS, array() );
		if ( ! is_array( $o ) ) {
			$o = array();
		}
		return wp_parse_args( $o, array(
			'api_key'   => '',
			'model'     => 'gpt-4o',
			'threshold' => '0.80',
		) );
	}

	public static function opt( $k ) {
		$o = self::opts();
		return isset( $o[ $k ] ) ? $o[ $k ] : '';
	}

	/* --------------------------------------------- resolve member document */

	/** Return array( 'url'=>.., 'path'=>.., 'ext'=>.. ) for a member's field-484 file, or null. */
	public static function doc( $uid ) {
		$url = '';
		if ( function_exists( 'bp_get_profile_field_data' ) ) {
			$raw = (string) bp_get_profile_field_data( array( 'field' => Config::FIELD_CA_DOC, 'user_id' => $uid ) );
			if ( preg_match( '/href=["\']([^"\']+)["\']/i', $raw, $m ) ) {
				$url = $m[1];
			} elseif ( preg_match( '#https?://\S+\.(pdf|docx?|jpe?g|png|webp)#i', $raw, $m ) ) {
				$url = $m[0];
			} elseif ( '' !== trim( wp_strip_all_tags( $raw ) ) ) {
				$url = trim( wp_strip_all_tags( $raw ) );
			}
		}
		// Fallback: scan the plugin's upload folder for this user.
		if ( '' === $url ) {
			$up  = wp_get_upload_dir();
			$dir = trailingslashit( $up['basedir'] ) . 'bpxcftr-profile-uploads/' . (int) $uid . '/file';
			if ( is_dir( $dir ) ) {
				$files = glob( $dir . '/*' );
				if ( ! empty( $files ) ) {
					$f   = $files[0];
					$url = trailingslashit( $up['baseurl'] ) . 'bpxcftr-profile-uploads/' . (int) $uid . '/file/' . rawurlencode( basename( $f ) );
				}
			}
		}
		if ( '' === $url ) {
			return null;
		}

		$up   = wp_get_upload_dir();
		$path = '';
		if ( strpos( $url, $up['baseurl'] ) === 0 ) {
			$path = $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) );
			$path = urldecode( $path );
		}
		$ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return array( 'url' => $url, 'path' => $path, 'ext' => $ext );
	}

	public static function members_with_docs( $limit = 500 ) {
		global $wpdb;
		$out = array();
		if ( ! function_exists( 'bp_core_get_table_prefix' ) ) {
			return $out;
		}
		$bp  = bp_core_get_table_prefix();
		$tbl = $bp . 'bp_xprofile_data';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$tbl} WHERE field_id = %d AND value <> '' ORDER BY id DESC LIMIT %d",
			Config::FIELD_CA_DOC, (int) $limit
		) );
		return array_map( 'intval', (array) $rows );
	}

	/* --------------------------------------------------------- OpenAI call */

	/** Returns array( ok=>bool, verdict=>array|null, error=>string, raw=>string ). */
	public static function run_ai( $uid ) {
		$key = Secrets::openai_api_key();
		if ( '' === $key ) {
			return array( 'ok' => false, 'error' => 'No OpenAI API key set. Add it on the Settings tab.' );
		}
		$doc = self::doc( $uid );
		if ( ! $doc ) {
			return array( 'ok' => false, 'error' => 'No document found for this member.' );
		}
		$ext = $doc['ext'];
		$img_ext = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );

		if ( ! in_array( $ext, $img_ext, true ) && 'pdf' !== $ext ) {
			return array( 'ok' => false, 'error' => 'Unsupported format (.' . $ext . '). Word files need manual review.' );
		}
		if ( empty( $doc['path'] ) || ! file_exists( $doc['path'] ) ) {
			return array( 'ok' => false, 'error' => 'Document file is not readable on the server.' );
		}
		$bytes = file_get_contents( $doc['path'] );
		if ( false === $bytes || '' === $bytes ) {
			return array( 'ok' => false, 'error' => 'Could not read the document file.' );
		}
		$b64 = base64_encode( $bytes );

		// The member's CLAIMED qualification (xProfile "Qualification", field 571):
		// "CA" (fully qualified / CA Final), "CA Inter", or "Other". Verification is
		// judged AGAINST this claim, not against a single full-CA standard.
		$claim_raw = function_exists( 'bp_get_profile_field_data' )
			? trim( wp_strip_all_tags( (string) bp_get_profile_field_data( array( 'field' => Config::FIELD_QUALIFICATION, 'user_id' => $uid ) ) ) )
			: '';
		$cl = strtolower( $claim_raw );
		if ( 'ca inter' === $cl || false !== strpos( $cl, 'inter' ) ) {
			$claim_label = 'CA Inter (Intermediate — a CA student who has cleared/enrolled at the Intermediate level, NOT yet a full member)';
			$claim_rule  = 'Because the claim is CA Inter, ACCEPTABLE proof includes: an ICAI Intermediate (IPCC) exam result / marksheet showing a pass, an ICAI Intermediate registration / student / SRN card, or an ICAI Intermediate certificate. A membership (ACA/FCA) number is NOT expected and MUST NOT be required. Recommend "verify" if the document credibly shows ICAI Intermediate status for this person.';
		} elseif ( 'ca' === $cl || 'ca final' === $cl || false !== strpos( $cl, 'final' ) ) {
			$claim_label = 'CA (fully qualified Chartered Accountant / CA Final — a member of ICAI)';
			$claim_rule  = 'Because the claim is full CA, ACCEPTABLE proof is EITHER (a) evidence of ICAI MEMBERSHIP — an ICAI membership certificate, an ACA/FCA membership number, or a Certificate of Practice (COP); OR (b) evidence of having PASSED the CA Final examination — a CA Final pass/completion certificate, an ICAI Final examination Statement of Marks or marksheet, or an official ICAI Final examination result that shows a PASS. Any one of these is sufficient — recommend "verify". Only an Intermediate-only document (Intermediate exam result, IPCC/Intermediate student or registration card) or a document that is not an ICAI/CA credential at all is NOT sufficient for a full-CA claim — recommend "manual_review" or "reject" in that case.';
		} else {
			$claim_label = ( '' === $claim_raw ? 'Not specified' : $claim_raw );
			$claim_rule  = 'The claimed qualification is not a standard CA level; judge the document on general authenticity as an ICAI/CA-related credential.';
		}

		$instructions =
			'You verify identity documents for CA Shaadi, a matrimony site for Chartered Accountants (and CA students) in India. '
			. 'The attached file is a member-uploaded proof of their qualification. '
			. 'IMPORTANT: the member CLAIMS the level: "' . $claim_label . '". Verify the document AGAINST THIS CLAIM. ' . $claim_rule . ' '
			. 'Respond with ONLY a JSON object with these keys: '
			. 'claimed_level (string, echo the claim), is_ca_document (boolean), document_type (string), '
			. 'supports_claim (boolean — does the document support the CLAIMED level?), full_name (string, or empty), '
			. 'membership_number (string ICAI/ACA/FCA number if visible, else empty), issuing_body (string), '
			. 'authenticity_confidence (number 0 to 1), recommendation (one of "verify","reject","manual_review"), '
			. 'reason (short string). Recommend "verify" only if the document credibly supports the member\'s CLAIMED level.';

		if ( 'pdf' === $ext ) {
			$content = array(
				array( 'type' => 'text', 'text' => $instructions ),
				array( 'type' => 'file', 'file' => array(
					'filename'  => basename( $doc['path'] ),
					'file_data' => 'data:application/pdf;base64,' . $b64,
				) ),
			);
		} else {
			$mime = ( 'jpg' === $ext ) ? 'jpeg' : $ext;
			$content = array(
				array( 'type' => 'text', 'text' => $instructions ),
				array( 'type' => 'image_url', 'image_url' => array(
					'url' => 'data:image/' . $mime . ';base64,' . $b64,
				) ),
			);
		}

		$body = array(
			'model'           => (string) self::opt( 'model' ),
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array( 'role' => 'user', 'content' => $content ),
			),
		);

		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => 'Request failed: ' . $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== (int) $code ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return array( 'ok' => false, 'error' => 'OpenAI error: ' . $msg );
		}
		$text = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		$verdict = json_decode( $text, true );
		if ( ! is_array( $verdict ) ) {
			return array( 'ok' => false, 'error' => 'Could not parse AI response.', 'raw' => $text );
		}
		return array( 'ok' => true, 'verdict' => $verdict, 'raw' => $text );
	}

	/* --------------------------------------------------------------- admin */

	public static function admin_menu() {
		add_menu_page( 'CA Verify', 'CA Verify', 'manage_options', 'csm-ca-verify', array( __CLASS__, 'page_queue' ), 'dashicons-yes-alt', 58 );
		add_submenu_page( 'csm-ca-verify', 'CA Verify — Queue', 'Queue', 'manage_options', 'csm-ca-verify', array( __CLASS__, 'page_queue' ) );
		add_submenu_page( 'csm-ca-verify', 'CA Verify — Settings', 'Settings', 'manage_options', 'csm-ca-verify-settings', array( __CLASS__, 'page_settings' ) );
	}

	/** Enqueue the queue-page JS (with its AJAX url + nonce) only on that screen. */
	public static function admin_assets( $hook ) {
		if ( 'toplevel_page_csm-ca-verify' !== $hook ) {
			return;
		}
		// csmToast replaces the browser alert() on error.
		Assets::style( 'app-screens', 'assets/css/app-screens.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'ui-dialog', 'assets/js/ui-dialog.js' );
		Assets::script( 'ca-verify', 'assets/js/ca-verify.js', array( 'cashaadi-ui-dialog' ) );
		wp_add_inline_script(
			'cashaadi-ca-verify',
			'window.CSM_AV=' . wp_json_encode( array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'csm_av_ajax' ),
			) ) . ';',
			'before'
		);
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['csm_av_save'] ) && check_admin_referer( 'csm_av_settings' ) ) {
			$o = self::opts();
			$posted_key = isset( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '';
			// Keep the existing key if the field was left blank (so it is not shown/echoed).
			if ( '' !== $posted_key ) {
				$o['api_key'] = sanitize_text_field( $posted_key );
			}
			$o['model']     = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4o';
			$o['threshold'] = isset( $_POST['threshold'] ) ? sanitize_text_field( wp_unslash( $_POST['threshold'] ) ) : '0.80';
			update_option( Config::OPT_AV_OPTIONS, $o );
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}
		$o = self::opts();
		$has_key = ( '' !== trim( (string) $o['api_key'] ) );
		echo '<div class="wrap"><h1>CA Verify — Settings</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'csm_av_settings' );
		echo '<table class="form-table">';
		echo '<tr><th>OpenAI API key</th><td>';
		echo '<input type="password" name="api_key" style="width:420px" autocomplete="new-password" placeholder="' . ( $has_key ? 'Saved — leave blank to keep' : 'sk-...' ) . '">';
		echo '<p class="description">Stored on your server only. Leave blank to keep the current key. ' . ( $has_key ? '<strong>A key is currently saved.</strong>' : 'No key saved yet.' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th>Model</th><td><input type="text" name="model" value="' . esc_attr( $o['model'] ) . '" style="width:220px"><p class="description">A vision-capable model, e.g. gpt-4o or gpt-4o-mini.</p></td></tr>';
		echo '<tr><th>Auto-suggest threshold</th><td><input type="text" name="threshold" value="' . esc_attr( $o['threshold'] ) . '" style="width:80px"><p class="description">Confidence at/above which the AI recommendation is highlighted green (0–1).</p></td></tr>';
		echo '</table>';
		echo '<p><button class="button button-primary" name="csm_av_save" value="1">Save settings</button></p>';
		echo '</form></div>';
	}

	public static function status_label( $uid ) {
		$s = get_user_meta( $uid, 'csm_av_status', true );
		if ( 'approved' === $s ) {
			return '<span style="color:#137333;font-weight:600">Approved</span>';
		}
		if ( 'rejected' === $s ) {
			return '<span style="color:#b3261e;font-weight:600">Rejected</span>';
		}
		return '<span style="color:#8a6d00">Pending</span>';
	}

	public static function page_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = self::members_with_docs();
		$has_key = Secrets::has( 'openai' );

		echo '<div class="wrap"><h1>CA Verify — Queue</h1>';
		if ( ! $has_key ) {
			echo '<div class="notice notice-warning"><p>No OpenAI API key set yet. Add it under <a href="' . esc_url( admin_url( 'admin.php?page=csm-ca-verify-settings' ) ) . '">Settings</a> before running AI checks.</p></div>';
		}
		echo '<p>' . count( $ids ) . ' member(s) have uploaded a document to the ICAI field.</p>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Member</th><th>Document</th><th>Format</th><th>AI status</th><th>Last AI result</th><th>Actions</th>'
			. '</tr></thead><tbody>';

		foreach ( $ids as $uid ) {
			$doc  = self::doc( $uid );
			$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : get_the_author_meta( 'display_name', $uid );
			$prof = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : '';
			$ext  = $doc ? $doc['ext'] : '';
			$aichk = in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf' ), true );
			$result = get_user_meta( $uid, 'csm_av_result', true );

			echo '<tr id="csm-av-row-' . (int) $uid . '">';
			echo '<td><a href="' . esc_url( $prof ) . '" target="_blank">' . esc_html( $name ) . '</a><br><small>#' . (int) $uid . '</small></td>';
			echo '<td>' . ( $doc ? '<a href="' . esc_url( $doc['url'] ) . '" target="_blank">View file</a>' : '<em>none</em>' ) . '</td>';
			echo '<td>' . ( $ext ? '.' . esc_html( $ext ) : '?' ) . ( $aichk ? '' : ' <small>(manual)</small>' ) . '</td>';
			echo '<td class="csm-av-status">' . self::status_label( $uid ) . '</td>';
			echo '<td class="csm-av-result"><small>' . ( $result ? esc_html( mb_substr( (string) $result, 0, 160 ) ) : '—' ) . '</small></td>';
			echo '<td>';
			if ( $aichk ) {
				echo '<button class="button csm-av-check" data-uid="' . (int) $uid . '">Run AI check</button> ';
			} else {
				echo '<em>Word file — review manually</em><br>';
			}
			echo '<button class="button csm-av-decide" data-uid="' . (int) $uid . '" data-decision="approved">Approve</button> ';
			echo '<button class="button csm-av-decide" data-uid="' . (int) $uid . '" data-decision="rejected">Reject</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/* --------------------------------------------------------------- ajax */

	public static function ajax_check() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'csm_av_ajax', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'error' => 'Not allowed.' ) );
		}
		$uid = isset( $_POST['uid'] ) ? (int) $_POST['uid'] : 0;
		if ( ! $uid ) {
			wp_send_json_error( array( 'error' => 'Bad user.' ) );
		}
		$res = self::run_ai( $uid );
		if ( empty( $res['ok'] ) ) {
			update_user_meta( $uid, 'csm_av_result', 'AI error: ' . ( isset( $res['error'] ) ? $res['error'] : 'unknown' ) );
			wp_send_json_error( array( 'error' => isset( $res['error'] ) ? $res['error'] : 'AI check failed.' ) );
		}
		update_user_meta( $uid, 'csm_av_result', wp_json_encode( $res['verdict'] ) );
		update_user_meta( $uid, 'csm_av_time', time() );
		wp_send_json_success( array( 'verdict' => $res['verdict'] ) );
	}

	public static function ajax_decide() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'csm_av_ajax', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'error' => 'Not allowed.' ) );
		}
		$uid = isset( $_POST['uid'] ) ? (int) $_POST['uid'] : 0;
		$dec = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		if ( ! $uid || ! in_array( $dec, array( 'approved', 'rejected' ), true ) ) {
			wp_send_json_error( array( 'error' => 'Bad request.' ) );
		}
		update_user_meta( $uid, 'csm_av_status', $dec );
		update_user_meta( $uid, 'csm_av_decided_by', get_current_user_id() );
		update_user_meta( $uid, 'csm_av_decided_at', time() );
		wp_send_json_success( array( 'status' => $dec ) );
	}
}
