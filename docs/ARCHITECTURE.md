# CAShaadi UI — System Architecture

How the CAShaadi custom code becomes **one coherent plugin** instead of 73 WPCode
snippets — same functionality, the new member-area UI, and no re-created bloat.

Grounded in: the full WPCode export (73 snippets, analysed — see the system model),
the approved 7-screen design, and a read-only pass over the live staging member area
(wizard, profile, Discover) on 2026-08-29.

---

## 1. What the bloat actually is

The problem was never "too many features." It is **structure**: 64 PHP + 5 CSS + 2 JS
+ 1 HTML + 1 text snippets, each self-contained, each re-declaring shared logic and
each hooking WordPress independently. Concretely, from the export:

1. **Duplicated helpers.** "Is this user premium?" is re-implemented at least six
   times — `csm_ck_is_premium` (#11795), `csm_ps_is_premium` (#11801),
   `csm_pv_is_premium` (#11811), `csm_rv_is_premium` (#11807), plus inline PMPro
   checks in #11614, #11620, #11675. Same for "gender field id", "profile age",
   "phone verified", "first name".
2. **Stacked filters on one hook.** Three snippets filter `bp_core_fetch_avatar_url`
   for three different reasons — private-photo blur (#11770), photo-request reveal
   (#11798), NSFW mask (#12119). They run in undefined order and can override each
   other. The correct avatar to show is whatever the last filter happened to say.
3. **Styling injected from PHP/JS.** Most UI ships as inline `<style>`/`<script>`
   echoed on `wp_footer` (#11844, #11682, #11681, #11797, #11621, #11619, …) or, in
   the wizard, injected by JS. Nothing is a real enqueued asset, so nothing is
   cacheable, versioned, or overridable.
4. **Scattered config.** Field IDs (277 phone, 286 age, 299 gender, 228 height, 496
   bio, 586 DOB, 484 ICAI), the group order `[1,7,6,4,9,8,10]`, PMPro level checks,
   and option keys are hardcoded across ~20 snippets. Change one field id → hunt
   through twenty places.
5. **One-off tools living as runtime hooks.** The avatar HD rebuild scanner (#11814),
   legacy avatar import (#11861), and several backfills hook `admin_init` and run
   guard checks on every admin request forever, though they are one-time migrations.
6. **Secrets in code / DB.** MSG91 token hardcoded (#11618); OpenAI key in the
   `csm_av_options` option (#11815, #12119). No single accessor, no constant.
7. **Redundant progress/gate UIs.** Profile progress exists four ways — meter
   (#11560), step indicator (#11629), uniform button+progress (#11844), and the
   wizard's own bars (#12132) — the source of the earlier "double progress bar."

> Inventory corrections found during analysis: **#11810 "Block User"** (a full
> blocking subsystem + DB table) is missing from the blueprint's module map;
> **#12113** = CA-verify auto-check cron + verified email; **#12119** = photo NSFW
> moderation sweep. All three are now placed below.

---

## 2. Target shape

One plugin, autoloaded, three layers: **core** (shared truth) → **modules** (thin
features that call core) → **assets/app-shell** (the new UI). Custom tables and
one-off tools are pulled out of the request path.

```
cashaadi-ui/
  cashaadi-ui.php              bootstrap: define constants, require core, register modules
  includes/
    core/                      the single source of truth — every module depends on this
      Config.php               field IDs, group order, PMPro level(s), option keys, endpoints
      Membership.php           is_premium(), level_label()            [kills the 6 duplicates]
      Verification.php         phone_verified(), ca_verified(), status [11618/11682/11701/11815]
      Photos.php               ONE avatar/photo-URL resolver: composes blur + request-reveal + NSFW
      Events.php               unified event log (csm_log_event)
      Secrets.php              MSG91 / OpenAI keys from wp-config constants (option fallback)
      Assets.php               enqueue registry — NO module echoes inline <style>/<script>
    modules/
      profile-edit/            wizard, reskin, mobile fixes, field logic + guards
      verification/            phone OTP, CA-doc AI + cron, badge, OTP-in-checklist
      photos/                  multi-upload, HD, default avatar, privacy/blur, request, NSFW
      discover/                tray UI, like/pass, routing, quota banner, entry points, filters
      matches/                 requests-sent tab, match emails
      premium/                 profile gate, contact gate, upsell, checkout, leads, insights, visitors
      messaging/               block user (+ Better Messages guard)
      email/                   reminder queue engine + admin monitor
      analytics/               GA4, Meta Pixel (reg + purchase), OG image, avatar alt
      admin/                   sales dashboard
      site/                    comments off, hide sidebar, mobile-menu fix, support footer, etc.
      app-shell/               NEW: bottom nav, top bar, member-screen reskins  ← the design layer
    db/
      Migrator.php             ONE versioned installer for all custom tables
    tools/                     one-off migrations as WP-CLI / gated admin actions (not runtime hooks)
  assets/
    css/ tokens.css            the design system (blue #2c5b8c / green #6f9f2e / Newsreader+Hanken)
    css/ <module>.css          real, versioned stylesheets
    js/  <module>.js           real, versioned scripts
```

The **mu-plugin `cashaadi-discovery.php`** stays the Discovery *engine* (candidate
selection/filtering); the `discover/` module owns only its UI and AJAX. We wrap it,
we don't reabsorb it, unless we later decide to.

---

## 3. Core layer — the anti-bloat lever

Everything that is duplicated today collapses into `includes/core/`:

| Core service | Replaces (examples) | Why it matters |
|---|---|---|
| `Config` | hardcoded field/group/level/option keys in ~20 snippets | one edit, not twenty; no drift |
| `Membership::is_premium()` | 6+ re-implementations | gating is consistent and testable |
| `Verification` | status checks in #11618/#11682/#11701/#11815/#12113 | one definition of "verified" |
| `Photos::display_url()` | 3 competing `bp_core_fetch_avatar_url` filters | one deterministic resolver, composed in order: block → NSFW mask → privacy blur → request reveal |
| `Assets` | inline `<style>`/`<script>` across ~15 snippets | cacheable, versioned, theme-overridable, CSP-friendly |
| `Secrets` | MSG91 in code, OpenAI in DB option | rotate once, read everywhere |
| `Migrator` | per-snippet `dbDelta` on load | tables install/upgrade once, on version change |

A module becomes: *subscribe to hooks → ask core → render an enqueued asset.* Thin.

---

## 4. Module map (all snippets placed)

**profile-edit** — 12124 reskin (CSS), 12132 wizard (JS), 11641 mobile fixes ✅ *(done, v0.2.0)*;
11629 + 11844 step/progress → **retire** (superseded by wizard); 11560 completion meter →
reconcile into the profile screen; 11624 partial-save, 11797 height guard, 11621 gender lock,
11625 email lock, 11611 age sync, 11760 monthly age refresh + DOB visibility, 11619 bio plain,
11812 created-for, 11842 skip-username, 11690 photo-step button, 11838 onboarding photos.

**verification** — 11618 phone OTP 🔴, 11682 OTP in checklist, 11815 CA-doc AI, 12113 auto-check
cron + email, 11701 verified badge.

**photos** — 11822 multi-upload, 11813 HD, 11617 default avatar, 11770 private/blur, 11771
lightbox, 11798 photo request, 12119 NSFW sweep; **tools:** 11814 rebuild scanner, 11861 legacy
import.

**discover** — 11602 tray UI, 11601 like/pass, 11630 like→request routing, 11599 refill engine,
11600 weekly reset (cron), 11675 quota banner, 11680/11681 entry points, 11556 gender filter,
11605 login redirect, 11801 premium search.

**matches** — 11637 requests-sent tab, 11694 match emails.

**premium** — 11620 profile gate, 11614 contact gate, 11579 upsell, 11795 checkout hygiene,
11796 intent leads, 11807 rejection insights, 11811 visitors, 11821 view email, 11626 pricing
redirect, 11581 checkout CSS, 11696 noindex.

**messaging** — 11810 block user.

**email** — 11732 queue engine, 11733 monitor.

**analytics** — 12112 GA4, 12084 Meta Pixel reg, 12091 Meta Pixel purchase, 12073 OG image, 11697 avatar alt.

**admin** — 11688 sales dashboard.

**site** — 11242 comments off, 11612 hide sidebar, 11674 mobile-menu JS, 11691 support footer,
11638 WC greeting, 11583 email activation, 11582 caps-lock, 11696 (see premium), 11241/11252
leftovers → **drop after confirm**.

---

## 5. The new UI (app-shell) — what's net-new vs. restyle

From the read-only staging pass: the **wizard already is the design language**
(centered card, segmented progress, Newsreader serif labels, blue/green, green CTA).
Every *other* member screen is still stock BuddyX. So the UI work is:

- **app-shell (build once):** bottom nav (Discover · Matches · Messages · Profile),
  top bar (serif title + notifications bell), hide BuddyX subnav/sidebar on member
  screens, keep the global site header. Hidden on the focused wizard.
- **restyle (per screen, reuse tokens):** Profile (completion ring + verified badge +
  photo grid), Matches (Received/Sent/Matches tabs over Friends), Messages (inbox +
  thread), Settings (grouped list), Notifications (bell loop), Discover (card UI over
  the tray — today a plain grid + quota banner).

`tokens.css` centralises the design system that currently lives as `var()` fallbacks
inside the wizard JS.

---

## 6. Migration order (staging-first; each step: move → verify parity → disable snippet → commit)

1. **Core scaffolding** — Config, Membership, Verification, Assets, Secrets, Migrator. No behavior change.
2. **profile-edit** — *(UI done)* then field logic/guards. ← we are here
3. **site** misc (low risk).
4. **analytics** (low risk, easy parity check).
5. **verification** — rotate MSG91 into a constant first.
6. **photos** — resolve the avatar-URL filter stack into `Photos`.
7. **discover** UI (engine stays in the mu-plugin).
8. **matches**.
9. **premium gating** — revenue-critical; explicit test checklist before cutover.
10. **email engine, admin, block**.
11. **app-shell + screen restyles** — parallel design track once the shell lands.
12. **Retire WPCode Lite** when it holds nothing we need.

---

## 7. Governance — the rules that keep it from re-bloating

- No inline `<style>`/`<script>` echoed from PHP — everything through `Assets`.
- No duplicated helper — shared logic lives in `core/`, nowhere else.
- No hardcoded field id / level / option key — only via `Config`.
- One filter per hook per concern — composed in core (avatar URL especially).
- Secrets only from `wp-config` constants, read via `Secrets`.
- One-off migrations are **tools**, not runtime hooks.
- Every custom table goes through `Migrator`.
- Nothing is deleted from WPCode until its plugin replacement is verified on staging.

> Open items to confirm on the live install: exact PMPro premium level id(s), enabled
> BuddyPress components, and whether the mu-plugin `cashaadi-discovery.php` should
> eventually fold into `discover/` or stay separate.
