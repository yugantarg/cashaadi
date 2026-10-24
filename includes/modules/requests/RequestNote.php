<?php
/**
 * A short note with a match request, for Premium and Gold members (v1.85.0).
 *
 * Owner's plan, 2026-10-24: requests are accepted 9% of the time; a note
 * ("why I am writing to you") gives the receiver a reason to say yes. One
 * note per sender and receiver, shown on the receiver's Requests list and in
 * the request email.
 *
 * Notes may not carry contact details: a phone number in a note would hand
 * out what the phone rules keep for matches and Gold.
 */

namespace CAShaadi\Modules\Requests;

use CAShaadi\Core\Membership;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestNote {

	const MAX = 200;

	public static function register() {
		Migrator::register( 'request_notes', array( __CLASS__, 'schema' ) );
	}

	public static function schema( $wpdb ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
 sender_id BIGINT UNSIGNED NOT NULL,
 receiver_id BIGINT UNSIGNED NOT NULL,
 note VARCHAR(400) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL,
 PRIMARY KEY  (sender_id, receiver_id),
 KEY receiver_id (receiver_id)
) " . $wpdb->get_charset_collate() . ';';
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_request_notes';
	}

	public static function allowed( $uid ) {
		return Membership::is_premium( (int) $uid ) || (bool) apply_filters( 'csm_request_note_allowed', false, (int) $uid );
	}

	/**
	 * Clean a note, or explain why it cannot be sent.
	 *
	 * @return string|\WP_Error The cleaned note ('' for none).
	 */
	public static function clean( $note ) {
		$note = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $note ) ) );
		if ( '' === $note ) {
			return '';
		}
		if ( mb_strlen( $note ) > self::MAX ) {
			return new \WP_Error( 'csm_note_long', sprintf( 'Keep the note under %d characters.', self::MAX ) );
		}
		if ( preg_match( '/\d[\d\s\-]{6,}\d/', $note ) || preg_match( '/@|https?:|www\.|whats\s?app|insta(gram)?|telegram/i', $note ) ) {
			return new \WP_Error( 'csm_note_contact', 'Notes cannot include phone numbers, emails, links or social handles.' );
		}
		return $note;
	}

	public static function save( $sender, $receiver, $note ) {
		global $wpdb;
		if ( '' === (string) $note ) {
			return;
		}
		$wpdb->replace( self::table(), array(
			'sender_id'   => (int) $sender,
			'receiver_id' => (int) $receiver,
			'note'        => (string) $note,
			'created_at'  => current_time( 'mysql' ),
		) );
	}

	public static function get( $sender, $receiver ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$t} WHERE sender_id = %d AND receiver_id = %d", (int) $sender, (int) $receiver ) );
	}
}
