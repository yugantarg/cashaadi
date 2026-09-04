<?php
/**
 * Engine — the tray/likes helpers that used to live in the `cashaadi()`
 * mu-plugin (`wp-content/mu-plugins/cashaadi-discovery.php`, 115 lines).
 *
 * That file was the last piece of live logic outside version control, and being
 * an mu-plugin it could not be switched off from the admin, could not be
 * deployed by git, and could not be reviewed alongside the code that depends on
 * it. Seven call sites in this plugin go through `cashaadi()`; the Discover
 * engine cannot fill a tray without it.
 *
 * FAITHFUL PORT — no behaviour change:
 *   - Same table prefix (`{$wpdb->prefix}csm_`) and the same per-request
 *     table_exists() cache.
 *   - get_week_id() still returns the IST ISO week ("2026-W36"). This is the
 *     key that `wp_csm_tray.week_assigned` is written with, so a change of
 *     timezone here would silently re-slice every existing week.
 *   - get_opposite_gender() still passes through the `csm_opposite_gender`
 *     filter, with the same arguments in the same order.
 *   - log_event() is preserved EXACTLY, including the fact that it writes to
 *     `wp_csm_event_log` — a table that does not exist on this install, so every
 *     call has always been a silent no-op. It is kept rather than deleted so the
 *     port is behaviour-identical; `wp_csm_seen` is what actually records
 *     impressions now. See docs/CONFIG.md.
 *
 * The global `cashaadi()` accessor lives in includes/core/globals.php, guarded
 * with function_exists so this is inert while the mu-plugin is still present —
 * mu-plugins load first, so its definition wins until the file is removed.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Engine {

	/** @var Engine|null */
	private static $instance = null;

	/** @var string */
	private $table_prefix;

	/** @var array<string,bool> */
	private $table_cache = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_prefix = $wpdb->prefix . 'csm_';
	}

	/** Full table name, e.g. table( 'tray' ) => wp_csm_tray. */
	public function table( $name ) {
		return $this->table_prefix . $name;
	}

	/** Whether a csm table physically exists. Cached per request. */
	public function table_exists( $name ) {
		global $wpdb;
		$full = $this->table( $name );
		if ( isset( $this->table_cache[ $full ] ) ) {
			return $this->table_cache[ $full ];
		}
		$found                      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		$this->table_cache[ $full ] = ( $found === $full );
		return $this->table_cache[ $full ];
	}

	/**
	 * IST-based ISO week id, e.g. "2026-W36".
	 *
	 * Asia/Kolkata is hardcoded, exactly as the mu-plugin had it — NOT the site
	 * timezone. Every row in wp_csm_tray.week_assigned was written with this, so
	 * it must keep returning the same string for the same moment.
	 */
	public function get_week_id() {
		$now = new \DateTime( 'now', new \DateTimeZone( 'Asia/Kolkata' ) );
		return $now->format( 'o-\WW' );
	}

	/** Raw gender value for a user. */
	public function get_gender( $user_id ) {
		if ( ! function_exists( 'xprofile_get_field_data' ) ) {
			return '';
		}
		return xprofile_get_field_data( 'Gender', $user_id );
	}

	/** Opposite gender string for matching. */
	public function get_opposite_gender( $user_id ) {
		$gender = $this->get_gender( $user_id );
		$opp    = ( 'Male' === $gender ) ? 'Female' : 'Male';
		return apply_filters( 'csm_opposite_gender', $opp, $user_id, $gender );
	}

	/**
	 * Optional event logger, preserved as-is.
	 *
	 * Writes only if a `wp_csm_event_log` table exists. It does not, and never
	 * has, so every call from the tray refill (profile_served,
	 * tray_refill_complete, pool_exhausted, weekly_reset_processed,
	 * match_created) has been discarded silently.
	 */
	public function log_event( $event_type, $actor_id, $target_id = 0, $metadata = array() ) {
		if ( ! $this->table_exists( 'event_log' ) ) {
			return false;
		}
		global $wpdb;
		return $wpdb->insert(
			$this->table( 'event_log' ),
			array(
				'event_type' => $event_type,
				'actor_id'   => $actor_id,
				'target_id'  => $target_id,
				'metadata'   => wp_json_encode( $metadata ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
	}
}
