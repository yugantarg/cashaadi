<?php
/**
 * "Few people saw your profile": a weekly nudge to fix what holds a member
 * back (owner, 2026-10-27).
 *
 * The exposure audit found 23% of active men with a photo shown to nobody
 * in a week, and tools/why-unseen.php showed why: their scores, not luck.
 * Against the cards actually shown they lacked verification (about 4.5
 * points) and answers to the matching questions (community, mother tongue,
 * religion, diet: about 5 points together; an unanswered question scores 0).
 *
 * So, for men and women alike: an active member who was shown to fewer than
 * LOW people in the last 7 days, and has something they can fix, is told
 * what, with a link to each:
 *
 *   - an email once a week (Thursdays IST, after Monday's draw has had time
 *     to show; respects the matches email setting);
 *   - a card on their own Profile page while it applies.
 *
 * No counts and no points are given: members are told that these help them
 * be shown, not how the ranking works. Each email is logged as reach_nudge
 * with what was missing, so the effect can be measured.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;
use CAShaadi\Modules\Emails\Engagement;
use CAShaadi\Modules\Emails\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reach {

	const LOW  = 3;  // shown to fewer than this many people in 7 days
	const DAYS = 7;
	const SEND_DOW = 4; // Thursday (ISO day of week, IST)

	public static function register() {
		add_action( 'csm_engagement_daily', array( __CLASS__, 'send_emails' ), 30 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// wp csm reach: who it applies to now, and what they are missing.
			\WP_CLI::add_command( 'csm reach', function () {
				$r = Reach::preview();
				\WP_CLI::log( sprintf( '%d active members were shown to fewer than %d people in %d days and have something to fix (%d men, %d women).', $r['n'], Reach::LOW, Reach::DAYS, $r['Male'], $r['Female'] ) );
				foreach ( $r['gaps'] as $k => $c ) {
					\WP_CLI::log( sprintf( '  %-7s %d', $k, $c ) );
				}
				\WP_CLI::log( 'Emails go out on Thursdays (IST) to those who allow match emails.' );
			} );
		}
	}

	/** Who it applies to now: counts by gender and by what is missing. */
	public static function preview() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID id, g.value gender FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value IN ( 'Male', 'Female' )
			 WHERE EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = u.ID AND a.type = 'last_activity' AND a.date_recorded >= %s )",
			Config::FIELD_GENDER, gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS )
		) );
		$out = array( 'n' => 0, 'Male' => 0, 'Female' => 0, 'gaps' => array() );
		foreach ( (array) $rows as $r ) {
			if ( ! self::applies( (int) $r->id ) ) {
				continue;
			}
			$out['n']++;
			$out[ $r->gender ]++;
			foreach ( self::gaps( (int) $r->id ) as $g ) {
				$out['gaps'][ $g[0] ] = ( $out['gaps'][ $g[0] ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/** How many people this member was shown to in the last DAYS days, by any route. */
	public static function showings( $uid ) {
		global $wpdb;
		$since = wp_date( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_impressions WHERE profile_id = %d AND served_at >= %s", (int) $uid, $since ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_event_log WHERE actor_id = %d AND event_type IN ( 'boost_shown', 'intro_shown' ) AND created_at >= %s", (int) $uid, $since ) );
	}

	/**
	 * What this member can fix, most valuable first: array of
	 * array( key, text, url ). Empty when there is nothing to fix.
	 */
	public static function gaps( $uid ) {
		$uid  = (int) $uid;
		$out  = array();
		$edit = class_exists( '\CAShaadi\Modules\ProfileEdit\ProfileEditScreen' );
		$url  = function ( $fid ) use ( $edit ) {
			$f = function_exists( 'xprofile_get_field' ) ? xprofile_get_field( (int) $fid ) : null;
			$g = $f ? (int) $f->group_id : 0;
			return $edit ? \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url( $g ) : home_url( '/profile/edit/?g=' . $g );
		};

		if ( class_exists( '\CAShaadi\Modules\Onboarding\PhotoOptions' ) && ! \CAShaadi\Modules\Onboarding\PhotoOptions::has_photo( $uid ) ) {
			$out[] = array( 'photo', 'Add a photo', home_url( '/profile/' ) );
		}
		$verified = method_exists( '\CAShaadi\Core\Verification', 'ca_verified' ) && \CAShaadi\Core\Verification::ca_verified( $uid );
		$state    = class_exists( '\CAShaadi\Modules\CaVerify\CaVerify' ) ? \CAShaadi\Modules\CaVerify\CaVerify::member_state( $uid ) : '';
		if ( ! $verified && 'pending' !== $state ) {
			$out[] = array( 'verify', 'Verify your CA or CA Inter status', $edit ? \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url( 10 ) : home_url( '/profile/edit/?g=10' ) );
		}
		$fields = array(
			'community' => array( \CAShaadi\Core\Buckets::field_id( 'community' ), 'your community' ),
			'tongue'    => array( \CAShaadi\Core\Buckets::field_id( 'tongue' ), 'your mother tongue' ),
			'religion'  => array( (int) xprofile_get_field_id_from_name( 'Religion' ), 'your religion' ),
			'diet'      => array( (int) xprofile_get_field_id_from_name( 'Diet' ), 'your diet' ),
		);
		$missing = array();
		$first   = 0;
		foreach ( $fields as $key => $f ) {
			if ( ! $f[0] ) {
				continue;
			}
			$v = xprofile_get_field_data( $f[0], $uid );
			$v = is_array( $v ) ? implode( '', $v ) : trim( wp_strip_all_tags( (string) $v ) );
			if ( '' === $v || '-' === $v ) {
				$missing[] = $f[1];
				$first     = $first ?: $f[0];
			}
		}
		if ( $missing ) {
			$last  = array_pop( $missing );
			$text  = 'Add ' . ( $missing ? implode( ', ', $missing ) . ' and ' : '' ) . $last;
			$out[] = array( 'fields', $text, $url( $first ) );
		}
		return $out;
	}

	/** Whether this member should hear about it now. */
	public static function applies( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		if ( ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) && \CAShaadi\Modules\Settings\Deactivate::is_paused( $uid ) )
			|| ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) && \CAShaadi\Modules\Settings\Closed::is_closed( $uid ) )
			|| ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) && \CAShaadi\Modules\Photos\PhotoHold::is_held( $uid ) ) ) {
			return false;
		}
		$u = get_userdata( $uid );
		if ( ! $u || strtotime( $u->user_registered . ' UTC' ) > time() - self::DAYS * DAY_IN_SECONDS ) {
			return false; // still in their first week: introductions handle that
		}
		return self::showings( $uid ) < self::LOW && self::gaps( $uid );
	}

	/** For the Profile page: the card's lines, or null. */
	public static function card( $uid ) {
		if ( ! self::applies( $uid ) ) {
			return null;
		}
		return array(
			'title' => 'Few members saw your profile this week',
			'body'  => 'Profiles with these are shown to many more people:',
			'items' => array_map( function ( $g ) { return array( 'text' => $g[1], 'url' => $g[2] ); }, self::gaps( $uid ) ),
		);
	}

	/** Weekly, on Thursdays: everyone it applies to who was active in the last 14 days. */
	public static function send_emails() {
		if ( ! class_exists( '\CAShaadi\Modules\Emails\Queue' ) || ! class_exists( '\CAShaadi\Modules\Emails\Engagement' ) ) {
			return;
		}
		$tz  = new \DateTimeZone( 'Asia/Kolkata' );
		$now = new \DateTimeImmutable( 'now', $tz );
		if ( (int) $now->format( 'N' ) !== self::SEND_DOW ) {
			return;
		}
		$week = strtolower( $now->format( 'o-\WW' ) );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT u.ID FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value IN ( 'Male', 'Female' )
			 WHERE EXISTS ( SELECT 1 FROM {$wpdb->prefix}bp_activity a WHERE a.user_id = u.ID AND a.type = 'last_activity' AND a.date_recorded >= %s )",
			Config::FIELD_GENDER, gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS )
		) );
		foreach ( (array) $ids as $uid ) {
			$uid = (int) $uid;
			if ( ! Engagement::allowed( $uid, 'csm_email_matches' ) || ! self::applies( $uid ) ) {
				continue;
			}
			$gaps = self::gaps( $uid );
			$list = '';
			foreach ( $gaps as $g ) {
				$list .= '<li style="margin:6px 0"><a href="' . esc_url( $g[2] ) . '">' . esc_html( $g[1] ) . '</a></li>';
			}
			$body = Engagement::wrap(
				Engagement::greeting( $uid ),
				'<p>Few members saw your profile this week. Profiles with these are shown to many more people:</p><ul>' . $list . '</ul><p>It takes a couple of minutes, and it counts from the next time profiles are shared.</p>',
				$gaps[0][2],
				'Complete your profile'
			);
			if ( Queue::notify( $uid, 'csm-reach-' . $week, 'Help more members find your profile', $body ) ) {
				cashaadi()->log_event( 'reach_nudge', $uid, 0, array( 'gaps' => wp_list_pluck( $gaps, 0 ) ) );
			}
		}
	}
}
