<?php
/**
 * The landing page, rebuilt (owner, 2026-10-28: "make it premium with app
 * screens and animations ... show live data instead of 2000 CAs joined").
 *
 * Its own document, like the app screens: the theme's and Elementor's styles
 * are left out, so the page carries only its own CSS (speed on phones). The
 * scripts stay, so Yoast's tags, the Meta pixel and Google tags still run.
 *
 *   /home-preview/       always, noindex, for review;
 *   the front page       for logged-out visitors once the option
 *                        csm_landing_live is 1 (Elementor's page stays as it
 *                        is, so switching back is the option alone).
 *
 * Numbers (owner): real, rounded down to the hundred, and never lower than
 * the highest figure shown before (csm_landing_peaks). Only totals that
 * only grow are used, so the floor is never untrue. No ICAI count.
 *
 * Activity line (owner): real joins, alternating men and women, with first
 * name, city and the blurred thumbnail the app uses; matches without names.
 * Never a member whose photos are private, paused, held, closed or an admin.
 * Served by REST (never cached) so "3 min ago" stays true on a cached page.
 */

namespace CAShaadi\Modules\Landing;

use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Landing {

	const PATH   = 'home-preview';
	const LIVE   = 'csm_landing_live';
	const PEAKS  = 'csm_landing_peaks';
	const STATS  = 'csm_landing_stats';
	const FEED   = 'csm_landing_feed';

	private static $on = false;

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** This request is the landing page. */
	private static function wanted() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$here = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		if ( self::PATH === strtolower( $here ) ) {
			return 'preview';
		}
		if ( get_option( self::LIVE ) && ! is_user_logged_in() && ( is_front_page() || is_home() ) ) {
			return 'live';
		}
		return '';
	}

	public static function maybe_render() {
		$mode = self::wanted();
		if ( ! $mode ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		self::$on = true;
		if ( 'preview' === $mode ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			add_filter( 'wpseo_robots', function () { return 'noindex, nofollow'; } );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 999 );
		self::render();
		exit;
	}

	public static function assets() {
		// Only this page's styles: the theme's and Elementor's add weight it does not use.
		global $wp_styles;
		foreach ( (array) $wp_styles->queue as $h ) {
			if ( 0 !== strpos( $h, 'cashaadi-' ) && 'admin-bar' !== $h ) {
				wp_dequeue_style( $h );
			}
		}
		wp_enqueue_style( 'cashaadi-landing-fonts', 'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&display=swap', array(), null );
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		Assets::style( 'landing', 'assets/css/landing.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'landing', 'assets/js/landing.js' );
		wp_localize_script( 'cashaadi-landing', 'CSM_LANDING', array(
			'feed' => esc_url_raw( rest_url( 'csm/v1/landing/activity' ) ),
		) );
	}

	/* ------------------------------------------------------------ numbers */

	/** Label => shown value ("4,300+"), floored at the highest shown before. */
	public static function stats() {
		$raw = get_transient( self::STATS );
		if ( ! is_array( $raw ) ) {
			$raw = self::count();
			set_transient( self::STATS, $raw, HOUR_IN_SECONDS );
		}
		$peaks = (array) get_option( self::PEAKS, array() );
		$out   = array();
		$dirty = false;
		foreach ( $raw as $key => $n ) {
			if ( null === $n ) {
				continue;
			}
			$shown = (int) floor( (int) $n / 100 ) * 100;
			if ( $shown > (int) ( $peaks[ $key ] ?? 0 ) ) {
				$peaks[ $key ] = $shown;
				$dirty         = true;
			}
			$shown = (int) ( $peaks[ $key ] ?? $shown );
			if ( $shown >= 100 ) {
				$out[ $key ] = $shown;
			}
		}
		if ( $dirty ) {
			update_option( self::PEAKS, $peaks, false );
		}
		return $out;
	}

	/** The live counts. Totals that only grow; null where a table is missing. */
	private static function count() {
		global $wpdb;
		$skip = array_unique( array_merge(
			array( 0 ),
			class_exists( '\\CAShaadi\\Modules\\Settings\\Closed' ) ? array_map( 'intval', (array) \CAShaadi\Modules\Settings\Closed::ids() ) : array(),
			array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) )
		) );
		$has  = function ( $t ) use ( $wpdb ) { return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); };
		$pv   = $wpdb->prefix . 'csm_profile_views';
		$fr   = $wpdb->prefix . 'bp_friends';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'members'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID NOT IN (" . implode( ',', $skip ) . ')' ),
			'matches'  => $has( $fr ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fr} WHERE is_confirmed = 1" ) : null,
			'requests' => $has( $fr ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fr}" ) : null,
			'views'    => $has( $pv ) ? (int) $wpdb->get_var( "SELECT COALESCE( SUM( hits ), COUNT(*) ) FROM {$pv}" ) : null,
		);
		// phpcs:enable
	}

	/* -------------------------------------------------------- activity line */

	public static function routes() {
		register_rest_route( 'csm/v1', '/landing/activity', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_activity' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function rest_activity() {
		$feed = get_transient( self::FEED );
		if ( ! is_array( $feed ) ) {
			$feed = self::feed();
			set_transient( self::FEED, $feed, 10 * MINUTE_IN_SECONDS );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'items' => $feed, 'now' => time() ), 200 );
	}

	/** Joins alternating women and men, a match after every two; times as unix seconds. */
	private static function feed() {
		$men   = self::joins( 'Male' );
		$women = self::joins( 'Female' );
		$match = self::matches();
		$out   = array();
		$n     = max( count( $men ), count( $women ) );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $women[ $i ] ) ) {
				$out[] = $women[ $i ];
			}
			if ( isset( $men[ $i ] ) ) {
				$out[] = $men[ $i ];
			}
			if ( isset( $match[ $i ] ) ) {
				$out[] = $match[ $i ];
			}
		}
		return $out;
	}

	/** Up to 8 recent joins of one gender who may appear publicly. */
	private static function joins( $gender ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT u.ID FROM {$wpdb->users} u
			 JOIN {$wpdb->prefix}bp_xprofile_data g ON g.user_id = u.ID AND g.field_id = %d AND g.value = %s
			 JOIN {$wpdb->usermeta} ph ON ph.user_id = u.ID AND ph.meta_key = 'csm_photos' AND ph.meta_value NOT IN ( '', 'a:0:{}' )
			 WHERE u.user_registered >= %s
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} pr WHERE pr.user_id = u.ID AND pr.meta_key = 'csm_photo_private' AND pr.meta_value = '1' )
			 ORDER BY u.user_registered DESC LIMIT 40",
			Config::FIELD_GENDER,
			$gender,
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		) ) );
		$closed = class_exists( '\\CAShaadi\\Modules\\Settings\\Closed' ) ? array_flip( (array) \CAShaadi\Modules\Settings\Closed::ids() ) : array();
		$out    = array();
		foreach ( $ids as $uid ) {
			if ( isset( $closed[ $uid ] ) || user_can( $uid, 'manage_options' )
				|| ( class_exists( '\\CAShaadi\\Modules\\Settings\\Deactivate' ) && \CAShaadi\Modules\Settings\Deactivate::is_paused( $uid ) )
				|| ( class_exists( '\\CAShaadi\\Modules\\Photos\\PhotoHold' ) && \CAShaadi\Modules\Photos\PhotoHold::is_held( $uid ) )
				|| get_user_meta( $uid, 'csm_pm_av_hidden', true ) ) {
				continue;
			}
			$name = trim( (string) ( function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( Config::FIELD_NAME, $uid ) : '' ) );
			$city = trim( (string) ( function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( Config::FIELD_CITY, $uid ) : '' ) );
			$first = ucfirst( strtolower( (string) strtok( $name, ' ' ) ) );
			if ( '' === $first || '' === $city || mb_strlen( $first ) < 2 ) {
				continue;
			}
			$photo = class_exists( '\\CAShaadi\\Modules\\Photos\\Privacy' ) ? \CAShaadi\Modules\Photos\Privacy::display_url( $uid, 'thumb', '' ) : '';
			$u     = get_userdata( $uid );
			$out[] = array(
				'kind'  => 'joined',
				'name'  => $first,
				'city'  => ucwords( strtolower( $city ) ),
				'photo' => $photo,
				'at'    => $u ? strtotime( $u->user_registered . ' UTC' ) : time(),
			);
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	/** Recent matches, by city only. */
	private static function matches() {
		global $wpdb;
		$t = $wpdb->prefix . 'csm_event_log';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT actor_id, created_at FROM {$t} WHERE event_type = 'match_made' ORDER BY id DESC LIMIT 8" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$city = trim( (string) ( function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( Config::FIELD_CITY, (int) $r->actor_id ) : '' ) );
			$out[] = array(
				'kind' => 'match',
				'city' => '' !== $city ? ucwords( strtolower( $city ) ) : '',
				// event_log stores site-local time.
				'at'   => (int) get_gmt_from_date( $r->created_at, 'U' ),
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------- page */

	private static function demo() {
		$img = function ( $n ) {
			$rel = 'assets/img/landing/demo-' . $n . '.jpg';
			return file_exists( CASHAADI_UI_DIR . $rel ) ? Assets::url( $rel ) : '';
		};
		return array(
			array( 'name' => 'Ananya', 'age' => 27, 'city' => 'Pune', 'job' => 'Senior Associate, Big 4', 'tags' => array( 'Verified CA', 'Marathi', '5\'4"' ), 'photo' => $img( 1 ), 'g' => 'f' ),
			array( 'name' => 'Rohan', 'age' => 29, 'city' => 'Mumbai', 'job' => 'Manager, Corporate Finance', 'tags' => array( 'Verified CA', 'Gujarati', '5\'10"' ), 'photo' => $img( 2 ), 'g' => 'm' ),
			array( 'name' => 'Ishita', 'age' => 26, 'city' => 'Delhi', 'job' => 'Tax Consultant', 'tags' => array( 'Verified CA', 'Punjabi', '5\'5"' ), 'photo' => $img( 3 ), 'g' => 'f' ),
			array( 'name' => 'Karthik', 'age' => 30, 'city' => 'Bengaluru', 'job' => 'Partner, CA Firm', 'tags' => array( 'Verified CA', 'Tamil', '5\'9"' ), 'photo' => $img( 4 ), 'g' => 'm' ),
		);
	}

	private static function card( $p, $cls = '' ) {
		$fallback = Assets::url( 'assets/img/avatar-' . ( 'f' === $p['g'] ? 'female' : 'male' ) . '.svg' );
		ob_start();
		?>
		<div class="lp-card <?php echo esc_attr( $cls ); ?>">
			<div class="lp-card-photo" style="background-image:url('<?php echo esc_url( $p['photo'] ? $p['photo'] : $fallback ); ?>')">
				<span class="lp-badge"><?php echo esc_html( $p['tags'][0] ); ?></span>
				<div class="lp-card-name"><?php echo esc_html( $p['name'] . ', ' . $p['age'] ); ?><small><?php echo esc_html( $p['job'] . ' · ' . $p['city'] ); ?></small></div>
			</div>
			<div class="lp-card-chips">
				<?php foreach ( array_slice( $p['tags'], 1 ) as $t ) : ?><span><?php echo esc_html( $t ); ?></span><?php endforeach; ?>
			</div>
			<div class="lp-card-acts"><i class="lp-pass">✕ Pass</i><i class="lp-save">Save</i><i class="lp-like">♥ Send request</i></div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render() {
		$reg   = home_url( '/register/' );
		$login = home_url( '/login/' );
		$stats = self::stats();
		$label = array(
			'members'  => 'Chartered Accountants and CA students',
			'matches'  => 'matches made',
			'requests' => 'requests sent',
			'views'    => 'profile views',
		);
		$demo  = self::demo();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( 'CA Shaadi | The matrimony app for Chartered Accountants' ); ?></title>
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php wp_head(); ?>
</head>
<body class="lp">
<header class="lp-nav">
	<a class="lp-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped ?></a>
	<nav>
		<a href="<?php echo esc_url( home_url( '/membership-pricing/' ) ); ?>" class="lp-nav-link">Pricing</a>
		<a href="<?php echo esc_url( $login ); ?>" class="lp-nav-link">Log in</a>
		<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-sm" data-csm-cta="nav">Register free</a>
	</nav>
</header>

<main>
	<section class="lp-hero">
		<div class="lp-hero-text">
			<p class="lp-kicker lp-in">Matrimony, made for CAs</p>
			<h1 class="lp-in">Meet someone who gets the <em>long hours</em>, the audits and the ambition.</h1>
			<p class="lp-lead lp-in">CA Shaadi is the matrimony app for Chartered Accountants and CA students. Verify with ICAI, keep your photos private until you choose, and get a fresh set of matches every week.</p>
			<div class="lp-cta-row lp-in">
				<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn" data-csm-cta="hero">Register free</a>
				<a href="#how" class="lp-btn lp-btn-ghost">See how it works</a>
			</div>
			<div class="lp-live lp-in" id="lp-live" aria-live="polite" hidden></div>
		</div>
		<div class="lp-hero-phone lp-in">
			<div class="lp-phone">
				<div class="lp-phone-bar"><span>Discover</span></div>
				<div class="lp-deck" id="lp-deck">
					<?php foreach ( $demo as $i => $p ) : ?>
						<?php echo self::card( $p, 0 === $i ? 'is-top' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped ?>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="lp-float lp-float-a"><b>✓</b> ICAI verified</div>
			<div class="lp-float lp-float-b"><b>♥</b> It's a match!</div>
		</div>
	</section>

	<?php if ( $stats ) : ?>
	<section class="lp-stats">
		<?php foreach ( $stats as $k => $v ) : ?>
			<div class="lp-stat lp-in">
				<b data-count="<?php echo (int) $v; ?>"><?php echo esc_html( number_format_i18n( $v ) ); ?>+</b>
				<span><?php echo esc_html( $label[ $k ] ); ?></span>
			</div>
		<?php endforeach; ?>
	</section>
	<?php endif; ?>

	<section class="lp-how" id="how">
		<h2 class="lp-in">How it works</h2>
		<div class="lp-step">
			<div class="lp-step-text lp-in">
				<span class="lp-num">1</span>
				<h3>Join and verify</h3>
				<p>Sign up free in two minutes. Upload your ICAI certificate or ID card and get the Verified CA badge on your profile, so people know you are who you say.</p>
			</div>
			<div class="lp-mini lp-in">
				<div class="lp-mini-screen lp-verify">
					<div class="lp-verify-badge">✓</div>
					<b>Verified CA</b>
					<p>ICAI membership checked</p>
					<ul><li>Profile complete</li><li>Photo added</li><li>ICAI verified</li></ul>
				</div>
			</div>
		</div>
		<div class="lp-step lp-step-flip">
			<div class="lp-step-text lp-in">
				<span class="lp-num">2</span>
				<h3>Get your matches every week</h3>
				<p>Every Monday, a fresh set of profiles chosen for you: age, city, community, language and what you are looking for. Filter by what matters, save the ones you want to think about.</p>
			</div>
			<div class="lp-mini lp-in">
				<div class="lp-mini-screen"><?php echo self::card( $demo[2], 'is-top lp-card-still' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped ?></div>
			</div>
		</div>
		<div class="lp-step">
			<div class="lp-step-text lp-in">
				<span class="lp-num">3</span>
				<h3>Connect when you both say yes</h3>
				<p>Send a request. When it is accepted, you are a match: photos open up and you can talk privately in the app. Your phone number stays yours until you decide.</p>
			</div>
			<div class="lp-mini lp-in">
				<div class="lp-mini-screen lp-chat">
					<div class="lp-chat-head"><i style="background-image:url('<?php echo esc_url( $demo[1]['photo'] ? $demo[1]['photo'] : Assets::url( 'assets/img/avatar-male.svg' ) ); ?>')"></i><b><?php echo esc_html( $demo[1]['name'] ); ?></b><span>Match</span></div>
					<p class="lp-msg lp-msg-in">Hi! Saw you also cleared in 2021. Which firm are you with now?</p>
					<p class="lp-msg lp-msg-out">Hey! Still at the same Big 4, audit season is around the corner 😅</p>
					<p class="lp-msg lp-msg-in">Ha, I know that feeling. Coffee this weekend?</p>
				</div>
			</div>
		</div>
	</section>

	<section class="lp-trust">
		<h2 class="lp-in">Private by design</h2>
		<div class="lp-tiles">
			<div class="lp-tile lp-in"><b>Your photos, your choice</b><p>Keep them private and they stay blurred for everyone you have not matched with.</p></div>
			<div class="lp-tile lp-in"><b>Your number stays yours</b><p>Decide who can see your phone number, or keep it to yourself.</p></div>
			<div class="lp-tile lp-in"><b>Block and report</b><p>Block anyone in one tap. Reported profiles are reviewed and taken down when they break the rules.</p></div>
			<div class="lp-tile lp-in"><b>Verified badge</b><p>Members who verify with ICAI carry a badge, so you know who has been checked.</p></div>
		</div>
	</section>

	<section class="lp-plans lp-in">
		<div>
			<h2>Free to join. More when you want it.</h2>
			<p>Every member gets a set of profiles each week. Premium brings more profiles and shows who viewed you. Gold adds unlimited profiles and the phone numbers members choose to share.</p>
		</div>
		<a href="<?php echo esc_url( home_url( '/membership-pricing/' ) ); ?>" class="lp-btn lp-btn-ghost">See plans</a>
	</section>

	<section class="lp-faq">
		<h2 class="lp-in">Questions</h2>
		<details class="lp-in"><summary>Who can join?</summary><p>CA Shaadi is built for Chartered Accountants and CA students. Members who verify their ICAI status get the Verified CA badge.</p></details>
		<details class="lp-in"><summary>Is it free?</summary><p>Yes. Joining and your weekly set of profiles are free. Premium and Gold are optional, for more profiles and features.</p></details>
		<details class="lp-in"><summary>Who can see my photos?</summary><p>Logged-in members. If you set your photos to private, they stay blurred for everyone you have not matched with.</p></details>
		<details class="lp-in"><summary>Who can see my phone number?</summary><p>You decide, in your settings: paying members, Gold members only, or nobody.</p></details>
		<details class="lp-in"><summary>How does verification work?</summary><p>Upload your ICAI certificate or ID card from your profile. Once it is checked, the Verified CA badge appears on your profile.</p></details>
	</section>

	<section class="lp-final lp-in">
		<h2>Your person might already be here.</h2>
		<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-light" data-csm-cta="final">Register free</a>
	</section>
</main>

<footer class="lp-foot">
	<nav>
		<a href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">About us</a>
		<a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">Terms</a>
		<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy</a>
		<a href="<?php echo esc_url( home_url( '/refund-policy/' ) ); ?>">Refunds</a>
		<a href="mailto:<?php echo esc_attr( Config::SUPPORT_EMAIL ); ?>"><?php echo esc_html( Config::SUPPORT_EMAIL ); ?></a>
	</nav>
	<p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> SDG Ventures. All rights reserved.</p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/** The site logo, or the wordmark. */
	private static function logo() {
		$id = (int) get_theme_mod( 'custom_logo' );
		$src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		return $src
			? '<img src="' . esc_url( $src ) . '" alt="CA Shaadi" height="36">'
			: '<span>CA Shaadi</span>';
	}
}
