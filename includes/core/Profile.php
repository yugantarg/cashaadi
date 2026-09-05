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
	/*
	 * A stand-in "viewer" for the how-others-see-me preview: a logged-in member
	 * who is not the owner and not a match. Passing 0 (a logged-OUT stranger) hid
	 * every "All members" field — e.g. Age — which real members do in fact see,
	 * so the preview understated what others get.
	 */
	const VIEWER_MEMBER = -1;

	/**
	 * Values that are not answers.
	 *
	 * Several selectboxes on this install ship a placeholder option whose LABEL is
	 * its value, and members who never opened the dropdown have that placeholder
	 * saved as real data: "Select" is stored for 26 members on Religion, 19 on
	 * Nuclear/Joint and 4 on Occupation Status. Rendered literally it reads
	 * "Nuclear/Joint: Select", which is worse than showing nothing.
	 *
	 * Treated as empty for BOTH display and completion counting — a member who
	 * left the placeholder in place has not answered, and the progress meter
	 * should say so rather than crediting them for it.
	 *
	 * DROPDOWN PLACEHOLDERS ONLY. An earlier version of this also treated "NA",
	 * "None" and "N/A" as empty, which was wrong: in the data those appear only on
	 * TEXTBOX fields (Other Qualifications, Father's Occupation, Company Name),
	 * where a member typing "None" is genuinely answering the question. "Select"
	 * appears only on selectboxes, where it can only mean the dropdown was never
	 * opened. Hiding a member's real answer is worse than the problem being fixed.
	 *
	 * Only exact, case-insensitive matches: a real answer containing the word
	 * ("Selected for audit") must survive.
	 */
	public static function is_placeholder( $value ) {
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( 'select', '-- select --', '--select--', 'select one', 'please select' ), true );
	}

	public static function hidden_for( $profile_id, $viewer_id ) {
		if ( self::VIEWER_MEMBER === (int) $viewer_id ) {
			return self::hidden_for_member( (int) $profile_id );
		}
		if ( ! function_exists( 'bp_xprofile_get_hidden_fields_for_user' ) ) {
			return array();
		}
		return array_map( 'intval', (array) bp_xprofile_get_hidden_fields_for_user( (int) $profile_id, (int) $viewer_id ) );
	}

	/**
	 * What a non-match logged-in member cannot see: fields set to "My matches"
	 * (friends) or "Only me" (adminsonly), plus the app's always-private ones
	 * (phone, exact DOB, and the verification documents). Public and "All
	 * members" fields stay visible — which is the point: this restores Age and
	 * any other member-visible detail to the preview.
	 */
	private static function hidden_for_member( $profile_id ) {
		$hidden = array();
		if ( function_exists( 'bp_xprofile_get_groups' ) && function_exists( 'xprofile_get_field_visibility_level' ) ) {
			foreach ( (array) bp_xprofile_get_groups( array( 'fetch_fields' => true ) ) as $g ) {
				foreach ( (array) ( isset( $g->fields ) ? $g->fields : array() ) as $f ) {
					$level = xprofile_get_field_visibility_level( (int) $f->id, (int) $profile_id );
					if ( 'friends' === $level || 'adminsonly' === $level ) {
						$hidden[] = (int) $f->id;
					}
				}
			}
		}
		$hidden[] = (int) Config::FIELD_PHONE;
		$hidden[] = (int) Config::FIELD_DOB;
		$hidden[] = (int) Config::FIELD_CA_DOC;
		if ( function_exists( 'xprofile_get_field_id_from_name' ) ) {
			$other = (int) xprofile_get_field_id_from_name( 'Other relevant documents' );
			if ( $other ) {
				$hidden[] = $other;
			}
		}
		/*
		 * Never the four that identify a profile. This path builds the list
		 * itself rather than asking BuddyPress, so SettingsScreen's filter does
		 * not reach it — the rule has to be applied here too.
		 */
		$hidden = array_diff( $hidden, Config::ALWAYS_PUBLIC_FIELDS );

		return array_values( array_unique( array_map( 'intval', $hidden ) ) );
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

	/**
	 * A stored xProfile value, as a human would type it.
	 *
	 * BuddyPress stores field data DOUBLE-ESCAPED, and its own display filters
	 * undo that on the way out. Ours never did, so the escaping surfaced:
	 *
	 *   Bio          ->  I\\'m looking for a caring partner
	 *   Company Name ->  Db Desai &amp;amp; Associates
	 *
	 * Both come from wp_filter_kses() on xprofile_data_value_before_save, which
	 * returns addslashes( wp_kses( ... ) ) — slashes AND entities, by design,
	 * because the native form posts slashed input and renders HTML output. The
	 * fix belongs on READ, not on save: writing unescaped values would diverge
	 * from every row BuddyPress has written since the site launched (verified —
	 * the oldest affected rows predate this plugin entirely).
	 *
	 * Entities are decoded because our screens render values as TEXT, via
	 * textContent, so `&amp;` has no chance to become `&` later. wp_kses re-encodes
	 * on the next save, so the round trip is stable.
	 */
	public static function text( $raw ) {
		$v = stripslashes_deep( (string) $raw );
		return trim( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
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
		return self::text( $v );
	}

	/**
	 * How much of a member's own profile is still outstanding, per group.
	 *
	 * Only three of this site's seven groups actually define required fields
	 * (Basic Details, Professional details, Community). Counting required-empty
	 * alone therefore reports "Complete" for Lifestyle, Family, Hobbies and
	 * Verification even when the member has filled in nothing at all — worse than
	 * useless, because it tells them there is nothing left to do. So:
	 *
	 *   - group HAS required fields → count required-but-empty (the real blockers,
	 *     the same ones /welcome/ enforces)
	 *   - group has NONE            → count every empty field, so the row still
	 *     reflects genuine progress
	 *
	 * Shared by the Profile screen and the older BuddyPress-page renderer so the
	 * headline count and the per-row counts cannot disagree.
	 *
	 * @return array{groups:array,outstanding:int,firstGap:int}
	 */
	public static function completion( $uid ) {
		$out = array( 'groups' => array(), 'outstanding' => 0, 'firstGap' => 0 );

		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return $out;
		}
		$groups = bp_xprofile_get_groups( array( 'fetch_fields' => true ) );
		if ( empty( $groups ) ) {
			return $out;
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

		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( empty( $by_id[ $gid ] ) ) {
				continue;
			}
			$group   = $by_id[ $gid ];
			$missing = self::missing_in_group( $group, $uid );

			$out['groups'][] = array(
				'id'      => $gid,
				'name'    => (string) $group->name,
				'missing' => $missing,
			);

			if ( $missing > 0 ) {
				$out['outstanding'] += $missing;
				if ( ! $out['firstGap'] ) {
					$out['firstGap'] = $gid;
				}
			}
		}

		return $out;
	}

	/**
	 * Fields the edit screen deliberately hides, so completion must not count
	 * them: a field a member cannot reach is a "detail left" they can never
	 * clear. 'Other relevant documents' is the Verification group's optional
	 * second upload — ProfileEditScreen hides it, which left Verification stuck
	 * at "1 detail left" for everyone even once the ICAI ID was uploaded.
	 *
	 * Kept in step with ProfileEditScreen::rest_get()'s own $hide list by name.
	 */
	const UNCOUNTED_FIELDS = array( 'Other relevant documents' );

	/** Outstanding fields in one group — see completion() for the rule. */
	public static function missing_in_group( $group, $uid ) {
		if ( empty( $group->fields ) || ! function_exists( 'xprofile_get_field_data' ) ) {
			return 0;
		}

		/*
		 * Count EVERY empty field, not only the required ones (owner: "why is Basic
		 * Details 'Complete' when non-mandatory details are yet to be filled").
		 * Completion now means the whole section is filled; the wizard offers all
		 * these fields (with skip), and the profile hub's "N left" reflects the
		 * same set. Age (auto-derived) and editor-hidden extras never count.
		 */
		$missing = 0;
		foreach ( $group->fields as $field ) {
			if ( in_array( (string) $field->name, self::UNCOUNTED_FIELDS, true ) ) {
				continue;
			}
			if ( (int) $field->id === Config::FIELD_AGE ) {
				continue;
			}
			$val = xprofile_get_field_data( $field->id, $uid );
			if ( is_array( $val ) ) {
				$val = implode( '', $val );
			}
			if ( '' === trim( (string) $val ) || self::is_placeholder( $val ) ) {
				$missing++;
			}
		}
		return $missing;
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
	/**
	 * @param int|null $viewer_id NULL = the current user. 0 = a logged-out
	 *                            STRANGER, which is what the "how others see me"
	 *                            preview needs. Passing 0 used to fall through to
	 *                            get_current_user_id(), so the preview quietly
	 *                            showed the member their own visibility while
	 *                            claiming restricted fields were hidden.
	 */
	public static function full( $profile_id, $viewer_id = null ) {
		$profile_id = (int) $profile_id;
		$viewer_id  = ( null === $viewer_id ) ? get_current_user_id() : (int) $viewer_id;
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
			/*
			 * Core\Verification, not Modules\Verification.
			 *
			 * Both classes exist and are named the same. The module one is the
			 * ICAI-document REST endpoint and has no ca_verified() at all, so
			 * calling it there is a fatal error that class_exists() does NOT catch —
			 * the class is present, the method simply is not. That took down the
			 * whole Discover queue endpoint on first run.
			 */
			'verified' => method_exists( '\CAShaadi\Core\Verification', 'ca_verified' )
				&& Verification::ca_verified( $profile_id ),
			'groups'   => array(),
		);

		/*
		 * The photo stack. Always at least the avatar, so a caller can render
		 * $out['photos'] unconditionally and never has to special-case an empty
		 * set. See photos() for why it is sometimes ONLY the avatar.
		 */
		$out['photos'] = self::photos( $profile_id, $viewer_id, $out['avatar'] );

		/*
		 * Say OUT LOUD that a photo is blurred.
		 *
		 * Discover handed the viewer a blurred picture and no explanation, so it
		 * read as a broken image or a bad upload rather than a deliberate choice
		 * — and the two ways to see it (match, or Premium) were invisible. The
		 * card now carries a small reveal button, and the copy comes from
		 * Gallery, which already writes this notice for the profile gallery,
		 * gendered and aware of whether a request is in flight.
		 *
		 * The flag is the viewer's, not the owner's: a matched viewer, a premium
		 * one and the owner all see photoHidden false and no button.
		 */
		$out['photoHidden'] = false;
		$out['photoNote']   = '';
		$out['upgradeUrl']  = '';
		if ( class_exists( '\CAShaadi\Modules\Photos\Privacy' )
			&& \CAShaadi\Modules\Photos\Privacy::is_hidden( $profile_id, $viewer_id ) ) {
			$out['photoHidden'] = true;
			if ( method_exists( '\CAShaadi\Modules\Photos\Gallery', 'blur_notice' ) ) {
				$out['photoNote']  = (string) \CAShaadi\Modules\Photos\Gallery::blur_notice( $profile_id, $viewer_id );
				$out['upgradeUrl'] = (string) \CAShaadi\Modules\Photos\Gallery::blur_upgrade_url();
			}
		}

		if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
			return $out;
		}

		/*
		 * Fields that already appear in the card header are skipped in the detail
		 * list — repeating Name and Bio under "Basic Details" makes the card look
		 * padded rather than informative.
		 */
		$skip = array(
			'Name', 'Bio', 'Age', 'City',

			/*
			 * Phone Number is NOT shown on a profile anyone else can see.
			 *
			 * The "how others see me" preview surfaced it: a stranger's view of a
			 * member listed their phone number in full. On a matrimonial site that
			 * is a real safety problem, not a formatting one — contact details
			 * belong behind a match, and members share them when they choose to.
			 * Field visibility could do this per member, but the safe default must
			 * not depend on every member having configured it.
			 *
			 * Date of birth is skipped because Age already appears in the header,
			 * and the stored DOB is a more precise fact than a profile needs to
			 * give away.
			 */
			'Phone Number', 'Date of birth',
		);

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
				/*
				 * Strip markup. xprofile_get_field_data() applies DISPLAY filters,
				 * and some field types return HTML — telephone comes back as
				 * <a href="tel:...">. Rendered as text (which is correct, so a value
				 * can never inject markup), that anchor showed literally on the
				 * profile card.
				 */
				$val = self::text( wp_strip_all_tags( (string) $val ) );
				if ( '' === $val || self::is_placeholder( $val ) ) {
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

	/**
	 * Every photo this viewer may see of this member, main one first.
	 *
	 * Discover showed a single image because Profile::full() only ever returned
	 * the BuddyPress avatar — so a member with five photos was browsed on the
	 * strength of one. The other four existed, but only on their profile screen.
	 *
	 * THE HARD PART IS NOT LISTING THEM, IT IS NOT LEAKING THEM. The avatar is
	 * safe by accident: bp_core_fetch_avatar() runs through Privacy::filter_url()
	 * and Nsfw::mask_avatar_url(), so a blurred or moderated member is already
	 * handled before this class sees the URL. Gallery attachments go through
	 * NEITHER — they are ordinary media URLs, and returning them raw would hand a
	 * blurred member's real photographs to exactly the people they blurred them
	 * from. So each restriction is re-applied here, explicitly:
	 *
	 *   - Private Photo on (and this viewer not entitled) → the avatar alone.
	 *     Privacy blurs a DERIVATIVE OF THE AVATAR FILE; there is no per-attachment
	 *     blur to hand back, so the honest answer is one blurred image, which is
	 *     what the member asked for and what they already get today.
	 *   - Avatar hidden by moderation → the avatar alone, which the NSFW filter has
	 *     already replaced with the default. Someone whose face was withheld must
	 *     not have the rest of their album published beside it.
	 *   - Individually flagged or removed attachments are dropped. Stricter than
	 *     the profile gallery, which shows them while csm_pm_enforce is off. A
	 *     browsing surface that puts a stranger's photo in front of someone
	 *     unprompted should not wait on an enforcement flag.
	 *
	 * The gallery's first entry is skipped: set_avatar() copies the main photo
	 * into BuddyPress, so it is the same picture as the avatar under a different
	 * URL, and including it would open every card on a duplicate.
	 *
	 * @param int    $profile_id Whose photos.
	 * @param int    $viewer_id  Who is looking (0 = logged-out stranger).
	 * @param string $avatar     The already-filtered avatar URL.
	 * @return string[] At least one URL.
	 */
	public static function photos( $profile_id, $viewer_id, $avatar ) {
		$profile_id = (int) $profile_id;
		$only       = array_filter( array( (string) $avatar ) );

		if ( ! class_exists( '\CAShaadi\Modules\Photos\Gallery' ) ) {
			return $only; // photos module flag-gated off
		}

		$is_owner = ( $viewer_id && (int) $viewer_id === $profile_id );

		if ( ! $is_owner
			&& class_exists( '\CAShaadi\Modules\Photos\Privacy' )
			&& \CAShaadi\Modules\Photos\Privacy::is_hidden( $profile_id, (int) $viewer_id ) ) {
			/*
			 * Blur it ourselves rather than trusting the URL we were handed.
			 *
			 * bp_core_fetch_avatar() is filtered by Privacy, but that filter asks
			 * is_hidden() WITHOUT a viewer, so it resolves to the current user. In
			 * the preview the current user is the owner — never hidden from
			 * themselves — so the avatar came back unblurred and the screen showed
			 * a member their real photograph while captioning it as the stranger's
			 * view. Here the viewer is explicit and already known to be excluded,
			 * so ask for the display URL directly.
			 */
			$blurred = \CAShaadi\Modules\Photos\Privacy::display_url( $profile_id, 'full', (string) $avatar );
			return array_filter( array( $blurred ? $blurred : (string) $avatar ) );
		}

		if ( get_user_meta( $profile_id, 'csm_pm_av_hidden', true ) ) {
			return $only;
		}

		$ids = (array) \CAShaadi\Modules\Photos\Gallery::get( $profile_id );
		array_shift( $ids ); // the main photo IS the avatar — see above

		$out = $only;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! $id ) {
				continue;
			}
			$status = (string) get_post_meta( $id, '_csm_pm_status', true );
			if ( 'flagged' === $status || 'removed' === $status ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( $url ) {
				$out[] = $url;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
