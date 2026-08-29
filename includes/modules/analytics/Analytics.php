<?php
/**
 * Analytics & social-meta module.
 *
 * Consolidates five WPCode snippets:
 *   #12084 Meta Pixel + CompleteRegistration
 *   #12091 Meta Pixel Purchase (PMPro)
 *   #12112 GA4 events (sign_up + purchase)
 *   #12073 Default OG / Twitter share image (Yoast)
 *   #11697 Avatar alt text (accessibility/SEO)
 *
 * IMPORTANT: unlike CSS/idempotent filters, firing a pixel or conversion event
 * twice DOUBLE-COUNTS it. So this whole module is gated behind
 * Config::analytics_enabled() (off unless wp-config sets CASHAADI_ANALYTICS_ENABLED
 * = true). Deploying it therefore changes nothing until you flip that flag in the
 * SAME change that disables the five snippets above.
 *
 * Behaviour is byte-for-byte faithful to the snippets; it reuses their user-meta
 * keys (csm_fb_purchase_pending / csm_ga_purchase_pending) so a purchase left
 * pending by a snippet is still consumed correctly after cutover.
 */

namespace CAShaadi\Modules\Analytics;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Analytics {

	public static function register() {
		if ( ! Config::analytics_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Meta Pixel base + PageView + CompleteRegistration (#12084).
		add_action( 'bp_complete_signup', array( __CLASS__, 'flag_fb_registered' ) );
		add_action( 'wp_head', array( __CLASS__, 'fb_pixel' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'fb_pixel' ), 5 );

		// Meta Pixel Purchase (#12091).
		add_action( 'pmpro_after_checkout', array( __CLASS__, 'store_purchase' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'fb_purchase' ), 2 );
		add_action( 'wp_footer', array( __CLASS__, 'fb_purchase' ), 6 );

		// GA4 sign_up + purchase (#12112).
		add_action( 'bp_complete_signup', array( __CLASS__, 'flag_ga_registered' ) );
		add_action( 'wp_footer', array( __CLASS__, 'ga4_events' ), 20 );

		// Default OG / Twitter share image (#12073).
		add_action( 'init', array( __CLASS__, 'og_default' ) );
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'og_fallback' ), 20 );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'og_fallback' ), 20 );

		// Avatar alt text (#11697).
		add_filter( 'bp_core_fetch_avatar', array( __CLASS__, 'avatar_alt_bp' ), 20, 2 );
		add_filter( 'get_avatar', array( __CLASS__, 'avatar_alt_wp' ), 20, 2 );
	}

	/* ---- Meta Pixel (#12084) ------------------------------------------- */

	public static function flag_fb_registered() {
		$GLOBALS['csm_fb_registered'] = true;
	}

	public static function fb_pixel() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		$id  = Config::FB_PIXEL_ID;
		$reg = ! empty( $GLOBALS['csm_fb_registered'] );
		?>
		<!-- Meta Pixel Code (CAShaadi) -->
		<script>
		!function(f,b,e,v,n,t,s)
		{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};
		if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
		n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];
		s.parentNode.insertBefore(t,s)}(window, document,'script',
		'https://connect.facebook.net/en_US/fbevents.js');
		fbq('init', '<?php echo esc_js( $id ); ?>');
		fbq('track', 'PageView');
		<?php if ( $reg ) { echo "fbq('track', 'CompleteRegistration');\n"; } ?>
		</script>
		<noscript><img height="1" width="1" style="display:none"
		src="https://www.facebook.com/tr?id=<?php echo esc_attr( $id ); ?>&ev=PageView&noscript=1"/></noscript>
		<!-- End Meta Pixel Code -->
		<?php
	}

	/* ---- Purchase capture shared by Meta + GA4 (#12091 / #12112) -------- */

	public static function store_purchase( $user_id, $morder ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}
		$value = 0.0;
		if ( is_object( $morder ) && isset( $morder->total ) && is_numeric( $morder->total ) ) {
			$value = (float) $morder->total;
		} elseif ( is_object( $morder ) && isset( $morder->InitialPayment ) && is_numeric( $morder->InitialPayment ) ) {
			$value = (float) $morder->InitialPayment;
		}
		if ( $value <= 0 ) {
			return; // skip free levels / zero orders
		}
		$level_name = '';
		if ( is_object( $morder ) && ! empty( $morder->membership_id ) && function_exists( 'pmpro_getLevel' ) ) {
			$lvl = pmpro_getLevel( (int) $morder->membership_id );
			if ( $lvl && ! empty( $lvl->name ) ) {
				$level_name = $lvl->name;
			}
		}
		$currency = ( is_object( $morder ) && ! empty( $morder->currency ) ) ? $morder->currency : 'INR';
		$payload  = array(
			'value'    => round( $value, 2 ),
			'currency' => $currency,
			'name'     => $level_name,
			'order'    => ( is_object( $morder ) && ! empty( $morder->code ) ) ? $morder->code : '',
		);
		// Separate keys so Meta and GA4 each fire exactly once (same keys the snippets used).
		update_user_meta( $user_id, 'csm_fb_purchase_pending', $payload );
		update_user_meta( $user_id, 'csm_ga_purchase_pending', $payload );
	}

	public static function fb_purchase() {
		static $printed = false;
		if ( $printed || ! is_user_logged_in() ) {
			return;
		}
		$uid     = get_current_user_id();
		$pending = get_user_meta( $uid, 'csm_fb_purchase_pending', true );
		if ( empty( $pending ) || ! is_array( $pending ) || empty( $pending['value'] ) ) {
			return;
		}
		$printed = true;
		delete_user_meta( $uid, 'csm_fb_purchase_pending' );

		$value    = (float) $pending['value'];
		$currency = ! empty( $pending['currency'] ) ? preg_replace( '/[^A-Za-z]/', '', $pending['currency'] ) : 'INR';
		$name     = ! empty( $pending['name'] ) ? $pending['name'] : 'Membership';
		?>
		<!-- Meta Pixel Purchase event (CAShaadi) -->
		<script>
		if ( window.fbq ) {
			fbq('track', 'Purchase', {
				value: <?php echo wp_json_encode( round( $value, 2 ) ); ?>,
				currency: <?php echo wp_json_encode( strtoupper( $currency ) ); ?>,
				content_name: <?php echo wp_json_encode( $name ); ?>,
				content_type: 'product'
			});
		}
		</script>
		<!-- End Meta Pixel Purchase event -->
		<?php
	}

	/* ---- GA4 (#12112) --------------------------------------------------- */

	public static function flag_ga_registered() {
		$GLOBALS['csm_ga_registered'] = true;
	}

	public static function ga4_events() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		$reg      = ! empty( $GLOBALS['csm_ga_registered'] );
		$purchase = null;
		if ( is_user_logged_in() ) {
			$uid     = get_current_user_id();
			$pending = get_user_meta( $uid, 'csm_ga_purchase_pending', true );
			if ( ! empty( $pending ) && is_array( $pending ) && ! empty( $pending['value'] ) ) {
				$purchase = $pending;
				delete_user_meta( $uid, 'csm_ga_purchase_pending' );
			}
		}
		if ( ! $reg && ! $purchase ) {
			return;
		}
		?>
		<!-- GA4 events (CAShaadi) -->
		<script>
		window.dataLayer = window.dataLayer || [];
		function csmGtag(){ (window.gtag ? window.gtag : function(){ window.dataLayer.push(arguments); }).apply(null, arguments); }
		<?php if ( $reg ) : ?>
		csmGtag('event', 'sign_up', { method: 'website' });
		<?php endif; ?>
		<?php
		if ( $purchase ) :
			$val  = (float) $purchase['value'];
			$cur  = ! empty( $purchase['currency'] ) ? preg_replace( '/[^A-Za-z]/', '', $purchase['currency'] ) : 'INR';
			$name = ! empty( $purchase['name'] ) ? $purchase['name'] : 'Membership';
			$txn  = ! empty( $purchase['order'] ) ? $purchase['order'] : '';
			?>
		csmGtag('event', 'purchase', {
			transaction_id: <?php echo wp_json_encode( $txn ); ?>,
			value: <?php echo wp_json_encode( round( $val, 2 ) ); ?>,
			currency: <?php echo wp_json_encode( strtoupper( $cur ) ); ?>,
			items: [{ item_name: <?php echo wp_json_encode( $name ); ?>, price: <?php echo wp_json_encode( round( $val, 2 ) ); ?>, quantity: 1 }]
		});
		<?php endif; ?>
		</script>
		<!-- End GA4 events -->
		<?php
	}

	/* ---- OG / Twitter default image (#12073) --------------------------- */

	public static function og_default() {
		if ( get_option( 'csm_og_default_set' ) ) {
			return; // already set (possibly by the snippet) — idempotent
		}
		$social = get_option( 'wpseo_social' );
		if ( ! is_array( $social ) ) {
			$social = array();
		}
		$social['og_default_image']    = Config::og_image_url();
		$social['og_default_image_id'] = Config::OG_IMAGE_ID;
		update_option( 'wpseo_social', $social );
		update_option( 'csm_og_default_set', 1 );
	}

	public static function og_fallback( $image ) {
		return $image ? $image : Config::og_image_url();
	}

	/* ---- Avatar alt text (#11697) -------------------------------------- */

	private static function fix_alt( $html, $item_id = 0 ) {
		if ( ! is_string( $html ) || false === strpos( $html, '<img' ) ) {
			return $html;
		}
		$alt = 'Member profile photo';
		if ( $item_id && function_exists( 'bp_core_get_user_displayname' ) ) {
			$name = bp_core_get_user_displayname( (int) $item_id );
			if ( $name ) {
				$alt = sprintf( 'Profile photo of %s', $name );
			}
		}
		if ( preg_match( '/alt\s*=\s*"\s*"/i', $html ) ) {
			$html = preg_replace( '/alt\s*=\s*"\s*"/i', 'alt="' . esc_attr( $alt ) . '"', $html, 1 );
		} elseif ( false === strpos( $html, ' alt=' ) ) {
			$html = preg_replace( '/<img /i', '<img alt="' . esc_attr( $alt ) . '" ', $html, 1 );
		}
		return $html;
	}

	public static function avatar_alt_bp( $html, $params ) {
		$id = ( is_array( $params ) && ! empty( $params['item_id'] ) ) ? $params['item_id'] : 0;
		return self::fix_alt( $html, $id );
	}

	public static function avatar_alt_wp( $html, $id_or_email ) {
		$item_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$item_id = (int) $id_or_email;
		} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
			$item_id = (int) $id_or_email->user_id;
		}
		return self::fix_alt( $html, $item_id );
	}
}
