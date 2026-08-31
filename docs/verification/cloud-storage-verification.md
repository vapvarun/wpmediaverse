# Cloud storage - verification plan + known gaps

> Audience: QA engineers + Pro customers using S3 / BunnyCDN.
> Status: living document. Last updated 2026-05-07 alongside the
> `wp mvs migrate-storage` CLI in Free 1.2.2-dev.

## TL;DR - what works today, what doesn't

| Scenario | Status |
|---|---|
| Fresh upload via UploadService → S3 | ✅ lands on local disk first, then queues `mvs_cloud_sync_media` to copy to S3 (see "Upload model") |
| Fresh upload via UploadService → BunnyCDN | ✅ same deferred-sync path |
| Public file access via CDN URL | ✅ via `StorageDriver::url()` (CDN-domain or direct S3/Bunny) |
| Signed-URL access for private media | ✅ via `Services\SignedUrlService` (always uses our serve endpoint, then 302 to cloud URL) |
| Delete media → cloud cleanup | ✅ `delete_cascade()` calls `$driver->delete()` |
| **Multi-size thumbnail generation in cloud mode** | ✅ Fixed in 1.2.2. Thumbs are generated from the local original and pushed per size; pre-1.2.2 rows are repaired with `wp mvs cloud-thumbs-backfill`. See "Known gap 1 (resolved)". |
| **Switching driver mid-life (local → s3, etc.)** | ⚠️ **No automatic migration before 1.2.2.** Use `wp mvs migrate-storage` (added 1.2.2). |
| **Cloud upload retry / resume on partial network failure** | ⚠️ S3 driver has 3-retry exponential backoff; BunnyCDN has single-attempt. |
| **Cloud download for migration / re-thumbnailing** | ✅ `StorageDriver::download()` added in 1.2.2 (Free interface + Pro implementations) |
| **No-fallback risk when cloud auth dies** | ⚠️ Upload returns false → media row never inserted → user sees "Failed to store the uploaded file." Not silent, but not graceful. |

## Storage model - single-driver, no replication

Active driver is selected via the `mvs_storage_driver` option:
- `local` - stores under `wp-content/uploads/wpmediaverse/{Y/m}/`
- `s3` (Pro) - uploads to `s3://{bucket}/wpmediaverse/{Y/m}/`
- `bunnycdn` (Pro) - uploads to BunnyCDN storage zone

**One driver active at a time.** No "store on both" mode, no automatic
local fallback if cloud fails. This is by design - multi-driver
replication doubles storage cost + duplicates on every upload.

## Upload model - local first, cloud deferred

Even with a cloud driver active, an upload **always writes to local disk
first** and then queues `mvs_cloud_sync_media` (Action Scheduler) to copy
the original and its variants up. The member never waits on a CDN round
trip to see their own photo. `UploadService::queue_cloud_sync()` schedules
it; `UploadService::run_cloud_sync()` is the worker, wired in
`Core\Plugin`. Expect a local copy to exist immediately after upload and
to disappear only once the sync has run (or after `wp mvs cleanup-local`).

## Pre-flight before testing on real cloud credentials

1. Active driver matches the credentials configured. Verify via:
   ```bash
   wp option get mvs_storage_driver
   ```
2. Pro plugin active - `s3` / `bunnycdn` are Pro drivers. The EDD licence is **not** a gate here: it is updates-and-support only, and the single feature it gates anywhere is Documents *writes* (`DocumentLicense::can_write()`). An unlicensed Pro install still gets the cloud drivers.
3. S3 bucket policy allows the configured access key to PUT, GET, DELETE.
4. BunnyCDN storage zone API key is the **storage zone read+write password**, not the account dashboard password.
5. Pro Settings → Cloud Storage → "Test connection" returns ✅.
6. `wp_remote_get` from this WP install can reach the configured CDN
   domain (firewall / outbound HTTPS allowed).

## Verification matrix

### Phase A - fresh upload, single driver

For EACH of {`local`, `s3`, `bunnycdn`} as the active driver:

| Step | Expected |
|---|---|
| 1. Upload an image via REST `/mvs/v1/media` | 201 Created, response includes `file_url` pointing at the active driver's URL pattern |
| 2. `mvs_media_index.file_path` row | populated with relative path (e.g. `2026/05/abc.jpg`) |
| 3. Browser GET of the `file_url` | 200, returns the image bytes |
| 4. `wp-content/uploads/wpmediaverse/{file_path}` exists locally? | YES for every driver immediately after upload (local-first model). For `s3` / `bunnycdn` it is removed once `mvs_cloud_sync_media` has run - run the queue before asserting absence |
| 5. PHP $_FILES temp directory after upload | empty (PHP cleaned its own tmp file) |
| 6. Multi-size thumbnails (`thumb_thumb`, `thumb_medium`, `thumb_large`) in `mvs_media_meta` | 3 distinct URLs from `multi_resize` for every driver. For `s3` / `bunnycdn` they point at the cloud once the deferred sync has run |
| 7. Activity created for the upload (BP active) | `bp_activity` row with the new `_mvs_activity_privacy_level` meta (1.2.1+) |

### Phase B - delete cleanup

| Step | Expected |
|---|---|
| 1. DELETE `/mvs/v1/media/{id}` (admin token) | 200 OK |
| 2. `$driver->exists($file_path)` after delete | false (cloud or local) |
| 3. Re-fetch the public URL | 404 (no orphan) |

### Phase C - driver migration (the new 1.2.2 CLI)

For each migration direction below:

| Direction | CLI |
|---|---|
| local → s3 | `wp mvs migrate-storage --from=local --to=s3` |
| local → bunnycdn | `wp mvs migrate-storage --from=local --to=bunnycdn` |
| s3 → local | `wp mvs migrate-storage --from=s3 --to=local` |
| bunnycdn → local | `wp mvs migrate-storage --from=bunnycdn --to=local` |
| s3 → bunnycdn | `wp mvs migrate-storage --from=s3 --to=bunnycdn` |

For each:

| Step | Expected |
|---|---|
| 1. `--dry-run` | Reports N media files would migrate. No actual transfers. |
| 2. Real run (without `--keep-source`) | All N files appear on destination + are removed from source. |
| 3. Real run with `--keep-source` | Files appear on destination AND remain on source (safety copy). |
| 4. Re-run after a clean migration | `Skipped N (already on destination)` - idempotent. |
| 5. Browser GET the file URL after migration but BEFORE flipping `mvs_storage_driver` | 404 (because the active driver is still the source, but file is on destination only). This proves the operator MUST flip the driver as the next step. |
| 6. `wp option update mvs_storage_driver {to}` then re-fetch | 200 from destination URL pattern. |
| 7. Migration mid-flight network failure | The specific row is `Failed`. Re-running picks up where it left off (idempotent). |

### Phase D - failure modes

| Scenario | Expected |
|---|---|
| S3 access key revoked → user uploads | Upload returns false; user sees "Failed to store the uploaded file." Logs show 3 retries with exponential backoff at 0s, 1s, 2s |
| BunnyCDN API key revoked → user uploads | Upload returns false; same user-facing error. Single attempt (no retry). |
| Local disk full → user uploads | Standard PHP filesystem error |
| Network timeout mid-download (migrate-storage) | The specific row marked Failed; partial local temp cleaned via `wp_delete_file` |
| Cloud bucket policy changes from public-read to private | `download()` in 1.2.2 still uses public URL - would 403 on private-only buckets. Migration would Fail those rows. **Workaround for now: temporarily make bucket public-read for the migration window. Signed-GET fallback is on the 1.3.0 list.** |

## Known gap 1 - multi-size thumbnails on cloud (RESOLVED in 1.2.2)

Originally, `generate_thumbnails()` called `wp_get_image_editor( $file_path )`
on a path from `$driver->get_full_path()`. For the S3 driver that is
`s3://bucket/wpmediaverse/path` - not a real filesystem path - so the
editor failed and the function fell through to `ensure_fallback_thumbs()`,
which pointed all three thumb meta keys at the original full-size
`file_url`. Cloud-mode sites served a full-size original as every
"thumbnail."

**Now:** uploads land on local disk first (see "Upload model"), so
`multi_resize` always runs against a real local file, and the deferred
cloud sync pushes each variant up at a predictable path. The size-aware
read path `SignedUrlService::maybe_direct_cloud_thumbnail_url()` then
returns a per-size cloud URL.

**Media uploaded before 1.2.2** still carries local `thumb_<size>` URLs.
Repair it with:

```bash
wp mvs cloud-thumbs-backfill --dry-run
wp mvs cloud-thumbs-backfill --limit=100
```

Pre-condition: the originals must already be on cloud - run
`wp mvs migrate-storage` first if not.

## Known gap 2 - no signed-GET path for private S3 buckets

`StorageDriver::download()` (added 1.2.2) uses
`wp_safe_remote_get($source_url)` where `$source_url` is the public
CDN/bucket URL. Buckets configured for private-only access return 403.

**Workaround (operator-managed):** make the bucket public-read for
the migration window, then revert.

**Fix path (1.3.0):** Add a `download_signed()` variant that signs
the GET request with the same SigV4 helper used by `put_object`.

## Operator runbook - switching local → s3

1. **Pre-deploy:** verify Pro is active, S3 credentials are saved in
   Pro Settings → Cloud Storage, "Test connection" returns ✅.
2. **Set retention policy on the bucket** so accidental deletes are
   recoverable for at least 30 days.
3. **Dry-run the migration:**
   ```bash
   wp mvs migrate-storage --from=local --to=s3 --dry-run
   ```
   Confirm the count matches `wp mvs stats` "Published Media."
4. **Real migration with safety copy:**
   ```bash
   wp mvs migrate-storage --from=local --to=s3 --keep-source
   ```
5. **Smoke-test a few media URLs.** Browser to `/media/{slug}/`, verify
   the image renders. Confirm via DevTools Network tab that the URL
   resolves to the S3/CDN domain.
6. **Flip the active driver:**
   ```bash
   wp option update mvs_storage_driver s3
   ```
7. **Smoke-test again.** Same URLs should still work - they now resolve
   via the s3 driver's `url()` builder.
8. **After 7 days of clean operation, prune the local copies:**
   ```bash
   # CAUTION: only after verifying every public URL still serves correctly.
   rm -rf wp-content/uploads/wpmediaverse/2024
   rm -rf wp-content/uploads/wpmediaverse/2025
   # ...etc
   ```

## Where to file findings

If a row in any of the matrices fails, file a Basecamp card in the
WPMediaVerse Bugs column with:
- the matrix table + cell that failed (e.g. "Phase C step 6, s3→local")
- driver versions: Free + Pro
- bucket / zone region (S3 us-east-1, etc.)
- bucket policy snippet (redact secrets)
- network reachability test output (`curl -v <bucket-url>`)

## Out of scope for this verification

- Performance / throughput at scale (Phase 5 of 1.2.1 - 100k seed)
- WAF / firewall integration (customer-environment specific)
- IPv6 reachability of S3 + BunnyCDN
- Cross-region S3 replication (operator's S3 config, not the plugin's)
