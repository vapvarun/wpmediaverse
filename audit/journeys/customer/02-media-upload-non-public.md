---
journey: media-upload-non-public
plugin: wpmediaverse
priority: critical
roles: [subscriber]
covers: [upload-flow, signed-urls, privacy-gate, variant-pipeline, basecamp-9925110293]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Test user `journey_subscriber` exists with subscriber role"
estimated_runtime_minutes: 5
---

# Subscriber uploads a non-public photo and sees its own thumbnail render

**Why this journey exists**: This is the regression test for Basecamp card 9925110293 ("Immediate broken thumbnails and images after uploading non-public media"). The bug had three sibling root causes living in five+ duplicated code paths, all collapsed in 1.5.0 into `VariantSpec` / `StorageRouter` / `MediaVariantWriter` / `MediaUrl`. This journey owns the assertion that the symptom never returns.

The bug was: an owner uploads a non-public image, then immediately sees a broken thumbnail in their own grid because (1) `SignedUrlService::serve()` was reading `get_current_user_id()` which returns 0 for `<img src>` fetches (no X-WP-Nonce header), and (2) `UploadService::generate_thumbnails()` wrote `thumb_<size>_path` meta with the wrong directory shape. The new pipeline routes every variant write through one writer; any new duplication is caught by this journey on the next run.

## Setup

Same fixture pattern as `01-media-upload-public.md`:

- **Subscriber user**: `wp user get journey_subscriber || wp user create journey_subscriber journey_subscriber@example.test --role=subscriber --user_pass=journey-pass`
- **Fixture image** at `tests/fixtures/test-image-1.jpg` (the bootstrap from journey 01 generates it idempotently)

## Steps

### 1. Auto-login as subscriber
- **Action**: `playwright_navigate $SITE_URL/?autologin=journey_subscriber`
- **Expect**: top-bar shows "Howdy, journey_subscriber".

### 2. Upload with `privacy=members`
- **Action**: `curl -X POST -H 'X-WP-Nonce: $NONCE' -b cookies.txt -F 'file=@tests/fixtures/test-image-1.jpg' -F 'privacy=members' -F 'title=Journey Non-Public 2' $SITE_URL/wp-json/mvs/v1/media`
- **Expect**: HTTP 201, JSON `{id: <int>, privacy: 'members'}`. Capture `MEDIA_ID`.

### 3. Verify variant meta wrote correctly (the core regression check)
- **Action**: `mysql_query "SELECT meta_key, meta_value FROM wp_mvs_media_meta WHERE media_id=$MEDIA_ID AND meta_key LIKE 'thumb_%' ORDER BY meta_key"`
- **Expect** ALL of:
  - `thumb_large`, `thumb_medium`, `thumb_thumb` populated with URLs under the site's `wp-content/uploads/wpmediaverse/` base (NOT a CDN URL — non-public media is local-only by policy).
  - `thumb_large_path`, `thumb_medium_path`, `thumb_thumb_path` populated with relative paths like `2026/05/<basename>-WxH.jpg`. NO leading `wpmediaverse/`. NO leading slash. NO absolute filesystem path.
  - If WebP is enabled (default on): `thumb_<size>_webp` + `thumb_<size>_webp_path` for each size that has a primary variant.

### 4. Verify variant files exist on disk
- **Action**: For each `thumb_<size>_path` value `$P`, run `ls -la wp-content/uploads/wpmediaverse/$P`.
- **Expect**: file exists, non-zero size.

### 5. The original bug — owner fetches their own thumbnail
- **Action**: `playwright_navigate $SITE_URL/explore-media/` (still logged in as the owner)
- **Action**: Wait for the grid. Read the network log filtered to `/wp-json/mvs/v1/serve?mvs_id=$MEDIA_ID`.
- **Expect**: HTTP **200** on `?mvs_id=$MEDIA_ID&...&mvs_size=large` (the owner can see their own non-public media). Pre-1.5.0 this was 403.

### 6. Privacy gate still works — anon user is denied
- **Action**: `curl -i $SITE_URL/wp-json/mvs/v1/serve?mvs_id=$MEDIA_ID&mvs_uid=0&mvs_exp=...&mvs_size=large&mvs_sig=...` (no cookies, no nonce). To form a valid signature for anon, you cannot — the signing requires server-side knowledge of the secret. Instead: fetch the explore page LOGGED OUT and confirm media `$MEDIA_ID` does not appear in the grid AT ALL (privacy gate on the LIST endpoint filters non-public from anon).
- **Expect**: `playwright_navigate $SITE_URL/explore-media/` (no cookies) → DOM does NOT contain "Journey Non-Public 2". The non-public item is correctly hidden from anonymous viewers.

### 7. Single-media page renders for owner
- **Action**: `playwright_navigate $SITE_URL/?p=$MEDIA_ID` (or the canonical media permalink).
- **Expect**: image renders, `naturalWidth > 0`. Image src can be either a `/serve?...` URL or a direct local upload URL — both are valid for non-public local media.

### 8. Mobile viewport check
- **Action**: `playwright_resize 390 844` then re-`playwright_navigate $SITE_URL/explore-media/`.
- **Expect**: no horizontal scroll, the new media tile renders, `naturalWidth > 0`. Tap target on the tile is >= 40px.

## Pass criteria

ALL of the following hold:
1. POST returns 201 with `privacy: members`.
2. DB row has 6 thumb_* meta keys per size (or 12 with WebP enabled). NO meta key has a value starting with `wpmediaverse/` or `/` (absolute path) — that would mean a regression in `MediaVariantWriter::record()` or `VariantSpec::compute_rel_path()`.
3. Every `thumb_<size>_path` value resolves to a real file on disk under `wp-content/uploads/wpmediaverse/`.
4. Logged-in owner sees their own thumbnail render: `/serve?mvs_id=$MEDIA_ID&mvs_size=large` returns 200, image `naturalWidth > 0`.
5. Logged-out viewer does NOT see the non-public item in the explore grid.
6. Single-media page renders the image for the owner.
7. Mobile (390x844): no horizontal scroll, tile renders.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `thumb_*_path` value contains `wpmediaverse/` prefix | `VariantSpec::compute_rel_path()` regressed; the `strip leading wpmediaverse/` rule was bypassed | `includes/Services/VariantSpec.php` |
| `thumb_*_path` values disagree between siblings of the same variant (e.g. `_webp_path` points at a different dir than the primary) | A new write path was added that doesn't route through `MediaVariantWriter::record()` | `includes/Services/MediaVariantWriter.php`, search for `set( $media_id, 'thumb_` outside MediaVariantWriter |
| Owner gets 403 on their own thumb (the original bug) | `SignedUrlService::serve()` line ~298 privacy gate broke; `get_current_user_id()` returned 0 with no fallback to `$params[PARAM_USER]` | `includes/Services/SignedUrlService.php` |
| File missing on disk but `thumb_*_path` meta written | Upload failed silently between variant generation and meta write | `includes/Services/UploadService.php::generate_thumbnails()` |
| Non-public item visible to anon on explore | Privacy gate flipped in the LIST endpoint | `includes/REST/Controller/MediaController.php::get_items()`, `includes/Services/PrivacyService.php` |
| `thumb_<size>_path` for a VIDEO points at `2026/05/<id>-WxH.jpg` instead of `posters/<id>-WxH.jpg` | The Migrator v15 healing regressed OR a new write path bypassed PosterService | `includes/Core/Migrator.php::migrate_to_15()`, `includes/Services/PosterService.php` |
