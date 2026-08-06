---
journey: storage-switch-migrate-mobile
plugin: wpmediaverse
priority: critical
roles: [administrator]
covers: [storage-management, location-based-display, private-stays-local, migrate-all, migrate-cli-variants, mobile-responsive, i18n]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "WPMediaVerse Pro active (Storage Management lives in Pro)"
  - "A cloud driver is configured (e.g. r2 with wp-config.php credential constants)"
  - "Existing media present: some public, some private; some on local, some on cloud"
estimated_runtime_minutes: 10
---

# Site owner switches storage service, migrates media, and existing media never breaks — desktop and phone

**Why this journey exists**: The 1.4.0 storage model is location-based: media displays from where its file actually lives, switching the active driver never breaks existing media, and private media always stays local. A site owner manages all of this from Settings, Storage. This journey locks that contract end-to-end, including the Storage Management screen at 390px.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Storage settings: `$SITE_URL/wp-admin/admin.php?page=mvs-settings` then the Storage tab (`#storage`).
- Capture current driver: `mysql_query "SELECT option_value FROM wp_options WHERE option_name='mvs_storage_driver'"` -> `ORIG_DRIVER`.

## Steps

### 1. Storage Overview reflects reality
- **Action**: open Settings, Storage; read the Storage Management table.
- **Expect**: one row per service that has media or is active; the active service shows an "Active" pill; counts + sizes are non-negative and match `SELECT count(*) ... GROUP BY` over `file_url` host. The table is a token-styled list (not a raw `widefat` table).
- **On fail**: `wpmediaverse-pro/includes/Admin/CloudOpsManager.php`, `wpmediaverse/includes/Services/CloudOps.php::counts_by_service`.

### 2. Existing media displays under the active cloud driver
- **Action**: with a cloud driver active, load `$SITE_URL/explore-media/`.
- **Expect**: public media on cloud serves directly from its CDN host (img `naturalWidth > 0`); public media still on local serves via `/wp-json/mvs/v1/serve` and also renders; NO broken images.
- **On fail**: `includes/Services/SignedUrlService.php` (`maybe_direct_cloud_*`, `is_cloud_hosted_url`).

### 3. Switch active driver to local, re-check display
- **Action**: `mysql_query "UPDATE wp_options SET option_value='local' WHERE option_name='mvs_storage_driver'"`; reload Explore.
- **Expect**: the SAME media still renders — cloud-hosted items still serve from their CDN host, local items via `/serve`. Switching the driver did NOT break anything.
- **On fail**: location-based resolver regressed to active-driver recompute.

### 4. Private media is local-only
- **Action**: `mysql_query "SELECT file_url FROM wp_mvs_media_index WHERE privacy<>'public' AND status='publish'"`.
- **Expect**: every non-public row's `file_url` is under the local uploads base (no cloud host). Restore: `UPDATE ... SET option_value='$ORIG_DRIVER'`.
- **On fail**: `includes/Services/StorageService.php::get_driver_for_privacy`, `UploadService::handle`.

### 5. Switch-service guard + migrate-all
- **Action**: with a cloud driver active and some local public media, confirm the guard notice shows the still-local count; click "Migrate all" and watch the progress bar to completion (or run one chunk).
- **Expect**: notice names the remaining count; progress bar advances and ends; migrated items' `file_url` rewrites to the cloud host; the overview counts shift accordingly.
- **On fail**: `CloudOpsManager::ajax_migrate_chunk`, `assets/js/admin-storage-mgmt.js`, `Services/CloudOps::migrate_one`.

### 5b. CLI migrate moves the VARIANTS, not just the original (REQUIRED)
- **Why**: the admin "Migrate all" button goes through `CloudOps::migrate_one`, but `wp mvs migrate-storage` used to carry its own loop that transferred `file_path` alone. Thumbnails and WebP/AVIF siblings stayed on local disk while `get_driver_for_location()` resolved their URLs to the CDN, so every derived image 404'd. The admin path was healthy the whole time, which is why this went unnoticed — a UI-only journey cannot catch it.
- **Action**: pick a public image still on local that has WebP siblings:
  `mysql_query "SELECT media_id, file_path FROM wp_mvs_media_index WHERE privacy='public' AND file_url LIKE '%/wp-content/uploads/%' LIMIT 1"`.
  Record every stored path: `wp eval '$r=\WPMediaVerse\Core\Plugin::container()->get("media_repository"); print_r($r->get_stored_file_paths(<ID>));'`
  Then run `wp mvs migrate-storage --from=local --to=<driver> --media-id=<ID> --keep-source`.
- **Expect**: EVERY path returned above resolves on the cloud host with HTTP 200 — not just the original. A `.webp` or `-WxH.jpg` returning 404/403 is this bug. Then load `$SITE_URL/media/<slug>/` and the Explore grid: no broken images, and `img.naturalWidth > 0` on the migrated item in both places (the single view reads `original_webp`, the grid reads `thumb_<size>_webp` — check BOTH, they fail independently).
- **Repair path for a site already broken this way**: `wp mvs cloud-thumbs-backfill --media-id=<ID>` must heal it even when all three `thumb_*` metas already point at the cloud — that skip branch still has to sweep `original_webp` / `original_avif`.
- **On fail**: `includes/CLI/Commands.php::migrate_storage` (must delegate to `CloudOps::migrate_one`, never re-implement the transfer), `Commands::sweep_stored_variants`, `Services/CloudOps::migrate_one`, `Repository/MediaRepository::get_stored_file_paths`.

### 6. Responsive check — mobile 390px (REQUIRED)
- **Action**: `playwright_resize 390 844`; reload Settings, Storage; screenshot the Storage Management overview, the R2/DO credential cards, and the migrate action row.
- **Expect**: `scrollWidth - innerWidth <= 1`; the overview list reflows (no clipped columns); credential fields full-width and labelled; Migrate all / Move next / Delete buttons reachable and >= 40px; the sidebar settings nav usable.
- **On fail**: `wpmediaverse-pro/assets/css/admin.css` storage-mgmt `@media (max-width:640px)` block.

### 7. Translation-readiness
- **Action**: grep `wpmediaverse-pro/templates/admin/storage-management.php` and `CloudOpsManager.php` for visible strings.
- **Expect**: all labels, the guard notice, button text, and JS i18n strings are wrapped with domain `wpmediaverse-pro` (JS via `wp_localize_script` `mvsProStorage.i18n`).
- **On fail**: the template/manager emitting the literal.

## Pass criteria

ALL of the following hold:
1. Storage Overview counts/sizes are accurate and the active service is marked.
2. Existing media renders under BOTH a cloud-active and local-active driver with no broken images.
3. Every non-public media's `file_url` is local (private never on cloud).
4. The switch-service guard shows the remaining count and Migrate all completes, rewriting URLs.
4b. `wp mvs migrate-storage` lands EVERY stored path on the destination (original + thumbnails + WebP/AVIF siblings), and both the single-media view and the grid render the migrated item with no broken images.
5. No horizontal scroll at 390x844 on the Storage screen; all controls reachable and >= 40px.
6. All Storage Management strings are translation-ready (`wpmediaverse-pro`).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Broken media after switching driver | resolver recomputes from active driver | `includes/Services/SignedUrlService.php` |
| Private media has a cloud `file_url` | upload/migration routed private to cloud | `includes/Services/StorageService.php`, `includes/Services/UploadService.php` |
| Overview counts wrong | host-matching count bug | `includes/Services/CloudOps.php::counts_by_service` |
| Migrate all hangs / loops | chunk loop or stall guard | `assets/js/admin-storage-mgmt.js`, `CloudOpsManager::ajax_migrate_chunk` |
| Storage table overflows at 390px | missing mobile breakpoint | `wpmediaverse-pro/assets/css/admin.css` |
