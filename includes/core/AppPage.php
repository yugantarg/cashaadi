<?php
/**
 * The app document.
 *
 * /welcome/ proved the pattern: render our own page instead of a theme
 * template. Every layout bug this rebuild has hit — floated list items, a
 * screen-reader-clipped logo, a hamburger opening an empty panel, a stylesheet
 * that was never enqueued on the screen that needed it — came from fighting
 * BuddyX and BuddyPress for control of the markup. Owning the document ends the
 * fight, and the proof is that welcome.css contains no `!important` at all.
 *
 * This is that shell, generalised so Discover, Requests, Messages and Profile
 * share one header, one bottom nav and one set of tokens rather than each
 * re-deriving them.
 *
 * wp_head()/wp_footer() are still called: analytics, the advertising tags and
 * anything else the site injects live there, and a member screen that silently
 * dropped them would make the funnel unmeasurable.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AppPage {

	/**
	 * Bottom-nav destinations, in the owner's information architecture:
	 * "discover shows the full profile of the other user, requests shows sent /
	 * received / viewers, messages shows current matches, profile shows my
	 * profile".
	 */
	public static function nav( $current = '' ) {
		$me = function_exists( 'bp_members_get_user_url' ) && is_user_logged_in()
			? trailingslashit( bp_members_get_user_url( get_current_user_id() ) )
			: home_url( '/' );

		$messages = function_exists( 'bp_get_messages_slug' ) ? bp_get_messages_slug() : 'messages';

		return array(
			'discover' => array(
				'label' => __( 'Discover', 'cashaadi-ui' ),
				'url'   => home_url( '/discover/' ),
				'icon'  => '<circle cx="12" cy="12" r="9"></circle><polygon points="15.5 8.5 10.5 10.5 8.5 15.5 13.5 13.5"></polygon>',
			),
			'requests' => array(
				'label' => __( 'Requests', 'cashaadi-ui' ),
				/*
				 * Our own /requests/ route, not BuddyPress's member sub-tab. The
				 * screen consolidates three sources (received, sent, viewers) that
				 * live in three different BuddyPress/Premium places, so there is no
				 * single BP URL that shows it.
				 */
				'url'   => home_url( '/requests/' ),
				'icon'  => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"></path>',
			),
			'messages' => array(
				'label' => __( 'Messages', 'cashaadi-ui' ),
				'url'   => $me . $messages . '/',
				'icon'  => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 20.6l1.6-5.1A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"></path>',
			),
			'profile'  => array(
				'label' => __( 'Profile', 'cashaadi-ui' ),
				'url'   => $me,
				'icon'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
			),
		);
	}

	/**
	 * Open the document. Everything between this and close() is the screen.
	 *
	 * @param string $title   Browser title.
	 * @param string $current Nav key to highlight.
	 */
	public static function open( $title, $current = '' ) {
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( $title ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="csm-app csm-app-<?php echo esc_attr( $current ); ?>">
	<div class="csm-app-wrap">
		<header class="csm-app-top">
			<a class="csm-app-brand" href="<?php echo esc_url( home_url( '/discover/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
			<button type="button" class="csm-app-menu-btn" id="csm-app-menu-btn" aria-expanded="false" aria-controls="csm-app-menu" aria-label="<?php esc_attr_e( 'Menu', 'cashaadi-ui' ); ?>">
				<span></span><span></span><span></span>
			</button>
		</header>

		<?php self::menu(); ?>

		<main class="csm-app-main" id="csm-app-main">
		<?php
	}

	/** The hamburger's contents — the options that are not bottom-nav destinations. */
	private static function menu() {
		$me = function_exists( 'bp_members_get_user_url' ) && is_user_logged_in()
			? trailingslashit( bp_members_get_user_url( get_current_user_id() ) )
			: home_url( '/' );

		$items = array(
			array( __( 'Edit my profile', 'cashaadi-ui' ), $me . 'profile/edit/group/1/' ),
			array( __( 'My photos', 'cashaadi-ui' ), $me . 'profile/change-avatar/' ),
			array( __( 'Settings', 'cashaadi-ui' ), $me . 'settings/' ),
			array( __( 'Help & support', 'cashaadi-ui' ), 'mailto:' . Config::SUPPORT_EMAIL ),
			array( __( 'Log out', 'cashaadi-ui' ), wp_logout_url( home_url( '/' ) ) ),
		);
		?>
		<nav class="csm-app-menu" id="csm-app-menu" hidden>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li><a href="<?php echo esc_url( $item[1] ); ?>"><?php echo esc_html( $item[0] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/** Close the document: support footer, bottom nav, wp_footer. */
	public static function close( $current = '' ) {
		?>
		</main>

		<footer class="csm-app-support">
			<?php
			printf(
				/* translators: %s: support email address. */
				esc_html__( 'Need help? Email us at %s', 'cashaadi-ui' ),
				'<a href="mailto:' . esc_attr( Config::SUPPORT_EMAIL ) . '">' . esc_html( Config::SUPPORT_EMAIL ) . '</a>'
			);
			?>
		</footer>

		<nav class="csm-app-nav" aria-label="<?php esc_attr_e( 'Main', 'cashaadi-ui' ); ?>">
			<?php foreach ( self::nav( $current ) as $key => $item ) : ?>
				<a class="csm-app-nav-item<?php echo $key === $current ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( $item['url'] ); ?>"
					<?php echo $key === $current ? 'aria-current="page"' : ''; ?>>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup above ?></svg>
					<span><?php echo esc_html( $item['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Shared assets for every app screen.
	 *
	 * Called before render, so the handles are enqueued by the time wp_head runs.
	 */
	public static function assets() {
		Assets::style( 'tokens', 'assets/css/tokens.css' );
		Assets::style( 'app-screens', 'assets/css/app-screens.css', array( 'cashaadi-tokens' ) );
		Assets::script( 'app-screens', 'assets/js/app-screens.js' );
	}

	/**
	 * Standard guard for an app route: logged-in only, and tell WordPress this is
	 * not the 404 it decided it was before we claimed the URL.
	 *
	 * @return bool True to proceed with rendering.
	 */
	public static function claim( $path ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$here = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		if ( strtolower( $here ) !== trim( $path, '/' ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . trim( $path, '/' ) . '/' ) ) );
			exit;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		nocache_headers();
		return true;
	}
}
