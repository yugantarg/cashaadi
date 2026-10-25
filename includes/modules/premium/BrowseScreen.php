<?php
/**
 * Browse all members, for Gold (owner, 2026-10-24: "Gold members can see all
 * profiles"). /browse/ lists every opposite-gender member, recently active
 * first, narrowed by the member's own Discover filters. Tapping one opens
 * the member screen, where Gold can send a request to anyone.
 *
 * Hidden as everywhere else: blocked pairs, closed, paused and held accounts.
 */

namespace CAShaadi\Modules\Premium;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Assets;
use CAShaadi\Core\Config;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BrowseScreen {

	const PER_PAGE = 24;

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url() {
		return home_url( '/browse/' );
	}

	public static function maybe_render() {
		$path = trim( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ), '/' );
		if ( 'browse' !== $path || ! AppPage::claim( 'browse' ) ) {
			return;
		}
		if ( ! Membership::is_gold( get_current_user_id() ) ) {
			wp_safe_redirect( home_url( '/membership-pricing/' ) );
			exit;
		}
		AppPage::assets();
		Assets::style( 'discover-app', 'assets/css/discover-app.css', array( 'cashaadi-app-screens' ) );
		Assets::style( 'browse', 'assets/css/browse.css', array( 'cashaadi-app-screens' ) );
		Assets::script( 'browse', 'assets/js/browse.js', array( 'cashaadi-app-screens' ) );
		wp_localize_script( 'cashaadi-browse', 'CSM_BROWSE', array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'list'     => rest_url( 'csm/v1/browse' ),
			'discover' => home_url( '/discover/' ),
		) );
		AppPage::open( __( 'All members', 'cashaadi-ui' ), 'discover' );
		echo '<div id="csm-browse-app"><p class="csm-app-loading">' . esc_html__( 'Loading…', 'cashaadi-ui' ) . '</p></div>';
		AppPage::close( 'discover' );
		exit;
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/browse', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** Everyone this viewer must not see. */
	private static function hidden( $uid ) {
		$h = array( (int) $uid );
		if ( function_exists( 'csm_bl_hidden_ids' ) ) {
			$h = array_merge( $h, (array) csm_bl_hidden_ids( $uid ) );
		}
		if ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) ) {
			$h = array_merge( $h, \CAShaadi\Modules\Settings\Closed::ids() );
		}
		if ( class_exists( '\CAShaadi\Modules\Settings\Deactivate' ) ) {
			$h = array_merge( $h, \CAShaadi\Modules\Settings\Deactivate::paused_ids() );
		}
		if ( class_exists( '\CAShaadi\Modules\Photos\PhotoHold' ) ) {
			$h = array_merge( $h, \CAShaadi\Modules\Photos\PhotoHold::held_ids() );
		}
		$h = array_merge( $h, array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ) );
		return array_values( array_unique( array_map( 'intval', $h ) ) );
	}

	public static function rest_list( $request ) {
		global $wpdb;
		$uid = get_current_user_id();
		if ( ! Membership::is_gold( $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Browsing all members is a Gold feature.' ), 200 );
		}
		$opposite = function_exists( 'cashaadi' ) ? cashaadi()->get_opposite_gender( $uid ) : '';
		if ( ! $opposite ) {
			return new \WP_REST_Response( array( 'ok' => true, 'items' => array(), 'more' => false ), 200 );
		}
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$where = class_exists( '\CAShaadi\Modules\Discover\Filters' ) ? \CAShaadi\Modules\Discover\Filters::where( '', $uid ) : '';
		$skip  = implode( ',', self::hidden( $uid ) );
		$xp    = $wpdb->prefix . 'bp_xprofile_data';
		$act   = $wpdb->base_prefix . 'bp_activity';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT xp.user_id FROM {$xp} xp
			 LEFT JOIN ( SELECT user_id, MAX(date_recorded) last FROM {$act} WHERE component = 'members' AND type = 'last_activity' GROUP BY user_id ) la ON la.user_id = xp.user_id
			 WHERE xp.field_id = %d AND xp.value = %s AND xp.user_id NOT IN ({$skip})" . $where . '
			 ORDER BY la.last DESC, xp.user_id DESC
			 LIMIT %d OFFSET %d',
			Config::FIELD_GENDER,
			$opposite,
			self::PER_PAGE + 1,
			( $page - 1 ) * self::PER_PAGE
		) );
		$more  = count( $ids ) > self::PER_PAGE;
		$items = array();
		foreach ( array_slice( $ids, 0, self::PER_PAGE ) as $id ) {
			$id     = (int) $id;
			$hidden = \CAShaadi\Core\Profile::hidden_for( $id, $uid );
			$items[] = array(
				'id'       => $id,
				'name'     => function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $id ) : '',
				'age'      => \CAShaadi\Core\Profile::age_of( $id, $hidden ),
				'city'     => \CAShaadi\Core\Profile::field( 'City', $id, $hidden ),
				'avatar'   => function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar( array( 'item_id' => $id, 'type' => 'full', 'html' => false ) ) : '',
				'verified' => method_exists( '\CAShaadi\Core\Verification', 'ca_verified' ) && \CAShaadi\Core\Verification::ca_verified( $id ),
				'gold'     => Gold::badge( $id ),
				'url'      => home_url( '/member/' . $id . '/' ),
			);
		}
		$f = class_exists( '\CAShaadi\Modules\Discover\Filters' ) ? \CAShaadi\Modules\Discover\Filters::get( $uid ) : array();
		return new \WP_REST_Response( array( 'ok' => true, 'items' => $items, 'more' => $more, 'filtered' => ! empty( $f ) ), 200 );
	}
}
