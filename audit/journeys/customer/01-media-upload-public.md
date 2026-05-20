---
journey: media-upload-public
plugin: wpmediaverse
priority: critical
roles: [subscriber]
covers: [upload-flow, signed-urls, privacy-default]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Test user `journey_subscriber` exists with subscriber role"
estimated_runtime_minutes: 5
---

# Subscriber uploads a public photo and sees it in the explore feed

**Why this journey exists**: The end-to-end upload-to-display path crosses 6 layers (REST → UploadService → MediaRepository → privacy gate → signed-URL serve → grid render). Any one of them can break silently — most often a signed-URL TTL change or privacy default flip. This journey is the smoke for the entire core flow.

## Setup

- Site: `$SITE_URL`
- User: `journey_subscriber` (autologin via `?autologin=journey_subscriber`)
- Fixture file: `tests/fixtures/test-image-1.jpg` (≤2MB, real JPEG)

## Steps

### 1. Auto-login as subscriber
- **Action**: `playwright_navigate $SITE_URL/?autologin=journey_subscriber`
- **Expect**: top-bar shows "Howdy, journey_subscriber"; subscriber capability `read` present.

### 2. POST to /wp-json/mvs/v1/media
- **Action**: `curl -X POST -H 'X-WP-Nonce: $NONCE' -F 'file=@tests/fixtures/test-image-1.jpg' -F 'privacy=public' -F 'title=Journey Test 1' $SITE_URL/wp-json/mvs/v1/media`
- **Expect**: HTTP 201, JSON `{id: <int>, url: '<signed-url>', privacy: 'public', title: 'Journey Test 1'}`. Capture `MEDIA_ID`.

### 3. Verify DB row
- **Action**: `mysql_query "SELECT id, owner_id, privacy, status FROM wp_mvs_media_index WHERE id=$MEDIA_ID"`
- **Expect**: row exists, `privacy='public'`, `status='published'`, `owner_id=<journey_subscriber's ID>`.

### 4. Fetch signed URL via /wp-json/mvs/v1/media/$MEDIA_ID/signed-url
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID/signed-url`
- **Expect**: HTTP 200, JSON contains a `url` field. For PUBLIC media on a cloud driver this is the DIRECT CDN URL (no `?token=` / no `/serve` proxy) per the location-based display model; only restricted/local media returns a signed `/serve?...token=` URL.

### 5. Open explore page (logged-out)
- **Action**: `playwright_navigate $SITE_URL/explore-media/`
- **Expect**: DOM contains an `<img>` whose `alt`/title matches "Journey Test 1". Because the item is PUBLIC, when a cloud driver is active the `src` is the active CDN URL (e.g. host ends in `b-cdn.net`) and `naturalWidth > 0`; only when `driver=local` is the `src` a signed `/serve?...token=` URL.

### 6. Verify image actually streams
- **Action**: `curl -I $SITE_URL/$IMG_SRC`
- **Expect**: HTTP 200, `Content-Type: image/jpeg`.

## Pass criteria

ALL of the following hold:
1. Upload returns 201 with a media ID.
2. DB row exists with `privacy='public'`, `status='published'`.
3. Signed-URL endpoint returns a `url`: the direct CDN URL for public-on-cloud, or a signed `/serve?...token=` URL only for restricted/local media.
4. Explore page (`/explore-media/`) renders the new media; for public-on-cloud the `<img>` `src` is the CDN host with `naturalWidth > 0`.
5. The image streams `image/jpeg` with HTTP 200 (direct CDN for public-on-cloud; signed `/serve` for local/restricted).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 403 on POST | Subscriber missing upload cap | `includes/Capabilities/MediaCapabilities.php` |
| Upload succeeds, signed URL 401 | TTL too short or signing key rotated | `includes/Services/SignedUrlService.php` |
| Image src is plain `/wp-content/uploads/...` (not signed) | Signing-entry-point regression | `includes/Services/MediaUrl.php`, `includes/Core/TemplateHelpers.php` |
| Explore renders no items | Privacy gate flipped or query filter | `includes/Services/PrivacyService.php`, `includes/REST/Controller/MediaController.php` |
