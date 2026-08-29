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
- **v0.11.0** — Profile view field sections (in `screens.css`): the xProfile
  `.bp-widget` groups (Basic Details, Professional details, …) → clean section
  cards (serif group heading, muted label / ink value rows, no boxy borders);
  stacks label-over-value on mobile. Verified live. Header card + photo grid +
  completion meter (#11560) already look reasonable; align later if wanted.
- **v0.12.0** — Settings restyle (in `screens.css`): BuddyPress `.standard-form`
  (General/Email/Visibility/…) → clean card with brand-styled labels, inputs
  (focus ring, muted readonly for the locked Account Email), and a blue Save
  Changes button; WP's own show/generate-password buttons left alone. Verified
  live. **All member screens are now restyled** (Directory/Matches, Messages,
  Notifications, Profile, Settings).
- **v0.13.x** — Discover restyle (in `screens.css`): the #11602 tray cards
  (`#csm-discovery-tray > .csm-card`) enhanced to match the design — serif names,
  consistent portrait photos, neutral Pass + green Like buttons; interior only,
  tray layout + like/pass JS (#11601) untouched. screens.css now also loads on
  the Discover page (scoped via `body.csm-cur-discover`). Verified live.
- **v0.14.0** — `site` module (`includes/modules/site/Site.php`): idempotent
  site-wide snippets #11242 (comments off), #11638 (WC greeting), #11696
  (noindex member pages), #11626 (/pricing/→/membership-pricing/); CSS-only
  #11612 (hide sidebar) + #11582 (caps-lock) moved into `site.css`. Verified
  live (home healthy, /pricing/ 301s correctly).
- **v0.14.1** — support footer (#11691) added to the site module: markup from PHP,
  styles in `site.css`, email from `Config::SUPPORT_EMAIL`. A general-sibling CSS
  rule (`.csm-support-footer ~ .csm-support-footer{display:none}`) hides the
  still-active snippet's duplicate, so both-active shows exactly one (verified: 2
  in HTML, 1 visible).

## SNIPPETS SAFE TO DISABLE (cutover list)
Everything the plugin has REPLACED. Everything NOT listed here has no plugin
replacement yet — leave it active (disabling breaks Discover/photos/verification/
premium/emails/etc.). Method: toggle off in WPCode, check the relevant staging
screen, re-enable if anything looks off.

- **Group A — safe now** (verified, tested both-active):
  `#11641` (mobile fixes CSS), `#11629` + `#11844` (old Save&Next/progress —
  superseded by the wizard), `#11624` (partial save), `#11621` (gender lock),
  `#11619` (bio plain), `#11611` (age sync), `#11797` (height guard),
  `#11625` (email lock); plus the **site** module (v0.14): `#11242` (comments
  off), `#11638` (WC greeting), `#11696` (noindex member pages), `#11626`
  (/pricing/ redirect), `#11612` (hide sidebar CSS), `#11582` (caps-lock CSS),
  `#11691` (support footer — plugin's copy dedupes the snippet's via CSS).
- **Group B — wizard core, disable + immediately check a profile-edit page**:
  `#12132` (Phase-2 JS — the plugin serves a newer Phase-3 wizard; disabling
  removes a potential double-wizard). `#12124` (Phase-0 reskin CSS) — the plugin
  does NOT fully replicate this; try it LAST and re-enable if the edit form looks
  wrong. tokens.css supplies the CSS variables it defined.
- **Group C — analytics, ONLY together with the wp-config flag**: first add
  `define('CASHAADI_ANALYTICS_ENABLED', true);` to wp-config, THEN disable
  `#12084, #12091, #12112, #12073, #11697` (else analytics stops — the module is
  gated off by default).
- **Do NOT disable (not migrated):** all the rest — Discover engine, photos,
  verification, premium/gating, matches, email queue, block, completion meter
  `#11560`, mobile-menu `#11674`, and the other site snippets.

### Operational learnings (do this every deploy)
- **CDN caches static assets for 7 days.** Staging serves assets via Hostinger
  CDN (`server: hcdn`, `max-age=604800`); edges hold inconsistent copies, so
  curling a BARE asset URL shows stale/flapping content even when the deploy
  succeeded. WordPress enqueues `?ver=<plugin version>` (fresh each bump), so
  verify with `...css?ver=<CASHAADI_UI_VER>` or, better, visually in a browser.
  Do NOT diagnose "stuck deploy" from bare-URL curls (it's just the CDN cache).
- Keep bumping the plugin version each deploy so the `?ver` busts CDN + browser
  cache for real users.
- For PHP/loader changes, still grep the home HTML for a fatal (it executes
  fresh); the FieldLogic autoloader fatal was real and this catches that class.
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
