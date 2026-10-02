# Changelog

Earlier versions are described in the git log (`git log --oneline`), one
commit per version.

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
