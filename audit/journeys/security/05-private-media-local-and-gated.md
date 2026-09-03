---
journey: private-media-local-and-gated
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [private-stays-local, privacy-gate, signed-url, no-public-cloud-leak, raw-storage-path-gated, privacy-change-revokes]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Two subscriber users: journey_owner and journey_other"
  - "A cloud driver is active (to prove private uploads still stay local)"
estimated_runtime_minutes: 7
---

# A private upload stays on local disk and is invisible to everyone but its owner

**Why this journey exists**: A site owner's hard expectation is that private media never leaks. In 1.4.0 that has two parts: private media is never uploaded to a public cloud bucket (it stays on local disk), and it is never served to another user or anonymous. Both must hold even while a cloud driver is the active storage.

## Setup

- Site: `$SITE_URL`
- Owner: `journey_owner` (autologin `?autologin=journey_owner`)
- Other: `journey_other`
- Active driver is a cloud driver (verify `mvs_storage_driver != local`).

## Steps

### 1. Upload a PRIVATE image as the owner
- **Action**: `curl -X POST -H 'X-WP-Nonce: $NONCE' -F 'file=@tests/fixtures/test-image-1.jpg' -F 'privacy=private' -F 'title=Secret' $SITE_URL/wp-json/mvs/v1/media` (as journey_owner). Capture `MEDIA_ID`.
- **Expect**: HTTP 201, `privacy: private`.

### 2. File is on LOCAL disk, not cloud
- **Action**: `mysql_query "SELECT file_url FROM wp_mvs_media_index WHERE media_id=$MEDIA_ID"`.
- **Expect**: `file_url` begins with the local uploads base URL (e.g. `$SITE_URL/wp-content/uploads/...`) even though a cloud driver is active. Thumbnails (`thumb_large` etc) are also local.
- **On fail**: `includes/Services/StorageService.php::get_driver_for_privacy`, `UploadService::handle` (driver chosen before privacy), `UploadService::generate_thumbnails` (cloud gate not privacy-aware).

### 3. Owner can view it
- **Action**: as journey_owner, open the single-media URL / my-media.
- **Expect**: image renders (`naturalWidth > 0`), served via signed `/serve` (not a public cloud URL).

### 4. Another logged-in user CANNOT view it
- **Action**: autologin as `journey_other`; request the media's signed-url endpoint and the single-media page.
- **Expect**: HTTP 403/empty; the image does not appear in journey_other's Explore or the owner's profile tab.
- **On fail**: `includes/Services/PrivacyService.php::can_view`, `SignedUrlController::get_signed_url_permissions_check`.

### 5. Anonymous CANNOT view it
- **Action**: logged-out, hit the `/serve` URL and the explore page.
- **Expect**: 403; absent from the anonymous Explore grid.

### 5b. Anonymous CANNOT fetch the RAW STORED PATH (REQUIRED)
- **Action**: take the exact `file_url` captured in **step 2** — the raw
  `/wp-content/uploads/wpmediaverse/...` path, not the `/serve` URL — and fetch it
  with **no cookies at all**: `curl -s -o /dev/null -w '%{http_code}' "$FILE_URL"`.
  Do the same for one generated variant (`-300x200`, `.webp`) in the same directory.
- **Expect**: **not 200.** 403 or 404. A 200 with the image bytes means the storage
  directory is world-readable and every non-public item on the site is downloadable
  by anyone holding the address.
- **On fail**: the deny files are being trusted instead of the server. Check
  `Services\HealthCheckService::probe_public_access()` — it should already be
  reporting this as **critical** in Site Health with an nginx rule to paste. If the
  probe says "good" while this step fails, the probe itself is broken (see the note
  below about the canary filename).

  > **Why this step exists (2026-09-02).** This journey is `priority: critical` and
  > claims `privacy-gate`, yet it shipped for months while private media was fully
  > downloadable on every nginx host. Step 2 *captures* `file_url` and asserts it is
  > local — and then nothing ever fetches it. Step 5 only ever tested `/serve`. The
  > protection is an `.htaccess` `Deny from all`, which Apache reads and **nginx
  > ignores entirely**, so the API said 403 while the file said 200. A journey that
  > reads the dangerous URL and never requests it is not covering the gate it names.

### 5c. Making an item private actually REVOKES it (REQUIRED)
- **Action**: upload a second image as **public**, capture its `file_url`, confirm
  anonymous can fetch it. Then `POST /wp-json/mvs/v1/media/{id}` with
  `{"privacy":"private"}` as the owner. Re-fetch **the same original URL**, still
  with no cookies.
- **Expect**: the REST item returns 403 **and** the previously-working raw URL stops
  returning 200. A published address that keeps serving after the owner made the item
  private means privacy is advisory: anyone who saw it while it was public — or has it
  in history, a cache or a CDN log — keeps it permanently.
- **On fail**: same as 5b. Revocation is only as real as the storage gate.

### 6. No public cloud URL is ever emitted
- **Action**: inspect every URL the owner's page emits for this media.
- **Expect**: no absolute cloud-host URL for a private item — only signed `/serve`. (`maybe_direct_cloud_*` returns '' for non-public.)
- **On fail**: `includes/Services/SignedUrlService.php::public_cloud_direct_allowed`.

## Pass criteria

ALL of the following hold:
1. Private upload returns 201 with `privacy=private`.
2. Its `file_url` + thumbnails are local even though a cloud driver is active.
3. The owner can view it via signed `/serve`.
4. A different logged-in user gets 403 and never sees it.
5. Anonymous gets 403 from `/serve` and never sees it in Explore.
6. **Anonymous fetching the RAW stored path from step 2 does not get 200** — neither the original nor a generated variant.
7. **Switching a public item to private stops its already-published raw URL from serving.**
8. No absolute public cloud URL is ever emitted for the private item.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Private `file_url` is a cloud host | privacy-aware driver routing broke | `includes/Services/StorageService.php`, `includes/Services/UploadService.php` |
| Other user can view | privacy gate | `includes/Services/PrivacyService.php` |
| Public cloud URL emitted for private | direct-cloud gate | `includes/Services/SignedUrlService.php::public_cloud_direct_allowed` |
| Owner sees broken image | signed serve of local file | `includes/Services/SignedUrlService.php::serve` |
| Raw uploads path returns 200 to anon | storage dir world-readable; deny files trusted instead of the server (nginx ignores `.htaccess`) | `includes/Services/HealthCheckService.php::probe_public_access`, `Core/Activator.php`, `Services/LocalDriver.php` |
| Probe says "good" while step 5b fails | canary is a dotfile, so nginx's `location ~ /\.` deny 404s it and the directory reads as protected | `HealthCheckService::CANARY` must not start with a dot |
