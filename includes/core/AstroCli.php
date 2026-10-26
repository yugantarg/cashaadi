<?php
/**
 * wp csm astro setup    create the Astro Details group (Time of Birth, Place
 *                       of Birth) and Native Place in Family Details. Safe to
 *                       run again.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AstroCli {

	public function setup() {
		if ( ! function_exists( 'xprofile_insert_field_group' ) ) {
			\WP_CLI::error( 'BuddyPress profile fields are not active.' );
		}
		$gid = Astro::group_id();
		if ( ! $gid || ! \BP_XProfile_Group::get( array( 'profile_group_id' => $gid ) ) ) {
			$gid = (int) xprofile_insert_field_group( array(
				'name'        => Astro::GROUP_NAME,
				'description' => 'For families who match horoscopes. You choose whether others see these.',
			) );
			if ( ! $gid ) {
				\WP_CLI::error( 'Could not create the group.' );
			}
			update_option( Astro::GROUP, $gid, false );
			\WP_CLI::log( "Created group {$gid}." );
		}

		$ids = (array) get_option( Astro::FIELDS, array() );
		$want = array(
			'time'  => array( 'Time of Birth', 'For example 06:45 AM.' ),
			'place' => array( 'Place of Birth', 'Town or city.' ),
		);
		$order = 1;
		foreach ( $want as $k => $def ) {
			if ( empty( $ids[ $k ] ) || ! xprofile_get_field( (int) $ids[ $k ] ) ) {
				$ids[ $k ] = (int) xprofile_insert_field( array(
					'field_group_id' => $gid,
					'name'           => $def[0],
					'description'    => $def[1],
					'type'           => 'textbox',
					'is_required'    => false,
					'field_order'    => $order,
				) );
				\WP_CLI::log( "Created {$def[0]}: field {$ids[ $k ]}." );
			}
			$order++;
		}
		update_option( Astro::FIELDS, $ids, false );

		if ( ! xprofile_get_field_id_from_name( Astro::NATIVE ) ) {
			$family = 0;
			foreach ( (array) bp_xprofile_get_groups() as $g ) {
				if ( false !== stripos( (string) $g->name, 'family' ) ) {
					$family = (int) $g->id;
					break;
				}
			}
			if ( ! $family ) {
				\WP_CLI::warning( 'No Family group found; Native Place not created.' );
			} else {
				$nid = (int) xprofile_insert_field( array(
					'field_group_id' => $family,
					'name'           => Astro::NATIVE,
					'description'    => 'The town your family is from.',
					'type'           => 'textbox',
					'is_required'    => false,
					'field_order'    => 99,
				) );
				\WP_CLI::log( "Created Native Place: field {$nid} in group {$family}." );
			}
		}
		\WP_CLI::success( 'Astro details ready. Run `wp csm buckets rebuild` to group cities by state.' );
	}
}
