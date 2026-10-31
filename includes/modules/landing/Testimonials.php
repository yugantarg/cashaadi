<?php
/**
 * Three member testimonials with name and photo (owner, 2026-10-31), on the
 * home page and the pricing page.
 *
 * Each member gave permission in the survey ("CA Shaadi may show this with my
 * name and photo"). Quotes are theirs, with spelling corrected only. The photo
 * is their current main photo; if they remove it or close their account, the
 * card shows their initial, and a closed account is left out.
 */

namespace CAShaadi\Modules\Landing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Testimonials {

	/** member id => [ name, quote ] */
	const ITEMS = array(
		3709 => array( 'Amarnath', 'Exclusive app for CAs, and CA certificate validation means genuine profiles.' ),
		4010 => array( 'Anil Nagaraj', 'Verifying the qualifications makes the background check easier. The cherry on top is the LinkedIn profile.' ),
		1895 => array( 'Aishwarya', 'One platform where I can find people with the same interests, in the same profession.' ),
	);

	public static function html( $title = 'What members say' ) {
		$cards = '';
		foreach ( self::ITEMS as $uid => $it ) {
			if ( ! get_userdata( $uid ) || ( class_exists( '\CAShaadi\Modules\Settings\Closed' ) && \CAShaadi\Modules\Settings\Closed::is_closed( $uid ) ) ) {
				continue;
			}
			$photo = '';
			$ids   = get_user_meta( $uid, 'csm_photos', true );
			if ( is_array( $ids ) && $ids && function_exists( 'bp_core_fetch_avatar' ) ) {
				$photo = (string) bp_core_fetch_avatar( array( 'item_id' => $uid, 'type' => 'full', 'html' => false ) );
			}
			$face = $photo
				? '<img src="' . esc_url( $photo ) . '" alt="" loading="lazy">'
				: '<span>' . esc_html( mb_strtoupper( mb_substr( $it[0], 0, 1 ) ) ) . '</span>';
			$cards .= '<figure class="csm-tm-card"><blockquote>“' . esc_html( $it[1] ) . '”</blockquote>'
				. '<figcaption><span class="csm-tm-face">' . $face . '</span><b>' . esc_html( $it[0] ) . '</b><small>CA Shaadi member</small></figcaption></figure>';
		}
		if ( '' === $cards ) {
			return '';
		}
		return '<section class="csm-tm"><h2>' . esc_html( $title ) . '</h2><div class="csm-tm-row">' . $cards . '</div></section>'
			. '<style>'
			. '.csm-tm{max-width:1040px;margin:32px auto;padding:0 16px}'
			. '.csm-tm h2{text-align:center;font-size:26px;margin:0 0 18px}'
			. '.csm-tm-row{display:grid;gap:16px;grid-template-columns:1fr}'
			. '@media(min-width:760px){.csm-tm-row{grid-template-columns:repeat(3,1fr)}}'
			. '.csm-tm-card{margin:0;padding:20px;border:1px solid #e4eaf0;border-radius:16px;background:#fff;display:flex;flex-direction:column;justify-content:space-between}'
			. '.csm-tm-card blockquote{margin:0 0 16px;font-size:16px;line-height:1.55;color:#16212b}'
			. '.csm-tm-card figcaption{display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
			. '.csm-tm-card b{font-size:15px}.csm-tm-card small{color:#5c6a76;width:100%;margin:-6px 0 0 52px}'
			. '.csm-tm-face{width:42px;height:42px;border-radius:50%;overflow:hidden;background:#e4eaf0;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#0b2350;flex:0 0 42px}'
			. '.csm-tm-face img{width:100%;height:100%;object-fit:cover}'
			. '</style>';
	}
}
