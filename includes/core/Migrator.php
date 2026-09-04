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

	/** Legacy global-version option (pre per-handle tracking; kept for reference). */
	const OPTION = 'cashaadi_db_version';

	/** Per-handle install ledger: handle => VERSION last installed at. */
	const OPTION_INSTALLED = 'cashaadi_schemas_installed';

	/**
	 * Bump this string whenever an EXISTING registered schema's columns change —
	 * it forces every registered schema to re-run dbDelta (idempotent). NEW
	 * schemas install on their own the first time their (gated) module registers
	 * them, regardless of VERSION, because run() tracks each handle separately.
	 * That decoupling is what lets a module be enabled later, on its own, without
	 * a coordinated VERSION bump.
	 */
	const VERSION = '5';

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
	 * Install/upgrade registered tables. Each schema handle is tracked separately
	 * in OPTION_INSTALLED, so a table installs the first time its (gated) module
	 * registers it, and re-runs dbDelta only when VERSION advances. Safe to call
	 * on every request; it early-returns once every registered handle is current.
	 */
	public static function run() {
		if ( empty( self::$schemas ) ) {
			return; // nothing registered this request (all owning modules gated off).
		}

		$installed = get_option( self::OPTION_INSTALLED, array() );
		if ( ! is_array( $installed ) ) {
			$installed = array();
		}

		$pending = array();
		foreach ( self::$schemas as $handle => $provider ) {
			if ( ! isset( $installed[ $handle ] ) || $installed[ $handle ] !== self::VERSION ) {
				$pending[ $handle ] = $provider;
			}
		}
		if ( empty( $pending ) ) {
			return; // every registered handle is at the current VERSION.
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $pending as $handle => $provider ) {
			$sql = (string) call_user_func( $provider, $wpdb );
			if ( '' !== $sql ) {
				dbDelta( $sql );
			}
			$installed[ $handle ] = self::VERSION;
		}
		update_option( self::OPTION_INSTALLED, $installed );
	}
}
