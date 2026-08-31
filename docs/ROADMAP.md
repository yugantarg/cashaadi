# CAShaadi UI — Build Roadmap

Incremental, staging-first. Each phase is a small, reviewable, deployable step.
Approved design reference: the "CAShaadi Member Area" canvas (7 mobile screens).

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

## Phase 3 — Matches (Connections)
- Tabbed Received / Sent / Matches over BuddyPress Friends.
- Accept / Decline / Message on native friendship actions.

## Phase 4 — Messages + Notifications
- Restyle inbox list + thread; notifications loop behind the bell.

## Phase 5 — Discover
- Browse/card UI over the Discovery module + xProfile filters.
- Like/Pass → connection request. (Largest custom piece — verify current logic first.)

## Phase 6 — Consolidate & harden
- Fold remaining WPCode helpers into the plugin where sensible; document what stays.
- Desktop responsive pass; accessibility; QA on staging; promote to live.

---
### Verify against live before building (from the audit)
Active plugins list · enabled BuddyPress components · PMPro levels · how the
Discovery module currently filters candidates · location of the OTP-verify snippet.
