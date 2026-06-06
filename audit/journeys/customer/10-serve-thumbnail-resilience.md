---
journey: serve-thumbnail-resilience
plugin: wpmediaverse
priority: critical
roles: [anonymous, subscriber]
covers: [signed-urls, serve-route, thumbnails, page-cache-compat, friendsofheraclitus-support]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "One LOCAL-stored public image media and one members image media with thumb_medium_path meta"
estimated_runtime_minutes: 4
---

# Thumbnails survive missing variants and page-cached (stale) signed URLs

**Why this journey exists**: Customer site friendsofheraclitus.org (WordPress.com Atomic, 2026-06-06) had every thumbnail 403 while originals served fine. Two independent defects shared the symptom: (1) `serve_thumbnail()` hard-403'd ("Access denied.") when the variant file was missing on disk even though the original existed, and (2) the 60-second signed-URL TTL was shorter than the host's full-page cache lifetime (Batcache max-age=300), so cached HTML served already-expired signatures to every anonymous visitor. Fixed in 1.6.1: missing image variants fall back to the original file, and an expired-but-correctly-signed URL still serves PUBLIC media (the privacy gate, not the clock, protects public files; expiry still hard-bounds non-public bearer URLs). Escape hatch: `add_filter( 'mvs_serve_expired_public_urls', '__return_false' )`.

## Steps

### 1. Baseline: thumbnail serves
- **Action**: mint a signed medium-thumb URL for the local public image (`wp eval` → `signed_urls->generate_thumbnail( $id, 0, 'medium' )`); curl it.
- **Expect**: HTTP 200, image content-type, byte size = the variant's size.

### 2. Missing variant falls back to original (the core regression check)
- **Action**: `mv` the variant file (`uploads/wpmediaverse/<thumb_medium_path>`) aside; curl the same URL; restore the file.
- **Expect**: HTTP 200 with the ORIGINAL file's byte size — never 403 "Access denied." A 403 here means the realpath fallback regressed.

### 3. Expired signature still serves PUBLIC media
- **Action**: via reflection on `SignedUrlService::sign()`, mint a URL with `mvs_exp = time() - 600` for the public media; curl.
- **Expect**: HTTP 200. A 403 means page-cached sites break again after cache age > TTL.

### 4. Expired signature still BLOCKS non-public media
- **Action**: same expired mint for the members media with `mvs_uid=0`; curl logged-out.
- **Expect**: HTTP 403 "Invalid or expired signed URL." — the bearer-token window must hold.

### 5. Tampered signature always blocks
- **Action**: replace `mvs_sig` with 64 zeros on the public-media URL; curl.
- **Expect**: HTTP 403 — expiry tolerance must never bypass HMAC verification.

### 6. Stale foreign-host thumb URL falls back to the original (migration case)
- **Action**: on a fixture media, clear `thumb_*_path` metas and set `thumb_medium` to `https://oldsite.wpcomstaging.com/...` (keep `file_path` intact); curl a fresh signed medium URL; restore metas after.
- **Expect**: HTTP 200 with the original file's bytes — the foreign URL is never served and never 403s the card. With `file_path` ALSO empty, expect 404 "Thumbnail not found." (never a traversal 403: `get_filesystem_path()` resolving to the wpmediaverse directory itself must not reach the containment check — both 404 paths require `is_file`).
