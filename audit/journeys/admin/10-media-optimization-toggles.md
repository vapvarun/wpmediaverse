---
journey: media-optimization-toggles
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [media-optimization-toggles, optimize-originals, generate-webp, generate-avif]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse 1.8.0+ active"
  - "GD or Imagick available for derivative generation"
estimated_runtime_minutes: 5
---

# Media optimization toggles persist and gate the upload pipeline

**Why this journey exists**: `mvs_optimize_originals`, `mvs_generate_webp`, and `mvs_generate_avif` are owner-controlled toggles that act during the upload pipeline, not on any GET route — so the cert contract (flip-and-dispatch) cannot prove them. This journey is their enforcement proof: it saves each toggle, reloads to confirm persistence, then uploads an image and asserts the derivative behaviour matches the toggle. A toggle that saves but does not change what the pipeline produces is a dead toggle.

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- Settings screen: `admin.php?page=mvs-settings#storage`
- Test asset: any JPEG/PNG > 1200px on the longest edge (so a resize is observable).

## Steps

### 1. Auto-login as admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: wp-admin dashboard, top-bar "Howdy, admin".

### 2. Enable all three optimization toggles
- **Action**: open `admin.php?page=mvs-settings#storage`; check `mvs_optimize_originals`, `mvs_generate_webp`, `mvs_generate_avif`; submit.
- **Expect**: HTTP 302 -> reload -> "Settings saved." notice.

### 3. Confirm persistence in DB
- **Action**: `mysql_query "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('mvs_optimize_originals','mvs_generate_webp','mvs_generate_avif')"`
- **Expect**: each row `option_value` == `1`.

### 4. Upload an image with toggles ON
- **Action**: `POST /wp-json/mvs/v1/media` (multipart) with the test asset.
- **Expect**: 201 Created; response `id` set. Derivatives exist: `mysql_query "SELECT meta_key FROM wp_mvs_media_meta WHERE media_id=<id> AND meta_key IN ('original_webp','original_avif')"` returns both rows (or the equivalent generated-file markers), and the stored original dimensions are within the configured max.

### 5. Disable all three toggles
- **Action**: uncheck the three toggles in the Storage tab; submit; reload.
- **Expect**: DB rows now `0`; reloaded checkboxes unchecked.

### 6. Upload a second image with toggles OFF
- **Action**: `POST /wp-json/mvs/v1/media` with the same asset.
- **Expect**: 201 Created; NO `original_webp` / `original_avif` derivative rows for the new id; the original is stored unmodified (no resize/re-encode).

## Pass criteria

ALL hold:
1. Each toggle persists to `wp_options` byte-for-byte after save + reload.
2. With toggles ON, an upload produces WebP and AVIF derivatives and (for optimize-originals) a resized/re-encoded original.
3. With toggles OFF, an upload produces NO derivatives and leaves the original untouched.
4. No 500 on either upload; the Settings-saved notice appears both times.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Toggle reverts after reload | Duplicate `register_setting()` overwrote the sanitizer | `includes/Admin/Settings/SettingsRegistrar.php` (search the option name) |
| Derivatives generated while toggle OFF | Pipeline reads the wrong option / ignores it | `includes/Services/ImageOptimizationService.php` (or the upload service) |
| No derivatives while toggle ON | GD/Imagick missing, or the format guard short-circuits | `includes/Services/ImageOptimizationService.php`; check `wp_image_editor_supports` |
| Upload 500 | Encoder threw on an unsupported source format | the optimization pipeline class |
