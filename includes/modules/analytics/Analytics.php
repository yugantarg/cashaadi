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

		/*
		 * Meta Pixel base + PageView (#12084).
		 *
		 * CompleteRegistration is NOT fired here any more (v1.61.1). It used to
		 * be, flagged on bp_complete_signup — which fires on the registration
		 * form POST, whose confirmation page renders this pixel. tracking.js
		 * ALSO fires CompleteRegistration on the first /welcome/ render, so one
		 * member produced two events. tracking.js keeps it, because its claim
		 * (Tracking\Events::claim) is once-per-member-ever rather than
		 * once-per-request, and by /welcome/ the member is logged in, so the
		 * event carries full advanced matching. Coverage is not lost: every
		 * member who registered in the 24h to 2026-09-22 reached /welcome/.
		 */
		add_action( 'wp_head', array( __CLASS__, 'fb_pixel' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'fb_pixel' ), 5 );

		// Meta Pixel Purchase (#12091).
		add_action( 'pmpro_after_checkout', array( __CLASS__, 'store_purchase' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'fb_purchase' ), 2 );
		add_action( 'wp_footer', array( __CLASS__, 'fb_purchase' ), 6 );

		// GA4 sign_up + purchase (#12112).
		add_action( 'bp_complete_signup', array( __CLASS__, 'flag_ga_registered' ) );
		add_action( 'wp_footer', array( __CLASS__, 'ga4_events' ), 20 );

		// Google Ads: register the conversion ID on the Google tag Site Kit already
		// loads. Queued through dataLayer, so it is order-independent of gtag.js.
		add_action( 'wp_head', array( __CLASS__, 'gads_config' ), 99 );

		// Default OG / Twitter share image (#12073).
		add_action( 'init', array( __CLASS__, 'og_default' ) );
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'og_fallback' ), 20 );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'og_fallback' ), 20 );

		// Avatar alt text (#11697).
		add_filter( 'bp_core_fetch_avatar', array( __CLASS__, 'avatar_alt_bp' ), 20, 2 );
		add_filter( 'get_avatar', array( __CLASS__, 'avatar_alt_wp' ), 20, 2 );
	}

	/* ---- Meta Pixel (#12084) ------------------------------------------- */

	/**
	 * Manual advanced matching (Meta EMQ).
	 *
	 * The pixel was sending no customer information at all — IP, user agent and
	 * fbp only — which held Event Match Quality at 6.1/10, and Meta's
	 * "Conversions API with Meta" mirrors the browser event, so the server copy
	 * was just as thin. Automatic advanced matching cannot read this signup
	 * form, so the values are supplied here.
	 *
	 * EVERY value is SHA-256 hashed after normalising, per Meta's spec: nothing
	 * identifying is ever written into the page. A value we do not have is
	 * OMITTED — hash('') is a valid-looking 64-char string that would match
	 * every other member who is also missing that field, which is worse than
	 * sending nothing.
	 *
	 * Two sources, because the pixel has to work on both sides of activation:
	 *   - logged in  -> the account and its xProfile fields;
	 *   - the signup POST -> $_POST, since the member has no account yet and
	 *     this is the request whose page BuddyPress renders after registering.
	 *
	 * @return array<string,string> Meta user-data keys, possibly empty.
	 */
	private static function fb_user_data() {
		$out = array();

		try {
			$raw = self::fb_identity();

			$email = strtolower( trim( (string) ( $raw['email'] ?? '' ) ) );
			if ( '' !== $email && is_email( $email ) ) {
				$out['em'] = self::fb_hash( $email );
			}

			/*
			 * Phone: digits only, country code, no plus. Indian mobiles are
			 * stored bare, so a 10-digit number gets 91; a number that already
			 * carries it is left alone. The field type renders an HTML tel:
			 * anchor, hence the tag strip.
			 */
			$phone = preg_replace( '/\D+/', '', wp_strip_all_tags( (string) ( $raw['phone'] ?? '' ) ) );
			$phone = ltrim( (string) $phone, '0' );
			if ( 10 === strlen( $phone ) ) {
				$phone = '91' . $phone;
			}
			if ( strlen( $phone ) >= 11 && strlen( $phone ) <= 15 ) {
				$out['ph'] = self::fb_hash( $phone );
			}

			// Name: first token is fn, the rest ln. Letters only, lowercased.
			$name = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $raw['name'] ?? '' ) ) ) );
			if ( '' !== $name ) {
				$parts = explode( ' ', $name, 2 );
				$fn    = preg_replace( '/[^a-z]/', '', strtolower( $parts[0] ) );
				if ( '' !== $fn ) {
					$out['fn'] = self::fb_hash( $fn );
				}
				if ( isset( $parts[1] ) ) {
					$ln = preg_replace( '/[^a-z]/', '', strtolower( $parts[1] ) );
					if ( '' !== $ln ) {
						$out['ln'] = self::fb_hash( $ln );
					}
				}
			}

			$gender = strtolower( trim( (string) ( $raw['gender'] ?? '' ) ) );
			if ( isset( $gender[0] ) && ( 'm' === $gender[0] || 'f' === $gender[0] ) ) {
				$out['ge'] = self::fb_hash( $gender[0] );
			}

			$dob = trim( (string) ( $raw['dob'] ?? '' ) );
			if ( '' !== $dob ) {
				$ts = strtotime( $dob );
				// A sane birth year; strtotime happily parses junk into 1970.
				if ( $ts && (int) gmdate( 'Y', $ts ) > 1900 && $ts < time() ) {
					$out['db'] = self::fb_hash( gmdate( 'Ymd', $ts ) );
				}
			}

			$city = preg_replace( '/[^a-z]/', '', strtolower( wp_strip_all_tags( (string) ( $raw['city'] ?? '' ) ) ) );
			if ( '' !== $city ) {
				$out['ct'] = self::fb_hash( $city );
			}

			if ( ! empty( $raw['user_id'] ) ) {
				$out['external_id'] = self::fb_hash( (string) (int) $raw['user_id'] );
			}

			/*
			 * Country last, and ONLY alongside a real identifier. On its own it
			 * identifies nobody — every member is Indian — and adding it
			 * unconditionally would hang a user-data object off every
			 * logged-out pageview for no matching benefit.
			 */
			if ( $out ) {
				$out['country'] = self::fb_hash( 'in' );
			}
		} catch ( \Throwable $e ) {
			// A tag must never take the page down: fall back to a plain init.
			return array();
		}

		return $out;
	}

	/** Meta wants lowercase hex SHA-256. */
	private static function fb_hash( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * One xProfile value, as STORED.
	 *
	 * xprofile_get_field_data() returns the value a profile page would print,
	 * which is not the value: the date of birth comes back as "31 years old"
	 * and the phone number as an HTML tel: anchor. Both are useless to hash —
	 * the DOB one silently produced no `db` key at all. get_value_byid() reads
	 * the row itself, which is what every one of these fields needs.
	 */
	private static function fb_field( $field_id, $uid ) {
		if ( ! class_exists( 'BP_XProfile_ProfileData' ) ) {
			return function_exists( 'xprofile_get_field_data' )
				? xprofile_get_field_data( $field_id, $uid )
				: '';
		}
		$v = \BP_XProfile_ProfileData::get_value_byid( $field_id, $uid );
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		return (string) $v;
	}

	/**
	 * The identity behind this request, unhashed and unnormalised.
	 *
	 * Reads the account when there is one, and otherwise the registration POST.
	 * Nothing here is printed; fb_user_data() hashes every value it uses.
	 */
	private static function fb_identity() {
		if ( is_user_logged_in() ) {
			$u   = wp_get_current_user();
			$uid = (int) $u->ID;
			return array(
				'email'   => $u->user_email,
				'name'    => self::fb_field( Config::FIELD_NAME, $uid ),
				'phone'   => self::fb_field( Config::FIELD_PHONE, $uid ),
				'gender'  => self::fb_field( Config::FIELD_GENDER, $uid ),
				'dob'     => self::fb_field( Config::FIELD_DOB, $uid ),
				'city'    => self::fb_field( Config::FIELD_CITY, $uid ),
				'user_id' => $uid,
			);
		}

		/*
		 * The registration POST. Read-only and nonce-free on purpose: this does
		 * not act on the input, it only mirrors what the member just typed into
		 * a hash. BuddyPress has already validated and stored it by the time
		 * this page renders.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['signup_email'] ) ) {
			return array();
		}
		$post = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		// A datebox posts three parts, or one value if the theme flattened it.
		$dob = $post( 'field_' . Config::FIELD_DOB );
		if ( '' === $dob ) {
			$y = $post( 'field_' . Config::FIELD_DOB . '_year' );
			$m = $post( 'field_' . Config::FIELD_DOB . '_month' );
			$d = $post( 'field_' . Config::FIELD_DOB . '_day' );
			if ( $y && $m && $d ) {
				$dob = $y . '-' . $m . '-' . $d;
			}
		}

		return array(
			'email'   => sanitize_email( wp_unslash( $_POST['signup_email'] ) ),
			'name'    => $post( 'field_' . Config::FIELD_NAME ),
			'phone'   => $post( 'field_' . Config::FIELD_PHONE ),
			'gender'  => $post( 'field_' . Config::FIELD_GENDER ),
			'dob'     => $dob,
			'city'    => $post( 'field_' . Config::FIELD_CITY ),
			'user_id' => 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function fb_pixel() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		$id = Config::FB_PIXEL_ID;
		$ud = self::fb_user_data();
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
		fbq('init', '<?php echo esc_js( $id ); ?>'<?php echo $ud ? ', ' . wp_json_encode( $ud ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>);
		fbq('track', 'PageView');
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

	/**
	 * Register the Google Ads conversion ID against the Google tag that is
	 * already on the page (Site Kit). Deliberately does NOT define gtag() or load
	 * another gtag.js — commands queued on dataLayer are picked up whenever the
	 * real tag initialises, so this is safe regardless of load order.
	 */
	public static function gads_config() {
		if ( is_admin() ) {
			return;
		}
		?>
		<!-- Google Ads conversion ID (CAShaadi) -->
		<script>
		window.dataLayer = window.dataLayer || [];
		(window.gtag || function(){ window.dataLayer.push(arguments); })('config', <?php echo wp_json_encode( Config::GADS_CONVERSION_ID ); ?>);
		</script>
		<?php
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
		<?php // Google Ads "Submit lead form" conversion — same moment as sign_up. ?>
		csmGtag('event', 'conversion', { send_to: <?php echo wp_json_encode( Config::GADS_LEAD_LABEL ); ?> });
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
