---
journey: settings-storage-display-tab
plugin: wpmediaverse
priority: high
roles: [administrator, member]
covers: [MV-SET-007, MV-SET-009, MV-SET-013, MV-SET-016, MV-SET-017, MV-SET-018, MV-SET-019, settings-storage-display-tab]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WP-CLI + mysql_query access for option checks"
  - "A library with 20+ media items for pagination steps"
  - "MediaVerse Pro NOT active (so Pro storage/layout options render locked, per MV-SET-007/016)"
estimated_runtime_minutes: 10
---

# Storage and Display tab settings persist and gate real rendering/storage behavior

**Why this journey exists**: seven owner-facing settings on the Storage and Display tabs, most with no in-band GET route a cert can flip-and-dispatch (a driver choice, a grid column count, a lightbox source are all render-time or write-path decisions). This journey proves each persists and changes the actual pipeline or DOM, and locks two Free-edition boundaries that are easy to regress: cloud drivers must be visibly locked without Pro, and a `wp-config.php`-defined credential must win over the DB option, visibly.

**Manifest note**: `JOURNEY-COVERAGE-MANIFEST.md`'s dedup table claims 8 IDs for this file; a direct grep of `covers:`/proposed-file assignments in the manifest's own per-area tables shows only 7 (MV-SET-008 routes to the existing `admin/10-media-optimization-toggles.md` instead). No 8th ID was found — treated as a manifest overcount, not a missing entry.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Settings screens: `admin.php?page=mvs-settings-storage`, `admin.php?page=mvs-settings-display`.
- A library with 20+ media items (for MV-SET-018), viewable at 1280x800 and 390x844 (for MV-SET-017).

## Steps

### 1. MV-SET-007 — Storage driver (Where files are stored)
- **Action**: Open the Storage tab with Pro inactive; confirm cloud driver options (`s3`, `bunnycdn`, `r2`, `dospaces`) render. Separately, define a storage credential constant in `wp-config.php` and reload the screen.
- **Assert**: `mvs_storage_driver` persists `local` (the only usable value without Pro); cloud options are visibly disabled/locked with "Requires MediaVerse Pro" — never selectable-but-silently-broken. The wp-config-defined field renders LOCKED with "Defined in wp-config.php" text; the stored DB option for that credential is ignored, not merged.

### 2. MV-SET-009 — Backend-only storage tuning (no screen control since 2.6.0)
- **Action**: `wp option get mvs_signed_url_ttl` (expect `3600`); `wp option update mvs_signed_url_ttl 600`; request a signed URL and confirm it expires at the new TTL. Repeat the pattern for `mvs_view_retention_days` (default `90`), `mvs_filename_strategy` (default `hashed`), `mvs_generate_webp` (default `true`).
- **Assert**: each option is still functionally live even with no settings-screen control — the TTL change is observable on the next signed URL issued. Separately confirm `mvs_cloud_direct_public_urls` is fully INERT since 1.4.0: changing it produces no observable effect — this is documented, not a bug to file.

### 3. MV-SET-013 — Allow Downloads
- **Action**: Turn off `mvs_allow_downloads` on the Display tab; view a media item on the frontend as a member.
- **Assert**: the download control/link is entirely absent from the DOM (not a disabled dead button that does nothing on click); right-click-save on the publicly served file is unaffected — this setting only removes the plugin's own download affordance.

### 4. MV-SET-016 — Layout (Display)
- **Action**: Choose "List" from the single Layout select; save; view Explore, the dashboard, an album page, and a collection page on the frontend.
- **Assert**: `mvs_thumbnail_style` persists `list` and `mvs_pro_feed_layout` resets to `grid`; the select shows only options the current (Free) edition supports — no unmarked Pro skin names (Instagram/Pinterest/Flickr/Dribbble) offered as if selectable; the layout change reaches album and collection pages too, not only Explore/dashboard (since 2.5.1).

### 5. MV-SET-017 — Grid Columns
- **Action**: Set `mvs_grid_columns` to `5`; save; view Explore at 1280x800, then at 390x844.
- **Assert**: desktop renders 5 columns; at 390px the responsive breakpoint collapses to fewer columns regardless of the configured desktop count (never 5 unreadable slivers). Confirm the shortcode-attribute immunity: an existing page's `[mvs_gallery columns="4"]` has no effect — only this setting controls column count (Basecamp 10297763946).

### 6. MV-SET-018 — Items Per Page
- **Action**: Set `mvs_items_per_page` to `6` on a library with 20+ items; view Explore.
- **Assert**: exactly 6 items render before Load More/pagination appears; a library with 6 or fewer items shows no dead Load More control. Same shortcode-attribute immunity as Grid Columns — a `count="24"` attribute on an existing page has no effect.

### 7. MV-SET-019 — Backend-only display tuning
- **Action**: `wp option update mvs_lightbox_image_source original`; open the lightbox on a media item.
- **Assert**: the NEXT lightbox open loads the full original file (not the `large` derivative) with no stale cache masking the change — confirm the actually-served file size changed, not just that the option value changed.

## Pass criteria

ALL hold:
1. Every option persists to `wp_options` byte-for-byte after save + reload.
2. Storage driver and layout selects never offer a Pro-only value as if it were usable on Free without a clear lock/label.
3. A `wp-config.php`-defined credential always wins over its DB-stored counterpart, visibly (locked field, not a silently-ignored value).
4. Grid Columns and Items Per Page changes are visible on the frontend and are NOT overridable by shortcode attributes.
5. Backend-only (no-screen) options in both files remain functionally live, proven by an observable runtime effect, not just an option-value check.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Cloud driver selectable without Pro | Missing Pro-gate on the Storage tab render | `includes/Admin/Settings/SettingsRegistrar.php`, `includes/Services/StorageRouter.php` |
| wp-config constant not honored / field editable | Constant-lock check missing in the field renderer | `includes/Admin/Settings/FieldRenderer.php` |
| Signed URL ignores new TTL | `SignedUrlService` reads a cached/default TTL | `includes/Services/SignedUrlService.php` |
| Download control still shows when off | Template doesn't check `mvs_allow_downloads` | `includes/Core/TemplateHelpers.php`, relevant `templates/*.php` |
| Layout change doesn't reach albums/collections | Album/collection template still hardcodes grid | `templates/album.php`, `templates/collection.php` |
| Grid Columns ignored at 390px | Missing/overridden responsive breakpoint rule | `assets/css/frontend.css` |
| Load More shows with nothing more to load | Pagination total-count check wrong | `includes/REST/Pagination.php` |
| Lightbox still serves `large` after option change | Lightbox source read is cached or hardcoded | `assets/js/frontend/lightbox.js` (or equivalent), `includes/Core/MediaUrl.php` |
