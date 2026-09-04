# Configuration that does NOT travel with git

Everything here lives on the server — in `wp-config.php` or in the database —
so a `git push` does not carry it. A site rebuilt from this repo alone will run
with every module OFF and no credentials. This file is the checklist.

Captured from **staging2** on 2026-09-04.

> **Secrets are never recorded here.** Where a value is a credential this file
> names the setting and where to enter it, and nothing else.

---

## 1. wp-config.php flags

Module gates. Each is `define( '<NAME>', true );`. All ten are ON on staging2.

| Constant | staging2 | Notes |
|---|---|---|
| `CASHAADI_DISCOVER_ENABLED` | true | Defines global engine functions — see the cutover rule below |
| `CASHAADI_MATCHES_ENABLED` | true | Same |
| `CASHAADI_BLOCK_ENABLED` | true | Same |
| `CASHAADI_SIGNUP_ENABLED` | true | |
| `CASHAADI_ANALYTICS_ENABLED` | true | Must not run alongside the WPCode analytics snippets |
| `CASHAADI_ADMIN_ENABLED` | true | |
| `CASHAADI_PREMIUM_ENABLED` | true | |
| `CASHAADI_CA_VERIFY_ENABLED` | true | |
| `CASHAADI_PHOTOS_ENABLED` | true | |
| `CASHAADI_VERIFICATION_ENABLED` | true | |
| `CASHAADI_EMAILS_ENABLED` | true | Cut over 2026-09-04; #11732/#11733 disabled in the same change |

**Cutover rule (learned the hard way).** For any module that defines global
functions — discover, block, matches, otp — disable the WPCode snippet FIRST,
then add the flag. Both live at once is a PHP redeclare fatal, which takes the
whole site down.

Credentials may also be defined as constants, and a constant always beats the
admin option:

| Constant | Purpose | Preferred home |
|---|---|---|
| `MSG91_AUTHKEY` / `CASHAADI_MSG91_*` | Phone OTP | Settings → CA Shaadi Phone OTP |

⚠️ **staging2 has a live MSG91 auth key in plaintext at `wp-config.php` line 2.**
It has been readable to anyone with file access for as long as it has been
there. Rotate it, and prefer the admin page over a constant from here on.

## 2. Cron

`DISABLE_WP_CRON` differs per environment **on purpose**:

| | setting | why |
|---|---|---|
| production | `true` | a real hPanel cron job calls wp-cron.php; healthy |
| staging2 | `false` (2026-09-04) | there is no hPanel cron for staging2, and the box has no `crontab` binary or spool — Hostinger cron is hPanel-only. So WordPress runs cron on page loads instead |

On staging that means cron advances **only when someone loads a page**. Fine for
testing; the daily tick fires on the first visit after it comes due.

Verified 2026-09-04: the ~47-event backlog (overdue since 2026-09-02) cleared,
`csm_remail_cron` rescheduled hourly, `csm_engagement_daily` daily. `replan(1000)`
scans all 545 members in 1.7s and `process()` returns `mode=paused` — both well
inside a request.

### ⚠️ staging mail sink — `wp-content/mu-plugins/00-staging-mail-sink.php`

**Not in git. Must never reach production.**

Enabling cron on staging immediately made Better Messages try to email real
members about unread threads — it runs its own notification cron every 15
minutes and is NOT governed by `csm_remail_master`. The sink defines `wp_mail()`
in an mu-plugin so it loads before Brevo's pluggable definition (Brevo bypasses
`pre_wp_mail` entirely), logs every message to
`wp-content/staging-mail-sink.log`, and returns true so callers behave normally.

It catches everything, including the activation email, which always sends by
design. Delete the file to restore real sending on staging.

## 3. Database-side settings

Set in the WordPress admin; not in git.

| Option | Where | staging2 | Production needs |
|---|---|---|---|
| `csm_tracking` | Settings → CA Shaadi Tracking | Google Ads id + label set; **`enabled: 0`** | Credentials, and `enabled` ON — deliberately off on staging so tests do not fire conversions |
| `csm_otp` | Settings → CA Shaadi Phone OTP | *(empty)* | MSG91 widget id / token / authkey, if OTP is wanted |
| `csm_pm_enforce` | Photo moderation | `1` (NSFW auto-hide ON) | Match deliberately |
| `csm_signup_fields_v2` | *(automatic)* | `1` | Guard for `Signup::ensure_signup_fields()`; set by the plugin, do not edit |
| `csm_seen_backfilled` | *(automatic)* | timestamp | One-shot guard for the `wp_csm_seen` backfill; do not edit |

Also configured in a plugin UI, not in git:

- **Better Messages → Mobile → Auto Open Full Screen: OFF.**

## 4. Custom tables

Installed by `Core\Migrator` (per-handle ledger in `cashaadi_schemas_installed`)
except where noted.

| Table | Owner |
|---|---|
| `wp_csm_tray`, `wp_csm_likes` | created outside the Migrator; helpers now in `Core\Engine` (mu-plugin retired 2026-09-04) |
| `wp_csm_seen` | `Modules\Discover\Seen` |
| `wp_csm_profile_views`, `wp_csm_rejections` | `Modules\Premium\Premium` |
| `wp_csm_blocks` | `Modules\Block` |
| `wp_csm_email_queue` | `Modules\Emails\Queue` (cut over from #11732, 2026-09-04) |
| `wp_csm_intent` | `Modules\Premium\Premium` |
| `wp_csm_photo_requests` | `Modules\Photos` |

`wp_csm_event_log` is referenced by `cashaadi()->log_event()` but **does not
exist**, so every event call is a silent no-op. `wp_csm_seen` replaced it for
the one thing that mattered (impressions). Delete the dead logging or create the
table — do not leave code that pretends to record.
