<?php
/**
 * Matches — global functions migrated from WPCode #11637. Kept global (not class
 * methods) because BuddyPress resolves the sub-nav screen callback by string
 * name, and csm_profile_age() is a shared helper other modules may call.
 * All function_exists()-guarded; required by Matches::register() when enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'csm_profile_age' ) ) {
	/**
	 * Age from the "Date of birth" field (586), with the legacy "Age" field (286)
	 * as a fallback. xprofile_get_field_data() is filtered on this site and hands
	 * back "30 years old" rather than a date, so the raw value is read directly.
	 *
	 * @return int Age, or 0 when it cannot be determined.
	 */
	function csm_profile_age( $uid ) {
		$uid = (int) $uid;
		if ( $uid < 1 || ! function_exists( 'xprofile_get_field_data' ) ) {
			return 0;
		}

		global $wpdb;
		$bp    = function_exists( 'buddypress' ) ? buddypress() : null;
		$table = ( $bp && isset( $bp->profile ) && ! empty( $bp->profile->table_name_data ) )
			? $bp->profile->table_name_data
			: $wpdb->prefix . 'bp_xprofile_data';
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT value FROM {$table} WHERE field_id = %d AND user_id = %d LIMIT 1",
			586, $uid
		) );
		if ( ! $raw ) {
			$raw = xprofile_get_field_data( 'Date of birth', $uid );
		}
		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}

		if ( $raw ) {
			$ts = strtotime( (string) $raw );
			if ( $ts ) {
				$born  = (int) gmdate( 'Ymd', $ts );
				$today = (int) current_time( 'Ymd' );
				if ( $born > 0 && $born <= $today ) {
					$age = (int) floor( ( $today - $born ) / 10000 );
					if ( $age > 0 && $age < 120 ) {
						return $age;
					}
				}
			}
		}

		if ( '' !== $raw && ! preg_match( '/[0-9]{4}-[0-9]{2}-[0-9]{2}/', (string) $raw ) && preg_match( '/([0-9]{1,3})/', (string) $raw, $m ) ) {
			$n = (int) $m[1];
			if ( $n > 0 && $n < 120 ) {
				return $n;
			}
		}

		$legacy = (int) xprofile_get_field_data( 'Age', $uid );
		return ( $legacy > 0 && $legacy < 120 ) ? $legacy : 0;
	}
}

if ( ! function_exists( 'csm_render_requests_sent' ) ) {
	/** HTML for the current user's pending SENT match requests (read-only cards). */
	function csm_render_requests_sent() {
		if ( ! is_user_logged_in() || ! function_exists( 'buddypress' ) ) {
			return '<div class="csm-discovery-tray"><p class="csm-tray-empty">Requests are unavailable right now.</p></div>';
		}

		$me = get_current_user_id();

		global $wpdb;
		$bp    = buddypress();
		$table = ( isset( $bp->friends ) && isset( $bp->friends->table_name ) ) ? $bp->friends->table_name : $wpdb->prefix . 'bp_friends';

		$pending_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT friend_user_id FROM {$table} WHERE initiator_user_id = %d AND is_confirmed = 0 ORDER BY date_created DESC",
			$me
		) );
		$pending_ids = array_values( array_filter( array_map( 'intval', (array) $pending_ids ) ) );

		ob_start();
		echo '<div class="csm-discovery-tray csm-requests-sent">';

		if ( empty( $pending_ids ) ) {
			echo '<p class="csm-tray-empty">No pending requests yet. Likes you send will appear here until they are accepted.</p>';
			echo '</div>';
			return ob_get_clean();
		}

		echo '<div class="csm-tray">';

		foreach ( $pending_ids as $pid ) {
			$user = get_userdata( $pid );
			if ( ! $user ) { continue; }

			$name        = $user->display_name ? $user->display_name : $user->user_login;
			$profile_url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $pid ) : get_author_posts_url( $pid );
			$avatar      = function_exists( 'bp_core_fetch_avatar' )
				? bp_core_fetch_avatar( array( 'item_id' => $pid, 'type' => 'full', 'html' => false ) )
				: get_avatar_url( $pid, array( 'size' => 300 ) );

			$age      = function_exists( 'xprofile_get_field_data' ) ? csm_profile_age( $pid )               : '';
			$location = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( 'City', $pid ) : '';
			$about    = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( 'Bio', $pid )  : '';

			echo '<div class="csm-card">';
			echo '<a class="csm-card-photo-link" href="' . esc_url( $profile_url ) . '">';
			echo '<img class="csm-card-photo" src="' . esc_url( $avatar ) . '" alt="' . esc_attr( $name ) . '" />';
			echo '</a>';
			echo '<span class="csm-badge-new csm-badge-pending">Pending</span>';
			echo '<div class="csm-card-body">';
			echo '<h3 class="csm-card-name"><a class="csm-card-name-link" href="' . esc_url( $profile_url ) . '">' . esc_html( $name );
			if ( $age ) { echo ' <span class="csm-card-age">, ' . esc_html( $age ) . '</span>'; }
			echo '</a></h3>';
			if ( $location ) { echo '<p class="csm-card-loc">' . esc_html( $location ) . '</p>'; }
			if ( $about ) { echo '<p class="csm-card-about">' . esc_html( wp_trim_words( $about, 28 ) ) . '</p>'; }
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}
}

if ( ! function_exists( 'csm_requests_sent_screen' ) ) {
	function csm_requests_sent_screen() {
		add_action( 'bp_template_content', 'csm_requests_sent_screen_content' );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}
}

if ( ! function_exists( 'csm_requests_sent_screen_content' ) ) {
	function csm_requests_sent_screen_content() {
		echo '<h2 class="screen-heading">' . esc_html__( 'Requests Sent', 'cashaadi' ) . '</h2>';
		echo csm_render_requests_sent(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
