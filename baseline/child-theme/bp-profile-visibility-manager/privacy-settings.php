<?php
/**
 * Profile visibility settings template
 *
 */
?>
<?php
$user_id = bp_displayed_user_id();
?>
<form name="bp-profile-visibility_settings" method="post" class="standard-form">
    <?php if ( bp_profile_visibility_get_option( 'enable_member_directory_visibility', 'yes' ) != 'no' ) : ?>
    <div class="bppv-visibility-settings-block">

        <label> <?php _e( 'List in members directory?', 'bp-profile-visibility-manager' ); ?></label>

        <div class="radio">
            <label><input type="radio" value="no" name="bp_exclude_in_dir" <?php echo checked( 'no', bp_profile_visibility_get_settings( $user_id, 'bp_exclude_in_dir' ) ); ?> /><?php _e( 'Yes', 'bp-profile-visibility-manager' ); ?></label>
            <label>
                <input type="radio" value="yes" name="bp_exclude_in_dir" <?php echo checked( 'yes', bp_profile_visibility_get_settings( $user_id, 'bp_exclude_in_dir' ) ); ?> /><?php _e( 'No', 'bp-profile-visibility-manager' ); ?>
            </label>
        </div>

        <p class="help-block"> <?php _e( "If you select No, You won't be listed in the members directory.", 'bp-profile-visibility-manager' ); ?></p>

    </div>
    <?php endif;?>

    <?php if ( bp_profile_visibility_get_option( 'enable_member_search_visibility', 'yes' ) != 'no' ) : ?>
    <div class="bppv-visibility-settings-block">
        <label> <?php _e( "List in member search?", 'bp-profile-visibility-manager' ); ?></label>
        <div class="radio">
            <label><input type="radio" value="no" name="bp_exclude_in_search" <?php echo checked( 'no', bp_profile_visibility_get_settings( $user_id, 'bp_exclude_in_search' ) ); ?> /><?php _e( 'Yes', 'bp-profile-visibility-manager' ); ?></label>
            <label><input type="radio" value="yes" name="bp_exclude_in_search" <?php echo checked( 'yes', bp_profile_visibility_get_settings( $user_id, 'bp_exclude_in_search' ) ); ?> /><?php _e( 'No', 'bp-profile-visibility-manager' ); ?></label>
        </div>
        <p class="help-block"> <?php _e( "If you select No, You won't be listed in the directory searches but you will be visible in the directory.", 'bp-profile-visibility-manager' ); ?>

    </div>
    <?php endif;?>

    <?php if ( bp_profile_visibility_get_option( 'enable_friend_request_visibility', 'yes' ) != 'no' ) : ?>

    <div class="bppv-visibility-settings-block">
        <label> <?php _e( "Allow members to send me match request?", 'bp-profile-visibility-manager' ); ?></label>
        <div class="radio">
            <label><input type="radio" value="yes" name="bp_allow_friendship_request" <?php echo checked( 'yes', bp_profile_visibility_get_settings( $user_id, 'bp_allow_friendship_request' ) ); ?> /><?php _e( 'Yes', 'bp-profile-visibility-manager' ); ?></label>
            <label><input type="radio" value="no" name="bp_allow_friendship_request" <?php echo checked( 'no', bp_profile_visibility_get_settings( $user_id, 'bp_allow_friendship_request' ) ); ?> /><?php _e( 'No', 'bp-profile-visibility-manager' ); ?></label>
        </div>
        <p class="help-block"> <?php _e( 'No one will be able to add you as a match if you select No.', 'bp-profile-visibility-manager' ); ?>

    </div>

    <?php endif;?>

    <?php if ( function_exists( 'bp_follow_start_following' ) && bp_profile_visibility_get_option( 'enable_follow_request_visibility', 'no' ) == 'yes' ) : ?>
        <div class="bppv-visibility-settings-block">
            <label> <?php _e( "Allow members to follow you?", 'bp-profile-visibility-manager' ); ?></label>
            <div class="radio">
                <label><input type="radio" value="yes" name="bp_allow_follow_request" <?php echo checked( 'yes', bp_profile_visibility_get_settings( $user_id, 'bp_allow_follow_request' ) ); ?> /><?php _e( 'Yes', 'bp-profile-visibility-manager' ); ?></label>
                <label><input type="radio" value="no" name="bp_allow_follow_request" <?php echo checked( 'no', bp_profile_visibility_get_settings( $user_id, 'bp_allow_follow_request' ) ); ?> /><?php _e( 'No', 'bp-profile-visibility-manager' ); ?></label>
            </div>
            <p class="help-block"> <?php _e( 'No one will be able to follow you if you select No.', 'bp-profile-visibility-manager' ); ?>

        </div>
    <?php endif; ?>
    <?php if ( bp_profile_visibility_get_option( 'enable_last_activity_visibility', 'yes' ) != 'no' ) : ?>
    <div class="bppv-visibility-settings-block">
        <label> <?php _e( 'Would you like to hide your last active time?', 'bp-profile-visibility-manager' ); ?></label>
        <div class="radio">
            <label><input type="radio" value="yes" name="bp_hide_last_active" <?php echo checked( 'yes', bp_profile_visibility_get_settings( $user_id, 'bp_hide_last_active' ) ); ?> /><?php _e( 'Yes', 'bp-profile-visibility-manager' ); ?>
            </label>
            <label><input type="radio" value="no" name="bp_hide_last_active" <?php echo checked( 'no', bp_profile_visibility_get_settings( $user_id, 'bp_hide_last_active' ) ); ?> /><?php _e( 'No', 'bp-profile-visibility-manager' ); ?>
            </label>
        </div>
        <p class="help-block"> <?php _e( 'Hiding last active time will affect your visibility in directory and other places.', 'bp-profile-visibility-manager' ); ?></p>
    </div>
    <?php endif;?>
    <?php if ( bp_profile_visibility_get_option( 'enable_profile_privacy_visibility', 'yes' ) != 'no' ) : ?>
    <div class="bppv-visibility-settings-block">
        <label> <?php _e( 'Who Can See your profile?', 'bp-profile-visibility-manager' ); ?></label>
        <?php bp_profile_visibility_list_visibilities( 'bp_profile_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_visibility' ) ); ?>


    </div>
    <?php endif;?>
    <?php if ( bp_profile_visibility_manager()->get_option( 'enable_friends_list_visibility' ) == 'yes' && bp_is_active( 'friends' ) ) : ?>
        <div class="bppv-visibility-settings-block">
            <label> <?php _e( 'Who Can See your matches?', 'bp-profile-visibility-manager' ); ?></label>
            <?php bp_profile_visibility_list_visibilities( 'bp_friends_list_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_friends_list_visibility' ) ); ?>
        </div>

    <?php endif; ?>

    <?php if ( bp_profile_visibility_manager()->get_option( 'enable_group_tab_visibility' ) == 'yes' && bp_is_active( 'groups' ) ) : ?>
        <div class="bppv-visibility-settings-block">
            <label> <?php _e( 'Who Can See your groups?', 'bp-profile-visibility-manager' ); ?></label>
            <?php bp_profile_visibility_list_visibilities( 'bp_profile_group_tab_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_group_tab_visibility' ) ); ?>
        </div>

    <?php endif; ?>

    <?php if ( defined( 'BP_PLATFORM_VERSION' ) ) : ?>

        <?php if ( bp_is_active( 'media' ) ) : ?>
            <?php if ( bp_profile_visibility_manager()->get_option( 'enable_bb_photo_tab_visibility' ) == 'yes' && bp_is_profile_media_support_enabled() ) : ?>
                <div class="bppv-visibility-settings-block">
                    <label> <?php _e( 'Who Can See your photos?', 'bp-profile-visibility-manager' ); ?></label>
                    <?php bp_profile_visibility_list_visibilities( 'bp_profile_bb_photo_tab_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_bb_photo_tab_visibility' ) ); ?>
                </div>
            <?php endif; ?>
            <?php if ( bp_profile_visibility_manager()->get_option( 'enable_bb_video_tab_visibility' ) == 'yes' && bp_is_profile_video_support_enabled() ) : ?>
                <div class="bppv-visibility-settings-block">
                    <label> <?php _e( 'Who Can See your videos?', 'bp-profile-visibility-manager' ); ?></label>
                    <?php bp_profile_visibility_list_visibilities( 'bp_profile_bb_video_tab_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_bb_video_tab_visibility' ) ); ?>
                </div>
            <?php endif; ?>
            <?php if ( bp_profile_visibility_manager()->get_option( 'enable_bb_document_tab_visibility' ) == 'yes' && bp_is_profile_document_support_enabled() ) : ?>
                <div class="bppv-visibility-settings-block">
                    <label> <?php _e( 'Who Can See your documents?', 'bp-profile-visibility-manager' ); ?></label>
                    <?php bp_profile_visibility_list_visibilities( 'bp_profile_bb_document_tab_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_bb_document_tab_visibility' ) ); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( bp_is_active( 'forums' ) && bp_profile_visibility_manager()->get_option( 'enable_bb_forum_tab_visibility' ) == 'yes' ) : ?>
            <div class="bppv-visibility-settings-block">
                <label> <?php _e( 'Who Can See your forums?', 'bp-profile-visibility-manager' ); ?></label>
                <?php bp_profile_visibility_list_visibilities( 'bp_profile_bb_forum_tab_visibility', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_bb_forum_tab_visibility' ) ); ?>
            </div>
        <?php endif; ?>
        <?php if ( bp_is_active( 'activity' ) && bp_profile_visibility_manager()->get_option( 'enable_restriction_bb_wall_posting' ) == 'yes' ) : ?>
            <div class="bppv-visibility-settings-block">
                <label> <?php _e( 'Who Can Post On Your Wall?', 'bp-profile-visibility-manager' ); ?></label>
                <?php ( new BPPV_Visibility_Modifier( array( 'public', 'self' ) ) )->list( 'bp_profile_bb_wall_posting_restriction', '', bp_profile_visibility_get_settings( $user_id, 'bp_profile_bb_wall_posting_restriction' ) ); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php wp_nonce_field( 'bp-profile-visibility-manager' ); ?>

    <p class="submit">
        <input type="submit" id="bppv_save_submit" name="bppv_save_submit" class="button" value="<?php _e( 'Save', 'bp-profile-visibility-manager' ); ?>"/>
    </p>
</form>