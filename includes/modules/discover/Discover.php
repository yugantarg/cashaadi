<?php
/**
 * Discover module — the weekly like/pass discovery experience.
 *
 * Migrates the WPCode "CSM — Discovery/Tray" cluster into one gated module:
 *   #11599 Tray Refill Engine            (engine.php)
 *   #11600 Weekly Reset Trigger          (engine.php, template_redirect)
 *   #11601 Like/Pass AJAX Handlers       (like()/pass() below)
 *   #11602 Discovery Tray Shortcode & UI ([cashaadi_discovery_tray])
 *   #11605 Member Login Redirect         (login_redirect filter)
 *   #11630 Like -> Match Request routing (engine.php, csm_log_event)
 *   #11675 Discover Quota Banner         (do_shortcode_tag filter)
 *   #11681 Discover Entry Points         (profile CTA + header compass in JS)
 *   #11680 Prominent Discover Tab (CSS)  (assets/css/discover.css)
 *
 * Depends on the `cashaadi()` mu-plugin engine (tray/likes tables, week id,
 * opposite-gender, logging) — that stays in place; this is only the WPCode layer.
 * Gated behind Config::discover_enabled(): the flag is OFF by default and MUST be
 * flipped on in the SAME change that disables the snippets above (they define the
 * same global functions/shortcode, so both-active would fatal on redeclare).
 * Opposite-gender directory filtering is NOT here — it lives in the child theme
 * (bp_pre_user_query_construct); #11556 that once duplicated it is retired.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Config;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Discover {

	public static function register() {
		if ( ! Config::discover_enabled() ) {
			return; // gated OFF until the coordinated cutover
		}

		// Global engine functions (csm_refill_tray / csm_maybe_weekly_reset /
		// csm_check_mutual_like / csm_log_event) — each function_exists-guarded.
		require_once __DIR__ . '/engine.php';

		// #11600 — lazy weekly reset on every front-end load.
		add_action( 'template_redirect', 'csm_maybe_weekly_reset' );

		// #11601 — like/pass AJAX.
		add_action( 'wp_ajax_csm_like_profile', array( __CLASS__, 'like' ) );
		add_action( 'wp_ajax_csm_pass_profile', array( __CLASS__, 'pass' ) );

		// #11602 — the tray shortcode.
		add_shortcode( 'cashaadi_discovery_tray', array( __CLASS__, 'tray' ) );

		// #11675 — quota banner injected above the tray shortcode output.
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'quota_banner' ), 10, 2 );

		// #11681 (2) — "Discover Matches" CTA on the member's own profile.
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'profile_cta' ), 5 );

		// #11605 — send members to /discover/ after login.
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );

		// Styles + header-compass JS, for logged-in members (front-end only).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/* ---- assets -------------------------------------------------------- */

	public static function assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		Assets::style( 'discover', 'assets/css/discover.css' );
		Assets::script( 'discover', 'assets/js/discover.js' );
		wp_add_inline_script(
			'cashaadi-discover',
			'window.CASHAADI_DISCOVER=' . wp_json_encode( array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'csm_tray_nonce' ),
				'discoverUrl' => home_url( '/discover/' ),
			) ) . ';',
			'before'
		);
	}

	/* ---- #11601 like / pass AJAX --------------------------------------- */

	/**
	 * Record a like or a pass.
	 *
	 * Extracted from like()/pass() so the admin-ajax handlers and the REST
	 * endpoint the new Discover screen uses cannot drift apart — the mutual-match
	 * detection below is subtle enough that two copies would eventually disagree
	 * about whether two people had matched.
	 *
	 * @param  int    $viewer_id  Acting member.
	 * @param  int    $profile_id Member acted on.
	 * @param  string $status     'liked' or 'passed'.
	 * @return array{ok:bool,is_mutual:bool,remaining:int}
	 */
	public static function act( $viewer_id, $profile_id, $status ) {
		$viewer_id  = (int) $viewer_id;
		$profile_id = (int) $profile_id;
		$status     = ( 'liked' === $status ) ? 'liked' : 'passed';

		if ( ! $viewer_id || ! $profile_id || $viewer_id === $profile_id ) {
			return array( 'ok' => false, 'is_mutual' => false, 'remaining' => 0 );
		}

		global $wpdb;
		$tray  = $wpdb->prefix . 'csm_tray';
		$likes = $wpdb->prefix . 'csm_likes';
		$now   = current_time( 'mysql' );

		$wpdb->update(
			$tray,
			array( 'status' => $status, 'acted_at' => $now ),
			array( 'viewer_id' => $viewer_id, 'profile_id' => $profile_id ),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		$is_mutual = false;
		if ( 'liked' === $status ) {
			// Mutual-like detection (tray current-week OR likes history). No write
			// to wp_csm_likes here; the weekly reset (#11600) owns like archival.
			$reverse = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT (
					EXISTS( SELECT 1 FROM {$tray}  WHERE viewer_id = %d AND profile_id = %d AND status = 'liked' )
					OR
					EXISTS( SELECT 1 FROM {$likes} WHERE viewer_id = %d AND profile_id = %d )
				)",
				$profile_id, $viewer_id, $profile_id, $viewer_id
			) );
			$is_mutual = $reverse > 0;
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND status = 'pending'",
			$viewer_id
		) );

		if ( function_exists( 'csm_log_event' ) ) {
			csm_log_event( 'liked' === $status ? 'like' : 'pass', $viewer_id, $profile_id );
		}

		/*
		 * A new mutual match just happened. Announce it once, here, where it is
		 * detected — both the admin-ajax like() and the REST rest_act() reach this
		 * method, so a single hook covers every entry point and listeners (e.g.
		 * MatchIntro seeding a conversation) cannot drift from the source of truth.
		 */
		if ( $is_mutual ) {
			do_action( 'csm_mutual_match', $viewer_id, $profile_id );
		}

		return array( 'ok' => true, 'is_mutual' => $is_mutual, 'remaining' => $remaining );
	}

	public static function like() {
		check_ajax_referer( 'csm_tray_nonce', 'nonce' );

		$res = self::act(
			get_current_user_id(),
			isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0,
			'liked'
		);
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => 'Invalid request.' ) );
		}
		wp_send_json_success( array(
			'is_mutual' => $res['is_mutual'],
			'remaining' => $res['remaining'],
		) );
	}

	public static function pass() {
		check_ajax_referer( 'csm_tray_nonce', 'nonce' );

		$res = self::act(
			get_current_user_id(),
			isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0,
			'passed'
		);
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => 'Invalid request.' ) );
		}
		wp_send_json_success( array( 'remaining' => $res['remaining'] ) );
	}

	/* ---- #11602 tray shortcode ----------------------------------------- */

	/**
	 * Height chip: the site stores Height in centimetres, the design shows
	 * imperial ("5′ 4″"). Mirrors the conversion the profile wizard already does
	 * in JS (round to whole inches, then split into feet + inches).
	 *
	 * @param  string $cm Raw field value.
	 * @return string     e.g. "5′ 4″", or '' when not a usable number.
	 */
	private static function height_label( $cm ) {
		if ( ! is_numeric( $cm ) ) {
			return '';
		}
		$cm = (int) round( (float) $cm );
		if ( $cm < 100 || $cm > 260 ) {
			return ''; // outside the wizard's own slider range — treat as unset
		}
		$inches = (int) round( $cm / 2.54 );
		// Literal prime / double-prime — \u{} escapes are NOT interpreted inside
		// single-quoted PHP strings.
		return intdiv( $inches, 12 ) . '′ ' . ( $inches % 12 ) . '″';
	}

	public static function tray() {
		if ( ! is_user_logged_in() ) {
			return '<div class="csm-tray-msg">Please log in to discover matches.</div>';
		}

		$viewer_id = get_current_user_id();

		global $wpdb;
		$tray = $wpdb->prefix . 'csm_tray';

		// Belt & braces: ensure the tray is filled (no-op if already filled).
		if ( function_exists( 'csm_refill_tray' ) ) {
			csm_refill_tray( $viewer_id );
		}

		$current_week = gmdate( 'o-\WW' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT profile_id, week_assigned FROM {$tray}
			 WHERE viewer_id = %d AND status = 'pending'
			 ORDER BY assigned_at ASC",
			$viewer_id
		) );

		ob_start();
		?>
		<div id="csm-discovery-tray" class="csm-tray">
			<?php if ( empty( $rows ) ) : ?>
				<div class="csm-tray-msg csm-tray-empty">
					You're all caught up! New profiles arrive every week. Check back soon.
				</div>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$pid = (int) $row->profile_id;

					$name = bp_core_get_user_displayname( $pid );
					if ( ! $name ) {
						$u    = get_userdata( $pid );
						$name = $u ? $u->display_name : 'Member';
					}

					$avatar = function_exists( 'bp_core_fetch_avatar' )
						? bp_core_fetch_avatar( array( 'item_id' => $pid, 'type' => 'full', 'html' => false ) )
						: get_avatar_url( $pid, array( 'size' => 300 ) );

					// Every field on this site carries a per-field visibility setting
					// ("This field may be seen by: Everyone / Only Me / All Members /
					// My Friends"). xprofile_get_field_data() does NOT enforce it —
					// BuddyPress applies visibility in its own profile loop — so a
					// card that reads fields directly would happily print data a
					// member marked private. Resolve the viewer's hidden-field list
					// once per profile and skip anything in it.
					$hidden = function_exists( 'bp_xprofile_get_hidden_fields_for_user' )
						? array_map( 'intval', (array) bp_xprofile_get_hidden_fields_for_user( $pid, $viewer_id ) )
						: array();

					// NOTE: the field names here are the site's REAL xProfile labels.
					// This card previously asked for 'Location' and 'About Me', which
					// do not exist on this install — so both lines always rendered
					// empty. The real fields are 'City' and 'Bio' (group 1).
					$f = function( $label ) use ( $pid, $hidden ) {
						if ( ! function_exists( 'xprofile_get_field_data' ) ) {
							return '';
						}
						if ( $hidden && function_exists( 'xprofile_get_field_id_from_name' ) ) {
							$fid = (int) xprofile_get_field_id_from_name( $label );
							if ( $fid && in_array( $fid, $hidden, true ) ) {
								return ''; // member has restricted this field from this viewer
							}
						}
						return trim( (string) xprofile_get_field_data( $label, $pid ) );
					};

					$age = $f( 'Age' );
					// 'Age' is filtered on this site and can come back as "27 years
					// old"; the card wants the bare number.
					if ( $age && preg_match( '/\d+/', $age, $m ) ) {
						$age = $m[0];
					}

					$city  = $f( 'City' );
					$bio   = $f( 'Bio' );
					$title = $f( 'Current Job Title' );

					// "Chartered Accountant · Mumbai"
					$sub = implode( ' · ', array_filter( array( $title, $city ) ) );

					// Fact chips. This is a MATRIMONIAL profile, so the facts people
					// actually decide on are qualification, community, language, diet
					// and family context — not dating-app prompts. Every one of these
					// is a real xProfile field (see docs/FIELD-INVENTORY.md); each is
					// visibility-filtered by $f() and simply omitted when unset, so a
					// sparse profile degrades to a clean card rather than empty chips.
					$chips = array_filter( array(
						$f( 'Qualification' ),
						$f( 'Company Name' ),
						self::height_label( $f( 'Height' ) ),
						$f( 'Religion' ),
						$f( 'Community' ),
						$f( 'Language (Mother Tongue)' ),
						$f( 'Diet' ),
					) );
					// Keep the card scannable — the profile page carries the full set.
					$chips = array_slice( $chips, 0, 6 );

					$verified = class_exists( Verification::class ) && Verification::ca_verified( $pid );

					$profile_url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $pid ) : get_author_posts_url( $pid );
					$is_new      = ( isset( $row->week_assigned ) && $row->week_assigned === $current_week );
				?>
					<article class="csm-card" data-profile-id="<?php echo esc_attr( $pid ); ?>">
						<a class="csm-card-media" href="<?php echo esc_url( $profile_url ); ?>">
							<div class="csm-card-photo" style="background-image:url('<?php echo esc_url( $avatar ); ?>');"></div>
							<?php if ( $verified ) : ?>
								<span class="csm-card-verified"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>ICAI Verified</span>
							<?php endif; ?>
							<?php if ( $is_new ) : ?>
								<span class="csm-badge-new">&#10024; NEW</span>
							<?php endif; ?>
							<div class="csm-card-overlay">
								<h3 class="csm-card-name"><?php echo esc_html( $name ); ?><?php if ( $age ) : ?><span class="csm-card-age">, <?php echo esc_html( $age ); ?></span><?php endif; ?></h3>
								<?php if ( $sub ) : ?>
									<p class="csm-card-sub"><?php echo esc_html( $sub ); ?></p>
								<?php endif; ?>
							</div>
						</a>

						<?php if ( $chips || $bio ) : ?>
							<div class="csm-card-body">
								<?php if ( $chips ) : ?>
									<ul class="csm-chips">
										<?php foreach ( $chips as $chip ) : ?>
											<li class="csm-chip"><?php echo esc_html( $chip ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<?php if ( $bio ) : ?>
									<p class="csm-card-bio"><?php echo esc_html( wp_trim_words( $bio, 28 ) ); ?></p>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div class="csm-card-actions">
							<button type="button" class="csm-btn csm-act csm-pass" data-profile-id="<?php echo esc_attr( $pid ); ?>" aria-label="<?php esc_attr_e( 'Pass', 'cashaadi-ui' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
							</button>
							<button type="button" class="csm-btn csm-act csm-like" data-profile-id="<?php echo esc_attr( $pid ); ?>" aria-label="<?php esc_attr_e( 'Like', 'cashaadi-ui' ); ?>">
								<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.7-9.6-9A5.4 5.4 0 0 1 12 6.2 5.4 5.4 0 0 1 21.6 12c-2.1 4.3-9.6 9-9.6 9z"></path></svg>
							</button>
						</div>
					</article>
				<?php endforeach; ?>
			<?php endif; ?>

			<div id="csm-tray-toast" class="csm-toast" style="display:none;"></div>
		</div>
		<?php
		// Ensure the tray JS + config are present even if this shortcode renders
		// on a page where the member-scoped enqueue above didn't run.
		Assets::script( 'discover', 'assets/js/discover.js' );
		if ( ! wp_style_is( 'cashaadi-discover', 'enqueued' ) ) {
			Assets::style( 'discover', 'assets/css/discover.css' );
		}
		return ob_get_clean();
	}

	/* ---- #11675 quota banner ------------------------------------------- */

	public static function quota_banner( $output, $tag ) {
		if ( 'cashaadi_discovery_tray' !== $tag ) {
			return $output;
		}
		$banner = self::quota_banner_html();
		return '' !== $banner ? $banner . $output : $output;
	}

	/** Public so DiscoverScreen's empty state can quote the same reset moment. */
	public static function next_monday_ist() {
		try {
			$tz  = new \DateTimeZone( 'Asia/Kolkata' );
			$now = new \DateTime( 'now', $tz );
			$next = clone $now;
			$dow  = (int) $now->format( 'N' ); // 1=Mon .. 7=Sun
			$add  = ( 8 - $dow ) % 7;
			if ( 0 === $add ) { $add = 7; }    // always the UPCOMING Monday
			$next->modify( '+' . $add . ' day' );
			$next->setTime( 0, 0, 0 );
			return $next;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function quota_banner_html() {
		if ( ! is_user_logged_in() || ! function_exists( 'cashaadi' ) ) {
			return '';
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return '';
		}

		global $wpdb;
		$csm = cashaadi();
		if ( ! $csm || ! method_exists( $csm, 'table' ) || ! method_exists( $csm, 'get_week_id' ) ) {
			return '';
		}
		$tray = $csm->table( 'tray' );
		$week = $csm->get_week_id();

		// Same authoritative rule as #11599: free = 5, Premium (level 2) = 10.
		$quota = 5;
		if ( function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( 2, $uid ) ) {
			$quota = 10;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND week_assigned = %s",
			$uid, $week
		) );
		$acted = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tray} WHERE viewer_id = %d AND week_assigned = %s AND status <> %s",
			$uid, $week, 'pending'
		) );
		$denom = $total > 0 ? $total : $quota;
		if ( $acted > $denom ) { $acted = $denom; }

		$next_dt  = self::next_monday_ist();
		$date_str = $next_dt ? $next_dt->format( 'l, j M Y' ) : 'next Monday';

		$acted_esc = (int) $acted;
		$quota_esc = (int) $quota;

		$line1 = sprintf( 'You have already acted on %d out of %d profiles this week.', $acted_esc, $quota_esc );
		$line2 = sprintf( '%d more profiles will be unlocked on %s.', $quota_esc, esc_html( $date_str ) );
		$line3 = 'Like or Pass the existing ones to make the most of your weekly picks — fresh profiles arrive this Monday.';

		$html  = '<div class="csm-quota-banner" role="note">';
		$html .= '<strong class="csm-quota-title">How Discover works</strong>';
		$html .= '<span class="csm-quota-line">You get <strong>' . $quota_esc . ' profiles per week</strong>' . ( 10 === $quota_esc ? ' (Premium)' : '' ) . '. ' . esc_html( $line1 ) . '</span>';
		$html .= '<span class="csm-quota-line">' . esc_html( $line2 ) . '</span>';
		$html .= '<span class="csm-quota-line csm-quota-hint">' . esc_html( $line3 ) . '</span>';

		$is_premium = ( 10 === $quota_esc );
		$exhausted  = ( ! $is_premium && $acted >= $quota );
		if ( $exhausted ) {
			$html .= '<div class="csm-quota-upgrade">';
			$html .= '<span class="csm-quota-upgrade-msg">You have used all your free profiles this week.</span>';
			$html .= '<a class="csm-quota-upgrade-btn" href="' . esc_url( site_url( '/membership-pricing/' ) ) . '">Upgrade to Premium</a>';
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/* ---- #11681 profile CTA -------------------------------------------- */

	public static function profile_cta() {
		if ( ! function_exists( 'bp_is_my_profile' ) || ! bp_is_my_profile() ) {
			return;
		}
		printf(
			'<div class="csm-discover-cta-wrap"><a class="csm-discover-cta" href="%s">Discover Matches &rarr;</a></div>',
			esc_url( home_url( '/discover/' ) )
		);
	}

	/* ---- #11605 login redirect ----------------------------------------- */

	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! is_a( $user, 'WP_User' ) ) {
			return $redirect_to;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}
		if ( ! empty( $requested_redirect_to ) && false === strpos( $requested_redirect_to, 'wp-admin' ) ) {
			return $requested_redirect_to;
		}
		return home_url( '/discover/' );
	}
}
