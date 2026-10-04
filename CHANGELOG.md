# Changelog

Earlier versions are described in the git log (`git log --oneline`), one
commit per version.

## 1.73.0 — 2026-10-04
- **Member journey data** (`Analytics\Journey`). Read-only: nothing ranks or
  decides off it.
  - New `wp_csm_member_day` table, written nightly at 02:30 IST, one row per
    member per day:
    - what happened: active, profiles served (with their average match points
      and popularity), likes and passes given, likes received, requests sent
      and received, matches, messages sent and received, new profile viewers;
    - what they were that night: gender, premium, quota, photos, verified,
      paused.
  - New events:
    - `purchase`: the order plus the member's journey totals to date, their
      channel, and what sent them to the pricing page;
    - `pricing_view`: each pricing-page visit (at most hourly per member),
      with the referring screen or `?src=`;
    - `match_made`.
  - The event log keeps these types, plus `account_deleted` and
    `once_field_changed`, forever. They were previously pruned after 180 days.
  - WP-CLI: `wp csm journey backfill --days=90`, `wp csm journey nightly`,
    `wp csm journey export member_day|events|impressions`.

## 1.72.0 — 2026-10-04
- **+5 profiles a week for adding a photo, +5 for an approved CA
  verification** (`Discover\Boost`):
  - Applied through `csm_tray_size`, so it counts from the moment it's earned.
  - The amount is set by option `csm_boost_per`.
- Discover: a "Get N more profiles this week" card above the profiles. Each row
  ticks off when done; an upload still in review says so. The card ends with
  "Verifying and adding a photo increase your chances 5 times."
- Ranking (`points-v1`): profiles get +4 for having a photo and +3 for being
  verified, so complete profiles are shown more. Both points appear in the
  impression log.
- "While you wait" suggestions mention the extra profiles.

## 1.71.0 — 2026-10-03
- **Gender and Date of birth can each be changed once** (`ProfileEdit\OnceFields`).
  - The change is made in the profile editor, after a warning that it can never
    be changed again. After that the field is read-only.
  - The rule is enforced on the server on every save path. Admins are exempt.
  - Clearing either field is refused.
  - A gender change clears pending Discover queue entries on both sides.
  - Changes are logged as the `once_field_changed` event.
- Sign-up form: "Gender" is now labelled "Gender of the bride / groom".
- Discover end-of-week screen:
  - "While you wait" shows up to three next steps: requests waiting, add photos,
    get verified, finish profile.
  - The free and Premium numbers are now correct for women (15 / 50).
- "Pause my profile" is offered to members in their first 7 days, and always
  to anyone already paused. On the delete screen it appears first, as a card.

## 1.70.0 — 2026-10-03
- Free women get **15 profiles a week** from Monday 2026-10-05 (IST)
  (`Discover\FreeFemale`).
  - Applied through `csm_tray_size`, so Discover, its banner and the engine
    all agree. Premium women keep 50. Men are unchanged.
  - The date and number can be changed with the `csm_free_female_from` and
    `csm_free_female_quota` options.
- One-time "Now 15 profiles a week" popup for free women who joined before
  that date, shown on their first app page from that date.
- Pricing page, logged-in women only, from that date: the free column and the
  intro say 15 a week. The Premium line reads "50 … vs 15 on the free plan".
- The new-member tour title uses the member's actual weekly number.

## 1.69.11 — 2026-10-03
- Fix: members who haven't verified their email were missing from the Sales
  Dashboard list. They have no role yet, and the "not administrator" role
  filter dropped them. Admins are now excluded by ID instead.

## 1.69.10 — 2026-10-03
- Sales Dashboard user list: administrators and deleted (closed) accounts
  are excluded, so its unfiltered total matches the Registered tile.

## 1.69.9 — 2026-10-03
- The headline female count is shown as "(51F)".

## 1.69.8 — 2026-10-03
- Sales Dashboard: each of the five headline numbers (DAU, WAU, MAU,
  Registered, Paid users) shows its female count in brackets.
- Registered now reads "excludes N deleted".
- Removed the "paid > ₹0 on WooCommerce" line.

## 1.69.7 — 2026-10-03
- Paid users is now counted from WooCommerce: customers with an order above
  ₹0 after refunds. It no longer uses the PMPro premium level.

## 1.69.6 — 2026-10-03
- Lifetime revenue and "ever paid" now come from WooCommerce orders, where
  Premium is actually bought: completed and processing orders, net of
  refunds, excluding admins. PMPro's order table is used only if WooCommerce
  is absent.

## 1.69.5 — 2026-10-03
- Paid users tile: a "Lifetime revenue" line, the sum of successful PMPro
  orders above ₹0. Admin orders are excluded.

## 1.69.4 — 2026-10-03
- Sales Dashboard: new **Registered** tile (excludes admins and deleted
  accounts; also shows how many are activated) and **Paid users** tile
  (premium now; also shows how many ever paid more than ₹0), next to
  DAU/WAU/MAU.
- The DAU chart and "Sign-ups by channel" are collapsed and expand on click.
  The channel table stays open after you pick a date range.

## 1.69.3 — 2026-10-02
- The account-deletion record stores the exact sign-up time, the deletion
  time (both IST) and the minutes in between.

## 1.69.2 — 2026-10-02
- Account deletion record now also stores the member's stage: whether they
  finished onboarding, photos, CA verification, profiles seen, likes sent and
  received, times shown, requests in, matches, and days since last active.

## 1.69.1 — 2026-10-02
- The delete screen and the confirmation email no longer mention data
  retention or erasure requests. That is covered by the Privacy Policy
  only (owner).

## 1.69.0 — 2026-10-02
- "Delete my account" now **closes** the account instead of erasing it
  (`Settings\Closed`):
  - All data is kept.
  - The account is hidden everywhere and treated as blocked by every member.
  - It can no longer log in, and its roles are removed.
  - Its email, login and profile slug are replaced with placeholder values, so
    the same person can sign up again with the same email and get a fresh
    account. The original values are kept in `csm_closed_*` user meta.
  - Full erasure is done on email request, by an admin deleting the user in
    wp-admin.
  - The screen text and the confirmation email have been updated to match.

## 1.68.5 — 2026-10-02
- Account deletion: the reason a member gives is kept. The deletion cleanup
  used to delete that member's event-log rows, including the
  `account_deleted` record written a moment before, so every reason was lost.
  The record now also stores gender, days since joining, channel and premium.

## 1.68.4 — 2026-10-02
- Attribution: a new `linkedin_ads` channel for sign-ups from LinkedIn ads
  (utm_source=linkedin with a paid medium). These were previously counted as
  plain Social.

## 1.68.3 — 2026-10-02
- LinkedIn sign-up conversion switched on: conversion ID 31535817.

## 1.68.2 — 2026-10-02
- Register form: the password hint now says "Use at least 8 characters."
  instead of WordPress's default (twelve characters and symbols).
- "Confirm new password" is relabelled "Confirm password (required)" and
  spaced like the other fields.

## 1.68.1 — 2026-10-02
- LinkedIn Insight Tag (partner 10988017) in the footer for logged-out
  visitors only. It is never printed in wp-admin, on member-area pages, or on a
  "staging" host.
- LinkedIn sign-up conversion on the registration confirmation page (the
  `bp_complete_signup` request), once per signup. It fires only when
  `CSM_LI_SIGNUP_CONVERSION_ID` is a numeric ID. That ID is empty by default, so
  nothing fires yet.
