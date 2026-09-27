<?php
/**
 * /refer/ — the member's CA Shaadi cash and referral link.
 *
 * Server-rendered; the only script is the copy / share buttons and the
 * "use my cash at checkout" switch (assets/js/refer.js).
 */

namespace CAShaadi\Modules\Referral;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReferralScreen {

	const PREMIUM_PRODUCT = 11566;

	public static function url() {
		return home_url( '/refer/' );
	}

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/cash/use', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_use' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_use( $request ) {
		$uid = get_current_user_id();
		if ( $request->get_param( 'use' ) ) {
			delete_user_meta( $uid, Referral::USE_META );
		} else {
			update_user_meta( $uid, Referral::USE_META, 'no' );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'use' => Referral::wants_to_use( $uid ) ), 200 );
	}

	private static function first_name( $uid ) {
		$n = class_exists( 'BP_XProfile_ProfileData' ) ? trim( (string) \BP_XProfile_ProfileData::get_value_byid( 1, $uid ) ) : '';
		if ( '' === $n ) {
			$u = get_userdata( $uid );
			$n = $u ? $u->display_name : '';
		}
		$p = preg_split( '/\s+/', $n );
		return ( $p && '' !== $p[0] ) ? $p[0] : __( 'A member', 'cashaadi-ui' );
	}

	private static function kind_label( $row ) {
		switch ( $row->kind ) {
			case 'referral':
				return sprintf( __( '%s joined through your link', 'cashaadi-ui' ), self::first_name( (int) $row->ref_user_id ) );
			case 'welcome':
				return __( 'Welcome credit for joining through a referral', 'cashaadi-ui' );
			case 'redeem':
				return __( 'Used at checkout', 'cashaadi-ui' );
			case 'restore':
				return __( 'Returned — the order did not go through', 'cashaadi-ui' );
			case 'reversal':
				return __( 'Adjusted by CA Shaadi', 'cashaadi-ui' );
			default:
				return __( 'Adjustment', 'cashaadi-ui' );
		}
	}

	public static function maybe_render() {
		if ( ! AppPage::claim( 'refer' ) ) {
			return;
		}
		$uid     = get_current_user_id();
		$bal     = Referral::balance( $uid );
		$link    = Referral::link_for( $uid );
		$use     = Referral::wants_to_use( $uid );
		$female  = Referral::amount_for_gender( 'female' );
		$male    = Referral::amount_for_gender( 'male' );
		$premium = function_exists( 'wc_get_product' ) && ( $p = wc_get_product( self::PREMIUM_PRODUCT ) ) ? (int) $p->get_price() : 0;
		$is_prem = class_exists( '\CAShaadi\Core\Membership' ) && \CAShaadi\Core\Membership::is_premium( $uid );

		$joined = get_users( array(
			'meta_key'   => Referral::BY_META,
			'meta_value' => $uid,
			'orderby'    => 'registered',
			'order'      => 'DESC',
			'number'     => 100,
		) );

		global $wpdb;
		$credited = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ref_user_id, amount FROM " . Referral::table() . " WHERE user_id = %d AND kind = 'referral'", $uid ) ) as $r ) {
			$credited[ (int) $r->ref_user_id ] = (int) $r->amount;
		}

		AppPage::assets();
		Assets::style( 'refer', 'assets/css/refer.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'refer', 'assets/js/refer.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-refer', 'CSM_REFER', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'use'   => rest_url( 'csm/v1/cash/use' ),
			'link'  => $link,
			'share' => sprintf(
				/* translators: %s: referral link */
				__( 'I am on CA Shaadi, the matrimony site for Chartered Accountants. Join with my link: %s', 'cashaadi-ui' ),
				$link
			),
		) );

		AppPage::open( __( 'Refer & earn', 'cashaadi-ui' ), 'refer' );
		?>
		<main class="csm-ref">
			<section class="csm-ref-bal">
				<span class="csm-ref-bal-label"><?php esc_html_e( 'Your CA Shaadi cash', 'cashaadi-ui' ); ?></span>
				<span class="csm-ref-bal-amt">₹<?php echo esc_html( number_format_i18n( $bal ) ); ?></span>
				<?php if ( $premium && ! $is_prem && $bal >= $premium ) : ?>
					<a class="csm-ref-cta" href="<?php echo esc_url( home_url( '/checkout/?add-to-cart=' . self::PREMIUM_PRODUCT ) ); ?>"><?php esc_html_e( 'Get a year of Premium free', 'cashaadi-ui' ); ?></a>
				<?php elseif ( $bal > 0 ) : ?>
					<span class="csm-ref-bal-note"><?php esc_html_e( 'Use it for Premium or anything else on CA Shaadi. It never expires.', 'cashaadi-ui' ); ?></span>
				<?php endif; ?>
			</section>

			<section class="csm-ref-card">
				<h2><?php esc_html_e( 'Invite someone you know', 'cashaadi-ui' ); ?></h2>
				<p class="csm-ref-rule">
					<?php
					printf(
						/* translators: 1: amount for a woman's profile, 2: amount for a man's */
						esc_html__( 'When someone joins with your link and completes their profile, you both get ₹%1$d if it is a woman\'s profile, or ₹%2$d if it is a man\'s.', 'cashaadi-ui' ),
						(int) $female,
						(int) $male
					);
					?>
				</p>
				<div class="csm-ref-link">
					<input type="text" readonly value="<?php echo esc_attr( $link ); ?>" aria-label="<?php esc_attr_e( 'Your referral link', 'cashaadi-ui' ); ?>">
					<button type="button" class="csm-ref-copy"><?php esc_html_e( 'Copy', 'cashaadi-ui' ); ?></button>
				</div>
				<div class="csm-ref-share">
					<a class="csm-ref-wa" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( sprintf( __( 'I am on CA Shaadi, the matrimony site for Chartered Accountants. Join with my link: %s', 'cashaadi-ui' ), $link ) ) ); ?>"><?php esc_html_e( 'Share on WhatsApp', 'cashaadi-ui' ); ?></a>
					<button type="button" class="csm-ref-native" hidden><?php esc_html_e( 'Share…', 'cashaadi-ui' ); ?></button>
				</div>
			</section>

			<section class="csm-ref-card">
				<h2><?php esc_html_e( 'People who joined with your link', 'cashaadi-ui' ); ?></h2>
				<?php if ( ! $joined ) : ?>
					<p class="csm-ref-empty"><?php esc_html_e( 'No one yet. Share your link to get started.', 'cashaadi-ui' ); ?></p>
				<?php else : ?>
					<ul class="csm-ref-list">
						<?php foreach ( $joined as $j ) : ?>
							<li>
								<span class="csm-ref-who"><?php echo esc_html( self::first_name( (int) $j->ID ) ); ?></span>
								<?php if ( isset( $credited[ (int) $j->ID ] ) ) : ?>
									<span class="csm-ref-ok">+₹<?php echo esc_html( number_format_i18n( $credited[ (int) $j->ID ] ) ); ?></span>
								<?php else : ?>
									<span class="csm-ref-wait"><?php esc_html_e( 'Waiting for them to finish their profile', 'cashaadi-ui' ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="csm-ref-card">
				<label class="csm-ref-toggle">
					<input type="checkbox" class="csm-ref-use" <?php checked( $use ); ?>>
					<span><?php esc_html_e( 'Use my CA Shaadi cash at checkout', 'cashaadi-ui' ); ?></span>
				</label>
				<p class="csm-ref-small"><?php esc_html_e( 'When this is on, your cash is taken off automatically when you buy, up to the full price.', 'cashaadi-ui' ); ?></p>
			</section>

			<?php $hist = Referral::history( $uid ); ?>
			<?php if ( $hist ) : ?>
			<section class="csm-ref-card">
				<h2><?php esc_html_e( 'History', 'cashaadi-ui' ); ?></h2>
				<ul class="csm-ref-hist">
					<?php foreach ( $hist as $h ) : ?>
						<li>
							<span class="csm-ref-hist-what">
								<?php echo esc_html( self::kind_label( $h ) ); ?>
								<small><?php echo esc_html( date_i18n( 'j M Y', strtotime( get_date_from_gmt( $h->created_at ) ) ) ); ?></small>
							</span>
							<span class="csm-ref-hist-amt <?php echo (int) $h->amount < 0 ? 'is-neg' : 'is-pos'; ?>">
								<?php echo esc_html( ( (int) $h->amount < 0 ? '−₹' : '+₹' ) . number_format_i18n( abs( (int) $h->amount ) ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>
		</main>
		<?php
		AppPage::close( 'refer' );
		exit;
	}
}
