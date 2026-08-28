# CAShaadi UI — Handoff for local Claude Code

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
