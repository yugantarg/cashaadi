<?php
/**
 * CSM — Google Ads Conversion (Submit lead form)
 *
 * PASTE THIS INTO WPCODE ON **PRODUCTION** (cashaadi.in).
 *   Code Type : PHP Snippet
 *   Location  : Run Everywhere
 *   Name      : CSM — Google Ads Conversion (Submit lead form)
 *
 * Why a snippet and not the plugin: cashaadi-ui is not installed on production,
 * so the plugin's copy of this only runs on staging2. The same behaviour now
 * exists in Modules\Analytics (v0.34.0) — when the plugin is eventually cut over
 * to production, DISABLE THIS SNIPPET in the same change that sets
 * CASHAADI_ANALYTICS_ENABLED, or the conversion will fire twice.
 *
 * What it does
 *   1. Registers the Ads conversion ID AW-1014629759 against the Google tag that
 *      Site Kit already loads. It does NOT load another gtag.js and does NOT
 *      define gtag() — commands are queued on dataLayer, so this is safe
 *      whatever order the tag initialises in.
 *   2. Fires the "Submit lead form" conversion when a registration completes.
 *
 * Deliberately NOT installed: the G-2EYMW7BYEJ GA4 property that came with the
 * tag. This site already has GA4 (G-VJW0VMS7KC via Site Kit); adding a second
 * property would collect the same traffic twice.
 *
 * The trigger mirrors the site's existing, proven pattern (#12112): flag on
 * bp_complete_signup, print in wp_footer of the SAME request — BuddyPress
 * renders the post-signup page without redirecting, so the flag survives.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---- 1. register the Ads conversion ID on the existing Google tag ---- */
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<!-- Google Ads conversion ID (CAShaadi) -->
	<script>
	window.dataLayer = window.dataLayer || [];
	(window.gtag || function(){ window.dataLayer.push(arguments); })('config', 'AW-1014629759');
	</script>
	<?php
}, 99 );

/* ---- 2. fire the conversion when a signup completes ---- */
add_action( 'bp_complete_signup', function () {
	$GLOBALS['csm_gads_registered'] = true;
} );

add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['csm_gads_registered'] ) ) {
		return;
	}
	?>
	<!-- Google Ads: Submit lead form conversion (CAShaadi) -->
	<script>
	window.dataLayer = window.dataLayer || [];
	(window.gtag || function(){ window.dataLayer.push(arguments); })(
		'event', 'conversion', { send_to: 'AW-1014629759/rXtbCPaksbsDEP-K6OMD' }
	);
	</script>
	<?php
}, 21 );
