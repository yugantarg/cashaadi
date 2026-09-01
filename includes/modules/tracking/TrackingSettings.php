<?php
/**
 * Tracking credentials — an admin screen, not wp-config.
 *
 * Owner, 2026-09-01: the advertising tags should live in the plugin, "but I
 * should be able to change credentials easily" — and for the server-side keys,
 * "I prefer a front end where I can enter rather than editing wp-config".
 *
 * So these are options, editable at Settings → CA Shaadi Tracking, rather than
 * constants. That is the right trade here: a mistyped conversion label costs a
 * campaign, and fixing it should not require SFTP access to a live site.
 *
 * WHAT IS AND IS NOT SECRET
 * The IDs (Google Ads conversion ID, GA4 measurement ID, Meta pixel ID) are not
 * secrets at all — they are visible in the page source of every site that uses
 * them. The two API keys ARE secrets: the GA4 Measurement Protocol secret and
 * the Meta access token can both write events into your properties. They are
 * stored in wp_options like any other setting, which means:
 *
 *   - they are readable by anyone with database access or the manage_options
 *     capability, which is the same bar as wp-config in practice
 *   - they are NOT written to the page, ever — server-side events are sent from
 *     PHP, so the browser never receives them
 *
 * If a key leaks, revoke it at the source; nothing here can be undone by
 * editing this screen alone.
 *
 * Constants still win where present, so an existing wp-config value keeps
 * working and can override the UI on a locked-down install.
 */

namespace CAShaadi\Modules\Tracking;

use CAShaadi\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackingSettings {

	const OPTION = 'csm_tracking';
	const GROUP  = 'csm_tracking_group';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
	}

	/** All stored values, with defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, array(
			'gads_id'         => '',
			'gads_label'      => '',
			'ga4_id'          => '',
			'ga4_api_secret'  => '',
			'meta_pixel_id'   => '',
			'meta_token'      => '',
			'enabled'         => 0,
		) );
	}

	/**
	 * One value, constant first.
	 *
	 * A constant in wp-config beats the UI so a locked-down install can pin a
	 * value, and so the existing CASHAADI_* constants keep working unchanged.
	 */
	public static function get( $key ) {
		$const = 'CASHAADI_' . strtoupper( $key );
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			return (string) constant( $const );
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	public static function menu() {
		add_options_page(
			__( 'CA Shaadi Tracking', 'cashaadi-ui' ),
			__( 'CA Shaadi Tracking', 'cashaadi-ui' ),
			'manage_options',
			'csm-tracking',
			array( __CLASS__, 'screen' )
		);
	}

	public static function settings() {
		register_setting( self::GROUP, self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => array(),
		) );
	}

	/**
	 * Clean every field, and never let a blank overwrite a stored secret.
	 *
	 * The two secret fields render empty (we do not print keys back into the
	 * page). Without this, opening the screen and pressing Save would wipe them —
	 * a trap that is easy to build and confusing to debug.
	 */
	public static function sanitize( $input ) {
		$old = self::all();
		$in  = is_array( $input ) ? $input : array();

		$out = array(
			'enabled'       => empty( $in['enabled'] ) ? 0 : 1,
			'gads_id'       => sanitize_text_field( $in['gads_id'] ?? '' ),
			'gads_label'    => sanitize_text_field( $in['gads_label'] ?? '' ),
			'ga4_id'        => sanitize_text_field( $in['ga4_id'] ?? '' ),
			'meta_pixel_id' => sanitize_text_field( $in['meta_pixel_id'] ?? '' ),
		);

		foreach ( array( 'ga4_api_secret', 'meta_token' ) as $secret ) {
			$posted = trim( (string) ( $in[ $secret ] ?? '' ) );
			if ( '' === $posted ) {
				$out[ $secret ] = $old[ $secret ]; // left blank = unchanged
			} elseif ( '__clear__' === $posted ) {
				$out[ $secret ] = '';              // explicit removal
			} else {
				$out[ $secret ] = sanitize_text_field( $posted );
			}
		}

		return $out;
	}

	private static function field( $key, $label, $help, $type = 'text', $placeholder = '' ) {
		$all    = self::all();
		$const  = 'CASHAADI_' . strtoupper( $key );
		$pinned = defined( $const ) && '' !== (string) constant( $const );
		$secret = in_array( $key, array( 'ga4_api_secret', 'meta_token' ), true );

		echo '<tr><th scope="row"><label for="csm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="%1$s" id="csm-%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text" placeholder="%5$s" autocomplete="off"%6$s>',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			// Secrets are never printed back into the page.
			$secret ? '' : esc_attr( $all[ $key ] ),
			esc_attr( $placeholder ),
			$pinned ? ' disabled' : ''
		);

		if ( $pinned ) {
			echo '<p class="description"><strong>' . esc_html__( 'Set in wp-config.php, which takes precedence over this screen.', 'cashaadi-ui' ) . '</strong></p>';
		} elseif ( $secret ) {
			$has = '' !== $all[ $key ];
			echo '<p class="description">'
				. ( $has
					? esc_html__( 'A key is saved. Leave blank to keep it, type a new one to replace it, or type __clear__ to remove it.', 'cashaadi-ui' )
					: esc_html__( 'No key saved yet.', 'cashaadi-ui' ) )
				. '</p>';
		}

		echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		echo '</td></tr>';
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CA Shaadi Tracking', 'cashaadi-ui' ); ?></h1>
			<p><?php esc_html_e( 'Conversion tracking for signup. Leave a field empty to switch that platform off — nothing is sent for a platform with no ID.', 'cashaadi-ui' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'cashaadi-ui' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $all['enabled'] ) ); ?>>
								<?php esc_html_e( 'Fire conversion events', 'cashaadi-ui' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave this off on staging, or test signups will be counted as real conversions.', 'cashaadi-ui' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Google Ads', 'cashaadi-ui' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						'gads_id',
						__( 'Conversion ID', 'cashaadi-ui' ),
						__( 'Google Ads → Goals → Conversions → Summary → your action → Tag setup. The ID looks like <code>AW-1014629759</code>.', 'cashaadi-ui' ),
						'text',
						'AW-1014629759'
					);
					self::field(
						'gads_label',
						__( 'Conversion label', 'cashaadi-ui' ),
						__( 'Same screen, shown as <code>send_to</code>. Paste the whole value including the ID, e.g. <code>AW-1014629759/abcDEF123</code>.', 'cashaadi-ui' ),
						'text',
						'AW-1014629759/abcDEF123'
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Google Analytics 4', 'cashaadi-ui' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						'ga4_id',
						__( 'Measurement ID', 'cashaadi-ui' ),
						__( 'GA4 → Admin → Data streams → your web stream. Looks like <code>G-XXXXXXX</code>.', 'cashaadi-ui' ),
						'text',
						'G-XXXXXXX'
					);
					self::field(
						'ga4_api_secret',
						__( 'Measurement Protocol secret', 'cashaadi-ui' ),
						__( 'Same data stream → Measurement Protocol API secrets → Create. Only needed for server-side events, which still record when a browser blocks the tag.', 'cashaadi-ui' ),
						'password'
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Meta (Facebook / Instagram)', 'cashaadi-ui' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						'meta_pixel_id',
						__( 'Pixel ID', 'cashaadi-ui' ),
						__( 'Meta Events Manager → Data sources → your pixel. A long number.', 'cashaadi-ui' ),
						'text'
					);
					self::field(
						'meta_token',
						__( 'Conversions API token', 'cashaadi-ui' ),
						__( 'Events Manager → your pixel → Settings → Conversions API → Generate access token. Only needed for server-side events.', 'cashaadi-ui' ),
						'password'
					);
					?>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Why the two API keys are optional', 'cashaadi-ui' ); ?></h2>
			<p class="description" style="max-width:60em">
				<?php esc_html_e( 'The IDs alone are enough for normal browser-based tracking. The two keys add server-side reporting, which still records a signup when an ad blocker or a privacy browser stops the tag from loading — typically a meaningful share of traffic. They are never sent to the browser.', 'cashaadi-ui' ); ?>
			</p>
		</div>
		<?php
	}
}
