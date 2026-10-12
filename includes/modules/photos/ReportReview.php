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
 *   pass   -> PhotoHold::release( $uid, 0 ): visible again, no email.
 *   hold   -> stays hidden; the member is emailed; a human decides.
 *   error  -> one retry 10 minutes later; then left for a human, emailed.
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

	public static function run( $uid ) {
		$uid = (int) $uid;
		$h   = PhotoHold::get( $uid );
		if ( ! $h || 'reported' !== $h['reason'] || ! empty( $h['human'] ) || ! in_array( $h['ai'] ?? '', array( 'pending', 'retry' ), true ) ) {
			return; // released, escalated, or already decided
		}

		$r = self::ask( $uid, (string) ( $h['note'] ?? '' ) );

		$h = PhotoHold::get( $uid ); // may have changed during the call
		if ( ! $h || ! empty( $h['human'] ) ) {
			return;
		}

		if ( ! $r['ok'] ) {
			if ( 'pending' === $h['ai'] ) {
				$h['ai']     = 'retry';
				$h['ai_why'] = substr( $r['error'], 0, 200 );
				update_user_meta( $uid, PhotoHold::META, $h );
				wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::CRON, array( $uid ) );
				return;
			}
			$h['ai']     = 'error';
			$h['ai_why'] = substr( $r['error'], 0, 200 );
			self::to_human( $uid, $h );
			return;
		}

		$v       = $r['verdict'];
		$why     = substr( implode( '; ', array_map( 'strval', (array) ( $v['reasons'] ?? array() ) ) ), 0, 300 );
		$pass    = 'pass' === ( $v['decision'] ?? '' );
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'report_ai_review', 0, $uid, array( 'decision' => $pass ? 'pass' : 'hold', 'confidence' => (float) ( $v['confidence'] ?? 0 ), 'reasons' => $why ) );
		}
		if ( $pass ) {
			PhotoHold::release( $uid, 0 );
			return;
		}
		$h['ai']     = 'hold';
		$h['ai_why'] = $why;
		self::to_human( $uid, $h );
	}

	private static function to_human( $uid, $h ) {
		if ( empty( $h['emailed'] ) ) {
			PhotoHold::email( $uid, 'held', $h['reason'] );
			$h['emailed'] = 1;
		}
		update_user_meta( $uid, PhotoHold::META, $h );
	}

	/** @return array{ok:bool,verdict?:array,error?:string} */
	private static function ask( $uid, $report_reason ) {
		$key = Secrets::openai_api_key();
		if ( '' === $key ) {
			return array( 'ok' => false, 'error' => 'No OpenAI key' );
		}

		$text = self::profile_text( $uid );
		$prompt = "You review a reported profile on CAShaadi.in, a matrimonial site for Indian chartered accountants (CA) and CA students. "
			. "A member reported it as: \"{$report_reason}\". Decide whether it should be visible to members.\n\n"
			. "Answer \"hold\" when the profile as a whole looks fake, a joke, spam or abusive. For example: a cartoon, celebrity, stock or AI-generated photo together with a throwaway bio like \"hi lol\"; "
			. "sexual or offensive photos or text; asking members to contact them off-site or for money; clear signs of a scam; photos that clearly show a different gender from the profile's stated gender.\n"
			. "Answer \"pass\" for an ordinary, sincere profile, even if it is short or has no photo. A non-human photo on its own (a pet, scenery) is acceptable if the rest of the profile looks genuine.\n\n"
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
