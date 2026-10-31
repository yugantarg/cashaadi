# Changelog

Earlier versions are described in the git log (`git log --oneline`), one
commit per version.

## 1.90.92 — 2026-10-31

- Discover ranks unfinished profiles lower (owner, audit): +8 points when every required profile field is filled (qualification, occupation, mother tongue, date of birth, city …), on top of the existing +10 for a photo and +6 for verification. Nobody is removed from Discover; an unfinished, photo-less profile now starts 18 points behind a finished one with a photo. Tunable through `csm_rank_points` (`complete`).

## 1.90.91 — 2026-10-31

- Profile completion reminders say exactly what is missing (owner): a line at the top, "Still missing on your profile: a profile photo, your qualification and your mother tongue.", one sentence on why, and a button (Add my photo / Complete my profile). Subject: "Add a photo to your CA Shaadi profile" when only the photo is missing, otherwise "A few details are missing from your CA Shaadi profile". The rest of the email (the template in WordPress → Emails) is unchanged.

## 1.90.90 — 2026-10-31

- Fix (audit): names with an apostrophe showed as "D&#039;cunha" on Discover and Requests cards. Display names now turn quote entities back into characters (only quotes; HTML output still escapes the name).

## 1.90.89 — 2026-10-31

- Login, lost-password and reset-password screens restyled in CA Shaadi's look (CSS only; WordPress's forms and checks unchanged; logo links to the home page).
- Password resets recorded (pw_reset_requested, pw_reset_done). `wp csm resets [--days=30]`: per day, new sign-ups, resets asked for, resets completed, and reset links asked for but never used (read from WordPress, so it also covers the days before this version).

## 1.90.88 — 2026-10-31

- Sign-up: an email that already has an account now gets "You already have an account with this email." with Log in and Forgot password? links under the field, instead of "Sorry, that email address is already used!" (78 people in two weeks). Recorded in the funnel as "already has an account".

## 1.90.87 — 2026-10-31

- Sign-up funnel beacon also checks for the Join screen's form (its conditions named only the BuddyPress form). The live data shows opens and starts were still being recorded after 28 Oct, so no gap; this makes it explicit.
- `wp csm funnel [--days=7]`: the funnel by step with the share kept from the step before, problems (rejected form, failed code email, resend, wrong code), onboarding steps answered, and the same by day.

## 1.90.86 — 2026-10-31

- Fix: Sales Dashboard showed members with no photo as Complete. It asked BuddyPress whether a member has an avatar, which our placeholder avatar makes true for everyone. It now uses the setup wizard's check (a gallery photo, or a real uploaded avatar file), so "Photo pending" appears again.

## 1.90.85 — 2026-10-31

- Sales Dashboard: "SMS pending" removed (owner: phone verification is not required of anyone). A member with email, profile and photo done now shows Complete; the status filter no longer offers SMS pending. The reminder emails that read the same status follow it.

## 1.90.84 — 2026-10-30

- Survey invitations only for engaged members (owner): joined at least 7 days ago, acted on at least 3 profiles (request, pass or save), and used the app on at least 2 different days. Still never to anyone offered the ₹99 boost.

## 1.90.83 — 2026-10-30

- Price notices made short (owner: "they can see the pricing page and understand"). Pricing page: "New prices from 1 November", one line ("Buy now at the old prices." / "Use the code OLDPRICE to get the old prices. They will not be available again.") and the countdown; no amounts or buttons in the box (the cards show the prices, and from 1 November Buy Premium and Buy Gold apply the code). Discover banner: "Prices go up on 1 November. Buy now at the old prices." / "Prices have gone up. Use the code OLDPRICE to get the old prices.", countdown, "See plans".

## 1.90.82 — 2026-10-30

- Price notices cover both plans (owner): the Discover banner ("Prices go up on 1 November: Premium ₹4,499, Gold ₹14,999. Buy now at the old prices…" / "New prices… You can still get the old prices… with code OLDPRICE", button "See plans at the old prices" to the pricing page), the pricing-page box (both prices, "these prices will never be available again", Buy Premium at ₹1,499 and Buy Gold at ₹7,999, which apply the code from 1 November). The price email is unchanged (owner: already sent).

## 1.90.81 — 2026-10-30

- Gold uses the OLDPRICE system (owner, replaces 1.90.80's fixed 5 November date): ₹14,999 from 1 November; OLDPRICE gives ₹7,000 off Gold (₹7,999) until the same per-member deadline (later of 5 November and three days after first seeing the offer), members who joined before 1 November only, once per member across both plans. Premium members can use it for Gold, with the upgrade credit. No email, banner or box for Gold: the Gold card says "Goes up to ₹14,999 on 1 November" before then and "₹7,999 with code OLDPRICE, for a limited time" while it works, and Buy Gold then applies the code. Admin preview shows Gold at ₹14,999.

## 1.90.80 — 2026-10-30

- Gold goes to ₹14,999 after 11:59 pm on 5 November (price filter on the Gold product; site cache purged at the switch). No code and no notice: until then the pricing page's Gold card says "₹7,999 till 5 Nov, then ₹14,999" (not shown to Gold members). The Gold popup test reads the live price instead of a fixed ₹7,999. Upgrade credit unchanged (what the member paid for Premium).

## 1.90.79 — 2026-10-30

- Privacy fix: survey drafts were saved under one browser key, so a second account on the same browser could see the first member's answers and their name-and-photo permission. Drafts are now saved per member (an opaque per-member key), the permission switch is never saved (chosen fresh each time), and the old shared key is removed on load.

## 1.90.78 — 2026-10-30

- Survey follow-ups (owner): after "Not now", a one-button note says it is on the Profile tab (the popup never comes back). Profile tab, Membership: "Earn ₹600: answer a short survey" for invited members who have not answered ("Continue" once started). Discover: a "Continue the survey" banner for members who opened it and left; × hides it for good. Answers in progress are kept in the browser, so nothing is lost on leaving. `csmConfirm` gains `alert` (one button).

## 1.90.77 — 2026-10-30

- Survey in batches (owner): nobody is invited until `wp csm survey invite <n>`; the next n eligible members who open the app then see the popup, and only invited members can answer. `wp csm survey batches`: per batch invited, saw the popup, answered, testimonials, response rate.
- Feature ratings and problems are shuffled per member ("Something else" stays last); the order shown is saved with each answer.

## 1.90.76 — 2026-10-30

- Survey copy (owner): "Help improve CA Shaadi and earn ₹600" · ₹300 for the survey · ₹300 for a testimonial · CA Shaadi Cash, usable anywhere on CA Shaadi on top of any other discount or offer. The Submit button shows the amount ("Submit and earn ₹300", ₹600 once a testimonial of 60+ characters has the permission switch on).
- Against random filling: refused if answered in under 40 seconds (signed start time) or if the text is not words (improve: 15+ characters, 3+ words; testimonial: 60+ characters, 8+ words; not one letter repeated). Flagged, not refused: every feature rated the same, or under 2 minutes; `wp csm survey` leaves flagged answers out unless --all.

## 1.90.75 — 2026-10-30

- Survey question 1 is a typed amount in rupees ("How much have you paid in total for matrimony services so far?", 0 if never) instead of ranges (owner). `wp csm survey` groups the amounts into bands and gives the median and average of those who paid.

## 1.90.74 — 2026-10-30

- Survey reworked (owner): "who is involved" removed (Created for is the proxy); each feature rated 1–5 for importance; up to 2 biggest problems; a testimonial with a switch for permission to use name and photo. Reward is CA Shaadi cash, not a boost: 300 for answering, another 300 for a testimonial (30+ characters) with permission, posted to the cash ledger (shown on /refer/ as "For answering our survey" / "For your testimonial", reversible on the admin page). Not offered to anyone ever offered the ₹99 boost. Popup and screen say both amounts plainly. `wp csm survey [--text]` lists average importance, problems, NPS, testimonials (with permission flag), split by self / parent, gender and plan.

## 1.90.73 — 2026-10-30

- Member survey at /survey/ (owner): what members have paid for matrimony services (the price signal, no "what would you pay"), the 2 things that would help most, the biggest problem, who is involved (me / family / both), likelihood to recommend (0–10) and one thing to improve (required). 1 free profile boost on submit, once per member, first 300 answers (`csm_survey_max`; `csm_survey_on` 0 stops it). Invited by a one-time popup, second in line after the ₹99 boost, for members past their first two days. `wp csm survey [--text]`: each answer by who is involved, gender and plan, NPS, and the improvement answers.

## 1.90.72 — 2026-10-30

- Dashboards cut to key numbers (owner: "I just want the data to be tracked ... I will ask you to give me the command"). Sales Dashboard: activity tiles (DAU, WAU, MAU, registered, paid), then one table per product (on the plan now, people who bought and revenue in 30 days and all time), the email-failure alert and the member table. Sign-ups by channel, the sign-up funnel, conversion, experiments and the price-notice tables leave the page; all still tracked. Emails page: status and campaigns only; per-email numbers and failures are `wp csm emails [--days=30]`. `wp csm oldprice status` adds the notice's seen / clicked / closed, email opens and clicks, and OLDPRICE purchases.

## 1.90.71 — 2026-10-30

- Price notice tracking (owner): the Discover banner and the pricing-page box record who saw them (once a day), clicked their button and closed the banner (`oldprice_notice` events, `csm/v1/oldprice/ev`). Sales panel: "Premium price notice" table, banner / box / email (saw, clicked, closed, in members), with purchases using OLDPRICE and Premium bought since 30 Oct.

## 1.90.70 — 2026-10-30

- Older profile gallery: another member's photo opens at the large size, not the original upload (owner). A member still opens their own originals.

## 1.90.69 — 2026-10-30

- Fix: `wp csm image-sizes slim` and `wp csm oldprice setup|status|extend` failed with "Too many positional arguments" (WP-CLI did not read their arguments from the docblock); arguments now declared when the commands are added.

## 1.90.68 — 2026-10-30

- Member photo uploads make only the thumbnail, medium and large copies the app uses, instead of ~17 (theme, Elementor and WooCommerce sizes). Admin uploads unchanged. `wp csm image-sizes slim [--yes]` removes the unused copies already made for member photos (counts only without --yes). Owner: too many inodes.

## 1.90.67 — 2026-10-30

- Emails page: "Paid within 7 days" per email: members who bought anything in the 7 days after opening it (a click counts as an open), and what they paid.
- Purchase events record the coupon codes used (e.g. oldprice).

## 1.90.66 — 2026-10-30

- One "Emails" page under Sales Dashboard replaces Reminder Emails, Email delivery and Email campaigns in the menu (owner: "complicated and not useful"). It shows: is email working (sending, provider, sent today of the cap, failures in 24 h, waiting, on hold); each kind of email over 30 days (sent, opened, clicked, unsubscribed, failed, last sent; dated types grouped under one name); campaigns with Stage, Release and Preview; failures in the last 7 days and why. The older screens stay reachable from links at the bottom for settings.

## 1.90.65 — 2026-10-30

- Gold upgrade credit reads what the member paid for Premium from their latest paid WooCommerce order (after any coupon), not PMPro's record, which may carry the level's price. ₹1,499 buyers (old price or OLDPRICE) and ₹4,499 buyers are each credited on their own price; no order (granted by hand): ₹1,499.

## 1.90.64 — 2026-10-30

- Pricing page before 1 November: a "New prices from 1 November" box (₹4,499 from then, the reason, ₹1,499 until then, OLDPRICE from 1st to 5th November for members who joined before 1 November, never again at ₹1,499) with a countdown to the change and Buy Premium at ₹1,499; the Premium card notes "Goes up to ₹4,499 on 1 November". Shown to free members and signed-out visitors. Admin preview `?oldprice=soon`; the Discover banner's preview link uses it.

## 1.90.63 — 2026-10-30

- Fix: `?oldprice=preview` showed nothing to an admin on a paid plan (the offer is hidden from Premium and Gold). Preview now always shows the banner and pricing box, and stamps nothing.

## 1.90.62 — 2026-10-30

- Discover: a banner for members who can still get Premium at ₹1,499 (owner). Until 1 November: "Premium price goes up to ₹4,499 on 1 November. Get it now at ₹1,499" with a countdown to the change; from then: "Premium is now ₹4,499. You can still get it at ₹1,499 with code OLDPRICE" with a countdown to the member's deadline and a button that applies the code. Close hides it for the day. Not for Premium, Gold, members who joined from 1 November, or admins (preview with `?oldprice=preview`).

## 1.90.61 — 2026-10-30

- Price email: the "3 full days" line replaced with "If you missed using this offer, email our support ... we may be able to activate the offer in certain cases" (owner). `wp csm oldprice extend <email>` gives a member 3 days from now.

## 1.90.60 — 2026-10-30

- Premium price notice email, a campaign on the Email campaigns screen (stage, then release): activated members who are not Premium or Gold. Says ₹4,499 from 1 November, OLDPRICE for ₹1,499 from 1st to 5th November, 3 full days from opening for anyone reading it after 2 November, and never at ₹1,499 again; lists Premium's features (by gender).
- Opening that email (first open or first click) starts the member's OLDPRICE days, moving them later if they had seen the offer earlier (`csm_email_opened` action from email tracking).

## 1.90.59 — 2026-10-30

- OLDPRICE only for members who joined before 1 November (owner): the box, the card note and the code itself. Signed-out visitors no longer see the box.

## 1.90.58 — 2026-10-30

- Premium goes to ₹4,499 at midnight on 1 November (price filter on the Premium product; LiteSpeed purged at the switch). Code OLDPRICE (`wp csm oldprice setup`): ₹3,000 off Premium only, once per member, refused before 1 November and after the member's deadline. Deadline: 11:59 pm IST on the later of 5 November and three days after the member first saw the offer; `csm_oldprice_open` 0 stops new deadlines. Pricing page: "New prices from 1 November" box with the reason, the code, "never available at ₹1,499 again, even with discounts", a live countdown and "Buy Premium at ₹1,499" (applies the code); the Premium card notes the code. Not shown to Premium or Gold members. Admin preview: `?oldprice=preview`. `wp csm oldprice status`.
- Gold upgrade credit uses what the member paid for Premium (₹1,499 if unknown), so ₹4,499 buyers are credited fairly.

## 1.90.57 — 2026-10-30

- Fix: a syntax error in the Sales panel's note (1.90.56).

## 1.90.56 — 2026-10-30

- Sales Dashboard: a Sales panel replaces the one-line Gold summary (owner: "clear info, not 1 figure"). Revenue and orders per product (Premium, Gold, boost pack, ₹99 boost) for today, 7 days, 30 days and all time; pricing-page visits and Buy taps against purchases; the Gold popup and ₹99 boost experiments by arm; Gold places. Paid orders only, admins left out; cached 10 minutes.

## 1.90.55 — 2026-10-30

- ₹99 boost popup moves to the front of the popup order, so a member drawn for it sees it on the first app page they open (owner). Other popups wait for a later page.

## 1.90.54 — 2026-10-30

- ₹99 boost: a fresh random draw each month for members who have still never paid (owner: willingness to pay can change). A running offer is not cut short by the new month. Draws, popups, taps and purchases go to the event log with the month; `wp csm boost-deal report` shows each month's arms (with other purchases within 30 days) and all months by segment.

## 1.90.53 — 2026-10-30

- ₹99 boost popup adds "You get it at most once a month." (owner).

## 1.90.52 — 2026-10-30

- ₹99 single boost, a throwaway deal run as an experiment (owner): members who have never paid, past their first two days, are assigned once to 'show' (share `csm_boost_deal_pct`, default 0 = off) or 'control'. 'show' gets a one-time popup ("A profile boost for ₹99"), then 48 hours in which the profile's Boost card also offers it. Hidden ₹99 product created by `wp csm boost-deal product`; only a member with a running offer can buy it, once. The boost is added (and taken back on refund) by the existing boost code. `wp csm boost-deal report`: seen, tapped, bought, by gender and "Created for" (self / parent / other family), and who in each arm paid for anything else afterwards. Last in the popup order, never on a page with another popup.
- `wp csm payers [--min-days=14]`: which markers go with paying (gender, "Created for", age, CA qualified, verified, photo, metro city, LinkedIn, paid-ad signup, first-week activity), one at a time and together (logistic regression), with an AUC check. Prints totals only. The base for an ability-to-pay score.
- Boost button says "Get 1 boost" for a single boost.

## 1.90.51 — 2026-10-30

- Gold popup test limited to a small group (owner: "say 200"): `csm_gold_intro_max` (default 200) caps the 'show' arm; once reached, nobody new is assigned, so with a 50% share control ends near the same size. `wp csm gold-intro` prints the cap.

## 1.90.50 — 2026-10-29

- "Introducing CA Shaadi Gold" popup, run as an experiment (owner). One-time; members are assigned once to 'show' (share `csm_gold_intro_pct`, default 0 = off) or 'control'; seen and "See Gold" taps are recorded; `wp csm gold-intro` compares Gold bought after assignment, shown against not shown. Lists only what Gold gives (unlimited profiles, requests first on their list, phone numbers members share unless the plan already has them, founding places left, ₹7,999 a year, Premium days credited), never the ranking boost. Not for admins, Gold members, members in their first two days, or when Gold is sold out. It is last in the popup order and only offered on a page with no other popup due.
- Dialog messages keep their line breaks (`white-space: pre-line`).

## 1.90.49 — 2026-10-29

- The new home page shows to everyone who reaches the home page, not only logged-out visitors (owner: as admin he still saw the old page). Members are still sent to Discover first; this covers admins and members arriving by a tagged link.

## 1.90.48 — 2026-10-29

- Intros: a viewer could still get two in one day, one second apart (#1679, 28 Oct 15:50; flagged by the daily check), and so four in a week. The delivery lock (v1.89.98) was held, but the waiting request kept the user meta it had loaded before waiting, which still said no intro today. The member's meta cache is now cleared once the lock is held, so the boost and intro limits are read fresh.

## 1.90.47 — 2026-10-28

- Daily check (Core\Watch): the email and the wp-admin notice appear only when there is something for the owner to do, one short line each (owner: "only disturb when there is something for me to fix", and not long). Each check carries a `fix` line; information-only findings (exposure shape, members not yet shown, groups the nightly regroup will correct, numbers opened after a withdrawn request) stay in `wp csm watch`. "Regroup has not run yet" on the first day is no longer a failure.

## 1.90.46 — 2026-10-28

- Daily check / exposure audit: "drawn more than their weekly allowance" now lists only viewers whose extra cards were drawn in the last day (owner: "this keeps showing"). The week's 26 October extras, from the refill race fixed on the 27th, would otherwise have repeated every morning until Sunday.

## 1.90.45 — 2026-10-28

- "Verify your CA" is the last step of the setup wizard (owner). For CA and CA Inter claims only (asked live through `/welcome/verify`, since the qualification may be answered earlier in the same wizard; anyone else passes straight on). Upload the ICAI document right there, through the same upload as Edit profile (admin-ajax `csm_pe_file`), so the AI check runs as usual. Optional: "Skip for now" finishes, and the step never holds onboarding open (`optional`, skipped by the completion check).

## 1.90.44 — 2026-10-28

- The "Get 10 more profiles: add a photo / verify your CA" popup (Boost::popup) and the "Get your Verified CA badge" popup (VerifyNudge) no longer open on Discover the moment the setup wizard ends (owner). Both wait until two days after joining (`Boost::is_new`), like the Discover card since v1.90.40; they are not marked seen, so each still comes once, later.

## 1.90.43 — 2026-10-28

- Sign-up screen: "Create my profile" said "Something went wrong" after a signup that had in fact worked and emailed its code (owner). Since BuddyPress 14 `bp_core_signup_user()` returns false on success (no user exists until activation); only a WP_Error is now treated as a failure.
- Choosing bride or groom now says what it means: "You are the bride, looking for a groom." / "You are the groom, looking for a bride." (owner).
- The bot trap field is no longer named "website", so a browser's autofill cannot fill it for a real person.

## 1.90.42 — 2026-10-28

- Date of birth and city move from sign-up to the setup wizard (owner). Sign-up asks name, bride or groom, mobile, email and password. The wizard already asks every required field that is still empty, so both now appear there; the 18-or-over check moves with the date (Welcome step save). BuddyPress's /register/ form drops them too (`csm_signup_fields_v3`). Date of birth stays "Only me" by default (the field's own default visibility).

## 1.90.41 — 2026-10-28

- No confirm-password box at sign-up (owner: "a burden"). The new screen never had one; on BuddyPress's /register/ form it is hidden again, and the server fills it from the password (v1.90.38), so it cannot block a signup.

## 1.90.40 — 2026-10-28

Owner's findings after signups were unblocked:
- Setup wizard (/welcome/) showed the logo twice: the app top bar (Core\Chrome, v1.90.7) is meant to stay off onboarding, but checked `is_page( 'welcome' )`, and /welcome/ is drawn by the plugin, not a WordPress page. Chrome now also stays off by address (welcome, activate, register, join).
- Setup wizard: Continue with a required answer empty did nothing visible when scrolled down (the message was only at the top of the step). Every missing answer is now marked in red with "Please fill this in", the first is scrolled to, and the message is repeated above Continue.
- Discover: the "Get 10 more profiles this week" card no longer appears straight after the wizard; it waits until two days after joining (`Boost::SETTLE_DAYS`). The extra profiles are granted as before.
- Sign-up screen: when `csm_join_live` is 1 it is shown AT /register/ (owner: keep /register/, it is what Meta and others track), not by a redirect to /join/.

## 1.90.39 — 2026-10-28

Our own sign-up screen at `/join/` (owner: "make this a part of the plugin and not go through BuddyPress"), so a BuddyPress, theme or WordPress update can no longer hide or break the form (as on 2026-10-28).
- One page, its own document (no theme or BuddyPress styles or scripts): name, bride or groom, date of birth (day / month / year), city, mobile, email, password with show / hide, terms. Errors appear under their field, checked in the browser and again on the server. Then the 4-digit code on the same page, through ActivationCode's endpoints, and on to /welcome/.
- The account is still made by BuddyPress (`bp_core_signup_user`, with BuddyPress's own email checks and each xProfile field type's validation), so the code email, referral attach, funnel, attribution and the Meta / Google sign-up events after activation are unchanged. Funnel `form_submit` and `validation_error` are recorded from the new screen too.
- Someone who signed up before but never entered the code goes straight to the code step.
- Spam: a hidden honeypot field and at most 8 submissions per IP per 10 minutes. Never cached (it carries nonces).
- `/register/` sends logged-out visitors to `/join/` (keeping any ad parameters) once the option `csm_join_live` is 1; until then `/join/` is a noindex preview. Set it back to 0 and BuddyPress's page returns.

## 1.90.38 — 2026-10-28

- **Signups stopped at about 1 pm IST (owner): fixed.** BuddyPress's stylesheet hides the confirm-password box on pages with a `js` body class and relies on WordPress's user-profile.js to copy the password into it. PasswordPolicy removes that script (with the strength meter), so the box was hidden and empty, and BuddyPress refused every signup with "Please make sure you enter your password twice". The confirm box is shown again (signup.css), and if it ever arrives empty the password is used for it (`bp_signup_pre_validate`); a filled-in confirm that differs is still refused.

## 1.90.37 — 2026-10-28

- `tools/signups.php`: read-only, signups per day by channel, per hour for the last three days (IST), today's accounts with and without a complete profile, and which plugin folders changed in the last two days, to find when and why signups dropped.

## 1.90.36 — 2026-10-28

Found by the first UI check:
- Home page at 320px: "Create your free profile" broke onto two lines; buttons are a little narrower on small phones and stay on one line.
- Log in page: "Show Password" broke onto three lines at 320px.
- Footer on the theme's pages: Terms and Conditions, Privacy Policy and Refund Policy each broke onto two lines on small phones; they now sit on one line each and wrap as a row.
- UI check: screen-reader-only text (hidden with `clip`) is no longer reported as overlapping or wrapping.

## 1.90.35 — 2026-10-28

- Landing page: Elementor's scripts are no longer loaded there. Without Elementor's page they threw "elementorFrontendConfig is not defined" on every load (found by the UI check).
- UI check: each page is really opened at each size (the size was not applied before), and 1px screen-reader-only text is no longer reported as a wrapped label.

## 1.90.34 — 2026-10-28

- `tools/ui-check/ui-check.js` (Node + Playwright, run from a dev machine or a cloud session, not the server): opens the public pages, and with `CSM_TEST_EMAIL` / `CSM_TEST_PASSWORD` the member screens, at seven sizes from 320px phones to 1536px laptops, and reports sideways scrolling, things past the screen edge, short button labels that wrap or are cut off, overlapping buttons, broken images, JavaScript errors and failed requests, with outlined screenshots. Read-only: every request but GET is blocked, and member profile pages are never opened.

## 1.90.33 — 2026-10-28

- Landing page on phones (owner's screenshot): the real logo is wide, and "Log in" and "Register free" wrapped onto two lines. The logo now shrinks first (28px tall on phones) and the nav items never wrap; checked at 320–1280px.
- The hero's background glow was wider than a phone screen, so the page could be dragged sideways. It is now held inside the page, and the page clips anything wider (`overflow-x: clip`).

## 1.90.32 — 2026-10-28

- Landing page (now the live home page): one `<title>`, Yoast's, as on the old home page. The page printed its own as well, so it had two.

## 1.90.31 — 2026-10-28

- Landing page: Google's tag (Site Kit GA4 and Tag Manager) was missing, because the page was drawn on template_redirect at priority 3, before Site Kit registers its tag. The page is now claimed early (not a 404, status 200) and drawn last on template_redirect. The Meta pixel and the attribution cookie were already there.

## 1.90.30 — 2026-10-28

- Landing FAQ: "and others from similar backgrounds" added to who is welcome (owner).

## 1.90.29 — 2026-10-28

- Landing FAQ (owner): "CA Inter qualified" in place of "CA students"; B Com and CA Foundation qualified added to those welcome to join.

## 1.90.28 — 2026-10-28

- Landing FAQ (owner): "Who can join?" says the site is mainly for CAs and CA students, that CS, CFA, CMA, ACCA and similar are welcome, and that the Verified badge is only for CAs and CA students who verify with ICAI. "Who can see my phone number?" removed.

## 1.90.27 — 2026-10-28

- Landing page: the register button can't be missed (owner). "Create your free profile" now also follows the numbers, how it works, safe and private, and the questions, and sits beside "See plans"; the nav button reads "Register free". A sticky bar with the button slides up once the hero's button scrolls away and hides again over the final one (full width on phones). Every button carries `data-csm-cta` with where it is.

## 1.90.26 — 2026-10-28

- Landing page numbers (owner): members, matches made and joined this week. Profile views and requests sent are gone. Members and matches keep the highest-shown floor; joined this week is the real figure each time, rounded down to the hundred, and hidden below 300 (it goes up and down, so a floor would make it untrue).

## 1.90.25 — 2026-10-28

- Landing page: the verified-badge tile reads "See who has verified their ICAI document" (owner).

## 1.90.24 — 2026-10-28

- Landing page: four more demo profiles from the owner's photos (`demo-8.jpg` to `demo-11.jpg`), eleven in the hero deck. Photos after the first two load one card before they are shown, so the page opens as fast as before.

## 1.90.23 — 2026-10-28

- Landing page: the owner's seven AI-generated demo photos (`assets/img/landing/demo-1.jpg` to `demo-7.jpg`, 720×960, about 90 KB each). The hero deck now cycles seven made-up profiles, women and men alternating.

## 1.90.22 — 2026-10-28

- Landing page in the ad's words and look (owner: "very simple language", no "AI coded language"): "India’s exclusive CA matrimony", "Find your CA match.", the three ticks (Verified CA profiles, Safe and private, Free to register) and "Create your free profile". Poppins, navy and green, green pill buttons. Every section's copy cut to plain short sentences.

## 1.90.21 — 2026-10-28

New landing page (owner: "premium with app screens and animations ... live data"), at `/home-preview/` (noindex) for review. The front page switches to it for logged-out visitors when the option `csm_landing_live` is 1; Elementor's page is untouched.
- Its own document with only its own CSS (theme and Elementor styles left out); Yoast, the Meta pixel and Google tags still load.
- Hero with a phone showing the Discover card, sliding to the next demo profile every few seconds; how it works with the verification screen, a profile card and a chat; privacy tiles; a plans teaser; FAQ; final call to action. Scroll reveals and count-ups; all motion off under reduced motion.
- Live numbers: members, matches made, requests sent, profile views. Real, rounded down to the hundred, never shown lower than the highest shown before (`csm_landing_peaks`), refreshed hourly. No ICAI count (owner).
- Live activity line from `csm/v1/landing/activity` (never cached): real joins alternating women and men, first name, city and the app's blurred thumbnail; matches by city only. Never a member with private photos, paused, held, closed, hidden by moderation or an admin.
- Copy corrected: no claim that every member is verified or that only verified members see profiles.
- Demo profiles use `assets/img/landing/demo-1.jpg` to `demo-4.jpg` (woman, man, woman, man) when present, else the default avatars.

## 1.90.20 — 2026-10-28

- `tools/landing-stats.php`: read-only, the numbers the new landing page could show today (members, with a photo, ICAI-verified, matches, requests, active and joined in 7 / 30 days, cities), and the gender split for the owner only.

## 1.90.19 — 2026-10-28

- `tools/over-draw.php`: read-only check of who got more Discover cards this week than their own allowance (the engine's, Gold skipped), and on which day the extra cards came.

## 1.90.18 — 2026-10-28

- Block: the check that refuses messages between a blocked pair was hooked to a Better Messages filter that does not exist (`bp_better_messages_user_can_send_message`), so it never ran. It now uses `better_messages_can_send_message` (present in Better Messages 2 and 3). Messages were already refused in practice: a block ends the match, and messaging is for matches only.

## 1.90.17 — 2026-10-28

- Requests: a request accepted in this visit showed in Matches with Decline / Save / Accept until a reload (owner's screenshot). It now joins Matches as a match, with Remove match and Send message.

## 1.90.16 — 2026-10-28

- Requests: Sent moves under More (Sent, Viewed me, Declined, Passed); main tabs are Received, Matches, Saved, More. Sent is now Premium and Gold only (owner): free members see initials, a count and an upgrade prompt, and the server sends them no identities.

## 1.90.15 — 2026-10-28

Nothing lost to one tap (owner):
- Requests has a "More" tab holding the less used lists: Viewed me, Declined and the new Passed. Main tabs are now Received, Matches, Sent, Saved, More.
- Passed: the Discover profiles a member passed, newest first, with "Send request" (puts the profile back and sends the request). Premium and Gold; free members see initials and an upgrade prompt, as with Declined. Leaves out anyone since matched or asked, blocked, or back in the deck.
- Remove match: in Matches, after a confirmation. Either side may; the other member is not told.

## 1.90.14 — 2026-10-28

- A withdrawn request puts the profile back in the sender's Discover, undecided (owner: "that should be the standard behaviour"; a Premium member withdrew one by accident and could not send it again). The card returns as part of this week, and the old like is cleared so a later like from the other side is not taken for a match. Not restored when the pair is blocked or the profile is paused or held. `Discover\Withdrawn::restore( $viewer, $profile )` does the same by hand.

## 1.90.13 — 2026-10-27

Security audit part B:
- The site's PHP `error_log` was downloadable at `/error_log`. `tools/harden.php` (dry run by default, `apply` to act) moves it out of `public_html` (kept), denies `error_log`, `*.log`, `readme.html`, `license.txt` and `wp-config-sample.php` in the root `.htaccess`, and stops PHP from running in `uploads/`. Rules sit between WordPress markers; each `.htaccess` is backed up first.
- `tools/audit-b.php` no longer stops at a folder it cannot read.

## 1.90.12 — 2026-10-27

- `tools/audit-b.php`: read-only security audit part B (versions, wp-config switches, file modes, PHP in uploads, world-writable files, admin accounts, plugin updates, WPCode snippet list). Prints no secrets and no snippet code.

## 1.90.11 — 2026-10-27

Security audit, part C (requests from outside) and the BuddyPress match button:
- The plugin's internal notes were readable by anyone (`CHANGELOG.md`, `HANDOFF.md`, `docs/`, which describe how Discover, boosts and reach work). A plugin-root `.htaccess` now denies `.md`, `.sql` and `.log` files, and `docs/` denies everything.
- Browser security headers on every page (`Core\Headers`): HSTS (a year), nosniff, SAMEORIGIN framing, a referrer policy and no camera / microphone / location.
- BuddyPress's own "Add Match" is for admins only (owner). The button is gone for members, and its link, its ajax calls and the BuddyPress REST route for new requests refuse members. Requests from Discover and Gold are unaffected.

## 1.90.10 — 2026-10-27

- Discover: "All profiles" returns to the grid at the profile you were on (or the next one, if you just decided it), not the top.

## 1.90.9 — 2026-10-27

- Discover no longer says "You've seen everyone" when more profiles are waiting (owner: as Gold, reached the end, went back, saw "widen your filters"; a reload brought the profiles). Deciding the last loaded card in the card view now loads the next page instead of the end screen; Next on the last loaded card loads more for Premium and Gold; and one "Show more" that found nothing no longer stops every later load until a reload.

## 1.90.8 — 2026-10-27

- The menu works for an administrator and when switched into a member. WordPress's admin bar (shown on the site to administrators, and kept after switching into a member) sat over the top bar and covered the photo button. The top bar and menu now sit below the admin bar on app screens and on theme pages.

## 1.90.7 — 2026-10-27

Menu review (owner: the pricing page showed the WordPress menu to logged-in members, the menu did not work on a laptop on several screens, and the menu / settings / profile split was clumsy):
- One list for everything about "me" (`Core\AccountMenu`). The Profile tab shows all of it in sections: Your profile (each part, with what is left), My profile (photos, astro details, who sees what, how others see me), Membership (plan, dates, payments, upgrade, Refer & earn), Privacy & safety (blocked members, pause), Account (email, phone, password, ICAI verification, email notifications, delete), Help (support, log out). Sections have ids for links (`/profile/#membership`, `#privacy`, `#account`).
- The Settings page is folded into the Profile tab: `/settings/` redirects to `/profile/#privacy`; its sub-pages (who sees what, blocked, notifications, pause, delete) stay and go "Back to profile".
- Top bar: logo, notifications bell and the member's photo, which opens the menu: View my profile, the most used destinations (Edit profile, My photos, upgrade, Membership, Refer & earn, Privacy & account, Help), Everything (the Profile tab) and Log out. On a laptop it is a dropdown under the photo.
- Every page a logged-in member sees gets that top bar and the nav (`Core\Chrome`): pricing, checkout and orders, Messages, My photos, notifications, BuddyPress profile pages and settings. The theme's header and menu are hidden for members; logged-out visitors keep them. This also fixes the laptop menu: on BuddyPress pages the only menu button was in a top bar hidden above 782px.

## 1.90.6 — 2026-10-27

Security audit follow-ups (owner's decisions):
- ICAI documents are no longer public files. `uploads/bpxcftr-profile-uploads/` and `uploads/csm-ca-extra/` get a deny-all `.htaccess` (written on admin_init), and documents are served by `admin-post.php?action=csm_doc` only to administrators and the member they belong to (`CaVerify\DocGuard`). The CA Verify queue, extra pages and the member's own link in Edit profile use it; the AI reads files from disk as before.
- A refunded or cancelled boost-pack order removes the boosts it added that are still unused (`boost_refunded`).
- Photo moderation checks a private member's real main picture, not the blurred copy it was handed (`Privacy::original_url`).
- At most 10 automated CA checks per member per 24 hours; past that the member is told to email support or try again after 24 hours (reason `limit`).

## 1.90.5 — 2026-10-27

- CA verification (owner: if the AI cannot verify with good confidence, the member is told and writes to support, as before): an AI "verify" that fails the new checks is a rejection with its reason, not manual review: not an ICAI document → `not_icai`, not the claimed level → `wrong_level`, confidence under the threshold → `unclear`, name not matching the profile → `name`. The member sees that reason and the usual support note.
- A verified member who changes their qualification level has their document checked again by the AI against the new level (approved again, or rejected with the reason), not sent to manual review.

## 1.90.4 — 2026-10-27

Security audit, part 1 (code review), second batch:
- Private photos: when a member with no photo uploaded one, every pending "ask for a photo" request was marked `approved`, which is the consent that unblurs a private photo, so anyone who had asked (free members too) saw a photo the member chose to blur. Such requests are now `uploaded`; seeing a private photo still needs the member's yes. (Requests already resolved this way before today stay approved.)
- Verified badge: a member approved as CA Inter could change Qualification to CA and be shown as "Verified CA". A real change of level by an approved member now sends them to manual review (`csm_av_hold_reason`).
- Phone reveal: the target id came from the request and any member was accepted. Now only the viewer's other gender, not paused, closed, photo-held or an administrator. The 20-a-day cap holds under parallel requests (per-member lock).
- Admin URL tools that change data or cost money (`?csmdob=apply`, `?csmage=apply`, `?csm_avc_runnow`, `?csm_avc_dryrun`, `?csm_pm_runnow`) stop at a "Confirm and run" link with a nonce (`Core\AdminTool`), so another page cannot trigger them (CSRF).
- Profile save refuses file / image fields as typed text (the ICAI document could be pointed at any path); `CaVerify::doc()` only accepts a path that resolves inside uploads.
- Photo uploads: images only (jpg, png, webp, heic), checked by the server, not the browser's claim.
- Magic sign-in links do not sign in a closed account.
- `tools/`: denied to the web (`.htaccess`) and each script exits unless run by WP-CLI.

## 1.90.3 — 2026-10-27

Security audit, part 1 (code review), confirmed findings fixed:
- Stored XSS through a display name: the avatar alt-text fix-up used `preg_replace` with the name in the replacement string, where `$0` is a back-reference, so a name containing it broke out of the attribute (admin Reports page, Viewed me, blocked list). Now `preg_replace_callback`.
- Stored XSS in the admin CA Verify queue: "Run AI check" put the model's reading of the member's document (name, number, reason) into the page as HTML. Now text only.
- Referral cash reused: on a failed payment the cash was returned while the order kept its discount, and a failed order can still be paid, so the same cash discounted order after order. Cash now returns only on cancelled / refunded; unpaid failed orders are cancelled after 2 hours like pending ones; an order paid after its cash was returned takes it again (ledger kind `retake`).
- CA verification auto-approval: any AI "verify" approved, read from keys the prompt never defines, at any confidence, with no name check, and text in the uploaded document could steer the model. Auto-approval now needs `recommendation` = verify, `is_ca_document` and `supports_claim` true, confidence at the threshold setting (0.80), and a profile name in the name read off the document; otherwise the member goes to manual review (`csm_av_hold_reason`). Instructions are sent as the system message and the documents alone as the user message, with document text declared content, never instructions.

## 1.90.2 — 2026-10-27

- Members with gallery photos but no main picture file (Riya: 1 gallery photo, no avatar files) showed a default picture to everyone, and a private photo could not be blurred. `wp csm photos missing` lists them; `wp csm photos repair [--dry-run]` rebuilds the main picture from the first gallery photo, keeping the member's last-active time. The daily check reports any such member.
- A private photo is blurred from the first gallery photo when the main picture file is missing; blurred copies are always written to the member's own avatar folder (a gallery source sits in a shared uploads folder, where the old-copy sweep would have removed other members' blurred copies).

## 1.90.1 — 2026-10-27

- A private photo whose blurred copy cannot be made now shows the site's own default picture (the grey figure members without a photo have), not BuddyPress's black-and-white default, which looked like a photo the member had chosen (owner: Riya's card).

## 1.90.0 — 2026-10-27

- "Few members saw your profile": "Verify" only for members whose qualification is CA or CA Inter (verification is by ICAI document; ACCA, CMA, CS and others choose "Other" and are not asked). A blank qualification is asked for instead ("Add your qualification").
- Half-and-half test (owner): members are split by id; in the first week it runs, the "hold" half gets neither the email nor the Profile card and is logged as `reach_hold`; from the next week everyone. `wp csm reach-result` compares the halves: gaps fixed since (photo, verification, matching questions) and showings in the 7 days after.

## 1.89.99 — 2026-10-27

- "Few members saw your profile" (owner, option 1, men and women): an active member shown to fewer than 3 people in 7 days, past their first week, with something they can fix, is told what: add a photo, verify CA / CA Inter (not while a document is pending), and the unanswered matching questions (community, mother tongue, religion, diet), each linked to where it is fixed. Email on Thursdays (IST), once a week, respecting the match-email setting, logged as `reach_nudge`; and a card on their Profile page while it applies. No counts or scoring are revealed. `wp csm reach` shows who it applies to and what they lack. `Discover\Reach`.

## 1.89.98 — 2026-10-27

- Fix (found by the exposure audit): members could be drawn several weeks' worth of Discover cards at once. Opening the app sends several requests together; each refill counted "0 drawn this week" before the others had saved, so each drew a full week (a free member got 30 cards at 00:33 on Monday; 257 viewers over their allowance this week). The refill now holds a per-member MySQL lock (`GET_LOCK csm_refill_<id>`), and boost / newcomer delivery another, so the second request waits and finds the week already drawn.

## 1.89.97 — 2026-10-27

- Exposure audit, from its first run: the weekly allowance is checked on the week's cards still in the viewer's list (a card removed because its member paused, was photo-held or left is replaced by design; 336 viewers were wrongly reported). Paused / held members are reported only if shown after their pause or hold began.

## 1.89.96 — 2026-10-27

- Exposure audit checks boosts (owner: boosted members too, more or less than they deserve). Each boost whose 24 hours are over: reached past its cap is a RULE anomaly; under half its cap is a SHAPE anomaly, with how many members of the other gender used Discover in those hours. Summary line: boosts finished and reach as a share of the cap (median, lowest, highest).

## 1.89.95 — 2026-10-27

- Gold is no longer exempt from Discover's weekly ceilings (showings and requests received), owner: Gold is shown more only through its weekly boost. The exemption had survived the removal of the permanent 3x (v1.89.47). Option `csm_gold_ceiling_exempt` now defaults to 0. If the option was ever saved as 1 on the server, delete it.
- Exposure audit counts Gold members like everyone else; only profiles boosted in the period are left out of the shape checks.

## 1.89.94 — 2026-10-27

- Exposure audit (owner): how often members are shown and whether it matches the rules. `wp csm exposure [--days=7]`, and `--who=<email>` for one member's showings by day and route (weekly draw, boost, newcomer intro) against their caps. RULE anomalies: boost / intro past their caps, intros outside the 24 hours, a viewer given more than 1 boost or intro a day or 3 intros a week, a viewer drawn past their weekly allowance, a liked profile shown again, a passed one inside 30 days, a paused or held member shown. SHAPE anomalies (rules held, result odd; Gold and boosted left out): >10% of active members with a photo shown to nobody, the top 1% taking >15% of showings, profiles above 5x the median, newcomers shown to fewer than 5 after their 24 hours, >5% of viewers finding Discover empty. Part of the daily check and its email.
- Fix: a free member's newcomer card was deleted by the Discover refill on their next visit (only this week's and boost cards were kept).

## 1.89.93 — 2026-10-27

- New member introductions run over 24 hours, like a boost (owner): from when the newcomer is first eligible, their score for each viewer is multiplied by the boost's steps (1.3x → 2x at 6 h → 5x at 12 h) and must reach the best score that viewer was shown in 30 days. Best matches get them first; by the afternoon nearly everyone qualifies; the window closes at 24 hours or at the cap (20 women for a new man, 40 men for a new woman). `csm_intro_start`; `intro_shown` logs hour, multiplier, score, bar.

## 1.89.92 — 2026-10-27

- New member introductions: no bar against the viewer's usual cards (owner: a newcomer has few details and no history, so would rarely pass). The viewer gets the newcomer they score highest with among newcomers; reach caps (20 / 40) unchanged.

## 1.89.91 — 2026-10-27

- New member introductions (owner: someone joining mid-week reached nobody until Monday). When a member opens Discover, they may get one newcomer as an extra card at the front: 1 a day, 3 a week, on top of their weekly cards. Newcomers: joined in the last 7 days, with a photo, passing the viewer's filters, never shown to them. Each new man is introduced to at most 20 women, each new woman to 40 men. The pick is the newcomer the viewer scores highest with on Discover's relevance score, and only if at least the viewer's average over 30 days. Tray week `intro`; logged as `intro_shown`. `Discover\Intro`.
- The NEW tag means the member joined in the last 14 days. It meant "drawn into your Discover this week", which was nearly every card.

## 1.89.90 — 2026-10-27

- Daily check, "unseen": reads the permanent showing record (`csm_seen`), not Discover trays (cleared weekly, so 354 members were wrongly reported), and fails only for new members with a photo; those without one are counted, since the ranking puts them last by design.

## 1.89.89 — 2026-10-27

- Daily check for the quiet failures (owner: "these are silent and I won't come to know"), `Core\Watch`, 02:40 daily and `wp csm watch` on demand. Looks in the data for each bug class found on 26-27 Oct: members whose stored groups no longer match their answers; the nightly regroup not running; members who joined 2-14 days ago and are in nobody's Discover; phone numbers opened with no request; the app's API being cached. Emails the site admin when anything fails, and shows a red notice in wp-admin until a clean run. Silent when all is well.

## 1.89.88 — 2026-10-27

- Diagnostics for profile cards that show only Phone / Gender / City on the site but in full from WP-CLI: `wp option update csm_debug_card <id>` records the next build of that card (viewer, hidden field ids, sections) in `csm_debug_card_last`. Off otherwise.

## 1.89.87 — 2026-10-27

- Mother tongue: every language named counts (owner): "Hindi, Punjabi" is in both the Hindi and the Punjabi groups. Hindi was dropped beside a regional language before. English still counts only when it is the only one. The profile always showed the words as typed. Run `wp csm buckets rebuild`.

## 1.89.86 — 2026-10-27

- Groups (community, mother tongue, city, native place) are built from the value being saved, not read back: a member who joined with "Hindi, punjabi" had no mother-tongue group at all, so the Punjabi filter missed him. Safety net: everyone is regrouped nightly (`csm_buckets_nightly`, 03:10).
- `tools/why-missing.php` also prints what the profile card would show the viewer.

## 1.89.85 — 2026-10-27

- Removing a filter chip (or applying filters) shows the new list straight away (owner: it needed a page reload). The app's API (every `csm/v1` route: Discover, Requests, Profile, filters) is now sent as never-cache, to the browser and to LiteSpeed, and the Discover / Requests / Edit profile screens fetch with `cache: 'no-store'`.
- `tools/why-missing.php` also prints the refill's own exclusions (gender, seen, blocked, paused, photo hold) and, for each grouped question, the profile's answer, stored groups and the groups the answer gives today.

## 1.89.84 — 2026-10-27

- Gold with filters sees new members who match (owner: a member who joined today and passed every filter never showed). Profiles served before the filters were set stay pending but hidden, and they kept the deck counted as full, so nothing new was added. A Gold deck is now topped up whenever fewer than a page (24) pass the filters, with members who match them. No change without filters. `tools/why-missing.php` tests each filter against a profile and shows its deck state.

## 1.89.83 — 2026-10-27

- The astro switch governs BuddyPress's own profile pages too (owner): with it on, date of birth is shown to others; off or never set, Time and Place of Birth are hidden from others. Before, those pages showed time and place whatever the switch said, and never the date of birth. Via `bp_xprofile_get_hidden_fields_for_user` (priority 50, after FieldLogic), so the app's cards follow the same rule.

## 1.89.82 — 2026-10-27

- Profile page on desktop: the Boost card (and the astro card) ran the full page width; they now sit in the same 560px centred column as the other cards.

## 1.89.81 — 2026-10-27

- Astro details switch reads "Show on profile" (owner), on = shown. It said "Hidden from my profile" while off, which read as the setting rather than its state.

## 1.89.80 — 2026-10-27

- Action bar on profile cards (Discover, Requests, member pages) is floating pills instead of a thick white bar (owner): icon and label side by side, on a see-through blurred background, with small glass arrows at the ends. Bar height 124px → 64px, so the photo gets that room back. Labels in the pill are short (Save, Decline, Accept); the full wording stays the button's accessible name. Checked at 360 / 390 / 412px wide.
- Tall photos are cropped from near the top (`object-position: 50% 18%`), so heads are no longer cut off.

## 1.89.79 — 2026-10-27

- Profile cards always show the start of what is below the photo (owner: on some profiles the photo filled the screen and there was no sign of more). The photo's height cap now uses the visible screen (`dvh`) and leaves ~88px, so the first lines of the bio or "Basic Details" sit above the buttons.

## 1.89.78 — 2026-10-26

- Discover never shows a match, or someone you already sent a request to (owner: a matched profile showed with "Send request" and its number open). The number had been opened on 25 Oct, before opening a number also closed the Discover card (v1.89.65), so the card stayed pending after the match. Such cards are now closed as liked when Discover loads. `tools/phone-check.php` explains any card's phone state.

## 1.89.77 — 2026-10-26

- City is a row in Basic Details on profile cards (owner), where it sits in the profile. The job / city lines over the photo are gone again: they were never visible (0px tall) until v1.89.76, and the job title is already in Professional details.

## 1.89.76 — 2026-10-26

- The real cause of the missing city (and job title) on profile cards: the photo area sets `line-height: 0`, the job/city lines inherited it, and with their two-line clamp they were 0px tall. They now have their own line height.

## 1.89.75 — 2026-10-26

- City shows on profile cards (owner: still missing). It was on the job line ("Job · City"), which is clamped to two lines, so a long job title cut it off; and the separate city line was skipped when it matched. Now the job has its line and the city always its own line under it.

## 1.89.74 — 2026-10-26

- Existing members are asked once for astro details (owner): a card on their own Profile page, under the boost card, "Add your astro details" with **Add details** (opens Edit profile → Astro Details) and **Not now**. Either answers it for good (user meta `csm_astro_asked`), as does setting the switch or filling in time of birth. Not a popup. New members are asked in onboarding, so they never see it.

## 1.89.73 — 2026-10-26

- Edit profile → Astro Details: the switch showed "Shown on my profile" for members who had never set it, while their profile (correctly) kept the section hidden. The editor now shows the saved state; only the onboarding step starts it on, because Continue saves it.

## 1.89.72 — 2026-10-26

- Requests → Received: the Filters button and chips sat flush against the screen edge and under the sticky tabs (top clipped); they now have the page's side padding and space below the tabs, as does the "N requests hidden by your filters" line.

## 1.89.71 — 2026-10-26

- City → state: about 70 towns and spellings from the first `wp csm buckets report` added (Gurgaon / Bengaluru / Ahmedabad misspellings, Jamkhandi, Adoni, Tirur, Kasargod, Barabanki, Hathras …). Run `wp csm buckets rebuild`.

## 1.89.70 — 2026-10-26

- Requests gains a **Declined** tab (owner): the requests you turned down, newest first, as profile tiles with "Declined N ago", opening the full card with a Send request button in case you change your mind. Premium and Gold only, like Viewed me: free members see initials and times and an upgrade box (`src=requests-declined`). Read from `csm_rejections`; people since matched, or who have asked again, are left out.

## 1.89.69 — 2026-10-26

- Filter sheet takes less room (owner chose ideas 1–3): each pick list (Community, Mother tongue, State, Religion, …) is one line showing what is chosen ("Brahmin +2 ›", "Any ›"); tapping it opens that list on its own, with Back / Done. Long lists (more than 10 groups) get a search box and the most common groups first ("Popular", counted twice a day, transient `csm_popular_<key>`), then the rest A–Z with Others last.
- The filters in use show as chips beside the Filter button on Discover and Requests ("Age 26–30 ×", "Gujarat ×"); × removes that one filter straight away.

## 1.89.68 — 2026-10-26

- City grouped by state (owner): the member types their city as before and the profile shows it as typed; it is also sorted into a state (or Abroad / Others) in `Core\Buckets` (kind `city`, field 587), with a "State" picker under the field in Edit profile. Discover / Requests filters gain **State**, ticked like Community. Native Place (new) is grouped the same way (kind `native`), not yet a filter. Run `wp csm buckets rebuild`, then `wp csm buckets report` to see cities that fit no state.
- Astro Details step (owner), just before verification: date of birth from sign-up shown read-only, Time of Birth and Place of Birth, and one Shown / Hidden switch for all three (user meta `csm_astro_show`; unset = hidden). When shown, the profile card has an Astro Details section with the date of birth; otherwise date of birth stays withheld as before. The same switch is at the top of the section in Edit profile. Created by `wp csm astro setup` (group and field ids in options `csm_astro_group`, `csm_astro_fields`), which also adds **Native Place** to Family Details.

## 1.89.67 — 2026-10-26

- v1.89.66 reverted (owner: clutter). Instead the city is its own line under the job on the profile card, read by field id (`cityLine`). Tiles unchanged.

## 1.89.65 — 2026-10-26

- Opening a phone number decides the card (owner): the request it sent, or the one it accepted, replaces Pass / Save / Send request (or Decline / Accept) with "✓ Request sent" / "✓ You're a match", keeping previous / next. The viewer's tray row is marked as a request, so the profile leaves Discover and Saved; Requests reloads its lists on the way back.

## 1.89.64 — 2026-10-26

- Phone row on profile cards: the number stays on one line, and Call / WhatsApp are small buttons under it (the number wrapped and "Call" ran into the last digit).

## 1.89.63 — 2026-10-26

- Matches, Sent and Viewed me use the same format as Received (owner): profile tiles, 24 at a time with Show more, opening the full card with previous/next and a labelled button — Matches: Send message; Sent: Withdraw request; Viewed me (Premium): Send request. Free members' Viewed me stays masked. Tab counts use real totals (`matchesTotal`, `sentTotal`).

## 1.89.62 — 2026-10-26

- Requests loads 24 at a time, as Discover: Received and Saved build full profiles only for the page shown (`limit`, +24 per "Show more"); Gold senders are still first. The tab counts use the real totals (`receivedTotal`, `savedOpenTotal`).

## 1.89.61 — 2026-10-26

- Sales Dashboard is faster (owner: slow to load). The member table was built with ~10 lookups per member on every open; it is now built from bulk queries (phones, genders, actions, last activity and plans in one query each, user data primed with `cache_users`), kept for 10 minutes (transient `csm_sd_rows`, "Updated N min ago · Refresh now"), filtered and searched in memory, and shown 200 rows a page.

## 1.89.60 — 2026-10-26

- When profiles come back to Discover (owner): ignored (shown, never decided, and no longer in the deck) after 15 days from the last showing; passed after 30 days (was one month); sent a request never. Filters `csm_ignore_reshow_days` / `csm_pass_reshow_days` replace `csm_pass_reshow_months`. Gold is unchanged: ignored profiles are never held back.

## 1.89.59 — 2026-10-26

- Viewing the number of someone who has already sent you a request accepts their request (owner): you become a match, they are told, and it leaves Saved if it was saved for later (event `phone_view_accept`). The confirm says so every time: "[Name] has sent you a request. Viewing their number accepts it, and you become a match." [View and accept].

## 1.89.58 — 2026-10-26

- Free members get a fresh set every week whether or not they acted (owner). At the first refill of a new week, their undecided profiles from earlier weeks leave the deck, so the full weekly number of new profiles arrives; those profiles stay counted as shown, so the new set is new people. Premium (carry-over plus new) and Gold (everyone) are unchanged; boosted cards are left alone.

## 1.89.57 — 2026-10-26

- Buy boosts empties the cart first, as Buy Premium / Gold do: a Gold item left from earlier and a second pack from a repeat tap were both ending up in the checkout.
- Pricing page: the boost pack is its own small card (Profile boosts · 3 for ₹799 · first in Discover for 24 hours · never expire) with a Buy boosts button, instead of a text link in the footnote.

## 1.89.56 — 2026-10-26

- Boost card: "As a Premium member you get 1 free boost every month." (Gold: "every week") under the boosts left.

## 1.89.55 — 2026-10-26

- Discover drew two bottom bars: Discover is also a WordPress page, so the old theme shell (`AppShell`) added its bar under the app's own. The shell now stays out of every app screen (`AppPage::$open`, set when a screen is claimed).

## 1.89.54 — 2026-10-26

- The red Requests count sits on the heart icon; it was positioned from the nav item and landed half above the bottom bar, cut off.

## 1.89.53 — 2026-10-26

- The "match requests waiting" popup is off (owner: too often). Instead the Requests tab in the bottom bar carries a red count of requests received and not yet answered or saved for later (9+ above nine). The weekly "requests waiting" email is unchanged.

## 1.89.52 — 2026-10-26

- Boost data, kept permanently: `boost_shown` (each boosted card delivered: hour into the boost, multiplier, the member's score, the viewer's bar), `boost_outcome` (request / pass / save on a boosted card, logged in `Discover::act` before the weekly reset can delete the row), and pack buy taps (`buy_click` with product `boost_pack`). `boost_started` and `boost_bought` are now kept permanently too.
- `wp csm spotlight report [--days=30]`: per boost, cards shown, requests / passes / saves on them, requests received in the 24 hours vs the 24 hours before, matches; totals; pack buy taps and sales.

## 1.89.51 — 2026-10-26

- Boost mechanics are not shown to members (owner): the profile card no longer says how many members a boost has reached or its limit, and the boost state sent to the browser no longer carries them. `wp csm spotlight status` still shows reach for admins.

## 1.89.50 — 2026-10-26

- Boost multiplier steps 1.3x at the start, 2x at 6 hours, 5x from 12 hours (straight lines between; `Spotlight::MULT_STEPS`). A woman's boost now reaches up to 100 men (was 150); a man's stays 50. Delivery stops once the cap is reached.

## 1.89.49 — 2026-10-26

- Boost delivery uses a multiplier on Discover's own score (owner): the boosted member's full ranking score for the viewer, times a multiplier that starts at 2.5x and rises to 5x over 12 hours, must beat the best profile that viewer has been shown in the last 30 days (`wp_csm_impressions`). Replaces the match-points bar. Filters still not used; highest boosted score wins.

## 1.89.48 — 2026-10-26

- Profile boosts go to the best matches first (owner): a viewer must reach a match-score bar from Discover's own match points (language, community, religion, age, city, diet). The bar starts at 60% of the full points and falls evenly to none at 18 hours, so the boost still fills. Among boosts a viewer qualifies for, the best match wins. A boosted card is shown whatever the viewer's filters.

## 1.89.47 — 2026-10-26

- Profile boosts (`Premium\Spotlight`, owner): 24 hours at the front of Discover. While a boost runs, the member is slotted in at the front of Discover for members of the other gender who open it, as a bonus card that does not use up their week (`week_assigned = 'boost'`). Reach is capped: a man's boost reaches up to 50 women, a woman's up to 150 men (options `csm_spot_cap_male` / `csm_spot_cap_female`); never someone already shown them; at most one boosted card per viewer a day.
- Premium gets 1 boost a month, Gold 1 a week (no carry-over); bought boosts never expire. `wp csm spotlight product` creates the pack (3 boosts, ₹799, hidden virtual product); paid orders add the boosts once. `wp csm spotlight give <email> --n=` and `wp csm spotlight status`.
- Gold's permanent 3x ranking is off (default `csm_gold_multiplier` now 1; measured at ~20x showings). Gold keeps priority at the top of requests and its ceiling exemption.
- Profile page: a Boost card (Boost now / running with hours left and reach / when the next one arrives, and the pack). Settings → Membership: Profile boosts row. Pricing: Premium "1 profile boost a month", Gold "1 profile boost every week" (was "Your profile shown first in Discover"), and a "3 profile boosts for ₹799" line.

## 1.89.46 — 2026-10-26

- Members who already have a photo or an approved badge are marked as having used the one-time boost (`Boost::mark_existing_used`, once on deploy): they get no more +5. Profiles already in anyone's deck stay; only future refills stop adding the 5.

## 1.89.45 — 2026-10-26

- The +5 profiles for a photo and +5 for CA verification are one-time (owner): each applies only in the week it is first earned (user meta `csm_boost_week_photo` / `csm_boost_week_verify`, never cleared, so removing and re-adding cannot earn it again). Earned mid-week, the next Discover load adds the 5. Members who already had a photo or badge get it once, this week. Prompts say "this week" / "once approved" instead of "every week".

## 1.89.44 — 2026-10-26

- Discover loads 24 profiles at a time for everyone (owner): the queue returns the first `limit` (24, then +24 per "Show more") of the undecided deck and its `total`, so a Premium deck built up over weeks no longer loads in one go.

## 1.89.43 — 2026-10-26

- Discover opens on the grid of profile cards for every Premium and Gold member, men included (owner); free members keep the one-card view. "Show more profiles" appears only while the week still owes profiles that have not loaded.

## 1.89.42 — 2026-10-26

- Premium profiles never expire (owner): undecided profiles always carried over, but the weekly cap then blocked the new week's. For Premium (not Gold) the cap is now what is waiting plus this week's grant, so the deck grows — e.g. 40 after four weeks if none are decided (`Discover::premium_carry_over`). Free members unchanged.
- Discover grid says "N profiles to see" when the deck holds more than a week's worth.
- Pricing: new Premium line "Profiles never expire: undecided ones add up week after week".

## 1.89.41 — 2026-10-26

- Gold sees every member (owner): for Gold, only profiles they decided on (sent a request / passed) are kept out of Discover. Profiles that were loaded but never acted on come back, including those lost when older versions (up to v1.89.10) cleared the undecided tray on every filter change. Saved profiles are never re-served into Discover (they are under Saved).

## 1.89.40 — 2026-10-26

- Requests looks like Discover (owner): Received and Saved are profile tiles; tapping one opens the full profile card with an action bar and previous/next arrows (`window.csmDeck` in app-screens.js, shared with Discover).
- Labelled buttons everywhere. Discover: Pass · Save for later · Send request. Received: Decline request · Save for later · Accept request. Saved: Decline request · Accept request for a saved request, Pass · Send request for a saved Discover profile.
- A received request can be saved for later (`save_request`, user meta `csm_req_saved`): it stays pending and moves from Received to Saved; answering it clears it. Received and Saved rows are now full profiles (`Profile::full`); Gold senders' tiles carry a Gold tag and still sort first.

## 1.89.39 — 2026-10-26

- Gold + filters: for Gold members the filters now choose which profiles load (refill uses `Filters::where_gold`), and Apply adds a fresh page of matching profiles. Before, each page of 24 was loaded unfiltered and then narrowed, leaving a handful, and "Show more" stopped early with "That is everyone". Premium keeps the narrow-the-week rule. Already-loaded profiles are not deleted (a loaded profile counts as shown).

## 1.89.38 — 2026-10-26

- Gold launch and opening sales purge the LiteSpeed cache, so visitors' cached pricing page stops saying "phone numbers open on 28 October" once they do.

## 1.89.37 — 2026-10-26

- Discover: no "N hidden by your filters · Show all" for unlimited (Gold) members. Their tray is loaded a page at a time, so the count was wrong (owner's screen recording). The filter button and its Clear still work.

## 1.89.36 — 2026-10-25

- The verification badge says "Verified CA Inter" for members whose qualification is CA Inter (profile cards, own profile page, and the approval email); it said "Verified CA" for everyone (`Profile::verified_label`).

## 1.89.35 — 2026-10-25

- Settings: a Membership section — Plan (Free / Premium / Gold), Member since, Valid until with days left (flagged in the last 14 days, linking to pricing in the last 30), and Payments and invoices (WooCommerce orders). Replaces the single Membership row.

## 1.89.34 — 2026-10-25

- Buy Gold empties the cart first, as Buy Premium does: an old unpaid Premium item in a member's saved cart made the Gold checkout show Premium. A Gold member cannot add Gold again.

## 1.89.33 — 2026-10-25

- Membership lifecycle events, kept permanently: `membership_first`, `membership_renewed` (bought again while active, or after it lapsed), `membership_upgraded` (to Gold), `membership_downgraded`, `membership_expired` (PMPro's expiry), `membership_ended` (refund, cancel, admin). Each records from/to level, old and new end dates, days left and gender.

## 1.89.32 — 2026-10-25

- Dropped the new `request_declined` event: declines were already kept in `wp_csm_rejections` (since v1.52). `request_withdrawn` stays.
- `filters_set` / `filters_cleared` are logged only when the filters actually change.

## 1.89.31 — 2026-10-25

- New events, kept permanently: `request_declined` / `request_withdrawn` (sender, receiver, hours waited, sender's plan and gender; from BuddyPress's own hooks, so every path is caught), `filters_set` / `filters_cleared` (the filters, undecided tray size and how many pass), `phone_privacy` (from → to).

## 1.89.30 — 2026-10-25

- Pricing-page tracking: every upgrade button carries a `?src=` tag (phone, photo-blur, photo, filter-discover, filter-requests, discover-gold, discover-empty, discover-limit, requests-gold, requests-saves, requests-viewers, profile, profile-gold, quota, settings, viewers-teaser, recent-viewers, upgrade-btn) via `window.csmSrc`.
- `pricing_view` events also record plan (free/premium/gold) and gender; new `buy_click` event for Buy Premium / Buy Gold taps; `purchase` records `gold`.
- `wp csm pricing report [--days=7]`: visits, members, Buy taps and purchases by source, then by plan and gender.

## 1.89.29 — 2026-10-25

- ZeptoMail: an invalid recipient (SM_113 / SMI_116) fails that one email without raising the "check the ZeptoMail credits" dashboard alert.

## 1.89.28 — 2026-10-25

- `wp csm gold sales`: opens Gold sales and the new pricing page now; phone viewing still switches on by itself on the launch date (owner).
- Anyone who buys a plan with phone numbers (Gold; Premium for a woman) before phone viewing opens gets 7 extra days on that level, once per order, with an order note (`GoldLaunch::interim_week`).
- The pricing page says when phone numbers open and offers the extra week until then.

## 1.89.27 — 2026-10-25

- No in-app banner for the Gold launch / phone-number change (owner); the notice email stays the only notice. `GoldLaunch::banner()` is no longer called.

## 1.89.26 — 2026-10-25

- Premium (not Gold) members see "Upgrade to Gold": a row on their own profile page while Gold is on sale, and "🔒 Upgrade to Gold to view" on locked phone numbers. `CSM_APP.isPremium` added.

## 1.89.25 — 2026-10-25

- Discover ranking: the new-profile boost drops from 5 points to 2 (owner: a new woman's account was flooded with requests). The "never shown" points that balance exposure are unchanged.

## 1.89.24 — 2026-10-25

- `wp csm gold notice` no longer claims a 500-a-day limit: the notice is sent in full while the command runs (`Queue::notify`, outside the daily cap).

## 1.89.23 — 2026-10-25

- Own profile page: a Gold member's badge reads "Gold" (gold styling) instead of "Premium".

## 1.89.22 — 2026-10-25

- Gold members no longer see "Upgrade to view" on phone numbers (`Gold::plan_has_phone`). Before the launch date their row reads "Opens on launch day".
- The owner's test accounts (`csm_gold_tester_regex`) can open numbers before launch (`Gold::phone_live( $viewer )`).

## 1.89.21 — 2026-10-25

- Discover grid: "Show all" sits at the end of the count line ("55 of 55 left this week · 7 hidden by your filters · Show all"); the card view and empty screen keep it beside the filter button.
- The plugin version had stayed at 1.89.17 since v1.89.18 (a failed step skipped the bump), so browsers kept the cached 1.89.17 scripts and styles. Now 1.89.21.

## 1.89.20 — 2026-10-25

- Discover: "N hidden by your filters · Show all" beside the filter button, so it shows in the one-card view too.

## 1.89.19 — 2026-10-25

- "N hidden by your filters · Show all" on Discover and Requests: a small button that clears every filter and reloads (`csmFilterBar.showAll`).

## 1.89.18 — 2026-10-25

- Women open phone numbers with Premium; men still need Gold (`Gold::can_reveal`, `Gold::is_female`).
- Pricing: for women "Call or WhatsApp any member directly" moves from Gold to Premium; men keep it under Gold. Footnote made neutral.
- Who sees what: men get "Premium and Gold members" / "Only me" for the phone; women keep "Gold members" / "Gold and Premium members" / "Only me".
- "Upgrade to view" shows only to viewers whom an upgrade would help. Gold launch email and banner name the right audience for each member.

## 1.89.17 — 2026-10-25

- Profile cards: the phone number is an ordinary row in its field's place in the details, not a box under the photo. Locked numbers read "🔒 Upgrade to view" (a link to pricing); Gold sees "View number"; an opened number has Call and WhatsApp links inline.

## 1.89.16 — 2026-10-25

- ICAI document upload (profile editor) and the "Get your Verified CA badge" popup say the document is never shared with any other member; they only see the Verified tag.

## 1.89.15 — 2026-10-25

- Phone numbers are no longer shown to matches (owner: "they can anyway share it in chat"). Choices on Who sees what: "Gold members" (default), "Gold and Premium members", "Only me". Members who chose "Only me" keep it; the brief "My matches only" counts as Only me. Other fields are unchanged.
- Profile cards say "Visible to Gold members" (or "Gold and Premium members") instead of "shown after you match". Pricing drops "Phone number after a match" from Free; the Gold launch email and banner no longer mention matches.

## 1.89.14 — 2026-10-25

- Who sees what: the phone number has its own choices — "Matches, Gold and Premium members", "Matches and Gold members" (the default, and what every existing number already does), "My matches only" and "Only me". Stored as user meta `csm_phone_audience`; BuddyPress keeps "friends" (or "adminsonly") so nothing else shows the number.
- `Gold::can_reveal( $viewer, $profile )` follows that choice: Premium members can open numbers whose owner allows Premium (same daily cap, same match request); "My matches only" closes the number to Gold too.

## 1.89.13 — 2026-10-25

- One filter for Discover and Requests (owner: "exactly the same as discover, we dont need two separate codes"). The filter button and sheet moved to `app-screens.js` / `app-screens.css` as `window.csmFilterBar`, used by both screens. Requests now uses the member's saved Discover filters, applied on the server with `Filters::keep()`; setting a filter on either screen changes both. The old Requests drop-downs, `filterKeys`, per-row `attrs` and `Filters::profile_attrs()` are gone.
- Requests shows how many received requests the filters are hiding.

## 1.89.12 — 2026-10-25

- Filter sheet: dropped the line explaining that filters narrow the week's set (owner: obvious).

## 1.89.11 — 2026-10-25

- Discover filters now only narrow the profiles already in the member's tray (owner: "the filter should only work on the profiles in my tray"). Applying a filter no longer clears the undecided profiles and refills the tray with matching ones, and the refill no longer uses the filters. Hidden profiles stay in the tray and come back when the filter is changed or cleared.
- The grid heading says how many profiles the filters are hiding, and an empty screen caused by filters says so instead of "That's your 50 for this week".

## 1.89.10 — 2026-10-25

- Discover filters: the sheet sent its choices without saying they were JSON, so WordPress read nothing and Apply saved an empty filter (wiping age, height, community and the rest). The request now carries `Content-Type: application/json`.

## 1.89.9 — 2026-10-25

- Discover filter sheet: on phones it now sits above the bottom nav, so Clear and Apply are always visible (a later `86vh` rule had undone the earlier visible-screen fix).
- Filter sheet, filter button, chips, ticks and grid tiles use the site colours (blue, ink, muted) instead of the old maroon.

## 1.89.8 — 2026-10-25
- Age and height filters accept one end on "Any" (e.g. "from 5′4″" with no
  upper limit). The minimum span applies only when both ends are chosen.

## 1.89.7 — 2026-10-25
- Discover grid heading shows the real number: "Unlimited profiles" for
  Gold, "N of 50 left this week" for everyone else, instead of how many
  happened to be loaded ("24 waiting for you").

## 1.89.6 — 2026-10-25
- The Discover card "Get N more profiles this week" (photo / verify CA) has
  a ✕; closing it hides it for good for that member (`csm_boost_card_hidden`).
  The extra profiles are still given when earned.

## 1.89.5 — 2026-10-25
- Pricing cards: Free shows ₹0 (not "Free" twice), no "+5" detail; Premium
  and Gold lines written out in full, adding "send a request to anyone"
  (Gold) and "keep all your saved profiles" (Premium).

## 1.89.4 — 2026-10-25
- Pricing page redesigned as three cards: Free; Premium, "Everything in
  Free, plus:"; Gold, "Everything in Premium, plus:". Filters are one line,
  "Advanced filters". The comparison tables are gone. Cards stack on phones.

## 1.89.3 — 2026-10-25
- Pricing page: drawn whenever the page's content is output after the
  head, not only inside WordPress's main loop, so page builders (Elementor)
  show it. Drawn once per page.

## 1.89.2 — 2026-10-25
- A **full refund** (order status Refunded) ends the membership the order
  bought: Premium ends; a Gold upgrade goes back to the Premium the member
  had, until its old end date. Partial refunds change nothing. An order
  note records it; events `membership_refunded` /
  `gold_refunded_premium_restored`. The level before checkout is kept on
  the order (`_csm_prev_level`).

## 1.89.1 — 2026-10-25
- Before launch, the owner's test accounts (yugantargupta@gmail.com and its
  +aliases; option `csm_gold_tester_regex`) can preview the new pricing page
  with `?v2=1` and buy Gold, to test the real purchase and upgrade-credit
  flow.

## 1.89.0 — 2026-10-25
Gold, part 3: launch.
- `wp csm gold notice --launch=YYYY-MM-DD [--dry-run]` (at least 3 days
  ahead): queues the phone-number notice email to every member whose number
  is not "Only me", and shows a banner in the app until launch ("From 1
  November, your matches and Gold members can see your phone number",
  linking to Privacy settings). `wp csm gold launch` switches everything on
  now; on the launch date it happens by itself (new pricing page, Gold on
  sale, Gold phone access). `wp csm gold status`.
- Upgrade prompts, Gold ones only once Gold is on sale: "Call now with
  Gold" on a locked phone row; Discover's end-of-week message mentions Gold's
  unlimited profiles; Requests → Sent: "Gold puts your requests at the top
  of their list".

## 1.88.3 — 2026-10-25
- Premium women's 50 a week also load 24 at a time with "Show more
  profiles" (they were all loaded at once). Their weekly total is
  unchanged.

## 1.88.2 — 2026-10-25
- Gold is described as **unlimited profiles** everywhere (pricing page,
  filter sheet, tour); no "50 at a time". Discover's grid loads 24 and a
  **Show more profiles** button loads the next page; when nobody new is
  left it says so. Queue endpoint takes `more=1`.

## 1.88.1 — 2026-10-25
- Gold sees every member **in Discover** instead of a separate Browse
  screen (owner: "do we really need another screen"). No weekly limit; 50
  undecided at a time in the grid view, refilled as they decide, with their
  filters. Empty Discover for Gold says they have seen everyone who matches.
  New engine filter `csm_tray_open_cap`. The /browse/ screen is removed.

## 1.88.0 — 2026-10-25
Gold, part 2.
- **Browse all members** (`/browse/`, Gold only): every opposite-gender
  member, most recently active first, using the member's Discover filters,
  24 at a time. Linked from Discover ("Browse all members →"). Blocked,
  closed, paused and held accounts are left out. Gold can send a request to
  anyone from their profile (Premium: only to people who viewed them).
- **New pricing page** (`Premium\PricingPage`): men's and women's wording,
  a short comparison, "Compare all features", "Buy Premium" / "Buy Gold",
  badge "Best chance of a match" (Gold, men) / "Most popular" (Premium,
  women), "Your plan" for current members, the upgrade credit shown to
  Premium members, "Founding places taken" at the cap. Off until
  `csm_pricing_v2` = 1; admins preview with `?v2=1`. The Gold column shows
  only while `csm_gold_on_sale` = 1 (or in the admin preview).
- Sales Dashboard: one Gold line (members, founding places, on sale,
  phone access, ceilings).

## 1.87.0 — 2026-10-25
Gold, part 1 (PMPro level 3, WooCommerce product 17394, ₹7,999 / year).
- Gold counts as Premium everywhere (`Membership::is_premium`), plus
  `Membership::is_gold`. Dashboard shows "Gold".
- **Ranking:** Gold profiles score 3x (`csm_gold_multiplier`) and ignore the
  weekly ceilings (`csm_gold_ceiling_exempt`, on). The 30-days-inactive rule
  still applies.
- **Priority requests:** a Gold member's requests come first on the
  receiver's Received list, highlighted "Gold · Priority".
- **Phone numbers:** Gold sees "View number" on every profile except "Only
  me". The first time, it explains that viewing sends a match request; the
  request is sent, the number shown, and the view logged
  (`wp_csm_phone_views`, event `gold_phone_view`). 20 new numbers a day.
  Off until launch: `csm_gold_phone_live` = 1 turns it on.
- **20 profiles a week** for Gold men (women keep 50). **Gold badge** on
  the card for Gold members who are verified.
- **Upgrade credit:** at checkout, a Premium member buying Gold gets
  ₹1,499 × unused days / 365 off.
- **Founding cap:** Gold stops selling at `csm_gold_cap` (25) members.

## 1.86.2 — 2026-10-24
- **Community: type freely, confirm the group.** In onboarding and Edit
  profile, once the member stops typing their community, a "Community
  group" list appears below it, pre-selected from their words ("baniya" →
  Agarwal / Marwari / Maheshwari / Baniya). They can change it, e.g. to
  Gujarati Vania / Vaishnav, or choose Others. The profile still shows what
  they typed; filters and ranking use the group. The choice is tied to the
  words it was made for (`csm_bucket_community_set`); new words are grouped
  again.
- **Mother tongue: typeahead.** Typing "Hin" offers Hindi; free text is
  still accepted and grouped as before.
- REST `csm/v1/buckets/suggest` and `csm/v1/buckets/choose`.

## 1.86.1 — 2026-10-24
- Profiles show Community exactly as the member typed it (the 1.86.0
  Community / Sub-community split is withdrawn).
- Community and mother-tongue filters are tick boxes of the groups, with
  Select all / Clear. Only ticked groups are shown; **Others** covers
  everyone with no group (an answer that fits none, or none given) and is
  shown only when ticked. Same "Others" value on Requests → Received.

## 1.86.0 — 2026-10-24
- **Community and mother-tongue groups** (`Core\Buckets`). Both questions
  are free text (800 different community answers, 330 language answers).
  Each answer is now sorted into groups by an ordered rules table: 38
  community groups (e.g. Agarwal, Aggarwal, Agrawal, Marwari, Maheshwari,
  Baniya, Gupta → "Agarwal / Marwari / Maheshwari / Baniya") and 24
  languages (a regional language wins over Hindi; English only counts on its
  own). Tested on the 24 Oct export: about 220 community and 9 language
  answers fit no group (mostly "hindu", "general", "na").
- Filters (Discover and Requests) run on the groups; members with no group
  are never filtered out. Ranking's "same community" and "same mother
  tongue" points compare groups.
- Profiles show **Community** (the group) and **Sub-community** (the
  member's own words) when the words say more than the group.
- Stored as user meta `csm_bucket_community` / `csm_bucket_tongue`, updated
  on every profile save. `wp csm buckets rebuild` and `wp csm buckets report`.

## 1.85.1 — 2026-10-24
- Filters on free-text fields (Community on this install) offer one switch,
  "Same community as mine", instead of a list of every spelling members
  typed. Values are compared ignoring case and extra spaces; profiles that
  left it blank are still shown. Applies to any filter field without fixed
  options, in Discover and on Requests → Received.

## 1.85.0 — 2026-10-24
Premium additions (step 2 of the Premium/Gold plan).
- **Discover filters for every Premium member** (until now Premium women
  only): age and height as before, plus community, mother tongue, religion,
  diet, annual income and qualification, as tap-to-choose lists. A profile
  that has not answered a question is still shown. Free members see the
  filter control locked, linking to pricing. The 50-a-week quota stays a
  Premium-women rule.
- **Filters on Requests → Received** for Premium: by the same dimensions and
  age, using values present among the senders (fields a sender hides are
  respected). Free members see it locked.
- **Note with a request** for Premium: tapping Like offers "Add a note"
  (up to 200 characters, no phone numbers, emails, links or handles). The
  note shows on the receiver's Requests list and in the request email.
  Table `wp_csm_request_notes`.

## 1.84.0 — 2026-10-24
- **Phone row on every profile card** (Discover, member pages, preview).
  Until now the number was never shown to anyone, matches included,
  whatever the member's privacy setting said.
  - Matches see the number, with Call and WhatsApp buttons.
  - The member sees their own number.
  - Everyone else sees "Hidden · shown after you match".
  - "Only me" shows "Not shared" to everyone.
  - "Everyone" / "All members" are treated as "My matches" for the phone,
    so no number reaches a stranger by accident.
  - `csm_phone_visible` filter, for Gold access later.

## 1.83.2 — 2026-10-23
- Fix: the Sales Dashboard's activity tiles crashed after the first
  WooCommerce refund. A refund is stored as its own order record with no
  customer; lifetime revenue now reads real orders only (refunds are still
  subtracted from each order's total).

## 1.83.1 — 2026-10-23
- Sales Dashboard: each panel (activity tiles, email alert, sign-ups by
  channel, sign-up funnel) is drawn separately; one that fails shows its
  error in place instead of a "critical error" for the whole page.
- Lifetime revenue (which loads every WooCommerce order) is cached for an hour.

## 1.83.0 — 2026-10-22
- Premium members can send a request to anyone who viewed their profile
  ("Who viewed me"), even if that person is not in their weekly set. The
  member page shows **Send request** instead of "This profile is not in your
  current set". The server checks it is a viewer, the opposite gender, not
  blocked, paused, on hold or already connected. It does not use up a
  weekly profile.

## 1.82.2 — 2026-10-22
- One report no longer hides a profile. It stays visible while OpenAI checks
  it, and is hidden only if the AI finds it clearly fake or spam (a cartoon
  with a throwaway bio, offensive content, asking for money, an obvious
  scam). Anything plausibly real passes; when in doubt, it passes. A hidden
  profile is emailed and waits on the Reports page for you.
- 2+ reports from different members still hide the profile until you
  release it. The reporter now sees "Thank you. We will review this profile."

## 1.82.1 — 2026-10-16
- CA Verify decides every document: verify or reject, never "review"
  (owner: "I don't want to leave things in pending queue"). Withdraws the
  1.82.0 change that sent genuine ICAI documents to the queue.
- Rejections say exactly what was wrong, in the AI's own sentence for this
  member (e.g. "You uploaded an Aadhaar card, which is not an ICAI document.
  Please upload…"), stored as `csm_av_member_msg`. Reviewer rejections use the
  fixed reason. The email and the profile note both say the first check is
  automated and sometimes wrong, and to email support@cashaadi.in.
- Extra pages: under the ICAI upload, "Add another page" (up to 3, PDF/JPG/
  PNG, 10 MB each) for results split across pages such as Group 1 and
  Group 2. All pages go to the AI together and appear in the admin queue.
  Adding a page restarts the check.

## 1.82.0 — 2026-10-16
- CA Verify: for a CA Inter claim, any CA Final document (admit card,
  registration, result or marksheet) now counts as proof, since only
  students who cleared Intermediate can sit Final.
- CA Verify queue: search by name, email or member ID, and filter by status
  (needs review, approved, rejected, not checked yet).

## 1.81.1 — 2026-10-12
- Reports and photo holds move off the Sales Dashboard (owner: "don't
  clutter sales dashboard with this") to their own admin page, **Reports**,
  whose menu item shows how many need a human. The Hold links are gone from
  the dashboard member list; holding by hand is from the member's profile.

## 1.81.0 — 2026-10-12
- **Reports are checked by OpenAI first** (`Photos\ReportReview`).
  - 1 report: the profile is hidden at once and, about 30 s later, its photos
    (up to 4, read from disk) and profile text (no contact details) go to
    OpenAI (the CA Verify key and model; override with option
    `csm_report_ai_model`). Pass: visible again, and the member is never
    told. Not passed: stays hidden, the member is emailed, and it waits for
    a human. An API error retries once after 10 minutes, then goes to a human.
  - 2+ reports from different members: hidden until a human releases it,
    whatever the AI said. A member counts once per profile.
  - The "Photo holds" panel shows the AI's decision and reasons, and "Needs
    your review" for anything waiting on you. An admin release clears the
    report count; an AI pass keeps it, so one more report goes to a human.
  - Event `report_ai_review` (decision, confidence, reasons) is logged.

## 1.80.0 — 2026-10-12
- **Report profile**: a quiet "Report profile" link at the foot of every
  profile card (Discover and member pages). Reasons: fake profile or photo,
  inappropriate photo or text, rude or abusive, something else.
  - One report puts the profile on hold (owner: "even a single report should
    be enough for now"): hidden from Discover and the directory, and an
    email "Your profile is under review" asks them to update their photo and
    About me. The admin releases it from the "Photo holds" panel, which shows
    the reason, who reported, and the count of further reports.
  - Up to 5 reports per member per day. Event `profile_reported` is logged.

## 1.79.0 — 2026-10-12
- **Photo hold** (`Photos\PhotoHold`) for profiles that look fake: a photo
  that is not a person, or does not match the profile's gender.
  - An admin holds a member from the Sales Dashboard list ("Hold: not a
    person · wrong gender · not genuine") or from the member's profile page.
  - While held: not in Discover (and pulled from unacted trays), not in the
    directory; the member sees a banner and gets one email asking for a clear
    photo of themselves. Matches and conversations are untouched.
  - A new photo marks the hold "new photo: review" at the top of the
    dashboard's "Photo holds" panel. The hold stays until an admin presses
    Release, which emails "your profile is visible again".
  - Events `photo_hold` and `photo_release` are logged.

## 1.78.2 — 2026-10-12
- Fix: the Discover "Next profile" button was drawn as a white card with a
  big margin, out of line with the other buttons. The "While you wait" panel
  (v1.71.0) used the same class name, `csm-d-next`; it is now `csm-d-wait`.

## 1.78.1 — 2026-10-10
- ZeptoMail sends retry once (after 2 s, 25 s timeout) on a network error such
  as a cURL timeout. HTTP errors (credits, auth) are not retried.
- The Sales Dashboard mail alert tells a network timeout apart from a ZeptoMail
  refusal, and only suggests checking credits for the latter.

## 1.78.0 — 2026-10-09
- **Unanswered match requests** (`Matches\RequestsWaiting`). On 9 Oct 2,446
  requests were pending, 94% waiting on women; 60% of those women had not been
  back since the request arrived.
  - Weekly reminder email "N people are waiting for your answer" (names up to
    three), to members with pending requests who have been away 3+ days and
    got a request after their last visit. Respects the "Match activity" email
    switch; skips paused and closed accounts. Type `csm-reqwait-<week>`.
  - Popup on login "N match requests waiting" → /requests/. Shown again only
    when a new request has arrived. It comes after the 15-profiles popup and
    before the boost, social and verify popups.
  - Requests from blocked or closed accounts are not counted.

## 1.77.2 — 2026-10-08
- `wp csm audience [--exclude=IDs]` prints a Meta customer list (email,
  phone, name, city, country, gender, birth year) of every registered member
  except admins and closed accounts, as CSV on stdout. Used as a lookalike
  seed; nothing is stored on the server.

## 1.77.1 — 2026-10-08
- `wp csm signup recover --since="2026-10-08 00:00" [--dry-run]` sends a
  fresh code to everyone who signed up in the window and never activated.
  Each person gets one email, with an apology, a code valid for 48 hours
  (`--hours`), and an "Enter your code" button that opens the activation page
  with their email filled in. Addresses that already have an account are
  skipped. It stops if the first 3 sends fail.

## 1.77.0 — 2026-10-08
- New **sign-up funnel tracker** (`Analytics\Funnel`, table `wp_csm_funnel`).
  It records each step from opening /register/ to finishing onboarding:
  page opened, form started, form submitted, form rejected (and which fields),
  code issued, code email sent or **failed (with the error)**, new code asked
  for, wrong code entered, account created, onboarding opened, each
  onboarding step, onboarding finished.
- The Sales Dashboard has a collapsible "Sign-up funnel" section with people
  per step, step-to-step drop-off, why forms were rejected, onboarding steps,
  and a by-day table.
- A red alert at the top of the Sales Dashboard when sign-up code emails (or
  any ZeptoMail send) failed in the last 24 hours. This follows the 8 Oct
  outage, when the ZeptoMail credits ran out and code emails failed silently.

## 1.76.1 — 2026-10-06
- The 1.76.0 first-week photo floor is withdrawn (owner). Instead, the ranking
  weights rise from +4 to **+10 for a photo** and from +3 to **+6 for
  verified**, for every member. Both can be tuned in `csm_rank_points`.

## 1.75.1 — 2026-10-04
- Verification is offered only to members whose Qualification is CA or CA
  Inter. This covers the popup, the Discover card and the "While you wait"
  suggestions, with a new `CaVerify::claim()` check.
- CA Inter members are asked for their **CA Inter ID** (ICAI registration card
  or Intermediate marksheet), not an ICAI certificate.
- Members with any other qualification see only the photo offer, and their
  verification note says it's for CA and CA Inter members.

## 1.75.0 — 2026-10-04
- **Combined one-time popup:** "Get 10 more profiles this week". It tells the
  member to add a photo and/or verify their CA (+5 each), says that this
  increases their chances 5 times, and links to the first thing left to do.
  - Shown once to anyone with something left to earn. A verification already
    in review doesn't count as something left.
  - It replaces the verify-only popup. That test showed a one-time prompt more
    than doubled ICAI uploads: 6.3% without the popup, 14.4% with it, 270 vs
    271 members.
- Discover end-of-week screen: the same offer is repeated at the top.
  Photo and verification are no longer listed again under "While you wait".

## 1.74.2 — 2026-10-04
- Verification-popup test ended. Setting `csm_verify_nudge_pct` to 0 now also
  stops popups for members already in the show group. Their recorded groups
  are kept, so the result can still be read.

## 1.74.1 — 2026-10-04
- Data is kept permanently for every member. The 400-day prune of daily
  activity (`csm_active_days`) and the 180-day prune of the event log are
  switched off.

## 1.74.0 — 2026-10-04
- Member details ("what they were") moved out of the daily table into a
  **change history**, `wp_csm_member_state`:
  - A row is written only when something differs from the member's previous
    row: gender, age, premium, quota, photos, verified, paused, closed, details
    left.
  - It is triggered by a profile edit, a photo / verification / pause / close
    change or a membership change, plus a nightly check for changes nothing
    announces, such as a Premium expiry.
  - `wp csm journey states` records a one-time starting row for everyone.
- `wp_csm_member_day` now has rows only for days something happened.
- **Conversation history**, `wp_csm_convo_day`: one row per pair per day they
  messaged, with each side's message count. One-to-one conversations only. It
  can be rebuilt with backfill.
- Export: `member_state` and `convo_day`.

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
