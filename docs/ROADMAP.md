# CAShaadi UI — Build Roadmap

Incremental, staging-first. Each phase is a small, reviewable, deployable step.
Approved design reference: the "CAShaadi Member Area" canvas (7 mobile screens).

## Phase 0 — Walking skeleton  ✅ (this commit)
- Plugin scaffold + wizard JS migrated from WPCode #12132.
- **Goal: prove the GitHub → Hostinger → staging pipeline end to end.**
- Done when: plugin active on staging serves the wizard, WPCode #12132 can be
  switched off with no visible change.

## Phase 1 — App shell (bottom nav + member layout)
- Bottom nav partial (Discover · Matches · Messages · Profile), injected on
  member pages, hidden on the focused wizard.
- Top bar (serif title + notifications bell).
- Hide BuddyX sidebar/subnav on member screens; keep global site header.
- `assets/css/app-shell.css`. Mobile-first; desktop → side/top nav.

## Phase 2 — Profile (own) + Settings
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
