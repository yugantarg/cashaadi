<?php
/**
 * Global-namespace shim for the Sales Admin Dashboard module (#11688).
 *
 * The reminder-email engine (#11732) calls csm_profile_pending_label() by that
 * exact global name (guarded with function_exists). The original #11688 snippet
 * defined it globally; this module keeps that one global available — delegating
 * to Dashboard::profile_pending_label() — so #11732 keeps working after #11688
 * is disabled at cutover.
 *
 * Required only from Dashboard::register(), i.e. only when Config::admin_enabled()
 * is true. The function_exists() guard keeps it from ever colliding with the
 * still-active #11688 snippet before cutover. Every other former global from
 * #11688 (fields-complete, ranks, membership label, acted count, signup emails)
 * had no cross-snippet caller and now lives only as a Dashboard static method.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'csm_profile_pending_label' ) ) {
	/**
	 * @param int      $user_id
	 * @param bool|null $is_pending Optional precomputed pending-signup flag.
	 * @return string One of Unknown|Email pending|Profile pending|Photo pending|SMS pending|Complete.
	 */
	function csm_profile_pending_label( $user_id, $is_pending = null ) {
		return \CAShaadi\Modules\Admin\Dashboard::profile_pending_label( $user_id, $is_pending );
	}
}
