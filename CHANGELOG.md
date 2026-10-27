# Changelog

Earlier versions are described in the git log (`git log --oneline`), one
commit per version.

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
