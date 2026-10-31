<?php
defined( 'ABSPATH' ) || exit; // run with: wp eval-file tools/pages.php
/*
 * Policy page updates (owner, 2026-10-31), for the payment gateway review.
 * Backs up each page first (post meta csm_backup_<date>), so it can be undone.
 *
 *  - Refund Policy: replaced by tools/pages/refund-policy.html (case by case,
 *    no automatic renewals). Elementor is switched off for this page so the
 *    plain content shows; the old Elementor layout stays in the backup.
 *  - Terms and Conditions: the introduction names SDG Ventures. Only that
 *    sentence changes; the Elementor layout is kept.
 */
$stamp = 'csm_backup_' . gmdate( 'Ymd_His' );

$refund = get_page_by_path( 'refund-policy' );
if ( ! $refund ) {
	WP_CLI::error( 'Refund Policy page not found.' );
}
$html = file_get_contents( __DIR__ . '/pages/refund-policy.html' );
update_post_meta( $refund->ID, $stamp, wp_slash( wp_json_encode( array(
	'post_content'        => $refund->post_content,
	'_elementor_data'     => get_post_meta( $refund->ID, '_elementor_data', true ),
	'_elementor_edit_mode' => get_post_meta( $refund->ID, '_elementor_edit_mode', true ),
) ) ) );
wp_update_post( array( 'ID' => $refund->ID, 'post_content' => $html ) );
update_post_meta( $refund->ID, '_elementor_edit_mode', '' );
delete_post_meta( $refund->ID, '_elementor_element_cache' );
WP_CLI::success( "Refund Policy (#{$refund->ID}) updated. Backup in post meta {$stamp}." );

$terms = get_page_by_path( 'terms-and-conditions' );
if ( ! $terms ) {
	WP_CLI::error( 'Terms page not found.' );
}
$old  = 'Welcome to CAShaadi.in, a matrimonial platform dedicated to Chartered Accountants.';
$new  = 'Welcome to CAShaadi.in, a matrimonial platform for Chartered Accountants, owned and operated by SDG Ventures (“we”, “us”).';
$data = (string) get_post_meta( $terms->ID, '_elementor_data', true );
$body = (string) $terms->post_content;
if ( false === strpos( $data, $old ) && false === strpos( $body, $old ) ) {
	WP_CLI::warning( 'Terms: the introduction sentence was not found as expected; nothing changed. Edit it in Elementor.' );
	return;
}
update_post_meta( $terms->ID, $stamp, wp_slash( wp_json_encode( array( 'post_content' => $body, '_elementor_data' => $data ) ) ) );
if ( false !== strpos( $data, $old ) ) {
	update_post_meta( $terms->ID, '_elementor_data', wp_slash( str_replace( $old, $new, $data ) ) );
}
if ( false !== strpos( $body, $old ) ) {
	wp_update_post( array( 'ID' => $terms->ID, 'post_content' => str_replace( $old, json_decode( '"' . $new . '"' ), $body ) ) );
}
delete_post_meta( $terms->ID, '_elementor_element_cache' );
delete_post_meta( $terms->ID, '_elementor_css' );
WP_CLI::success( "Terms (#{$terms->ID}) introduction now names SDG Ventures. Backup in post meta {$stamp}." );
