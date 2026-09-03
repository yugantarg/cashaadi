<?php
/**
 * Phone OTP (MSG91) credentials — entered in the admin, not wp-config.
 *
 * Owner: credentials should have a front end, not raw PHP. Tracking already had
 * one; MSG91's authkey / widget id / token were constant-only, so this gives them
 * the same treatment — an option-backed Settings screen with the same secret
 * handling (blank leaves a saved key untouched, __clear__ removes it, keys are
 * never printed back into the page).
 *
 * A CASHAADI_MSG91_* constant in wp-config still wins, so a locked-down install
 * can pin values and the existing constants keep working.
 */

namespace CAShaadi\Modules\Otp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OtpSettings {

	const OPTION = 'csm_otp';
	const GROUP  = 'csm_otp_group';

	/** key => wp-config constant that overrides it. */
	private static function map() {
		return array(
			'widget_id'  => 'CASHAADI_MSG91_WIDGET_ID',
			'token_auth' => 'CASHAADI_MSG91_TOKEN_AUTH',
			'authkey'    => 'CASHAADI_MSG91_AUTHKEY',
		);
	}

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
			'widget_id'  => '',
			'token_auth' => '',
			'authkey'    => '',
		) );
	}

	/** One value: wp-config constant first, then the saved option. */
	public static function get( $key ) {
		$map = self::map();
		if ( isset( $map[ $key ] ) && defined( $map[ $key ] ) && '' !== (string) constant( $map[ $key ] ) ) {
			return (string) constant( $map[ $key ] );
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/** True once the widget id and token — the two OTP needs — are present. */
	public static function ready() {
		return '' !== self::get( 'widget_id' ) && '' !== self::get( 'token_auth' );
	}

	public static function menu() {
		add_options_page(
			__( 'CA Shaadi Phone OTP', 'cashaadi-ui' ),
			__( 'CA Shaadi Phone OTP', 'cashaadi-ui' ),
			'manage_options',
			'csm-otp',
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

	/** Widget id is not a secret; the token and authkey are — never overwrite with blank. */
	public static function sanitize( $input ) {
		$old = self::all();
		$in  = is_array( $input ) ? $input : array();

		$out = array( 'widget_id' => sanitize_text_field( $in['widget_id'] ?? '' ) );

		foreach ( array( 'token_auth', 'authkey' ) as $secret ) {
			$posted = trim( (string) ( $in[ $secret ] ?? '' ) );
			if ( '' === $posted ) {
				$out[ $secret ] = $old[ $secret ];
			} elseif ( '__clear__' === $posted ) {
				$out[ $secret ] = '';
			} else {
				$out[ $secret ] = sanitize_text_field( $posted );
			}
		}
		return $out;
	}

	private static function field( $key, $label, $help, $type = 'text' ) {
		$all    = self::all();
		$map    = self::map();
		$const  = isset( $map[ $key ] ) ? $map[ $key ] : '';
		$pinned = $const && defined( $const ) && '' !== (string) constant( $const );
		$secret = in_array( $key, array( 'token_auth', 'authkey' ), true );

		echo '<tr><th scope="row"><label for="csm-otp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="%1$s" id="csm-otp-%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text" autocomplete="off"%5$s>',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			$secret ? '' : esc_attr( $all[ $key ] ),
			$pinned ? ' disabled' : ''
		);

		if ( $pinned ) {
			echo '<p class="description"><strong>' . esc_html__( 'Set in wp-config.php, which takes precedence over this screen.', 'cashaadi-ui' ) . '</strong></p>';
		} elseif ( $secret ) {
			$has = '' !== $all[ $key ];
			echo '<p class="description">'
				. ( $has
					? esc_html__( 'A value is saved. Leave blank to keep it, type a new one to replace it, or type __clear__ to remove it.', 'cashaadi-ui' )
					: esc_html__( 'Nothing saved yet.', 'cashaadi-ui' ) )
				. '</p>';
		}
		echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		echo '</td></tr>';
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CA Shaadi Phone OTP', 'cashaadi-ui' ); ?></h1>
			<p><?php esc_html_e( 'MSG91 credentials for phone-number verification. Enter them here — no need to edit any files. OTP stays off until the Widget ID and Token are both set (and the OTP module is enabled).', 'cashaadi-ui' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						'widget_id',
						__( 'Widget ID', 'cashaadi-ui' ),
						__( 'MSG91 → Dashboard → OTP → your widget. A short hex string.', 'cashaadi-ui' )
					);
					self::field(
						'token_auth',
						__( 'Token Auth', 'cashaadi-ui' ),
						__( 'MSG91 → the same OTP widget → Widget Configuration → Token Auth.', 'cashaadi-ui' ),
						'password'
					);
					self::field(
						'authkey',
						__( 'Auth Key', 'cashaadi-ui' ),
						__( 'MSG91 → Settings → API → Auth Key. Used server-side to verify the access token.', 'cashaadi-ui' ),
						'password'
					);
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
