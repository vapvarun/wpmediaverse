---
journey: collection-settings-and-integrations
plugin: wpmediaverse
priority: normal
roles: [administrator, subscriber]
covers: [MV-ADM-019, MV-ADM-020, MV-ADM-021, MV-ADM-022, collection-metabox, integrations-page, private-community-notice, role-permissions]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "A Collection post to edit; some tagged/categorized media to match Smart rules against"
  - "A community plugin (e.g. BuddyNext) or the ability to fake mvs_rest_require_auth=true for the Private Community notice"
estimated_runtime_minutes: 8
---

# Owner configures a Collection's rules, checks Integrations, resolves the Private Community notice, and confirms role permissions live in General settings only

**Why this journey exists**: four unrelated but small admin surfaces that don't warrant
their own files: the per-Collection meta box (manual vs. smart rules), the Integrations
page (one-click companion installs), the Private Community Default admin notice (a
one-time nag with real setting-changing buttons), and MV-ADM-022 — confirming the old
role-permission matrix is genuinely gone in 2.6.0 and "Who can upload media" on the
General settings tab is the one real control left.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Collection edit screen: any Collection post's edit-post URL.
- Integrations: `$SITE_URL/wp-admin/admin.php?page=mvs-integrations` (reached from the
  Overview summary card since 2.6.0, no longer in the sidebar).
- Settings > General: `admin.php?page=mvs-settings#general`.

## Steps

### 1. Collection Settings meta box — Manual mode
- **Action**: open a Collection's edit screen, "Collection Settings" meta box, choose
  "Manual".
- **Expect**: description "Add media with the Save button on any media item." shown;
  no rule builder rendered.
- **On fail**: `includes/Admin/CollectionMetaBox.php`.

### 2. Smart mode — add rules across all criteria types
- **Action**: choose "Smart"; add rules for Media Type, Tag, Category, Author,
  Privacy, Date After, Date Before (all-must-match); save.
- **Expect**: each rule-type select shows/hides the matching value control
  (`data-for-key` attributes gate visibility per type — Tag/Category/Privacy get their
  own select, Author/dates get their own inputs); saved rules persist and the
  collection resolves to the matching items on its frontend page.
- **On fail**: `CollectionMetaBox.php` save handler + rule-value template (~L220-290).

### 3. Dynamic rule rows, zero-match safety
- **Action**: click "+ Add Rule" repeatedly, then remove a row, without a page reload.
- **Expect**: rows add/remove client-side without breaking the form. Save a rule set
  with zero possible matches (e.g. an author who owns nothing tagged that way) — the
  collection resolves to an empty (not broken) frontend result.

### 4. Document-type rule is a documented no-op on Free
- **Action**: add a Media Type rule with value "document" on a Free-only site with no
  Pro documents visible in this rule context.
- **Expect**: zero matches, no crash, no error — this is a known, documented no-op
  (the rule key matches MIME type, and documents are `application/pdf`, not
  "document"). Confirm it degrades safely, don't treat the zero-match result itself as
  a bug.

### 5. Integrations page — install/activate/connected states
- **Action**: open Integrations; for an uninstalled companion click "Install free"; for
  an installed-but-inactive one confirm the button reads "Activate"; for an active one
  confirm the "Connected" badge.
- **Expect**: badge/button text matches exactly: "Connected" / "Installed, inactive" /
  "Not installed" (`IntegrationsPage.php` L101-107, L211-217); "Install free" one-click
  installs+activates (nonce `wpmediaverse_install_companion_{slug}`).
- **On fail**: `includes/Admin/IntegrationsPage.php`.

### 6. Integrations — same states/copy on the Overview summary card
- **Action**: compare the Overview page's Integrations summary card against the full
  Integrations page for the same companion.
- **Expect**: identical status badge and copy ("Extend MediaVerse with the Wbcom
  stack. Each plugin works on its own - installing one here does not tie it to
  MediaVerse.") on both screens.

### 7. Integrations page is reachable but not in the sidebar
- **Action**: bookmark `admin.php?page=mvs-integrations`, navigate away, then revisit
  directly.
- **Expect**: page still renders even though no sidebar entry links to it from any
  other MediaVerse screen (only reachable from the Overview card).

### 8. Non-privileged install attempt
- **Action**: as a role without `install_plugins`, attempt the install action (crafted
  request with a valid nonce for their session).
- **Expect**: `wp_die()` 403 "You do not have permission to install plugins."

### 9. Private Community Default notice — appears once, resolves either way
- **Action**: set the host community to private (`mvs_rest_require_auth` filter
  answers true) while `mvs_default_privacy` is still `public` and the notice hasn't
  been answered; visit any MediaVerse admin screen.
- **Expect**: warning notice: "Your community is private, but new uploads default to
  Public." + "Public files can be opened by anyone who has the file address, including
  people who are not members." Rendered as a single POST form (two submit buttons,
  `name="choice"` values `members`/`keep`) — NOT clickable GET links, since the action
  changes a setting (`PrivateCommunityDefault.php` L87, `ACTION` const = `mvs_private_default`).
- **On fail**: `includes/Admin/PrivateCommunityDefault.php`.

### 10. "Use Members Only" changes the setting; "Keep Public" doesn't — both silence the notice
- **Action**: click "Use Members Only" on one test site (or reset the answered flag
  and click "Keep Public" on another run).
- **Expect**: "Use Members Only" flips `mvs_default_privacy` to `members`
  (`update_option( 'mvs_default_privacy', 'members' )`, L112); "Keep Public" leaves the
  option untouched. EITHER choice sets `mvs_private_default_answered` so the notice
  never reappears — confirm by reloading the admin screen after each.
- **On fail**: `PrivateCommunityDefault.php` action handler.

### 11. Notice never shows to a non-admin
- **Action**: as a role without `manage_options`, visit the same admin screens under
  the same private-community condition.
- **Expect**: notice never renders for them (`current_user_can('manage_options')`
  gate).

### 12. Role permissions — no matrix screen, "Who can upload media" is the one real control
- **Action**: check the Settings sidebar for a "Permissions" tab — confirm it does not
  exist. Go to Settings > General > "Who can upload media", untick a role, save; log
  in as a member with that role and confirm the upload button/UI is gone AND
  `POST /mvs/v1/media` is refused for them. Re-tick the role, save, confirm upload
  works again for that role.
- **Expect**: no Permissions tab anywhere in Settings (removed in 2.6.0 intentionally —
  not a bug); the checkbox list on General always matches who can really upload
  (reads live roles, not a stale copy); unticking removes `upload_mvs_media` capability
  and the REST route refuses that role.
- **On fail**: `includes/Admin/Settings/GeneralSettingsRegistrar.php` (`mvs_upload_roles`
  option, ~L260-281), `includes/Capabilities/MediaCapabilities.php`.

### 13. Old role-matrix choices survived the 2.6.0 removal
- **Action**: on a site upgraded from pre-2.6.0 with the old matrix's role choices
  already saved, confirm those choices are still in force post-upgrade (no reset to
  defaults just because the screen disappeared).
- **Expect**: role capabilities set by the old matrix remain intact; only the UI for
  changing them narrowed to "Who can upload media".

### 14. `mvs_settings_sections` filter can bring the matrix back (documented extension point)
- **Action**: (code-review check, no UI action needed) confirm a developer can
  re-register a Permissions section via the `mvs_settings_sections` filter.
- **Expect**: this is an intentional, documented extension point — not a defect that
  the matrix was removed from the default UI.

## Pass criteria

ALL of the following hold:

1. Collection meta box's Manual/Smart modes and all seven rule-value types behave per the documented UI, including the safe zero-match and document-type no-op cases.
2. Integrations page and the Overview summary card show identical, correctly-worded status badges; a non-privileged install attempt is refused with the exact `wp_die()` message; the page stays reachable though absent from the sidebar.
3. The Private Community notice is a POST form (never a GET link), shows the exact documented copy, and either button choice permanently silences it — with only "Use Members Only" changing the actual setting.
4. No Permissions tab exists anywhere in Settings; "Who can upload media" on General is the sole live control and it actually gates both the frontend upload UI and the REST route.
5. Pre-2.6.0 role choices survived the matrix's removal, and the `mvs_settings_sections` filter can restore the old screen for anyone who wants it.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Smart rule value control doesn't switch when rule type changes | `data-for-key` show/hide JS broken | Collection meta box JS / `CollectionMetaBox.php` template |
| Private Community notice implemented as clickable links | Form replaced with `<a href>` during a refactor | `includes/Admin/PrivateCommunityDefault.php` |
| Notice reappears after either choice | `mvs_private_default_answered` not set on both branches | `PrivateCommunityDefault.php` action handler |
| Unticking a role in "Who can upload media" doesn't block REST | `upload_mvs_media` capability not checked in `permission_callback` | `includes/REST/Controller/MediaController.php`, `Capabilities/MediaCapabilities.php` |
| A "Permissions" tab reappears without the filter being used | `GeneralSettingsRegistrar.php` regressed to re-registering the matrix by default | `includes/Admin/Settings/GeneralSettingsRegistrar.php` |
