<?php
/**
 * Announcement — the one email that tells every member the rebuild is live.
 *
 * WHY IT IS ITS OWN MODULE. Engagement's campaigns are recurring and derive
 * their audience from a condition ("your photo is too small"). This one is the
 * opposite: it goes once, to everybody, and the audience is simply "is a real
 * member". Folding that into Engagement would put a one-off beside a set of
 * rules that run every day, which is how a send-once email gets sent twice.
 *
 * THE COPY IS THE OWNER'S, VERBATIM. It was written and edited by hand and is
 * reproduced here unchanged. Do not improve it in passing.
 *
 * WHO IS EXCLUDED, AND WHY IT MATTERS. Only accounts holding a role and with
 * user_status = 0 are in the audience. On this install those two conditions
 * exclude exactly the 67 accounts that never completed activation — which is
 * also where every disposable-domain signup lives (1secmail, anonmails.de,
 * discard.email and friends). Mailing those on a sending domain with no
 * reputation is how a new ZeptoMail account earns a spam-trap hit on its first
 * campaign, so the exclusion is deliberate, not incidental.
 *
 * Nothing here sends. stage() writes rows with status 'held'; releasing them is
 * a separate, manual act on the Email campaigns screen.
 */

namespace CAShaadi\Modules\Emails;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Announcement {

	/** Sent once, ever. No period suffix — that is the point. */
	const TYPE = 'csm-v2launch';

	const SUBJECT   = 'CAShaadi v2 is now live!';
	const PREHEADER = 'New Discover, new profile, same matches.';

	/**
	 * Every real member.
	 *
	 * A role plus user_status = 0 is the test for "finished signing up". We do
	 * not filter on profile completeness or last login: the whole point of this
	 * email is to reach people who have not been back yet.
	 */
	public static function audience() {
		$out = array();
		foreach ( get_users( array( 'fields' => array( 'ID', 'user_status' ) ) ) as $u ) {
			if ( (int) $u->user_status !== 0 ) {
				continue;   // never activated
			}
			$wu = new \WP_User( (int) $u->ID );
			if ( empty( $wu->roles ) ) {
				continue;   // no role: activation never completed
			}
			$out[] = (int) $u->ID;
		}
		return $out;
	}

	/** "Hi Anita," — first name only, never blank. */
	private static function greeting( $uid ) {
		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( '' === trim( (string) $name ) ) {
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : '';
		}
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$first = ( $parts && '' !== $parts[0] ) ? $parts[0] : 'there';
		return 'Hi ' . $first . ',';
	}

	/**
	 * The message, for one member.
	 *
	 * The unsubscribe href is a MARKER, not a URL: the row has no tracking
	 * token until it is delivered, and Tracking::instrument() swaps it for the
	 * real link at that moment.
	 */
	public static function body( $uid ) {
		$discover = home_url( '/discover/' );
		$settings = home_url( '/settings/' );

		$bullet = 'margin:0 0 12px';

		return '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">'

			// Preview text: shown by the inbox next to the subject, never on the page.
			. '<div style="display:none;font-size:1px;color:#faf7f5;max-height:0;overflow:hidden">'
			. esc_html( self::PREHEADER ) . '</div>'

			. '<p>' . esc_html( self::greeting( $uid ) ) . '</p>'
			. '<p>We&rsquo;ve spent the last few months rebuilding CAShaadi, and it&rsquo;s live today.</p>'

			. '<p style="font-weight:700;margin:24px 0 10px">What&rsquo;s changed</p>'
			. '<ul style="padding-left:20px;margin:0">'
			. '<li style="' . $bullet . '"><strong>Discover</strong> &mdash; Get a fresh set of profiles for you each week. '
			. 'You can look through all of them before deciding on any, save one to think about, and see who&rsquo;s already interested.</li>'
			. '<li style="' . $bullet . '"><strong>Your profile</strong> &mdash; a clearer editor, better photos, and proper control over who sees what.</li>'
			. '<li style="' . $bullet . '"><strong>Requests and Messages</strong> &mdash; everything in one place, so you can see who asked, '
			. 'who accepted, and pick up a conversation.</li>'
			. '</ul>'

			. '<p style="margin:20px 0 0">Your profile, your matches and your conversations are all exactly as you left them.</p>'

			. '<p style="margin:26px 0"><a href="' . esc_url( $discover ) . '" '
			. 'style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">'
			. 'Open Discover</a></p>'

			. '<hr style="border:0;border-top:1px solid #e8e0da;margin:28px 0 16px">'
			. '<p style="color:#7a6f68;font-size:13px;margin:0 0 8px">'
			. 'You&rsquo;re getting this because you have a CAShaadi account. You can change which emails you receive in '
			. '<a href="' . esc_url( $settings ) . '" style="color:#7a6f68">Settings &rarr; Email notifications</a>, or '
			. '<a href="' . Tracking::UNSUB_MARKER . '" style="color:#7a6f68">unsubscribe</a>.</p>'
			. '<p style="color:#7a6f68;font-size:13px;margin:0">'
			. 'Need anything? <a href="mailto:support@cashaadi.in" style="color:#7a6f68">support@cashaadi.in</a></p>'
			. '</div>';
	}

	/**
	 * Write the held rows. Sends nothing.
	 *
	 * Queue::stage() is the one that refuses a duplicate, a bounced address, a
	 * global opt-out or a paused member, so staging twice is safe and the
	 * second run simply reports everyone as skipped.
	 *
	 * @return array{audience:int,staged:int,skipped:int}
	 */
	public static function stage() {
		if ( ! Config::emails_enabled() ) {
			return array( 'audience' => 0, 'staged' => 0, 'skipped' => 0 );
		}

		$audience = self::audience();
		$staged   = 0;
		$skipped  = 0;

		foreach ( $audience as $uid ) {
			if ( Queue::stage( $uid, self::TYPE, self::SUBJECT, self::body( $uid ) ) ) {
				$staged++;
			} else {
				$skipped++;
			}
		}

		return array( 'audience' => count( $audience ), 'staged' => $staged, 'skipped' => $skipped );
	}
}
