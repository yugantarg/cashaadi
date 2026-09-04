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

### Snippets: 73 → 2

| Snippet | Why it is still on |
|---|---|
| #11732 / #11733 Reminder emails | Kept by owner's choice (paused anyway) |

**Nothing else remains.** #11701 (Verified CA badge) and #11682 (OTP checklist)
were retired on 2026-09-02 once `CASHAADI_VERIFICATION_ENABLED` was added;
`Modules\Verification` serves both. Verified after: the badge endpoint
`POST csm/v1/verified` returns `{"383":"ca","386":"ca"}` and the "Verified CA"
badge still renders on the profile hub.

### Cutover flags (staging2 `wp-config.php`)

**On (10):** analytics, premium, photos, verification, discover, matches, block,
admin, ca_verify, signup.
**Off (3):** emails, otp, profile_tools.

### Production

**Untouched. Verified 2026-09-02 (read-only):** the plugin is not installed —
`plugins.php` lists no CAShaadi plugin, and the flag notice is absent. A
"Photo Moderation" admin menu exists there but belongs to snippet #12119, not to
us.

Production runs the original snippet set: **74 snippets, 66 active** (staging2 is
at 2). Everything the migration disabled on staging — block, premium, discover,
photos, field logic — is still live there on its snippets, which is correct and
expected.

**Production cron is healthy.** Site Health reports no failed scheduled event and
no PMPro cron-disabled notice. The cron problem investigated on staging2 was
entirely a clone artifact — now confirmed against production rather than assumed.

---

## 2. What is left

### Owner actions (nothing below is blocked on code)

| Action | Why it matters |
|---|---|
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

### ⚠️ Tracking on production — VERIFIED, and worse than one snippet

Checked directly on cashaadi.in (read-only, 2026-09-02). **Three** tracking
snippets are ACTIVE there, and the plugin duplicates all three:

| Snippet | Fires | Plugin also fires |
|---|---|---|
| #12112 GA4 events | `sign_up` at **registration** | `sign_up` at **activation** |
| #12084 Meta Pixel | `CompleteRegistration` | `CompleteRegistration` |
| #12091 Meta Pixel | `purchase` (PMPro) | — |

So enabling plugin tracking without disabling #12112 and #12084 double-counts
every signup in **both** GA4 and Meta. Google Ads imports the GA4 event as a
conversion, so the number the bidding algorithm optimises against inflates.

They also disagree on the milestone: the snippets fire at registration, the
plugin at activation — and an unactivated signup is not a signup.

**#12091's `purchase` event is NOT duplicated by the plugin.** Leave it on, or
rebuild purchase tracking first.

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

---

## Owner review round — v0.64–v0.66 (2026-09-02)

### Two data/privacy faults, both found by looking rather than reasoning

**Profile edit was about to corrupt data.** The editor filled its inputs from
`xprofile_get_field_data()`, which applies DISPLAY filters:

```
Phone Number  -> <a href="tel://08697222644" rel="nofollow">08697222644</a>
Date of birth -> "27 years old"      (the Age filter, not a date)
```

Saving Basic Details would have written the anchor markup into Phone and a
non-date into DOB. Editors must read `BP_XProfile_ProfileData::get_value_byid()`.

**Phone numbers were visible to strangers.** The new "how others see me" preview
surfaced it on its first run. Contact details now never appear on a profile
another member can see — per-field visibility can express this, but a safe
default must not depend on every member having configured it. DOB withheld too;
Age is already in the header.

### Fixed in this round

* Browser `confirm()`/`alert()` — all five replaced with an in-app sheet/toast;
  unsaved changes is a passive pill.
* ICAI ID rendered as a text box (`file` is a custom field type) — now links to
  the classic uploader instead of a control that cannot work.
* "Other relevant documents" hidden (**hidden, never deleted**).
* Discover empty state: when new profiles arrive + countdown, and Premium for
  free members only. Quotes the same reset as the quota banner.
* Hamburger redesigned: identity, non-tab actions, Log out set apart.
* Header uses the logo, found by SLUG (ids differ across environments).
* `/profile/preview/` — the member's own Discover card, since Discover is where
  others actually see them.

### Click-test result

21 client-rendered destinations plus 8 static links: **zero broken, zero landing
on home.** Ten leave the app into BuddyX (settings sub-editors, photos, public
profile) — each has a back link.

> ⚠️ **Never let a link crawler follow the logout URL.** A first pass fetched it
> with credentials and destroyed its own session mid-audit, which then looked
> like every screen redirecting to login.

### Still open from this round

* **Blocked members / Email notifications** still open BuddyPress settings
  sub-screens. Bringing them in-app means building a blocked list and a
  notifications form — real work, not a link change.
* The **settings gear** on BuddyPress member screens measured correct (44×44,
  21px icon) and looked right in screenshots — the reported mangling was not
  reproducible. Needs a specific screen to chase.

---

## v0.67 — closing the gaps (2026-09-02)

**Blocked members is now an app screen** at `/settings/blocked/`, no longer a
jump into BuddyPress settings. Rendering only: the Block module still owns the
table, the unblock action and its cache invalidation, and now exposes
`blocked_ids()` so the screen never needs the table name.

**The phone row is no longer a dead end.** It advertised verification that cannot
happen here — the OTP module is flag-off, snippet #11618 is disabled, and MSG91
credentials were never supplied, so "Not verified" linked to a form with no
verification UI. It now checks whether verification is genuinely available
(module enabled AND credentials present) and, while it is not, shows the number
as a plain readout. It becomes a working link the moment OTP is enabled.

### One root cause, three symptoms — the rule worth remembering

`xprofile_get_field_data()` applies DISPLAY filters. The telephone field returns
`<a href="tel://…">…</a>`, and dates come back as "27 years old". This produced
three separate bugs before the pattern was obvious:

1. **Profile editor** filled its inputs from it, so saving would write anchor
   markup into Phone and a non-date into DOB.
2. **Profile display** rendered the anchor as literal text on the card.
3. **`Verification::user_phone()`** stripped non-digits from the anchor and
   harvested the number twice — `86972226448697222644`. That is also the number
   an OTP would be sent to. `Premium::lead_phone()` shared the cause and stored
   the markup on leads a salesperson calls.

> **Rule:** `xprofile_get_field_data()` is for DISPLAY only. Anything that
> stores, edits or transmits a value must read
> `BP_XProfile_ProfileData::get_value_byid()`.

Also worth noting: two methods were called from outside the module that owns
them (`Block::do_unblock`, `Block::table`) and both were private — the second
returned 500 until caught. Check visibility before reaching across a module.

### Still open

* Messages mobile takeover (one Better Messages setting — owner's call).
* Tracking credentials, MSG91, `csm_pm_enforce` — all owner-supplied.
* **Nothing has been used by a real member on a real phone.**

---

## v0.68 — Email notifications in-app; settings seams closed

`/settings/notifications/`. The last of the settings seams — Settings, Blocked
members and Email notifications are now all app screens.

**Settings are discovered, not hardcoded.** BuddyPress exposes no API listing
them; each component prints its own rows on the `bp_notification_settings`
action. So the rows are captured by buffering that action and reading the inputs
back out — if Better Messages or another component adds a preference later it
appears here on its own, where a written list would silently omit it.

That capture is also the **security boundary on save**: only a key BuddyPress
just rendered can be written. Verified — a crafted key returns "Unknown
setting." rather than writing arbitrary user meta.

Toggles save on tap instead of a grid of yes/no radios behind a Save button, and
each reverts if its save fails, so the switch never shows a state the server
refused. Verified: toggling persists across a re-read.

### Remaining seams (linked out, each with a back link)

Photos, the settings *editors* for email/password/field-visibility, and another
member's profile page. Account security stays with BuddyPress deliberately.

---

## v0.69 — the "empty profile loop" explained (and it was ours)

**Not a BuddyPress fault.** Fetching the member page HTML showed the loop working
perfectly: four widgets, 5.6KB, Name / Qualification CA / Religion Hindu /
Hobbies Yoga. Only the LIVE DOM looked empty — because `innerText` ignores
hidden elements, which is precisely what made it read as a broken loop.

The cause was `profile-sections.css` hiding `table.profile-fields` under
`body.csm-prof-screen`. That rule was **correct when added** in v0.36.1: the page
then also rendered the hub's section rows, so the table beneath them was a
duplicate. `ProfileScreen` stopped rendering those rows in v0.65.1 when the hub
moved to `/profile/` — and the rule outlived its reason, leaving four 16px
shells with their contents `display: none`.

Diagnosed by reproducing the asymmetry deliberately: visible as admin on another
member's profile, hidden when switched INTO that member viewing their own. That
own-profile-only behaviour is what pointed at our CSS rather than the loop.

> **Second stale-rule bug of this rebuild.** The first hid the Discover card's
> name behind pre-v0.29 styling. When a screen changes job, its old CSS does not
> announce that it has become wrong — worth a grep for rules scoped to a screen
> whenever that screen's responsibilities move.

### It also exposed a half-applied privacy fix

v0.66.1 withheld phone numbers via `Core\Profile`, which covers Discover and the
preview. Restoring the field table showed that BuddyPress's own member page
renders the xProfile loop directly and was **still printing the number in full**
to any logged-in member.

Now filtered at `bp_xprofile_get_hidden_fields_for_user` — the function
BuddyPress consults in its loop AND the one `Core\Profile` filters through, so
there is one answer to "may this viewer see this field" instead of two places to
keep in step. Verified both ways: hidden from another member, still visible to
the member themselves.

---

## Queued by the owner (2026-09-04)
- ⬜ **"Save for later" as a third Discover action**, alongside Like and Pass.
  Touches more than the button row: `wp_csm_tray.status` is an ENUM
  (`pending`,`liked`,`passed`,`expired`) and needs a fourth value, and
  `Seen.action` needs the same.
  Quota is NOT a question — the weekly 5/10 is how many profiles enter the
  tray, and `$served_week` counts rows by assignment week regardless of status,
  so no action a member takes earns them more profiles. Saving is quota-neutral.
  The real open questions are:
  1. **Decided (owner, 2026-09-04): a saved profile moves to a Saved list on
     the Requests screen**, which already collects received / sent / viewers.
     Discover stays a one-at-a-time deck.
  2. Acting on it later must still work — `Discover::act()` now requires
     `status = 'pending'`, so it must accept `'saved'` too, or a member can
     never like someone they saved.
  3. Does saving count as a decision for the re-show gap below? (It should not
     — saving is explicitly "not yet decided".)

- ✅ **Re-show after a gap (owner decisions, 2026-09-04)** — shipped v0.91.0.
  - **Only passes expire.** A like stays excluded permanently: that member
    already has a pending match request with the profile, and re-serving someone
    you have asked to match with reads as broken. Unacted rows stay excluded too
    (they are still sitting in the tray).
  - **Window: 1 month.** Shorter than the 3-month floor I offered, so it is a
    `csm_pass_reshow_months` filter, not a hardcoded number — tunable without a
    deploy if members start reporting repeats.
  - Owner is aware of the trade-off: with a 5/week tray, a small eligible pool
    and a 1-month window, a member who passes steadily will start seeing
    previously-passed profiles again fairly often. Watch `wp_csm_seen` for
    `times_served > 1` growth after cutover.

- ⬜ **First-action explainer.** The first time a member clicks Like, Pass or
  Save for later, show a short popup saying what that action does — that Like
  sends a match request the other person can accept, that Pass is permanent and
  the profile will not return, and what Save keeps. Once per action per member
  (user meta) — **decided (owner, 2026-09-04): all three actions, first use of
  each**, never again. Pass being irreversible is the part people most need
  told BEFORE they use it, not after.

## Engagement email programme (owner decisions, 2026-09-04)

**Volume rule: a DAILY DIGEST for match activity**, not one email per event.
Likes received, matches and message alerts batch into one email per day. A
member hears about a match up to 24h late — the owner accepted that trade for
lower unsubscribe risk. Transactional mail (verification outcome, password) is
exempt and sends immediately.

**Opt-out: per-category toggles** on the existing Email notifications screen —
matches / nudges / weekly batch as separate switches, so a member annoyed by one
kind does not unsubscribe from all of them.

### To build

| Email | Cadence | Notes |
|---|---|---|
| New batch has arrived | Weekly, on the Monday reset | Owner's original request |
| Log in every 2 weeks to be shown more | Fortnightly, dormant-leaning | **Only honest because ranking makes activity a hard tier** — if that tier is ever removed, pull this email |
| Someone liked you | → daily digest | Respect the premium gate: free members are told they have a request, never who |
| It's a match | → daily digest | Pairs with the MatchIntro conversation seeding |
| Weekly picks expiring | Weekly, before the Monday reset | Only when unacted rows remain |
| Verification status changed | Immediate, transactional | ICAI approved/rejected — today a member gets no outcome at all |
| Dormant win-back | 60+ days inactive | "You have dropped out of the active pool" — true, per the tier |

### Already exists — audit before building a second one

- **`better_messages_send_notifications`** runs every 15 minutes: Better Messages
  already emails about new messages. **Do not build our own** — either route
  message alerts into the digest AND turn theirs off, or leave theirs alone.
  Building both means members get two emails per message.
- **`cashaadi_sent_profile_incomplete_email_to_users`** (daily) already sends
  the completion nudge. Audit what it says — completion is measured properly now
  — rather than adding a second.
- **`csm_remail_cron`** is the WPCode #11732 reminder queue. New emails should go
  through that queue, which means the **email module cutover is a prerequisite**
  for this programme rather than a parallel task.

### Blocker

**staging2 has no working cron** (`DISABLE_WP_CRON` with nothing calling
wp-cron.php), so nothing scheduled can be tested there until it is set up in
hPanel → Advanced → Cron Jobs. Production's cron is healthy. Every email above
is cron-driven except the verification one.

### Declined

"Your request was declined" — rejection with nothing to act on. The data is
already captured for insights; the member does not need the mail.
