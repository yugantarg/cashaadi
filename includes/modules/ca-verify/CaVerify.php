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
		// A fresh upload restarts the review -- see on_doc_changed().
		add_action( 'xprofile_data_after_save', array( __CLASS__, 'on_doc_changed' ) );

		/*
		 * What the ICAI upload accepts. bpxcftr's 'file' type allowed only
		 * doc/docx/pdf, so a photograph of a membership card -- the thing most
		 * members actually have to hand -- was refused at upload. Owner,
		 * 2026-09-19: "ICAI ID in PDF or jpg format."
		 *
		 * Word files are dropped: run_ai() cannot read them, so every one
		 * became manual work and an indefinite "in review" for the member.
		 * Everything left here is something the model can actually check.
		 */
		add_filter( 'bpxcftr_allowed_extensions', array( __CLASS__, 'allowed_extensions' ) );
		if ( ! Config::ca_verify_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_ajax_csm_av_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_csm_av_decide', array( __CLASS__, 'ajax_decide' ) );
	}

	/** PDF or a photo — the formats run_ai() can read. */
	public static function allowed_extensions( $ext ) {
		if ( ! is_array( $ext ) ) {
			$ext = array();
		}
		$ext['file'] = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' );
		return $ext;
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
		// '-' is bpxcftr's placeholder, left behind when an upload is refused.
		// It is not a document (2026-09-24: nine members read "in review" on it).
		if ( '-' === $url ) {
			$url = '';
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
			"SELECT DISTINCT user_id FROM {$tbl} WHERE field_id = %d AND value <> '' AND value <> '-' ORDER BY id DESC LIMIT %d",
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
			. 'reason (short string), '
			/*
			 * A fixed code as well as prose. The prose is for the reviewer; the
			 * code selects which of the member-facing sentences in reasons() the
			 * person is shown and emailed. Without it a rejection could only
			 * ever say "we could not verify your document", which tells nobody
			 * what to do differently.
			 */
			. 'reason_code (one of "unclear","name","not_icai","incomplete","wrong_level","expired","other" — '
			. 'the single best category for why it is not verifiable; "other" only if none fits). '
			. 'Recommend "verify" only if the document credibly supports the member\'s CLAIMED level.';

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

	/**
	 * Why a document was rejected, in the member's language.
	 *
	 * A fixed list rather than free text. Three reasons: a reviewer clicking
	 * Reject at speed will not write a sentence, so free text would usually be
	 * empty; the wording reaching a member should be considered once rather than
	 * improvised per case; and a stored key can be re-worded later without
	 * rewriting what past members were told.
	 *
	 * Owner: "i want a short reviewer reason e.g. photo not clear, name not
	 * matched, this is employee ID not ICAI ID etc."
	 */
	public static function reasons() {
		return array(
			'unclear'     => __( 'The document was not clear enough to read. Please upload a sharper photo or the original PDF.', 'cashaadi-ui' ),
			'name'        => __( 'The name on the document does not match your profile name.', 'cashaadi-ui' ),
			'not_icai'    => __( 'That is not an ICAI document. Please upload your ICAI certificate, marksheet or membership card.', 'cashaadi-ui' ),
			'incomplete'  => __( 'Part of the document was cut off. Please upload the full page.', 'cashaadi-ui' ),
			'wrong_level' => __( 'The document does not support the qualification on your profile. Please upload proof of the level you have claimed.', 'cashaadi-ui' ),
			'expired'     => __( 'The document could not be verified as current. Please upload a recent ICAI document.', 'cashaadi-ui' ),
			'format'      => __( 'We could not open that file type. Please upload your ICAI document as a PDF, JPG or PNG.', 'cashaadi-ui' ),
			'other'       => __( 'We could not verify the document you uploaded. Please upload a clear ICAI certificate, marksheet or membership card showing your name.', 'cashaadi-ui' ),
		);
	}

	/** Short labels for the admin queue's dropdown. */
	public static function reason_labels() {
		return array(
			'unclear'     => 'Not clear / unreadable',
			'name'        => 'Name does not match',
			'not_icai'    => 'Not an ICAI document',
			'incomplete'  => 'Cut off / incomplete',
			'wrong_level' => 'Wrong qualification level',
			'expired'     => 'Not current',
			'format'      => 'File type we cannot open',
			'other'       => 'Other',
		);
	}

	/**
	 * What the MEMBER should be told, as a machine-readable state.
	 *
	 * status_label() below is the admin queue's HTML and is not reusable here.
	 * More to the point, nothing told the member anything at all: a rejected
	 * document left the profile showing "Verify now" with no explanation, so
	 * somebody who uploaded the wrong file had no way to learn that — the owner
	 * uploaded a random PDF and the screen still read "completed" under
	 * Verification with a Verify now button beside it, which is two different
	 * half-truths and no answer.
	 *
	 * @return string none | pending | approved | rejected
	 */
	public static function member_state( $uid ) {
		$uid = (int) $uid;
		$s   = (string) get_user_meta( $uid, 'csm_av_status', true );

		if ( 'approved' === $s ) {
			return 'approved';
		}
		if ( 'rejected' === $s ) {
			return 'rejected';
		}
		if ( 'review' === $s ) {
			return 'review';   // the model could not decide; a person is looking
		}
		// doc() already answers "has anything been uploaded"; a second copy of
		// that question would be one more thing to keep in step.
		return self::doc( $uid ) ? 'pending' : 'none';
	}

	/**
	 * One sentence for the member. Never the AI's reasoning verbatim — that is
	 * written for a reviewer, mentions confidence scores, and would be a poor
	 * and occasionally alarming thing to show the person it judged.
	 */
	public static function member_note( $uid ) {
		switch ( self::member_state( $uid ) ) {
			case 'approved':
				return __( 'Your ICAI document was accepted. Your profile shows the Verified CA badge.', 'cashaadi-ui' );
			case 'rejected':
				$reasons = self::reasons();
				$key     = (string) get_user_meta( (int) $uid, 'csm_av_reason', true );
				return isset( $reasons[ $key ] ) ? $reasons[ $key ] : $reasons['other'];
			case 'review':
				return __( 'Your document needs a closer look from our team. We will email you once it is decided — you do not need to do anything.', 'cashaadi-ui' );
			case 'pending':
				return __( 'Your document is being checked. This usually takes a day.', 'cashaadi-ui' );
			default:
				return __( 'Upload your ICAI certificate to get the Verified CA badge.', 'cashaadi-ui' );
		}
	}

	/**
	 * Record a rejection and tell the member why, in one place.
	 *
	 * Used by both the cron (a clear model "reject") and the admin queue, so
	 * neither can forget the email. Until 2026-09-19 neither sent one: a
	 * member found out only by revisiting their profile, which most never did —
	 * hence "showing in review indefinitely" from the member's side even when
	 * a decision had been made.
	 *
	 * @param int    $uid    The member.
	 * @param string $reason A key from reasons(); anything else becomes 'other'.
	 * @param int    $by     Reviewer id, 0 for the model.
	 */
	public static function reject( $uid, $reason, $by = 0 ) {
		$uid    = (int) $uid;
		$reason = array_key_exists( (string) $reason, self::reasons() ) ? (string) $reason : 'other';

		update_user_meta( $uid, 'csm_av_status', 'rejected' );
		update_user_meta( $uid, 'csm_av_reason', $reason );
		update_user_meta( $uid, 'csm_av_decided_by', (int) $by );
		update_user_meta( $uid, 'csm_av_decided_at', time() );
		if ( 0 === (int) $by ) {
			update_user_meta( $uid, 'csm_av_auto', 1 );
		}

		self::email_rejection( $uid, $reason );
	}

	/**
	 * "We could not verify your document — here is why, here is where to fix it."
	 *
	 * Queued, so it obeys the master switch and the caps. The type carries the
	 * decision time so a member rejected, re-uploading and rejected again is
	 * told the second time too — a fixed type would dedupe the second away.
	 */
	private static function email_rejection( $uid, $reason ) {
		$user = get_userdata( $uid );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$reasons = self::reasons();
		$why     = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reasons['other'];
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name    = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : $user->display_name;
		$first   = trim( (string) preg_split( '/\s+/', trim( (string) $name ) )[0] );

		$body = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">'
			. '<p>Hi ' . esc_html( $first ? $first : 'there' ) . ',</p>'
			. '<p>We looked at the ICAI document on your ' . esc_html( $site ) . ' profile and could not verify it yet.</p>'
			. '<p style="padding:12px 14px;background:#fdf3e7;border-left:4px solid #a9822b;border-radius:6px"><strong>' . esc_html( $why ) . '</strong></p>'
			. '<p>Upload a replacement and we will check it again — the Verified CA badge is shown as soon as it passes.</p>'
			. '<p style="margin:26px 0"><a href="' . esc_url( home_url( '/profile/edit/?g=10' ) )
			. '" style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">Upload a new document</a></p>'
			. '<p style="color:#7a6f68;font-size:13px">If you think this is a mistake, reply to this email and a person will look.</p>'
			. '</div>';

		if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Queue' ) ) {
			\CAShaadi\Modules\Emails\Queue::notify( $uid, 'csm-ca-rejected-' . time(), 'About your ICAI document on ' . $site, $body );
			return;
		}
		wp_mail( $user->user_email, 'About your ICAI document on ' . $site, $body );
	}

	/**
	 * A fresh upload starts the review again.
	 *
	 * Without this a rejected member who did exactly what the email asked —
	 * uploaded a better document — stayed "rejected" forever, because nothing
	 * watched the field. The model re-checks it on the next sweep.
	 */
	public static function on_doc_changed( $data ) {
		$field_id = is_object( $data ) && isset( $data->field_id ) ? (int) $data->field_id : 0;
		$uid      = is_object( $data ) && isset( $data->user_id ) ? (int) $data->user_id : 0;
		if ( $field_id !== (int) Config::FIELD_CA_DOC || ! $uid ) {
			return;
		}
		/*
		 * Only a real upload restarts the review. A refused upload leaves
		 * bpxcftr's '-' placeholder, and resetting on that told members their
		 * nothing was "in review" — for weeks (2026-09-24).
		 */
		$value = is_object( $data ) && isset( $data->value ) ? trim( (string) $data->value ) : '';
		if ( '' === $value || '-' === $value ) {
			return;
		}
		$status = (string) get_user_meta( $uid, 'csm_av_status', true );
		if ( 'approved' === $status ) {
			return;   // an approved member changing their document is a reviewer's call, not an automatic one
		}
		delete_user_meta( $uid, 'csm_av_status' );
		delete_user_meta( $uid, 'csm_av_result' );
		delete_user_meta( $uid, 'csm_av_reason' );
		delete_user_meta( $uid, 'csm_av_time' );
		delete_user_meta( $uid, 'csm_av_attempts' );

		/*
		 * Check it in minutes, not at the next daily sweep. The sweep picks up
		 * every pending member, so one early run is all this needs.
		 */
		if ( class_exists( __NAMESPACE__ . '\\CaCron' ) ) {
			wp_schedule_single_event( time() + 60, CaCron::HOOK );
		}
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
			// The reason travels with the Reject click, so rejecting without
			// choosing one is impossible rather than merely discouraged.
			echo '<select class="csm-av-reason" data-uid="' . (int) $uid . '" style="max-width:190px;margin:4px 0">';
			foreach ( self::reason_labels() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
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
		if ( 'rejected' === $dec ) {
			// One path for every rejection, so the email cannot be forgotten.
			$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
			self::reject( $uid, $reason, get_current_user_id() );
		} else {
			update_user_meta( $uid, 'csm_av_status', $dec );
			update_user_meta( $uid, 'csm_av_decided_by', get_current_user_id() );
			update_user_meta( $uid, 'csm_av_decided_at', time() );
			// Cleared on approval so a member rejected, re-uploaded and then
			// accepted is not left carrying an explanation for a reversed decision.
			delete_user_meta( $uid, 'csm_av_reason' );
		}

		wp_send_json_success( array( 'status' => $dec ) );
	}
}
