# CAShaadi UI

A WordPress plugin that gives the **CAShaadi** member area (BuddyPress) a
premium, mobile-first, Hinge/Bumble-style interface — a persistent bottom-nav
app shell, a focused profile-completion wizard, and restyled Matches, Messages,
Profile and Settings screens.

**It is a UI layer only.** It never writes to the database and never changes
saving, validation, the completion gate, age-sync, membership, or verification
logic. BuddyPress + PMPro + rtMedia remain the engine; this plugin restyles and
restructures the front end on top of them.

## Why a plugin (not WPCode snippets)
Version control, diffs, safe rollback, review, and a reliable `git pull` deploy
to staging — replacing hand-pasted snippets in the browser editor.

## Structure
```
cashaadi-ui.php            main plugin file (enqueues, scoped to member pages)
assets/
  js/profile-wizard.js     the AJAX no-reload completion wizard (migrated from WPCode #12132)
  css/                     app-shell + screen styles (added per roadmap)
docs/
  FEATURE-AUDIT.md         every screen mapped to its real BuddyPress feature
  ROADMAP.md               incremental build plan
```

## Deploy (staging first, always)
1. Push to GitHub `main`.
2. Hostinger hPanel → **Git** pulls the repo into
   `.../staging/wp-content/plugins/cashaadi-ui/`.
3. Activate **CAShaadi UI** on staging; deactivate the equivalent WPCode snippets.
4. Verify on staging, then promote to live.

## Ground rules (project constraints)
- No blind edits on the live site — staging first, review, then promote.
- No DB-affecting change without an explicit decision + backup.
- Validate PHP (`php -l`) and JS (`node --check`) before every commit.

