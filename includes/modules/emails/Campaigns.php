<?php
/**
 * Campaigns — a one-off mailing, fired by hand.
 *
 * WHY THIS EXISTS. The queue already had two controls: a master switch and a
 * daily cap. Neither answers the owner's actual question, which is "not yet,
 * and not all of them": "We will be slowly firing the emails in production but
 * I want a manual control to decide when to fire them ... We do have lot of
 * pending emails so I'll sequence them at my pace."
 *
 * So a campaign is written to the queue with status 'held', which due_rows()
 * does not select. The rows exist, can be counted, and one can be read back
 * exactly as the member would receive it — but nothing moves until somebody
 * presses Release on this screen, and then only as many as they typed.
 *
 * This is a gate IN FRONT OF the existing controls, never a way round them.
 * Released rows still meet the master switch, the daily cap and the hourly cap,
 * so releasing 163 does not send 163 at once.
 */

namespace CAShaadi\Modules\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Campaigns {

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_csm_campaign', array( __CLASS__, 'handle' ) );
	}

	/** The campaigns this screen knows how to stage. */
	private static function all() {
		return array(
			Engagement::PHOTO_QUALITY_TYPE => array(
				'title' => 'Low-quality photo — ask for a re-upload',
				'why'   => 'Members whose stored photo is under 300px across, so it renders soft on a card. '
					. 'Their original was deleted at upload and cannot be recovered, so only they can fix it.',
				'stage' => array( '\CAShaadi\Modules\Emails\Engagement', 'stage_photo_quality' ),
				'size'  => array( '\CAShaadi\Modules\Emails\Engagement', 'photo_quality_audience' ),
			),
		);
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['csm-sales-dashboard'] ) ? 'csm-sales-dashboard' : 'tools.php';
		add_submenu_page(
			$parent,
			'Email campaigns',
			'Email campaigns',
			'manage_options',
			'csm-campaigns',
			array( __CLASS__, 'render' )
		);
	}

	/* ------------------------------------------------------------- actions */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'csm_campaign' );

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$all  = self::all();
		$msg  = '';

		if ( ! isset( $all[ $type ] ) ) {
			wp_safe_redirect( add_query_arg( 'csm_msg', rawurlencode( 'Unknown campaign.' ), self::url() ) );
			exit;
		}

		if ( 'stage' === $do ) {
			$r   = call_user_func( $all[ $type ]['stage'] );
			$msg = sprintf(
				'Staged %d of %d. %d skipped (already staged, opted out, or no address). Nothing has been sent.',
				(int) $r['staged'],
				(int) $r['audience'],
				(int) $r['skipped']
			);
		} elseif ( 'release' === $do ) {
			$n = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;
			$n = min( $n, 500 );
			$released = Queue::release( $type, $n );
			$msg = $released
				? sprintf( 'Released %d. They now go out through the normal queue, subject to the master switch and the daily cap.', $released )
				: 'Nothing to release — none are on hold.';
		} elseif ( 'hold' === $do ) {
			$held = Queue::unrelease( $type );
			$msg  = sprintf( 'Put %d back on hold. Anything already sent cannot be recalled.', $held );
		}

		wp_safe_redirect( add_query_arg( 'csm_msg', rawurlencode( $msg ), self::url() ) );
		exit;
	}

	private static function url() {
		return admin_url( 'admin.php?page=csm-campaigns' );
	}

	/* -------------------------------------------------------------- render */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Email campaigns</h1>';

		if ( ! empty( $_GET['csm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['csm_msg'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$master = Queue::master_on();
		echo '<p>' . ( $master
			? '<strong style="color:#a00">Sending is LIVE.</strong> Anything you release will go out.'
			: '<strong>Sending is paused</strong> (csm_remail_master = 0). Releasing marks rows ready; they wait for the master switch.'
		) . '</p>';

		foreach ( self::all() as $type => $c ) {
			$counts = Queue::type_counts( $type );
			$staged = array_sum( $counts );

			echo '<div class="card" style="max-width:820px;padding:16px 20px;margin:18px 0">';
			echo '<h2 style="margin-top:0">' . esc_html( $c['title'] ) . '</h2>';
			echo '<p style="color:#555">' . esc_html( $c['why'] ) . '</p>';

			if ( ! $staged ) {
				// Count the audience only when nothing is staged: it walks the
				// avatar directories, and there is no reason to do that on every
				// page load once the campaign exists.
				$size = count( call_user_func( $c['size'] ) );
				echo '<p><strong>' . (int) $size . '</strong> members currently match. Nothing staged yet.</p>';
				self::button( $type, 'stage', 'Stage the campaign (writes held rows, sends nothing)' );
			} else {
				printf(
					'<p>On hold: <strong>%d</strong> &nbsp;|&nbsp; released and waiting: <strong>%d</strong> &nbsp;|&nbsp; sent: <strong>%d</strong> &nbsp;|&nbsp; failed: %d &nbsp;|&nbsp; cancelled: %d</p>',
					(int) $counts['held'],
					(int) $counts['pending'],
					(int) $counts['sent'],
					(int) $counts['failed'],
					(int) $counts['cancelled']
				);

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
				wp_nonce_field( 'csm_campaign' );
				echo '<input type="hidden" name="action" value="csm_campaign">';
				echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
				echo '<input type="hidden" name="do" value="release">';
				echo '<label>Release <input type="number" name="count" value="25" min="1" max="500" style="width:80px"> now</label> ';
				echo '<button class="button button-primary">Release</button>';
				echo '</form>';

				self::button( $type, 'hold', 'Put released-but-unsent back on hold' );
				self::button( $type, 'stage', 'Re-scan and stage any new matches' );

				$sample = Queue::sample( $type );
				if ( $sample ) {
					echo '<h3>What they receive</h3>';
					echo '<p><strong>Subject:</strong> ' . esc_html( $sample->subject ) . '<br>';
					echo '<strong>First recipient:</strong> ' . esc_html( $sample->user_email ) . '</p>';
					/*
					 * The stored body, rendered. It is our own markup from
					 * Engagement::wrap() and it is what will actually be sent —
					 * escaping it here would preview something other than the
					 * email, which defeats the point of a preview.
					 */
					echo '<div style="border:1px solid #ccd0d4;background:#fff;padding:14px;max-width:560px">' . wp_kses_post( $sample->body ) . '</div>';
				}
			}

			echo '</div>';
		}

		echo '</div>';
	}

	private static function button( $type, $do, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'csm_campaign' );
		echo '<input type="hidden" name="action" value="csm_campaign">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<button class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}
