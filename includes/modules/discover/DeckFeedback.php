<?php
/**
 * "Were this week's profiles relevant to you?" (owner, 2026-10-31), the last
 * card once a member has gone through the week's set, from the batch of
 * Monday 2 November (week 2026-W45, option csm_deck_fb_from).
 *
 * Once a week per member: Yes, or No with what should change (age, location,
 * community, religion, qualification, something else, and a line of text).
 * Not for Gold, who has no weekly set. Until answered it shows again on the
 * end-of-week screen.
 *
 * Events: deck_feedback (member; week, relevant, reasons, text, quota,
 * premium, gender). `wp csm deck-feedback [--weeks=1]` sums it up.
 */

namespace CAShaadi\Modules\Discover;

use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeckFeedback {

	const META    = 'csm_deck_fb_week';   // last week asked
	const FROM    = 'csm_deck_fb_from';   // first week to ask
	const REASONS = array(
		'age'       => 'Age',
		'location'  => 'Location',
		'community' => 'Community',
		'religion'  => 'Religion',
		'qual'      => 'Qualification',
		'other'     => 'Something else',
	);

	public static function register() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'csm/v1', '/discover/feedback', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => 'is_user_logged_in',
			) );
		} );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm deck-feedback', array( __CLASS__, 'cli' ), array(
				'synopsis' => array( array( 'type' => 'assoc', 'name' => 'weeks', 'optional' => true ) ),
			) );
		}
	}

	/** Ask this member this week? */
	public static function due( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! function_exists( 'cashaadi' ) || Membership::is_gold( $uid ) ) {
			return false;
		}
		$week = cashaadi()->get_week_id();
		if ( strcmp( $week, (string) get_option( self::FROM, '2026-W45' ) ) < 0 ) {
			return false;
		}
		return get_user_meta( $uid, self::META, true ) !== $week;
	}

	public static function rest( $request ) {
		$uid = get_current_user_id();
		if ( ! self::due( $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$week = cashaadi()->get_week_id();
		update_user_meta( $uid, self::META, $week );
		$rel = (string) $request->get_param( 'relevant' );
		if ( '' === $rel ) { // closed without answering
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$reasons = array_values( array_intersect( array_map( 'sanitize_key', (array) $request->get_param( 'reasons' ) ), array_keys( self::REASONS ) ) );
		cashaadi()->log_event( 'deck_feedback', $uid, 0, array(
			'week'     => $week,
			'relevant' => '1' === $rel ? 1 : 0,
			'reasons'  => $reasons,
			'text'     => mb_substr( trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) ), 0, 500 ),
			'quota'    => Discover::quota_for( $uid ),
			'premium'  => Membership::is_premium( $uid ) ? 1 : 0,
			'gender'   => (string) cashaadi()->get_gender( $uid ),
		) );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * wp csm deck-feedback [--weeks=1]
	 *
	 * Yes / No by gender and plan, what should change, and the written answers.
	 */
	public static function cli( $args, $assoc ) {
		global $wpdb;
		$weeks = max( 1, (int) ( $assoc['weeks'] ?? 1 ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT metadata FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'deck_feedback' AND created_at >= %s", wp_date( 'Y-m-d H:i:s', time() - $weeks * WEEK_IN_SECONDS ) ) );
		$all   = array();
		foreach ( (array) $rows as $r ) {
			$m = json_decode( (string) $r->metadata, true );
			if ( is_array( $m ) ) {
				$all[] = $m;
			}
		}
		if ( ! $all ) {
			\WP_CLI::log( 'No answers yet.' );
			return;
		}
		$groups = array(
			'all'          => function () { return true; },
			'men, free'    => function ( $m ) { return 'Male' === $m['gender'] && ! $m['premium']; },
			'men, paid'    => function ( $m ) { return 'Male' === $m['gender'] && $m['premium']; },
			'women, free'  => function ( $m ) { return 'Female' === $m['gender'] && ! $m['premium']; },
			'women, paid'  => function ( $m ) { return 'Female' === $m['gender'] && $m['premium']; },
		);
		$table = array();
		foreach ( $groups as $g => $fn ) {
			$set = array_values( array_filter( $all, $fn ) );
			$n   = count( $set );
			$yes = count( array_filter( $set, function ( $m ) { return ! empty( $m['relevant'] ); } ) );
			$row = array( 'group' => $g, 'answers' => $n, 'relevant' => $n ? round( 100 * $yes / $n ) . '%' : '-' );
			foreach ( self::REASONS as $k => $label ) {
				$no  = $n - $yes;
				$c   = count( array_filter( $set, function ( $m ) use ( $k ) { return in_array( $k, (array) ( $m['reasons'] ?? array() ), true ); } ) );
				$row[ $label ] = $no ? round( 100 * $c / $no ) . '%' : '-';
			}
			$table[] = $row;
		}
		\WP_CLI::log( "Last {$weeks} week(s). Reason columns are shares of the 'not relevant' answers." );
		\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );
		\WP_CLI::log( "\nWhat should change:" );
		foreach ( $all as $m ) {
			if ( '' !== ( $m['text'] ?? '' ) ) {
				\WP_CLI::log( '  [' . ( 'Female' === $m['gender'] ? 'woman' : 'man' ) . ', ' . ( $m['premium'] ? 'paid' : 'free' ) . '] ' . $m['text'] );
			}
		}
	}
}
