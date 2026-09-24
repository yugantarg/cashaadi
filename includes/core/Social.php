<?php
/**
 * LinkedIn and Instagram on the profile (owner, 2026-09-24).
 *
 * Optional, asked in the wizard and editable in the profile, never emailed.
 * They are ordinary xProfile fields, so the wizard, the editor, visibility and
 * the profile card all handle them without a parallel system; this class only
 * owns what is specific to them:
 *
 *   - PARSING. Members paste anything: a full URL, a URL with tracking junk, an
 *     @handle, a bare username. What is STORED is one canonical form — the
 *     LinkedIn profile URL, the Instagram username — so display never has to
 *     guess again.
 *   - SAFETY. Every link we print is rebuilt from a validated handle against a
 *     fixed https host. A stored value can never become an arbitrary href.
 *
 * Mirrored in welcome.js / profile-edit-app.js (csmSocialParse) for the live
 * "Shows as" note under the field.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Social {

	/** Instagram paths that are pages, not people. */
	const IG_RESERVED = array( 'p', 'reel', 'reels', 'explore', 'stories', 'tv', 'accounts', 'direct', 'about', 'developer', 'legal' );

	/** Which social network a field id is, or ''. */
	public static function kind( $field_id ) {
		$field_id = (int) $field_id;
		if ( Config::FIELD_LINKEDIN === $field_id ) {
			return 'linkedin';
		}
		if ( Config::FIELD_INSTAGRAM === $field_id ) {
			return 'instagram';
		}
		return '';
	}

	/** LinkedIn public-profile slug from whatever was typed, or ''. */
	public static function linkedin_slug( $raw ) {
		$v = trim( (string) $raw );
		if ( '' === $v ) {
			return '';
		}
		if ( false !== stripos( $v, 'linkedin.com' ) ) {
			if ( preg_match( '#linkedin\.com/(?:in|pub)/([A-Za-z0-9\-_%]{2,100})#i', $v, $m ) ) {
				return $m[1];
			}
			return ''; // a LinkedIn link, but not to a person (a company, a post)
		}
		$v = ltrim( $v, '@' );
		return preg_match( '/^[A-Za-z0-9\-_]{3,100}$/', $v ) ? $v : '';
	}

	/** Instagram username from whatever was typed, or ''. */
	public static function instagram_handle( $raw ) {
		$v = trim( (string) $raw );
		if ( '' === $v ) {
			return '';
		}
		if ( false !== stripos( $v, 'instagram.com' ) ) {
			if ( ! preg_match( '#instagram\.com/([A-Za-z0-9._]{1,30})#i', $v, $m ) ) {
				return '';
			}
			$v = $m[1];
		}
		$v = strtolower( ltrim( $v, '@' ) );
		if ( ! preg_match( '/^[a-z0-9._]{1,30}$/', $v ) || in_array( $v, self::IG_RESERVED, true ) ) {
			return '';
		}
		return $v;
	}

	/** The value to STORE for this field: canonical, or '' if unreadable. */
	public static function normalise( $kind, $raw ) {
		if ( 'linkedin' === $kind ) {
			$s = self::linkedin_slug( $raw );
			return '' === $s ? '' : 'https://www.linkedin.com/in/' . $s;
		}
		if ( 'instagram' === $kind ) {
			return self::instagram_handle( $raw );
		}
		return (string) $raw;
	}

	/**
	 * What a profile card shows: label text and a safe link, rebuilt from the
	 * parsed handle. Null when the stored value cannot be read.
	 *
	 * @return array{text:string,url:string}|null
	 */
	public static function display( $kind, $stored ) {
		if ( 'linkedin' === $kind ) {
			$s = self::linkedin_slug( $stored );
			return '' === $s ? null : array(
				'text' => 'linkedin.com/in/' . $s,
				'url'  => 'https://www.linkedin.com/in/' . rawurlencode( $s ) . '/',
			);
		}
		if ( 'instagram' === $kind ) {
			$h = self::instagram_handle( $stored );
			return '' === $h ? null : array(
				'text' => '@' . $h,
				'url'  => 'https://www.instagram.com/' . rawurlencode( $h ) . '/',
			);
		}
		return null;
	}
}
