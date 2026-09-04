<?php
/**
 * Engagement emails — the seven the owner specified on 2026-09-04.
 *
 * EVENT-DRIVEN (sent the moment it happens):
 *   - someone liked you          matches
 *   - it's a match               matches
 *
 * SCHEDULED (one daily tick decides what is due):
 *   - new batch has arrived      batch    weekly, Monday, IST
 *   - your picks expire soon     batch    weekly, Saturday, IST
 *   - log in to be shown more    nudges   fortnightly, 14-59 days idle
 *   - we miss you                nudges   monthly at most, 60+ days idle
 * (The CA-verification outcome is transactional and lives in CaCron.)
 *
 * NOTHING HERE CALLS wp_mail(). Every send goes through Queue::notify(), so the
 * master switch governs all of it and each email is a visible row before it goes
 * anywhere. That is what stops a repeat of the staging leak that emailed 97 real
 * members.
 *
 * FREQUENCY is enforced by the queue's UNIQUE KEY (user_id, email_type), not by
 * bookkeeping here: every type carries its period or its subject
 * ("csm-batch-2026-w36", "csm-liked-412"), so a second attempt for the same
 * event is refused by the database rather than by remembering to check.
 *
 * OPT-OUT is per category (matches / batch / nudges), registered into the
 * BuddyPress notification settings screen the app already renders, so the three
 * switches appear at /settings/notifications/ with no new UI and are saved by
 * BuddyPress's own handler.
 *
 * HONESTY CONSTRAINT: the fortnightly email tells members that logging in gets
 * them shown to more people. That is only true because csm_refill_tray() ranks
 * active members as a hard tier above dormant ones. If that tier is ever
 * removed, this email must go with it.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Engagement {

	/** Daily cron hook. One tick; each email decides whether it is due today. */
	const CRON = 'csm_engagement_daily';

	/** Categories a member can switch off, and their default. */
	const CATEGORIES = array(
		'csm_email_matches' => 'Match activity (someone likes you, or you match)',
		'csm_email_batch'   => 'Your weekly profiles (new batch, picks expiring)',
		'csm_email_nudges'  => 'Reminders to come back',
	);

	public static function register() {
		if ( ! Config::emails_enabled() ) {
			return; // same gate as the queue it writes into
		}

		// Event-driven.
		add_action( 'csm_profile_liked', array( __CLASS__, 'on_liked' ), 10, 2 );
		add_action( 'csm_mutual_match', array( __CLASS__, 'on_match' ), 20, 2 );

		// Scheduled.
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );

		// The three opt-out switches, into the screen BuddyPress already renders.
		add_action( 'bp_notification_settings', array( __CLASS__, 'settings_rows' ), 30 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON );
		}
	}

	/* ---------------------------------------------------------- preferences */

	/**
	 * May we send this member this category?
	 *
	 * Default is YES — a member who has never touched the settings screen has no
	 * meta, and defaulting to silence would mean nobody ever hears about a match.
	 * Only an explicit 'no' opts out. The queue separately honours the global
	 * csm_remail_optout.
	 */
	public static function allowed( $user_id, $category ) {
		$v = get_user_meta( (int) $user_id, $category, true );
		return 'no' !== $v;
	}

	public static function settings_rows() {
		$uid = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : get_current_user_id();
		echo '<tr><th scope="row">' . esc_html__( 'CA Shaadi emails', 'cashaadi-ui' ) . '</th><td colspan="2"></td></tr>';
		foreach ( self::CATEGORIES as $key => $label ) {
			$on = self::allowed( $uid, $key );
			printf(
				'<tr><td>%s</td><td class="yes"><input type="radio" name="notifications[%s]" value="yes" %s/></td><td class="no"><input type="radio" name="notifications[%s]" value="no" %s/></td></tr>',
				esc_html( $label ),
				esc_attr( $key ),
				checked( $on, true, false ),
				esc_attr( $key ),
				checked( $on, false, false )
			);
		}
	}

	/* --------------------------------------------------------------- events */

	/**
	 * Someone liked you. Immediate, on the owner's revised rule: a member is
	 * rarely liked more than once a day (137 likes across ~10 weeks among 275
	 * viewers), and the first hours after a request are when interest is highest.
	 *
	 * The liker is NOT named for free members — who liked you is the premium
	 * feature, and naming them in an email would hand it over.
	 */
	public static function on_liked( $liker_id, $liked_id ) {
		$liker_id = (int) $liker_id;
		$liked_id = (int) $liked_id;
		if ( ! $liker_id || ! $liked_id || $liker_id === $liked_id ) {
			return;
		}
		if ( ! self::allowed( $liked_id, 'csm_email_matches' ) ) {
			return;
		}
		if ( self::blocked( $liker_id, $liked_id ) ) {
			return;
		}

		$premium = class_exists( '\CAShaadi\Core\Membership' )
			&& \CAShaadi\Core\Membership::is_premium( $liked_id );

		$who  = $premium ? self::name( $liker_id ) : 'Someone';
		$body = self::wrap(
			self::greeting( $liked_id ),
			'<p><strong>' . esc_html( $who ) . '</strong> has sent you a match request on ' . esc_html( self::site() ) . '.</p>'
			. ( $premium ? '' : '<p style="color:#7a6f68;font-size:13px">Upgrade to Premium to see who it is.</p>' )
			. '<p>Accept it and you can start a conversation.</p>',
			home_url( '/requests/' ),
			'View your requests'
		);

		Queue::notify( $liked_id, 'csm-liked-' . $liker_id, 'You have a new match request', $body );
	}

	/** Both sides matched. Tell each of them, once per pair. */
	public static function on_match( $a, $b ) {
		$a = (int) $a;
		$b = (int) $b;
		if ( ! $a || ! $b || $a === $b || self::blocked( $a, $b ) ) {
			return;
		}
		self::match_one( $a, $b );
		self::match_one( $b, $a );
	}

	private static function match_one( $to, $other ) {
		if ( ! self::allowed( $to, 'csm_email_matches' ) ) {
			return;
		}
		$body = self::wrap(
			self::greeting( $to ),
			'<p>You and <strong>' . esc_html( self::name( $other ) ) . '</strong> have matched on ' . esc_html( self::site() ) . '.</p>'
			. '<p>You can message each other now — a conversation is already waiting for you.</p>',
			home_url( '/messages/' ),
			'Open the conversation'
		);
		Queue::notify( $to, 'csm-match-' . (int) $other, 'It\'s a match on ' . self::site(), $body );
	}

	/* ------------------------------------------------------------ scheduled */

	/**
	 * One tick a day. Each block decides whether it is due, so there is a single
	 * cron entry to keep alive rather than four.
	 *
	 * Day-of-week is read in IST, matching the tray's own week boundary — the
	 * batch email must land on the same Monday the tray resets on, not the
	 * server's Monday.
	 */
	public static function daily() {
		$ist = self::ist_now();
		$dow = (int) $ist->format( 'N' ); // 1 = Mon .. 7 = Sun

		if ( 1 === $dow ) {
			self::send_batch( $ist );
		}
		if ( 6 === $dow ) {
			self::send_expiring( $ist );
		}
		self::send_idle( $ist );
	}

	/** "Your new profiles have arrived" — to everyone holding a fresh tray. */
	private static function send_batch( $ist ) {
		$week = strtolower( $ist->format( 'o-\WW' ) );
		foreach ( self::members_with_pending() as $uid ) {
			if ( ! self::allowed( $uid, 'csm_email_batch' ) ) {
				continue;
			}
			$body = self::wrap(
				self::greeting( $uid ),
				'<p>Your new profiles for this week are ready on ' . esc_html( self::site() ) . '.</p>'
				. '<p>They are chosen for you and they are yours for the week — have a look while they are fresh.</p>',
				home_url( '/discover/' ),
				'See this week\'s profiles'
			);
			Queue::notify( $uid, 'csm-batch-' . $week, 'Your new profiles are ready', $body );
		}
	}

	/** Saturday: unacted profiles are about to be replaced. */
	private static function send_expiring( $ist ) {
		$week = strtolower( $ist->format( 'o-\WW' ) );
		foreach ( self::members_with_pending() as $uid => $count ) {
			if ( ! self::allowed( $uid, 'csm_email_batch' ) ) {
				continue;
			}
			$body = self::wrap(
				self::greeting( $uid ),
				'<p>You still have <strong>' . (int) $count . '</strong> profile' . ( 1 === (int) $count ? '' : 's' )
				. ' waiting on ' . esc_html( self::site() ) . '.</p>'
				. '<p>A new set arrives on Monday. Have a look at these before then.</p>',
				home_url( '/discover/' ),
				'Open Discover'
			);
			Queue::notify( $uid, 'csm-expiring-' . $week, 'Your profiles are waiting', $body );
		}
	}

	/**
	 * The two idle emails.
	 *
	 * 14-59 days → the fortnightly nudge. 60+ → win-back, at most monthly. The
	 * bands do not overlap, so a long-dormant member gets one email, not two.
	 */
	private static function send_idle( $ist ) {
		$fortnight = $ist->format( 'o-' ) . str_pad( (string) (int) ceil( (int) $ist->format( 'W' ) / 2 ), 2, '0', STR_PAD_LEFT );
		$month     = $ist->format( 'Y-m' );

		foreach ( self::idle_members( 14, 59 ) as $uid ) {
			if ( ! self::allowed( $uid, 'csm_email_nudges' ) ) {
				continue;
			}
			$body = self::wrap(
				self::greeting( $uid ),
				'<p>Members who log in at least once a fortnight are shown to more people on '
				. esc_html( self::site() ) . ' — active profiles come first when we choose who to show.</p>'
				. '<p>It takes a moment, and it puts you back in front of people looking now.</p>',
				home_url( '/discover/' ),
				'Log in and be seen'
			);
			Queue::notify( $uid, 'csm-nudge-' . $fortnight, 'Be seen by more members', $body );
		}

		foreach ( self::idle_members( 60, 3650 ) as $uid ) {
			if ( ! self::allowed( $uid, 'csm_email_nudges' ) ) {
				continue;
			}
			$body = self::wrap(
				self::greeting( $uid ),
				'<p>It has been a while since you visited ' . esc_html( self::site() ) . ', and your profile is no longer being shown to new members.</p>'
				. '<p>One visit puts you back in the active pool.</p>',
				home_url( '/discover/' ),
				'Come back'
			);
			Queue::notify( $uid, 'csm-winback-' . $month, 'Your profile is not being seen', $body );
		}
	}

	/* --------------------------------------------------------------- lookup */

	/** viewer_id => count of pending tray rows. */
	private static function members_with_pending() {
		global $wpdb;
		$t    = $wpdb->prefix . 'csm_tray';
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT viewer_id, COUNT(*) c FROM {$t} WHERE status = 'pending' GROUP BY viewer_id"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->viewer_id ] = (int) $r->c;
		}
		return $out;
	}

	/**
	 * Members whose last activity falls between $min and $max days ago.
	 *
	 * Reads bp_activity's last_activity rows — the same signal csm_refill_tray()
	 * ranks on, so "you are not being shown" in the copy and the actual ranking
	 * cannot disagree. Members with no activity row at all are excluded: they
	 * have never logged in, and that is the activation reminder's job.
	 */
	private static function idle_members( $min_days, $max_days ) {
		global $wpdb;
		$now  = (int) current_time( 'timestamp' );
		$from = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $max_days . ' days', $now ) );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $min_days . ' days', $now ) );

		$ids = $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT user_id FROM {$wpdb->prefix}bp_activity
			 WHERE type = 'last_activity'
			 GROUP BY user_id
			 HAVING MAX(date_recorded) BETWEEN %s AND %s",
			$from,
			$to
		) );
		return array_map( 'intval', (array) $ids );
	}

	private static function blocked( $a, $b ) {
		return function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( (int) $a, (int) $b );
	}

	private static function ist_now() {
		try {
			return new \DateTime( 'now', new \DateTimeZone( 'Asia/Kolkata' ) );
		} catch ( \Exception $e ) {
			return new \DateTime();
		}
	}

	private static function site() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	private static function name( $uid ) {
		$n = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( '' === trim( (string) $n ) ) {
			$u = get_userdata( $uid );
			$n = $u ? $u->display_name : 'A member';
		}
		return $n;
	}

	/** "Hi Anita," — first name only, falling back to something that is not blank. */
	private static function greeting( $uid ) {
		$parts = preg_split( '/\s+/', trim( (string) self::name( $uid ) ) );
		$first = ( $parts && '' !== $parts[0] ) ? $parts[0] : 'there';
		return 'Hi ' . $first . ',';
	}

	/** One house style for every engagement email. */
	private static function wrap( $greeting, $html, $url, $cta ) {
		return '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">'
			. '<p>' . esc_html( $greeting ) . '</p>'
			. $html
			. '<p style="margin:26px 0"><a href="' . esc_url( $url ) . '" style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">' . esc_html( $cta ) . '</a></p>'
			. '<p style="color:#7a6f68;font-size:13px">You can turn these emails off in Settings → Email notifications.</p>'
			. '</div>';
	}
}
