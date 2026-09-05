# Production cutover runbook

**Nothing in here has been executed.** This is the plan for moving
`cashaadi.in` from 66 WPCode snippets to the plugin, in an order where every step
is individually reversible.

Written 2026-09-05 against plugin **v1.4.1** on staging2.

## The two sides today

| | production | staging2 |
|---|---|---|
| `cashaadi-ui` plugin | **not installed** | v1.4.1 |
| Active WPCode snippets | **66** | 0 |
| `cashaadi()` mu-plugin | present | removed |
| Child theme `functions.php` | 653 lines | 136 lines |
| `CASHAADI_*` flags | none | 11, all true |
| LiteSpeed Cache | installed 2026-09-05 | not installed |

Production is the old world entire. Staging2 is months ahead. That gap is the
risk this runbook exists to manage.

---

## The three ways this can break

Each has already happened once, on staging, which is why they are listed first.

**1. Redeclare fatal.** A module that defines global functions cannot run
alongside its snippet. `Discover`, `Block`, `Matches`, `Otp` and `Emails` all
do. **Disable the snippet FIRST, then set the flag** — never the reverse, and
never both in one page load.

**2. WPCode's cache.** Setting `post_status = draft` does **not** disable a
snippet. WPCode keeps the code in the `wpcode_snippets` option and keeps running
it from there. Delete that option after changing statuses, then confirm with
`function_exists()` on one of the snippet's functions — not by looking at the
admin screen.

**3. Unguarded class declarations.** The child theme declares
`My_Custom_Datebox_Field` with no `class_exists()` guard. The plugin's `Datebox`
stands down while `cashaadi_register_custom_datebox()` exists, so the plugin can
be installed safely first — but the theme file and that takeover are one step,
not two.

---

## Phase 0 — before touching anything

1. **Full backup**: files and database. hPanel → Backups. Verify it completed.
2. **Record the baseline** so "did we break it" has an answer:
   - `wp option get active_plugins`
   - list of the 66 snippet IDs (in this doc's appendix)
   - homepage, `/members/`, `/register/`, `/membership-pricing/` HTTP status
   - member count, tray row count
3. **Pick a low-traffic window.** Steps 2–4 each cause a brief inconsistent state.
4. **Confirm the three unknowns are covered** (see Open Questions).

## Phase 1 — install the plugin, inert

Install `cashaadi-ui` and activate it with **no `CASHAADI_*` flags set**.

Every gated module checks its flag and returns, so this changes nothing. What
*does* take effect immediately and is not flag-gated:

- `Core\Health` — an admin page, read-only
- `Core\globals.php` — `function_exists`-guarded, so the mu-plugin still wins
- `ThemeCompat` — **takes over 13 child-theme hooks on `after_setup_theme`**
- `Coach` — the tour and action explainers appear for members
- `Premium::copy_owner_on_purchase` — receipts start copying to `admin_email`

> ⚠️ ThemeCompat and Coach are **not** flag-gated. Phase 1 is therefore not a
> no-op. Verify the register page still reads "Create Your CAShaadi Account" and
> that `/members/` renders before continuing.

**Check:** every page 200. **Rollback:** deactivate the plugin.

## Phase 2 — snippets off, flags on, one module at a time

For each module below, in this order: disable its snippets → delete the
`wpcode_snippets` option → confirm the function is gone → add the flag → verify.

| Order | Flag | Snippets to disable |
|---|---|---|
| 1 | `CASHAADI_PHOTOS_ENABLED` | 11617, 11770, 11771, 11798, 11813, 11822, 11838, 11861, 12119 |
| 2 | `CASHAADI_VERIFICATION_ENABLED` | 11618, 11682 |
| 3 | `CASHAADI_CA_VERIFY_ENABLED` | 11701, 11815, 12113 |
| 4 | `CASHAADI_PREMIUM_ENABLED` | 11579, 11620, 11795, 11796, 11807, 11811 |
| 5 | `CASHAADI_BLOCK_ENABLED` | 11810 |
| 6 | `CASHAADI_MATCHES_ENABLED` | 11637, 11694 |
| 7 | `CASHAADI_DISCOVER_ENABLED` | 11599, 11600, 11601, 11602, 11605, 11630, 11675, 11680, 11681 |
| 8 | `CASHAADI_SIGNUP_ENABLED` | 11583, 11842 |
| 9 | `CASHAADI_ADMIN_ENABLED` | 11688 |
| 10 | `CASHAADI_EMAILS_ENABLED` | 11732, 11733 |
| 11 | `CASHAADI_ANALYTICS_ENABLED` | 11697, 12073, 12084, 12091, 12112 |

Ungated, so their snippets can be disabled at any point after Phase 1: 11556,
11560, 11581, 11582, 11611, 11612, 11619, 11621, 11624, 11625, 11626, 11629,
11641, 11674, 11691, 11696, 11760, 11797, 11812, 11844, 12124.

**Discover (7) is the one to slow down on** — it owns the tray, the weekly reset
and like/pass. Verify a tray fills before moving on.

## Phase 3 — retire the mu-plugin

Only once `CASHAADI_DISCOVER_ENABLED` is on and Discover works.

```
mv wp-content/mu-plugins/cashaadi-discovery.php wp-content/cashaadi-discovery.php.retired
```

The plugin's guarded `cashaadi()` takes over. **Check:** `cashaadi()` resolves to
`CAShaadi\Core\Engine`, `get_week_id()` still returns the same IST week string,
a tray still fills. **Rollback:** move the file back.

## Phase 4 — the child theme

Replace `functions.php` with the 136-line version (mirrored at
`theme/buddyx-child/functions.php`). This is the atomic step: deleting
`cashaadi_register_custom_datebox()` is what activates the plugin's `Datebox`,
and removing the anonymous closure is what stops the gender filter running twice.

**Back up the live file first.** **Check:** register page copy, members directory
renders, a profile-edit form still shows Day/Month/Year.

## Phase 5 — configuration that does not deploy

None of this travels with git.

- Tracking credentials, and **turn conversions ON** (deliberately off on staging)
- MSG91 credentials, if phone OTP is wanted
- `csm_pm_enforce = 1` (NSFW auto-hide)
- Better Messages → Mobile → Auto Open Full Screen **off**
- `csm_remail_master` — **leave at 0** until you have watched the queue fill
  correctly for a day. Turning it on releases the backlog at 50/day.

## Phase 6 — data

- **Placeholder cleanup**: `UPDATE wp_bp_xprofile_data SET value='' WHERE
  field_id IN (302,405,418) AND LOWER(TRIM(value))='select'` — production has its
  own copy of this. Back up the rows first.
- **Fake users**: production has its own equivalents of the 15 disposable-domain
  accounts and `abc@email.com`. Re-run the identification there; do not assume the
  same IDs.
- **Purge LiteSpeed cache** after everything.

---

## Open questions to settle before Phase 2

1. **#12204 Google Ads lead conversion** — the Analytics module covers GA4 and
   Meta Pixel. Whether it covers this specific Google Ads lead conversion is
   **unverified**. Check before disabling it, or conversions stop being recorded.
2. **21 ungated snippets** listed above have no flag, which means their behaviour
   lives in always-on modules. Each should be spot-checked rather than assumed —
   staging2 running without them is good evidence, not proof, since some paths
   (checkout styling, SEO noindex) may never have been exercised there.
3. **`admin_email`** on production is `admin@cashaadi.in`. Confirm someone reads
   it before purchase receipts start copying there in Phase 1.

## Rollback

Every phase is individually reversible: deactivate the plugin (1), remove a flag
and re-publish the snippets (2), move the mu-plugin back (3), restore
`functions.php` from its backup (4). There is no point at which recovery requires
the database backup — but take it anyway.
