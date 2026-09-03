# baseline/ — live code that was never in version control

Read-only snapshots taken from **staging2** on 2026-09-04 over SSH. Nothing here
is loaded by the plugin. It exists so the repo is a complete picture of what
runs the site, and so this code can be reviewed and migrated deliberately
instead of being edited in place on a server.

**Do not edit these files expecting an effect.** They are copies. The live
originals are still the ones running.

| Path | Live original | Lines |
|---|---|---|
| `mu-plugins/cashaadi-discovery.php` | `wp-content/mu-plugins/` | 115 |
| `child-theme/` | `wp-content/themes/buddyx-child/` | ~815 |
| `snippets/11732.php` | WPCode snippet — Reminder Email Queue (Engine) | 1438 |
| `snippets/11733.php` | WPCode snippet — Reminder Email Monitor (Admin) | 694 |

Both snippets are recovered from `wp_posts.post_content`, un-escaped back to
real source, and `php -l` clean. They are the only two WPCode snippets still
active on staging2 — everything else has already been migrated into the plugin.

`wp-config.php` is deliberately **not** copied: it holds live credentials. What
matters from it is recorded, without secrets, in `docs/CONFIG.md`.

## What the mu-plugin owns

`cashaadi()` is 115 lines and is the base the Discover engine sits on:

- `wp_csm_tray` and `wp_csm_likes` — created outside the Migrator
- `get_week_id()` — the IST week string (`o-\WW`)
- `get_gender()` / `get_opposite_gender()`
- `table()` / `table_exists()`
- `log_event()` — writes to `wp_csm_event_log`, **a table that does not exist**,
  so every call has been a silent no-op

Because it is an mu-plugin it loads before everything and cannot be switched off
from the admin. Migrating it means moving these into `includes/core` and
deleting the file in the same change — the same disable-first ordering as the
snippet cutovers, or the global function is declared twice and the site fatals.
