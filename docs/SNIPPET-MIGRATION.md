# WPCode → Plugin — Snippet Migration Blueprint

Source: `wpcodesnippetsexport-2026-08-29.json` — **73 snippets** (64 PHP, 5 CSS, 2 JS, 1 HTML, 1 text).
Goal: fold all CAShaadi custom code into the `cashaadi-ui` plugin (versioned, one
source of truth), then delete the WPCode snippets. Migrate **module by module**,
test each on **staging**, disable the snippet only after parity is confirmed.

> ⚠️ **Do NOT commit the raw export to the repo** — snippet #11618 contains a live
> MSG91 token. The export stays out of git; secrets move to `wp-config.php`.

---

## Security actions (do first / alongside)
- **#11618 Phone OTP** — MSG91 access token hardcoded → **rotate it**, then read it
  from a `wp-config.php` constant (e.g. `CASHAADI_MSG91_AUTHKEY`).
- **#11815 / #12113 / #12119** — OpenAI key lives in DB option `csm_av_options.api_key`
  (not in code — fine). Optionally also move to a `wp-config` constant.
- **#12084 / #12112 / #12091** — Meta Pixel / GA4 IDs: public, but tidy into config.

## Redundancy to resolve (part of de-bloat)
- Profile edit **Save & Next / progress** appears in **3 places**: our wizard
  **#12132**, plus **#11629** (Save & Next + Step Indicator) and **#11844**
  (Uniform Save & Next + Progress). These overlap and are the likely cause of the
  earlier "two progress bars." After the wizard is in the plugin, **#11629 and
  #11844 are strong deletion candidates** (verify, then remove).
- **#11241** (message after 1st paragraph, 59 b) & **#11252** (Sign Up HTML) look
  like leftovers — confirm and likely drop.

---

## Proposed plugin structure
```
cashaadi-ui/
  cashaadi-ui.php                 loader + module includes
  includes/
    profile-edit/    wizard, reskin, mobile fixes, field guards, field logic
    verification/    phone OTP, CA-doc AI verify + cron, verified badge
    photos/          multi-upload, HD, default avatar, privacy/blur, requests, NSFW sweep
    discover/        tray + UI, like/pass, refill engine, weekly reset, search, filters
    matches/         requests-sent sub-tab, match emails
    premium/         profile gate, contact gate, upsell, visitors, checkout, insights
    email/           reminder queue engine + monitor
    admin/           sales dashboard
    analytics/       GA4, Meta Pixel, OG image, SEO bits
    site/            misc site-wide tweaks
  assets/ css|js/
```

---

## Module map (all 73)

### profile-edit  (UI + field logic)
- 12124 Profile Edit reskin (CSS) — ✅ in plugin
- 12132 Profile Edit wizard (JS) — ✅ in plugin
- 11641 Profile Form Mobile Fixes (DOB select + hide Age) (CSS)
- 11629 Save & Next + Step Indicator — ⚠️ likely superseded → delete after verify
- 11844 Uniform Save & Next + Progress — ⚠️ likely superseded → delete after verify
- 11690 Photo Step Next Button (onboarding)
- 11838 Photos on Avatar Screen (onboarding)
- 11619 Bio Plain Textarea (disable rich editor)
- 11812 Created For Field
- 11842 Skip Username at Signup
- 11624 Allow Partial Profile Save 🔶 logic
- 11797 Height Input Guard 🔶 logic
- 11621 Lock Gender After Signup 🔶 logic
- 11625 Lock Account Email 🔶 logic
- 11611 Sync Age from DOB 🔶 logic
- 11760 Monthly Age Refresh + DOB Visibility Tools 🔶 logic/cron

### verification
- 11618 Phone OTP Verification 🔴 secret (MSG91)
- 11682 OTP Status in Completion Checklist
- 11815 CA Document AI Verification 🔶 OpenAI
- 12113 CA Verify Auto-Check Cron + Verified Email 🔶 cron, depends 11815
- 11701 Verified CA Badge (blue tick)

### photos
- 11822 Member Photos (multi-upload, no crop)
- 11813 Photo Resolution (HD Avatars)
- 11814 Avatar HD Rebuild SCANNER 🔶 one-off tool
- 11861 Import Legacy Avatar + Cover 🔶 one-off tool
- 11617 Local Default Avatar (remove Gravatar)
- 11770 Private Photo (Blur for non-matches) 🔶 gating
- 11771 Photo Lightbox + Privacy Notice
- 11798 Photo Request (Ask & Approve)
- 12119 Photo Moderation (NSFW) Sweep + Queue 🔶 OpenAI, enforcement OFF by default

### discover
- 11602 Discovery Tray Shortcode & UI
- 11601 Like/Pass AJAX Handlers
- 11630 Like → BuddyPress Match Request (routing)
- 11681 Discover Entry Points (Header Icon + Profile CTA)
- 11675 Discover Quota Banner
- 11680 Prominent Discover Tab (Mobile) (CSS)
- 11599 Tray Refill Engine 🔶 core
- 11600 Weekly Reset Trigger 🔶 cron
- 11801 Premium Partner Search
- 11556 Gender-Based Filtering (opposite gender only)
- 11605 Member Login Redirect to Discover

### matches
- 11637 Matches: "Requests Sent" Sub-Tab
- 11694 Match Emails (native, all paths)

### premium
- 11620 Profile Gate (Blur+CTA / Directory Redirect) 🔶 core gating
- 11614 Contact Details Gate (Phone + Email, Premium)
- 11795 Premium Checkout Hygiene
- 11796 Premium Intent Leads
- 11807 Rejection Visibility & Profile Insights (Premium)
- 11811 Profile Visitors (Who Viewed Me)
- 11821 Profile-View Email (batched, 1/day)
- 11579 Upgrade to Premium Button
- 11626 Redirect /pricing/ → /membership-pricing/
- 11581 Membership Checkout Styling (CSS)
- 11696 Noindex PMPro member pages (SEO)

### email
- 11732 Reminder Email Queue (Engine) 🔶 core, 26 KB
- 11733 Reminder Email Monitor (Admin)

### admin
- 11688 Sales Admin Dashboard

### analytics
- 12112 GA4 events (sign_up + purchase)
- 12084 Meta Pixel + CompleteRegistration
- 12091 Meta Pixel Purchase (PMPro Premium)
- 12073 Default Social Share Image (OG + Twitter)
- 11697 Avatar alt text (SEO)

### site (misc — confirm each; some may just stay)
- 11612 Hide Recent Posts Sidebar
- 11242 Completely Disable Comments
- 11674 Fix Mobile Menu Double-Toggle (JS)
- 11691 Support Email Footer
- 11638 WC My-Account Greeting Reword
- 11583 One-Click Email Activation + Auto-Login 🔶 auth flow
- 11582 Hide Caps Lock Warning (CSS)
- 11581 (see premium)
- 11241 message after 1st paragraph — leftover? drop
- 11252 Sign Up (HTML) — leftover? confirm
- 11560 Profile Completion Meter 🔶 (overlaps wizard progress — reconcile)

---

## Migration sequence (risk-ordered)
1. **profile-edit UI** (have wizard/reskin) + retire #11629/#11844 redundancy. *(low risk, immediate de-bloat + fixes the double progress bar)*
2. **profile-edit field logic/guards** (#11624/#11797/#11621/#11625/#11611). *(higher risk — save/gate behaviour; test each)*
3. **verification** (rotate MSG91 first). 
4. **premium gating** (#11620 etc — revenue-critical, careful staging tests).
5. **photos**, **discover engine**, **matches**, **email engine**, **admin**, **analytics**, **site**.

Each step: move code into the module → activate plugin path on staging → confirm
identical behaviour → **deactivate the snippet** → commit. When all of a snippet's
work lives in the plugin and is verified, delete the snippet. When WPCode holds
nothing we need, remove WPCode Lite itself.

> Rule: no snippet is deleted until its plugin replacement is verified on staging.
> Revenue/gating and verification snippets get an explicit test checklist before cutover.
