# Storage Driver Verification Runbook (reusable model)

How to verify an S3-compatible storage driver (S3, BunnyCDN, Cloudflare R2,
DigitalOcean Spaces, …) end-to-end before shipping. This is the model used for
the R2 rollout (2026-05-20); reuse it for every new storage backend across the
portfolio.

Three layers — pass all three:

## 1. Driver unit/integration tests (construction — no live account)
`tests/unit/StorageDriverTest.php` (golden-master): assert each driver builds the
right **host**, **request URI** (virtual-hosted vs path-style), **public URL**,
and **signing region** for its provider. These run in CI without credentials.
Add a block per new provider.

## 2. Live driver round-trip (needs real credentials)
With creds set (prefer `wp-config.php` constants for secrets), run via WP-CLI
`eval-file` against the real endpoint. Cover every path:
- `is_configured()` true; and false when a required field is blank (negative).
- `store()` small file — **signed PUT** (the SigV4 gate).
- `store()` a file **> 50 MB** — the streamed UNSIGNED-PAYLOAD cURL path.
- key with **spaces / nested dirs / unicode** — per-segment URI encoding.
- `exists()` true after put; `exists()` false for a random key.
- `delete()` then `exists()` false.
- `url()` shape (host + path-style/virtual-hosted as expected).
- Restore options + delete every test object after. No secrets persisted.

## 3. Admin + live display (the real user flow)
- **Test Connection button** in Settings → Storage returns the success message
  (exercises the AJAX handler + round-trip + UI).
- **Display**: enable the provider's public domain (R2 needs `r2.dev` or a custom
  domain — the bare API endpoint is private), set `*_cdn_domain` + enable
  `mvs_cloud_direct_public_urls`, upload a real **image, video, audio** through
  the plugin, and verify each renders in the frontend FROM the provider:
  - image `naturalWidth > 0` (rendered),
  - video `readyState >= 1` + poster loads,
  - audio `readyState >= 1`,
  - each asset HEAD = 200 with the correct content-type from the public domain.

## Gotchas this caught (provider-specific)
- **R2 buckets are private by default** — display needs a public domain; the bare
  `*.r2.cloudflarestorage.com` endpoint is not publicly readable.
- **`download()` is an unsigned public GET** (inherited from the S3 driver) — it
  fails on private buckets. Migration *pulling from* a private cloud needs a
  signed GET (follow-up). Upload/exists/delete are signed and work.
- **Switching the active driver does not move existing media.** Existing media
  404s under the new service until migrated (`wp mvs migrate-storage --from=<old>
  --to=<new>` re-uploads). URLs are computed from the *enabled* service. See the
  admin-control spec in `plan/2026-05-20-storage-service-management.md`.
- **`-dev`/pre-release version suffixes** sort below the release in
  `version_compare` — strip them before comparing (lockstep guard).

## Cleanup checklist (always)
Delete every test object from the bucket, delete test media rows, revert all
`mvs_*` options to pre-test state, disable any public domain you enabled, remove
temp files, and rotate any credentials shared outside a secret store.
