# Storage Driver Verification Runbook (reusable model)

How to verify an S3-compatible storage driver (S3, BunnyCDN, Cloudflare R2,
DigitalOcean Spaces, …) end-to-end before shipping. This is the model used for
the R2 rollout (2026-05-20); reuse it for every new storage backend across the
portfolio.

Three layers - pass all three:

## 1. Driver unit/integration tests (construction - no live account)
`wpmediaverse-pro/tests/unit/StorageDriverTest.php` (golden-master; the S3-family
drivers are Pro, so the test lives in Pro): assert each driver builds the
right **host**, **request URI** (virtual-hosted vs path-style), **public URL**,
and **signing region** for its provider. These run in CI without credentials.
Add a block per new provider.

## 2. Live driver round-trip (needs real credentials)
With creds set (prefer `wp-config.php` constants for secrets), run via WP-CLI
`eval-file` against the real endpoint. Cover every path:
- `is_configured()` true; and false when a required field is blank (negative).
- `store()` small file - **signed PUT** (the SigV4 gate).
- `store()` a file **> 50 MB** - the streamed UNSIGNED-PAYLOAD cURL path.
- key with **spaces / nested dirs / unicode** - per-segment URI encoding.
- `exists()` true after put; `exists()` false for a random key.
- `delete()` then `exists()` false.
- `url()` shape (host + path-style/virtual-hosted as expected).
- Restore options + delete every test object after. No secrets persisted.

## 3. Admin + live display (the real user flow)
- **Test Connection button** in Settings → Storage returns the success message
  (exercises the AJAX handler + round-trip + UI).
- **Display**: enable the provider's public domain (R2 needs `r2.dev` or a custom
  domain - the bare API endpoint is private) and set `*_cdn_domain`. Nothing else
  needs enabling: direct-from-CDN display for public cloud-hosted media is
  automatic (see "Display model" below). Upload a real **image, video, audio**
  through the plugin, and verify each renders in the frontend FROM the provider:
  - image `naturalWidth > 0` (rendered),
  - video `readyState >= 1` + poster loads,
  - audio `readyState >= 1`,
  - each asset HEAD = 200 with the correct content-type from the public domain.

## Display model (location-based, not driver/toggle-based)
Storage is either local or a single cloud at a time. A media's display URL is
derived from **where its file actually lives + its privacy**, never from the
active driver or a global setting:
- **Public + ungated**, file on cloud (stored `file_url`/`thumb_<size>` is an
  absolute non-local URL) → served **directly** from that CDN URL. The `/serve`
  proxy is local-only and 403s a cloud-hosted file, so a direct URL is the only
  thing that works - this is automatic, no opt-in required.
- **Public + ungated**, file local → signed `/serve` URL (proxy streams the
  local file; keeps AVIF/WebP negotiation + download logging).
- **Restricted / access-gated** → always signed `/serve` so the per-request
  privacy check fires.

Implemented in `SignedUrlService::public_cloud_direct_allowed()` +
`is_cloud_hosted_url()`, consumed by `maybe_direct_cloud_thumbnail_url()` and
`maybe_direct_cloud_url()`. Escape hatch: the `mvs_serve_public_cloud_direct`
filter forces public media back through `/serve`; `mvs_public_cloud_thumbnail_url`
/ `mvs_public_cloud_file_url` rewrite the emitted URL. The legacy
`mvs_cloud_direct_public_urls` option no longer gates display (kept for
back-compat only; its checkbox was removed).

## Gotchas this caught (provider-specific)
- **R2 buckets are private by default** - display needs a public domain; the bare
  `*.r2.cloudflarestorage.com` endpoint is not publicly readable.
- **`download()` is an unsigned public GET** (inherited from the S3 driver) - it
  fails on private buckets. Migration *pulling from* a private cloud needs a
  signed GET (follow-up). Upload/exists/delete are signed and work.
- **Private media whose file is cloud-only cannot be served yet.** `/serve` is a
  local-file proxy; it has no cloud-fetch path, so a private/gated media whose
  bytes live only on cloud 403s. Keep private media local until a signed-GET
  cloud fetch lands in `/serve` (follow-up). Public media is unaffected - it
  serves directly from the CDN.
- **Switching the active driver does not move existing media.** Existing media
  keeps serving from its actual stored location (`mvs_media_index.file_url` /
  `thumb_<size>`), so enabling a service never breaks un-migrated media. Local
  files serve through `/serve`; cloud files serve directly. Migrate with
  `wp mvs migrate-storage --from=<old> --to=<new>` (or the Storage Management
  admin), which re-uploads and rewrites the stored URLs to the destination.
  `wp help mvs migrate-storage` documents the flags (`--dry-run`,
  `--keep-source`, `--media-id`, `--limit`).
- **`-dev`/pre-release version suffixes** sort below the release in
  `version_compare` - strip them before comparing (lockstep guard).

## Cleanup checklist (always)
Delete every test object from the bucket, delete test media rows, revert all
`mvs_*` options to pre-test state, disable any public domain you enabled, remove
temp files, and rotate any credentials shared outside a secret store.
