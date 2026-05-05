---
journey: activation-creates-pages
plugin: wpmediaverse
priority: critical
roles: [administrator]
covers: [activation-page-defaults, mvs-page-explore, mvs-page-dashboard, mvs-page-upload]
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
  - Explore content contains `[mvs_gallery columns="3" count="24"]`
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

## Pass criteria

ALL of the following hold:

1. After fresh activation, `mvs_page_explore`, `mvs_page_dashboard`, and `mvs_page_upload` all return positive integers.
2. Each option points at a published `page` whose content embeds the corresponding shortcode.
3. Hitting each page permalink renders the WPMediaVerse UI — not an empty WordPress page.
4. Re-activating the plugin does NOT duplicate any of the three pages.
5. A user-renamed page is preserved across activate/deactivate cycles.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `mvs_page_upload` is `0` | `Activator::create_pages()` is missing the upload entry | `includes/Core/Activator.php` |
| Upload page rendered but no form shows | Shortcode missing or `[mvs_upload]` not registered | `includes/Shortcodes/Shortcodes.php` |
| Re-activation creates `upload-media-2` page | Lookup-by-slug step in `create_pages()` regressed | `includes/Core/Activator.php::create_pages()` step 2 |
| Renaming a page wipes the customisation | The "skip if existing option points at a live page" guard is broken | `includes/Core/Activator.php::create_pages()` step 1 |
