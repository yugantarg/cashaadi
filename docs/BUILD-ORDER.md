# Build order

Agreed 2026-09-01. Supersedes the phase ordering in ROADMAP.md, which described
the snippet migration only and predates the decision to rebuild the member UI.

Direction: **incremental headless** — WordPress/BuddyPress stays as the data
layer and admin; member screens become our own templates rendering client-side
against REST. See WELCOME-SPEC.md for the pattern.

---

## 0 — Production activation email  ✅ CLOSED (owner, 2026-09-01)

Checked on production: activation works via the emailed link. The broken
templates and the pending backlog are a **staging2** condition, not a live one.
No hotfix needed, and this no longer blocks or precedes anything.

<details><summary>Original entry</summary>

### Verify production activation email

The broken `bp-email` templates (154 accounts unactivated, post type not even
editable) were diagnosed **on staging2, which is a clone of production**. If the
same breakage is live, production has been failing to activate real signups —
paid ad spend landing on accounts that can never log in.

* Sign up on cashaadi.in with a fresh alias; confirm a usable activation arrives.
* If it does not: ship `Signup\ActivationCode`'s template-free `wp_mail()` path
  to production as a hotfix, ahead of everything else here.
* Then decide what to do with the pending backlog — those are real people.

Cannot be checked from the dev side: needs production DB/admin access.

</details>

## 1 — Close out activation on staging2  ⬅ in progress

Retest end to end now that the blank-page bug and the missing resend are fixed:
wrong code → inline error, digits retained → resend → new code → activation →
lands in the app. Makes step 0's hotfix ready to ship.

## 2 — `/welcome/`

Per WELCOME-SPEC.md. Photo as step 1, no page loads, progress derived from the
data, and — reported live 2026-09-01 — the member **stays** until photos and the
mandatory fields are done. Also retires by construction:

* the dead Back button (three separate reports; one root cause, the wizard's
  per-group form POSTs)
* members reaching Discover with no photo and no details

## 3 — Conversion tracking

Ships **with** step 2, not after: the moment onboarding stops doing page loads,
pageview-based conversions silently go to zero. GA4 + Google Ads + Meta events,
fired from server-confirmed milestones, deduped per member.

## 4 — Remaining screens, one at a time

Discover (full scrollable profile card) → Requests (sent / received / viewers)
→ Messages → Profile. Each ships independently on staging2.

## 5 — Production cutover — LAST, and only when everything is complete

Owner, 2026-09-01: production is not touched until the rebuild is finished.

The earlier plan put this second, arguing that a plugin which faithfully
reimplements the snippets is the safest thing to cut over. That argument does
not survive its own premise: "faithful" now means faithfully reproducing a
funnel that lets members reach the app with no photo and no profile. Cutting
over now would also mean cutting over **twice**, and the second time — carrying
the redesign — is the risky one either way.

So: one cutover, at the end, of the finished thing. Snippet-disable first for
function-defining modules, then flags, in a low-traffic window. Re-test premium
checkout on prod. The Google Ads tag moves out of WPCode into the plugin here.

> **Step 0 is deliberately NOT part of this.** A broken activation email on
> production is a live bug, not a migration, and its fix does not need the
> plugin — the template-free code email can ship as a standalone WPCode snippet,
> exactly like the Google Ads tag. Do not let it wait on the cutover.

## 6 — Blocked on credentials (owner adds to `wp-config.php`)

None of these block anything above.

| Needs | For |
|---|---|
| `CASHAADI_MSG91_WIDGET_ID`, `CASHAADI_MSG91_TOKEN_AUTH` | phone OTP (#11618 still on its snippet) |
| `CASHAADI_OPENAI_API_KEY` | `ca-verify` ICAI document check |
| GA4 Measurement Protocol secret; Meta access token + pixel ID | server-side conversions (ad-blocker resistant) |

Also still on snippets by choice: reminder emails #11732/#11733 (paused anyway).

---

## Standing rules

* Never delete xProfile groups or fields — see FIELD-INVENTORY.md. Migration is
  a **code deployment**; production data is the source of truth and nothing is
  ever copied from staging2.
* Function-defining modules: disable the snippet **first**, then add the flag,
  or the site fatals on redeclare.
* Never deactivate BuddyPress.

---

## Owner decisions — 2026-09-01

| # | Decision | Status |
|---|---|---|
| 1 | Photo minimum | **Resolved differently.** Snippet #11813 already sets 896x1024 @ q92 with originals kept to 2400px — quality was never unmanaged. Rather than relax BuddyPress's floor (which IS its storage size, so lowering it costs quality), welcome.js upscales undersized photos so nobody is blocked. |
| 2 | Ask for phone last | ✅ Shipped. `Welcome::ASK_LAST` defers field 277 without hardcoding the rest of the order. |
| 3 | Google Ads inside the plugin, credentials easy to change | ✅ Screen shipped at **Settings → CA Shaadi Tracking**. |
| 4 | Server-side keys via UI, not wp-config | ✅ Same screen. Owner to paste the GA4 Measurement Protocol secret and Meta Conversions API token; each field states where to find it. |
| 5 | "Select": leave stored data, just stop offering it | ✅ Already correct — v0.46.0 hides the option at render and touches no data. Members who saved "Select" keep it. |
| 6 | Production activation | ✅ Confirmed working via link. Step 0 closed. |

### Still to build

The tracking screen **stores** credentials; nothing fires yet. `fireConversions()`
in welcome.js is a single call site waiting for the events slice — that is the
next piece of work, and it is what decisions 3 and 4 unblock.

---

## Before enabling tracking on production — REQUIRED

**Disable WPCode snippet #12112 "CSM - GA4 events (sign_up + purchase)" first.**

It is active and fires a GA4 `sign_up` on `bp_complete_signup`. The plugin fires
`sign_up` too, so with both live every signup is counted twice — and since
Google Ads imports that GA4 event as a conversion, the number it bids against
inflates. They also disagree on the milestone: the snippet fires at
**registration**, the plugin at **activation**, and an unactivated signup is not
a signup.

The snippet's `purchase` event (PMPro checkout) is NOT duplicated by the plugin.
If #12112 is disabled, that purchase tracking must be re-created in the plugin
or it is lost.

### Also note

* Site Kit already loads gtag with the live GA4 property **G-VJW0VMS7KC** and
  Google tag GT-M6QBRJT. That is the Measurement ID to paste into the settings
  screen. The plugin does not load a second gtag when one is present.
* Every plugin GA4 event is scoped with `send_to` (v0.52.2). Without that, gtag
  delivers to *every* configured property, so staging events reach live
  analytics whatever IDs the plugin holds.
* "Enable tracking" is OFF on staging2 and should stay off.

---

## Status — 2026-09-01 (end of session)

| Screen | State |
|---|---|
| `/welcome/` onboarding | ✅ own document, 9 derived steps, real Back, conversions wired |
| `/discover/` | ✅ own document, full scrollable profile card, optimistic like/pass |
| `/requests/` | ✅ own document, received / sent / viewers, premium gate server-side |
| `/profile/` | ✅ own document, completion-first hub |
| Messages | ⏸ **left on Better Messages by decision** — see below |

### Messages: assessed, not rebuilt

Desktop is fine (renders inline, header visible, no scroll lock). **On mobile it
takes the entire viewport** — `position: fixed`, `z-index: 100000`, full height,
plus `body { overflow: hidden }` — so it covers the header AND the bottom nav,
and its own header offers no way back. The only exit is the browser back button.

That is now the one screen that traps a member, because the other three all keep
a persistent nav.

**It is a setting, not code.** Better Messages → Settings → **Mobile**:
* *Full Screen Mode* — "Open the messages page in full screen on mobile devices"
* *Auto Open Full Screen* — "Automatically enter full screen when opening the
  messages page"

Recommended: turn **Auto Open Full Screen OFF**, leave Full Screen Mode ON. The
list then keeps the app nav; fullscreen stays available for the conversation
view, where it helps. Not changed — it affects every member, so it is the
owner's call.

Also seen: Better Messages reports a **Cron Issue**, the dashboard reports 15
past-due Action Scheduler actions, and WebSocket is not enabled. Without it,
delivery falls back to polling. Worth investigating separately.

### Remaining seams (accepted transitional state)

These still render in BuddyX chrome and are linked to from the new screens:
member profile view, profile edit, change-avatar, settings, messages.

Both navs now take their destinations from `Core\AppPage::nav()`, so the same
tab goes to the same place from anywhere, and "Back to profile" returns to the
`/profile/` hub rather than the BuddyPress member page.

---

## Snippet retirement — batch 1 done (2026-09-01)

**Disabled on staging2, verified live.** Active snippets: 32 → 26.

| Snippet | Replaced by |
|---|---|
| #11624 Allow Partial Profile Save | `FieldLogic::partial_save` |
| #11621 Lock Gender After Signup | `FieldLogic::gender_lock` + `genderLocked` |
| #11619 Bio Plain Textarea | `FieldLogic::bio_plain` (now all fields) |
| #11611 Sync Age from DOB | `FieldLogic::sync_age` |
| #11797 Height Input Guard | `profile-forms.js` `heightGuard` |
| #11625 Lock Account Email | `profile-forms.js` `emailLock` |

Checked before disabling, not after:

* every hook each snippet registered has a `FieldLogic` counterpart;
* the front-end halves (height guard, gender lock, email lock) are all served by
  `FieldLogic::enqueue`, which localises `CASHAADI_FORMS` on the edit and
  settings screens;
* no other **active** snippet references the helpers these define
  (`csm_sync_age_for_user`, `csm_get_raw_dob`, `csm_gender_is_locked`,
  `csm_hg_assets`, `CSM_*_FIELD_ID`) — disabling a snippet other code calls is
  a fatal, so this was the load-bearing check;
* the plugin references none of them either.

Verified after: edit screen serves `{heightGuard:1, genderLocked:1}`, settings
serves `{emailLock:1}`, no rich-text editor, no fatals, all routes 200.

### ⚠️ Before repeating this on production

**#11611 carries a one-time age backfill** (`admin_init`, guarded by the
`csm_age_dob_backfill_done` option) that gives a DOB to members who have an Age
but no DOB. It is spent on staging2. **Confirm that option is set on production
before disabling #11611 there** — otherwise those members never get a DOB, and
`FieldLogic` does not port the backfill (deliberately: it is a migration, not
runtime code).

### Still to retire

* Photos (#11822, #11838, #11813, #11771, #11770, #11798, #11861, #11690) —
  load-bearing while `CASHAADI_PHOTOS_ENABLED` is off. Flag first, then disable.
* Profile-edit UX (#12124, #12119, #11844, #11629, #11641).
* Reminder emails #11732/#11733 — paused by choice.

---

## Photos cutover — analysed, NOT executed (needs a wp-config edit)

Eight snippets, all still active because `CASHAADI_PHOTOS_ENABLED` is off.

### Plugin coverage

| Snippet | Plugin equivalent |
|---|---|
| #11822 Member Photos (grid, upload/delete/main) | `Photos\Gallery` |
| #11770 Private Photo (blur) | `Photos\Privacy` |
| #11771 Photo Lightbox + Privacy Notice | `Photos\Gallery` lightbox |
| #11798 Photo Request (Ask & Approve) | `Photos\PhotoRequest` |
| #11813 Photo Resolution (HD Avatars) | `Media\MediaQuality` (already mirrored) |
| #11838 Photos on Avatar Screen | `Photos\PhotoOnboarding` |
| #11690 Photo Step Next Button | `PhotoOnboarding::render_next_button` |
| #11861 Import Legacy Avatar | `Photos\LegacyImport` |

### Order within the batch is NOT free

Two hard dependencies, both on #11822:

* **#11838** calls `csm_ph_uploader_html()`
* **#11861** calls `csm_ph_get()`, `csm_ph_save()`, `CSM_PH_MAX`

Disabling #11822 while either is active is a **fatal**. Disable #11838 and
#11861 first, #11822 last.

### Flag first, then disable — and why that reverses the usual rule

The standing rule is snippet-first, because two copies of a *function* is a
redeclare fatal. It does not apply here: the plugin's photo modules are
class-based and define no global functions, so nothing can collide.

They do register the same AJAX actions (`csm_ph_upload`, `csm_ph_delete`,
`csm_ph_main`) and the same shortcodes. That is not double-processing either:
those handlers end in `wp_send_json_*()`, which calls `wp_die()`, so the first
callback to run terminates the request and the second never executes. The plugin
registers at plugin-load, before WPCode's snippets, so the plugin's handler wins.

What flag-first DOES cause is a brief window of **duplicated UI** — two photo
grids, two privacy toggles — which is cosmetic and ends as soon as the snippets
are switched off. Snippet-first would instead leave members with **no photo
functionality at all** until the flag lands. Cosmetic beats broken.

### Why it is not done yet

`Config::photos_enabled()` is checked inside each module's `register()`, which
runs at plugin load — so the constant must exist **before** the plugin file
executes. wp-config or an mu-plugin; a WPCode snippet is too late.

That edit sits in a Hostinger File Manager shared with **production**, and a
wrong-folder edit there is a production outage. The staging2 file is
`public_html/staging2/wp-config.php` — a previous flag edit went into
`public_html/staging/` by mistake, and the flags silently never registered.

**Line to add** (beside the existing eight flags):

```php
define( 'CASHAADI_PHOTOS_ENABLED', true );
```

**Verify before saving:** the file already contains the other `CASHAADI_*_ENABLED`
flags. If it does not, it is the wrong wp-config — stop.

Then disable, in order: #11690, #11798, #11771, #11813, #11838, #11861, #11822.
