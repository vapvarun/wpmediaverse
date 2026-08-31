# Media delivery performance rework — plan (2026-06-14)

> **STATUS: PHASE A IMPLEMENTED. PHASES B AND C NOT BUILT.** Phase A is verified in
> code as of 2.4.0 - `MediaRepository::prefetch()` is called before the render loop
> in `templates/explore.php`, `album.php` and `collection.php`, and
> `AccessRulesService::prefetch_active_rules()` exists. Everything below the
> "Phase A results" table is a proposal: no split public/private storage dir, no
> `migrate-public-static` CLI command, no `srcset` re-introduction. Do not cite
> Phase B or Phase C behaviour as shipped.

Status: **Phase A SHIPPED (2026-06-14). Phase B scoped — "optimize /serve, keep one dir" (owner decision). Phase C deferred.**
Owner: Varun. Target: 1.7.0 (Free; Pro inherits). Driver focus: **local / shared hosting**.

## Decisions (2026-06-14)

- **Phase A — DONE.** Grid N+1 killed: ~170 queries/12-tile page → **6** (−96%), 14.2ms → 5.8ms, verified at the render-loop level + browser (0 broken images, 0 empty posters, access-control unchanged). See "Phase A results" below.
- **Phase B — owner chose "keep one dir, optimize /serve only"** (NOT the split-storage rework). No storage restructure / no file moves / no migration on the 50+ installs. Reduce per-image cost within the existing proxy + lean on Card #4 caching. See revised Phase B.
- **Phase C (srcset)** — deferred until/unless Phase B makes URLs cheap.

## Why this exists

The team has reported 3× that media pages load slowly and "PHP rendering eats 2-3s extra per image" on shared hosting. We kept fixing individual cards (thumbnail size, cache headers) without profiling the whole flow. This plan is the complete fix, replacing the band-aids.

## Measured baseline (this install — Local, fast SSD + opcache)

| Path | Cost here | Shared-host reality |
|---|---|---|
| `/serve` per image (PHP proxy) | TTFB ~40ms vs ~4ms static (10×) | Full WP bootstrap **per image** = the reported **2-3s/image** on InstaWP |
| Server-rendered grid | **~14 DB queries/tile → ~170 for 12 tiles** | Same query count; worse latency on slow DB |
| (reverted) srcset attempt | +3 queries/tile | Would have made the N+1 worse |

The "2-3s per image" in the cache card was the InstaWP shared host: **the cost is the WordPress bootstrap that runs on every `/serve` request.** A static file skips it entirely (~4ms, served by the webserver).

## Root cause: two independent PHP-cost layers

1. **Per-image bootstrap.** On the local driver, *every* image — public included — is served through `/wp-json/mvs/v1/serve`, a PHP route that boots all of WordPress to stream one file. The cloud driver already avoids this for public media (`public_cloud_direct_allowed()` → direct CDN URL); the **local driver does not mirror it**. The uploads `.htaccess` (`Deny from all`, written by `Activator` L160-164 + `LocalDriver::ensure_protection_files` L144-155) forces even public files through the proxy.
2. **Server-render N+1.** `TemplateHelpers::render_grid_item()` issues ~14 queries/tile. `MediaRepository::prefetch()` (L225-290) batch-loads index+meta in 2 queries but is **never called** before the render loops in `explore.php` / `album.php` / `collection.php`. Worse, `get_all()` (L754-778) bypasses the request cache entirely, so every tile re-queries.

Being "100% REST-ready" removes neither: a REST/app client still hits `/serve` per image (layer 1), and website visitors still pay the server render (layer 2).

## The plan — 3 phases, smallest-risk-first

### Phase A — Kill the grid N+1 (SHIPPED 2026-06-14)

Goal: ~170 queries/page → ~3-5. **Result: 6 queries/12-tile page.**

Implemented:
1. `MediaRepository::prefetch()` + `AccessRulesService::prefetch_active_rules()` called once before the render loop in `explore.php`, `album.php`, `collection.php`, and the `media-grid` / `explore-feed` block render.php.
2. `get_raw()` Tier 1b: honor `$meta_fully_loaded` so absent-key reads after prefetch return null with no query (e.g. `has_resolvable_thumbnail` probing ungenerated sizes).
3. `get_all()` now reads from / primes the request cache + marks meta fully loaded (was always re-querying).
4. `exists()` is cache-aware (a prefetched row → no query; `can_view()` called it once per tile).
5. `AccessRulesService::has_active_rules()` request-cached + bulk `prefetch_active_rules()` (one DISTINCT query/page); cache flushed on any rule write.
6. `filter_privacy_can_view()` reordered to check the (cached) `has_active_rules()` FIRST and return early for rule-less media — skipping the per-tile `get_post()` owner lookup. Safe because `PrivacyService::check_access()` already grants owner/admin before this filter runs (verified).

#### Phase A results (anonymous = worst case, 12 public tiles)
| | Queries | Time |
|---|---|---|
| Before | 170 (14.2 q/tile) | 14.2 ms |
| After | **6** | 5.8 ms |

Access-control re-verified after the reorder: public→anon ✓; private→anon ✗ / owner ✓ / admin ✓; public+access-rule→anon ✗. Browser: 0 broken images, 0 empty posters. CI (lint/WPCS/PHPStan/coding-rules/contract/UX) green.

Risk: low — pure read-path caching + a behavior-preserving reorder. Win on every grid on every driver.

### Phase B — CHOSEN: optimize `/serve`, keep one storage dir

Owner decision (2026-06-14): do **not** restructure storage. Keep the single `uploads/wpmediaverse/` dir + deny-all `.htaccess`; reduce the per-image cost within the existing proxy. Honest limit: **the first load of each public image still pays a WordPress bootstrap** — this direction cannot fully remove the 2-3s/image the way split-storage would. The levers:

1. **Card #4 caching (already shipped)** — public media gets a render-stable URL + `Cache-Control: public, max-age=1 week`, so browser/CDN serve repeat loads and scroll-back with **0 PHP**. A page/CDN cache (Batcache, Cloudflare, a host edge) in front of `/serve` then absorbs the first-load bootstrap too. **Recommend documenting "put a page/object cache in front" as the shared-host guidance.**
2. **Persistent object cache** — on shared hosts without Redis, the per-image bootstrap is dominated by autoloaded options + plugin init, not MediaVerse. Phase A already removed MediaVerse's own per-tile DB cost. Document the object-cache recommendation.
3. **Optional fast-path (future, evaluate):** a `SHORTINIT` mu-route for public `/serve` that validates the HMAC + streams the file without booting the full plugin stack. High complexity + security-sensitive (needs salts + privacy check) — only if profiling on a real shared host shows the bootstrap is the bottleneck *after* Phase A + caching.

Verification for this direction: on a real shared host, confirm repeat image loads are served from cache (304/from-disk, no PHP) and that Phase A cut the page-render queries. Re-measure TTFB after enabling a page cache.

---

### Phase B (ALTERNATIVE — NOT CHOSEN): static-serve public media on the local driver

Kept for the record. This is the only approach that fully removes the per-image bootstrap, but the owner declined the storage-restructure risk on 50+ installs.

Goal: public images load with **0 PHP / 0 bootstrap**, mirroring the cloud model. Private/restricted stay gated.

**The safe-to-serve-statically gate (copy `public_cloud_direct_allowed()` exactly):**
```
privacy == 'public'
  AND has_active_rules(media_id) == false      // a public item with access rules stays gated
  AND file_type != 'image/svg+xml'             // SVG keeps CSP-sandbox via /serve
  AND apply_filters('mvs_serve_public_cloud_direct', true, media_id) !== false
```

**Storage approach (recommended): split by privacy, mirror the cloud model.**
- Public media → a web-accessible dir, e.g. `uploads/wpmediaverse-public/{y}/{m}/` (NO deny rule).
- Private/restricted → `uploads/wpmediaverse/` (keep `Deny from all`, served via `/serve`).
- `StorageService::get_driver_for_privacy()` already routes by privacy at write time — extend so the *local* driver picks the public vs private base dir.
- On privacy flip: `relocalize_media_urls()` already rewrites meta; extend it to **move the file** between the two dirs (currently it only rewrites URLs). Add `mvs_media_privacy_changed` file-move.
- Default emission: wire the (already-added) `mvs_public_local_file_url` / `mvs_public_local_thumbnail_url` filters so that, for safe-to-static media, they return the **direct uploads URL** by default (no longer `''`). Private falls through to `/serve` unchanged.

**Migration (existing 50+ installs):** `Migrator` bump — move existing `privacy='public'` files from the protected dir to the public dir, rewrite their `file_path`/`thumb_*_path` bases, idempotent, with a `wp mvs migrate-public-static --dry-run`. Reversible (a filter to force everything back through `/serve`: `mvs_serve_public_cloud_direct` → false already does this for behavior; the migration itself is one-way but documented per Production Rule #4).

**Alternatives considered (and why not):**
- *`.htaccess` allow-list*: can't know per-file privacy in `.htaccess` → would leak private files. Rejected.
- *301 redirect from `/serve` to a static file*: still 1 PHP bootstrap per image. Half-measure.
- *Keep one dir, rely only on cache headers (current Card #4)*: only helps repeat loads; first load of every image still bootstraps. This is the band-aid we're replacing.

**Side-effects of bypassing `/serve` (from the security audit — all acceptable for public):**
- Download counting / view tracking: lost for static public files (low — views aren't even tracked in the serve path today). Keep counting for explicit downloads via the existing `/download` REST endpoint.
- Pro watermarking: only applies to *gated* media → public is unaffected.
- SVG CSP sandbox: preserved by **excluding SVG** from static serving (stays on `/serve`).

Risk: medium-high (storage layout + migration + privacy-flip file move, 50+ live sites). Must ship behind the existing escape-hatch filter and a reversible-documented migration.

### Phase C — Re-introduce srcset cheaply

Once Phase B makes public URLs cheap static strings (no per-size signing, no privacy query), re-add the responsive `srcset` (150/300/1024w) + `sizes` in `media_thumbnail()` and the REST `thumbnail_srcset` field — built from the prefetched `thumb_*_path` meta, **zero extra queries**. (This is the version that should have shipped; the reverted one signed 3 URLs/tile.)

## Verification plan (must pass before "done")

- Re-run the per-tile query harness: 12-tile page ≤ 5 MVS queries (Phase A).
- On a sandbox **forced to the local driver**: a public image URL is a direct `/wp-content/uploads/...` static URL (not `/serve`); `curl -I` shows it served by the webserver with `Cache-Control: public` and **no PHP** (compare TTFB to the static-asset baseline). Private image still returns a signed `/serve` URL and 403s without a valid signature.
- Security regression check: public-with-access-rules item, members/private items, and SVGs all still route through `/serve` and gate correctly.
- Browser: Explore grid at desktop + 390px, video posters present, no broken tiles.
- `composer ci` green; refresh wppqa baseline + manifest at release.

## Production Rules compliance

- New behavior is filter-gated (`mvs_serve_public_cloud_direct`, `mvs_public_local_*`) — site owners can force the old all-proxy behavior.
- Migration is a `Migrator` bump (minor release, not patch), idempotent, documented one-way with a CLI dry-run.
- No public symbol removed; `/serve` stays fully functional for private + as the opt-out path for public.

## Effort / sequencing recommendation

1. **Phase A first** — low risk, ships the biggest render-time win immediately, independent of storage.
2. **Phase B next** — the actual 2-3s/image fix; needs its own review + sandbox-on-local testing + migration.
3. **Phase C** — small, after B.
