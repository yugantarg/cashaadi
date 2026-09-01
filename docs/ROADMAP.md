# CAShaadi UI — Build Roadmap

Incremental, staging-first. Each phase is a small, reviewable, deployable step.

Design reference: the "CAShaadi Member Area" canvas (7 mobile screens) — but per
the owner (2026-09-01) that canvas is **suggestive**. It defines the visual
language (tokens, card shape, grouped lists, bottom nav); the *content* of every
screen must come from the real backend fields and features. See
**docs/FIELD-INVENTORY.md** for that contract — including the fields that do NOT
exist (no prompts; no `Location`/`About Me`) and the per-field visibility rule
custom screens must enforce.

## Phase 0 — Walking skeleton  ✅ (this commit)
- Plugin scaffold + wizard JS migrated from WPCode #12132.
- **Goal: prove the GitHub → Hostinger → staging pipeline end to end.**
- Done when: plugin active on staging serves the wizard, WPCode #12132 can be
  switched off with no visible change.

## Phase 1 — App shell (bottom nav + member layout)  🔶 partial
- ✅ Bottom nav partial (Discover · Matches · Messages · Profile), injected on
  member pages, hidden on the focused wizard.
- ✅ Top bar (serif title + notifications bell).
- Hide BuddyX sidebar/subnav on member screens; keep global site header.
  - ✅ `#object-nav` (vertical section nav) hidden on mobile.
  - ✅ v0.26.0 — `.buddypress-icons-wrapper` (theme header's duplicate messages
    icon + notifications bell) hidden on mobile member screens.
  - ✅ **`#subnav` hidden (v0.28.0)** on the member's own Profile and on Settings.
    Replaced, not covered: profile section rows cover Edit, the gallery's "Add or
    remove photos" covers Change Photo, and the Settings hub covers every
    settings tab.
  - ✅ **Theme header removed (v0.28.0)** — `#masthead` hidden on member screens.
    Log Out lives in the Settings hub, messages/bell in the app shell, and the
    owner confirmed Home / About Us / Pricing are intentionally dropped from the
    member area. `#masthead` is position:static with no compensating padding, so
    it collapses with no layout gap; marketing pages keep their header.
- ✅ `assets/css/app-shell.css`. Mobile-first.
- ⛔ Desktop: shell is mobile-only (`<=782px`); hiding chrome on desktop needs a
  desktop nav first, otherwise desktop members are stranded.

## Phase 2 — Profile (own) + Settings  🔶 partial
- ✅ **Settings hub (v0.27.x)** — the design's grouped list (Account / Privacy &
  photos / Account status) + Log out + Delete my account, on the member's own
  settings screen, mobile only. Replaces the `#subnav` tab strip there (every
  sub-screen it hid is reachable from a row). Delete row is gated on
  `bp_disable_account_deletion()` — the route 200s but renders no delete UI when
  deletion is disabled.
- ✅ **Profile section rows (v0.28.x)** — one row per real xProfile group in
  `Config::GROUP_ORDER` with a completion state, each opening
  `/profile/edit/group/{id}/`. Status logic: groups WITH required fields count
  required-but-empty; the four groups with none (Lifestyle, Family Details,
  Hobbies, Verification) count empty fields instead — otherwise they always read
  "Complete" even when empty.
- ⬜ Still open: avatar completion ring + "Finish your profile — N steps left"
  CTA. **Product decision** — owner retired the old #11560 percentage meter; the
  design shows an 80% ring. The section rows now carry the same information.
- Restyle member front: completion ring, verified badge, rtMedia photo grid,
  section status, entries to wizard + settings.
- Settings grouped list; Delete-account (native), Membership/Billing → PMPro.

## Phase 3 — Matches (Connections)  🔶 partial
- ✅ **Row list + pill tabs (v0.30.0)** — Matches / Requests / Visitors / Sent now
  render as a vertical list (square avatar left, name + facts right, inline
  Accept/Decline) with pill sub-nav tabs, instead of the directory's browsing
  grid. Same BuddyPress markup, presented per context: scoped to
  `body.csm-cur-matches`, so the members directory keeps its grid (verified).
  Needs one extra class of specificity to beat `screens.css`, which loads later.
- ⚠️ **Not visually confirmed with data** — the admin account has no matches or
  pending requests, so the row layout and Accept/Decline styling were verified by
  CSSOM/selector matching only. Check on an account that has requests.
- ⬜ Accept / Decline / Message behaviour itself is native BuddyPress and
  untouched.

## Phase 4 — Messages + Notifications  🔶 partial
- ✅ **Messages retheme (v0.31.0)** — kept **Better Messages** rather than swapping
  to native BP messages. It is third-party but exposes ~60 `--bm-*` CSS custom
  properties, so we drive its own theming API instead of overriding its layout:
  font, ink/surface/ground/hair, brand blue and softer radii now come from our
  tokens. Defined on `.bp-messages-wrap-main` — custom properties inherit and the
  nearest ancestor definition wins, so no `!important` and nothing to re-fix when
  the plugin updates.
  - ⚠️ Several `--bm-*` values are **bare RGB triplets** (`44, 91, 140`) because
    the plugin composes them as `rgba(var(--bm-x), .5)`. A hex value there breaks
    every rule that uses it.
  - Verified: all overridden properties resolve on the live app, and inner chat
    elements (`.bm-side-content`) compute to Hanken Grotesk.
- ⬜ On **desktop** the BuddyPress profile card + photos still sit above the chat;
  only the mobile rule hides them. Part of the Phase 6 desktop pass.
- ⬜ Notifications screen still stock BuddyPress (`Unread | Read` + a filter
  dropdown). The design shows an icon-chip list.

## Phase 5 — Discover  🔶 partial
- ✅ **Card redesign (v0.29.0)** — photo-first card per the approved design: tall
  portrait with name + "Job title · City" overlaid on a scrim, ICAI Verified
  pill, fact chips (Qualification / Company Name / Height, cm → 5′ 6″) and bio,
  with circular Pass + green Like. `assets/js/discover.js` untouched; Like/Pass
  re-verified live (200, success, remaining 10 → 9).
- ✅ **Data bug fixed** — the card queried xProfile for `'Location'` and
  `'About Me'`, neither of which exists here, so both lines always rendered
  empty. Real fields are **`City`** and **`Bio`**. `Age` is filtered and can
  return "27 years old", so the digits are extracted.
- ❌ **Prompts — dropped.** The mock's Hinge-style prompt Q&A has no backing
  field on this site, and the data model is matrimonial rather than dating. The
  card shows **Bio**, the real equivalent.
- ✅ **Per-field visibility enforced (v0.29.1)** — the card now skips fields the
  member restricted (see FIELD-INVENTORY.md).
- ✅ **Matrimonial facts on the card (v0.30.0)** — chips now come from the real
  fields this audience decides on: Qualification, Company Name, Height, Religion,
  Community, Language (Mother Tongue), Diet. Capped at 6 so rich profiles stay
  scannable and sparse ones degrade cleanly; all visibility-filtered.
- ⬜ Filter control — **explicitly deferred by the owner (2026-09-01)**.
- ⬜ Filter control in the Discover header (design has a filter icon).
- ⬜ Desktop: cards currently wrap 2-up; a deliberate desktop layout is Phase 6.

## Phase 6 — Consolidate & harden  🔶 partial
- ✅ **Desktop pass (v0.32.x)** — desktop now gets the same app instead of stock
  BuddyPress. A fixed **left rail** (Discover · Matches · Messages · Profile)
  replaces the bottom bar, the top bar carries title + settings + bell, the theme
  header and `#object-nav` are gone, and content is held to a 780px column.
  The rail is introduced BEFORE the chrome is removed — same ordering rule as
  mobile, so nobody is stranded without navigation.
  - The Settings hub, profile section rows and Matches row list became
    `@media all` rather than duplicating rules per breakpoint.
  - Settings and Messages now open as **focused screens** (profile card + photo
    gallery hidden), which also closed the logged desktop-Messages gap.
  - Verified visually: rail, top bar, stacked rows, Discover feed, Settings hub.
    Marketing pages are untouched (masthead visible, no rail, no body padding).
- ⚠️ **Two bugs that only a screenshot caught** (recorded as a lesson):
  1. Top-bar child styles were written inside the mobile media query, so the
     desktop reveal rendered them unstyled (title 37px, actions 7px wide).
  2. BuddyX floats `#item-body li`, so the Settings and profile section rows sat
     side-by-side at 216px instead of stacking — wrong on mobile too, just never
     seen. DOM/CSSOM checks pass on both; only rendering exposes them.
- ⬜ Accessibility pass; real-device QA; Notifications screen; promote to live.
- ⬜ Fold remaining WPCode helpers into the plugin where sensible.

---
### Verify against live before building (from the audit)
Active plugins list · enabled BuddyPress components · PMPro levels · how the
Discovery module currently filters candidates · location of the OTP-verify snippet.
