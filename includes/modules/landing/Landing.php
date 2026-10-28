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
 * Numbers (owner): members, matches made and joined this week. Real,
 * rounded down to the hundred. Members and matches only grow and are never
 * shown lower than the highest figure shown before (csm_landing_peaks);
 * this week's joins go up and down, so they show as they are and are hidden
 * below WEEK_MIN. No ICAI count.
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
	const STATS  = 'csm_landing_stats_v2'; // v2: members, matches, week
	const FEED   = 'csm_landing_feed';
	const WEEK_MIN = 300; // "joined this week" is hidden below this

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
		wp_enqueue_style( 'cashaadi-landing-fonts', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@1,6..72,500&display=swap', array(), null );
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
			/*
			 * This week's joins go up and down, so they are never held at a
			 * past high: the real figure, hidden in a week below WEEK_MIN.
			 */
			if ( 'week' === $key ) {
				if ( $shown >= self::WEEK_MIN ) {
					$out[ $key ] = $shown;
				}
				continue;
			}
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

	/** The live counts (owner, 2026-10-28: members, matches made, joined this week); null where a table is missing. */
	private static function count() {
		global $wpdb;
		$skip = array_unique( array_merge(
			array( 0 ),
			class_exists( '\\CAShaadi\\Modules\\Settings\\Closed' ) ? array_map( 'intval', (array) \CAShaadi\Modules\Settings\Closed::ids() ) : array(),
			array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) )
		) );
		$has  = function ( $t ) use ( $wpdb ) { return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); };
		$fr   = $wpdb->prefix . 'bp_friends';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'members'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID NOT IN (" . implode( ',', $skip ) . ')' ),
			'matches'  => $has( $fr ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fr} WHERE is_confirmed = 1" ) : null,
			'week'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND ID NOT IN (" . implode( ',', $skip ) . ')', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ),
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
			array( 'name' => 'Meera', 'age' => 28, 'city' => 'Hyderabad', 'job' => 'Finance Manager', 'tags' => array( 'Verified CA', 'Telugu', '5\'3"' ), 'photo' => $img( 5 ), 'g' => 'f' ),
			array( 'name' => 'Arjun', 'age' => 31, 'city' => 'Ahmedabad', 'job' => 'Own CA practice', 'tags' => array( 'Verified CA', 'Gujarati', '5\'11"' ), 'photo' => $img( 6 ), 'g' => 'm' ),
			array( 'name' => 'Neha', 'age' => 25, 'city' => 'Chennai', 'job' => 'Audit Associate', 'tags' => array( 'Verified CA', 'Tamil', '5\'2"' ), 'photo' => $img( 7 ), 'g' => 'f' ),
			array( 'name' => 'Vikram', 'age' => 28, 'city' => 'Udaipur', 'job' => 'Manager, Internal Audit', 'tags' => array( 'Verified CA', 'Marwari', '5\'9"' ), 'photo' => $img( 8 ), 'g' => 'm' ),
			array( 'name' => 'Kavya', 'age' => 27, 'city' => 'Gurugram', 'job' => 'Assistant Manager, Taxation', 'tags' => array( 'Verified CA', 'Hindi', '5\'4"' ), 'photo' => $img( 9 ), 'g' => 'f' ),
			array( 'name' => 'Aditya', 'age' => 29, 'city' => 'Nagpur', 'job' => 'Finance Controller', 'tags' => array( 'Verified CA', 'Marathi', '5\'8"' ), 'photo' => $img( 10 ), 'g' => 'm' ),
			array( 'name' => 'Tanvi', 'age' => 27, 'city' => 'Goa', 'job' => 'Senior Consultant', 'tags' => array( 'Verified CA', 'Konkani', '5\'5"' ), 'photo' => $img( 11 ), 'g' => 'f' ),
		);
	}

	/** $lazy: the photo loads only when the deck is about to show the card (landing.js). */
	private static function card( $p, $cls = '', $lazy = false ) {
		$fallback = Assets::url( 'assets/img/avatar-' . ( 'f' === $p['g'] ? 'female' : 'male' ) . '.svg' );
		ob_start();
		?>
		<div class="lp-card <?php echo esc_attr( $cls ); ?>">
			<div class="lp-card-photo" <?php echo $lazy ? 'data-bg' : 'style'; ?>="<?php echo esc_attr( ( $lazy ? '' : 'background-image:url(\'' ) . esc_url( $p['photo'] ? $p['photo'] : $fallback ) . ( $lazy ? '' : '\')' ) ); ?>">
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
			'members'  => 'members',
			'matches'  => 'matches made',
			'week'     => 'joined this week',
		);
		$demo  = self::demo();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( 'CA Shaadi | India’s exclusive CA matrimony' ); ?></title>
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
			<p class="lp-kicker lp-in">India’s exclusive CA matrimony</p>
			<h1 class="lp-in">Find your <em>CA match.</em></h1>
			<ul class="lp-ticks lp-in">
				<li>Verified CA profiles</li>
				<li>Safe and private</li>
				<li>Free to register</li>
			</ul>
			<div class="lp-cta-row lp-in">
				<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn" data-csm-cta="hero">Create your free profile</a>
				<a href="#how" class="lp-btn lp-btn-ghost">How it works</a>
			</div>
			<div class="lp-live lp-in" id="lp-live" aria-live="polite" hidden></div>
		</div>
		<div class="lp-hero-phone lp-in">
			<div class="lp-phone">
				<div class="lp-phone-bar"><span>Discover</span></div>
				<div class="lp-deck" id="lp-deck">
					<?php foreach ( $demo as $i => $p ) : ?>
						<?php echo self::card( $p, 0 === $i ? 'is-top' : '', $i > 1 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped ?>
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

	<div class="lp-cta-mid lp-in"><a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-big" data-csm-cta="stats">Create your free profile</a><span>Free to register</span></div>

	<section class="lp-how" id="how">
		<h2 class="lp-in">How it works</h2>
		<div class="lp-step">
			<div class="lp-step-text lp-in">
				<span class="lp-num">1</span>
				<h3>Sign up and verify</h3>
				<p>Create your free profile. Upload your ICAI certificate or ID card to get the Verified CA badge.</p>
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
				<h3>See your matches</h3>
				<p>New profiles every Monday. Use filters to find what you want.</p>
			</div>
			<div class="lp-mini lp-in">
				<div class="lp-mini-screen"><?php echo self::card( $demo[2], 'is-top lp-card-still' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped ?></div>
			</div>
		</div>
		<div class="lp-step">
			<div class="lp-step-text lp-in">
				<span class="lp-num">3</span>
				<h3>Connect</h3>
				<p>Send a request. When they accept, you can chat in the app.</p>
			</div>
			<div class="lp-mini lp-in">
				<div class="lp-mini-screen lp-chat">
					<div class="lp-chat-head"><i style="background-image:url('<?php echo esc_url( $demo[1]['photo'] ? $demo[1]['photo'] : Assets::url( 'assets/img/avatar-male.svg' ) ); ?>')"></i><b><?php echo esc_html( $demo[1]['name'] ); ?></b><span>Match</span></div>
					<p class="lp-msg lp-msg-in">Hi! Nice to match with you.</p>
					<p class="lp-msg lp-msg-out">Hi! Same here. Which city are you in?</p>
					<p class="lp-msg lp-msg-in">Mumbai. You?</p>
				</div>
			</div>
		</div>
	</section>

	<div class="lp-cta-mid lp-in"><a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-big" data-csm-cta="how">Create your free profile</a><span>Free to register</span></div>

	<section class="lp-trust">
		<h2 class="lp-in">Safe and private</h2>
		<div class="lp-tiles">
			<div class="lp-tile lp-in"><b>Private photos</b><p>Keep your photos blurred until you match.</p></div>
			<div class="lp-tile lp-in"><b>Your phone number</b><p>You choose who can see it.</p></div>
			<div class="lp-tile lp-in"><b>Block and report</b><p>Block anyone in one tap. We check every report.</p></div>
			<div class="lp-tile lp-in"><b>Verified badge</b><p>See who has verified their ICAI document.</p></div>
		</div>
	</section>

	<div class="lp-cta-mid lp-in"><a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-big" data-csm-cta="trust">Create your free profile</a><span>Free to register</span></div>

	<section class="lp-plans lp-in">
		<div>
			<h2>Free to register</h2>
			<p>Premium and Gold give you more profiles and more features.</p>
		</div>
		<div class="lp-plans-acts">
			<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn" data-csm-cta="plans">Create your free profile</a>
			<a href="<?php echo esc_url( home_url( '/membership-pricing/' ) ); ?>" class="lp-btn lp-btn-ghost">See plans</a>
		</div>
	</section>

	<section class="lp-faq">
		<h2 class="lp-in">Questions</h2>
		<details class="lp-in"><summary>Who can join?</summary><p>CA Shaadi is mainly for Chartered Accountants and CA students. People from related professions, like CS, CFA, CMA and ACCA, are welcome to join too. The Verified badge is given only to CAs and CA students who verify with ICAI.</p></details>
		<details class="lp-in"><summary>Is it free?</summary><p>Yes, registering is free. You get new profiles every week. Premium and Gold are optional.</p></details>
		<details class="lp-in"><summary>Who can see my photos?</summary><p>Only logged-in members. Set them to private and they stay blurred until you match.</p></details>
		<details class="lp-in"><summary>How do I get verified?</summary><p>Upload your ICAI certificate or ID card from your profile. Once checked, you get the Verified CA badge.</p></details>
	</section>

	<div class="lp-cta-mid lp-in"><a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-big" data-csm-cta="faq">Create your free profile</a><span>Free to register</span></div>

	<section class="lp-final lp-in">
		<h2>Find your CA match.</h2>
		<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn lp-btn-big" data-csm-cta="final">Create your free profile</a>
	</section>
</main>

<?php // Always in reach once the hero's button has scrolled away (owner, 2026-10-28: "so no one can miss it"). ?>
<div class="lp-sticky" id="lp-sticky" aria-hidden="true">
	<span>Free to register</span>
	<a href="<?php echo esc_url( $reg ); ?>" class="lp-btn" data-csm-cta="sticky" tabindex="-1">Create your free profile</a>
</div>

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
