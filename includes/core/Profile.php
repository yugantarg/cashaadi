<?php
/**
 * Reading a member's profile, safely.
 *
 * Every screen that shows someone else's details needs the same three things,
 * and every one of them has been got wrong at least once on this site:
 *
 *   1. PER-FIELD VISIBILITY IS NOT AUTOMATIC. Each field carries an "Everyone /
 *      Only Me / All Members / My Friends" setting, and
 *      xprofile_get_field_data() ignores it completely — BuddyPress applies
 *      visibility inside its own profile loop, which custom screens do not use.
 *      The Discover card read fields directly and would have shown restricted
 *      City / Bio / Company / Height to everyone (fixed v0.29.1). Anything that
 *      reads fields itself MUST filter through
 *      bp_xprofile_get_hidden_fields_for_user().
 *
 *   2. SOME VALUES NEED INTERPRETING. Age is filtered on this site and comes
 *      back as "27 years old" rather than 27; Height is stored in centimetres
 *      but read as feet and inches everywhere it is shown.
 *
 *   3. EMPTY IS NOT THE SAME AS HIDDEN, but both must simply not render, or a
 *      sparse profile turns into a wall of blank rows.
 *
 * Centralising it means the next screen inherits the correct behaviour instead
 * of re-deriving it.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Profile {

	/**
	 * Field ids this viewer may not see for this member.
	 *
	 * @return int[]
	 */
	public static function hidden_for( $profile_id, $viewer_id ) {
		if ( ! function_exists( 'bp_xprofile_get_hidden_fields_for_user' ) ) {
			return array();
		}
		return array_map( 'intval', (array) bp_xprofile_get_hidden_fields_for_user( (int) $profile_id, (int) $viewer_id ) );
	}

	/** Centimetres to the imperial label used everywhere on this site. */
	public static function height_label( $cm ) {
		if ( ! is_numeric( $cm ) ) {
			return '';
		}
		$cm = (int) round( (float) $cm );
		if ( $cm < 100 || $cm > 260 ) {
			return ''; // outside the wizard's slider range — treat as unset
		}
		$inches = (int) round( $cm / 2.54 );
		// Literal prime characters: \u{} escapes are not interpreted in single quotes.
		return intdiv( $inches, 12 ) . '′ ' . ( $inches % 12 ) . '″';
	}

	/** "27 years old" -> "27". */
	public static function age_number( $raw ) {
		return preg_match( '/\d+/', (string) $raw, $m ) ? $m[0] : '';
	}

	/**
	 * A single field by NAME, visibility-filtered.
	 *
	 * Names must be the site's real xProfile labels — 'City' and 'Bio', never
	 * 'Location' or 'About Me', which do not exist here and silently returned
	 * empty on every card until v0.29.0.
	 */
	public static function field( $label, $profile_id, $hidden = null ) {
		if ( ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}
		if ( null === $hidden ) {
			$hidden = self::hidden_for( $profile_id, get_current_user_id() );
		}
		if ( $hidden && function_exists( 'xprofile_get_field_id_from_name' ) ) {
			$fid = (int) xprofile_get_field_id_from_name( $label );
			if ( $fid && in_array( $fid, $hidden, true ) ) {
				return '';
			}
		}
		$v = xprofile_get_field_data( $label, (int) $profile_id );
		if ( is_array( $v ) ) {
			$v = implode( ', ', array_filter( array_map( 'strval', $v ) ) );
		}
		return trim( (string) $v );
	}

	/**
	 * The whole profile, grouped, ready to render.
	 *
	 * Hidden and empty fields are dropped, and a group with nothing left in it is
	 * dropped too — so a half-filled profile reads as a short profile rather than
	 * a broken one.
	 *
	 * @return array{id:int,name:string,age:string,city:string,bio:string,avatar:string,verified:bool,groups:array}
	 */
	public static function full( $profile_id, $viewer_id = 0 ) {
		$profile_id = (int) $profile_id;
		$viewer_id  = $viewer_id ? (int) $viewer_id : get_current_user_id();
		$hidden     = self::hidden_for( $profile_id, $viewer_id );

		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $profile_id ) : '';
		if ( ! $name ) {
			$u    = get_userdata( $profile_id );
			$name = $u ? $u->display_name : __( 'Member', 'cashaadi-ui' );
		}

		$out = array(
			'id'       => $profile_id,
			'name'     => $name,
			'age'      => self::age_number( self::field( 'Age', $profile_id, $hidden ) ),
			'city'     => self::field( 'City', $profile_id, $hidden ),
			'bio'      => self::field( 'Bio', $profile_id, $hidden ),
			'job'      => self::field( 'Current Job Title', $profile_id, $hidden ),
			'avatar'   => function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $profile_id, 'type' => 'full', 'html' => false ) )
				: get_avatar_url( $profile_id, array( 'size' => 600 ) ),
			'url'      => function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $profile_id ) : '',
			'verified' => class_exists( '\CAShaadi\Modules\Verification\Verification' )
				&& \CAShaadi\Modules\Verification\Verification::ca_verified( $profile_id ),
			'groups'   => array(),
		);

		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return $out;
		}

		/*
		 * Fields that already appear in the card header are skipped in the detail
		 * list — repeating Name and Bio under "Basic Details" makes the card look
		 * padded rather than informative.
		 */
		$skip = array( 'Name', 'Bio', 'Age', 'City' );

		$groups = bp_xprofile_get_groups( array( 'fetch_fields' => true ) );
		$order  = Config::GROUP_ORDER;
		$by_id  = array();
		foreach ( (array) $groups as $g ) {
			$by_id[ (int) $g->id ] = $g;
		}
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
			$rows = array();
			foreach ( $by_id[ $gid ]->fields as $field ) {
				if ( in_array( $field->name, $skip, true ) ) {
					continue;
				}
				if ( in_array( (int) $field->id, $hidden, true ) ) {
					continue; // member restricted this field from this viewer
				}
				$val = xprofile_get_field_data( $field->id, $profile_id );
				if ( is_array( $val ) ) {
					$val = implode( ', ', array_filter( array_map( 'strval', $val ) ) );
				}
				$val = trim( (string) $val );
				if ( '' === $val ) {
					continue;
				}
				if ( 'Height' === $field->name ) {
					$val = self::height_label( $val );
					if ( '' === $val ) {
						continue;
					}
				}
				$rows[] = array( 'label' => (string) $field->name, 'value' => $val );
			}
			if ( $rows ) {
				$out['groups'][] = array( 'name' => (string) $by_id[ $gid ]->name, 'fields' => $rows );
			}
		}

		return $out;
	}
}
