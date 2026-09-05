<?php
/**
 * Photo helpers.
 *
 * WHAT THIS NO LONGER DOES
 * It used to render a "Your photos" heading and a blur checkbox on the avatar
 * screen. Both were already there: snippet #11822 renders the photo grid with
 * its own heading and help text, and #11770 renders a "Photo privacy" toggle.
 * So the screen carried two of each.
 *
 * Worse, ours rendered with NO styling at all. The rules for it live in
 * assets/css/photos-gallery.css, which only the Photos module enqueues — and
 * that module is flag-gated OFF on staging2, so the stylesheet never loads on
 * the very screen the markup appeared on. Verified live: computed padding 0,
 * no background, no radius. It was the ugliest thing on the page and it was
 * entirely self-inflicted.
 *
 * WHAT REMAINS
 *   - has_photo(): the single answer to "does this member have a photo", used
 *     by /welcome/ step 1 and the /profile/ hub.
 *   - suppress_placeholder_zoom(): stops the avatar lightbox opening on a
 *     placeholder, by neutralising the snippet's own config rather than
 *     duplicating its behaviour.
 *
 * Both read the shared contract (`csm_photos`, `csm_photo_private`) rather than
 * going through the flag-gated Photos module, so they work whichever layer is
 * live.
 */

namespace CAShaadi\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoOptions {

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'suppress_placeholder_zoom' ), 1 );
	}

	/**
	 * Stop the avatar lightbox opening on a photo that does not exist.
	 *
	 * Reported live: tapping a blank profile photo opened a full-screen
	 * mystery-man. The zoom attaches whenever window.CSM_PN.url is set, and that
	 * is set even with no upload, because the avatar falls back to Gravatar's
	 * d=mm or BuddyPress's own mystery-man image.
	 *
	 * The equivalent guard is already in assets/js/photos-gallery.js — but that
	 * file is NOT loaded on staging2: the Photos module is flag-gated OFF there,
	 * so the zoom is served by snippet #11771's inline script instead (verified
	 * in the DOM: no plugin photo scripts on the page at all). Editing the
	 * snippet would fork behaviour away from the plugin we are migrating to, and
	 * enabling the photos flag here would be an unplanned cutover.
	 *
	 * So: intercept the assignment. The snippet opens with
	 * `if (!cfg || !cfg.url) { return; }` — its own guard. Blanking `url` for a
	 * placeholder makes the snippet decline to attach, using the check it
	 * already has. This runs at wp_head priority 1, before the footer script
	 * that does the assigning.
	 *
	 * Harmless once photos moves into the plugin: the JS guard covers the same
	 * case, and this shim only ever blanks a URL that is already a placeholder.
	 */
	public static function suppress_placeholder_zoom() {
		if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		?>
		<script id="csm-no-placeholder-zoom">
		(function () {
			function isPlaceholder( u ) {
				return ! u
					|| /gravatar\.com/i.test( u )
					|| /mystery-?man/i.test( u )
					|| /\/bp-core\/images\//i.test( u );
			}
			var store;
			try {
				Object.defineProperty( window, 'CSM_PN', {
					configurable: true,
					get: function () { return store; },
					set: function ( next ) {
						if ( next && isPlaceholder( next.url ) ) {
							try { next = Object.assign( {}, next, { url: '' } ); } catch ( e ) {}
						}
						store = next;
					}
				} );
			} catch ( e ) {}
		})();
		</script>
		<?php
	}

	/** Does this member have at least one photo? Used by /welcome/ progress. */
	public static function has_photo( $uid ) {
		$uid = (int) $uid;

		$ids = get_user_meta( $uid, 'csm_photos', true );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return true;
		}

		/*
		 * A member who uploaded through BuddyPress directly still counts — but
		 * bp_get_user_has_avatar() CANNOT be used to decide that here.
		 *
		 * The Photos module filters bp_core_avatar_default to serve a local
		 * placeholder instead of Gravatar, and BuddyPress then reports "has
		 * avatar" for everyone, including a member who has never uploaded
		 * anything. That silently made the mandatory photo step skippable: every
		 * new signup was told it was already done. Found by hand-testing a real
		 * registration, which jumped straight past the photo screen.
		 *
		 * So ask the filesystem instead: BuddyPress writes uploads to
		 * uploads/avatars/<user id>/, and that directory exists only when a real
		 * file was saved.
		 */
		$dir = wp_upload_dir();
		if ( empty( $dir['basedir'] ) ) {
			return false; // cannot tell; treat as "no photo" so we ask rather than assume
		}

		$path = trailingslashit( $dir['basedir'] ) . 'avatars/' . $uid;
		if ( ! is_dir( $path ) ) {
			return false;
		}

		// The directory can linger after a delete, so require an actual image.
		$files = glob( $path . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE );
		return ! empty( $files );
	}

}
