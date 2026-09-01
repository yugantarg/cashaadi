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
