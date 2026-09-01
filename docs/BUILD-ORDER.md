# Build order

Agreed 2026-09-01. Supersedes the phase ordering in ROADMAP.md, which described
the snippet migration only and predates the decision to rebuild the member UI.

Direction: **incremental headless** — WordPress/BuddyPress stays as the data
layer and admin; member screens become our own templates rendering client-side
against REST. See WELCOME-SPEC.md for the pattern.

---

## 0 — Verify production activation email  🔴 URGENT, owner

The broken `bp-email` templates (154 accounts unactivated, post type not even
editable) were diagnosed **on staging2, which is a clone of production**. If the
same breakage is live, production has been failing to activate real signups —
paid ad spend landing on accounts that can never log in.

* Sign up on cashaadi.in with a fresh alias; confirm a usable activation arrives.
* If it does not: ship `Signup\ActivationCode`'s template-free `wp_mail()` path
  to production as a hotfix, ahead of everything else here.
* Then decide what to do with the pending backlog — those are real people.

Cannot be checked from the dev side: needs production DB/admin access.

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
