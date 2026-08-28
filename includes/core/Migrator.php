<?php
/**
 * Migrator — one versioned installer for every custom DB table.
 *
 * Today each snippet runs its own dbDelta on load and tracks its own
 * *_db_version option (#11732 reminder queue, #11796 leads, #11798 photo
 * requests, #11807 rejections/views, #11810 block, #11811 visitors). As each
 * module migrates into this plugin, its schema is registered here and its
 * snippet installer is retired — tables then install/upgrade in one place, only
 * when the registered version changes, not on every request.
 *
 * NOTHING is registered yet, so this ships as inert infrastructure: run() is a
 * no-op until the first module hands its schema over. That keeps this a pure
 * zero-behaviour-change addition.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrator {

	const OPTION = 'cashaadi_db_version';

	/**
	 * Bump this string whenever a registered schema below changes.
	 * Stays 0 while no schema is registered.
	 */
	const VERSION = '0';

	/**
	 * Registered schemas: handle => callable returning the CREATE TABLE SQL for
	 * dbDelta (use $wpdb->prefix and $wpdb->get_charset_collate()).
	 *
	 * Empty for now. Example of what a migrated module will add:
	 *
	 *   self::register( 'leads', function ( $wpdb ) {
	 *       $t = $wpdb->prefix . 'csm_leads';
	 *       $c = $wpdb->get_charset_collate();
	 *       return "CREATE TABLE {$t} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ... ) {$c};";
	 *   } );
	 *
	 * @var array<string,callable>
	 */
	private static $schemas = array();

	/** Register a table schema (called by a module at load, before run()). */
	public static function register( $handle, callable $sql_provider ) {
		self::$schemas[ $handle ] = $sql_provider;
	}

	/**
	 * Install/upgrade all registered tables, but only when VERSION advances.
	 * Safe to call on every request; it early-returns once up to date.
	 */
	public static function run() {
		if ( empty( self::$schemas ) ) {
			return; // nothing owned yet — inert.
		}
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return; // already current.
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::$schemas as $provider ) {
			$sql = (string) call_user_func( $provider, $wpdb );
			if ( '' !== $sql ) {
				dbDelta( $sql );
			}
		}
		update_option( self::OPTION, self::VERSION );
	}
}
