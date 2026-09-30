---
journey: activation-creates-pages
plugin: wpmediaverse
priority: critical
roles: [administrator]
covers: [activation-page-defaults, mvs-page-explore, mvs-page-dashboard, mvs-page-upload, MV-WIZ-005, MV-WIZ-006]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse v1.2.0+ installed (active or inactive)"
  - "WP-CLI available"
estimated_runtime_minutes: 2
---

# Activation creates Explore / Dashboard / Upload pages with shortcodes

**Why this journey exists**: A site owner installing WPMediaVerse must NOT have to hand-create three pages and paste shortcodes before any frontend feature works. Prior to this fix, only Explore + Dashboard were auto-created — the Upload form had no host page on a fresh install, so the "Upload" link in the Explore header pointed at page-id 0 (404). This journey verifies activation auto-creates all three pages with the right shortcode embedded, and that re-activating doesn't duplicate them.

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- DB pre-condition: clean install OR plugin previously active. Either is fine — the test reactivates to force `Activator::activate()` to run.

## Steps

### 1. Reset to a clean state, then reactivate

- **Action**:
  ```
  wp option delete mvs_page_explore mvs_page_dashboard mvs_page_upload
  wp post delete $(wp post list --post_type=page --name=explore-media --format=ids)  --force 2>/dev/null || true
  wp post delete $(wp post list --post_type=page --name=my-media       --format=ids)  --force 2>/dev/null || true
  wp post delete $(wp post list --post_type=page --name=upload-media   --format=ids)  --force 2>/dev/null || true
  wp plugin deactivate wpmediaverse && wp plugin activate wpmediaverse
  ```
- **Expect**: deactivate + activate both succeed.

### 2. All three page options point at real published pages

- **Action**:
  ```
  wp option get mvs_page_explore
  wp option get mvs_page_dashboard
  wp option get mvs_page_upload
  ```
- **Expect**: each returns a positive integer; that integer corresponds to a `post_type=page, post_status=publish` row.

### 3. Each page contains the right shortcode

- **Action**:
  ```
  wp post get $(wp option get mvs_page_explore)   --field=post_content
  wp post get $(wp option get mvs_page_dashboard) --field=post_content
  wp post get $(wp option get mvs_page_upload)    --field=post_content
  ```
- **Expect**:
  - Explore content contains `[mvs_gallery`
    - A clean 2.4.2+ install writes exactly `[mvs_gallery]`. An older site keeps
      `[mvs_gallery columns="3" count="24"]`: reactivation does NOT rewrite an
      existing page (Activator falls through to the slug lookup), and both extra
      attributes are stripped by `shortcode_atts` anyway, so the two render the
      same. Assert the shortcode is present, not the attributes it never had.
      Basecamp 10297763946.
  - Dashboard content contains `[mvs_dashboard]`
  - Upload content contains `[mvs_upload]`

### 4. Frontend renders WPMediaVerse UI on each page

- **Action**: `playwright_navigate` to each page permalink in turn.
- **Expect**:
  - `/explore-media/` renders the explore grid (selector `.mvs-explore` or `.mvs-gallery`).
  - `/my-media/` renders the dashboard shell (selector `.mvs-dashboard`).
  - `/upload-media/` renders the upload form (selector `.mvs-upload-form` or a form with a file input).

### 5. Re-activation is idempotent

- **Action**:
  ```
  wp plugin deactivate wpmediaverse && wp plugin activate wpmediaverse
  wp post list --post_type=page --name=upload-media --format=count
  ```
- **Expect**: `1` (single upload page, no duplicate).

### 6. Owner-customised pages are respected

- **Action**: rename the upload page (e.g. WP admin → Pages → "Upload Media" → change title to "Submit a photo"), keep the shortcode, then deactivate + activate the plugin. Check `mvs_page_upload`.
- **Expect**: option still points at the same post id (the customised page is NOT replaced).

### 7. Unrelated same-titled page is never falsely adopted (MV-WIZ-005)

- **Action**: Create a page titled exactly "My Media" by hand, with unrelated content and NO `[mvs_dashboard]` shortcode. Delete the `mvs_page_dashboard` option (`wp option delete mvs_page_dashboard`) so activation has to resolve the page fresh. Deactivate + reactivate the plugin.
- **Expect**: `create_pages()`'s adoption order is: (1) existing option pointing at a live page with the shortcode, (2) a published page at the expected slug (`my-media`) carrying the shortcode, (3) a published page matching the exact title carrying the shortcode, (4) create new. The hand-made "My Media" page fails step 3 (title matches, shortcode does not), so it is skipped and a NEW page is created instead. `mvs_page_dashboard` now points at the new page, not the hand-made one, and the hand-made page's content is completely unchanged (confirm via `wp post get <its id> --field=post_content`).

### 8. Activation never edits the site's navigation menu (MV-WIZ-006)

- **Action**: Enable WordPress core's "Automatically add new top-level pages to this menu" option on the site's active nav menu (Appearance > Menus > Menu Options, or `wp option get nav_menu_options` shows the menu's id under `auto_add`). Delete `mvs_page_upload` and its page so activation has to create a fresh one. Deactivate + reactivate the plugin.
- **Expect**: `create_pages()` detaches WordPress core's `_wp_auto_add_pages_to_menu` (hooked on `transition_post_status`) before creating pages and reattaches it in a `finally`-equivalent step right after the loop (Coding Rule 17). The newly created Upload page does NOT appear in that nav menu automatically, even though the core option that would normally add it is on. Confirm the hook was genuinely restored, not permanently removed: create an unrelated new page by hand (Pages > Add New > Publish) afterward and confirm WordPress's own auto-add DOES add that page to the menu — proving the detach was scoped to activation, not a lasting regression.

## Pass criteria

ALL of the following hold:

1. After fresh activation, `mvs_page_explore`, `mvs_page_dashboard`, and `mvs_page_upload` all return positive integers.
2. Each option points at a published `page` whose content embeds the corresponding shortcode.
3. Hitting each page permalink renders the WPMediaVerse UI — not an empty WordPress page.
4. Re-activating the plugin does NOT duplicate any of the three pages.
5. A user-renamed page is preserved across activate/deactivate cycles.
6. A pre-existing page sharing a target title but lacking (or having the wrong) shortcode is never adopted — activation creates a new page instead and leaves the unrelated page's content untouched.
7. Activation never adds its created pages to a nav menu configured to auto-add new top-level pages, and WordPress's own auto-add keeps working normally for pages the plugin didn't create, immediately afterward.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `mvs_page_upload` is `0` | `Activator::create_pages()` is missing the upload entry | `includes/Core/Activator.php` |
| Upload page rendered but no form shows | Shortcode missing or `[mvs_upload]` not registered | `includes/Shortcodes/Shortcodes.php` |
| Re-activation creates `upload-media-2` page | Lookup-by-slug step in `create_pages()` regressed | `includes/Core/Activator.php::create_pages()` step 2 |
| Renaming a page wipes the customisation | The "skip if existing option points at a live page" guard is broken | `includes/Core/Activator.php::create_pages()` step 1 |
| An unrelated same-titled page gets adopted (MV-WIZ-005) | The title-match branch (step 3) dropped its shortcode check | `includes/Core/Activator.php::create_pages()` step 3 |
| A newly created page appears in the nav menu (MV-WIZ-006) | `remove_action('transition_post_status', '_wp_auto_add_pages_to_menu', 10)` missing or its re-`add_action()` guard is skipped on an early `continue`/return | `includes/Core/Activator.php::create_pages()` |
| Unrelated pages stop auto-adding to menus after any MediaVerse activation | The detached hook was never reattached (permanent removal, not scoped) | `includes/Core/Activator.php::create_pages()` (the `$auto_add_detached` re-`add_action()` call) |
