<?php
/**
 * Admin: Referrals & cash. Under the Sales Dashboard menu.
 *
 * Read the ledger, see who is referring whom, spot fake-account clusters
 * (several referred accounts from one IP), and reverse a credit. A reversal is
 * a new negative row — the original is never edited — and can only happen once
 * per entry (the ledger's unique key).
 */

namespace CAShaadi\Modules\Referral;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReferralAdmin {

	const SLUG = 'csm-referrals';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_csm_cash_reverse', array( __CLASS__, 'reverse' ) );
	}

	public static function menu() {
		add_submenu_page( 'csm-sales-dashboard', 'Referrals & cash', 'Referrals & cash', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function reverse() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		check_admin_referer( 'csm_cash_reverse_' . $id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Referral::table() . ' WHERE id = %d', $id ) );
		if ( $e && in_array( $e->kind, array( 'referral', 'welcome' ), true ) && (int) $e->amount > 0 ) {
			Referral::post( (int) $e->user_id, - (int) $e->amount, 'reversal', (int) $e->ref_user_id, (int) $e->id, 'Reversed entry #' . (int) $e->id . ' by user #' . get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&reversed=' . $id ) );
		exit;
	}

	private static function who( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid ) {
			return '—';
		}
		$u = get_userdata( $uid );
		$n = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : ( $u ? $u->display_name : '' );
		return sprintf( '<a href="%s">%s</a> <span style="color:#888">#%d</span>', esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) ), esc_html( $n ? $n : 'deleted' ), $uid );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$t = Referral::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$t}" );
		$given = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE kind IN ('referral','welcome')" );
		$spent = - (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE kind IN ('redeem','restore')" );
		$refs  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '" . Referral::BY_META . "'" );
		$top   = $wpdb->get_results( "SELECT user_id, COUNT(*) n, SUM(amount) amt FROM {$t} WHERE kind = 'referral' GROUP BY user_id ORDER BY n DESC LIMIT 10" );
		$rows  = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 200" );
		$rev   = array();
		foreach ( (array) $wpdb->get_col( "SELECT order_id FROM {$t} WHERE kind = 'reversal'" ) as $oid ) {
			$rev[ (int) $oid ] = true;
		}
		// phpcs:enable

		echo '<div class="wrap"><h1>Referrals &amp; CA Shaadi cash</h1>';
		if ( isset( $_GET['reversed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Entry #' . absint( $_GET['reversed'] ) . ' reversed.</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		printf(
			'<p style="font-size:14px">Accounts that joined through a link: <strong>%d</strong> &nbsp;·&nbsp; Cash given: <strong>₹%s</strong> &nbsp;·&nbsp; Spent: <strong>₹%s</strong> &nbsp;·&nbsp; Outstanding across all members: <strong>₹%s</strong></p>',
			$refs,
			esc_html( number_format_i18n( $given ) ),
			esc_html( number_format_i18n( $spent ) ),
			esc_html( number_format_i18n( $out ) )
		);

		echo '<h2>Top referrers</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>Member</th><th>Completed referrals</th><th>Earned</th></tr></thead><tbody>';
		if ( ! $top ) {
			echo '<tr><td colspan="3">None yet.</td></tr>';
		}
		foreach ( (array) $top as $r ) {
			printf( '<tr><td>%s</td><td>%d</td><td>₹%s</td></tr>', self::who( $r->user_id ), (int) $r->n, esc_html( number_format_i18n( (int) $r->amt ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px">Ledger (latest 200)</h2>';
		echo '<p style="color:#666">For referral credits, the IP is the new member\'s at signup. Several referrals from one IP for the same referrer is the pattern of fake accounts.</p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>When</th><th>Member</th><th>Kind</th><th>Amount</th><th>Related</th><th>Signup IP</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">No entries yet.</td></tr>';
		}
		foreach ( (array) $rows as $e ) {
			$related = (int) $e->order_id && in_array( $e->kind, array( 'redeem', 'restore' ), true )
				? sprintf( '<a href="%s">Order #%d</a>', esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $e->order_id ) ), (int) $e->order_id )
				: self::who( $e->ref_user_id );
			$ip = 'referral' === $e->kind ? (string) get_user_meta( (int) $e->ref_user_id, Referral::IP_META, true ) : '';
			$action = '';
			if ( in_array( $e->kind, array( 'referral', 'welcome' ), true ) ) {
				$action = isset( $rev[ (int) $e->id ] )
					? '<em>reversed</em>'
					: sprintf(
						'<form method="post" action="%s" onsubmit="return confirm(\'Reverse this credit?\')"><input type="hidden" name="action" value="csm_cash_reverse"><input type="hidden" name="entry" value="%d">%s<button class="button button-small">Reverse</button></form>',
						esc_url( admin_url( 'admin-post.php' ) ),
						(int) $e->id,
						wp_nonce_field( 'csm_cash_reverse_' . (int) $e->id, '_wpnonce', true, false )
					);
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td style="color:%s;font-weight:600">%s₹%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				(int) $e->id,
				esc_html( get_date_from_gmt( $e->created_at, 'j M Y H:i' ) ),
				self::who( $e->user_id ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( $e->kind ),
				(int) $e->amount < 0 ? '#b3261e' : '#137333',
				(int) $e->amount < 0 ? '−' : '+',
				esc_html( number_format_i18n( abs( (int) $e->amount ) ) ),
				$related, // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( $ip ),
				$action // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table></div>';
	}
}
