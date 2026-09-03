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
	 * The site logo URL for our headers, or '' when none is configured.
	 *
	 * WordPress's custom_logo first; if unset (as on this install) fall back to
	 * the media item with slug 'cashaadi-logo' — by slug, because attachment ids
	 * differ between staging and production. Cached for a day.
	 */
	public static function logo_src() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			$cached = get_transient( 'csm_logo_id' );
			if ( false === $cached ) {
				$att    = get_page_by_path( 'cashaadi-logo', OBJECT, 'attachment' );
				$cached = $att ? (int) $att->ID : 0;
				set_transient( 'csm_logo_id', $cached, DAY_IN_SECONDS );
			}
			$logo_id = (int) $cached;
		}
		$logo = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
		return ( $logo && ! empty( $logo[0] ) ) ? (string) $logo[0] : '';
	}

	/**
	 * Bottom-nav destinations, in the owner's information architecture:
	 * "discover shows the full profile of the other user, requests shows sent /
	 * received / viewers, messages shows current matches, profile shows my
	 * profile".
	 */
	/*
	 * Icon paths are unmodified Feather icons (compass / heart / message-circle /
	 * user). They are DATA — do not hand-simplify the `d` and `points` attributes.
	 * Earlier versions of these were rounded by hand, and the settings gear built
	 * the same way rendered as a lump at 21px, which is the "mangled settings
	 * button" that was reported three times.
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
				'icon'  => '<circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>',
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
				'icon'  => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>',
			),
			'messages' => array(
				'label' => __( 'Messages', 'cashaadi-ui' ),
				'url'   => $me . $messages . '/',
				'icon'  => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>',
			),
			'profile'  => array(
				'label' => __( 'Profile', 'cashaadi-ui' ),
				// Our own /profile/ hub, not BuddyPress's member page — this tab is
				// "what do I still need to do", and the public view links out of it.
				'url'   => home_url( '/profile/' ),
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
			<a class="csm-app-brand" href="<?php echo esc_url( home_url( '/discover/' ) ); ?>">
				<?php
				/*
				 * The real logo when the site has one, the wordmark only as a
				 * fallback. Owner: "The CA shaadi in header should be our logo not
				 * the words." custom_logo is WordPress's own setting, so this picks
				 * up whatever is configured in the Customiser without hardcoding a
				 * path that could 404 after a media change.
				 */
				$logo_id = (int) get_theme_mod( 'custom_logo' );

				/*
				 * The theme's custom_logo is unset on this install, so the header fell
				 * back to the wordmark everywhere — which is what the owner objected
				 * to. There IS a logo in the library (cashaadi-logo.png), so fall back
				 * to it by SLUG rather than by id: ids differ between staging and
				 * production, a hardcoded one would 404 on the other environment.
				 *
				 * Cached for a day because this is a query on every app page render.
				 * Setting custom_logo in the Customiser makes this branch moot and
				 * gives the marketing pages the logo too.
				 */
				if ( ! $logo_id ) {
					$cached = get_transient( 'csm_logo_id' );
					if ( false === $cached ) {
						$att    = get_page_by_path( 'cashaadi-logo', OBJECT, 'attachment' );
						$cached = $att ? (int) $att->ID : 0;
						set_transient( 'csm_logo_id', $cached, DAY_IN_SECONDS );
					}
					$logo_id = (int) $cached;
				}

				$logo = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
				if ( $logo && ! empty( $logo[0] ) ) {
					printf(
						'<img class="csm-app-logo" src="%s" alt="%s">',
						esc_url( $logo[0] ),
						esc_attr( get_bloginfo( 'name' ) )
					);
				} else {
					echo esc_html( get_bloginfo( 'name' ) );
				}
				?>
			</a>
			<button type="button" class="csm-app-menu-btn" id="csm-app-menu-btn" aria-expanded="false" aria-controls="csm-app-menu" aria-label="<?php esc_attr_e( 'Menu', 'cashaadi-ui' ); ?>">
				<span></span><span></span><span></span>
			</button>
		</header>

		<?php self::menu(); ?>

		<main class="csm-app-main" id="csm-app-main">
		<?php
	}

	/**
	 * The hamburger.
	 *
	 * Redesigned (owner: "the hamburger menu should be redesigned to match our new
	 * philosophy"). It was a flat list of five links that duplicated the bottom nav's
	 * neighbourhood and buried Log out among them.
	 *
	 * Now it answers "who am I and what can I do that is not a tab": the member's
	 * own identity at the top, then the things that have no home in the bottom nav,
	 * then Log out set apart because it is the one destructive action here.
	 *
	 * Everything else — Discover, Requests, Messages, Profile — is a tab, and does
	 * not belong in a menu as well.
	 */
	private static function menu() {
		$uid = get_current_user_id();
		$me  = function_exists( 'bp_members_get_user_url' ) && $uid
			? trailingslashit( bp_members_get_user_url( $uid ) )
			: home_url( '/' );

		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( ! $name ) {
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : __( 'My account', 'cashaadi-ui' );
		}

		$edit = class_exists( '\CAShaadi\Modules\ProfileEdit\ProfileEditScreen' )
			? \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url()
			: $me . 'profile/edit/group/1/';
		$settings = class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' )
			? \CAShaadi\Modules\Settings\SettingsScreen::url()
			: $me . 'settings/';

		$items = array(
			array( __( 'Edit my profile', 'cashaadi-ui' ), $edit ),
			array( __( 'My photos', 'cashaadi-ui' ), $me . 'profile/change-avatar/' ),
			// The owner asked to be able to see their profile as others do; the
			// public view is exactly that, so it belongs here rather than only on
			// the hub.
			array( __( 'View as others see me', 'cashaadi-ui' ), home_url( '/profile/preview/' ) ),
			array( __( 'Settings', 'cashaadi-ui' ), $settings ),
			array( __( 'Help & support', 'cashaadi-ui' ), 'mailto:' . Config::SUPPORT_EMAIL ),
		);
		?>
		<nav class="csm-app-menu" id="csm-app-menu" hidden>
			<div class="csm-app-menu-me">
				<?php
				echo get_avatar( $uid, 80, '', '', array( 'class' => 'csm-app-menu-av' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup
				?>
				<span class="csm-app-menu-name"><?php echo esc_html( $name ); ?></span>
			</div>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li><a href="<?php echo esc_url( $item[1] ); ?>"><?php echo esc_html( $item[0] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<a class="csm-app-menu-out" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
				<?php esc_html_e( 'Log out', 'cashaadi-ui' ); ?>
			</a>
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
		// In-app confirm/toast, replacing browser dialogs. A dependency rather than
		// an import because the same file is used on BuddyPress screens too.
		Assets::script( 'ui-dialog', 'assets/js/ui-dialog.js' );
		Assets::script( 'app-screens', 'assets/js/app-screens.js', array( 'cashaadi-ui-dialog' ) );
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
