<?php
/**
 * Premium module.
 *
 * The premium snippets inject buttons/gates, so running them alongside the
 * active WPCode versions would double them. The whole module is therefore gated
 * behind Config::premium_enabled() (off unless wp-config sets
 * CASHAADI_PREMIUM_ENABLED = true) — deploying it changes nothing until a
 * coordinated cutover (flip the flag + disable the migrated premium snippets).
 *
 * This first increment migrates:
 *   #11579 Upgrade to Premium button (own profile + members directory)
 *
 * Still to migrate here (later, still behind this flag): #11795 checkout
 * hygiene, #11620 profile gate, #11614 contact gate, #11807/#11811/#11821, etc.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premium {

	public static function register() {
		if ( ! Config::premium_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Upgrade button (#11579): on the member's own profile header and the
		// members directory.
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'upgrade_on_profile' ) );
		add_action( 'bp_before_directory_members_content', array( __CLASS__, 'upgrade_on_directory' ) );

		// Checkout hygiene (#11795): stop existing premium members re-buying, and
		// keep the cart to just the premium product for everyone else.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_add_to_cart' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'bounce_premium' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_assets' ) );

		// Contact gate (#11614): phone + email visible only to owner / admin /
		// premium; everyone else sees an upgrade nudge.
		add_filter( 'bp_get_the_profile_field_value', array( __CLASS__, 'gate_phone_field' ), 20, 3 );
		add_action( 'bp_after_member_header', array( __CLASS__, 'contact_email_row' ) );

		// Profile Visitors (#11811): "who viewed me". Read-only over the
		// wp_csm_profile_views table (created/logged by #11807, which stays
		// active). Premium sees the full list; free sees a locked teaser + count.
		add_shortcode( 'csm_profile_visitors', array( __CLASS__, 'pv_shortcode' ) );
		add_action( 'bp_setup_nav', array( __CLASS__, 'pv_subnav' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'premium_assets' ) );

		// Premium intent leads (#11796): track upgrade-clicks that haven't paid.
		// Owns the wp_csm_intent table via the Migrator.
		Migrator::register( 'intent', array( __CLASS__, 'lead_schema' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'lead_record' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'lead_on_payment' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'lead_on_payment' ), 10, 1 );
		add_action( 'pmpro_after_change_membership_level', array( __CLASS__, 'lead_on_level' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'lead_menu' ) );
	}

	public static function premium_assets() {
		if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
			Assets::style( 'premium', 'assets/css/premium.css' );
		}
	}

	/* ---- profile visitors (#11811) ------------------------------------- */

	private static function pv_view_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_profile_views';
	}

	/** Visitor rows for $uid (newest first), blocked users + admins excluded. */
	private static function pv_rows( $uid, $limit = 200 ) {
		global $wpdb;
		$t = self::pv_view_table();
		// The table is owned by #11807; guard in case it isn't present.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$t
		) );
		if ( ! $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT viewer_id, hits, last_at FROM {$t} WHERE viewed_id = %d ORDER BY last_at DESC LIMIT %d",
			(int) $uid,
			(int) $limit
		) );
		if ( empty( $rows ) ) {
			return array();
		}
		$hidden = function_exists( 'csm_bl_hidden_ids' ) ? array_flip( csm_bl_hidden_ids( $uid ) ) : array();
		$out    = array();
		foreach ( $rows as $r ) {
			$vid = (int) $r->viewer_id;
			if ( isset( $hidden[ $vid ] ) || user_can( $vid, 'manage_options' ) ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	public static function pv_list_html( $uid ) {
		$rows    = self::pv_rows( $uid );
		$premium = Membership::is_premium( $uid );

		$html = '<div class="csm-pv"><h2>Profile visitors</h2>';

		if ( ! $premium ) {
			return $html . self::pv_teaser_html( $rows ) . '</div>';
		}
		if ( empty( $rows ) ) {
			return $html . '<p class="csm-pv-empty">No one has viewed your profile yet. Complete your profile and start matching to get noticed.</p></div>';
		}

		$now = current_time( 'timestamp' );
		foreach ( $rows as $r ) {
			$vid   = (int) $r->viewer_id;
			$name  = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $vid ) : get_the_author_meta( 'display_name', $vid );
			$link  = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $vid ) : '';
			$av    = get_avatar( $vid, 56 );
			$ts    = strtotime( (string) $r->last_at );
			$ago   = $ts ? human_time_diff( $ts, $now ) . ' ago' : '';
			$exact = $ts ? esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : '';
			$hits  = (int) $r->hits;

			$html .= '<div class="csm-pv-row">';
			$html .= '<a href="' . esc_url( $link ) . '">' . $av . '</a>';
			$html .= '<div class="csm-pv-main">';
			$html .= '<a class="csm-pv-name" href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
			$html .= '<div class="csm-pv-when" title="' . $exact . '">Viewed you ' . esc_html( $ago ) . '</div>';
			if ( $hits > 1 ) {
				$html .= '<div class="csm-pv-count">Visited ' . (int) $hits . ' times</div>';
			}
			$html .= '</div></div>';
		}
		return $html . '</div>';
	}

	/** Blurred teaser for free members: real count + timing, identities masked. */
	private static function pv_teaser_html( $rows ) {
		$count = count( $rows );
		if ( 0 === $count ) {
			return '<p class="csm-pv-empty">No one has viewed your profile yet. Complete your profile and start matching to get noticed.</p>';
		}
		$upgrade = site_url( '/membership-pricing/' );
		$now     = current_time( 'timestamp' );
		$show    = array_slice( $rows, 0, 6 );

		$h = '<div class="csm-pv-lock"><div class="csm-pv-lock-blur">';
		foreach ( $show as $r ) {
			$vid  = (int) $r->viewer_id;
			$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $vid ) : get_the_author_meta( 'display_name', $vid );
			$init = strtoupper( mb_substr( trim( (string) $name ), 0, 1 ) );
			if ( '' === $init ) {
				$init = 'C';
			}
			$ts  = strtotime( (string) $r->last_at );
			$ago = $ts ? human_time_diff( $ts, $now ) . ' ago' : '';
			$h  .= '<div class="csm-pv-row"><span class="csm-pv-ph" aria-hidden="true"></span><div class="csm-pv-main">'
				. '<div class="csm-pv-mask">' . esc_html( $init ) . '&bull;&bull;&bull;&bull;&bull;&bull;</div>'
				. '<div class="csm-pv-when">Viewed you ' . esc_html( $ago ) . '</div></div></div>';
		}
		$h .= '</div><div class="csm-pv-lock-cta"><div class="csm-pv-lock-icon">&#128064;</div>'
			. '<div class="big">' . (int) $count . '</div>'
			. '<h3>' . ( 1 === $count ? 'member viewed your profile' : 'members viewed your profile' ) . '</h3>'
			. '<p>Upgrade to Premium to see exactly who visited you, their photos, and when.</p>'
			. '<a class="csm-pv-upgrade" href="' . esc_url( $upgrade ) . '">Upgrade to Premium</a></div></div>';
		return $h;
	}

	public static function pv_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return self::pv_list_html( get_current_user_id() );
	}

	public static function pv_subnav() {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'friends' ) || ! is_user_logged_in() ) {
			return;
		}
		$slug       = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';
		$user_url   = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : '';
		$parent_url = $user_url ? trailingslashit( $user_url . $slug ) : '';
		bp_core_new_subnav_item( array(
			'name'            => 'Visitors',
			'slug'            => 'visitors',
			'parent_slug'     => $slug,
			'parent_url'      => $parent_url,
			'screen_function' => array( __CLASS__, 'pv_screen' ),
			'position'        => 40,
			'user_has_access' => bp_is_my_profile(),
		) );
	}

	public static function pv_screen() {
		add_action( 'bp_template_content', array( __CLASS__, 'pv_screen_content' ) );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	public static function pv_screen_content() {
		// Built from escaped values + trusted static markup above.
		echo self::pv_list_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ---- contact gate (#11614) ----------------------------------------- */

	/** Owner, admin, or premium member may see another member's contact info. */
	private static function can_see_contact( $displayed_user_id ) {
		$viewer = get_current_user_id();
		if ( ! $viewer ) {
			return false;
		}
		if ( (int) $viewer === (int) $displayed_user_id ) {
			return true; // owner
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true; // admin
		}
		return Membership::is_premium( $viewer ); // premium
	}

	private static function contact_nudge() {
		return '<span class="csm-contact-locked">🔒 <a href="'
			. esc_url( home_url( '/membership-pricing/' ) )
			. '">Upgrade to Premium to view contact details</a></span>';
	}

	/** Gate the phone xProfile field (277) value in place. */
	public static function gate_phone_field( $value, $type, $field_id ) {
		if ( (int) $field_id !== Config::FIELD_PHONE ) {
			return $value;
		}
		$displayed = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : 0;
		return self::can_see_contact( $displayed ) ? $value : self::contact_nudge();
	}

	/** Inject a gated Email row into the member header. */
	public static function contact_email_row() {
		if ( ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}
		$displayed = bp_displayed_user_id();
		if ( ! $displayed ) {
			return;
		}
		if ( self::can_see_contact( $displayed ) ) {
			$user = get_userdata( $displayed );
			if ( ! $user || empty( $user->user_email ) ) {
				return;
			}
			$email_html = '<a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>';
		} else {
			$email_html = self::contact_nudge();
		}
		// $email_html is built from esc_attr/esc_html + a trusted static nudge.
		echo '<div class="csm-contact-email-row"><span class="csm-contact-label">Email</span><span class="csm-contact-value">' . $email_html . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ---- checkout hygiene (#11795) ------------------------------------- */

	/**
	 * Gate the add-to-cart for the premium product: block a duplicate purchase by
	 * an existing premium member; otherwise keep the cart to just this one item.
	 */
	public static function guard_add_to_cart( $passed, $product_id ) {
		if ( (int) $product_id !== (int) Config::WC_PREMIUM_PRODUCT ) {
			return $passed;
		}
		if ( ! function_exists( 'WC' ) || ! ( WC()->cart instanceof \WC_Cart ) ) {
			return $passed;
		}
		if ( Membership::is_premium( get_current_user_id() ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'You are already a Premium member — no need to buy again.', 'notice' );
			}
			return false; // block the duplicate purchase
		}
		WC()->cart->empty_cart();
		return $passed;
	}

	/** Belt-and-braces: bounce a premium member off the premium add-to-cart URL. */
	public static function bounce_premium() {
		if ( is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['add-to-cart'] ) || (int) $_GET['add-to-cart'] !== (int) Config::WC_PREMIUM_PRODUCT ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! Membership::is_premium( get_current_user_id() ) ) {
			return;
		}
		$dest = function_exists( 'bp_loggedin_user_url' ) ? bp_loggedin_user_url() : home_url( '/' );
		wp_safe_redirect( $dest );
		exit;
	}

	/**
	 * On the pricing page, replace the premium add-to-cart link with a label for
	 * members who are already premium (JS reads window.CASHAADI_PREMIUM).
	 */
	public static function checkout_assets() {
		if ( is_admin() || ! is_user_logged_in() || ! Membership::is_premium() ) {
			return;
		}
		Assets::script( 'premium', 'assets/js/premium.js' );
		wp_add_inline_script(
			'cashaadi-premium',
			'window.CASHAADI_PREMIUM=' . wp_json_encode( array(
				'isPremium' => true,
				'productId' => (int) Config::WC_PREMIUM_PRODUCT,
			) ) . ';',
			'before'
		);
	}

	public static function upgrade_on_profile() {
		if ( function_exists( 'bp_displayed_user_id' ) && function_exists( 'bp_loggedin_user_id' )
			&& bp_displayed_user_id() === bp_loggedin_user_id() ) {
			self::upgrade_button( 'profile' );
		}
	}

	public static function upgrade_on_directory() {
		self::upgrade_button( 'directory' );
	}

	/**
	 * Render an Upgrade-to-Premium button for non-premium users. On the member's
	 * own profile, if the profile is still incomplete, show a single
	 * "complete your profile" CTA instead (mirrors #11579). Styles live in
	 * assets/css/site.css.
	 */
	private static function upgrade_button( $context ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Incomplete profile → single "complete profile" CTA (own profile only).
		if ( 'profile' === $context
			&& function_exists( 'cashaadi_has_missing_required_fields' )
			&& cashaadi_has_missing_required_fields( get_current_user_id() ) ) {
			$edit = function_exists( 'bp_loggedin_user_url' )
				? trailingslashit( bp_loggedin_user_url() . 'profile/edit' )
				: home_url( '/' );
			printf(
				'<div class="cashaadi-complete-profile-wrap"><a class="cashaadi-complete-profile-btn" href="%s">Complete your profile to browse other profiles &rarr;</a></div>',
				esc_url( $edit )
			);
			return;
		}

		if ( Membership::is_premium() ) {
			return; // already premium — no upsell
		}

		printf(
			'<div class="cashaadi-upgrade-wrap"><a class="cashaadi-upgrade-btn" href="%s">Upgrade to Premium</a></div>',
			esc_url( home_url( '/membership-pricing/' ) )
		);
	}

	/* ---- premium intent leads (#11796) --------------------------------- */

	private static function lead_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_intent';
	}

	/** CREATE TABLE for the Migrator. */
	public static function lead_schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_intent';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			user_id BIGINT UNSIGNED NOT NULL,
			first_at DATETIME NOT NULL,
			last_at DATETIME NOT NULL,
			clicks INT UNSIGNED NOT NULL DEFAULT 1,
			converted TINYINT NOT NULL DEFAULT 0,
			converted_at DATETIME NULL,
			PRIMARY KEY  (user_id),
			KEY converted (converted)
		) {$charset};";
	}

	/** Record (or bump) a lead when the premium product is added to cart. */
	public static function lead_record( $cart_item_key, $product_id ) {
		if ( (int) $product_id !== (int) Config::WC_PREMIUM_PRODUCT ) {
			return;
		}
		$uid = (int) get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		global $wpdb;
		$t   = self::lead_table();
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (user_id, first_at, last_at, clicks, converted)
			 VALUES (%d, %s, %s, 1, 0)
			 ON DUPLICATE KEY UPDATE last_at = %s, clicks = clicks + 1",
			$uid,
			$now,
			$now,
			$now
		) );
	}

	private static function lead_mark_converted( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid ) {
			return;
		}
		global $wpdb;
		$t = self::lead_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET converted = 1, converted_at = %s WHERE user_id = %d AND converted = 0",
			current_time( 'mysql' ),
			$uid
		) );
	}

	public static function lead_on_payment( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$uid = (int) $order->get_user_id();
		if ( ! $uid ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === (int) Config::WC_PREMIUM_PRODUCT ) {
				self::lead_mark_converted( $uid );
				break;
			}
		}
	}

	public static function lead_on_level( $level_id, $user_id ) {
		if ( (int) $level_id === Config::PMPRO_PREMIUM_LEVEL ) {
			self::lead_mark_converted( $user_id );
		}
	}

	private static function lead_phone( $uid ) {
		if ( function_exists( 'xprofile_get_field_data' ) ) {
			$p = xprofile_get_field_data( Config::FIELD_PHONE, $uid );
			if ( $p ) {
				return $p;
			}
		}
		return '';
	}

	private static function lead_rows( $only_pending = true ) {
		global $wpdb;
		$t     = self::lead_table();
		$where = $only_pending ? 'WHERE converted = 0' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( "SELECT user_id, first_at, last_at, clicks, converted, converted_at FROM {$t} {$where} ORDER BY last_at DESC" );
	}

	public static function lead_menu() {
		add_users_page( 'Intent Leads', 'Intent Leads', 'manage_options', 'csm-intent-leads', array( __CLASS__, 'lead_page' ) );
	}

	public static function lead_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		if ( isset( $_GET['csm_lead_csv'] ) ) {
			check_admin_referer( 'csm_lead_csv' );
			$rows = self::lead_rows( true );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=intent-leads.csv' );
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'Name', 'Phone', 'Email', 'Clicks', 'First clicked', 'Last clicked' ) );
			foreach ( $rows as $r ) {
				$u = get_userdata( $r->user_id );
				fputcsv( $out, array(
					$u ? bp_core_get_user_displayname( $r->user_id ) : ( '#' . $r->user_id ),
					self::lead_phone( $r->user_id ),
					$u ? $u->user_email : '',
					$r->clicks,
					$r->first_at,
					$r->last_at,
				) );
			}
			fclose( $out );
			exit;
		}

		$pending = self::lead_rows( true );
		$csv_url = wp_nonce_url(
			add_query_arg( array( 'page' => 'csm-intent-leads', 'csm_lead_csv' => 1 ), admin_url( 'users.php' ) ),
			'csm_lead_csv'
		);

		echo '<div class="wrap"><h1>Premium Intent Leads</h1>';
		echo '<p>Members who clicked <strong>Upgrade to Premium</strong> but have not paid yet — high-intent leads for follow-up.</p>';
		echo '<p><strong>' . count( $pending ) . '</strong> pending lead(s). <a class="button button-primary" href="' . esc_url( $csv_url ) . '">Download CSV</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Clicks</th><th>First clicked</th><th>Last clicked</th></tr></thead><tbody>';
		if ( empty( $pending ) ) {
			echo '<tr><td colspan="6">No pending leads yet.</td></tr>';
		}
		foreach ( $pending as $r ) {
			$u = get_userdata( $r->user_id );
			echo '<tr>';
			echo '<td>' . esc_html( $u ? bp_core_get_user_displayname( $r->user_id ) : ( '#' . $r->user_id ) ) . '</td>';
			echo '<td>' . esc_html( self::lead_phone( $r->user_id ) ) . '</td>';
			echo '<td>' . esc_html( $u ? $u->user_email : '' ) . '</td>';
			echo '<td>' . (int) $r->clicks . '</td>';
			echo '<td>' . esc_html( $r->first_at ) . '</td>';
			echo '<td>' . esc_html( $r->last_at ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
