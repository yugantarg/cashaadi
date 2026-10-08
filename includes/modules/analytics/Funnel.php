<?php
/**
 * Sign-up funnel: every step from opening /register/ to finishing onboarding
 * (owner, 2026-10-08: "build a stepwise tracker that sees where the drop-offs
 * are happening", after code emails failed silently for a night when the
 * ZeptoMail credits ran out).
 *
 * One row per step reached, in wp_csm_funnel. A person is counted once per
 * step: by an anonymous browser id (cookie csm_fsid) before the form is sent,
 * by a hash of their email from then on. Steps:
 *
 *   register_view    opened the register page            browser beacon
 *   form_start       typed into the form                 browser beacon
 *   form_submit      sent the form                       bp_signup_validate
 *   validation_error the form was rejected (which fields) bp_signup_validate
 *   signup_created   form accepted, code issued          bp_core_signup_user
 *   code_email_sent / code_email_failed (with the error)  ActivationCode
 *   code_resend      asked for a new code
 *   code_wrong       entered a wrong or expired code
 *   activated        entered the code; account created
 *   welcome_view     opened onboarding (once per member)
 *   welcome_step     answered an onboarding step (once per member per step)
 *   onboarded        finished onboarding
 *
 * Read-only for the product: nothing here changes what a member sees. Kept
 * permanently, like the other analytics tables. The Sales Dashboard shows the
 * funnel and a red alert when code emails have failed in the last 24 hours.
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Funnel {

	const COOKIE = 'csm_fsid';

	/** Steps the browser may report (everything else is recorded server-side). */
	const BEACON_STEPS = array( 'register_view', 'form_start' );

	/** The main path, in order, with dashboard labels. */
	const PATH = array(
		'register_view'   => 'Opened register page',
		'form_start'      => 'Started filling the form',
		'form_submit'     => 'Submitted the form',
		'signup_created'  => 'Form accepted (code issued)',
		'code_email_sent' => 'Code email sent',
		'activated'       => 'Entered code (account created)',
		'welcome_view'    => 'Opened onboarding',
		'onboarded'       => 'Finished onboarding',
	);

	public static function register() {
		Migrator::register( 'funnel', array( __CLASS__, 'schema' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'wp_footer', array( __CLASS__, 'beacon' ), 40 );
		add_action( 'bp_signup_validate', array( __CLASS__, 'on_validate' ), 9999 );
		add_action( 'bp_core_signup_user', array( __CLASS__, 'on_signup' ), 30, 4 );
		add_action( 'csm_code_email', array( __CLASS__, 'on_code_email' ), 10, 4 );
		add_action( 'csm_code_resend', array( __CLASS__, 'on_resend' ) );
		add_action( 'csm_code_attempt', array( __CLASS__, 'on_attempt' ), 10, 4 );
		add_action( 'csm_welcome_view', array( __CLASS__, 'on_welcome_view' ) );
		add_action( 'csm_welcome_step', array( __CLASS__, 'on_welcome_step' ), 10, 2 );
		add_action( 'csm_onboarding_completed', array( __CLASS__, 'on_onboarded' ) );
	}

	public static function schema( $wpdb ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 step VARCHAR(32) NOT NULL,
 pkey VARCHAR(40) NOT NULL DEFAULT '',
 sid VARCHAR(32) NOT NULL DEFAULT '',
 user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 detail VARCHAR(255) NOT NULL DEFAULT '',
 info TEXT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY  (id),
 KEY step_time (step, created_at),
 KEY pkey (pkey),
 KEY user_id (user_id)
) " . $wpdb->get_charset_collate() . ';';
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_funnel';
	}

	/* -------------------------------------------------------------- write */

	public static function email_key( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return '' === $email ? '' : sha1( $email );
	}

	private static function sid() {
		$s = isset( $_COOKIE[ self::COOKIE ] ) ? strtolower( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $s ) ? $s : '';
	}

	public static function record( $step, $pkey = '', $user_id = 0, $detail = '', $info = '' ) {
		global $wpdb;
		$sid  = self::sid();
		$pkey = '' !== $pkey ? $pkey : ( '' !== $sid ? $sid : ( $user_id ? 'u' . (int) $user_id : '' ) );
		if ( '' === $pkey ) {
			return;
		}
		$wpdb->insert( self::table(), array(
			'step'       => substr( (string) $step, 0, 32 ),
			'pkey'       => substr( $pkey, 0, 40 ),
			'sid'        => $sid,
			'user_id'    => (int) $user_id,
			'detail'     => substr( (string) $detail, 0, 255 ),
			'info'       => '' === $info ? null : substr( (string) $info, 0, 2000 ),
			'created_at' => current_time( 'mysql', true ),
		) );
	}

	/** Has this member already reached this step (with this detail)? */
	private static function reached( $uid, $step, $detail = null ) {
		global $wpdb;
		$t = self::table();
		if ( null === $detail ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE user_id = %d AND step = %s LIMIT 1", $uid, $step ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE user_id = %d AND step = %s AND detail = %s LIMIT 1", $uid, $step, $detail ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The person key of a member who signed up after tracking began. */
	private static function member_key( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? self::email_key( $u->user_email ) : '';
	}

	/* ------------------------------------------------------------ browser */

	/**
	 * Tiny inline beacon on the register page. Inline so it survives page
	 * caching; it sets its own anonymous id, and reports each step once per
	 * browser tab session.
	 */
	public static function beacon() {
		if ( is_admin() || ! function_exists( 'bp_is_register_page' ) || ! bp_is_register_page() ) {
			return;
		}
		$url = esc_url_raw( rest_url( 'csm/v1/funnel' ) );
		?>
<script>(function(){try{
var m=document.cookie.match(/(?:^|; )csm_fsid=([a-f0-9]{32})/),s=m&&m[1];
if(!s){s='';for(var i=0;i<32;i++){s+=Math.floor(Math.random()*16).toString(16);}document.cookie='csm_fsid='+s+';path=/;max-age=7776000;samesite=lax'+(location.protocol==='https:'?';secure':'');}
var q=new URLSearchParams(location.search),src=q.get('utm_source')||(document.referrer?(new URL(document.referrer)).hostname:'direct');
function send(step){try{if(sessionStorage.getItem('csm_f_'+step))return;sessionStorage.setItem('csm_f_'+step,'1');}catch(e){}
var b=new URLSearchParams({step:step,sid:s,src:src});if(navigator.sendBeacon){navigator.sendBeacon(<?php echo wp_json_encode( $url ); ?>,b);}else{fetch(<?php echo wp_json_encode( $url ); ?>,{method:'POST',body:b,keepalive:true});}}
if(!document.querySelector('form input[name="signup_email"]'))return;
send('register_view');
document.addEventListener('focusin',function f(e){if(e.target&&e.target.form){send('form_start');document.removeEventListener('focusin',f);}});
}catch(e){}})();</script>
		<?php
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/funnel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_beacon' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function rest_beacon( $request ) {
		$step = (string) $request->get_param( 'step' );
		$sid  = strtolower( (string) $request->get_param( 'sid' ) );
		if ( ! in_array( $step, self::BEACON_STEPS, true ) || ! preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		// A loose per-IP cap so the open endpoint cannot be used to flood the table.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$k  = 'csm_fnb_' . md5( $ip );
		$n  = (int) get_transient( $k );
		if ( $n >= 120 ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		$src = substr( sanitize_text_field( (string) $request->get_param( 'src' ) ), 0, 100 );
		self::record( $step, $sid, 0, $src );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------- server */

	/** The register form was sent; record it, and why it was rejected if it was. */
	public static function on_validate() {
		$email = isset( $_POST['signup_email'] ) ? sanitize_email( wp_unslash( $_POST['signup_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$key   = self::email_key( $email );
		self::record( 'form_submit', $key );
		$errs = function_exists( 'buddypress' ) && isset( buddypress()->signup->errors ) ? (array) buddypress()->signup->errors : array();
		if ( $errs ) {
			$msgs = array();
			foreach ( $errs as $f => $m ) {
				$msgs[] = $f . ': ' . wp_strip_all_tags( is_array( $m ) ? implode( ' ', $m ) : (string) $m );
			}
			self::record( 'validation_error', $key, 0, implode( ',', array_keys( $errs ) ), implode( "\n", $msgs ) );
		}
	}

	public static function on_signup( $user_id, $user_login, $user_password, $user_email ) {
		self::record( 'signup_created', self::email_key( $user_email ) );
	}

	/** From ActivationCode::send_code_email(): $context is 'signup' or 'resend'. */
	public static function on_code_email( $email, $ok, $error, $context ) {
		self::record( $ok ? 'code_email_sent' : 'code_email_failed', self::email_key( $email ), 0, (string) $context, (string) $error );
	}

	public static function on_resend( $email ) {
		self::record( 'code_resend', self::email_key( $email ) );
	}

	/** From ActivationCode::attempt(): $reason is '' on success. */
	public static function on_attempt( $email, $ok, $user_id, $reason ) {
		if ( $ok ) {
			self::record( 'activated', self::email_key( $email ), (int) $user_id );
		} else {
			self::record( 'code_wrong', self::email_key( $email ), 0, (string) $reason );
		}
	}

	public static function on_welcome_view( $uid ) {
		$uid = (int) $uid;
		if ( $uid && ! self::reached( $uid, 'welcome_view' ) ) {
			self::record( 'welcome_view', self::member_key( $uid ), $uid );
		}
	}

	public static function on_welcome_step( $uid, $key ) {
		$uid = (int) $uid;
		if ( $uid && '' !== (string) $key && ! self::reached( $uid, 'welcome_step', (string) $key ) ) {
			self::record( 'welcome_step', self::member_key( $uid ), $uid, (string) $key );
		}
	}

	public static function on_onboarded( $uid ) {
		$uid = (int) $uid;
		if ( $uid && ! self::reached( $uid, 'onboarded' ) ) {
			self::record( 'onboarded', self::member_key( $uid ), $uid );
		}
	}

	/* ---------------------------------------------------------- dashboard */

	/** IST date range → UTC bounds. */
	private static function bounds( $from, $to ) {
		$tz = wp_timezone();
		$u  = new \DateTimeZone( 'UTC' );
		return array(
			( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( $u )->format( 'Y-m-d H:i:s' ),
			( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( $u )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Red banner: code emails (or any mail) failed in the last 24 hours. */
	public static function alert() {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MAX(created_at) last FROM {$t} WHERE step = 'code_email_failed' AND created_at >= %s", $since ) );
		$mail = get_option( 'csm_mail_error_last' );
		$h    = '';
		if ( $row && (int) $row->n > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$err = (string) $wpdb->get_var( "SELECT info FROM {$t} WHERE step = 'code_email_failed' ORDER BY id DESC LIMIT 1" );
			$h  .= sprintf(
				'<strong>%d sign-up code emails failed in the last 24 hours</strong> (last at %s IST). People cannot finish signing up while this lasts. Last error: %s',
				(int) $row->n,
				esc_html( get_date_from_gmt( $row->last, 'j M H:i' ) ),
				'<code>' . esc_html( '' !== $err ? $err : 'wp_mail returned false' ) . '</code>'
			);
		}
		if ( is_array( $mail ) && ! empty( $mail['at'] ) && time() - (int) $mail['at'] < DAY_IN_SECONDS && '' === $h ) {
			$h .= sprintf( '<strong>An email failed to send at %s IST.</strong> <code>%s</code>', esc_html( wp_date( 'j M H:i', (int) $mail['at'] ) ), esc_html( (string) $mail['msg'] ) );
		}
		return '' === $h ? '' : '<div class="notice notice-error" style="padding:10px 12px;margin:15px 0">' . $h . ' Check the ZeptoMail credits first.</div>';
	}

	public static function summary() {
		global $wpdb;
		$t    = self::table();
		$to   = isset( $_GET['fto'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['fto'] ) ? $_GET['fto'] : wp_date( 'Y-m-d' );
		$from = isset( $_GET['ffrom'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['ffrom'] ) ? $_GET['ffrom'] : wp_date( 'Y-m-d', strtotime( '-6 days' ) );
		list( $a, $b ) = self::bounds( $from, $to );

		$counts = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT step, COUNT(DISTINCT pkey) n FROM {$t} WHERE created_at BETWEEN %s AND %s GROUP BY step", $a, $b ) ) as $r ) {
			$counts[ $r->step ] = (int) $r->n;
		}

		$open = isset( $_GET['ffrom'] ) || isset( $_GET['fto'] ) ? ' open' : '';
		$h  = '<details' . $open . ' style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin:15px 0">';
		$h .= '<summary style="cursor:pointer;font-weight:600">Sign-up funnel (where people drop off)</summary>';
		$h .= '<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0">';
		$h .= '<input type="hidden" name="page" value="csm-sales-dashboard">';
		$h .= '<input type="date" name="ffrom" value="' . esc_attr( $from ) . '"> to <input type="date" name="fto" value="' . esc_attr( $to ) . '"> <button class="button">Show</button></form>';
		if ( ! $counts ) {
			return $h . '<p style="margin:0;color:#666">Nothing recorded in this range yet (tracking started with v1.77.0).</p></details>';
		}

		$first = 0;
		$prev  = 0;
		$h    .= '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Step</th><th style="text-align:right">People</th><th style="text-align:right">of previous</th><th style="text-align:right">of start</th></tr></thead><tbody>';
		foreach ( self::PATH as $step => $label ) {
			$n = isset( $counts[ $step ] ) ? $counts[ $step ] : 0;
			if ( ! $first ) {
				$first = $n;
			}
			$pp  = $prev ? (int) round( 100 * $n / $prev ) : 0;
			$red = $prev && $pp < 60 ? ' style="text-align:right;color:#b32d2e;font-weight:600"' : ' style="text-align:right"';
			$h  .= sprintf(
				'<tr><td>%s</td><td style="text-align:right">%d</td><td%s>%s</td><td style="text-align:right;color:#666">%s</td></tr>',
				esc_html( $label ),
				$n,
				$red,
				$prev ? $pp . '%' : '',
				$first ? (int) round( 100 * $n / $first ) . '%' : ''
			);
			$prev = $n;
		}
		$h .= '</tbody></table>';

		$side = array(
			'validation_error'  => 'Form rejected at least once',
			'code_email_failed' => 'Code email FAILED',
			'code_resend'       => 'Asked for a new code',
			'code_wrong'        => 'Entered a wrong / expired code',
		);
		$h .= '<p style="margin:12px 0 4px"><strong>Problems along the way</strong> (people)</p><table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( $side as $step => $label ) {
			$n  = isset( $counts[ $step ] ) ? $counts[ $step ] : 0;
			$st = 'code_email_failed' === $step && $n ? ' style="color:#b32d2e;font-weight:600"' : '';
			$h .= sprintf( '<tr><td%s>%s</td><td style="text-align:right"%s>%d</td></tr>', $st, esc_html( $label ), $st, $n );
		}
		$h .= '</tbody></table>';

		$h .= self::error_fields( $a, $b );
		$h .= self::welcome_steps( $a, $b );
		$h .= self::by_day( $a, $b );
		return $h . '<p style="color:#666;margin:10px 0 0">A person is one browser before the form is sent and one email address after. Red: fewer than 60% made it from the step above.</p></details>';
	}

	/** Which fields the rejected forms complained about. */
	private static function error_fields( $a, $b ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT detail FROM {$t} WHERE step = 'validation_error' AND created_at BETWEEN %s AND %s", $a, $b ) );
		if ( ! $rows ) {
			return '';
		}
		$by = array();
		foreach ( $rows as $d ) {
			foreach ( array_filter( explode( ',', (string) $d ) ) as $f ) {
				$by[ $f ] = ( isset( $by[ $f ] ) ? $by[ $f ] : 0 ) + 1;
			}
		}
		arsort( $by );
		$h = '<p style="margin:12px 0 4px"><strong>Why forms were rejected</strong> (times each field was flagged)</p><table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( array_slice( $by, 0, 10, true ) as $f => $n ) {
			$h .= sprintf( '<tr><td>%s</td><td style="text-align:right">%d</td></tr>', esc_html( self::field_label( $f ) ), $n );
		}
		return $h . '</tbody></table>';
	}

	private static function field_label( $f ) {
		$names = array(
			'signup_email'            => 'Email',
			'signup_username'         => 'Username',
			'signup_password'         => 'Password',
			'signup_password_confirm' => 'Password confirmation',
		);
		if ( isset( $names[ $f ] ) ) {
			return $names[ $f ];
		}
		if ( preg_match( '/^field_(\d+)/', $f, $m ) && function_exists( 'xprofile_get_field' ) ) {
			$fld = xprofile_get_field( (int) $m[1] );
			if ( $fld && ! empty( $fld->name ) ) {
				return $fld->name;
			}
		}
		return $f;
	}

	/** How far into onboarding people got. */
	private static function welcome_steps( $a, $b ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT detail, COUNT(DISTINCT user_id) n FROM {$t} WHERE step = 'welcome_step' AND created_at BETWEEN %s AND %s GROUP BY detail ORDER BY n DESC", $a, $b ) );
		if ( ! $rows ) {
			return '';
		}
		$h = '<p style="margin:12px 0 4px"><strong>Onboarding steps answered</strong> (members)</p><table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( $rows as $r ) {
			$label = 'blur' === $r->detail ? 'Photo step' : self::field_label( $r->detail );
			$h    .= sprintf( '<tr><td>%s</td><td style="text-align:right">%d</td></tr>', esc_html( $label ), (int) $r->n );
		}
		return $h . '</tbody></table>';
	}

	/** Per IST day, so an outage shows up as a day that stops at one step. */
	private static function by_day( $a, $b ) {
		global $wpdb;
		$t   = self::table();
		$off = (int) wp_timezone()->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
		$cols = array( 'register_view' => 'Opened', 'form_submit' => 'Submitted', 'signup_created' => 'Accepted', 'code_email_failed' => 'Email failed', 'activated' => 'Activated', 'onboarded' => 'Onboarded' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) d, step, COUNT(DISTINCT pkey) n FROM {$t} WHERE created_at BETWEEN %s AND %s GROUP BY d, step ORDER BY d DESC", $off, $a, $b ) );
		$days = array();
		foreach ( (array) $rows as $r ) {
			$days[ $r->d ][ $r->step ] = (int) $r->n;
		}
		if ( ! $days ) {
			return '';
		}
		$h = '<p style="margin:12px 0 4px"><strong>By day</strong></p><table class="widefat striped" style="max-width:640px"><thead><tr><th>Day</th>';
		foreach ( $cols as $label ) {
			$h .= '<th style="text-align:right">' . esc_html( $label ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $days as $d => $s ) {
			$h .= '<tr><td>' . esc_html( wp_date( 'D j M', strtotime( $d . ' 12:00:00' ) ) ) . '</td>';
			foreach ( $cols as $step => $label ) {
				$n  = isset( $s[ $step ] ) ? $s[ $step ] : 0;
				$st = 'code_email_failed' === $step && $n ? ';color:#b32d2e;font-weight:600' : '';
				$h .= '<td style="text-align:right' . $st . '">' . $n . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table>';
	}
}
