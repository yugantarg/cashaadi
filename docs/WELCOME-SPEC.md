# `/welcome/` — onboarding as one route

Spec, 2026-09-01. First real screen of the **incremental-headless** direction:
our own page template, rendering client-side, talking to REST. No BuddyPress
templates, no BuddyX, no page-per-screen navigation.

---

## 1. Why a state machine, not a wizard

The current wizard traverses xProfile **groups** by POSTing BuddyPress's own
per-group forms. Everything painful about onboarding follows from that:

* a photo isn't an xProfile field, so it can't be a step — hence the redirect
  gate, and hence the reload the owner rejected
* "back" means re-POSTing a previous group, which is why back-navigation broke
  at the CA/CA-Inter step
* every step is a page load, so analytics sees seven pageviews and no funnel

`/welcome/` replaces group-traversal with an explicit **ordered list of steps**
held client-side. Steps are independent of xProfile grouping: one or two
questions per screen, in whatever order converts best, drawn from any group.

## 2. Steps

Compulsory set only — everything else lives in profile edit, per the owner.

| # | Step | Writes to | Required |
|---|---|---|---|
| 1 | **Photo** (+ blur choice, default off) | `members/{id}/avatar`, `csm_photo_private` | ✅ min 1 |
| 2 | Name | xProfile Name | ✅ |
| 3 | Date of birth | field 586 | ✅ |
| 4 | Gender | field 299 | ✅ |
| 5 | City | xProfile City | ✅ |
| 6 | Phone number | field 277 | ✅ (OTP optional, never blocking) |
| 7 | Qualification | field 571 | ✅ |
| 8 | Occupation status | xProfile Occupation Status | ✅ |
| 9 | Mother tongue | xProfile Language | ✅ |

Exit to `/discover/` on completion. **Photo is step 1 and cannot be skipped** —
the requirement that produced the gate, now met without navigation.

> Source of truth for required-ness is the xProfile field's own `is_required`,
> read at render time — never a hardcoded list here, or this drifts the first
> time someone edits a field in wp-admin.

## 3. Mechanics

* **One route.** A page template registered by the plugin; no BP template.
* **Client-side steps.** Only the active step is in the DOM. No navigation, so
  browser back is trapped and mapped to "previous step" via `history.pushState`
  — this is what fixes the back-navigation bug properly.
* **Save per step**, on advance, via `POST csm/v1/welcome/step`. A step never
  blocks on the network: optimistic advance, with a retry banner on failure.
* **Resume after refresh.** Progress is derived **server-side** from the data
  itself — the first required field still empty is the current step. Nothing is
  stored about "where they were", so it cannot desynchronise from reality.
* **Photo upload** to `buddypress/v1/members/{id}/avatar` (confirmed present in
  the REST index), with client-side downscale before upload.

### Endpoints

| Method | Route | Purpose |
|---|---|---|
| GET | `csm/v1/welcome/state` | steps, labels, options, current position |
| POST | `csm/v1/welcome/step` | save one step's answers |
| POST | `csm/v1/welcome/complete` | mark done, fire conversion, return redirect |

All `permission_callback` = logged-in **and own user only**. Field writes go
through `xprofile_set_field_data()`, never raw SQL — the data-safety rule in
FIELD-INVENTORY.md applies unchanged.

---

## 4. Conversion tracking

**The problem a SPA creates:** with no page loads, pageview-based conversions
never fire. Every event below must be fired **explicitly**.

### Events

| Milestone | GA4 | Google Ads | Meta |
|---|---|---|---|
| Account activated | `sign_up` | **conversion** (`GADS_LEAD_LABEL`) | `CompleteRegistration` |
| Entered `/welcome/` | `onboarding_start` | — | — |
| Photo added | `onboarding_photo` | — | — |
| Onboarding complete | `onboarding_complete` | secondary conversion | `Lead` |

The **Google Ads "Completed signup" conversion fires on activation**, not on
form submit — an unactivated signup is not a signup, and counting it would
inflate the number the bidding algorithm optimises against.

`Config::GADS_CONVERSION_ID` / `GADS_LEAD_LABEL` already hold the values.

### Firing correctly

Three failure modes to design out, each of which silently corrupts the numbers:

1. **Double-counting on refresh.** Every conversion carries an `event_id`, and
   the server sets a one-time user-meta flag (`csm_fired_{event}`) — fired once
   per member, ever. A refresh or a back-button cannot re-fire it.
2. **Firing before the server agrees.** Events are emitted from the *response*
   of `complete` / activation, never optimistically on click. A conversion that
   fires for a failed activation is worse than a missing one.
3. **Virtual pageviews.** Each step sends a GA4 `page_view` with a synthetic
   path (`/welcome/step/photo`) so funnel drop-off is visible per step. Without
   this GA4 sees one page and the funnel is unmeasurable.

### Where the tags live — this needs a decision

Analytics tags are currently a **WPCode snippet on production**, because the
plugin isn't installed there. But `/welcome/` *is* the plugin. So on production
the plugin must be installed before this page exists at all, and at that point
the Google Ads tag should move into the plugin with it rather than staying a
snippet — otherwise the tag and the events it listens for live in two places
with no guarantee both are present.

**Ad blockers** suppress a meaningful share of client-side conversions. The
robust fix is server-side: GA4 **Measurement Protocol** and Meta **Conversions
API**, fired from `complete` where we already know activation succeeded. Both
need credentials the owner must supply (MP API secret; Meta access token +
pixel ID) — I will not put these in the repo; they belong in `wp-config.php`
alongside the other `CASHAADI_*` constants. Recommended, not required: the
client-side tags work without them, just less completely.

---

## 5. Build order

1. `csm/v1/welcome/state` + `step` (+ tests against a real member)
2. Page template + step renderer, photo step first
3. History/back handling
4. Conversion events, client-side
5. Server-side conversions (once credentials exist)
6. Retire the old wizard — **only** once `/welcome/` is proven, and by ceasing
   to route to it, never by deleting xProfile structure

## 6. Out of scope

Discover, Requests, Messages, Profile — the same pattern, later. `/welcome/`
first because it's the highest-stakes funnel and the acute pain.

---

## 7. Verified against the live site (2026-09-01)

Built and driven end to end on staging2. What testing changed in this spec:

| Assumption | Reality |
|---|---|
| REST calls just work when logged in | Cookie auth returns **401** without an `X-WP-Nonce` header. The page must localize `wp_create_nonce('wp_rest')`. |
| Avatar upload = POST the file | BuddyPress also requires **`action=bp_avatar_upload`** in the form data; `wp_handle_upload()` otherwise rejects it as "Invalid form submission". |
| Success returns an object | It returns an **array** of size variants, `[{full, thumb}]`. Checking `d.full` treats every success as a failure. |
| Any photo will do | BuddyPress enforces a **896 x 1024 minimum**. On a step nobody can skip, this needs saying before the upload, not after. |
| `history.pushState` per step is enough | Resuming mid-flow leaves a **single** history entry, so Back exits onboarding entirely. The stack must be seeded with one entry per completed step. |

Confirmed working: nine derived steps; resume at the first unanswered one (a
member who answered five fields at signup lands on step 7); Back walking
7 → 6 → 5 without leaving `/welcome/`; saves persisting; completion redirecting
to Discover; and `complete` returning `fireEvents: true` once and `false` on
every later call, so a refresh cannot double-count the conversion.
