<?php
/**
 * Replace the Refund Policy page's content (owner, 2026-10-23).
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/refund-policy.php          (shows what it would change)
 *   wp eval-file wp-content/plugins/cashaadi-ui/tools/refund-policy.php apply    (changes it)
 * WordPress keeps the old text as a revision, so it can be restored from the
 * page editor's Revisions panel.
 */
$apply = in_array( 'apply', (array) $args, true );
$pages = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 's' => 'refund', 'numberposts' => 10 ) );
$pages = array_values( array_filter( $pages, function ( $p ) { return false !== stripos( $p->post_title . ' ' . $p->post_name, 'refund' ); } ) );
if ( 1 !== count( $pages ) ) {
	WP_CLI::log( 'Expected exactly one Refund page, found ' . count( $pages ) . ':' );
	foreach ( $pages as $p ) { WP_CLI::log( "  #{$p->ID} {$p->post_title} ({$p->post_status}) " . get_permalink( $p ) ); }
	WP_CLI::error( 'Nothing changed. Tell Claude which ID to use.' );
}
$page = $pages[0];

$html = <<<'HTML'
<!-- wp:paragraph --><p><em>Last updated: 23 October 2026</em></p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>CAShaadi Premium is a digital membership. Once purchased, it is activated on your account immediately.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>Refunds in genuine cases</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>We give refunds in genuine cases. Examples are being charged twice for the same membership, a payment taken without Premium being activated on your account, or a purchase made by mistake and reported before the membership is used.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>All other cases</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>For service issues, or any other problem you face using the platform, refunds are at CAShaadi's discretion. We will look at each request individually and may offer a refund, a partial refund, an extension of your membership, or another resolution.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>How to ask</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Email <a href="mailto:support@cashaadi.in">support@cashaadi.in</a> from your registered email address, with your order number and the reason for your request. We aim to reply within 3 working days.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>How refunds are paid</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Approved refunds are made to the original payment method. They usually reach your account within 5 to 7 working days, depending on your bank or payment provider.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>When refunds are not given</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Refunds are not given for a membership that has been substantially used, or where an account was closed for breaking our terms (for example a fake profile or misuse of the platform).</p><!-- /wp:paragraph -->
HTML;

WP_CLI::log( "Page: #{$page->ID} \"{$page->post_title}\" " . get_permalink( $page ) );
WP_CLI::log( 'Current text starts: ' . mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $page->post_content ) ) ), 0, 200 ) . '…' );
if ( ! $apply ) {
	WP_CLI::success( 'Dry run. Run again with "apply" at the end to replace it.' );
	return;
}
$r = wp_update_post( array( 'ID' => $page->ID, 'post_content' => $html ), true );
if ( is_wp_error( $r ) ) {
	WP_CLI::error( $r->get_error_message() );
}
WP_CLI::success( 'Refund policy updated. The old version is under Revisions in the page editor.' );
