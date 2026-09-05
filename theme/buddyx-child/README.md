# theme/buddyx-child

The child theme's `functions.php` **as it now stands on the server**, kept here so
the last file outside this repo is at least visible to it.

This is a mirror, not the live file — editing it changes nothing. The live copy is
`wp-content/themes/buddyx-child/functions.php`.

## Why it still exists

653 lines became 136. What remains is genuine theme work plus one shim:

- `buddyx_child_enqueue_styles()` and the parent/child `theme_mods` bridge.
- `cashaadi_has_missing_required_fields()` — the required-field check. The theme's
  own BuddyPress templates (`members-loop.php`, the friends templates) call
  `csm_user_profile_is_complete()` directly, and the plugin's version delegates to
  this one. Keeping it here means those templates do not fatal if the plugin is
  ever deactivated. Both definitions are `function_exists`-guarded and agree.
- `csm_user_profile_is_complete()` — same reasoning; the plugin defines it first
  and this guarded copy stands down.

The five BuddyPress template overrides in `buddypress/` also stay. Templates
belong to a theme.

## What moved, 2026-09-05

Everything else — username hashing, login/logout redirects, BuddyPress wording,
navigation, the members-directory gender filter, the custom xProfile date field —
is now in `includes/modules/theme-compat/`. The friends notification-settings
function was deleted rather than moved: the Engagement module had already
unhooked it.

The pre-migration original is on the server as
`functions.php.bak-premigration-20260905-135343`.
