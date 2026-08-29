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
  site-wide snippets #11696
  (noindex member pages), #11626 (/pricing/→/membership-pricing/); CSS-only
  #11612 (hide sidebar) + #11582 (caps-lock) moved into `site.css`. Verified
  live (home healthy, /pricing/ 301s correctly).
- **v0.14.1** — support footer (#11691) added to the site module: markup from PHP,
  styles in `site.css`, email from `Config::SUPPORT_EMAIL`. A general-sibling CSS
  rule (`.csm-support-footer ~ .csm-support-footer{display:none}`) hides the
  still-active snippet's duplicate, so both-active shows exactly one (verified: 2
  in HTML, 1 visible).
- **v0.15.x** — Premium module (`includes/modules/premium/Premium.php`), all
  GATED behind `CASHAADI_PREMIUM_ENABLED` (see Group D), built on core
  Membership/Config:
    - #11579 upgrade button (own profile + directory; "complete profile" fallback)
    - #11795 checkout hygiene (block re-purchase, cart cleanup, pricing label —
      label JS in `assets/js/premium.js`)
    - (#11614 contact gate NOT migrated — already disabled on the live site)
  Button/contact styles in `site.css`. Deployed + verified healthy (gated off, no
  behaviour change). NOT visually verified (needs the flag on + a free-vs-premium
  test account) — do that before cutover.
  NOTE: **#11620 "Profile Gate" is NOT premium** — it's a *completion/phone-verify*
  gate (blur incomplete profiles) with child-theme deps (`csm_user_profile_is_complete`,
  `cashaadi_has_missing_required_fields`). It belongs in a completion/profile
  concern, not the premium flag; left in WPCode for now.
    - #11811 Profile Visitors ("who viewed me": premium full list / free locked
      teaser; subnav under Matches + `[csm_profile_visitors]`). READ-ONLY over the
      `wp_csm_profile_views` table, which is owned/logged by #11807 — so #11807
      must stay active (or be migrated) for visitors to have data. CSS in
      `assets/css/premium.css`.
    - #11796 Premium Intent Leads (`wp_csm_intent` table via the **Migrator** —
      the first real schema; Migrator VERSION now `1`, created only when the flag
      is on). Tracks upgrade-clicks not yet paid; Users → Intent Leads admin page
      + CSV export.
  Deployed + verified healthy (all gated off). NOT visually verified (need flag on
  + free-vs-premium accounts) — do before cutover.
  NOTE: **#11620 "Profile Gate" is NOT premium** — it's a *completion/phone-verify*
  gate (blur incomplete profiles) with child-theme deps; left in WPCode.
    - #11807 Rejection Insights (v0.17): owns `wp_csm_rejections` +
      `wp_csm_profile_views` via the **Migrator** (VERSION 2), logs rejections
      (`friends_friendship_rejected/accepted`) + profile views
      (`template_redirect`), and renders `[csm_rejection_insights]` (3-tab premium
      panel / free locked teaser). This CLOSES the visitors data chain — #11811
      now reads a table this module owns. Tab JS in `premium.js`, styles in
      `premium.css`.
    - #11821 view-email (v0.18): email the owner (max 1/day, user_meta
      `csm_pve_last`) when someone views their profile. Runs after log_view.
  The premium module is now **COMPLETE — 6 gated snippets**: #11579, #11795,
  #11811, #11796, #11807, #11821. Cutover = flip `CASHAADI_PREMIUM_ENABLED`
  + disable all six together. The Migrator creates the 3 tables on enable
  (dbDelta no-ops on the snippet-made tables). MUST be tested with a free + a
  premium account first (contact gate, visitors, insights, view-email, leads).
  (#11581 checkout CSS is NOT gated — it's in `site.css` as a Group-A item.)
  **CUTOVER TESTED & PASSED on staging (2026-08-29):** `CASHAADI_PREMIUM_ENABLED`
  is ON in staging wp-config and the 7 premium snippets are DISABLED. Verified via
  User Switching with a free (Vedika) + premium (Yosha) account: upgrade button
  (free)/none (premium), contact gate nudge/details, Visitors masked-teaser/full-
  list, Intent Leads table created (no DB error, CSV), and the view-email actually
  delivered. Staging DOES send real email. Leave the snippets OFF. For PRODUCTION:
  same steps (wp-config define + disable the 7); the 3 tables already exist there.
  Only unshown: `[csm_rejection_insights]` panel (no page embeds the shortcode yet).
  NOTE: **#11620 "Profile Gate" is NOT premium** (completion/phone gate) — left in WPCode.
- **v0.19.0** — Photos module started (`includes/modules/photos/Photos.php`):
  #11617 local default avatar (attachment 11616, resolved per-env) + #11813 HD
  avatar sizes (896x1024 full / 448x512 thumb, jpeg q92). Both idempotent avatar
  filters, NOT gated (safe both-active → Group A). Verified deployed + healthy.
  The 3-filter privacy/NSFW resolver is Group E (gated).
- **v0.20–0.22** — Photos hard gates (all gated by CASHAADI_PHOTOS_ENABLED):
  `Photos\Privacy` (#11770 blur, GD/Imagick derivative, fail-safe to default),
  `Photos\PhotoRequest` (#11798 ask/approve, reveal composed into
  csm_photo_is_hidden, table via Migrator, assets in photos.css/js + a
  csm_photo_is_hidden() shim in compat.php for the transition), `Photos\Nsfw`
  (#12119 OpenAI moderation sweep + mask@21 + admin queue). Deployed + healthy
  (gated off). Composed chain verified structurally; behaviour test pending the
  flag-on cutover.
- **v0.23.0** — Verification display module (`Modules\Verification`): #11701
  verified-CA badge (REST `csm/v1/verified` → `Core\Verification::ca_verified/
  ca_level`, injected client-side) + #11682 OTP checklist item; CSS/JS in
  `assets/{css,js}/verification.*`. Gated by CASHAADI_VERIFICATION_ENABLED. First
  real consumer of `Core\Verification`. Deployed + healthy (gated off).

## ALREADY-INACTIVE snippets (do NOT migrate / do NOT re-introduce)
The export JSON has no active/disabled flag. Per the site owner, exactly these 8
were already inactive on the LIVE site (superseded or retired) — the plugin must
NOT replicate them: **`#12132`** (Phase-2 wizard JS — superseded by the plugin's
wizard), **`#11814`** (avatar HD rebuild tool), **`#11801`** (premium partner
search), **`#11638`** (WC greeting), **`#11614`** (contact gate — contact is
hidden from EVERYONE via the phone field's xProfile visibility, not a snippet),
**`#11252`** (Sign Up HTML), **`#11241`** (post-paragraph message), **`#11242`**
(disable comments). #11614/#11638/#11242 were mistakenly migrated and have been
REMOVED (v0.24). Everything else in the export is ACTIVE.

## SNIPPETS SAFE TO DISABLE (cutover list)
Everything the plugin has REPLACED. Everything NOT listed here has no plugin
replacement yet — leave it active (disabling breaks Discover/photos/verification/
premium/emails/etc.). Method: toggle off in WPCode, check the relevant staging
screen, re-enable if anything looks off.

- **Group A — safe now** (verified, tested both-active):
  `#11641` (mobile fixes CSS), `#11629` + `#11844` (old Save&Next/progress —
  superseded by the wizard), `#11624` (partial save), `#11621` (gender lock),
  `#11619` (bio plain), `#11611` (age sync), `#11797` (height guard),
  `#11625` (email lock); plus the **site** module (v0.14): `#11696` (noindex member pages), `#11626`
  (/pricing/ redirect), `#11612` (hide sidebar CSS), `#11582` (caps-lock CSS),
  `#11691` (support footer — plugin's copy dedupes the snippet's via CSS),
  `#11581` (membership checkout CSS → `site.css`), `#11617` (local default
  avatar) + `#11813` (HD avatar sizes) — both idempotent avatar filters (photos
  module, v0.19).
- **Group B — wizard**: `#12132` (Phase-2 wizard JS) is ALREADY INACTIVE on the
  live site — no action; the plugin's `profile-wizard.js` (newer Phase-3) is the
  wizard. `#12124` (Phase-0 reskin CSS) is ACTIVE and NOT migrated — LEAVE IT ON
  (the wizard builds on it; `tokens.css` only supplies fallback vars).
- **Group C — analytics, ONLY together with the wp-config flag**: first add
  `define('CASHAADI_ANALYTICS_ENABLED', true);` to wp-config, THEN disable
  `#12084, #12091, #12112, #12073, #11697` (else analytics stops — the module is
  gated off by default).
- **Group D — premium (COMPLETE, cut over + tested on staging)**: 6 snippets
  (`#11579/#11795/#11811/#11796/#11807/#11821`) behind `CASHAADI_PREMIUM_ENABLED`,
  already flag-on + snippets-disabled on staging and verified. `#11614` contact
  gate and `#11620` profile gate are NOT part of it — both were already disabled
  on the live site. For production: same steps (flag + disable the 6).
- **Group E — photos hard-gates (BUILT, gated, ready to test)**: `#11770` private
  blur (`Photos\Privacy`), `#11798` photo request (`Photos\PhotoRequest`, owns
  `wp_csm_photo_requests` via Migrator v3), `#12119` NSFW (`Photos\Nsfw`) — the
  three fighting `bp_core_fetch_avatar_url` filters are now ONE composed chain:
  Privacy blur at prio 20 (revealed for an approved request via the
  `csm_photo_is_hidden` filter) then NSFW mask at prio 21 (absolute — wins over
  blur AND reveal). Gated behind `CASHAADI_PHOTOS_ENABLED`. **Cutover = flip flag
  + disable ALL THREE together** (`#11770/#11798/#12119`) — NOT one at a time (the
  plugin now provides all three, so a lone snippet would double). Test like
  premium (match vs non-match viewer; a flagged avatar masks). NSFW reuses the
  shared OpenAI key (Secrets) + the `csm_pm_sweep_event` cron + `csm_pm_*` meta.
  Still un-migrated in photos: `#11822` gallery/upload, `#11771` lightbox, one-off
  tools `#11814`/`#11861`, and `#11838`/`#11690` onboarding photo bits.
- **Group F — verification display (gated, ready to test)**: `#11701` verified-CA
  badge + `#11682` OTP checklist item → `Modules\Verification\Verification`, built
  on `Core\Verification`. Gated behind `CASHAADI_VERIFICATION_ENABLED`; cutover =
  flip flag + disable `#11701` and `#11682` together (they inject via JS/REST so
  both-active doubles). The OTP itself (`#11618`, MSG91) is NOT here — deferred
  until its key moves to a wp-config constant.
- **Do NOT disable (not migrated):** all the rest — Discover engine, the photos
  gallery/lightbox/onboarding items, OTP `#11618` + CA-doc `#11815`/`#12113`,
  matches, email queue, block `#11810`, admin dashboard `#11688`, completion
  meter `#11560`, mobile-menu `#11674`, and misc site (`#11583` email activation,
  `#11842` skip username, `#11812` created-for).

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
