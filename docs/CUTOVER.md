# Production cutover runbook — single-window plan

**Nothing here has been executed.** This replaces the phased draft: the owner
asked for a full migration in one window, which is workable because staging2 has
been running the finished state for weeks.

Written 2026-09-05 against plugin **v1.4.1**.

---

## What is and is not being moved

**Moving:** the plugin, the flags, the stripped child theme, the retirement of
the mu-plugin and all 66 snippets.

**NOT moving: the database.** Production is live and taking signups — the newest
member registered on the day this was written. Staging2's database is a months-old
clone plus test data. Copying it would delete ~45 real members and every message
and profile edit since. Production's data stays exactly where it is.

That is the whole reason this is a cutover rather than a site copy.

## Why one window is defensible

Production and staging2 run **the same 28 plugins**, differing by exactly one on
each side (`litespeed-cache` on production, `cashaadi-ui` on staging2). The end
state has been live on staging for weeks with the owner testing it. There is no
software difference left to discover incrementally.

**The cost:** if something misbehaves afterwards, there are 66 changes to bisect
rather than one. And rollback is restore-from-backup, which loses any signup that
happened during the window. Pick a genuinely quiet hour.

---

## The three ways this breaks

Each has already happened once on staging.

**1. Redeclare fatal.** `Discover`, `Block`, `Matches`, `Otp` and `Emails` define
global functions. Their snippets must be off **before** the flags go on. In this
plan both happen inside one maintenance window with the site briefly unreachable,
which sidesteps it — but the order below still matters.

**2. WPCode's cache.** `post_status = draft` does **not** disable a snippet.
WPCode runs the code from the `wpcode_snippets` option. Delete that option, then
prove it with `function_exists()` — never by looking at the admin screen.

**3. The unguarded datebox class.** The theme declares
`My_Custom_Datebox_Field` with no guard. The plugin's `Datebox` stands down while
`cashaadi_register_custom_datebox()` exists, so the theme swap is what hands over.

---

## Before the window

1. **Full backup, files and database.** Confirm it completed. This is the only
   rollback for steps 3–7.
2. **Baseline, recorded**: `wp option get active_plugins`, user count, homepage /
   `/members/` / `/register/` / `/membership-pricing/` status codes.
3. **Have the tracking credentials to hand** — Google Ads ID and label. They are
   already in staging's `csm_tracking`; production needs the same values **and
   `enabled = 1`**, which staging deliberately has off.
4. **Spot-check list ready** (below).
5. **Maintenance mode on**, or accept ~10 minutes of inconsistency.

---

## The window

### 1. Deploy the plugin files
Pull the repo into `wp-content/plugins/cashaadi-ui`. Do **not** activate yet.

### 2. Disable every snippet, in one operation
```sql
UPDATE wp_posts SET post_status='draft'
 WHERE post_type='wpcode' AND post_status='publish';
DELETE FROM wp_options WHERE option_name='wpcode_snippets';
```
**Verify** — this is the step that silently fails:
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

### 4. Activate the plugin
`wp plugin activate cashaadi-ui`. Tables install on `init`
(`wp_csm_seen`, `wp_csm_event_log`), and `Seen::backfill()` runs once over the
existing tray and likes.

### 5. Retire the mu-plugin
```
mv wp-content/mu-plugins/cashaadi-discovery.php wp-content/cashaadi-discovery.php.retired
```

### 6. Swap the child theme
Back up the live `functions.php`, then replace it with the 136-line version from
`theme/buddyx-child/functions.php` in the repo.

### 7. Analytics, atomically with its flag
In **Settings → CA Shaadi Tracking**: enter the Google Ads ID and label and set
**enabled = 1**.

> Snippet #12204 and `Modules\Analytics` fire the **same** conversion
> (`AW-1014629759`, "Submit lead form"). With the snippet off and `enabled=0`,
> conversions stop recording **silently**. This step is not optional and does not
> belong in a later phase.

### 8. Purge caches
`wp litespeed-purge all`, and flush object cache.

---

## Immediately after — verify in this order

| Check | Expected |
|---|---|
| `/`, `/members/`, `/register/`, `/membership-pricing/` | 200 |
| `wp eval 'echo function_exists("csm_refill_tray");'` | true (plugin's copy) |
| `cashaadi()` resolves to | `CAShaadi\Core\Engine` |
| `get_week_id()` | same IST week string as before |
| Register page | "Create Your CAShaadi Account", "Continue" button |
| Members directory | renders, opposite-gender only, no admins |
| A tray fills | `csm_refill_tray( <member id> )` returns rows |
| Profile edit form | Day / Month / Year selects with labels |
| **Integration health page** | Sales Dashboard → Integration health, all green |

The health page is the fastest single check: it verifies the ten xProfile field
ids, required functions and classes, the custom tables, cron scheduling and mail
routing in one screen.

## Configuration that does not deploy

- `csm_pm_enforce = 1` (NSFW auto-hide)
- MSG91 credentials, if phone OTP is wanted
- Better Messages → Mobile → Auto Open Full Screen **off**
- **`csm_remail_master` stays at 0.** Leave it a day, watch the queue fill
  correctly, then switch on. Turning it on releases the backlog at 50/day.

## Data, after the site is confirmed healthy

- **Placeholder cleanup** — production has its own copy:
  `UPDATE wp_bp_xprofile_data SET value='' WHERE field_id IN (302,405,418)
   AND LOWER(TRIM(value))='select'` — back the rows up first.
- **Fake users** — re-run the identification on production; do not reuse
  staging's IDs, they will not match.

---

## Spot-check list: the 21 ungated snippets

These have no flag, so their behaviour lives in always-on modules. Staging2 has
run without them for weeks, which is evidence rather than proof — some paths may
never have been exercised there. Worth ten minutes each after the window:

| Check on production | From snippets |
|---|---|
| Members directory shows opposite gender only | 11556 |
| Profile completion meter shows a sane % | 11560, 11844, 11629 |
| Membership checkout page still styled | 11581 |
| Age matches date of birth on a profile | 11611, 11760 |
| Bio edit is a plain textarea | 11619 |
| Gender is read-only after signup | 11621 |
| Email field locked on the account screen | 11625 |
| `/pricing/` redirects to `/membership-pricing/` | 11626 |
| Height input rejects nonsense | 11797 |
| "Created for" field renders | 11812 |
| Member pages are noindex | 11696 |
| Mobile menu opens once, not twice | 11674 |
| Support email footer present | 11691 |
| Profile edit screen looks right on mobile | 11641, 12124 |

## Rollback

**During the window:** reverse the steps — deactivate the plugin, move the
mu-plugin back, restore `functions.php`, re-publish the snippets
(`UPDATE wp_posts SET post_status='publish' WHERE post_type='wpcode' AND ID IN
(…)`) and delete `wpcode_snippets` again so WPCode rebuilds.

**After the window:** restore the backup, accepting the loss of anything
registered since. This is why the window should be quiet and short.
