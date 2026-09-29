<?php
/**
 * Where each new member came from (owner, 2026-09-28).
 *
 * BROWSER: a tiny inline script records first-touch attribution in the
 * first-party cookie `csm_attr` (90 days, set once) and last-touch in
 * `csm_attr_last` (updated on every landing that carries a signal: a UTM tag,
 * an ad click ID, or an outside referrer). It is JavaScript, not PHP, because
 * most pages are served from LiteSpeed's cache, where PHP never runs.
 *
 * SERVER: when the account is created — user_register, which in this flow runs
 * on the registration form submit (accounts exist before email verification)
 * — both cookies are read, sanitised and stored:
 *   csm_src_first / csm_src_last  JSON
 *   csm_channel                   google_ads | meta_ads | organic_search |
 *                                 social | referral | direct | unknown
 *
 * `unknown` = no cookie at all (script blocked, or the account predates this).
 */

namespace CAShaadi\Modules\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attribution {

	const FIRST   = 'csm_attr';
	const LAST    = 'csm_attr_last';
	const CHANNEL = 'csm_channel';

	/** The fields kept, and nothing else. */
	const KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'lp', 'rh', 'ts' );

	public static function channels() {
		return array(
			'google_ads'     => 'Google Ads',
			'meta_ads'       => 'Meta Ads',
			'organic_search' => 'Organic search',
			'social'         => 'Social',
			'referral'       => 'Referral',
			'direct'         => 'Direct',
			'unknown'        => 'Unknown',
		);
	}

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'script' ), 3 );
		// After Referral::attach (priority 20), so a member-referral link is known.
		add_action( 'user_register', array( __CLASS__, 'capture' ), 25 );
	}

	/* ---------------------------------------------------------- browser */

	public static function script() {
		if ( is_user_logged_in() ) {
			return; // members are not arriving
		}
		?>
<script>(function(){try{var q=new URLSearchParams(location.search),own=location.hostname.replace(/^www\./,''),rh='';try{if(document.referrer){rh=new URL(document.referrer).hostname.replace(/^www\./,'');}}catch(e){}if(rh===own){rh='';}var ks=['utm_source','utm_medium','utm_campaign','utm_content','utm_term','gclid','gbraid','wbraid','fbclid'],a={},touch=!!rh;ks.forEach(function(k){var v=q.get(k);if(v){a[k]=v.slice(0,120);touch=true;}});a.lp=location.pathname.slice(0,120);a.rh=rh.slice(0,120);a.ts=Math.floor(Date.now()/1000);var v=encodeURIComponent(JSON.stringify(a)),o=';path=/;max-age=7776000;samesite=lax;secure',has=function(n){return document.cookie.split('; ').some(function(c){return c.indexOf(n+'=')===0;});};if(!has('<?php echo esc_js( self::FIRST ); ?>')){document.cookie='<?php echo esc_js( self::FIRST ); ?>='+v+o;}if(touch||!has('<?php echo esc_js( self::LAST ); ?>')){document.cookie='<?php echo esc_js( self::LAST ); ?>='+v+o;}}catch(e){}})();</script>
		<?php
	}

	/* ----------------------------------------------------------- server */

	/** One cookie, decoded and reduced to the known fields. Null if absent or unreadable. */
	public static function read( $name ) {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$raw = json_decode( wp_unslash( (string) $_COOKIE[ $name ] ), true ); // PHP has already URL-decoded it
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( self::KEYS as $k ) {
			if ( ! isset( $raw[ $k ] ) || '' === $raw[ $k ] ) {
				continue;
			}
			$out[ $k ] = 'ts' === $k ? (int) $raw[ $k ] : substr( sanitize_text_field( (string) $raw[ $k ] ), 0, 120 );
		}
		return $out;
	}

	public static function capture( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || get_user_meta( $uid, self::CHANNEL, true ) ) {
			return;
		}
		$first = self::read( self::FIRST );
		$last  = self::read( self::LAST );
		if ( $first ) {
			update_user_meta( $uid, 'csm_src_first', wp_json_encode( $first ) );
		}
		if ( $last ) {
			update_user_meta( $uid, 'csm_src_last', wp_json_encode( $last ) );
		}
		$referred = (bool) get_user_meta( $uid, 'csm_referred_by', true );
		update_user_meta( $uid, self::CHANNEL, self::classify( $first, $last, $referred ) );
	}

	private static function has_signal( $a ) {
		if ( ! $a ) {
			return false;
		}
		foreach ( array( 'utm_source', 'utm_medium', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'rh' ) as $k ) {
			if ( ! empty( $a[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Paid-ad medium values. */
	private static function is_paid_medium( $med ) {
		return in_array( strtolower( (string) $med ), array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'pmax', 'display', 'cpm', 'ads', 'ad', 'sem' ), true );
	}

	/** google_ads / meta_ads if this one visit was an ad click, else ''. */
	private static function paid_channel( $a ) {
		if ( ! $a ) {
			return '';
		}
		$src  = strtolower( (string) ( $a['utm_source'] ?? '' ) );
		$paid = self::is_paid_medium( $a['utm_medium'] ?? '' );
		if ( ! empty( $a['gclid'] ) || ! empty( $a['gbraid'] ) || ! empty( $a['wbraid'] ) || ( 'google' === $src && $paid ) ) {
			return 'google_ads';
		}
		if ( ! empty( $a['fbclid'] ) || ( in_array( $src, array( 'facebook', 'instagram', 'fb', 'ig', 'meta' ), true ) && $paid ) ) {
			return 'meta_ads';
		}
		return '';
	}

	/**
	 * The channel.
	 *
	 * An ad click in EITHER visit decides it, the most recent one winning
	 * (owner, 2026-09-29). The first version only looked at the last visit that
	 * carried any signal, so someone who clicked a paid Instagram ad and later
	 * came back through Instagram without the ad's tags read as "Social" — three
	 * of the first four "Social" sign-ups were paid Instagram clicks.
	 *
	 * Without an ad click: a member's referral link, then organic search, social,
	 * other websites, direct — from the last visit that carried a signal.
	 */
	public static function classify( $first, $last, $referred = false ) {
		$paid = self::paid_channel( $last );
		if ( '' === $paid ) {
			$paid = self::paid_channel( $first );
		}
		if ( '' !== $paid ) {
			return $paid;
		}
		if ( $referred ) {
			return 'referral';
		}
		if ( ! $first && ! $last ) {
			return 'unknown';
		}
		$a   = self::has_signal( $last ) ? $last : ( self::has_signal( $first ) ? $first : ( $last ? $last : $first ) );
		$src = strtolower( (string) ( $a['utm_source'] ?? '' ) );
		$med = strtolower( (string) ( $a['utm_medium'] ?? '' ) );
		$rh  = strtolower( (string) ( $a['rh'] ?? '' ) );

		if ( 'referral' === $med ) {
			return 'referral';
		}
		if ( 'organic' === $med || preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo|ecosia|yandex|baidu)\./', $rh ) ) {
			return 'organic_search';
		}
		$social_src = in_array( $src, array( 'facebook', 'instagram', 'fb', 'ig', 'linkedin', 'twitter', 'x', 'youtube', 'whatsapp', 'reddit', 'pinterest', 'quora', 'telegram' ), true );
		if ( 'social' === $med || $social_src || preg_match( '/(^|\.)(facebook|instagram|linkedin|lnkd|twitter|youtube|reddit|pinterest|quora|whatsapp|telegram)\.|(^|\.)(t\.co|x\.com|wa\.me)$/', $rh ) ) {
			return 'social';
		}
		if ( '' !== $rh || '' !== $src ) {
			return 'referral';
		}
		return 'direct';
	}

	/** Recompute a member's channel from what was stored at signup. */
	public static function reclassify( $uid ) {
		$uid   = (int) $uid;
		$first = json_decode( (string) get_user_meta( $uid, 'csm_src_first', true ), true );
		$last  = json_decode( (string) get_user_meta( $uid, 'csm_src_last', true ), true );
		$ch    = self::classify( is_array( $first ) ? $first : null, is_array( $last ) ? $last : null, (bool) get_user_meta( $uid, 'csm_referred_by', true ) );
		update_user_meta( $uid, self::CHANNEL, $ch );
		return $ch;
	}

	/** For the dashboard: channel key, label, campaign. */
	public static function describe( $uid ) {
		$ch   = (string) get_user_meta( (int) $uid, self::CHANNEL, true );
		$ch   = '' === $ch ? 'unknown' : $ch;
		$all  = self::channels();
		$camp = '';
		foreach ( array( 'csm_src_last', 'csm_src_first' ) as $k ) {
			$a = json_decode( (string) get_user_meta( (int) $uid, $k, true ), true );
			if ( is_array( $a ) && ! empty( $a['utm_campaign'] ) ) {
				$camp = (string) $a['utm_campaign'];
				break;
			}
		}
		return array( 'key' => $ch, 'label' => isset( $all[ $ch ] ) ? $all[ $ch ] : $ch, 'campaign' => $camp );
	}
}
