# Handover — state as of 2026-09-30

Written for a cold start: what is running, what is decided, what is still open,
and the traps that have already cost time.

Plugin **v1.65.5**, on `main`, deployed to production. The cutover from WPCode
snippets finished on 2026-09-07; everything described here runs from this
plugin.

> Sections 1, 3 and 4 were read off production at v1.48.1 and still hold.
> Sections 2, 5 and 6 were rebuilt from the commit history for v1.49–v1.65.5
> and **have not been re-read off the server**. Before relying on a live option
> value, read it with WP-CLI.

---

## 1. Access

```
ssh cashaadi                                    # production (owner's laptop only)
cd ~/domains/cashaadi.in/public_html            # webroot
/opt/alt/php82/usr/bin/php "$(command -v wp)"   # WP-CLI — the default CLI php is 7.4
```

- **Deploy**: `git push origin main`, then `git pull` in
  `wp-content/plugins/cashaadi-ui` on production, then `wp litespeed-purge all`.
  Hostinger auto-deploys **staging2 only**; production is always a manual pull.
- **Cloud Claude sessions have no SSH and no Google login.** They can commit and
  push, but they cannot deploy, run WP-CLI, or touch Google Ads, GTM, GA4 or
  Meta. Work that needs any of these goes to the owner as written steps.
- **Lint without a local php**: `ssh cashaadi 'php -l' < file.php`
- **staging2** lives at `~/domains/cashaadi.in/public_html/staging2`. Its own
  `.htaccess` blocks it from the web, because it was using up the shared LVE
  budget. WP-CLI can still reach it.
- **No `crontab`** on this host. Hostinger manages cron outside the shell.

## 2. What the plugin does now (v1.49 → v1.65.5)

Modules live in `includes/modules/`. Only the behaviour that changed or was added
since v1.48.1 is listed here.

### Sign-up and onboarding
- The sign-up form has a password eye toggle and live "passwords match" feedback.
  **The OTP code email is the only activation email**, because BuddyPress's
  activation-link email is suppressed.
- The `/welcome/` wizard does these things:
  - Shows age from DOB and the feet/inches readout for height.
  - Shows a busy state while a step saves.
  - Lets the member pick the main photo and reorder photos.
  - **Asks for any empty required field**, gender included, even if it was
    meant to come from sign-up.
  - Counts the photo step as done once the member passes it.
- Heights typed as `5.3`, `5.10`, `1.52` or `152.4` are parsed by
  `Profile::height_cm()` and saved as cm.
- There is no PMPro checkout for the free level, and no checkout at all while
  logged out. Both used to create bare WP users with no profile.

### Discover
- **Ranking (v1.68.0, `Discover\Ranker`, algo `points-v1`)** gives each profile
  a points score per viewer. Points are in option `csm_rank_points`:
  - Matching, up to 18: same mother tongue +4, same community +4, same
    religion +3, age fit +3 (man between 1 year younger and 5 years older than
    the woman) or +1 (6–8 years older), same city +2, same diet +2. A blank
    field on either side earns 0.
  - Newness +5, for members who joined in the last 30 days.
  - Few impressions: 0 to +5. Never shown = +5; the most-shown profile in the
    pool = 0.
  - Popularity: 0 to +3, from the like rate as a multiple of the site rate.
  - Popular-to-popular: 0 to +3, for viewer and profile being equally popular.
  - Random: 0 to +5.
  - The active tier comes first, then the **weekly ceilings** (from v1.67.0):
    - impressions: `csm_rank_weekly_ceiling`, where 0 (auto) means 2× the
      gender's weekly average, minimum 10;
    - requests received: `csm_rank_weekly_request_cap` (6).
    A profile over either ceiling goes behind everyone else, but is not
    excluded.
  - Profile edits count from the next tray refill.
- **Impression log `wp_csm_impressions`** (v1.68.0, `Discover\Impressions`)
  has one row per profile served. Each row holds the slot, pool size, algo,
  experiment and arm, tier, score, every score part as JSON, and a JSON
  snapshot of BOTH members at that moment.
  - Outcomes live elsewhere and join on the (viewer, profile) pair:
    `csm_seen.action`, `bp_friends`, `csm_rejections`, `csm_profile_views`,
    Better Messages and blocks.
  - Nothing ranks off this table. It exists for the data-driven algorithm,
    planned in about 6 months.
- A member with **no gender** is never served. They are sent to the wizard.
- **Premium women** get 50 profiles a week (`csm_tray_size` filter) and age and
  height filters (minimum 5-year and 5-inch spans), built on
  `csm_refill_extra_where`. Above 20 a week, Discover opens on a grid view.
- When a free member reaches the end of their set, Next opens the Premium
  prompt.

### Profile
- There are optional **LinkedIn (602)** and **Instagram (603)** fields in Basic
  Details, after Bio. By default everyone can see them, and the member can
  narrow that. Visibility is enforced per viewer in PHP, not by BuddyPress
  alone. `Core\Social` stores handles in canonical form. The fields count
  toward "details left", and existing members get a one-time popup.
- **Refer & earn** is the first row under Manage on the Profile screen.

### Referral (v1.65.0) — `modules/referral`
- Every member has a `?ref=` link. When the referred person completes their
  profile, both members are credited: **500 for a woman's profile, 200 for a
  man's**.
- 1 cash = ₹1. Cash pays up to 100% of any purchase and never expires. There
  are no referral emails.
- The ledger is `wp_csm_cash_ledger`, and balance = sum of rows. A UNIQUE key
  makes every credit idempotent. There is no self-referral, and the signup IP is
  kept.
- At checkout the balance is applied as a negative fee. It is returned on
  cancel, fail or refund, or after 2 hours unpaid.
- Screens: the `/refer/` page, and an admin ledger with top referrers, signup
  IPs and reversal.

### ICAI verification (`modules/ca-verify`)
- A clear model **reject** sets the status, stores a reason code, and emails the
  member. Admin rejections take the same path through `reject()`.
- `manual_review` shows the member "with our team, we will email you".
- Uploads accept **PDF, JPG and PNG**. Word files are refused with a "format"
  reason.
- Transport errors retry at most 3 times, then go to a person. A new upload
  restarts the review.
- The sweep runs hourly, and a real upload also gets its own run a minute later.
  Old verdicts that have a result but no status are re-checked.
- **Verify-badge popup test (v1.66.0, `VerifyNudge`)**: a one-time popup for
  members who have uploaded nothing. Each one is assigned to an arm on their
  first app page load: `csm_vn_arm` is `show` or `control`, set according to
  `csm_verify_nudge_pct` (0 = off), and `csm_vn_at` records when. Further meta
  keys are `csm_vn_seen` and `csm_vn_clicked`.
- **The manual queue still needs a human.** At handover #774, #651 and #653
  were waiting.

### Premium
- Women **don't get the per-view "someone viewed you" email**. This is set by
  option and filter `csm_viewed_email_skip_genders` (default `Female`).
  Instead, women get a **weekly "N people viewed you" digest on Friday
  evening**, with a Sunday catch-up and never on Saturday. It is keyed by date,
  and `csm_view_digest_start` sets when it starts.
- Views by admins, and by sessions an admin has switched into, are not recorded
  or emailed. Test accounts' views now count normally again.
- Declines made through BuddyPress's own UI are logged too, by `on_bp_reject()`.
- The pricing page shows logged-in women the 50-profile and filters offer.

### Emails (ZeptoMail)
- ZeptoMail is the transport and has delivered thousands of emails. The API key
  is stored only in the admin screen.
- The **weekly batch** goes only to members active in the last 30 days, with
  no cap (v1.59.0). `csm_engagement_batch_cap` works like this:
  - `0` turns it off.
  - A negative value turns it on with no cap.
  - A positive value caps it.
- The match-request email names the sender for every recipient. The
  "X accepted your request" email was dropped, because "It's a match" already
  goes to both sides.
- **The bulk/transactional split is still the rule.** `Queue::is_transactional()`
  is the single definition, used for both quiet hours and the one-bulk-per-day
  cap.
- The weekly bulk schedule is: Friday view digest, Saturday expiring profiles,
  Monday new batch.

### Tracking and analytics
- **GA4 `sign_up` fires exactly once per member.** `tracking.js` sends it after
  email verification (the `Tracking\Events` claim), at the same moment as
  Meta's `CompleteRegistration`. No Google Ads conversion fires directly,
  because Ads imports GA4's `sign_up`.
- The Meta pixel uses **manual advanced matching**: hashed em, ph, fn, ln, ge,
  db, ct, country and external_id, read from stored xProfile values.
  `CompleteRegistration` is no longer duplicated.
- `SubmitApplication` fires for female-profile sign-ups, but it is **off**
  (`csm_track_submitapplication = 0`) while Meta delivery is being looked into.
- **Attribution**: an inline script keeps `csm_attr` (first touch, 90 days) and
  `csm_attr_last`. On registration these become `csm_src_first`,
  `csm_src_last` and `csm_channel`.
  - The channels are google_ads, meta_ads, organic_search, social, referral,
    direct and unknown.
  - A paid click in either visit decides the channel.
  - `reclassify()` re-derives the channel for existing members.

### Admin / Sales Dashboard
- DAU, WAU and MAU come from `wp_csm_active_days`, using IST days and excluding
  admins.
- There is a Source column, a channel filter, and a "Sign-ups by channel"
  summary.
- "Last active" and "Registered" times are now correct. They used to be off by
  the UTC-to-IST difference.

## 3. Decisions the owner has made

- Photo is mandatory: **the last photo cannot be deleted**. The member has to
  add another first.
- **Phone verification is not required** for anything. The OTP prompt is off.
- **Phone numbers are currently hidden from everyone**, matches included. The
  owner hasn't decided yet whether matches should see them.
- The Verified CA badge requires `csm_av_status = 'approved'`, nothing less.
  **Never write "ICAI-verified profiles" in ads or copy**, because only some
  members are verified.
- Signup password: 8 characters, no other rules, no strength meter.
- Deleting an account **requires a reason**, which is stored in the event log.
- **404s must not be redirected.** 404-to-301 is set to "No Redirect" with
  logging on.
- The launch announcement copy is the owner's own, word for word. Do not
  reword it.

## 4. Traps — every one of these has already cost time

1. **404-to-301 masked every broken link** as a redirect for a year. Read
   `wp_404_to_301` when you're hunting a bad link.
2. **Elementor stores URLs as JSON with escaped slashes.** Search for
   `stage\/register`, not `stage/register`.
3. **Better Messages' `bpProfileSlug` is `bp-messages`.** It is overridden only
   while notifications are being sent. Overriding it globally took Messages
   down with a redirect loop.
4. **`function_exists()` around a name you haven't verified turns a fatal into
   a wrong answer.** For example, `friends_get_friendship_requests()` doesn't
   exist, so the guard silently skipped the call.
5. **Yoast redirects attachment URLs before `template_redirect`.** To 404 them,
   hook `wp` and clear the queried object.
6. **LiteSpeed caches redirects and pages.** After any change, run
   `wp litespeed-purge all`. For page content, also run `wp elementor flush-css`.
   Because pages are cached, anything that has to capture per-visit data
   (`?ref=`, UTM tags, click IDs) is an **inline script**, not PHP.
7. **The reminder planner cancels by user.** Keep cancellations scoped to a
   type.
8. **Cancelling queued rows never stops a recurring send.** Stop the planner
   option, not the rows.
9. **A long-lived WP-CLI process caches user meta.** When verifying writes made
   by another process, read the table directly.
10. **Admins see unblurred photos by design.** Test photo privacy as an ordinary
    member.
11. **Bulk `DELETE`, and anything that arms live sending, is blocked by the
    permission classifier.** Expect to need interactive approval.
12. **`xprofile_get_field_data()` returns display values**: DOB comes back as
    "31 years old" and the phone as an HTML link. Use `get_value_byid()` for
    stored values.
13. **Meta meta-storage strips backslashes** from JSON saved in user meta, so
    `json_decode()` returns null. Treat old stored verdicts as text.
14. **An empty gender used to mean "Male"** in `get_opposite_gender()`. Guard
    every binary fallback against the empty case.
15. **Verify before adding a hook.** v1.52.2 duplicated a decline logger that
    already existed, because a query used the wrong column names.

## 5. Open items (owner)

- **Google Ads**: Performance Max "Campaign #1" is paused. It spent 90% on
  Display app banners, which were accidental taps. The owner is to build a
  **Search – CA Matrimony** campaign: Search only, India presence, ₹100/day,
  Maximize conversions, goal = GA4 `sign_up`. The only Primary goal is
  "cashaadi.in (web) sign_up".
- **Advertiser identity verification** in Google Ads is due by **27 Oct 2026**,
  or all Google ads stop.
- **GTM** (GTM-5VXXLHX6): the old "GA4 Event - sign_up (registration lead)" tag
  was paused. Confirm that the version was published.
- **Meta**: ads are delivering, and most recent sign-ups come from the Instagram
  paid campaign. Upload the member exclusion list from `~/exports`, then delete
  it from the server.
- Run a ₹0 free-Premium checkout test.
- Decisions still to make:
  - Should matches see phone numbers?
  - Should we build a lookalike seed of about 1,200 rows?
  - Should we show a one-time Refer & earn announcement popup?

## 6. Open items (code) — carried from v1.48.1, not re-checked

- Nothing retries a ZeptoMail transport failure automatically.
- Better Messages shows a stale chat avatar from its own client-side IndexedDB,
  and there is no PHP lever to fix it.
- Direct upload URLs are public to anyone who already knows them. Enumeration is
  closed.
- The Contact Us page is not in the footer menu and has no business name or
  address.
