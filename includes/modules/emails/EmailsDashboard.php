<?php
/**
 * One Emails page (owner, 2026-10-30: "the emails dashboards are very
 * complicated and not useful, have a common dashboard that is genuinely
 * useful").
 *
 * Four answers, in this order:
 *   1. Is email working? Sending on or off, the provider, sent today against
 *      the daily cap, failures in the last 24 hours, waiting in the queue.
 *   2. How is each email doing? Last 30 days, one row per kind of email:
 *      sent, opened, clicked, unsubscribed, failed. Clicks are the number to
 *      trust (opens are blocked or faked by some mail apps).
 *   3. Campaigns: what is staged, and Stage / Preview / Release in one place
 *      (the actions are Campaigns' own handler, which returns here).
 *   4. Recent problems: the last failures and why.
 *
 * The three older screens (Reminder Emails, Email delivery, Email campaigns)
 * leave the menu but still open from the links at the bottom, for settings.
 */

namespace CAShaadi\Modules\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmailsDashboard {

	const SLUG = 'csm-emails';

	/** Friendly names, by the type's prefix (dated types end in -<date or id>). */
	const NAMES = array(
		'csm-activation-reminder-1' => 'Activation reminder 1',
		'csm-activation-reminder-2' => 'Activation reminder 2',
		'csm-completion-reminder-1' => 'Profile completion reminder 1',
		'csm-completion-reminder-2' => 'Profile completion reminder 2',
		'csm-liked'          => 'Someone liked you',
		'csm-match'          => 'You have a match',
		'csm-batch'          => 'New weekly profiles',
		'csm-expiring'       => 'Profiles expiring',
		'csm-nudge'          => 'Come back reminder',
		'csm-winback'        => 'Win-back',
		'csm-viewed'         => 'Someone viewed your profile',
		'csm-viewed-digest'  => 'Profile views digest',
		'csm-reqwait'        => 'Requests waiting',
		'csm-reach'          => 'Low reach tips',
		'csm-photohold'      => 'Photo on hold',
		'csm-photorelease'   => 'Photo approved after hold',
		'csm-photo-req'      => 'Photo requested',
		'csm-photo-ok'       => 'Photo approved',
		'csm-photo-mod'      => 'Photo moderation',
		'csm-photoqual'      => 'Low-quality photo (campaign)',
		'csm-blur'           => 'Blurred photos',
		'csm-ca-verify'      => 'Verify your CA status',
		'csm-ca-verified'    => 'CA verified',
		'csm-phone-notice'   => 'Phone number notice',
		'csm-intent-leads'   => 'Sales leads',
		'csm-v2launch'       => 'Launch announcement (campaign)',
		'csm-price-nov26'    => 'Premium price notice (campaign)',
	);

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['csm-sales-dashboard'] ) ? 'csm-sales-dashboard' : 'tools.php';
		add_submenu_page( $parent, 'Emails', 'Emails', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		// The older screens stay reachable by link, not in the menu.
		foreach ( array( 'csm-reminder-emails', 'csm-email-delivery', 'csm-campaigns' ) as $old ) {
			remove_submenu_page( $parent, $old );
		}
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** csm-liked-2026-10-30 → csm-liked; fixed names kept as they are. */
	public static function group( $type ) {
		if ( isset( self::NAMES[ $type ] ) ) {
			return $type;
		}
		$g = preg_replace( '/-[0-9][0-9a-z-]*$/', '', (string) $type );
		return '' !== $g ? $g : (string) $type;
	}

	public static function name( $group ) {
		return self::NAMES[ $group ] ?? $group;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$t     = Queue::table();
		$today = wp_date( 'Y-m-d' ) . ' 00:00:00';
		$day   = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$month = wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$box   = 'background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 16px;margin:16px 0;max-width:980px';
		$cell  = 'padding:6px 10px;text-align:right';

		echo '<div class="wrap"><h1>Emails</h1>';
		if ( ! empty( $_GET['csm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['csm_msg'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// 1. Is email working?
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'sent' AND processed_at >= %s", $today ) );
		$failed_24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'failed' AND processed_at >= %s", $day ) );
		$waiting    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ( 'pending', 'deferred', 'waiting' )" );
		$held       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'held'" );
		// phpcs:enable
		$master   = Queue::master_on();
		$dry      = Queue::dry_run();
		$provider = class_exists( __NAMESPACE__ . '\\Zepto' ) && Zepto::configured() && ! Zepto::brevo_active();
		// A few bounces to bad addresses are normal; many at once is not.
		$ok       = $master && ! $dry && $provider && $failed_24 <= max( 3, (int) ( 0.1 * $sent_today ) );
		$tiles    = array(
			array( 'Sending', $master ? ( $dry ? 'Test mode' : 'On' ) : 'Paused', $master && ! $dry ? '#1a7f37' : '#b3261e' ),
			array( 'Provider', $provider ? 'ZeptoMail OK' : 'Not set up', $provider ? '#1a7f37' : '#b3261e' ),
			array( 'Sent today', $sent_today . ' of ' . Queue::daily_cap(), '#16212b' ),
			array( 'Failed, last 24 h', (string) $failed_24, $failed_24 ? '#b3261e' : '#1a7f37' ),
			array( 'Waiting to send', (string) $waiting, '#16212b' ),
			array( 'On hold (campaigns)', (string) $held, '#16212b' ),
		);
		echo '<div style="' . $box . '"><h2 style="margin:0 0 10px">' . ( $ok ? '✅ Email is working' : '⚠️ Email needs attention' ) . '</h2><div style="display:flex;flex-wrap:wrap;gap:10px">';
		foreach ( $tiles as $tile ) {
			echo '<div style="flex:1 1 140px;border:1px solid #e4eaf0;border-radius:8px;padding:10px 12px"><div style="color:#5c6a76;font-size:12px">' . esc_html( $tile[0] ) . '</div><div style="font-size:20px;font-weight:700;color:' . esc_attr( $tile[2] ) . '">' . esc_html( $tile[1] ) . '</div></div>';
		}
		echo '</div></div>';

		// 2. How is each email doing? (last 30 days)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT email_type, SUM( status = 'sent' ) sent, SUM( status = 'failed' ) failed,
			        SUM( status = 'sent' AND opened_at IS NOT NULL ) opened, SUM( status = 'sent' AND clicked_at IS NOT NULL ) clicked,
			        SUM( unsub_at IS NOT NULL ) unsub, MAX( processed_at ) last
			 FROM {$t} WHERE processed_at >= %s GROUP BY email_type",
			$month
		) );
		$by = array();
		foreach ( (array) $rows as $r ) {
			$g = self::group( $r->email_type );
			if ( ! isset( $by[ $g ] ) ) {
				$by[ $g ] = array( 'sent' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0, 'unsub' => 0, 'last' => '' );
			}
			foreach ( array( 'sent', 'failed', 'opened', 'clicked', 'unsub' ) as $k ) {
				$by[ $g ][ $k ] += (int) $r->$k;
			}
			$by[ $g ]['last'] = max( $by[ $g ]['last'], (string) $r->last );
		}
		uasort( $by, function ( $a, $b ) { return $b['sent'] <=> $a['sent']; } );
		$pct = function ( $n, $of ) {
			return $of ? (int) $n . ' <span style="color:#666">(' . round( 100 * $n / $of ) . '%)</span>' : (int) $n;
		};
		echo '<div style="' . $box . '"><h2 style="margin:0 0 4px">How each email is doing</h2><p style="margin:0 0 10px;color:#5c6a76">Last 30 days. Clicks are the number to trust: some mail apps block opens, others report opens nobody made.</p>';
		if ( ! $by ) {
			echo '<p>Nothing sent in the last 30 days.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Email</th><th style="' . $cell . '">Sent</th><th style="' . $cell . '">Opened</th><th style="' . $cell . '">Clicked</th><th style="' . $cell . '">Unsubscribed</th><th style="' . $cell . '">Failed</th><th style="' . $cell . '">Last sent</th></tr></thead><tbody>';
			foreach ( $by as $g => $s ) {
				echo '<tr><td>' . esc_html( self::name( $g ) ) . '</td>'
					. '<td style="' . $cell . '">' . (int) $s['sent'] . '</td>'
					. '<td style="' . $cell . '">' . $pct( $s['opened'], $s['sent'] ) . '</td>'
					. '<td style="' . $cell . '"><strong>' . $pct( $s['clicked'], $s['sent'] ) . '</strong></td>'
					. '<td style="' . $cell . '">' . $pct( $s['unsub'], $s['sent'] ) . '</td>'
					. '<td style="' . $cell . ( $s['failed'] ? ';color:#b3261e' : '' ) . '">' . (int) $s['failed'] . '</td>'
					. '<td style="' . $cell . '">' . esc_html( $s['last'] ? human_time_diff( strtotime( $s['last'] ), current_time( 'timestamp' ) ) . ' ago' : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// 3. Campaigns.
		echo '<div style="' . $box . '"><h2 style="margin:0 0 4px">Campaigns</h2><p style="margin:0 0 10px;color:#5c6a76">One-off mailings. Stage writes them without sending; Release sends that many, within the daily and hourly limits.</p>';
		foreach ( Campaigns::all() as $type => $c ) {
			$n      = Queue::type_counts( $type );
			$staged = array_sum( $n );
			echo '<div style="border-top:1px solid #eee;padding:10px 0"><strong>' . esc_html( $c['title'] ) . '</strong><br>';
			if ( ! $staged ) {
				echo '<span style="color:#5c6a76">Not staged yet.</span> ';
				self::button( $type, 'stage', 'Stage' );
			} else {
				$st = Tracking::stats( $type );
				printf(
					'<span>On hold <b>%d</b> · waiting <b>%d</b> · sent <b>%d</b> · clicked <b>%d</b> · failed %d</span><br>',
					(int) $n['held'], (int) $n['pending'], (int) $n['sent'], (int) $st['clicked'], (int) $n['failed']
				);
				if ( $n['held'] ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:6px 10px 0 0">';
					wp_nonce_field( 'csm_campaign' );
					echo '<input type="hidden" name="action" value="csm_campaign"><input type="hidden" name="back" value="emails">';
					echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="do" value="release">';
					echo 'Send <input type="number" name="count" value="' . (int) min( 500, $n['held'] ) . '" min="1" max="500" style="width:80px"> ';
					echo '<button class="button button-primary">Release</button></form>';
				}
				self::button( $type, 'stage', 'Add new members who now match' );
				$sample = Queue::sample( $type );
				if ( $sample ) {
					echo '<details style="margin-top:8px"><summary style="cursor:pointer">Preview: ' . esc_html( $sample->subject ) . '</summary>'
						. '<div style="border:1px solid #ccd0d4;background:#fff;padding:14px;max-width:560px;margin-top:8px">' . wp_kses_post( $sample->body ) . '</div></details>';
				}
			}
			echo '</div>';
		}
		echo '</div>';

		// 4. Recent problems.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fails = $wpdb->get_results( $wpdb->prepare( "SELECT email_type, user_email, note, processed_at FROM {$t} WHERE status = 'failed' AND processed_at >= %s ORDER BY processed_at DESC LIMIT 10", wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		echo '<div style="' . $box . '"><h2 style="margin:0 0 8px">Problems, last 7 days</h2>';
		if ( ! $fails ) {
			echo '<p style="margin:0;color:#1a7f37">None.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>Email</th><th>To</th><th>Why</th></tr></thead><tbody>';
			foreach ( $fails as $f ) {
				echo '<tr><td>' . esc_html( human_time_diff( strtotime( $f->processed_at ), current_time( 'timestamp' ) ) ) . ' ago</td><td>' . esc_html( self::name( self::group( $f->email_type ) ) ) . '</td><td>' . esc_html( $f->user_email ) . '</td><td>' . esc_html( $f->note ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<p style="color:#5c6a76">Settings and tools: <a href="' . esc_url( admin_url( 'admin.php?page=csm-reminder-emails' ) ) . '">sending switch, limits and the queue</a> · <a href="' . esc_url( admin_url( 'admin.php?page=csm-email-delivery' ) ) . '">provider and test email</a> · <a href="' . esc_url( admin_url( 'admin.php?page=csm-campaigns' ) ) . '">campaigns in detail</a></p>';
		echo '</div>';
	}

	private static function button( $type, $do, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:6px 10px 0 0">';
		wp_nonce_field( 'csm_campaign' );
		echo '<input type="hidden" name="action" value="csm_campaign"><input type="hidden" name="back" value="emails">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<button class="button">' . esc_html( $label ) . '</button></form>';
	}
}
