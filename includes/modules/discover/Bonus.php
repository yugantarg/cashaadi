<?php
/**
 * Bonus profiles for active women (owner, 2026-10-31): more men get seen,
 * without bothering women who are not deciding anyway.
 *
 * Who: a woman (not Gold, who already sees everyone) who has decided (sent a
 * request or passed) on 3 or more profiles in the last 7 days, opening
 * Discover with fewer than 3 undecided cards left.
 *
 * What: the next men in line by her own Discover score, drawn by the same
 * engine as her weekly cards (same filters, exclusions and ranking points),
 * up to 3 a day and 10 a week. They sit in the tray as week 'bonus', so they
 * do not use up her weekly profiles. An ordinary card, no label.
 *
 * Test: each eligible woman is put in an arm once, 'show' or 'control'
 * (csm_bonus_pct, default 50, is the share shown). Control women are only
 * counted. `wp csm bonus [--days=14]` compares the arms.
 *
 * Events: bonus_arm (woman, arm), bonus_shown (woman, man).
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bonus {

	const WEEK_ROW  = 'bonus';
	const ARM       = 'csm_bonus_arm';    // woman: 'show' or 'control'
	const DAY       = 'csm_bonus_day';    // woman: array( date, count )
	const WEEK      = 'csm_bonus_week';   // woman: array( week id, count )
	const PER_DAY   = 3;
	const PER_WEEK  = 10;
	const MIN_DONE  = 3;                  // decisions in the last 7 days
	const LOW_DECK  = 3;                  // given when fewer undecided cards than this

	/** Set while the engine draws bonus cards: how many. */
	public static $want = 0;

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm bonus', array( __CLASS__, 'cli' ), array(
				'synopsis' => array( array( 'type' => 'assoc', 'name' => 'days', 'optional' => true ) ),
			) );
		}
	}

	public static function deliver( $viewer ) {
		$viewer = (int) $viewer;
		if ( ! $viewer || ! function_exists( 'cashaadi' ) || ! function_exists( 'csm_refill_tray' )
			|| 'Female' !== trim( (string) cashaadi()->get_gender( $viewer ) ) || Membership::is_gold( $viewer ) ) {
			return;
		}
		global $wpdb;
		$done = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Seen::table() . " WHERE viewer_id = %d AND action IN ( 'liked', 'passed' ) AND acted_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$viewer, wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
		) );
		if ( $done < self::MIN_DONE ) {
			return;
		}
		$arm = (string) get_user_meta( $viewer, self::ARM, true );
		if ( '' === $arm ) {
			$pct = max( 0, min( 100, (int) get_option( 'csm_bonus_pct', 50 ) ) );
			$arm = wp_rand( 1, 100 ) <= $pct ? 'show' : 'control';
			update_user_meta( $viewer, self::ARM, $arm );
			cashaadi()->log_event( 'bonus_arm', $viewer, 0, array( 'arm' => $arm ) );
		}
		if ( 'show' !== $arm ) {
			return;
		}
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_tray WHERE viewer_id = %d AND status = 'pending'", $viewer ) );
		if ( $open >= self::LOW_DECK ) {
			return;
		}
		$today = wp_date( 'Y-m-d' );
		$week  = cashaadi()->get_week_id();
		$d     = get_user_meta( $viewer, self::DAY, true );
		$w     = get_user_meta( $viewer, self::WEEK, true );
		$d_n   = ( is_array( $d ) && ( $d[0] ?? '' ) === $today ) ? (int) $d[1] : 0;
		$w_n   = ( is_array( $w ) && ( $w[0] ?? '' ) === $week ) ? (int) $w[1] : 0;
		$n     = min( self::PER_DAY - $d_n, self::PER_WEEK - $w_n, self::LOW_DECK - $open );
		if ( $n < 1 ) {
			return;
		}
		self::$want = $n;
		$got        = (array) csm_refill_tray( $viewer );
		self::$want = 0;
		if ( ! $got ) {
			return;
		}
		update_user_meta( $viewer, self::DAY, array( $today, $d_n + count( $got ) ) );
		update_user_meta( $viewer, self::WEEK, array( $week, $w_n + count( $got ) ) );
		foreach ( $got as $pid ) {
			cashaadi()->log_event( 'bonus_shown', $viewer, (int) $pid, array() );
		}
	}

	/**
	 * wp csm bonus [--days=14]
	 *
	 * The two arms side by side, over the last N days: women, bonus cards,
	 * what they did with them, and every woman's decisions and requests.
	 */
	public static function cli( $args, $assoc ) {
		global $wpdb;
		$days = max( 1, (int) ( $assoc['days'] ?? 14 ) );
		$from = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$seen = Seen::table();
		\WP_CLI::log( 'Share shown: ' . (int) get_option( 'csm_bonus_pct', 50 ) . '% (csm_bonus_pct). Last ' . $days . ' days.' );
		$out = array();
		foreach ( array( 'show', 'control' ) as $arm ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", self::ARM, $arm ) ) );
			$n   = count( $ids );
			$in  = $ids ? implode( ',', $ids ) : '0';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'bonus_shown' AND created_at >= %s AND actor_id IN ({$in})", $from ) );
			$b     = $wpdb->get_row( "SELECT SUM( status = 'liked' ) liked, SUM( status = 'passed' ) passed, SUM( status = 'pending' ) open FROM {$wpdb->prefix}csm_tray WHERE week_assigned = 'bonus' AND viewer_id IN ({$in})" );
			$all   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM( action = 'liked' ) liked, SUM( action IN ( 'liked', 'passed' ) ) decided FROM {$seen} WHERE viewer_id IN ({$in}) AND acted_at >= %s", $from ) );
			// phpcs:enable
			$out[] = array(
				'arm'                 => $arm,
				'women'               => $n,
				'bonus cards'         => $cards,
				'bonus: request'      => (int) ( $b->liked ?? 0 ),
				'bonus: passed'       => (int) ( $b->passed ?? 0 ),
				'bonus: undecided'    => (int) ( $b->open ?? 0 ),
				'decisions per woman' => $n ? round( (int) $all->decided / $n, 1 ) : 0,
				'requests per woman'  => $n ? round( (int) $all->liked / $n, 1 ) : 0,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $out, array_keys( $out[0] ) );
	}
}
