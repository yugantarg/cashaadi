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

## Photos cutover — DONE (2026-09-02)

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

### Executed and verified

`CASHAADI_PHOTOS_ENABLED` added to `public_html/staging2/wp-config.php`, then all
eight snippets disabled. **Active snippets: 32 → 18.**

Path verification before writing, because production's `wp-config.php` sits in
the same `public_html` listing as `staging/` and `staging2/`:

1. breadcrumb read `public_html › staging2 › wp-config`;
2. the file already contained the other eight `CASHAADI_*_ENABLED` flags;
3. the edit was computed as a string insert and **length-checked** — abort unless
   `after.length === before.length + addition.length` and removing the inserted
   line reproduced the original byte-for-byte;
4. reloaded from disk to confirm: 4210 bytes, 9 flags, DB credentials and salts
   intact, file still opens with `<?php`.

Disable order was dependents-then-base (#11690, #11798, #11771, #11813, #11838,
#11861, #11822) because #11838 and #11861 call functions #11822 defines.

**The written order listed only seven of the eight** — #11770 was in the coverage
table but missing from the order line, so it stayed active through the first
pass. Caught by verifying state afterwards rather than trusting the HTTP 200s,
and disabled separately.

Verified after: staging2 and production both healthy, no fatals; the plugin's
`photos.css`/`photos-gallery.css` now load (they did not before); the photo grid,
add control and privacy toggle each render once; `profile/me` reports
`hasPhoto: true` with the member's real avatar; the Discover queue returns five
profiles with real avatars; blur state preserved.

### Remaining snippets: 18

Next candidates are the profile-edit UX group (#12124, #12119, #11844, #11629,
#11641). Reminder emails #11732/#11733 stay by choice.

---

## Profile-edit — rebuilt, snippets retired (2026-09-02)

**Not retired. Retiring them would have removed the working half.**

The five (#12124 reskin, #12119, #11844 Save & Next + Progress, #11629 Save &
Next + Steps, #11641 mobile fixes) render the presentation that profile editing
actually uses. The PLUGIN's contribution to that screen was
`assets/js/profile-wizard.js` — whose own header reads:

> Phase 3 (AJAX no-reload wizard) **[STAGING DRAFT]** … ⚠ NOT YET LIVE-TESTED

with two `// VERIFY` markers still unresolved. It was enqueued and live anyway,
and had taken the form over (verified: `data-csm-ajax="1"` on the edit form).

### It was the cause of a bug reported three times

"When the backend group changes I am not able to go back."

Line 186 advanced groups with `history.replaceState()` instead of
`pushState()`, so 1 → 2 → 3 **replaced** the single history entry each time and
Back left profile-edit entirely. The identical defect `/welcome/` hit, where the
fix was seeding real history entries.

**v0.58.0 stops enqueuing it.** Not repaired, because the flow no longer wants
it: `/welcome/` owns onboarding, and opening an editor from the `/profile/` hub
means "change one section and come back" — the native form and the "Back to
profile" link already do that. A no-reload chain through seven groups solves a
problem this app no longer has.

Verified after: no `profile-wizard.js`, form free of `data-csm-ajax`, step
indicator and "Save & Next → Step 3 of 8" still rendered by the snippets, back
link to `/profile/`, and **Back from group 7 returns to group 1** instead of
exiting.

The file remains in the repo. To resurrect it: fix the history handling and
clear both `// VERIFY` markers first.

### Snippet count unchanged at 18

These five stay until a tested replacement exists.

### Rebuilt as an app screen, then retired (v0.59.0)

`/profile/edit/?g=<group>` — its own document, one section at a time.

**Not a wizard, deliberately.** The hub sends members here to change one section
and go back, so there is no chain, no "Save & Next", no step counter. Sections
are a switcher, not a sequence — onboarding belongs to `/welcome/`.

Back works by construction: `pushState` per section plus a `popstate` handler.
Verified live — Lifestyle → Family → Community, then Back → Family → Back →
Lifestyle, never leaving `/profile/edit/`. That is the bug reported three times.

Saving goes through `xprofile_set_field_data()` per field, the same call the
native form makes, so visibility and every downstream hook behave identically;
only fields belonging to the requested group can be written. An emptied field is
a deletion via `xprofile_delete_field_data()`. `xprofile_updated_profile` still
fires, so FieldLogic's age-sync survives. Verified: setting Diet = Vegetarian
persisted and read back from the server.

**Retired: #12124, #11844, #11629, #11641. Active snippets 18 → 14.**

### ⚠️ #12119 is NOT a profile-edit snippet — do not disable it

It is untitled in the list and sits beside #12124, which is why it was filed
here. It is actually **"CSM — Photo Moderation (NSFW) Sweep + Queue"**: an
OpenAI-backed cron sweep that masks flagged avatars via
`bp_core_fetch_avatar_url` and fills an admin review queue.

**The plugin's `Photos\Nsfw` module is never registered** — `cashaadi-ui.php`
does not reference it at all, flag or no flag. So #12119 is the only photo
moderation on the site, and switching it off would silently remove it.

Wiring `Nsfw` up is its own task: it shares option keys (`csm_pm_*`) and reads
the CA-Verify OpenAI key, so both running at once would double-sweep.

---

## Settings — app screen at /settings/ (v0.60.0, 2026-09-02)

The hub rows are not new (Settings.php has rendered them since v0.27). What was
wrong is where they lived: the hamburger and the profile hub both said
"Settings" and dropped members into BuddyX chrome.

**It owns the list, not the editors.** Email and password changes stay with
BuddyPress's own settings forms — they handle re-authentication, the
email-change confirmation loop and password strength. Re-implementing that over
REST would mean re-implementing account security for visual consistency, which
is not a trade worth making.

So those rows link out, and `AppShell::render_back()` now covers BuddyPress's
settings sub-screens too, saying **"Back to settings"** rather than "Back to
profile" — verified live on `/settings/notifications/`.

Details worth keeping: rows with no destination render as static readouts rather
than links, so a status value does not look tappable; and "Delete my account"
appears only when `bp_disable_account_deletion()` allows it (it is disabled on
this install, so the row correctly does not render).

### App surface now complete

`/welcome/` · `/discover/` · `/requests/` · `/profile/` · `/profile/edit/` ·
`/settings/` — all own documents. Messages stays with Better Messages by
decision.

Still in BuddyX chrome, reached by linking out: another member's profile view,
the photos screen, and the settings sub-editors — each with a back link into the
app.

---

## NSFW moderation — module wired, #12119 retired (v0.61.0, 2026-09-02)

`Photos\Nsfw` existed in the repo but `cashaadi-ui.php` never referenced it, so
it was dead code and snippet #12119 was doing all photo moderation alone. That
was the last real migration gap.

**Order was the opposite of convenient.** Both bind the same cron event
(`csm_pm_sweep_event`) and the same meta keys — by design, so verdicts and the
schedule carry over. But two live callbacks on one cron tick means two sweeps
and **two OpenAI bills per run**, so they must never overlap. #12119 was disabled
BEFORE the wiring was deployed; an hourly sweep tolerates the gap.

Checked first that this was not a no-op: CA Verify reports "A key is currently
saved", and `Secrets::openai_api_key()` falls back to that same option, so the
module inherits the snippet's key.

Verified after, via the module's own status endpoint
(`?page=csm-photo-mod&csm_pm_status=1`):

```
pending_avatars=0  pending_media=1  enforce=off
key=set  schedule=hourly  next=2026-09-01 18:14:43 UTC
```

* `key=set` — key found through the CA Verify fallback
* `schedule=hourly` + a real next run — the snippet's existing schedule carried
  over rather than being lost or duplicated
* `pending_avatars=0` — prior verdicts survived; the module is not re-sweeping
  everything and re-billing for it
* `enforce=off` — the snippet's safety default preserved; nothing is hidden until
  the `csm_pm_enforce` option is set

**Active snippets 14 → 13.**

---

## Six duplicate snippets retired + a Gravatar leak fixed (v0.62.0, 2026-09-02)

Retired **#11696, #11626, #11691, #11612, #11582, #11617**. All six were running
alongside plugin code that already did the same job (`Site`, `site.css`,
`Photos`), none defined functions or constants, and no other active snippet
referenced them. **Active snippets 13 → 7.**

Behaviour captured before and re-checked after — identical on every point:

| Check | Before | After |
|---|---|---|
| `/pricing/` | 301 → `/membership-pricing/` | same |
| Support footer on home | present | same |
| `site.css` loaded | yes | same |
| `/login/` robots | `noindex, follow` | same |

### The Gravatar leak (found while checking #11617's parity)

#11617 is named "Local Default Avatar (Remove Gravatar)". It had never removed
Gravatar. A member with no photo rendered:

```
//www.gravatar.com/avatar/<md5-of-email>?s=896&r=g&d=mm
```

`d=mm` is Gravatar's own mystery-man, so the local default was never reached.
The snippet and the plugin carried the same four default-avatar filters and got
the same result — both ineffective, which is why retiring the snippet changed
nothing.

**Cause:** `bp_core_fetch_avatar()` only consults the default-avatar filters once
it has decided *not* to use Gravatar, and that decision is a separate filter —
`bp_core_fetch_avatar_no_grav` — which neither layer touched.

**This was a privacy leak, not a cosmetic one:** every avatar render sent an MD5
of the member's email address to a third party, for every visitor, on a
matrimonial site.

Fixed in `Photos::register()`. Verified: members with no photo now serve
`wp-content/uploads/2026/06/abstract-user-flat-4.png`, zero Gravatar requests.

---

## Snippet migration essentially complete — 4 active (v0.63.0, 2026-09-02)

**73 → 4.**

### This round

* **#11556** gender filtering — was already an inert 723-byte comment
  ("RETIRED 2026 — Flag 4 consolidation"), no hooks. Disabled as tidying.
* **#11581** checkout CSS → `assets/css/checkout.css`, loaded only on
  WooCommerce cart/checkout.
* **#11674** BuddyX menu-toggle double-fire fix → `assets/js/menu-toggle-fix.js`,
  loaded only where jQuery is present. The app screens own their own menu and
  are untouched.

Both migrations are verbatim, so behaviour is unchanged. Verified live: the menu
fix serves on the home page, the checkout CSS on `/cart/`.

> `/checkout/` 302s to `/cart/` on an empty cart, so a non-following request to
> `/checkout/` tests the wrong page — use `/cart/`.

⚠️ **Flagged, not silently changed:** the checkout CSS forces the Place Order
button to `#c2185b`, a magenta that is not one of the design tokens. Recolouring
is a design decision, not a migration one.

### The 4 that remain

| Snippet | Status |
|---|---|
| #11732 / #11733 Reminder emails | Kept **by owner's choice** (paused anyway) |
| #11701 Verified CA Badge | Blocked on a wp-config flag |
| #11682 OTP checklist item | Blocked on the same flag |

**#11701 and #11682 are ready to retire.** `Modules\Verification` covers both and
is gated OFF so it cannot double them. Checked: neither the plugin nor any other
active snippet calls #11701's globals (`csm_user_is_verified_ca`,
`csm_user_ca_level`), so disabling cannot fatal.

**Owner action — in `public_html/staging2/wp-config.php`, beside the other flags:**

```php
define( 'CASHAADI_VERIFICATION_ENABLED', true );
```

Verify before saving: the file already contains the other `CASHAADI_*_ENABLED`
flags. If not, it is the wrong wp-config — production's sits in the same
`public_html` listing. Then disable #11701 and #11682.

(I could not do this edit: the Hostinger session had expired and I do not enter
credentials.)
