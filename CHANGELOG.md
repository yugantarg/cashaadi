# Changelog

Earlier versions are described in the git log (`git log --oneline`), one
commit per version.

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
