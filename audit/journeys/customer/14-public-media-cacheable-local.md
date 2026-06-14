---
journey: public-media-cacheable-local
plugin: wpmediaverse
priority: high
roles: [anonymous]
covers: [signed-url-stability, serve-cache-headers, local-driver]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Storage driver = local (this journey does NOT apply on a cloud driver, where public media serves direct from the CDN and bypasses /serve)"
  - "At least one PUBLIC image and one PRIVATE image exist"
estimated_runtime_minutes: 4
---

# Public media on the local driver is cacheable; private stays no-store

**Why this journey exists**: On the local driver, ALL media — including public — went through the signed `/serve` proxy with `nocache_headers()` and a per-render expiry, so every public image was uncacheable and paid a full WP bootstrap on every request (~2.5s/image on shared hosts). 1.7.0 gives PUBLIC media a render-stable signed URL (`resolve_expiry`, behind `mvs_stable_public_urls`) and `Cache-Control: public, max-age=…` (`mvs_public_media_max_age`, default 1 week), while private/restricted media keeps the no-store bearer behaviour. (Basecamp card: "Public media is uncacheable on the local storage driver".)

> Run this only with `local` storage active. On a cloud driver, public media returns a direct CDN URL and never reaches `/serve`, so steps 2-4 don't apply.

## Steps

### 1. Confirm local driver
- **Action**: verify the active storage driver is local (no cloud provider configured).
- **Expect**: public image URLs point at `/wp-json/mvs/v1/serve?...`.

### 2. Public signed URL is stable across renders
- **Action**:
  ```bash
  wp eval '$s=\WPMediaVerse\Core\Plugin::container()->get("signed_urls"); $a=$s->generate($PUBLIC_ID,0); $b=$s->generate($PUBLIC_ID,0); echo ($a===$b?"STABLE":"UNSTABLE")." ".$a;'
  ```
- **Expect**: `STABLE` — identical URL across calls (was a unique `mvs_exp`/`mvs_sig` per render).

### 3. Public /serve response is cacheable
- **Action**: `curl -sI "$PUBLIC_SERVE_URL"`
- **Expect**: `Cache-Control: public, max-age=604800` (no `no-store`); a far-future `Expires`.

### 4. Private /serve response stays no-store
- **Action**: `curl -sI "$PRIVATE_SERVE_URL"` (as the owner / signed viewer)
- **Expect**: `Cache-Control: no-store, no-cache` (private bearer-token semantics unchanged).

### 5. Opt-out filter
- **Action**: `add_filter('mvs_stable_public_urls','__return_false')` (mu-plugin), re-check step 2.
- **Expect**: public URL reverts to a rolling `now+ttl` expiry — the escape hatch works.
