<?php
/**
 * Template part for displaying the footer info
 *
 * @package buddyx
 */

namespace BuddyX\Buddyx;

?>

<?php do_action( 'buddyx_copyright_before' ); ?>

<div class="site-info">
	<div class="container">
        <div id="footer_content">
            <div class="left">
                <?php echo wp_nav_menu( ['menu' => 'Site navigation'] ); ?>
            </div>
            <div class="right">
                <?php echo wp_nav_menu( ['menu' => 'Follow Us'] ); ?>
            </div>
        </div>
	</div>

    <?php echo buddyx_footer_custom_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

</div><!-- .site-info -->

<?php do_action( 'buddyx_copyright_after' ); ?>
