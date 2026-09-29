---
journey: storage-drivers-cloud
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-STO-001, MV-STO-002, MV-STO-003, MV-STO-004, storage-drivers, storage-s3, storage-bunnycdn, storage-r2, storage-dospaces]
prerequisites:
  - "Both plugins active"
  - "Real or sandbox credentials for at least one of S3/BunnyCDN/R2/DigitalOcean Spaces, to exercise the success path; deliberately wrong credentials to exercise each driver's specific failure text"
estimated_runtime_minutes: 10
---

# Each of the four cloud drivers shows its own section, its own success text, and its own specific failure hint — never a shared generic message where one exists

**Why this journey exists**: `wpmediaverse/audit/pro/journeys/admin/03-pro-storage-driver-registers.md` only proves the `mvs_storage_driver` filter returns a driver object and the settings dropdown shows/hides sections. This journey goes past the filter into real credential-card behavior per driver — the exact success/failure strings, R2's public-domain warning, and the addressing-style differences that make each driver its own thing, not four skins on one class.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.

## Steps

### 1. Amazon S3 — configure and see only S3's section
- **Action**: Settings > Storage, set driver select to Amazon S3; fill bucket, region (default `us-east-1`), access key, secret key; Save.
- **Expect**: only the S3 section is visible (others hidden via `show_when`), live, without a page reload when the dropdown changes. `is_configured()` requires bucket+key+secret non-empty; blank fields make `store()` return false silently (the driver itself gives no message — only Test Connection surfaces one).
- **Also**: a `MVS_PRO_AWS_ACCESS_KEY`/`MVS_PRO_AWS_SECRET_KEY` constant in wp-config.php takes precedence over DB-stored values.

### 2. S3 Test Connection — success and bare failure text
- **Action**: Test Connection with valid credentials; then with a wrong secret key.
- **Expect**: success: `Connected to S3 bucket "{bucket}" in {region}.`; failure: the bare `Failed to upload test file to S3.` (S3 does not implement `get_last_error()`, unlike BunnyCDN — don't expect a detailed reason here).

### 3. BunnyCDN — configure and the region/password 401 disambiguation
- **Action**: select BunnyCDN; enter zone name, the FTP & API Access password (not the account login), and region; Save; Test Connection with a wrong password, then with the right password but wrong region.
- **Expect**: BOTH failure modes surface the SAME long, specific message via `describe_http_failure()`/`get_last_error()`: "BunnyCDN rejected the credentials (HTTP 401) at {endpoint}. Two things produce this: the Storage Zone password is wrong..., or the zone lives in a different region — BunnyCDN answers 401 rather than 404 when the region is wrong, so check the Region setting..." — this is the ONLY driver wired to give a specific reason instead of a generic upload-failed fallback.
- **Also**: a 404 names the zone; a 5xx says "usually temporary; the upload is retried automatically"; unknown codes get "BunnyCDN returned HTTP %d."; any of these appends "Response: {first 120 chars}" if present. `exists()` uses a `Range: bytes=0-0` GET (accepting 200 or 206) because BunnyCDN's storage API has no HEAD support.
- **On fail**: `describe_http_failure()` losing its per-code branching.

### 4. BunnyCDN large-file path
- **Action**: (if feasible) upload a file over 50MB while BunnyCDN is active.
- **Expect**: switches from `wp_remote_request` to a streamed cURL PUT (`STREAM_THRESHOLD` = 52428800 bytes) and still succeeds.

### 5. Cloudflare R2 — all-four-required and the no-CDN-domain admin notice
- **Action**: select R2; leave `mvs_pro_r2_cdn_domain` blank; fill account id, bucket, access key, secret key; Save.
- **Expect**: `is_configured()` requires ALL FOUR fields (stricter than S3, which doesn't require an account id). With the CDN domain blank, ProSettings shows an ADMIN NOTICE (not a driver error) explaining the raw `{account}.r2.cloudflarestorage.com` host isn't publicly readable without a custom domain or r2.dev subdomain.
- **Also**: a blank account_id alone must fail `is_configured()` even with bucket/keys filled.

### 6. R2 Test Connection
- **Action**: Test Connection with and without the CDN domain set.
- **Expect**: generic round-trip failure text on bad credentials: "Upload of the test file failed — check the credentials, bucket name, and region." (R2 has no bespoke per-field validation like S3/BunnyCDN.)

### 7. R2 path-style addressing with special characters
- **Action**: upload a file with a special character in its filename while R2 is active.
- **Expect**: not visibly broken — `rawurlencode` is applied to the path-style URI.

### 8. DigitalOcean Spaces — virtual-hosted addressing, no CDN-domain gate
- **Action**: select DigitalOcean Spaces; fill Space name, region (default `nyc3`), keys; leave CDN domain blank; Save; Test Connection.
- **Expect**: success message `Connected to DigitalOcean Space "{bucket}" in {region}.`; unlike R2, a blank CDN domain does NOT trigger a warning — files still load via the raw `{bucket}.{region}.digitaloceanspaces.com` URL. A wrong region/key both fall to the same generic round-trip failure text as R2 (no DO-specific hint).

### 9. Editing region after files exist breaks reachability
- **Action**: (structural note, verify by code/behaviour) change the region on an active driver with existing files.
- **Expect**: existing files' URLs point at the OLD region-based subdomain and become unreachable — this is expected given the addressing model, not itself a bug, but worth confirming it isn't silently "fixed" by a URL-rewrite that doesn't exist.

## Pass criteria

1. Each driver's section shows/hides live via the dropdown, with no page reload.
2. Each driver's success and failure text matches the documented strings exactly, including BunnyCDN's specific 401 disambiguation and R2's four-required-fields stricter gate.
3. R2 shows the no-CDN-domain admin notice when blank; DOSpaces does not.
4. BunnyCDN's large-file cURL path and R2's path-style URL-encoding both work without visible breakage.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| BunnyCDN 401 shows a generic message instead of the region/password hint | `get_last_error()` not wired into the AJAX handler response | BunnyCDN driver `describe_http_failure()` |
| R2 accepts a blank account_id | `is_configured()` not requiring all four fields | R2 driver `is_configured()` |
| R2 no-CDN-domain notice never appears | notice logic missing or gated on the wrong option | `ProSettings.php` (~line 741-748) |
| Driver section doesn't hide/show live on dropdown change | `show_when` JS binding broken | Storage tab settings JS |
