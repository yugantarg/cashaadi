<?php
/**
 * LinkedIn Insight Tag + sign-up conversion.
 *
 * Owner, 2026-10-02. LinkedIn's terms forbid the tag on pages that carry
 * sensitive personal data, and every member page is exactly that, so:
 *   - the tag prints only for logged-out visitors, never in wp-admin, never on
 *     a member-area path, never on a host containing "staging";
 *   - LiteSpeed already keeps logged-in and logged-out caches apart (logged-in
 *     requests are never served the public copy), so a cached logged-out page
 *     carrying the tag cannot reach a member.
 *
 * Sign-up conversion (option B, owner 2026-10-02): fired on the registration
 * confirmation page — the request where BuddyPress has just created the
 * signup (bp_complete_signup) and renders "check your email". That page holds
 * no personal data and the visitor is logged out. It counts sign-ups before
 * email verification, unlike Meta/GA4 (first /welcome/ visit); accepted, since
 * the base tag must stay off /welcome/. Once per signup: the flag is set by the
 * signup itself, and a reload re-posts a form that BuddyPress then refuses
 * (the email is taken), so bp_complete_signup cannot run twice for one person.
 *
 * The conversion prints only when CSM_LI_SIGNUP_CONVERSION_ID is a non-empty
 * numeric ID (wp-config or cashaadi-ui.php); until then nothing fires.
 */

namespace CAShaadi\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LinkedIn {

	const PARTNER_ID = '10988017';

	/** Path prefixes of member-area pages. Matched against the request path. */
	const MEMBER_PATHS = array(
		'/discover', '/matches', '/requests', '/messages', '/members', '/profile',
		'/welcome', '/refer', '/settings', '/account', '/my-account', '/checkout',
		'/cart', '/membership-account', '/membership-checkout', '/notifications',
		'/activity', '/groups', '/saved',
	);

	private static $signup = false;

	public static function register() {
		add_action( 'bp_complete_signup', array( __CLASS__, 'flag_signup' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 30 );
	}

	public static function flag_signup() {
		self::$signup = true;
	}

	public static function footer() {
		static $printed = false;
		if ( $printed || is_admin() || self::is_staging() ) {
			return;
		}
		// The signup-completion request loads the tag whatever the login state;
		// everywhere else only logged-out, non-member pages do.
		if ( ! self::$signup && ( is_user_logged_in() || self::is_member_path() ) ) {
			return;
		}
		$printed = true;
		echo self::base_tag(); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( self::$signup && '' !== self::conversion_id() ) {
			echo "<script>window.lintrk('track', { conversion_id: " . self::conversion_id() . " });</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- digits only
		}
	}

	/** The configured conversion ID if it is purely numeric, else ''. */
	public static function conversion_id() {
		$id = defined( 'CSM_LI_SIGNUP_CONVERSION_ID' ) ? trim( (string) CSM_LI_SIGNUP_CONVERSION_ID ) : '';
		return ctype_digit( $id ) ? $id : '';
	}

	private static function is_staging() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $host, 'staging' ) || false !== strpos( (string) home_url(), 'staging' );
	}

	private static function is_member_path() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
		}
		$path = '/' . ltrim( strtolower( $path ), '/' );
		foreach ( self::MEMBER_PATHS as $p ) {
			if ( $path === $p || 0 === strpos( $path, $p . '/' ) ) {
				return true;
			}
		}
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			return true; // any BuddyPress member page, whatever its slug
		}
		return false;
	}

	private static function base_tag() {
		$pid = self::PARTNER_ID;
		return <<<HTML
<!-- LinkedIn Insight Tag (CAShaadi) -->
<script type="text/javascript">
_linkedin_partner_id = "{$pid}";
window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];
window._linkedin_data_partner_ids.push(_linkedin_partner_id);
</script><script type="text/javascript">
(function(l) {
if (!l){window.lintrk = function(a,b){window.lintrk.q.push([a,b])};
window.lintrk.q=[]}
var s = document.getElementsByTagName("script")[0];
var b = document.createElement("script");
b.type = "text/javascript";b.async = true;
b.src = "https://snap.licdn.com/li.lms-analytics/insight.min.js";
s.parentNode.insertBefore(b, s);})(window.lintrk);
</script>
<noscript>
<img height="1" width="1" style="display:none;" alt="" src="https://px.ads.linkedin.com/collect/?pid={$pid}&fmt=gif" />
</noscript>
<!-- End LinkedIn Insight Tag -->

HTML;
	}
}
