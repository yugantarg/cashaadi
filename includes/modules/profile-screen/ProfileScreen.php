<?php
/**
 * Profile screen — the design's profile section rows.
 *
 * The approved design replaces BuddyPress's "View / Edit / Change Profile Photo"
 * tab strip on the member's own profile with a list of profile sections, each
 * showing its completion state and opening the matching editor:
 *
 *     About & Basics          Complete
 *     Professional            Complete
 *     Family & Lifestyle      2 left
 *
 * The mock showed three illustrative rows; this renders the SEVEN real xProfile
 * groups in Config::GROUP_ORDER (Basic Details, Professional details, Community,
 * Lifestyle and Habits, Family Details, Hobbies and Interests, Verification), so
 * the screen always matches the site's actual field structure.
 *
 * Status is derived from REQUIRED fields only — "N left" is the number of
 * required fields still empty in that group, which is the same notion of
 * "steps left" the onboarding wizard enforces. Groups with nothing outstanding
 * read "Complete".
 *
 * Conservative by construction, like the Settings hub:
 *   - renders markup only; saves nothing, changes no field logic
 *   - mobile only (<= 782px), where the app shell lives — desktop keeps the
 *     stock BuddyPress profile untouched
 *   - every row links to /profile/edit/group/{id}/, a real BuddyPress screen
 *
 * Because the rows cover Edit, and the photo gallery already links to
 * /profile/change-avatar/ ("Add or remove photos"), the #subnav strip on this
 * screen can be hidden without losing a route.
 */

namespace CAShaadi\Modules\ProfileScreen;

use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileScreen {

	public static function register() {
		// After the member header (and after the photo gallery, which uses the
		// default priority on this same hook).
		add_action( 'bp_after_member_header', array( __CLASS__, 'render' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 23 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * The member's OWN profile *view* screen — never someone else's, and not the
	 * edit / change-avatar / change-cover sub-screens (those are the editors the
	 * rows point at).
	 */
	private static function is_here() {
		if ( ! function_exists( 'bp_is_user_profile' ) || ! function_exists( 'bp_is_my_profile' ) ) {
			return false;
		}
		if ( ! bp_is_user_profile() || ! bp_is_my_profile() ) {
			return false;
		}
		$action = function_exists( 'bp_current_action' ) ? (string) bp_current_action() : '';
		return in_array( $action, array( '', 'public' ), true );
	}

	public static function body_class( $classes ) {
		if ( self::is_here() ) {
			$classes[] = 'csm-prof-screen'; // my own profile
		}
		// Any member's profile view, mine or someone else's. Photos belong on this
		// screen and nowhere else; without this they render on Matches,
		// Notifications and every other member screen, because the gallery hooks
		// bp_after_member_header which fires on all of them.
		if ( function_exists( 'bp_is_user_profile' ) && bp_is_user_profile() ) {
			$action = function_exists( 'bp_current_action' ) ? (string) bp_current_action() : '';
			if ( in_array( $action, array( '', 'public' ), true ) ) {
				$classes[] = 'csm-on-profile';
			}
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::is_here() ) {
			return;
		}
		Assets::style( 'profile-sections', 'assets/css/profile-sections.css' );
	}

	/**
	 * How many fields are still outstanding in one xProfile group.
	 *
	 * Only three of this site's seven groups actually carry required fields
	 * (verified on staging2: Basic Details, Professional details, Community).
	 * Counting *required*-empty alone would therefore report "Complete" for
	 * Lifestyle, Family Details, Hobbies and Verification even when the member
	 * has filled in nothing at all — worse than useless, because it tells them
	 * there is nothing left to do.
	 *
	 * So:
	 *   - group HAS required fields → count required-but-empty (the real blockers
	 *     the onboarding wizard enforces)
	 *   - group has NONE            → count empty fields across the group, so the
	 *     row still reflects genuine progress
	 *
	 * @param  object $group A group from bp_xprofile_get_groups( fetch_fields ).
	 * @param  int    $uid   User id.
	 * @return int           Count of outstanding fields (0 = complete).
	 */
	private static function missing_in_group( $group, $uid ) {
		if ( empty( $group->fields ) || ! function_exists( 'xprofile_get_field_data' ) ) {
			return 0;
		}

		$has_required = false;
		foreach ( $group->fields as $field ) {
			if ( ! empty( $field->is_required ) ) {
				$has_required = true;
				break;
			}
		}

		$missing = 0;
		foreach ( $group->fields as $field ) {
			// When the group defines required fields, only those count as
			// outstanding; otherwise every empty field does.
			if ( $has_required && empty( $field->is_required ) ) {
				continue;
			}
			$val = xprofile_get_field_data( $field->id, $uid );
			if ( is_array( $val ) ) {
				$val = implode( '', $val );
			}
			if ( '' === trim( (string) $val ) ) {
				$missing++;
			}
		}
		return $missing;
	}

	public static function render() {
		if ( ! self::is_here() ) {
			return;
		}
		if ( ! function_exists( 'bp_xprofile_get_groups' ) || ! function_exists( 'bp_loggedin_user_url' ) ) {
			return;
		}

		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}

		$groups = bp_xprofile_get_groups( array( 'fetch_fields' => true ) );
		if ( empty( $groups ) ) {
			return;
		}

		// Index by id so we can present them in the site's canonical order.
		$by_id = array();
		foreach ( $groups as $g ) {
			$by_id[ (int) $g->id ] = $g;
		}

		$order = Config::GROUP_ORDER;
		foreach ( array_keys( $by_id ) as $gid ) {
			if ( ! in_array( $gid, $order, true ) ) {
				$order[] = $gid; // any group not in the canonical list still shows
			}
		}

		$base = trailingslashit( bp_loggedin_user_url() );

		// ---- nudge -------------------------------------------------------
		// Members leave the wizard once the compulsory fields are done, so the
		// profile is deliberately incomplete afterwards. Surface what is left and
		// jump straight to the first section that needs work, rather than leaving
		// them to discover it by scrolling the rows.
		$outstanding = 0;
		$first_gap   = 0;
		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( empty( $by_id[ $gid ] ) ) {
				continue;
			}
			$n = self::missing_in_group( $by_id[ $gid ], $uid );
			if ( $n > 0 ) {
				$outstanding += $n;
				if ( ! $first_gap ) {
					$first_gap = $gid;
				}
			}
		}

		if ( $outstanding > 0 && $first_gap ) {
			echo '<a class="csm-nudge" href="' . esc_url( $base . 'profile/edit/group/' . $first_gap . '/' ) . '">';
			echo '<span class="csm-nudge-text">';
			echo '<strong class="csm-nudge-title">' . esc_html(
				sprintf(
					/* translators: %d: number of profile details still empty. */
					_n( '%d detail left', '%d details left', $outstanding, 'cashaadi-ui' ),
					$outstanding
				)
			) . '</strong>';
			echo '<span class="csm-nudge-sub">' . esc_html__( 'A fuller profile gets more matches.', 'cashaadi-ui' ) . '</span>';
			echo '</span>';
			echo '<span class="csm-nudge-cta">' . esc_html__( 'Continue', 'cashaadi-ui' ) . '</span>';
			echo '</a>';
		}

		echo '<section class="csm-sec" aria-label="' . esc_attr__( 'Profile sections', 'cashaadi-ui' ) . '">';
		echo '<h3 class="csm-sec-h">' . esc_html__( 'Your profile', 'cashaadi-ui' ) . '</h3>';
		echo '<ul class="csm-sec-list">';

		foreach ( $order as $gid ) {
			$gid = (int) $gid;
			if ( empty( $by_id[ $gid ] ) ) {
				continue;
			}
			$group   = $by_id[ $gid ];
			$missing = self::missing_in_group( $group, $uid );

			echo '<li class="csm-sec-row">';
			echo '<a href="' . esc_url( $base . 'profile/edit/group/' . $gid . '/' ) . '">';
			echo '<span class="csm-sec-label">' . esc_html( $group->name ) . '</span>';
			if ( $missing > 0 ) {
				echo '<span class="csm-sec-state is-todo">'
					/* translators: %d: number of required fields still empty. */
					. esc_html( sprintf( _n( '%d left', '%d left', $missing, 'cashaadi-ui' ), $missing ) )
					. '</span>';
			} else {
				echo '<span class="csm-sec-state is-done">' . esc_html__( 'Complete', 'cashaadi-ui' ) . '</span>';
			}
			echo '<span class="csm-sec-chev" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>'
				. '</span>';
			echo '</a></li>';
		}

		echo '</ul></section>';
	}
}
