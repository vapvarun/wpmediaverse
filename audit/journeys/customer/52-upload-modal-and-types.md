---
journey: upload-modal-and-types
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [MV-UPL-001, MV-UPL-002, MV-UPL-003, MV-UPL-004, upload-modal, upload-photo, upload-gallery, upload-video-audio]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A JPEG fixture (see customer/01-media-upload-public.md), 2-3 more images for a gallery, an MP4 and an MPEG/OGG audio fixture"
estimated_runtime_minutes: 8
---

# The FAB opens the upload modal, and photo/gallery/video/audio uploads each land correctly

## Setup

- Member A: subscriber with default `mvs_upload_roles` (`?autologin=<memberA>`).
- `mvs_allowed_file_types` at its default (images, video, audio all allowed).

## Steps

### 1. FAB is absent for a logged-out visitor, present for an uploader (MV-UPL-001)
- **Action**: `playwright_navigate $SITE_URL/explore-media/` logged out; inspect for `.mvs-fab`.
- **Expect**: NO floating action button in the DOM at all (not rendered-then-blocked).
- **Action**: log in as Member A; reload.
- **Expect**: `.mvs-fab` present; click it.
- **Expect**: the upload modal opens with Photo/Gallery/Video/Audio options and a visible dropzone + click-to-browse.
- **Action**: press Esc with a file already selected but not yet submitted.
- **Expect**: modal closes; no upload request was sent (the in-progress selection is discarded).

### 2. Upload a photo with title/description/tags (MV-UPL-002)
- **Action**: choose Photo; select the JPEG fixture (dropzone or browse); confirm a thumbnail preview appears immediately, before any network request completes; expand "Add details"; fill Title, Description, and Tags (comma-separated); click a "Popular tags" pill if one is shown; submit.
- **Expect**: `POST /wp-json/mvs/v1/media` returns 201; a loading/progress state is visible while in flight; on success the modal closes with a success toast and the item appears in Explore/dashboard with the entered title, description, and tags — including the popular-tag pill's tag, appended without duplicating a tag already typed.
- **Action**: on a site with zero existing tags, reopen the modal.
- **Expect**: the "Popular tags" pill row is entirely absent (not an empty row).

### 3. Upload a gallery of multiple images (MV-UPL-003)
- **Action**: choose Gallery; select 3 image files; before submitting, remove one via its per-file remove control; submit the remaining 2.
- **Expect**: a thumbnail preview per selected file appears immediately; after removal only 2 previews remain; submitting creates 2 separate media items (verify via `mysql_query "SELECT COUNT(*) FROM wp_mvs_media_index WHERE post_author=<memberA id> ORDER BY media_id DESC LIMIT 2"`) with ONE combined success confirmation, not two separate toasts.
- **Action**: select a gallery mix of one image + one video.
- **Expect**: client-side rejection naming how many files were skipped ("N file(s) skipped — upload one media type at a time."), before any request is sent.

### 4. Upload video and audio (MV-UPL-004)
- **Action**: choose Video; select the MP4 fixture; submit.
- **Expect**: 201; the resulting item has a poster (embedded cover frame or client-supplied frame) — verify `thumb_large` resolves to a real, non-blank image, not a black/blank tile.
- **Action**: choose Audio; select the MPEG/OGG fixture; submit.
- **Expect**: 201; item renders with embedded/decorative artwork, not a broken image icon.
- **Action**: attempt to select a file type NOT on `mvs_allowed_file_types` for the chosen mode (e.g. disable video in Settings, then try a video upload).
- **Expect**: client-side rejection ("N file(s) not allowed for {mode} upload.") BEFORE any upload request is attempted (check the network log shows no `POST` for the rejected file).

## Pass criteria

ALL of the following hold:
1. `.mvs-fab` is absent for a logged-out visitor and for a role without `upload_mvs_media`; present and functional for an uploader. Esc discards an in-progress selection without submitting.
2. A photo upload with title/description/tags/popular-tag-pill lands correctly; the popular-tags row is absent with zero site-wide tags.
3. A gallery upload previews per-file, supports per-file removal, rejects a mixed-type selection client-side with a count-naming toast, and shows one combined success confirmation.
4. Video/audio uploads succeed with a non-blank poster/artwork; a disallowed type for the chosen mode is rejected client-side before any request.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| FAB visible to a logged-out visitor | capability check on FAB render missing | `templates/partials/shared-ui-frame.php` |
| Popular-tags pill row shown with zero site tags | missing empty-check before rendering the row | `src/blocks/shared-ui/view.js` (upload modal) |
| Gallery shows one toast per file | success handling not batched | `src/blocks/shared-ui/view.js` |
| Video shows a blank/black poster | `PosterService` fallback-poster path not wired | `includes/Services/PosterService.php` |
| Disallowed type reaches the server anyway | client-side `mvs_allowed_file_types` check bypassed | `src/blocks/shared-ui/view.js` (upload validation) |
