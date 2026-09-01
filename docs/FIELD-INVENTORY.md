# Backend field inventory — the contract the UI is built from

**Principle (owner, 2026-09-01): the front-end design canvas is _suggestive_.
The member area must be built from the fields and features that actually exist
in the backend — not from content invented for the mock.**

Practical consequences:

* The mock's Hinge-style **prompts** ("A perfect Sunday looks like —") are **not
  being built**: there is no prompt field or table on this site. The Discover
  card shows **Bio**, the real equivalent.
* The mock's three profile sections ("About & Basics / Professional / Family &
  Lifestyle") are illustrative. The profile screen renders the **seven real
  xProfile groups** in `Config::GROUP_ORDER`.
* This is a **matrimonial** data model (Religion, Community, Mother Tongue,
  Family, Diet, Annual Income) — not a dating one. Surfacing those is what makes
  the product useful; copying Hinge's information architecture would not.

---

## xProfile groups and fields

Verified live on staging2 (2026-09-01) from the profile-edit screens.
`*` = required. Only the first three groups define required fields.

| Group (id) | Fields |
|---|---|
| **Basic Details** (1) | Name\*, Created for, Phone Number\*, Gender\*, Bio, Date of birth\*, City\*, Height, Age |
| **Professional details** (7) | Qualification\*, Other Qualifications, Occupation Status\*, Current Job Title, Company Name, Annual Income |
| **Community** (6) | Religion, Language (Mother Tongue)\*, Community |
| **Lifestyle and Habits** (4) | Drinking, Smoking, Diet |
| **Family Details** (9) | Nuclear/Joint, Father's Occupation, Mother's Occupation, Family Affluence, Family Details |
| **Hobbies and Interests** (8) | Hobbies and Interests |
| **Verification** (10) | ICAI ID, Other relevant documents |

Known field ids live in `Core\Config`: phone 277, age 286, gender 299, height
228, bio 496, dob 586, CA doc 484, qualification 571.

### Gotchas that have already caused bugs

* **`Location` and `About Me` do not exist.** The Discover card asked for both
  and silently rendered nothing for every member until v0.29.0. The real fields
  are **`City`** and **`Bio`**.
* **`Age` is filtered** and can return `"27 years old"`, not `27`. Extract digits
  before using it as a number/suffix.
* **`Height` is centimetres.** Convert for display (`5′ 6″`); the wizard does the
  same conversion in JS.
* **Only groups 1, 7 and 6 have required fields.** Any "is this section done?"
  logic based purely on required-empty will report the other four groups
  Complete even when they are entirely blank (fixed in v0.28.1 by counting empty
  fields for groups with no required fields).

### ⚠️ Per-field visibility must be enforced by hand

Every field carries a visibility setting — **Everyone / Only Me / All Members /
My Friends**. `xprofile_get_field_data()` **does not enforce it**; BuddyPress
applies visibility inside its own profile loop. Any custom screen that reads
fields directly (Discover cards, future match/search cards) **must** filter
through:

```php
$hidden = bp_xprofile_get_hidden_fields_for_user( $displayed_user_id, $viewer_id );
```

and skip field ids in that list. The Discover card did not, and would have shown
restricted City / Bio / Company Name / Height to everyone (fixed in v0.29.1).

---

## Features behind the UI

| Feature | Where it lives | Notes |
|---|---|---|
| Discover tray, like/pass, weekly quota | `Modules\Discover` + `cashaadi()` mu-plugin | free 5/wk, Premium 10/wk |
| Matches / requests | BuddyPress Friends + `Modules\Matches` | Requests-Sent sub-tab |
| Who viewed me | `Modules\Premium` | premium sees list, free sees locked teaser |
| Block | `Modules\Block` | mutual hide, `wp_csm_blocks` |
| Photos + privacy | `Modules\Photos` | per-photo, blur for non-matches |
| Phone OTP | `#11618` snippet (not migrated) | `csm_phone_verified` |
| ICAI doc AI verification | `Modules\CaVerify` | `csm_av_status` |
| Premium checkout | `Modules\Premium` + Woo/PMPro | product 11566, level 2 |
| Reminder emails | `#11732/#11733` snippets (not migrated) | paused |
