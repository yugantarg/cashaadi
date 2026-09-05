<?php
/**
 * Health — does the ground this plugin stands on still look the way we assumed?
 *
 * Almost nothing here is our own code. The member experience is assembled from
 * BuddyPress, PMPro, Better Messages, bpxcftr and a child theme, and this plugin
 * reaches into all of them: it calls their functions, removes their hooks, reads
 * their tables and hard-codes their xProfile field ids. Every one of those is a
 * promise someone else can quietly break in a routine update.
 *
 * The failures that matter are the SILENT ones. If BuddyPress renames
 * friends_notification_new_request, our remove_action() stops matching and
 * duplicate emails come back — with no error anywhere. If someone rebuilds an
 * xProfile field, Config::FIELD_GENDER stops meaning Gender and the site starts
 * reading the wrong column. Nothing fatals; it just goes wrong.
 *
 * So this does not try to PREVENT upstream change — that is not winnable, and
 * guarding every rename would be more machinery than the risk deserves. It makes
 * change VISIBLE: one page that says what we expect, what we found, and what
 * broke. A version fingerprint turns "the site went odd last week" into
 * "BuddyPress went 14.4.0 → 14.5.0 on the 3rd".
 *
 * Read-only and cheap: no writes except the stored fingerprint, no network.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health {

	/** Where the last-seen plugin versions live, so a change can be spotted. */
	const OPTION_SEEN = 'csm_health_versions';

	/** Result of the last run, for the dashboard and the admin notice. */
	const OPTION_LAST = 'csm_health_last';

	const OK   = 'ok';
	const WARN = 'warn';
	const FAIL = 'fail';

	/**
	 * xProfile ids are hard-coded all over this plugin. If an id ever stops
	 * pointing at the field we think it does, everything downstream is wrong in a
	 * way no error will report — so the id/name pairing is checked, not assumed.
	 */
	const EXPECTED_FIELDS = array(
		1   => 'Name',
		228 => 'Height',
		277 => 'Phone Number',
		286 => 'Age',
		299 => 'Gender',
		484 => 'ICAI ID',
		496 => 'Bio',
		571 => 'Qualification',
		586 => 'Date of birth',
		587 => 'City',
	);

	/** Functions the plugin calls on other people's code. */
	const REQUIRED_FUNCTIONS = array(
		'bp_send_email'                        => 'BuddyPress',
		'xprofile_get_field_data'              => 'BuddyPress',
		'xprofile_set_field_data'              => 'BuddyPress',
		'xprofile_get_field_id_from_name'      => 'BuddyPress',
		'bp_xprofile_get_hidden_fields_for_user' => 'BuddyPress',
		'bp_core_fetch_avatar'                 => 'BuddyPress',
		'bp_core_get_user_displayname'         => 'BuddyPress',
		'friends_add_friend'                   => 'BuddyPress',
		'friends_check_friendship_status'      => 'BuddyPress',
		'pmpro_hasMembershipLevel'             => 'Paid Memberships Pro',
	);

	/** Classes instantiated or read directly. */
	const REQUIRED_CLASSES = array(
		'BP_XProfile_ProfileData'  => 'BuddyPress',
		'BP_XProfile_Field'        => 'BuddyPress',
		'BP_Friends_Friendship'    => 'BuddyPress',
	);

	/**
	 * Functions we deliberately UNHOOK. These are the sharpest edge: if the name
	 * changes, remove_action() silently does nothing and the behaviour we
	 * suppressed comes straight back.
	 */
	const SUPPRESSED_FUNCTIONS = array(
		'friends_notification_new_request'      => 'BuddyPress would email its own match-request notice, duplicating ours',
		'friends_notification_accepted_request' => 'BuddyPress would email its own request-accepted notice, duplicating ours',
	);

	/** Tables the plugin reads or writes. */
	const REQUIRED_TABLES = array(
		'csm_tray'          => 'Discover tray',
		'csm_likes'         => 'Like archive',
		'csm_seen'          => 'Impressions and decisions',
		'csm_email_queue'   => 'Email queue',
		'csm_profile_views' => 'Who viewed me',
	);

	/** Integration plugins whose version changes are worth noticing. */
	const WATCHED_PLUGINS = array(
		'buddypress/bp-loader.php'                            => 'BuddyPress',
		'paid-memberships-pro/paid-memberships-pro.php'       => 'Paid Memberships Pro',
		'bp-better-messages/bp-better-messages.php'           => 'Better Messages',
		'bp-xprofile-custom-field-types/bp-xprofile-custom-field-types.php' => 'XProfile Custom Field Types',
		'mailin/sendinblue.php'                               => 'Brevo',
		'woocommerce/woocommerce.php'                         => 'WooCommerce',
	);

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ------------------------------------------------------------- the checks */

	/**
	 * Run every check.
	 *
	 * @return array{status:string,checks:array,at:string}
	 */
	public static function run() {
		$checks = array_merge(
			self::check_fields(),
			self::check_functions(),
			self::check_classes(),
			self::check_suppressed(),
			self::check_tables(),
			self::check_cron(),
			self::check_mail(),
			self::check_versions()
		);

		$status = self::OK;
		foreach ( $checks as $c ) {
			if ( self::FAIL === $c['status'] ) {
				$status = self::FAIL;
				break;
			}
			if ( self::WARN === $c['status'] ) {
				$status = self::WARN;
			}
		}

		$result = array( 'status' => $status, 'checks' => $checks, 'at' => current_time( 'mysql' ) );
		update_option( self::OPTION_LAST, $result, false );
		return $result;
	}

	private static function row( $status, $area, $label, $detail = '' ) {
		return compact( 'status', 'area', 'label', 'detail' );
	}

	/** Do our hard-coded field ids still name the fields we think they do? */
	private static function check_fields() {
		$out = array();
		if ( ! function_exists( 'xprofile_get_field_id_from_name' ) ) {
			return array( self::row( self::FAIL, 'xProfile', 'Cannot verify field ids', 'BuddyPress xProfile is not loaded.' ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bp_xprofile_fields';
		foreach ( self::EXPECTED_FIELDS as $id => $name ) {
			$actual = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			if ( null === $actual ) {
				$out[] = self::row( self::FAIL, 'xProfile', sprintf( 'Field %d is missing', $id ), sprintf( 'Expected "%s". Code that reads this id now reads nothing.', $name ) );
			} elseif ( strtolower( trim( $actual ) ) !== strtolower( $name ) ) {
				$out[] = self::row( self::FAIL, 'xProfile', sprintf( 'Field %d changed', $id ), sprintf( 'Expected "%s", found "%s". Every use of this id is now reading the wrong field.', $name, $actual ) );
			}
		}
		if ( ! $out ) {
			$out[] = self::row( self::OK, 'xProfile', sprintf( 'All %d field ids match', count( self::EXPECTED_FIELDS ) ) );
		}
		return $out;
	}

	private static function check_functions() {
		$missing = array();
		foreach ( self::REQUIRED_FUNCTIONS as $fn => $owner ) {
			if ( ! function_exists( $fn ) ) {
				$missing[] = $fn . '() [' . $owner . ']';
			}
		}
		return $missing
			? array( self::row( self::FAIL, 'Dependencies', count( $missing ) . ' required function(s) missing', implode( ', ', $missing ) ) )
			: array( self::row( self::OK, 'Dependencies', 'All required functions present' ) );
	}

	private static function check_classes() {
		$missing = array();
		foreach ( self::REQUIRED_CLASSES as $cls => $owner ) {
			if ( ! class_exists( $cls ) ) {
				$missing[] = $cls . ' [' . $owner . ']';
			}
		}
		return $missing
			? array( self::row( self::FAIL, 'Dependencies', count( $missing ) . ' required class(es) missing', implode( ', ', $missing ) ) )
			: array( self::row( self::OK, 'Dependencies', 'All required classes present' ) );
	}

	/**
	 * The suppression checks. A missing function here does NOT mean something is
	 * broken right now — it means our remove_action() no longer matches anything,
	 * so whatever we were suppressing is free to happen again.
	 */
	private static function check_suppressed() {
		$out = array();
		foreach ( self::SUPPRESSED_FUNCTIONS as $fn => $consequence ) {
			if ( ! function_exists( $fn ) ) {
				$out[] = self::row( self::WARN, 'Suppression', sprintf( '%s() no longer exists', $fn ), 'Our remove_action() now matches nothing. ' . $consequence . '. Find its replacement and unhook that instead.' );
			}
		}
		if ( ! $out ) {
			$out[] = self::row( self::OK, 'Suppression', 'Suppressed upstream senders still match' );
		}
		return $out;
	}

	private static function check_tables() {
		global $wpdb;
		$missing = array();
		foreach ( self::REQUIRED_TABLES as $suffix => $what ) {
			$t = $wpdb->prefix . $suffix;
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				$missing[] = $t . ' (' . $what . ')';
			}
		}
		return $missing
			? array( self::row( self::FAIL, 'Tables', count( $missing ) . ' table(s) missing', implode( ', ', $missing ) ) )
			: array( self::row( self::OK, 'Tables', 'All custom tables present' ) );
	}

	/**
	 * Cron is where the quietest failures live: four of the seven engagement
	 * emails only exist if it runs, and if it stops nothing complains.
	 */
	private static function check_cron() {
		$out = array();

		$hooks = array(
			'csm_remail_cron'       => 'Email queue',
			'csm_engagement_daily'  => 'Engagement emails',
		);
		foreach ( $hooks as $hook => $what ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$out[] = self::row( self::FAIL, 'Cron', sprintf( '%s is not scheduled', $hook ), $what . ' will never run.' );
			}
		}

		$last = get_option( 'csm_remail_last_run' );
		if ( is_array( $last ) && ! empty( $last['at'] ) ) {
			$age = time() - strtotime( get_gmt_from_date( $last['at'] ) . ' +0000' );
			if ( $age > DAY_IN_SECONDS ) {
				$out[] = self::row( self::FAIL, 'Cron', 'Email queue has not run for ' . human_time_diff( time() - $age ), 'Last run ' . $last['at'] . '. Cron is probably not firing: check that a real cron calls wp-cron.php, or that DISABLE_WP_CRON is false.' );
			} elseif ( $age > 3 * HOUR_IN_SECONDS ) {
				$out[] = self::row( self::WARN, 'Cron', 'Email queue last ran ' . human_time_diff( time() - $age ) . ' ago', 'Expected hourly.' );
			}
		} else {
			$out[] = self::row( self::WARN, 'Cron', 'Email queue has never recorded a run' );
		}

		if ( ! $out ) {
			$out[] = self::row( self::OK, 'Cron', 'Scheduled jobs are running' );
		}
		return $out;
	}

	/**
	 * Mail routing. The staging sink is listed because on production it would
	 * swallow every email while reporting success — the single most damaging
	 * silent failure available to this install.
	 */
	private static function check_mail() {
		$out = array();

		$sink = WPMU_PLUGIN_DIR . '/00-staging-mail-sink.php';
		if ( file_exists( $sink ) ) {
			$armed = false !== stripos( $sink, '/staging' );
			$out[] = $armed
				? self::row( self::WARN, 'Mail', 'Staging mail sink is active', 'No email leaves this install. Correct for staging; delete the file to send for real.' )
				: self::row( self::FAIL, 'Mail', 'Staging mail sink present on a NON-staging path', 'It is inert thanks to its path guard, but it must not be here at all. Delete it.' );
		}

		if ( class_exists( '\CAShaadi\Modules\Emails\Queue' ) && ! \CAShaadi\Modules\Emails\Queue::master_on() ) {
			$out[] = self::row( self::WARN, 'Mail', 'Email sending is paused', 'csm_remail_master is 0: emails are queued and visible but never sent. Deliberate on staging; on production it means members hear nothing.' );
		}

		return $out ? $out : array( self::row( self::OK, 'Mail', 'Mail routing is live' ) );
	}

	/**
	 * The general answer to "did an update change something?".
	 *
	 * Versions are compared against the last run, so an update shows up once, with
	 * both numbers, next to everything else that is failing. That is usually
	 * enough to explain a breakage without bisecting anything.
	 */
	private static function check_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$seen = (array) get_option( self::OPTION_SEEN, array() );
		$now  = array();
		$out  = array();

		foreach ( self::WATCHED_PLUGINS as $file => $label ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$v            = (string) $all[ $file ]['Version'];
			$now[ $file ] = $v;
			if ( isset( $seen[ $file ] ) && $seen[ $file ] !== $v ) {
				$out[] = self::row( self::WARN, 'Versions', sprintf( '%s updated: %s → %s', $label, $seen[ $file ], $v ), 'Re-check anything below that is failing — an update is the most likely cause.' );
			}
		}

		update_option( self::OPTION_SEEN, $now, false );

		return $out ? $out : array( self::row( self::OK, 'Versions', 'No integration plugin has changed version' ) );
	}

	/* ---------------------------------------------------------------- surface */

	public static function menu() {
		/*
		 * Prefer the CA Shaadi dashboard, but fall back to Tools: that menu is
		 * added by the admin module, which is flag-gated, and a health page that
		 * disappears exactly when the site is misconfigured would be useless.
		 */
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['csm-sales-dashboard'] ) ? 'csm-sales-dashboard' : 'tools.php';

		add_submenu_page(
			$parent,
			'Integration health',
			'Integration health',
			'manage_options',
			'csm-health',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Only ever shown for a real failure, and only to administrators. A notice
	 * that appears when nothing is wrong stops being read.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$last = get_option( self::OPTION_LAST );
		if ( ! is_array( $last ) || self::FAIL !== ( $last['status'] ?? '' ) ) {
			return;
		}
		$fails = 0;
		foreach ( (array) $last['checks'] as $c ) {
			if ( self::FAIL === $c['status'] ) {
				$fails++;
			}
		}
		printf(
			'<div class="notice notice-error"><p><strong>CA Shaadi:</strong> %d integration check(s) failing. <a href="%s">See what changed</a>.</p></div>',
			(int) $fails,
			esc_url( admin_url( 'admin.php?page=csm-health' ) )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = self::run();

		$colours = array( self::OK => '#1a7f37', self::WARN => '#9a6700', self::FAIL => '#b42318' );
		$words   = array( self::OK => 'OK', self::WARN => 'Check', self::FAIL => 'Broken' );

		echo '<div class="wrap"><h1>Integration health</h1>';
		echo '<p style="max-width:52em">This plugin sits on BuddyPress, PMPro, Better Messages and a child theme. It calls their functions, unhooks some of them and hard-codes xProfile field ids — all of which an update can change without any error appearing. This page checks those assumptions so a breakage can be diagnosed instead of hunted.</p>';

		printf(
			'<p style="font-size:15px"><strong>Overall: <span style="color:%s">%s</span></strong> &nbsp; <span style="color:#666">checked %s</span></p>',
			esc_attr( $colours[ $result['status'] ] ),
			esc_html( $words[ $result['status'] ] ),
			esc_html( $result['at'] )
		);

		echo '<table class="widefat striped" style="max-width:60em"><thead><tr><th style="width:90px">Status</th><th style="width:120px">Area</th><th>Check</th></tr></thead><tbody>';
		foreach ( $result['checks'] as $c ) {
			printf(
				'<tr><td><strong style="color:%s">%s</strong></td><td>%s</td><td>%s%s</td></tr>',
				esc_attr( $colours[ $c['status'] ] ),
				esc_html( $words[ $c['status'] ] ),
				esc_html( $c['area'] ),
				esc_html( $c['label'] ),
				$c['detail'] ? '<br><span style="color:#666">' . esc_html( $c['detail'] ) . '</span>' : ''
			);
		}
		echo '</tbody></table>';
		echo '<p style="color:#666;max-width:52em">Re-run by reloading this page. "Check" means something changed and is worth a look; "Broken" means an assumption this plugin relies on is no longer true.</p>';
		echo '</div>';
	}
}
