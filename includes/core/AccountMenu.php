<?php
/**
 * Everything about "me", defined once (owner, 2026-10-27: "a logical
 * segregation of what should come into profile and what should come into
 * menu ... easy to reach places regardless of where they are").
 *
 * Two views of the same list, so they can never disagree again (the old
 * hamburger, the Profile page's "Manage" and the Settings page each listed a
 * different subset):
 *
 *   sections()  the Profile tab: every destination, in sections
 *               (My profile, Membership, Privacy & safety, Account, Help);
 *   quick()     the photo menu in the top bar, on every page: the most used
 *               destination of each section and "Everything", which opens
 *               the Profile tab.
 *
 * The Settings page is folded into the Profile tab; /settings/ redirects to
 * it (SettingsScreen). Settings rows still come from SettingsScreen's REST
 * handler, which owns their states (phone, ICAI, plan, boosts).
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountMenu {

	/** The Settings page's own data, built as before. */
	private static function settings( $uid ) {
		static $memo = array();
		if ( ! isset( $memo[ $uid ] ) ) {
			$memo[ $uid ] = array();
			if ( class_exists( '\CAShaadi\Modules\Settings\SettingsScreen' ) && (int) get_current_user_id() === (int) $uid ) {
				$r = \CAShaadi\Modules\Settings\SettingsScreen::rest_get( null );
				$memo[ $uid ] = $r instanceof \WP_REST_Response ? (array) $r->get_data() : array();
			}
		}
		return $memo[ $uid ];
	}

	/** One Settings row by its label, or null. */
	private static function row( $uid, $label ) {
		foreach ( (array) ( self::settings( $uid )['groups'] ?? array() ) as $g ) {
			foreach ( (array) $g['rows'] as $r ) {
				if ( $label === $r['label'] ) {
					return $r;
				}
			}
		}
		return null;
	}

	private static function photos_url( $uid ) {
		return function_exists( 'bp_members_get_user_url' )
			? trailingslashit( bp_members_get_user_url( (int) $uid ) ) . 'profile/change-avatar/'
			: home_url( '/profile/' );
	}

	private static function edit_url( $gid = 0 ) {
		return class_exists( '\CAShaadi\Modules\ProfileEdit\ProfileEditScreen' )
			? \CAShaadi\Modules\ProfileEdit\ProfileEditScreen::url( $gid )
			: home_url( '/profile/edit/' );
	}

	/** The next plan up, as a row, or null for Gold. */
	private static function upgrade( $uid ) {
		$gold    = Membership::is_gold( $uid );
		$premium = Membership::is_premium( $uid );
		if ( $gold ) {
			return null;
		}
		if ( $premium && ! get_option( 'csm_gold_on_sale' ) ) {
			return null;
		}
		return array(
			'label' => $premium ? 'Upgrade to Gold' : 'Upgrade to Premium',
			'url'   => site_url( '/membership-pricing/?src=' . ( $premium ? 'profile-gold' : 'profile' ) ),
			'cta'   => true,
		);
	}

	/**
	 * The Profile tab's sections: array of array( key, title, rows ), each row
	 * array( label, value?, ok?, url, sub?, cta? ).
	 */
	public static function sections( $uid ) {
		$uid = (int) $uid;
		$s   = self::settings( $uid );
		$out = array();

		// My profile.
		$rows   = array();
		$rows[] = array( 'label' => 'Edit profile', 'url' => self::edit_url() );
		$blur   = self::row( $uid, 'Photo blur' );
		$rows[] = array( 'label' => 'My photos', 'url' => self::photos_url( $uid ), 'value' => $blur ? 'Blur ' . strtolower( (string) $blur['value'] ) : '' );
		// "Astro details" left this list (audit, v1.90.96): it is already a row in
		// the profile details above, so it showed twice.
		$rows[] = array( 'label' => 'Who sees what', 'url' => home_url( '/settings/visibility/' ) );
		$rows[] = array( 'label' => 'See how others see me', 'url' => home_url( '/profile/preview/' ) );
		$out[]  = array( 'key' => 'me', 'title' => 'My profile', 'rows' => $rows );

		// Membership.
		$rows = array();
		foreach ( (array) ( $s['groups'] ?? array() ) as $g ) {
			if ( 'Membership' === $g['title'] ) {
				$rows = array_values( array_filter( (array) $g['rows'], function ( $r ) { return 'Profile boosts' !== $r['label']; } ) );
			}
		}
		$up = self::upgrade( $uid );
		if ( $up ) {
			$rows[] = $up;
		}
		if ( class_exists( '\CAShaadi\Modules\Referral\ReferralScreen' ) ) {
			$cash   = class_exists( '\CAShaadi\Modules\Referral\Referral' ) ? (int) \CAShaadi\Modules\Referral\Referral::balance( $uid ) : 0;
			$rows[] = array( 'label' => 'Refer & earn', 'url' => home_url( '/refer/' ), 'value' => $cash > 0 ? '₹' . number_format_i18n( $cash ) . ' cash' : '' );
		}
		// The survey, for invited members who have not answered (v1.90.78).
		if ( class_exists( '\\CAShaadi\\Modules\\Survey\\Survey' ) ) {
			$sv = \CAShaadi\Modules\Survey\Survey::profile_row( $uid );
			if ( $sv ) {
				$rows[] = $sv;
			}
		}
		$out[] = array( 'key' => 'membership', 'title' => 'Membership', 'rows' => $rows );

		// Privacy & safety.
		$rows   = array();
		$rows[] = array( 'label' => 'Blocked members', 'url' => home_url( '/settings/blocked/' ) );
		if ( ! empty( $s['pauseUrl'] ) ) {
			$rows[] = array( 'label' => ! empty( $s['paused'] ) ? 'Profile paused' : 'Pause my profile', 'url' => $s['pauseUrl'], 'value' => ! empty( $s['paused'] ) ? 'Turn back on' : '', 'ok' => ! empty( $s['paused'] ) ? false : null );
		}
		$out[] = array( 'key' => 'privacy', 'title' => 'Privacy & safety', 'rows' => $rows );

		// Account.
		$rows = array();
		foreach ( array( 'Email', 'Phone number', 'Password', 'ICAI verification', 'Email notifications' ) as $label ) {
			$r = self::row( $uid, $label );
			if ( $r ) {
				$rows[] = $r;
			}
		}
		if ( ! empty( $s['deleteUrl'] ) ) {
			$rows[] = array( 'label' => 'Delete my account', 'url' => $s['deleteUrl'] );
		}
		$out[] = array( 'key' => 'account', 'title' => 'Account', 'rows' => $rows );

		// Help.
		$out[] = array( 'key' => 'help', 'title' => 'Help', 'rows' => array(
			array( 'label' => 'Help & support', 'url' => 'mailto:' . Config::SUPPORT_EMAIL, 'value' => Config::SUPPORT_EMAIL ),
			array( 'label' => 'Log out', 'url' => wp_logout_url( home_url( '/' ) ), 'out' => true ),
		) );
		return $out;
	}

	/** The photo menu: label => url, most used first, then Everything and Log out. */
	public static function quick( $uid ) {
		$uid  = (int) $uid;
		$prof = home_url( '/profile/' );
		$items = array(
			array( 'Edit profile', self::edit_url() ),
			array( 'My photos', self::photos_url( $uid ) ),
			array( 'Membership', $prof . '#membership' ),
			array( 'Refer & earn', home_url( '/refer/' ) ),
			array( 'Privacy & account', $prof . '#privacy' ),
			array( 'Help & support', 'mailto:' . Config::SUPPORT_EMAIL ),
		);
		$up = self::upgrade( $uid );
		if ( $up ) {
			array_splice( $items, 2, 0, array( array( $up['label'], $up['url'] ) ) );
		}
		return $items;
	}

	/** The photo button for the top bar. */
	public static function button() {
		$uid = get_current_user_id();
		echo '<button type="button" class="csm-app-me-btn" id="csm-app-menu-btn" aria-expanded="false" aria-controls="csm-app-menu" aria-label="' . esc_attr__( 'Menu', 'cashaadi-ui' ) . '">';
		echo get_avatar( $uid, 64, '', '', array( 'class' => 'csm-app-me-av' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup
		echo '</button>';
	}

	/** The notifications bell with its unread count, or nothing without BuddyPress notifications. */
	public static function bell() {
		if ( ! function_exists( 'bp_loggedin_user_url' ) || ! function_exists( 'bp_notifications_get_unread_notification_count' ) ) {
			return;
		}
		$n   = (int) bp_notifications_get_unread_notification_count( get_current_user_id() );
		$url = trailingslashit( bp_loggedin_user_url() ) . ( function_exists( 'bp_get_notifications_slug' ) ? bp_get_notifications_slug() : 'notifications' ) . '/';
		echo '<a class="csm-app-bell" href="' . esc_url( $url ) . '" aria-label="' . esc_attr__( 'Notifications', 'cashaadi-ui' ) . '">'
			. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>'
			. ( $n > 0 ? '<i class="csm-app-bell-n">' . esc_html( $n > 99 ? '99+' : (string) $n ) . '</i>' : '' )
			. '</a>';
	}

	/** The menu panel the photo button opens. */
	public static function panel() {
		$uid  = get_current_user_id();
		$name = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $uid ) : '';
		if ( ! $name ) {
			$u    = get_userdata( $uid );
			$name = $u ? $u->display_name : __( 'My account', 'cashaadi-ui' );
		}
		?>
		<nav class="csm-app-menu" id="csm-app-menu" hidden>
			<a class="csm-app-menu-me" href="<?php echo esc_url( home_url( '/profile/' ) ); ?>">
				<?php echo get_avatar( $uid, 80, '', '', array( 'class' => 'csm-app-menu-av' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup ?>
				<span class="csm-app-menu-name"><?php echo esc_html( $name ); ?><small><?php esc_html_e( 'View my profile', 'cashaadi-ui' ); ?></small></span>
			</a>
			<ul>
				<?php foreach ( self::quick( $uid ) as $item ) : ?>
					<li><a href="<?php echo esc_url( $item[1] ); ?>"><?php echo esc_html( $item[0] ); ?></a></li>
				<?php endforeach; ?>
				<li><a class="csm-app-menu-all" href="<?php echo esc_url( home_url( '/profile/' ) ); ?>"><?php esc_html_e( 'Everything', 'cashaadi-ui' ); ?> ›</a></li>
			</ul>
			<a class="csm-app-menu-out" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'cashaadi-ui' ); ?></a>
		</nav>
		<?php
	}
}
