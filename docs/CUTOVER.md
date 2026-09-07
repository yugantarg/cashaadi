# Production cutover — runbook

Rewritten **2026-09-07** against plugin **v1.22.0**. The previous version was
written at v1.4.1 and is out of date in ways that matter: cron, the mail
transport and the photo pipeline have all changed since.

**Nothing here has been executed** except the pre-flight checks marked ✅.

---

## What is and is not moving

**Moving:** the plugin, the flags, the stripped child theme, the retirement of
the old mu-plugin and all 66 snippets.

**NOT moving: the database.** Production has **515 real members** and is taking
signups. Staging2's database is a months-old clone plus test data. Copying it
would delete every member and message since. Production's data stays where it
is. That is the whole reason this is a cutover and not a site copy.

## Pre-flight, already verified ✅

| Check | Result |
|---|---|
| xProfile field ids match the plugin's constants | ✅ all ten, plus `Created for` = 594 |
| Production plugin directory | absent — clean slate |
| WordPress cron | ✅ fixed 2026-09-07: one job, `*/5`, PHP 8.2 |
| Availability | ✅ no 503 in the probe since 2026-09-06 18:17 |
| Daily mail cap | ✅ `csm_remail_daily_cap` = 2000 |
| Master mail switch | ✅ `csm_remail_master` = 0 (nothing sends) |

## The three ways this breaks

Each has happened once already, on staging.

**1. Redeclare fatal.** `Discover`, `Block`, `Matches`, `Otp` and `Emails`
define global functions. Their snippets must be off **before** the flags go on.

**2. WPCode's cache.** `post_status = draft` does **not** disable a snippet —
WPCode runs the code held in the `wpcode_snippets` option. Delete that option,
then prove it with `function_exists()`, never by looking at the admin screen.

**3. The unguarded datebox class.** The theme declares
`My_Custom_Datebox_Field` with no guard. `Datebox` stands down while
`cashaadi_register_custom_datebox()` exists, so the theme swap is what hands
over — the two must change in the same step.

---

## Before the window

1. **Full backup, files and database, confirmed complete.** This is the only
   rollback for steps 3–7. Trigger it in hPanel; it is not something the shell
   can do.
2. **Record the baseline**: `wp option get active_plugins`, user count, and the
   status codes for `/`, `/members/`, `/register/`, `/membership-pricing/`.
3. **Pick a quiet hour.** Rollback loses any signup made during the window.

## The window

### 1. Deploy the plugin files — do NOT activate
Clone the repo into `wp-content/plugins/cashaadi-ui`. An inactive plugin
directory does nothing.

### 2. Disable every snippet, in one operation
```sql
UPDATE wp_posts SET post_status='draft' WHERE post_type='wpcode' AND post_status='publish';
DELETE FROM wp_options WHERE option_name='wpcode_snippets';
```
**Verify — this is the step that fails silently:**
```
wp eval 'echo function_exists("csm_refill_tray") ? "STILL LIVE" : "off";'
```

### 3. Add the flags
Near the top of `wp-config.php`, above the "stop editing" line:
```php
define( 'CASHAADI_DISCOVER_ENABLED', true );
define( 'CASHAADI_MATCHES_ENABLED', true );
define( 'CASHAADI_BLOCK_ENABLED', true );
define( 'CASHAADI_SIGNUP_ENABLED', true );
define( 'CASHAADI_ANALYTICS_ENABLED', true );
define( 'CASHAADI_ADMIN_ENABLED', true );
define( 'CASHAADI_PREMIUM_ENABLED', true );
define( 'CASHAADI_CA_VERIFY_ENABLED', true );
define( 'CASHAADI_PHOTOS_ENABLED', true );
define( 'CASHAADI_VERIFICATION_ENABLED', true );
define( 'CASHAADI_EMAILS_ENABLED', true );
```
`CASHAADI_PAUSE_ENABLED` is deliberately **omitted** — "pause my profile" is
built but held back at the owner's request.

### 4. Activate the plugin
`wp plugin activate cashaadi-ui`.

### 5. Retire the old mu-plugin
```
mv wp-content/mu-plugins/cashaadi-discovery.php wp-content/cashaadi-discovery.php.retired
```

### 6. Remove the three temporary mu-plugins
All were stopgaps for problems the plugin now solves properly:

| File | Superseded by |
|---|---|
| `00-csm-memlog.php` | diagnosis complete |
| `01-csm-photo-upload-cost.php` | the plugin downscales in the browser |
| `02-csm-avatar-size.php` | `Modules\Photos\Photos` (and it renders from the ORIGINAL, which the mu-plugin cannot) |

### 7. Swap the child theme
Back up the live `functions.php` (**653 lines**), then replace it with the
136-line version from `theme/buddyx-child/functions.php`.

### 8. Analytics, atomically with its flag
**Settings → CA Shaadi Tracking**: enter the Google Ads ID and label and set
**enabled = 1**. Snippet #12204 and `Modules\Analytics` fire the *same*
conversion; with the snippet off and `enabled = 0`, conversions stop recording
**silently**.

### 9. Purge caches
`wp litespeed-purge all`, and flush the object cache.

---

## What activation will do to member data

These run automatically, once, and they change stored settings for real
members. None is reversible by re-running it.

| Migration | Effect on 515 members |
|---|---|
| `enforce_private_defaults` | Sets **phone → My matches** and **date of birth → Only me**, and makes both fields member-editable. On staging this moved 525 of 528. |
| `retire_loggedin` | Folds the retired "All members" level into "Everyone". |
| `merge_relative_friend` | Renames the "Friend" option to "Relative/Friend" and moves anyone holding "Relative" or "Friend" onto it. |
| `backfill_ages` | Writes the missing `Age` rows from date of birth. |
| `Seen::backfill` | Populates `wp_csm_seen` from the existing tray and likes. |
| Table installs | `wp_csm_seen`, `wp_csm_event_log`. |

Every one is a deliberate decision already taken; they are listed so nobody is
surprised by a privacy setting changing on 500 profiles at once.

## Immediately after — verify in this order

| Check | Expected |
|---|---|
| `/`, `/members/`, `/register/`, `/membership-pricing/` | 200 |
| `wp eval 'echo function_exists("csm_refill_tray");'` | true (the plugin's copy) |
| `cashaadi()` resolves to | `CAShaadi\Core\Engine` |
| Register page | "Create Your CAShaadi Account", "Continue" |
| Members directory | opposite gender only, no admins |
| A tray fills | `csm_refill_tray( <member id> )` returns rows |
| Profile edit | date of birth is a typed `dd/mm/yyyy` field |
| **Integration health page** | Sales Dashboard → Integration health, all green |
| Availability probe | still 200s (`~/monitor/probe.csv`) |

## Configuration that does not deploy

- `csm_pm_enforce = 1` (NSFW auto-hide)
- MSG91 credentials, if phone OTP is wanted
- Better Messages → **site_interval 30, thread_interval 8** (production is
  still on 10/3; staging was retuned 2026-09-06)
- Better Messages → Mobile → Auto Open Full Screen **off**
- **ZeptoMail**: enter the API key in CA Shaadi → Email delivery, then
  deactivate Brevo, then send the test. In that order — Brevo redefines
  `wp_mail()` and the two cannot share.
- **`csm_remail_master` stays 0.** Leave it a day, watch the queue fill
  correctly, then switch on.

## After the site is confirmed healthy

- **Avatar regeneration**: `Gallery::regenerate_avatars( 20 )` in batches, when
  the box is quiet. Only helps members whose original is ≥1400px.
- **Placeholder cleanup**: production has its own copy —
  `UPDATE wp_bp_xprofile_data SET value='' WHERE field_id IN (302,405,418) AND LOWER(TRIM(value))='select'`. Back the rows up first.
- **Fake users**: re-run the identification on production; staging's ids will
  not match.
- **The 163-member photo email** is staged on STAGING only. Production needs
  its own audience scan from the Email campaigns screen.

## Rollback

**During the window:** reverse the steps — deactivate the plugin, move the
mu-plugin back, restore `functions.php`, re-publish the snippets
(`UPDATE wp_posts SET post_status='publish' WHERE post_type='wpcode' AND ID IN (…)`)
and delete `wpcode_snippets` again so WPCode rebuilds.

**After the window:** restore the backup, accepting the loss of anything
registered since. The data migrations above are **not** undone by deactivating
the plugin — only by the restore. That is the strongest argument for the backup
in step 1.
