---
journey: watermark-stamped-at-upload
plugin: wpmediaverse
priority: high
roles: [administrator, author, anonymous]
covers: [watermark-stamp-upload, watermark-admin-global]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "WPMediaVerse Pro active (Pro owns the Watermarker stamp)"
  - "PHP GD extension available"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 6
---

# Watermark is an admin-global setting, baked into the image at upload — everyone sees it

**2.0.0 redesign**: watermarking is no longer per-media and is NOT coupled to
access rules (the old access-rules UI + rules-gated watermark preview/serve path
was removed). It is a single admin setting (Settings -> Display -> Image
Watermarking): Enable + Apply-to (all uploads / selected uploader roles). The
mark is stamped into the image bytes at upload (`UploadService` ->
`mvs_watermark_stamp_file` -> Pro `Watermarker::stamp_file`), so every viewer —
regardless of role or login state — sees the watermark on the stored file and its
thumbnails. This journey replaces the retired `access-rules-and-watermark` journey.

## Setup

- Admin (`?autologin=1`).
- Enable + scope watermark to all uploads:
  ```sql
  UPDATE wp_options SET option_value='1'   WHERE option_name='mvs_watermark_enabled';
  UPDATE wp_options SET option_value='all' WHERE option_name='mvs_watermark_apply';
  UPDATE wp_options SET option_value='text' WHERE option_name='mvs_watermark_type';
  ```
- Fixture image at `tests/fixtures/test-image-1.jpg` (see journey 01 for the generator).

## Steps

### 1. Settings page exposes ONE watermark control (no access-rules coupling)
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=mvs-settings`; open the Display section.
- **Expect**: exactly one `[name="mvs_watermark_enabled"]` and one `[name="mvs_watermark_apply"]` (options: All uploads / Uploads from selected roles). No "access rule" wording anywhere on the page. Enable help text says the mark is baked in at upload and everyone sees it.

### 2. Upload an in-scope image (member) — file is stamped
- **Action**: as an in-scope uploader, `POST /wp-json/mvs/v1/media` with the fixture (see journey 01 step 2). Capture `$MEDIA_ID` + its stored file path.
- **Expect**: HTTP 201. The stored original file bytes differ from the source fixture (stamped); `md5(stored) != md5(fixture)`. (The Pro `Watermarker::stamp_file` renames the editor's extension-corrected output back onto the source path — the STORED file must be the stamped one.)

### 3. Every viewer sees the watermark (no role gate)
- **Action**: view `/media/{slug}/` as the owner, as another member, and logged-out.
- **Expect**: all three render the SAME stamped image (public media). There is no per-viewer/per-role watermark branch and no lock overlay.

### 4. Scope = selected roles excludes out-of-scope uploads
- **Action**: set `mvs_watermark_apply='roles'` + `mvs_watermark_roles` to a role the uploader does NOT have; upload again as that uploader.
- **Expect**: the new file is NOT stamped (`md5(stored) == md5(fixture)`), confirming `WatermarkService::applies_to_user()` gates by uploader role.

### 5. No dead watermark preview/serve surface remains
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID` (as owner).
- **Expect**: no `watermark_url` / `preview_url` (`mvs_size=watermark`) field; the removed access-rules-coupled preview path returns nothing.

## Pass criteria

ALL hold:
1. One watermark setting in admin (enable + apply scope), no access-rules language.
2. An in-scope upload is stamped into the stored file (bytes differ from source) and into thumbnails.
3. Every viewer (owner / member / anonymous) sees the same stamped public image.
4. Out-of-scope uploads (role scope) are NOT stamped.
5. No `watermark_url`/preview surface in the REST payload.

## Fail diagnostics

- Uploaded file NOT stamped → Pro `Watermarker::stamp_file` not hooked on `mvs_watermark_stamp_file`, or the save-path rename (editor writes `.jpg` next to the `.tmp`) regressed; confirm `UploadService` calls the filter before optimize/derivatives.
- Watermark shown to some roles but not others on the SAME public media → a per-viewer branch crept back in; stamping is at upload, display is role-agnostic.
- `watermark_url`/preview reappears → dead access-rules-coupled preview/serve code was re-added.
