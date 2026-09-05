<?php
/**
 * My_Custom_Datebox_Field — BuddyPress date field, relabelled and re-wrapped.
 *
 * GLOBAL class name, deliberately: it is stored in xProfile's field-type map and
 * the child theme declared it under this exact name. Renaming it would orphan
 * the mapping. Loaded only from Datebox::install(), behind a class_exists guard.
 *
 * Ported verbatim from buddyx-child/functions.php — the markup below is what the
 * theme's CSS targets, so the wrapper divs and class names matter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BP_XProfile_Field_Type_Datebox' ) ) {
	return;
}

class My_Custom_Datebox_Field extends BP_XProfile_Field_Type_Datebox {

	public function __construct() {
		parent::__construct();

		// How the type is listed in wp-admin › Users › Profile Fields.
		$this->name     = __( 'Date (picker)', 'cashaadi-ui' );
		$this->category = __( 'Custom', 'cashaadi-ui' );
	}

	/**
	 * Three selects, each with a visible label, inside .datebox-field wrappers.
	 *
	 * Same fields BuddyPress renders; the difference is the structure and the
	 * labels, which is what the styling hangs off.
	 */
	public function edit_field_html( array $raw_properties = array() ) {

		// Whose profile is being edited.
		if ( isset( $raw_properties['user_id'] ) ) {
			$user_id = (int) $raw_properties['user_id'];
			unset( $raw_properties['user_id'] );
		} else {
			$user_id = bp_displayed_user_id();
		}

		$day_r = bp_parse_args(
			$raw_properties,
			array(
				'id'   => bp_get_the_profile_field_input_name() . '_day',
				'name' => bp_get_the_profile_field_input_name() . '_day',
			)
		);

		$month_r = bp_parse_args(
			$raw_properties,
			array(
				'id'   => bp_get_the_profile_field_input_name() . '_month',
				'name' => bp_get_the_profile_field_input_name() . '_month',
			)
		);

		$year_r = bp_parse_args(
			$raw_properties,
			array(
				'id'   => bp_get_the_profile_field_input_name() . '_year',
				'name' => bp_get_the_profile_field_input_name() . '_year',
			)
		);
		?>

			<legend>
				<?php bp_the_profile_field_name(); ?>
				<?php bp_the_profile_field_required_label(); ?>
			</legend>

			<?php if ( bp_get_the_profile_field_description() ) : ?>
				<p class="description" tabindex="0"><?php bp_the_profile_field_description(); ?></p>
			<?php endif; ?>

			<div class="input-options datebox-selects">
				<?php
				// Dynamic hook, e.g. bp_field_12_errors.
				do_action( bp_get_the_profile_field_errors_action() );
				?>

				<div class="datebox-field datebox-day">
					<label for="<?php bp_the_profile_field_input_name(); ?>_day" class="xprofile-field-label">
						<?php esc_html_e( 'Day', 'buddypress' ); ?>
					</label>

					<select <?php $this->output_edit_field_html_elements( $day_r ); ?>>
						<?php
						bp_the_profile_field_options( array(
							'type'    => 'day',
							'user_id' => $user_id,
						) );
						?>
					</select>
				</div>

				<div class="datebox-field datebox-month">
					<label for="<?php bp_the_profile_field_input_name(); ?>_month" class="xprofile-field-label">
						<?php esc_html_e( 'Month', 'buddypress' ); ?>
					</label>

					<select <?php $this->output_edit_field_html_elements( $month_r ); ?>>
						<?php
						bp_the_profile_field_options( array(
							'type'    => 'month',
							'user_id' => $user_id,
						) );
						?>
					</select>
				</div>

				<div class="datebox-field datebox-year">
					<label for="<?php bp_the_profile_field_input_name(); ?>_year" class="xprofile-field-label">
						<?php esc_html_e( 'Year', 'buddypress' ); ?>
					</label>

					<select <?php $this->output_edit_field_html_elements( $year_r ); ?>>
						<?php
						bp_the_profile_field_options( array(
							'type'    => 'year',
							'user_id' => $user_id,
						) );
						?>
					</select>
				</div>

			</div><!-- .input-options.datebox-selects -->

		<?php
	}
}
