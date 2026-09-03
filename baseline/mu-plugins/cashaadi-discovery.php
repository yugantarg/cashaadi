<?php
/**
 * Plugin Name: CAShaadi Discovery Tray
 * Description: Core helpers for the CAShaadi weekly discovery tray (quota: 5 male / 10 female).
 * Version: 1.0.0
 * Author: CAShaadi
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'CASHAADI_DISCOVERY_VERSION' ) ) {
    define( 'CASHAADI_DISCOVERY_VERSION', '1.0.0' );
}

class CAShaadi_Discovery {

    private static $instance = null;
    private $table_prefix;
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

    /* Full table name, e.g. table("tray") => wp_csm_tray */
    public function table( $name ) {
        return $this->table_prefix . $name;
    }

    /* Whether a csm table physically exists (future-proof guard). */
    public function table_exists( $name ) {
        global $wpdb;
        $full = $this->table( $name );
        if ( isset( $this->table_cache[ $full ] ) ) {
            return $this->table_cache[ $full ];
        }
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full ) );
        $this->table_cache[ $full ] = ( $found === $full );
        return $this->table_cache[ $full ];
    }

    /* IST-based ISO week id, e.g. 2026-W25. */
    public function get_week_id() {
        $now = new DateTime( 'now', new DateTimeZone( 'Asia/Kolkata' ) );
        return $now->format( 'o-\\WW' );
    }

    /* Raw gender value for a user. */
    public function get_gender( $user_id ) {
        if ( ! function_exists( 'xprofile_get_field_data' ) ) {
            return '';
        }
        return xprofile_get_field_data( 'Gender', $user_id );
    }

    /* Opposite gender string for matching. */
    public function get_opposite_gender( $user_id ) {
        $gender = $this->get_gender( $user_id );
        $opp    = ( $gender === 'Male' ) ? 'Female' : 'Male';
        return apply_filters( 'csm_opposite_gender', $opp, $user_id, $gender );
    }

    /*
     * Optional event logger. Self-guarding: only writes if a csm_event_log
     * table exists, so it is a safe no-op today and "just works" if added later.
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

/* Global accessor: cashaadi()->get_gender( $id ) etc. */
function cashaadi() {
    return CAShaadi_Discovery::get_instance();
}
add_action( 'plugins_loaded', 'cashaadi' );

/**
 * CSM — Canonical profile-completion check (single source of truth).
 * Returns TRUE when the profile is complete, FALSE otherwise.
 * Wraps the existing theme helper so behaviour is byte-for-byte unchanged;
 * defined here in the mu-plugin so it loads before all snippets/theme.
 */
if ( ! function_exists( 'csm_user_profile_is_complete' ) ) {
	function csm_user_profile_is_complete( $user_id = 0 ) {
		if ( ! $user_id ) { $user_id = get_current_user_id(); }
		if ( ! $user_id ) { return false; }
		if ( function_exists( 'cashaadi_has_missing_required_fields' ) ) {
			return ! cashaadi_has_missing_required_fields( $user_id );
		}
		return true;
	}
}
