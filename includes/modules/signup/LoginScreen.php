<?php
/**
 * The WordPress login, lost-password and reset-password screens, in CA
 * Shaadi's look (owner, 2026-10-31), and a record of password resets so we
 * can tell whether they work.
 *
 * Look: only CSS on wp-login.php (login_enqueue_scripts). The forms, their
 * fields and their security checks stay WordPress's own; the logo already
 * set on the site is kept.
 *
 * Record (event log): pw_reset_requested when a reset email is asked for,
 * pw_reset_done when a new password is set through the link. Before this
 * (v1.90.89) nothing was recorded, so `wp csm resets` can also read the reset
 * links WordPress still holds (requested, never used) for the days before.
 */

namespace CAShaadi\Modules\Signup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LoginScreen {

	public static function register() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'css' ), 99 );
		add_filter( 'login_headerurl', function () { return home_url( '/' ); } );
		add_action( 'retrieve_password_key', array( __CLASS__, 'on_requested' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_done' ), 10, 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm resets', array( __CLASS__, 'cli' ), array(
				'synopsis' => array( array( 'type' => 'assoc', 'name' => 'days', 'optional' => true ) ),
			) );
		}
	}

	public static function on_requested( $user_login, $key ) {
		$u = get_user_by( 'login', $user_login );
		if ( $u && function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'pw_reset_requested', (int) $u->ID, 0, array() );
		}
	}

	public static function on_done( $user ) {
		if ( $user instanceof \WP_User && function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'pw_reset_done', (int) $user->ID, 0, array() );
		}
	}

	public static function css() {
		?>
<style>
body.login{background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Arial,sans-serif;color:#16212b}
.login #login{width:360px;max-width:calc(100% - 32px);padding:6vh 0 24px}
.login form{border:1px solid #e4eaf0;border-radius:16px;box-shadow:0 6px 24px rgba(11,35,80,.06);padding:24px 22px;margin-top:16px}
.login label{font-size:14px;font-weight:600;color:#16212b}
.login form .input,.login input[type=text],.login input[type=password],.login input[type=email]{border:1px solid #c9d2dc;border-radius:10px;padding:8px 12px;font-size:16px;min-height:46px;box-shadow:none;background:#fff}
.login form .input:focus,.login input:focus{border-color:#0b2350;box-shadow:0 0 0 2px rgba(11,35,80,.15);outline:0}
.login .button-primary,.wp-core-ui .button-primary{float:none;display:block;width:100%;min-height:46px;margin-top:8px;border:0;border-radius:999px;background:#0b2350;color:#fff;font-size:16px;font-weight:700;text-shadow:none;box-shadow:none}
.login .button-primary:hover,.login .button-primary:focus{background:#13306b;color:#fff}
.login .forgetmenot{float:none;margin-bottom:6px}
.login .submit{display:block}
.login #nav,.login #backtoblog{text-align:center;margin:14px 0 0;padding:0}
.login #nav a,.login #backtoblog a{color:#0b2350;font-weight:600;text-decoration:none}
.login #nav a:hover,.login #backtoblog a:hover{text-decoration:underline;color:#0b2350}
.login .message,.login .notice,.login #login_error{border:0;border-radius:12px;box-shadow:none;background:#fff;padding:12px 14px;margin:16px 0 0}
.login .message,.login .notice-info{border-left:4px solid #0b2350}
.login #login_error,.login .notice-error{border-left:4px solid #b3261e}
.login .button.wp-hide-pw{min-height:46px;color:#0b2350}
.login .pw-weak{display:block}
.login #language-switcher{display:none}
.login .privacy-policy-page-link{margin-top:18px}
</style>
		<?php
	}

	/**
	 * wp csm resets [--days=30]
	 *
	 * Password resets per day against new sign-ups: asked for, completed
	 * (a new password set through the link), and the completion rate. Days
	 * before v1.90.89 have no record; for those, the reset links WordPress
	 * still holds (asked for and never used) are counted instead.
	 */
	public static function cli( $args, $assoc ) {
		global $wpdb;
		$days = max( 1, (int) ( $assoc['days'] ?? 30 ) );
		$from = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$ev   = $wpdb->prefix . 'csm_event_log';
		$rows = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, event_type t, COUNT(DISTINCT actor_id) n FROM {$ev} WHERE event_type IN ('pw_reset_requested','pw_reset_done') AND created_at >= %s GROUP BY d, t", $from . ' 00:00:00' ) ) as $r ) {
			$rows[ $r->d ][ $r->t ] = (int) $r->n;
		}
		// Unused reset links WordPress still holds: "<unix time>:<hash>".
		$unused = array();
		foreach ( (array) $wpdb->get_col( "SELECT user_activation_key FROM {$wpdb->users} WHERE user_activation_key LIKE '%:%'" ) as $k ) {
			$ts = (int) strtok( (string) $k, ':' );
			if ( $ts > strtotime( $from . ' 00:00:00' ) ) {
				$d            = wp_date( 'Y-m-d', $ts );
				$unused[ $d ] = ( $unused[ $d ] ?? 0 ) + 1;
			}
		}
		$signups = array();
		$ft      = $wpdb->prefix . 'csm_funnel';
		$off     = (int) wp_timezone()->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) d, COUNT(DISTINCT pkey) n FROM {$ft} WHERE step = 'activated' AND created_at >= %s GROUP BY d", $off, get_gmt_from_date( $from . ' 00:00:00' ) ) ) as $r ) {
			$signups[ $r->d ] = (int) $r->n;
		}
		// phpcs:enable
		$out = array();
		$sum = array( 0, 0, 0, 0 );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d   = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
			$req = $rows[ $d ]['pw_reset_requested'] ?? 0;
			$don = $rows[ $d ]['pw_reset_done'] ?? 0;
			$out[] = array(
				'day'                 => $d,
				'new sign-ups'        => $signups[ $d ] ?? 0,
				'resets asked'        => $req,
				'resets completed'    => $don,
				'links never used'    => $unused[ $d ] ?? 0,
			);
			$sum[0] += $signups[ $d ] ?? 0;
			$sum[1] += $req;
			$sum[2] += $don;
			$sum[3] += $unused[ $d ] ?? 0;
		}
		\WP_CLI::log( "Last {$days} days. 'Resets asked/completed' are recorded from v1.90.89; 'links never used' are reset emails asked for whose link was never used (WordPress keeps the latest per member)." );
		\WP_CLI\Utils\format_items( 'table', $out, array_keys( $out[0] ) );
		\WP_CLI::log( sprintf( 'Totals: %d sign-ups · %d resets asked · %d completed (%s) · %d links never used.', $sum[0], $sum[1], $sum[2], $sum[1] ? round( 100 * $sum[2] / $sum[1] ) . '%' : '-', $sum[3] ) );
	}
}
