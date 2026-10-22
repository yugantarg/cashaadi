<?php
/**
 * OpenAI review of a profile after its first report (owner, 2026-10-12: "In
 * case of 1 report, the full profile is sent to openai for a decision. If
 * passed, it is restored. If 2 or more reports then it is hidden till human
 * reviews").
 *
 * Runs from a single cron event ~30 s after the report. Sends the photos
 * (read from disk, so blurred or private photos are seen too) and the
 * profile text, minus contact details. The same OpenAI key and model as CA
 * Verify.
 *
 * The profile stays visible while it is checked (owner, 2026-10-22).
 *   pass   -> nothing happens; the member never knows.
 *   hold   -> only when clearly fake or spam: hidden, the member is emailed,
 *             and it waits on the Reports page for a human.
 *   error  -> one retry 10 minutes later; then left visible and logged.
 *
 * Owner: non-human photos alone are fine. The model is told to judge the
 * profile as a whole: a cartoon plus a joke bio ("hi lol") is the case to
 * hold.
 */

namespace CAShaadi\Modules\Photos;

use CAShaadi\Core\Config;
use CAShaadi\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportReview {

	const CRON       = 'csm_report_ai_review';
	const MAX_PHOTOS = 4;

	/** A first report waiting for the AI: array( by, note, at, retry? ). The profile stays visible. */
	const PENDING = 'csm_report_ai';

	public static function run( $uid ) {
		$uid = (int) $uid;
		$p   = get_user_meta( $uid, self::PENDING, true );
		if ( ! is_array( $p ) || PhotoHold::is_held( $uid ) ) {
			delete_user_meta( $uid, self::PENDING );
			return; // escalated to a human, released, or already held
		}

		$r = self::ask( $uid, (string) ( $p['note'] ?? '' ) );

		if ( ! is_array( get_user_meta( $uid, self::PENDING, true ) ) || PhotoHold::is_held( $uid ) ) {
			return; // a second report arrived during the call: a human has it
		}
		if ( ! $r['ok'] ) {
			if ( empty( $p['retry'] ) ) {
				$p['retry'] = 1;
				update_user_meta( $uid, self::PENDING, $p );
				wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::CRON, array( $uid ) );
				return;
			}
			// Could not check twice: the profile stays visible (it was only one
			// report); the error is logged for the record.
			delete_user_meta( $uid, self::PENDING );
			if ( function_exists( 'cashaadi' ) ) {
				cashaadi()->log_event( 'report_ai_review', 0, $uid, array( 'decision' => 'error', 'reasons' => substr( $r['error'], 0, 200 ) ) );
			}
			return;
		}

		$v    = $r['verdict'];
		$why  = substr( implode( '; ', array_map( 'strval', (array) ( $v['reasons'] ?? array() ) ) ), 0, 300 );
		$fake = 'hold' === ( $v['decision'] ?? '' );
		delete_user_meta( $uid, self::PENDING );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'report_ai_review', 0, $uid, array( 'decision' => $fake ? 'hold' : 'pass', 'confidence' => (float) ( $v['confidence'] ?? 0 ), 'reasons' => $why ) );
		}
		if ( $fake ) {
			PhotoHold::hold( $uid, 'reported', (int) ( $p['by'] ?? 0 ), (string) ( $p['note'] ?? '' ), true, array( 'ai' => 'hold', 'ai_why' => $why, 'reports' => 1 ) );
		}
	}

	/** @return array{ok:bool,verdict?:array,error?:string} */
	private static function ask( $uid, $report_reason ) {
		$key = Secrets::openai_api_key();
		if ( '' === $key ) {
			return array( 'ok' => false, 'error' => 'No OpenAI key' );
		}

		$text = self::profile_text( $uid );
		$prompt = "You review a reported profile on CAShaadi.in, a matrimonial site for Indian chartered accountants (CA) and CA students. "
			. "A member reported it as: \"{$report_reason}\". Decide whether to HIDE it.\n\n"
			. "Answer \"hold\" ONLY when it is CLEARLY fake or spam: for example a cartoon, celebrity or stock photo together with a throwaway bio like \"hi lol\"; "
			. "sexual or offensive photos or text; asking members for money or to contact them off-site; obvious scam or spam.\n"
			. "Answer \"pass\" whenever the account is plausibly a real person, even if the profile is short, has no photo, has a non-human photo, or you are unsure. When in doubt, pass.\n\n"
			. "Reply in JSON only: {\"decision\":\"pass\" or \"hold\",\"confidence\":0 to 1,\"reasons\":[short strings]}.\n\n"
			. "PROFILE:\n" . $text;

		$content = array( array( 'type' => 'text', 'text' => $prompt ) );
		foreach ( self::photo_files( $uid ) as $path ) {
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$mime = in_array( $ext, array( 'jpg', 'jpeg' ), true ) ? 'jpeg' : ( in_array( $ext, array( 'png', 'webp', 'gif' ), true ) ? $ext : '' );
			$data = '' !== $mime ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $data ) {
				$content[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:image/' . $mime . ';base64,' . base64_encode( $data ), 'detail' => 'low' ) );
			}
		}

		$model = class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) ? (string) \CAShaadi\Modules\CaVerify\CaVerify::opt( 'model' ) : '';
		$resp  = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'           => (string) get_option( 'csm_report_ai_model', '' !== $model ? $model : 'gpt-4o' ),
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array( array( 'role' => 'user', 'content' => $content ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => $resp->get_error_message() );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array( 'ok' => false, 'error' => 'OpenAI: ' . ( $json['error']['message'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $resp ) ) );
		}
		$v = json_decode( (string) ( $json['choices'][0]['message']['content'] ?? '' ), true );
		if ( ! is_array( $v ) || ! isset( $v['decision'] ) ) {
			return array( 'ok' => false, 'error' => 'Could not read the AI answer' );
		}
		return array( 'ok' => true, 'verdict' => $v );
	}

	/** Name, age, gender and every profile field, minus contact details. */
	private static function profile_text( $uid ) {
		$p     = \CAShaadi\Core\Profile::full( $uid, $uid ); // as the member sees it: nothing hidden
		$lines = array( 'Name: ' . ( $p['name'] ?? '' ), 'Age: ' . ( $p['age'] ?? '' ) );
		if ( function_exists( 'xprofile_get_field_data' ) ) {
			$lines[] = 'Stated gender: ' . wp_strip_all_tags( (string) xprofile_get_field_data( Config::FIELD_GENDER, $uid ) );
		}
		if ( ! empty( $p['bio'] ) ) {
			$lines[] = 'About me: ' . $p['bio'];
		}
		foreach ( (array) ( $p['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				$label = (string) ( $f['label'] ?? '' );
				if ( preg_match( '/phone|mobile|email|whatsapp|linkedin|instagram/i', $label ) ) {
					continue;
				}
				$lines[] = $label . ': ' . wp_strip_all_tags( (string) ( $f['value'] ?? '' ) );
			}
		}
		return implode( "\n", $lines );
	}

	/** Local paths of the avatar and gallery photos, medium size where there is one. */
	private static function photo_files( $uid ) {
		$up    = wp_get_upload_dir();
		$files = array();
		if ( function_exists( 'bp_core_fetch_avatar' ) ) {
			$url = (string) bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'full', 'html' => false, 'no_grav' => true ) );
			$url = preg_replace( '/^https?:/', '', strtok( $url, '?' ) );
			$base = preg_replace( '/^https?:/', '', $up['baseurl'] );
			if ( 0 === strpos( $url, $base ) ) {
				$path = $up['basedir'] . substr( $url, strlen( $base ) );
				if ( is_readable( $path ) && false === strpos( $path, 'mystery-man' ) ) {
					$files[] = $path;
				}
			}
		}
		$ids = get_user_meta( $uid, 'csm_photos', true );
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$path = get_attached_file( (int) $id );
			if ( ! $path ) {
				continue;
			}
			$mid = image_get_intermediate_size( (int) $id, 'medium_large' );
			if ( $mid && ! empty( $mid['path'] ) && is_readable( $up['basedir'] . '/' . $mid['path'] ) ) {
				$path = $up['basedir'] . '/' . $mid['path'];
			}
			if ( is_readable( $path ) ) {
				$files[] = $path;
			}
		}
		return array_slice( array_values( array_unique( $files ) ), 0, self::MAX_PHOTOS );
	}
}
