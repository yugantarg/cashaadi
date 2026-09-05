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
 * hygiene, #11807/#11811/#11821, etc. (#11614 contact gate and #11620 profile
 * gate are NOT migrated — already disabled on the live site.)
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

		// (#11614 contact gate intentionally NOT migrated — it was already
		// disabled on the live site; contact details are no longer gated.)

		/*
		 * Copy the admin address on every membership purchase (owner, 2026-09-05:
		 * "the same mail sent to admin email").
		 *
		 * PMPro already sends the admin a SEPARATE, terser notice. This is
		 * different: it copies the MEMBER'S OWN receipt — the same email, with the
		 * same wording and the same order details the customer sees — so the admin
		 * inbox holds exactly what was sent rather than a summary of it.
		 */
		add_filter( 'pmpro_email_recipient', array( __CLASS__, 'copy_owner_on_purchase' ), 10, 2 );

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

		// Rejection Visibility & Profile Insights (#11807): owns the rejections +
		// profile-views tables (the latter feeds Visitors above), logs both, and
		// renders the [csm_rejection_insights] premium panel.
		Migrator::register( 'rejections', array( __CLASS__, 'rej_schema' ) );
		Migrator::register( 'profile_views', array( __CLASS__, 'view_schema' ) );
		add_action( 'friends_friendship_rejected', array( __CLASS__, 'on_bp_reject' ), 10, 2 );
		add_action( 'friends_friendship_accepted', array( __CLASS__, 'on_bp_accept' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'log_view' ), 20 );
		add_shortcode( 'csm_rejection_insights', array( __CLASS__, 'rv_shortcode' ) );

		// Profile-view email (#11821): email the owner (max 1/day) when someone
		// views their profile. Runs right after log_view.
		add_action( 'template_redirect', array( __CLASS__, 'pve_notify' ), 25 );

		// Front-end assets for premium features (CSS + JS + a small config).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'premium_assets' ) );
	}

	/** Enqueue premium CSS/JS on the front-end, with a small config object. */
	public static function premium_assets() {
		if ( is_admin() ) {
			return;
		}
		Assets::style( 'premium', 'assets/css/premium.css' );
		Assets::script( 'premium', 'assets/js/premium.js' );
		wp_add_inline_script(
			'cashaadi-premium',
			'window.CASHAADI_PREMIUM=' . wp_json_encode( array(
				'isPremium' => ( is_user_logged_in() && Membership::is_premium() ),
				'productId' => (int) Config::WC_PREMIUM_PRODUCT,
			) ) . ';',
			'before'
		);
	}

	/**
	 * Where to copy purchase receipts.
	 *
	 * Defaults to WordPress's own admin_email — admin@cashaadi.in on production,
	 * a real mailbox — rather than a hardcoded personal address, so it follows the
	 * site's configuration and keeps working if the owner changes. Overridable by
	 * option or filter; empty disables the copy.
	 */
	public static function purchase_notify_to() {
		$to = (string) get_option( 'csm_purchase_notify_to', (string) get_option( 'admin_email', '' ) );
		return trim( (string) apply_filters( 'csm_purchase_notify_to', $to ) );
	}

	/**
	 * Copy the admin on the member's purchase receipt.
	 *
	 * These are the MEMBER-facing checkout templates, not the *_admin ones —
	 * PMPro sends its own admin notice already, and the point here is to have the
	 * identical customer email on file.
	 *
	 * 'checkout_paid'  — a paid membership
	 * 'checkout_check' — pay-by-check, also a purchase
	 * 'checkout_free'  — deliberately NOT included: a free level is a signup, not
	 *                    a purchase, and on a site where most members are free it
	 *                    would bury the ones that matter.
	 *
	 * wp_mail() takes a comma-separated list, so this appends a recipient rather
	 * than sending a second message: one email, delivered to both, byte-identical.
	 *
	 * Worth being aware of: this puts a member's own receipt, including their name
	 * and order details, into the admin mailbox. That is ordinary for a merchant
	 * keeping records — but it is a real copy of a customer's mail, so the address
	 * it goes to should be one the business controls.
	 */
	public static function copy_owner_on_purchase( $recipient, $email ) {
		if ( ! is_object( $email ) || empty( $email->template ) ) {
			return $recipient;
		}
		if ( ! in_array( $email->template, array( 'checkout_paid', 'checkout_check' ), true ) ) {
			return $recipient;
		}

		$extra = self::purchase_notify_to();
		if ( '' === $extra || ! is_email( $extra ) ) {
			return $recipient;
		}

		// Never duplicate: if the admin address already IS this address, one copy.
		$current = array_filter( array_map( 'trim', explode( ',', (string) $recipient ) ) );
		if ( in_array( $extra, $current, true ) ) {
			return $recipient;
		}
		$current[] = $extra;

		return implode( ',', $current );
	}

	/* ---- profile visitors (#11811) ------------------------------------- */

	private static function pv_view_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_profile_views';
	}

	/** Visitor rows for $uid (newest first), blocked users + admins excluded. */
	/**
	 * Profile-view rows for a member, newest first.
	 *
	 * Public so the Requests screen's REST endpoint can reuse it rather than
	 * re-querying the table and inventing a second definition of "who viewed me".
	 * Callers MUST apply the premium gate themselves — these rows carry real
	 * viewer ids, and a free member must never receive them.
	 */
	public static function pv_rows( $uid, $limit = 200 ) {
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
		/*
		 * Via Verification::user_phone(), which reads the RAW stored value.
		 *
		 * This returned xprofile_get_field_data() directly, and the telephone field
		 * type applies a display filter — so a lead's phone was stored as
		 *   <a href="tel://08697222644" rel="nofollow">08697222644</a>
		 * markup in the leads table, on the record a salesperson actually calls.
		 */
		if ( method_exists( '\CAShaadi\Core\Verification', 'user_phone' ) ) {
			$p = \CAShaadi\Core\Verification::user_phone( (int) $uid );
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

	/* ---- rejection visibility & profile insights (#11807) -------------- */

	private static function rej_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_rejections';
	}

	private static function view_table() {
		global $wpdb;
		return $wpdb->prefix . 'csm_profile_views';
	}

	public static function rej_schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_rejections';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			rejecter_id BIGINT UNSIGNED NOT NULL,
			rejected_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'request',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (rejecter_id, rejected_id),
			KEY rejected_id (rejected_id)
		) {$charset};";
	}

	public static function view_schema( $wpdb ) {
		$t       = $wpdb->prefix . 'csm_profile_views';
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$t} (
			viewer_id BIGINT UNSIGNED NOT NULL,
			viewed_id BIGINT UNSIGNED NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 1,
			first_at DATETIME NOT NULL,
			last_at DATETIME NOT NULL,
			PRIMARY KEY  (viewer_id, viewed_id),
			KEY viewed_id (viewed_id),
			KEY last_at (last_at)
		) {$charset};";
	}

	/** Idempotent: record that $rejecter declined $rejected. */
	public static function log_rejection( $rejecter, $rejected, $source = 'request' ) {
		$rejecter = (int) $rejecter;
		$rejected = (int) $rejected;
		if ( ! $rejecter || ! $rejected || $rejecter === $rejected ) {
			return;
		}
		global $wpdb;
		$t = self::rej_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (rejecter_id, rejected_id, source, created_at)
			 VALUES (%d, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE source = VALUES(source), created_at = VALUES(created_at)",
			$rejecter,
			$rejected,
			sanitize_key( $source ),
			current_time( 'mysql' )
		) );
	}

	public static function on_bp_reject( $friendship_id, $friendship = null ) {
		if ( ! $friendship && class_exists( 'BP_Friends_Friendship' ) ) {
			$friendship = new \BP_Friends_Friendship( (int) $friendship_id );
		}
		if ( ! is_object( $friendship ) ) {
			return;
		}
		$rejecter = isset( $friendship->friend_user_id ) ? (int) $friendship->friend_user_id : 0;
		$rejected = isset( $friendship->initiator_user_id ) ? (int) $friendship->initiator_user_id : 0;
		self::log_rejection( $rejecter, $rejected, 'request' );
	}

	public static function on_bp_accept( $friendship_id, $initiator_user_id = 0, $friend_user_id = 0 ) {
		$initiator_user_id = (int) $initiator_user_id;
		$friend_user_id    = (int) $friend_user_id;
		if ( ! $initiator_user_id || ! $friend_user_id ) {
			return;
		}
		global $wpdb;
		$t = self::rej_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE (rejecter_id = %d AND rejected_id = %d) OR (rejecter_id = %d AND rejected_id = %d)",
			$friend_user_id, $initiator_user_id, $initiator_user_id, $friend_user_id
		) );
	}

	/** Log a profile view (viewer -> viewed) with a hit counter. */
	public static function log_view() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		self::record_view( (int) get_current_user_id(), (int) bp_displayed_user_id() );
	}

	/**
	 * Record that $viewer looked at $viewed.
	 *
	 * Parameterised so a view can be recorded from somewhere that is not a
	 * BuddyPress member page — specifically the Discover screen, which renders a
	 * full profile inline and therefore never sets bp_displayed_user_id(). One
	 * definition of "a view happened", used by both entry points.
	 *
	 * @return bool True when a row was written.
	 */
	public static function record_view( $viewer, $viewed ) {
		$viewer = (int) $viewer;
		$viewed = (int) $viewed;
		if ( ! $viewer || ! $viewed || $viewer === $viewed ) {
			return false;
		}
		if ( user_can( $viewer, 'manage_options' ) || user_can( $viewed, 'manage_options' ) ) {
			return false; // never record views by or of admins
		}
		global $wpdb;
		$t   = self::view_table();
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (viewer_id, viewed_id, hits, first_at, last_at)
			 VALUES (%d, %d, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at)",
			$viewer, $viewed, $now, $now
		) );
		return true;
	}

	private static function who_declined_me( $uid ) {
		global $wpdb;
		$t = self::rej_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT rejecter_id FROM {$t} WHERE rejected_id = %d ORDER BY created_at DESC LIMIT 100", $uid
		) ) );
	}

	private static function i_declined( $uid ) {
		global $wpdb;
		$t = self::rej_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT rejected_id FROM {$t} WHERE rejecter_id = %d ORDER BY created_at DESC LIMIT 100", $uid
		) ) );
	}

	private static function viewed_no_action( $uid ) {
		global $wpdb;
		$vt      = self::view_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$viewers = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT viewer_id FROM {$vt} WHERE viewed_id = %d ORDER BY last_at DESC LIMIT 200", $uid
		) ) );
		if ( empty( $viewers ) ) {
			return array();
		}
		$ft = $wpdb->prefix . 'bp_friends';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$related = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT CASE WHEN initiator_user_id = %d THEN friend_user_id ELSE initiator_user_id END
			 FROM {$ft} WHERE initiator_user_id = %d OR friend_user_id = %d",
			$uid, $uid, $uid
		) ) );
		$rt = self::rej_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rej = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT CASE WHEN rejecter_id = %d THEN rejected_id ELSE rejecter_id END
			 FROM {$rt} WHERE rejecter_id = %d OR rejected_id = %d",
			$uid, $uid, $uid
		) ) );
		$exclude = array_flip( array_merge( $related, $rej ) );
		$out     = array();
		foreach ( $viewers as $v ) {
			if ( ! isset( $exclude[ $v ] ) && ! user_can( $v, 'manage_options' ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	private static function rv_cards( $ids, $empty ) {
		if ( empty( $ids ) ) {
			return '<p class="csm-rv-empty">' . esc_html( $empty ) . '</p>';
		}
		$age_field  = Config::FIELD_AGE;
		$city_field = function_exists( 'xprofile_get_field_id_from_name' ) ? (int) xprofile_get_field_id_from_name( 'City' ) : 0;
		$h = '<div class="csm-rv-cards">';
		foreach ( $ids as $mid ) {
			$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $mid ) : get_the_author_meta( 'display_name', $mid );
			$link = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $mid ) : '';
			$av   = get_avatar( $mid, 84 );
			$age  = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( $age_field, $mid ) : '';
			$city = $city_field ? xprofile_get_field_data( $city_field, $mid ) : '';
			$h   .= '<div class="csm-rv-card">';
			$h   .= '<a href="' . esc_url( $link ) . '" class="csm-rv-card-av">' . $av . '</a>';
			$h   .= '<a href="' . esc_url( $link ) . '" class="csm-rv-card-name">' . esc_html( $name ) . '</a>';
			$h   .= '<div class="csm-rv-card-meta">';
			if ( $age ) {
				$h .= esc_html( $age ) . ' yrs';
			}
			if ( $city ) {
				$h .= ( $age ? ' &middot; ' : '' ) . esc_html( $city );
			}
			$h .= '</div></div>';
		}
		return $h . '</div>';
	}

	private static function rv_locked_html() {
		$upgrade = site_url( '/membership-pricing/' );
		return '<div class="csm-rv-lock"><div class="csm-rv-lock-blur" aria-hidden="true">'
			. '<div class="r"><span></span><span></span><span></span></div>'
			. '<div class="r"><span></span><span></span><span></span></div></div>'
			. '<div class="csm-rv-lock-cta"><div class="csm-rv-lock-icon">&#128274;</div>'
			. '<h3>Profile Insights</h3>'
			. '<p>See who declined your request, revisit people you declined, and discover members who viewed your profile. This is a Premium feature.</p>'
			. '<a class="csm-rv-upgrade" href="' . esc_url( $upgrade ) . '">Upgrade to Premium</a></div></div>';
	}

	public static function rv_shortcode() {
		$uid = (int) get_current_user_id();
		if ( ! is_user_logged_in() || ! Membership::is_premium( $uid ) ) {
			return self::rv_locked_html();
		}

		$declined_me = self::who_declined_me( $uid );
		$i_declined  = self::i_declined( $uid );
		$viewed      = self::viewed_no_action( $uid );

		$h  = '<div class="csm-rv"><div class="csm-rv-tabs">';
		$h .= '<button type="button" class="csm-rv-tab active" data-p="a">Who declined you (' . count( $declined_me ) . ')</button>';
		$h .= '<button type="button" class="csm-rv-tab" data-p="b">People you declined (' . count( $i_declined ) . ')</button>';
		$h .= '<button type="button" class="csm-rv-tab" data-p="c">Viewed you, no action (' . count( $viewed ) . ')</button>';
		$h .= '</div>';
		$h .= '<div class="csm-rv-panel active" data-p="a"><p class="csm-rv-hint">Members who declined a match request you sent.</p>'
			. self::rv_cards( $declined_me, 'No one has declined your requests. That is a good sign.' ) . '</div>';
		$h .= '<div class="csm-rv-panel" data-p="b"><p class="csm-rv-hint">Requests you declined. Changed your mind? Open a profile to send a fresh request.</p>'
			. self::rv_cards( $i_declined, 'You have not declined anyone.' ) . '</div>';
		$h .= '<div class="csm-rv-panel" data-p="c"><p class="csm-rv-hint">Members who opened your profile but have not sent you a request yet.</p>'
			. self::rv_cards( $viewed, 'No profile viewers waiting on the sidelines right now.' ) . '</div>';
		$h .= '</div>';
		return $h;
	}

	/* ---- profile-view email (#11821) ----------------------------------- */

	private static function pve_visitors_url( $uid ) {
		$base = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $uid ) : '';
		if ( ! $base ) {
			return site_url( '/' );
		}
		$slug = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';
		return trailingslashit( trailingslashit( $base ) . $slug . '/visitors' );
	}

	public static function pve_html_ct() {
		return 'text/html; charset=UTF-8';
	}

	/** Email the profile owner (at most once/day) that they have a new visitor. */
	public static function pve_notify() {
		if ( ! is_user_logged_in() || ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		self::notify_view( (int) get_current_user_id(), (int) bp_displayed_user_id() );
	}

	/**
	 * Tell $viewed that someone looked at them. At most one email per calendar
	 * day, whatever the source.
	 *
	 * Parameterised alongside record_view() so the Discover screen can notify
	 * without pretending to be a member page.
	 */
	public static function notify_view( $viewer, $viewed ) {
		$viewer = (int) $viewer;
		$viewed = (int) $viewed;
		if ( ! $viewer || ! $viewed || $viewer === $viewed ) {
			return;
		}
		if ( user_can( $viewer, 'manage_options' ) || user_can( $viewed, 'manage_options' ) ) {
			return; // never notify about/for admins
		}
		if ( function_exists( 'csm_bl_is_blocked_pair' ) && csm_bl_is_blocked_pair( $viewer, $viewed ) ) {
			return;
		}

		// One email per calendar day (site timezone).
		$today = current_time( 'Ymd' );
		if ( (string) get_user_meta( $viewed, 'csm_pve_last', true ) === $today ) {
			return;
		}

		$owner = get_userdata( $viewed );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}

		// Stamp first so a slow mailer can't double-send in the same request.
		update_user_meta( $viewed, 'csm_pve_last', $today );

		$first = trim( (string) ( function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $viewed ) : $owner->display_name ) );
		if ( '' === $first ) {
			$first = 'there';
		} else {
			$parts = preg_split( '/\s+/', $first );
			$first = $parts[0];
		}

		$url  = esc_url( self::pve_visitors_url( $viewed ) );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = 'Someone viewed your profile on ' . $site;

		$msg  = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#2b2b2b;max-width:520px;margin:0 auto">';
		$msg .= '<p>Hi ' . esc_html( $first ) . ',</p>';
		$msg .= '<p>Good news — your profile is getting noticed. <strong>Someone just viewed your profile</strong> on ' . esc_html( $site ) . '.</p>';
		$msg .= '<p style="margin:26px 0"><a href="' . $url . '" style="background:#7a1220;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;display:inline-block">See who viewed you</a></p>';
		$msg .= '<p style="color:#7a6f68;font-size:13px">You are receiving this because someone viewed your CA Shaadi profile. We send this at most once a day.</p>';
		$msg .= '</div>';

		/*
		 * Through the queue, not wp_mail(): the master switch must govern every
		 * notification. This one sent to 97 real members from STAGING between 20
		 * Aug and 4 Sep, because staging carries a clone of the member list and a
		 * live Brevo key, and nothing here consulted the kill switch.
		 *
		 * The type carries the date, so the table's unique key enforces the
		 * once-a-day rule as well as the user-meta stamp above.
		 */
		if ( class_exists( '\\CAShaadi\\Modules\\Emails\\Queue' ) ) {
			\CAShaadi\Modules\Emails\Queue::notify( $viewed, 'csm-viewed-' . $today, $subject, $msg );
		}
	}
}
