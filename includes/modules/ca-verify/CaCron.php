<?php
/**
 * CA Verify — Auto-Check Cron + Verified Email.
 *
 * Migrates WPCode #12113 ("CSM — CA Verify: Auto-Check Cron + Verified Email"),
 * the companion to #11815 (CaVerify). It:
 *   - runs a self-adapting sweep (hourly until the pending backlog clears, then
 *     daily) that auto-approves members whose AI recommendation is "verify",
 *   - emails a member the moment their csm_av_status becomes 'approved' (one
 *     decision point — covers both cron auto-approve and manual admin Approve),
 *   - one-time backfills the "already-notified" guard so switching this on never
 *     retro-emails existing verified members,
 *   - exposes admin-only ?csm_avc_* diagnostics (test email / dryrun / runnow /
 *     status).
 *
 * The AI engine lives in CaVerify (run_ai / members_with_docs); every call to it
 * is class/method_exists()-guarded so a missing engine never fatals — mirroring
 * the snippet's function_exists() guards.
 *
 * Cron hook: 'csm_avc_sweep_event' (preserved). Recurrence: hourly -> daily,
 * self-adapting via reschedule().
 *
 * Gated behind Config::ca_verify_enabled() — dormant until the coordinated
 * cutover (flip the flag + disable #11815/#12113).
 */

namespace CAShaadi\Modules\CaVerify;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaCron {

	const HOOK = 'csm_avc_sweep_event';

	public static function register() {
		if ( ! Config::ca_verify_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		add_action( self::HOOK, array( __CLASS__, 'sweep' ) );

		// First-time registration: start hourly to work through the backlog.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}

		// Fire the verified email once, the moment csm_av_status becomes 'approved'
		// (any path — cron auto-approve or manual admin Approve).
		add_action( 'updated_user_meta', array( __CLASS__, 'maybe_email_on_status' ), 20, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'maybe_email_on_status' ), 20, 4 );

		// One-time guard backfill + admin-only diagnostics.
		add_action( 'admin_init', array( __CLASS__, 'backfill_guard' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_tools' ) );
	}

	/* ---------- config ---------- */

	public static function batch() {
		return (int) apply_filters( 'csm_avc_batch', 4 );
	}

	/** Whether the AI engine (CaVerify) is present. */
	private static function has_engine() {
		return method_exists( __NAMESPACE__ . '\\CaVerify', 'run_ai' );
	}

	/* ---------- normalise a members_with_docs entry to an int user id ---------- */
	public static function uid( $u ) {
		if ( is_object( $u ) ) {
			return isset( $u->ID ) ? (int) $u->ID : ( isset( $u->id ) ? (int) $u->id : 0 );
		}
		if ( is_array( $u ) ) {
			return isset( $u['ID'] ) ? (int) $u['ID'] : ( isset( $u['id'] ) ? (int) $u['id'] : 0 );
		}
		return (int) $u;
	}

	/* ---------- who still needs an AI check ----------
	 * pending = has a field-484 doc AND no stored result yet AND not already decided.
	 * Members checked before (Aug batch, held-for-review, tests) all have
	 * csm_av_result set, so they are skipped and never re-run or downgraded.
	 */
	public static function pending_ids( $limit ) {
		if ( ! method_exists( __NAMESPACE__ . '\\CaVerify', 'members_with_docs' ) ) {
			return array();
		}
		$rows = CaVerify::members_with_docs( 2000 );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$uid = self::uid( $r );
			if ( ! $uid ) {
				continue;
			}
			$status = get_user_meta( $uid, 'csm_av_status', true );
			if ( 'approved' === $status || 'rejected' === $status || 'review' === $status ) {
				continue;   // decided, or waiting on a person -- not the model's again
			}
			$result = get_user_meta( $uid, 'csm_av_result', true );
			if ( '' !== $result && null !== $result ) {
				/*
				 * A transport or model error is not a decision. Those rows were
				 * skipped forever here -- one bad API minute and the member was
				 * "pending" for good. Retry after six hours; a real verdict
				 * (JSON, not "AI error: ...") is still final.
				 */
				$is_error = 0 === strpos( (string) $result, 'AI error' );
				$age      = time() - (int) get_user_meta( $uid, 'csm_av_time', true );

				/*
				 * A verdict from the earlier prompt carries no decision (it only
				 * said is_ca_document / supports_claim), so nothing ever acted on
				 * it and the member read "in review" for weeks. Treat it as not
				 * yet checked; the current prompt returns a decision.
				 */
				// Matched as text: meta storage strips backslashes, so many of those
				// old results are no longer valid JSON and json_decode() returns null.
				$undecided = ! $is_error
					&& ! preg_match( '/"(decision|verdict|recommendation)"\s*:\s*"[a-z_]+"/i', (string) $result );

				if ( ! $undecided && ( ! $is_error || $age < 6 * HOUR_IN_SECONDS ) ) {
					continue;
				}
			}
			$out[] = $uid;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/* ---------- process one member ----------
	 * $dry = true: run the model + compute the decision, but write nothing and
	 * email no one. Returns a short status string for logging.
	 */
	public static function process( $uid, $dry ) {
		if ( ! self::has_engine() ) {
			return 'no_engine';
		}
		$res = CaVerify::run_ai( $uid );

		if ( ! is_array( $res ) || empty( $res['ok'] ) || empty( $res['verdict'] ) ) {
			$err = ( is_array( $res ) && isset( $res['error'] ) ) ? (string) $res['error'] : 'unknown';

			/*
			 * A file type the model cannot read is a decision, not an error:
			 * retrying a .docx forever left the member "in review" for weeks.
			 * Ask for a PDF or image instead.
			 */
			if ( 0 === strpos( $err, 'Unsupported format' ) ) {
				if ( $dry ) {
					return 'dry:reject:format';
				}
				update_user_meta( $uid, 'csm_av_result', 'AI error: ' . $err );
				update_user_meta( $uid, 'csm_av_time', time() );
				CaVerify::reject( $uid, 'format', 0 );
				return 'rejected:format';
			}

			if ( $dry ) {
				return 'dry:error:' . $err;
			}
			update_user_meta( $uid, 'csm_av_result', 'AI error: ' . $err );
			update_user_meta( $uid, 'csm_av_time', time() );

			/*
			 * Transport errors are retried (pending_ids, after six hours) — but
			 * not for ever. After three, a person takes it, and the member is
			 * told that rather than reading "in review" indefinitely.
			 */
			$n = (int) get_user_meta( $uid, 'csm_av_attempts', true ) + 1;
			update_user_meta( $uid, 'csm_av_attempts', $n );
			if ( $n >= 3 ) {
				update_user_meta( $uid, 'csm_av_status', 'review' );
				return 'held:errors';
			}
			return 'error';
		}

		$verdict = $res['verdict'];
		$rec     = is_array( $verdict ) ? (string) ( isset( $verdict['decision'] ) ? $verdict['decision'] : ( isset( $verdict['verdict'] ) ? $verdict['verdict'] : ( isset( $verdict['recommendation'] ) ? $verdict['recommendation'] : '' ) ) ) : '';

		if ( $dry ) {
			return 'dry:' . ( '' === $rec ? 'unknown' : $rec );
		}

		/* persist exactly like the Run-AI-check button handler does */
		update_user_meta( $uid, 'csm_av_result', wp_json_encode( $verdict ) );
		update_user_meta( $uid, 'csm_av_time', time() );

		if ( 'verify' === $rec ) {
			update_user_meta( $uid, 'csm_av_status', 'approved' ); /* triggers the verified-email hook */
			update_user_meta( $uid, 'csm_av_auto', 1 );
			update_user_meta( $uid, 'csm_av_decided_by', 0 );
			update_user_meta( $uid, 'csm_av_decided_at', time() );
			return 'approved';
		}

		/*
		 * The two non-verify outcomes used to be treated the same: leave the
		 * row untouched for the owner. That meant a member whose document the
		 * model had clearly rejected saw "being checked, usually takes a day"
		 * indefinitely, and was told nothing. Owner, 2026-09-19: "there should
		 * be a proper email system and even on the profile screen it needs to
		 * be clear what exactly is the issue."
		 *
		 * A clear "reject" is now acted on: status, a reason the member can act
		 * on, and an email saying so. The reviewer can still overturn it from
		 * the queue, and a re-upload restarts the review. "manual_review" stays
		 * a person's call -- but the member is told THAT, rather than left
		 * reading a promise of a day.
		 */
		if ( 'reject' === $rec ) {
			$code = is_array( $verdict ) && isset( $verdict['reason_code'] ) ? sanitize_key( (string) $verdict['reason_code'] ) : '';
			if ( '' === $code || ! array_key_exists( $code, CaVerify::reasons() ) ) {
				$code = self::guess_reason( is_array( $verdict ) && isset( $verdict['reason'] ) ? (string) $verdict['reason'] : '' );
			}
			CaVerify::reject( $uid, $code, 0 );
			return 'rejected:' . $code;
		}

		update_user_meta( $uid, 'csm_av_status', 'review' );
		return 'held:' . $rec;
	}

	/**
	 * Map the model's prose reason onto a member-facing key, for models that
	 * ignore the reason_code instruction. Keyword order matters: "not an ICAI
	 * document" beats "unclear" if both appear, because the fix is different.
	 */
	private static function guess_reason( $text ) {
		$t = strtolower( (string) $text );
		if ( '' === $t ) {
			return 'other';
		}
		$map = array(
			'not_icai'    => array( 'not an icai', 'not icai', 'not a ca', 'unrelated', 'different document', 'not a certificate' ),
			'wrong_level' => array( 'intermediate', 'ipcc', 'level', 'final', 'membership number', 'does not support' ),
			'name'        => array( 'name' ),
			'incomplete'  => array( 'cut off', 'cropped', 'partial', 'incomplete', 'missing part' ),
			'expired'     => array( 'expired', 'old', 'outdated', 'not current' ),
			'unclear'     => array( 'blur', 'unclear', 'illegible', 'low resolution', 'cannot read', 'unreadable', 'poor quality' ),
		);
		foreach ( $map as $key => $needles ) {
			foreach ( $needles as $n ) {
				if ( false !== strpos( $t, $n ) ) {
					return $key;
				}
			}
		}
		return 'other';
	}

	/* ---------- the sweep the cron fires ---------- */
	public static function sweep() {
		$ids = self::pending_ids( self::batch() );
		foreach ( $ids as $uid ) {
			self::process( $uid, false );
		}

		/*
		 * Always hourly. Dropping to daily when the queue looked clear meant a
		 * new upload could wait a day; an hourly look at an empty queue costs
		 * one query. (New uploads also get their own run a minute after upload
		 * — see CaVerify::on_doc_changed.)
		 */
		self::reschedule( 'hourly' );
	}

	/* ---------- scheduling ---------- */
	public static function reschedule( $recurrence ) {
		if ( 'hourly' !== $recurrence && 'daily' !== $recurrence ) {
			$recurrence = 'daily';
		}
		if ( wp_get_schedule( self::HOOK ) === $recurrence ) {
			return;
		}
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_schedule_event( time() + 300, $recurrence, self::HOOK );
	}

	/* ---------- verified email ---------- */
	public static function email_html( $name, $login ) {
		$name  = esc_html( $name );
		$login = esc_url( $login );
		return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.55">'
			. '<h2 style="color:#1d9bf0;margin:0 0 12px">You are verified &#10003;</h2>'
			. '<p>Hi ' . $name . ',</p>'
			. '<p>Good news — your Chartered Accountant credentials have been verified on <strong>CA Shaadi</strong>. '
			. 'Your profile now carries the blue <strong>Verified CA</strong> badge, which helps you stand out and builds trust with potential matches.</p>'
			. '<p style="margin:22px 0"><a href="' . $login . '" style="display:inline-block;background:#1d9bf0;color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Go to CA Shaadi</a></p>'
			. '<p style="color:#888;font-size:12px;margin-top:26px">You are receiving this because your profile on CA Shaadi was verified. If this was not you, please ignore this email.</p>'
			. '</div>';
	}

	public static function send_verified_email( $uid ) {
		$user = get_userdata( $uid );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$name    = $user->display_name ? $user->display_name : $user->user_login;
		$subject = 'Your CA credentials are verified — CA Shaadi';
		$body    = self::email_html( $name, home_url( '/' ) );
		// Queued: a verification outcome is a notification, not a login-critical
		// transactional mail, so it belongs under the master switch. One per
		// member — the unique key stops a re-run mailing them twice.
		if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Queue' ) ) {
			return \CAShaadi\Modules\Emails\Queue::notify( $uid, 'csm-ca-verified', $subject, $body );
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $user->user_email, $subject, $body, $headers );
	}

	/* fire once, the moment csm_av_status becomes 'approved' (any path) */
	public static function maybe_email_on_status( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( 'csm_av_status' !== $meta_key ) {
			return;
		}
		if ( 'approved' !== $meta_value ) {
			return;
		}
		if ( get_user_meta( $object_id, 'csm_avc_verified_email_sent', true ) ) {
			return;
		}
		update_user_meta( $object_id, 'csm_avc_verified_email_sent', 1 ); /* set before send: no double fire */
		self::send_verified_email( $object_id );
	}

	/* ---------- one-time guard backfill ----------
	 * Mark everyone ALREADY approved as already-notified, so switching this on can
	 * never retro-email the existing verified members. Runs once (option-gated) on
	 * admin load.
	 */
	public static function backfill_guard() {
		if ( get_option( 'csm_avc_guard_backfilled' ) ) {
			return;
		}
		if ( ! method_exists( __NAMESPACE__ . '\\CaVerify', 'members_with_docs' ) ) {
			return;
		}
		$rows = CaVerify::members_with_docs( 5000 );
		foreach ( (array) $rows as $r ) {
			$uid = self::uid( $r );
			if ( ! $uid ) {
				continue;
			}
			if ( 'approved' === get_user_meta( $uid, 'csm_av_status', true ) ) {
				update_user_meta( $uid, 'csm_avc_verified_email_sent', 1 );
			}
		}
		update_option( 'csm_avc_guard_backfilled', 1 );
	}

	/* ---------- admin-only diagnostics (explicit query flag + manage_options) ---------- */
	public static function admin_tools() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_GET['csm_avc_test_email'] ) ) {
			$u  = wp_get_current_user();
			$ok = self::send_verified_email( $u->ID );
			wp_die( 'csm_avc test email to ' . esc_html( $u->user_email ) . ' => ' . ( $ok ? 'SENT (wp_mail true)' : 'FAILED (wp_mail false)' ) );
		}

		if ( ! empty( $_GET['csm_avc_dryrun'] ) ) {
			$ids   = self::pending_ids( 1 );
			$lines = array();
			foreach ( $ids as $uid ) {
				$lines[] = $uid . ' => ' . self::process( $uid, true );
			}
			wp_die( 'csm_avc dryrun (no writes): ' . esc_html( empty( $lines ) ? 'no pending members found' : implode( ' | ', $lines ) ) );
		}

		if ( ! empty( $_GET['csm_avc_runnow'] ) ) {
			$ids   = self::pending_ids( self::batch() );
			$lines = array();
			foreach ( $ids as $uid ) {
				$lines[] = $uid . ' => ' . self::process( $uid, false );
			}
			wp_die( 'csm_avc runnow (LIVE): ' . esc_html( empty( $lines ) ? 'no pending members found' : implode( ' | ', $lines ) ) );
		}

		if ( ! empty( $_GET['csm_avc_status'] ) ) {
			$pending = count( self::pending_ids( 100000 ) );
			$sched   = wp_get_schedule( self::HOOK );
			$next    = wp_next_scheduled( self::HOOK );
			wp_die( 'csm_avc status: pending=' . $pending . ' schedule=' . ( $sched ? $sched : 'none' )
				. ' next=' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'n/a' )
				. ' guard_backfilled=' . ( get_option( 'csm_avc_guard_backfilled' ) ? 'yes' : 'no' ) );
		}
	}
}
