# CAShaadi UI — Handoff for local Claude Code

## CURRENT STATUS (updated 2026-08-29)
Pipeline live: push to `main` → Hostinger auto-deploys to staging in ~20s.
Design + migration plan: see `docs/ARCHITECTURE.md` (coherent core → modules → UI).

Shipped & deployed to staging (all running SAFELY alongside the still-active
WPCode snippets — nothing disabled yet):
- **v0.2.0** — #11641 mobile fixes → `assets/css/profile-edit.css` + `site.css`.
- **v0.3.0** — core layer: `includes/core/` (Config, Membership, Verification,
  Secrets, Assets, Migrator) + `CAShaadi\` autoloader.
- **v0.4.0** — profile-edit field logic module (`includes/modules/profile-edit/
  FieldLogic.php` + `assets/js|css/profile-forms.*`): #11624/#11621/#11619/
  #11611/#11797/#11625.
- **v0.5.0** — analytics module (`includes/modules/analytics/Analytics.php`):
  #12084/#12091/#12112/#12073/#11697. **GATED OFF** (Config::analytics_enabled)
  so it can't double-count; cutover = define `CASHAADI_ANALYTICS_ENABLED` true in
  wp-config AND disable those 5 snippets, together.
- **v0.6.0 / 0.7.x** — app-shell (`includes/modules/app-shell/AppShell.php` +
  `assets/css/tokens.css` + `app-shell.css`): mobile **bottom nav** (Discover ·
  Matches · Messages · Profile) + **top bar** (serif screen title + Settings gear
  + Notifications bell w/ unread badge), and the redundant vertical `#object-nav`
  hidden on mobile (top bar carries Settings/Notifications; `#subnav` kept). Top
  bar rendered via `bp_before_member_header`. NET-NEW UI, no snippet to disable.
  Mobile-only (<=782px); desktop unchanged. Verified live on staging.
  Next app-shell ideas: deciding whether to also slim the `#item-header` profile
  card per screen.
- **v0.8.x** — member-list restyle (`assets/css/screens.css`): premium portrait
  photo cards for the shared BuddyPress member markup — covers Directory,
  Matches, Requests, Visitors at once. Serif names, muted meta (height/age/city),
  green "Add Match" (friendship) + blue secondary actions. Scoped to
  `body.csm-screens`; enqueued on member screens + members directory. 2-up grid
  on mobile; card interior at all widths. NET-NEW UI. Verified live on staging.
  Still to restyle: Settings (grouped list), Notifications, and the own-Profile
  screen (completion ring / photo grid).
- **v0.9.x** — Messages screen. Messages = the **Better Messages** plugin
  (component `bp-messages`, class `.bpbm-*`), a self-contained chat app. We do
  NOT override its internals (fragile). Fixed detection so the top bar reads
  "Messages" and the bottom nav highlights Messages there; added per-screen body
  class `csm-cur-<screen>`; on mobile, hide the redundant profile card +
  completion meter (via `#item-header > *:not(.csm-topbar)`, keeping our top bar)
  so BM reads as a focused full-screen chat. To brand the chat accent color, set
  it in Better Messages' own settings (admin) — not via our CSS.
- **v0.10.0** — Notifications restyle (in `screens.css`): BuddyPress
  `table.notifications` → clean rows on desktop (serif text, muted date, blue/red
  actions) and stacked cards on mobile; bulk-select + Bulk Actions kept. Loads via
  the existing `csm-screens` body class. Verified live (desktop + mobile).
  Still to restyle: Settings (grouped list) and the own-Profile screen.

Pending your action on staging (WPCode), each verified-then-disabled:
- Module-1 UI: **#11641, #11629, #11844**
- Field logic: **#11624, #11621, #11619, #11611, #11797, #11625**
- Analytics (with the wp-config flag): **#12084, #12091, #12112, #12073, #11697**
(Field-logic UX — email/gender/height — not yet visually confirmed; server
filters are idempotent so both-active is safe.)

### Operational learnings (do this every deploy)
- **Verify the actual changed file is live (200) before trusting health** — a
  "home 200" check passed once on code that had not deployed (a missed webhook),
  hiding a fatal that only appeared once the file landed. Check the new file.
- If a push doesn't deploy in ~1 min, the webhook was likely missed: push an
  empty commit (`git commit --allow-empty`) to re-trigger.
- No `php` on this Mac → can't `php -l`. Deploy to staging is the real test;
  the plugin loads on every request, so a parse/class error 500s the site.
  Autoloader now maps CamelCase namespace → kebab-case dir (ProfileEdit →
  profile-edit) and `register()` is guarded by `class_exists`.

---


You're picking up a WordPress plugin that replaces WPCode snippet bloat with a
versioned, mobile-first member-area UI for CAShaadi (BuddyPress). Everything you
need is in this folder.

## START HERE (first run)
A repo was seeded from a cloud sandbox that can't delete files, so its `.git` is
half-initialized and stuck. Reset it cleanly on this Mac:

    rm -f _bootstrap.tgz
    rm -rf .git
    git init -b main
    git add -A
    git commit -m "Initial commit: CAShaadi UI scaffold + migration blueprint"
    git remote add origin https://github.com/yugantarg/cashaadi.git
    git push -u origin main

Then wire auto-deploy: hPanel -> STAGING site -> Advanced -> Git -> Continue with
GitHub -> pick yugantarg/cashaadi -> branch main -> Root directory =
.../wp-content/plugins/cashaadi-ui -> Deploy -> enable auto-deployment.

## What this is
- `cashaadi-ui.php` — plugin loader (enqueues on BuddyPress member pages).
- `assets/js/profile-wizard.js` — the AJAX no-reload profile-completion wizard,
  already migrated out of WPCode #12132 and live-tested on staging.
- `docs/FEATURE-AUDIT.md` — every planned screen mapped to its real backend feature.
- `docs/ROADMAP.md` — incremental build plan (app shell -> profile -> matches -> etc).
- `docs/SNIPPET-MIGRATION.md` — inventory of all 73 WPCode snippets grouped into
  plugin modules, risk-ordered migration sequence, redundancies, secrets.

## Approved design
7-screen mobile member-area canvas (Discover, Matches, Messages, Profile,
Settings, Notifications, Wizard) with a Hinge/Bumble bottom nav. Build UI to match it.

## Current live/staging state
- Staging `staging.cashaadi.in` currently runs the wizard + reskin via WPCode
  snippets #12124 (CSS) and #12132 (JS). The plan is to serve them from THIS
  plugin instead, then retire those snippets.
- Nothing on production has changed.

## SECURITY TODO (do early)
- WPCode #11618 (Phone OTP) has a hardcoded MSG91 access token — it was exported,
  treat as exposed: ROTATE it, then read it from a wp-config.php constant.
- Keep the raw WPCode export OUT of git (it contains that token).

## Ground rules
- Staging first, always. No blind edits on live. No DB-affecting change without an
  explicit decision + backup.
- No snippet is deleted until its plugin replacement is verified on staging.
- Validate before commit: `php -l` on PHP, `node --check` on JS.

## Next module to build
Module 1 = profile-edit: fold in the mobile-fix CSS (#11641), then retire the
redundant "Save & Next + progress" snippets (#11629 and #11844) that overlap the
wizard and caused the earlier double progress bar. See SNIPPET-MIGRATION.md.
