<?php
/**
 * Weekly "N people viewed your profile" digest.
 *
 * For members who no longer get the daily "someone viewed you" email (women,
 * per csm_viewed_email_skip_genders — see Premium::view_email_wanted()). A
 * woman's profile is shown to many men every day, so the daily email carried
 * no news and its open rate fell with volume; men are viewed rarely and keep
 * the daily one. One weekly email with a real number keeps the hook.
 *
 * Sent through the queue like every other notification. The type key is per
 * ISO week, so the table's unique key makes a second send in the same week
 * impossible, whatever the cron does.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Modules\Emails\Engagement;
use CAShaadi\Modules\Emails\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewDigest {

	const CRON = 'csm_view_digest_hourly';

	/** IST send window: Sunday from this hour; Monday catches up if missed. */
	const SEND_DOW  = 7;
	const SEND_HOUR = 18;

	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/**
	 * The Sunday this digest belongs to: today if Sunday, else the previous one.
	 * The Monday catch-up therefore shares Sunday's key, and cannot re-send.
	 */
	public static function digest_sunday( $now = null ) {
		$now = $now ? $now : self::ist_now();
		return 7 === (int) $now->format( 'N' ) ? $now : $now->modify( 'last sunday' );
	}

	/** The type key, e.g. csm-viewed-digest-20260927. */
	public static function type_key( $now = null ) {
		return 'csm-viewed-digest-' . self::digest_sunday( $now )->format( 'Ymd' );
	}

	/**
	 * No digest for Sundays before this date (option csm_view_digest_start,
	 * Ymd). Lets the first digest wait for a clean week after the daily email
	 * stopped, rather than re-telling last week's views.
	 */
	public static function started( $now = null ) {
		$from = preg_replace( '/\D/', '', (string) get_option( 'csm_view_digest_start', '' ) );
		return '' === $from || self::digest_sunday( $now )->format( 'Ymd' ) >= $from;
	}

	/** Are we inside the send window? Sunday from SEND_HOUR, or any of Monday. */
	public static function in_window( $now = null ) {
		$now = $now ? $now : self::ist_now();
		$dow = (int) $now->format( 'N' );
		$h   = (int) $now->format( 'G' );
		return ( self::SEND_DOW === $dow && $h >= self::SEND_HOUR ) || ( 1 === $dow && $h >= 9 );
	}

	public static function tick() {
		if ( ! self::in_window() || ! self::started() ) {
			return;
		}
		self::run();
	}

	/**
	 * Queue this week's digests. Idempotent: the per-week type key refuses a
	 * repeat for anyone already queued.
	 *
	 * @return array{audience:int,queued:int,skipped:int}
	 */
	public static function run( $dry = false ) {
		$out  = array( 'audience' => 0, 'queued' => 0, 'skipped' => 0 );
		$type = self::type_key();

		foreach ( self::audience() as $uid => $n ) {
			$out['audience']++;
			if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Engagement' ) && ! Engagement::allowed( $uid, 'csm_email_matches' ) ) {
				$out['skipped']++;
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				$out['skipped']++;
				continue;
			}
			if ( $dry ) {
				$out['queued']++;
				continue;
			}
			$ok = Queue::notify( $uid, $type, self::subject( $n ), self::body( $uid, $n ) );
			$out[ $ok ? 'queued' : 'skipped' ]++;
		}
		return $out;
	}

	/**
	 * Members due a digest → distinct viewers in the last 7 days.
	 *
	 * Only members whose gender is on the daily-email skip list; everyone else
	 * still gets the daily email and must not get both. Admin viewers are never
	 * recorded (Premium::record_view), so they need no exclusion here; blocked
	 * pairs are dropped per member.
	 *
	 * @return array<int,int> user_id => viewer count
	 */
	public static function audience() {
		global $wpdb;
		$t     = $wpdb->prefix . 'csm_profile_views';
		$since = self::ist_now()->modify( '-7 days' )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT viewed_id, GROUP_CONCAT(DISTINCT viewer_id) AS viewers
			 FROM {$t} WHERE last_at >= %s GROUP BY viewed_id",
			$since
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$uid = (int) $r->viewed_id;
			if ( Premium::view_email_wanted( $uid ) ) {
				continue; // still on the daily email
			}
			if ( user_can( $uid, 'manage_options' ) ) {
				continue;
			}
			$viewers = array_filter( array_map( 'intval', explode( ',', (string) $r->viewers ) ) );
			if ( function_exists( 'csm_bl_is_blocked_pair' ) ) {
				$viewers = array_filter( $viewers, function ( $v ) use ( $uid ) {
					return ! csm_bl_is_blocked_pair( $v, $uid );
				} );
			}
			if ( count( $viewers ) > 0 ) {
				$out[ $uid ] = count( $viewers );
			}
		}
		return $out;
	}

	public static function subject( $n ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return 1 === $n
			? '1 person viewed your profile this week on ' . $site
			: $n . ' people viewed your profile this week on ' . $site;
	}

	public static function body( $uid, $n ) {
		$user  = get_userdata( $uid );
		$name  = trim( (string) ( function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : ( $user ? $user->display_name : '' ) ) );
		$parts = preg_split( '/\s+/', $name );
		$first = ( $parts && '' !== $parts[0] ) ? $parts[0] : 'there';
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url   = esc_url( home_url( '/requests/' ) );
		$who   = 1 === $n ? '<strong>1 person</strong> viewed your profile' : '<strong>' . (int) $n . ' people</strong> viewed your profile';

		$msg  = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">';
		$msg .= '<p>Hi ' . esc_html( $first ) . ',</p>';
		$msg .= '<p>Your weekly update from ' . esc_html( $site ) . ': ' . $who . ' in the last 7 days.</p>';
		$msg .= '<p style="margin:26px 0"><a href="' . $url . '" style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">See who viewed you</a></p>';
		$msg .= '<p style="color:#7a6f68;font-size:13px">You are receiving this because members viewed your ' . esc_html( $site ) . ' profile. We send this once a week.</p>';
		$msg .= '</div>';
		return $msg;
	}

	private static function ist_now() {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Kolkata' ) );
	}
}
