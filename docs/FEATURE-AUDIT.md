# CAShaadi — Feature Audit & Screen → Backend Map

Purpose: pin every screen in the approved design to the **real backend feature**
that already powers it, so we build on what exists and invent nothing.

Legend: ✅ exists & working · 🟡 exists, needs UI work · 🔨 needs building ·
❓ verify against live install

---

## Platform stack (as observed in wp-admin)

| Layer | What | Notes |
|---|---|---|
| CMS | WordPress | PHP 8.x, Hostinger |
| Community | **BuddyPress** (Nouveau template) | the member-area engine |
| Theme | **BuddyX-Child** | + Kirki theme options |
| Membership / paywall | **Paid Memberships Pro (PMPro)** | `pmpro-variation_1` body class; "complete profile to browse" gate |
| Media / photos | **rtMedia** | profile photo galleries |
| Payments | **PhonePe PG**, **WooCommerce** + Payments | subscription / paid plans |
| Page builder | Elementor | marketing pages |
| Snippets (current) | **WPCode Lite** | ← to be migrated INTO this plugin |
| Custom code | child-theme functions.php, mu-plugin `cashaadi-discovery.php` | |
| Verification | Mobile OTP via **MSG91**; ICAI document (xProfile field 484) | |
| SEO/analytics | Site Kit | |

> ❓ To confirm live: exact active-plugins list, which **BuddyPress components**
> are enabled (Extended Profiles / Friends / Private Messaging / Notifications /
> Activity / Member Types), and the PMPro levels.

---

## Screen → backend feature map

### 1. Discover  (`Main` artboard)
- **Backend:** custom Discovery module (mu-plugin `cashaadi-discovery.php`) over the
  BuddyPress Members directory + xProfile filters. 🟡
- **Build:** a proper page template + query for the swipe/browse card; wire
  like/pass to the connection (Friends) request action. Filters from xProfile
  (city, qualification, age).
- **Verify:** how Discovery currently selects/filters candidates.

### 2. Matches  (`Matches` artboard — Received / Sent / Matches tabs)
- **Backend:** **BuddyPress Friends** component (renamed "Connections/Matches").
  Friend requests = "Received"; pending outgoing = "Sent"; confirmed = "Matches". ✅
- **Build:** restyle the three friends screens into the tabbed card UI; Accept /
  Decline call the native friendship accept/reject actions.

### 3. Messages  (`Messages` artboard)
- **Backend:** **BuddyPress Private Messaging**. ✅
- **Build:** restyle inbox list + thread view; keep native send/compose.

### 4. Profile  (`Profile` artboard — own)
- **Backend:** xProfile field groups + rtMedia photos + completion gate (#11620)
  + ICAI/OTP verification. ✅
- **Build:** restyle the member front/profile screen: completion ring, verified
  badge, photo grid (rtMedia), section status, entry to wizard & settings.

### 5. Settings  (`Settings` artboard — incl. Delete account)
- **Backend:** BuddyPress **Settings** (General / Notifications / Profile
  Visibility / Delete Account) + PMPro (membership/billing). ✅
- **Build:** restyle into grouped list; **Delete account** = native
  `settings/delete-account`; add Membership/Billing row → PMPro account page.

### 6. Notifications  (`Notifications` artboard)
- **Backend:** **BuddyPress Notifications**. ✅
- **Build:** restyle the notifications loop; reachable via a bell (top bar).

### 7. Completion wizard  (`Wizard` artboard)
- **Backend:** xProfile edit + partial-save (#11624) + age-sync (#11611) +
  completion gate (#11620). ✅ **Already built** (the AJAX no-reload flow now on staging).
- **Build:** migrate the existing JS/CSS into this plugin; single progress bar.

---

## The app shell (new, cross-cutting)  🔨
- **Bottom nav bar** (Discover · Matches · Messages · Profile) — a real template
  partial injected on member pages, hidden on the focused wizard.
- **Top bar** per screen (serif title + bell for Notifications).
- Hide the default BuddyX sidebar/subnav on member screens; keep global site header.
- Mobile-first; desktop places the same nav on the side/top.

---

## Migration out of WPCode
Snippets to fold into this plugin (then deactivate in WPCode):
- `#12124` Profile Edit reskin (Phase 0 CSS)
- `#12132` Profile Edit wizard (Phase 3 AJAX JS, current)
- OTP verify box (`#csm-otp-box`) — ❓ locate its snippet
- Completion gate / age-sync / partial-save / height guard helpers
  (#11620 / #11611 / #11624 / #11797 / #11641 / #11621) — ❓ inventory & decide
  which move into the plugin vs stay.

> Golden rule (from project constraints): **no blind live edits**; everything
> ships to **staging** first, reviewed, then promoted. DB-affecting changes need
> an explicit decision + backup.
