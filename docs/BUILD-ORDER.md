# Build order & current state

**Last verified: 2026-09-02, staging2, plugin v0.63.0.**

This is a state document, not a log. It says what exists, what is left, and what
will bite you. The chronological account of how we got here is in git history.

**Direction: incremental headless.** WordPress and BuddyPress stay as the data
layer and admin. Member screens are our own templates, rendered client-side
against REST, owning their whole document — no BuddyX, no BuddyPress templates,
no page-per-screen navigation.

Why that direction: every layout bug this rebuild hit came from fighting the
theme for control of the markup. The proof it worked is that `welcome.css`
contains no `!important` at all.

---

## 1. Where things stand

### Member screens

| Screen | Route | State |
|---|---|---|
| Onboarding | `/welcome/` | Own document. Steps derived from `is_required`; progress derived from the data, never stored. |
| Discover | `/discover/` | Own document. Full scrollable profile card, optimistic like/pass. |
| Requests | `/requests/` | Own document. Received / sent / viewers, premium gate applied **server-side**. |
| Profile hub | `/profile/` | Own document. Completion-first. |
| Profile edit | `/profile/edit/?g=N` | Own document. One section at a time — not a wizard. |
| Settings | `/settings/` | Own document. Owns the list; BuddyPress owns the editors. |
| Messages | member `/messages/` | **Better Messages, by decision.** See §4. |

Still rendered by BuddyX, reached by linking out — each with a back link into
the app: another member's profile, the photos screen, the settings sub-editors.

### Snippets: 73 → 4

| Snippet | Why it is still on |
|---|---|
| #11732 / #11733 Reminder emails | Kept by owner's choice (paused anyway) |
| #11701 Verified CA Badge | Blocked on one wp-config flag — see §2 |
| #11682 OTP checklist item | Same flag |

### Cutover flags (staging2 `wp-config.php`)

**On (9):** analytics, premium, photos, discover, matches, block, admin,
ca_verify, signup.
**Off (4):** verification, emails, otp, profile_tools.

### Production

**Untouched. The plugin is not installed there.** Production still runs on its
own snippet set.

---

## 2. What is left

### Owner actions (nothing below is blocked on code)

| Action | Why it matters |
|---|---|
| `define( 'CASHAADI_VERIFICATION_ENABLED', true );` in `public_html/staging2/wp-config.php` | Last snippet blocker. Then disable #11701 and #11682 — verified safe: `Modules\Verification` covers both, and nothing calls #11701's globals. |
| Better Messages → Settings → **Mobile** → turn **Auto Open Full Screen OFF** | Messages is the only screen that traps members. See §4. |
| Paste tracking credentials at **Settings → CA Shaadi Tracking**, then switch "Enable tracking" on | Nothing is sent until then. |
| `csm_pm_enforce` option | Photo moderation currently detects and queues but hides nothing. |
| MSG91 widget ID + token | Phone OTP. |

### Build work remaining

* **Server-side conversions** — GA4 Measurement Protocol + Meta CAPI, fired from
  `Welcome::rest_complete()` where the server already knows activation
  succeeded. Recovers ad-blocked events. Needs the credentials above.
* **Nothing has been tested by a real member on a real phone.** Every
  verification in this project was done by driving the DOM as admin or via User
  Switching. The signup → activation → `/welcome/` → Discover journey has never
  been walked by an actual person. This is the single largest untested risk
  before production.

---

## 3. Production cutover — last

Owner's decision: production is not touched until the rebuild is finished, and
it happens **once**. Cutting over earlier would mean doing it twice, and the
second time — carrying the redesign — is the risky one either way.

The sequence, in a low-traffic window:

1. Deploy the plugin. **Migration is a code deployment, not a data migration** —
   the plugin renders member data, it does not own it. Copy nothing from
   staging2; its members are a stale clone.
2. Add the flags.
3. Disable the corresponding snippets, in dependency order (§5).
4. Re-test premium checkout, which touches Woo + PMPro.

### ⚠️ Check before enabling tracking on production

**#12112 "CSM - GA4 events (sign_up + purchase)".** It is OFF on staging2 but
its state on production is unverified. If it is on, every signup counts twice:
it fires GA4 `sign_up` at **registration**, the plugin fires at **activation**,
and Google Ads imports that event as a conversion — so the number the bidding
algorithm optimises against inflates.

Its `purchase` event (PMPro checkout) is **not** duplicated by the plugin. Turn
#12112 off and that tracking is lost until rebuilt.

Also: Site Kit already loads gtag with the live GA4 property **G-VJW0VMS7KC**.
That is the Measurement ID for the settings screen. The plugin does not load a
second gtag when one is present.

---

## 4. Messages — assessed, deliberately not rebuilt

Rebuilding a real-time messaging client would be replacing working software with
worse software. Desktop is fine: renders inline, header visible, no scroll lock.

**On mobile it takes the entire viewport** — `position: fixed`, `z-index:
100000`, full height, plus `body { overflow: hidden }` — covering the header and
the bottom nav, with no way back in its own UI. The only exit is the browser
back button. Every other screen keeps a persistent nav, so this is the one that
traps people.

**It is a setting, not code:** Better Messages → Settings → Mobile →
*Auto Open Full Screen* **off**, leaving *Full Screen Mode* on. The list then
keeps the app nav; fullscreen stays available for the conversation view, where
it helps.

Not changed here because it affects every member — owner's call.

---

## 5. Standing rules

**Never delete xProfile groups or fields.** See `FIELD-INVENTORY.md`. Deleting a
group or field deletes every member's answers for it — thousands of profiles on
production, irreversibly. "Remove profile groups" always means *stop showing
them*, never *delete them*.

**Never deactivate BuddyPress.** It has caused an outage on this project before.

**Snippet retirement order depends on what collides:**

* *Global functions* → disable the snippet **first**, or the plugin's copy is a
  redeclare fatal.
* *Dependencies between snippets* → disable dependents **before** the snippet
  they call. (#11838 and #11861 called #11822's helpers.)
* *Class-based modules with no globals* → order is free; overlap only duplicates
  UI. Photos went flag-first for exactly this reason.

**Before disabling anything, scan every other active snippet for references to
the functions and constants it defines.** Disabling a snippet another one calls
is a fatal. This check has justified itself repeatedly.

**Verify state afterwards — never trust the HTTP 200s.** A batch of six
disable calls all returned 200 while one snippet stayed active, because the
written order listed only seven of eight items.

---

## 6. Things that will waste your time if you do not know them

**Production's `wp-config.php` sits in the same `public_html` listing as
`staging/` and `staging2/`.** A wrong-folder edit is a production outage, and a
flag edit has already gone into `staging/` by mistake once and silently never
registered. Verify by opening the file and confirming it already contains the
other `CASHAADI_*_ENABLED` flags before writing.

**404-to-301 turns every BuddyPress 404 into a redirect to the home page.** A
mistyped member URL looks exactly like a catastrophically broken site. Check the
username before believing it.

**GA4 events must be scoped with `send_to`.** Without it gtag delivers to *every*
configured property — Site Kit configures the live one, so staging events land
in production analytics regardless of what IDs the plugin holds. This happened.

**Per-field visibility is not automatic.** `xprofile_get_field_data()` ignores
it. Anything reading fields directly must filter through
`bp_xprofile_get_hidden_fields_for_user()`, or it leaks restricted data. Use
`Core\Profile`, which handles it.

**A CSS blur is not a paywall.** The premium gate on "who viewed me" is applied
server-side before serialisation — a free member's JSON contains an initial and
a relative time, never an id, name or avatar.

**`Age` is filtered** and returns `"27 years old"`; **`Height` is centimetres**;
**`Location` and `About Me` do not exist** — the real fields are `City` and
`Bio`. All handled by `Core\Profile`.

**Two classes are named `Verification`** — `Core\Verification` (has
`ca_verified()`) and `Modules\Verification\Verification` (the REST endpoint,
which does not). `class_exists()` does not save you; use `method_exists()`.

**`/checkout/` 302s to `/cart/` on an empty cart**, so a non-following request to
`/checkout/` tests the wrong page.

---

## 7. Related documents

| Document | What it holds |
|---|---|
| `FIELD-INVENTORY.md` | The xProfile contract, field gotchas, and the data-safety rule |
| `WELCOME-SPEC.md` | The onboarding design and the five assumptions live testing overturned |
| `ARCHITECTURE.md` | Plugin layout and module conventions |
| `SNIPPET-MIGRATION.md` | Per-snippet migration record |
| `ROADMAP.md` | Superseded by this document; kept for history |
