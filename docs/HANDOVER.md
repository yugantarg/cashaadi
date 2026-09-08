# Handover — state as of 2026-09-08

Written for a cold start: what is running, what is decided, what is still open,
and the traps that have already cost time. Facts here were read off production,
not remembered.

Plugin **v1.48.1**. Production has **557 users**. Cutover completed 2026-09-07.

---

## 1. Access

```
ssh cashaadi                                    # production
cd ~/domains/cashaadi.in/public_html            # webroot
/opt/alt/php82/usr/bin/php "$(command -v wp)"   # WP-CLI — the default CLI php is 7.4
```

- **Deploy**: `git push origin main`, then `git pull` in
  `wp-content/plugins/cashaadi-ui` on production. Hostinger auto-deploys
  **staging2 only**; production is always a manual pull.
- **Lint without a local php**: `ssh cashaadi 'php -l' < file.php`
- **staging2** is at `~/domains/cashaadi.in/public_html/staging2`, and is
  **web-blocked** (403 via its own `.htaccess`; original saved as
  `.htaccess.pre-offline-20260907`). It was consuming the shared per-account
  LVE budget and is the prime suspect for the 2026-09-07 503s. WP-CLI still
  reaches it.
- **No `crontab`** on this host — Hostinger manages cron outside the shell. WP
  cron and the availability probe both run, but cannot be inspected or changed
  from SSH.

## 2. Email system

**ZeptoMail** is the transport (`pre_wp_mail`), Brevo (`mailin`) is deactivated.
From address `support@cashaadi.in`. The API key lives in the admin screen only —
**never in the repo, never typed by Claude.**

Current settings, all live:

| | |
|---|---|
| `csm_remail_master` | `1` |
| `csm_remail_dryrun` | `0` |
| hourly cap | 40 |
| daily cap | 500 |
| weekly cap | 0 (off — built, unused, owner did not want it) |
| **bulk per member** | **1 / day** |
| quiet hours | 00:00–07:00, **bulk only** |
| `csm_engagement_batch_cap` | **0 = weekly batch OFF** |
| completion reminders | OFF via `csm_remail_plan` |

**The bulk/transactional split is the important rule.** Owner: *"I'm fine with 5
different it's a match messages. I don't want a reactivation email plus a v2
launch email plus a new batch email on the same day."* `Queue::is_transactional()`
is the single definition, used by both quiet hours and the per-member cap.
Transactional = `csm-liked-*`, `csm-viewed-*`, `csm-match*`, `csm-ca-verified`.

### Launch announcement (`csm-v2launch`)

```
sent 244 / 512   pending 268
open 28.3%  click 9%  unsub 1 (0.4%)  signed-in 16  failed 0
```

Copy is the owner's, verbatim, in `Modules\Emails\Announcement`. Do not reword it.
Audience is "has a role and `user_status = 0`", which excludes unactivated
accounts by construction.

### Queued and waiting

| type | status | n |
|---|---|---|
| `csm-v2launch` | pending | 268 |
| `csm-activation-reminder-1` | held | 55 |
| `csm-activation-reminder-2` | waiting | 58 |
| `csm-nudge-2026-19` | held | 8 |
| `csm-completion-reminder-1/2` | held/waiting | 1/1 |

Activation reminders are deliberately held: send them on a day the announcement
is not running.

### Verified working end to end

Open pixel, click redirect (refuses off-site targets), **unsubscribe**
(GET asks, POST writes — mail scanners prefetch, so a GET must never write), and
**magic-link sign-in** (`MagicLink`, 14 days, single use, never for admins,
sensitive screens still demand a real login).

## 3. Decisions the owner has made

- Photo is mandatory: **the last photo cannot be deleted** — add another first.
- **Phone verification is no longer required** for anything. Existing
  `csm_phone_verified` flags are kept; the OTP prompt is off.
- Verified CA badge requires `csm_av_status = 'approved'`, nothing less.
- Signup password: **8 characters, no other rules**, no generated password, no
  strength meter, `autocomplete="new-password"` so the browser offers its own.
- Delete account **requires a reason** (free text, stored in the event log).
- **404s must not be redirected** — 404-to-301 is set to "No Redirect" with
  logging on, because a redirect makes broken links undetectable.

## 4. Traps — every one of these has already cost time

1. **404-to-301 masked every broken link** as a redirect to the home page for a
   year. It *was* logging them the whole time; nobody read the log. Now set to
   No Redirect. **Read `wp_404_to_301` when hunting a bad link.**
2. **Elementor stores URLs as JSON with escaped slashes.** Searching for
   `stage/register` finds nothing; you must search `stage\/register`. Two
   database searches reported a page clean when it was not.
3. **Better Messages' `bpProfileSlug` is `bp-messages`**, but BuddyPress
   registers `messages`. Every link BM emails 404s. Fixed via
   `bp_better_messages_page` — **but only while sending notifications.**
   Overriding it globally caused a redirect loop that took the Messages page
   down (v1.35.0 → fixed in v1.44.1).
4. **`function_exists()` around a name you have not verified turns a fatal into
   a wrong answer.** `friends_get_friendship_requests()` does not exist; the
   guard silently skipped it and **95 members saw no received requests for
   months.** The real name is `friends_get_friendship_request_user_ids()`.
5. **Yoast redirects attachment URLs before `template_redirect`.** To 404 them
   you must hook `wp` *and* clear the queried object.
6. **LiteSpeed caches redirects.** After any routing change:
   `wp litespeed-purge all`, and `wp elementor flush-css` for page content.
7. **The reminder planner cancels by user, not by type.** `replan()` used to
   cancel *everything* queued for a member with "nothing outstanding" — it
   silently killed **278 of 519 announcements**, every member whose profile was
   complete. Scoped in v1.29.0.
8. **Cancelling queued rows never stops a recurring send.** The weekly batch is
   planned *per run* across Monday and Tuesday: cancelling Monday's 150 left
   Tuesday's run to queue 146 *different* members and mail them. Stop the
   planner (`csm_engagement_batch_cap = 0`), not the rows. Same lesson as the
   completion reminders.
9. **A long-lived WP-CLI process caches user meta.** Reading a value, letting an
   HTTP request write it, then reading again returns the stale miss — this
   produced a false "unsubscribe is broken". Read from the table directly when
   verifying cross-process writes.
10. **Admins see unblurred photos by design.** Testing photo privacy as an admin
    looks like a leak and is not. Test as an ordinary member.
11. **Bulk `DELETE` and anything that arms live sending is blocked by the
    permission classifier.** Expect to need interactive approval.

## 5. Open items

- **Two ZeptoMail timeouts** were reset to `pending`. Nothing retries a
  transport failure automatically — a network blip silently loses an email.
  Worth building.
- **Stale avatar in Better Messages chat.** Server side is correct (files
  unique, URLs current, blur correct). It is BM's own client-side IndexedDB
  (`bmdb`), and there is **no PHP lever** to clear it. Unconfirmed whether
  clearing site data fixes it.
- **Vedika (393) has never had premium** — no PMPro row on either install. The
  owner believed otherwise; the premium test aliases are Himani (383), Yosha
  (389) and +google (610).
- **No "Shaista Tasleem" exists** on the site under any spelling — a password
  reset was requested and could not be sent.
- **Contact Us** page exists (`/contact-us/`) but is not in the footer menu, and
  carries no business name or address. An Indian site taking payments usually
  needs both.
- Direct upload URLs are still public if you already know them. Enumeration is
  closed (`/wp-json/wp/v2/media` 404s for non-staff, attachment pages 404).
  Closing direct access means serving uploads through PHP — a much bigger job.
- Post-cutover leftovers: `wp-config.php.pre-cutover`, `functions.php.pre-cutover`,
  `cashaadi-discovery.php.retired`.

## 6. Backups made recently

```
csm_purge_*_20260908                       23 spam accounts, 17 tables
csm_purge_*_20260905                       staging2's earlier purge
~/home-11442.pre-stagefix.html             home page content
~/home-11442.elementor.pre-stagefix.json   home page Elementor JSON
~/1122{3,5,7}.elementor.pre-contactfix.json  the three legal pages
~/domains/.../staging2/.htaccess.pre-offline-20260907
```

The 404 log was trimmed to 30 days (51,531 rows removed, 706 kept).
