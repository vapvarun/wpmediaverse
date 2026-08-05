# Fix plan — WebP/AVIF 403 on CDN sites (Basecamp card 10162798416)

Status: **IMPLEMENTED 2026-08-05.** P1 and P2 both landed; see "Outcome" at the bottom.
Investigated against `2.3.1` source and reproduced on the `mediaverse.local` sandbox before any
code was changed.

**Card:** [Support] [Media Gallery] Broken WebP images (403) — stale local webp URLs emitted when
JPEG is served from Bunny CDN
**Client:** vystopiatransformation.com (production, Apache) · Zoho 233992000086966067
**Severity:** P1 for CDN sites · **Category:** our bug · **Effort:** ~3 h + verification
**Target:** `2.3.1` (unreleased — `v2.3.0` is the latest tag)

## The invariant this fix restores

> **Cloud path is for display. All processing happens on the local path.**

The codebase already works this way — variants are generated on local disk, `_path` metas hold
driver-agnostic relative paths, and the display layer resolves them through the active driver.
**The WebP/AVIF display path is the one place that does not.** It reads an absolute URL that was
frozen at process time and hands it straight to the browser.

That is the whole bug. Not a missing feature — a single surface that skipped the established
pattern.

## Verdict: yes, fix it. The card's diagnosis is correct.

All four claims check out against source. Reproduced at code level:

```
CASE A — JPEG via gated /serve      → webp out: (empty — guard fired, correct)
CASE B — JPEG direct from Bunny CDN → webp out: http://site/wp-content/uploads/wpmediaverse/…webp
                                                 ^ different host, into the deny-all folder
```

The only guard in `get_webp_variant_url()` is `strpos( $jpeg_url, '/mvs/v1/serve' )`. A direct CDN
URL contains no `/serve`, so it never fires. The 403 comes from the `Deny from all` `.htaccess`
MediaVerse itself writes (`Activator.php:147`).

## The fix is already written — three functions away

`maybe_direct_cloud_thumbnail_url()` (the **JPEG** path) implements the invariant in two branches:

```php
// 1. Preferred: driver-agnostic rel path → active/location driver
$rel_path = $repo->get_raw( $media_id, $meta_key . '_path' );
if ( '' !== $rel_path ) {
    $driver = $storage->get_driver_for_location( $media_id );
    return $driver->url( $rel_path );
}

// 2. Legacy rows (pre-1.4.0, no _path yet) — use the stored URL, but ONLY if
//    it is actually cloud-hosted. Otherwise let /serve stream the local file.
if ( ! $this->is_cloud_hosted_url( $thumb_url ) ) {
    return '';
}
```

**`get_webp_variant_url()` / `get_avif_variant_url()` implement neither branch.** They read the
legacy URL meta and return it unconditionally.

So the fix is not to invent a guard — it is to apply the one that already exists. Nothing new is
introduced, and the two paths stop disagreeing.

## Complete-flow audit — all 25 legacy-URL reads classified

Swept every `get_raw()` reading a non-`_path` URL meta across Free and Pro, so this does not come
back:

| Call site | Role | Verdict |
|---|---|---|
| `TemplateHelpers` webp (:689–745) | **display — emits URL** | **BROKEN — P1** |
| `TemplateHelpers` avif (:764–810) | **display — emits URL** | **BROKEN — P1** |
| `SignedUrlService::maybe_direct_cloud_url` (:531–538) | display — emits URL | correct — has both branches |
| `SignedUrlService::maybe_direct_cloud_thumbnail_url` (:838–912) | display — emits URL | correct — the reference implementation |
| `SignedUrlService::local_webp_variant_path` (:1091) | process — local FS path | correct — prefers `_path` |
| `SignedUrlService` AVIF equivalent (:1233) | process — local FS path | correct |
| `SignedUrlService::has_resolvable_thumbnail` (:196–217) | **existence check**, not emission | fine |
| `MediaController` (:778) | **existence check** ("is there a thumb yet") | fine |
| `MediaListPage` (:548, :1396, :1624, :1625) | admin display — emits URL | **stale on CDN — P2** |
| `UploadService` (:1492) | process | fine — local |
| `ImageOptimizationService` (:495) | process | fine — local |
| `StorageService` (:142, :261) | location detection from `file_url` | works; see note |
| `StorageRepairService` (:334) | repair tooling | fine |
| `CLI\Commands` (:481, :623, :1070) | CLI tooling | fine |
| `CloudOps::migrate_one` | process — **never rewrites `*_webp`/`*_avif` URL metas** (0 matches) | **P2** |

**Two display surfaces are broken (P1), one admin surface is stale (P2), one migration gap (P2).**
Everything else already honours the invariant. `/serve` was done correctly.

**Affected user-facing surfaces** — all four call sites funnel through `TemplateHelpers`:
`:134`/`:167` (lightbox), `:413` (grid thumbnail), `:648–649` (`picture_or_img()`, used
everywhere). Matches the card: gallery grid, lightbox, BP activity thumbnails.

**Pro:** no callers. Pro ships the Bunny driver, so verification needs Pro active.

**Note on `StorageService` location detection.** `get_driver_for_location()` reads `file_url` to
decide where a file lives — URL-as-state, the same fragility class that produced this bug. It is
load-bearing and correct today; changing it is a separate piece of work with its own risk.
**Explicitly out of scope**, recorded here so it is a decision rather than an oversight.

## Correction to an earlier read: the legacy branch must stay

I first concluded the card's "backfill existing rows" was unnecessary because `_path` coverage is
100%. **That holds on this sandbox and must not be assumed in production.**
`MediaVariantWriter::path_meta_ok()` *deliberately* skips `_path` for imported records:

> *"Imported records (MediaPress / rtMedia / BuddyBoss) may store ABSOLUTE paths in `file_path` …
> Falling through to the legacy URL branch keeps imported records working."*

So real sites have rows with a `_webp` URL and no `_webp_path`. Branch 2 is what serves them —
which is another reason to copy the JPEG idiom wholesale rather than simplify it away.

Sandbox coverage, for reference (100% here — hence the wrong first conclusion):

| meta | rows | `_path` sibling | rows |
|---|---|---|---|
| `original_webp` | 47 | `original_webp_path` | 47 |
| `thumb_large_webp` | 14 | `thumb_large_webp_path` | 14 |
| `thumb_medium_webp` | 48 | `thumb_medium_webp_path` | 48 |
| `thumb_thumb_webp` | 43 | `thumb_thumb_webp_path` | 43 |

## Work

### P1 — `TemplateHelpers` webp/avif (fixes the customer bug)

Replace the raw meta read with the JPEG idiom:

1. Keep the existing `/serve` guard — unchanged, still correct.
2. `_path` present → `get_driver_for_location( $media_id )->url( $rel )`. Puts the variant on the
   same host as the JPEG by construction.
3. No `_path` → legacy URL, **but only if `is_cloud_hosted_url()`**; otherwise return `''` and let
   the JPEG stand.

**Consolidate while in there.** The two methods are ~57-line near-identical siblings differing
only by format. Collapse to one private `get_variant_url( $media_id, $size, $jpeg_url, $format )`
with two thin public wrappers keeping their current signatures (Production Rules 1 and 2). Use
`Core\MediaUrl::variant_meta_key()` / `variant_path_meta_key()` for the key mapping — the facade
exists, and its own docblock notes the mapping is *"hand-written 6 times across `TemplateHelpers`
and `SignedUrlService`"*. This removes two of the six and keeps
`bin/duplication-gate.sh` (local-CI 1.5) quiet.

### P2 — admin + migration hygiene (ship separately, do not block P1)

- `MediaListPage` (4 sites): route through the same resolver so admin stops showing dead URLs.
- `CloudOps::migrate_one()`: rewrite `*_webp` / `*_avif` URL metas to the destination base,
  mirroring `StorageService::relocalize_media_urls()`. The WebP **files already migrate** —
  `get_stored_file_paths()` selects `meta_key LIKE '%_path'`, which matches
  `thumb_large_webp_path` — so only the URL meta goes stale.

### Not doing

- **The card's suggestion (3), "backfill existing migrated rows."** Unnecessary once P1 lands:
  the read side stops consulting the stale meta wherever `_path` exists, repairing migrated rows
  on read with no data migration.
- **`StorageService` location detection** — see note above.

## Verification (blocking)

- [ ] Unit: `_path` present, cloud driver → variant host == JPEG host
- [ ] Unit: `_path` present, local driver → unchanged behaviour
- [ ] Unit: no `_path`, legacy URL is local while JPEG is on CDN → returns `''`
- [ ] Unit: no `_path`, legacy URL is cloud-hosted → returns it (imported-record path)
- [ ] Unit: `/serve` JPEG → returns `''` (existing guard, regression)
- [ ] Re-run `scratchpad/repro-webp-403.php` — CASE B must emit `example.b-cdn.net`
- [ ] Browser, Pro active + Bunny driver: grid, lightbox, BP activity — no broken images, no 403
- [ ] Browser, local driver: WebP still served, `/serve` unchanged
- [ ] Imported-record row (no `_path`): renders, never broken
- [ ] `composer ci` green

**No existing test covers variant URL emission** — only `ImageOptimizationServiceTest`, which
covers generation. That gap is why this shipped; the unit tests above are part of the fix, not
follow-up.

## Release

`2.3.1`, patch-shaped: no schema, no new settings, no hook changes, no public signature changes.
Production Rule 7 satisfied. Pro needs no release.

```
* Fix      - WebP and AVIF images no longer return 403 on sites using a CDN. Variant URLs now resolve through the same storage driver as the JPEG instead of a stale local path.
```

---

## Outcome (2026-08-05)

**Landed.** P1 and P2 together, plus the missing test coverage.

| File | Change |
|---|---|
| `includes/Core/MediaUrl.php` | New `variant_url( $media_id, $meta_key )` — the single resolver: `_path` through `get_driver_for_location()`, legacy URL meta as fallback |
| `includes/Core/TemplateHelpers.php` | Two ~57-line siblings collapsed to `get_variant_url()` + thin wrappers; adds the same-host invariant |
| `includes/Admin/MediaListPage.php` | Variant table links and "Open WebP copy" resolve through the facade |
| `includes/Services/CloudOps.php` | `refresh_variant_urls()` repoints every `%_path` sibling URL meta after migration |
| `tests/unit/VariantUrlTest.php` | 8 regression tests (new file) |

**The resolver landed in `MediaUrl`, not `TemplateHelpers`.** The first pass put the `_path` →
driver logic inline in `TemplateHelpers`, which would have meant duplicating it into
`MediaListPage` for the admin links — n=2 on the duplication rule and a second place to fix next
time. `Core\MediaUrl` is the read-side URL facade and its own docblock already complained the
size+format mapping was "hand-written 6 times", so it is where this belongs. Both callers now
share one implementation.

**Verification run**

- 8 new tests pass; **3 of them fail against the pre-fix code** (CDN pairing with `_path`, without
  `_path`, and the AVIF twin) — confirmed by reverting and re-running. The other 5 pass both
  before and after, proving the working paths were not broken.
- Full suite 276 pass (was 268). `composer ci:no-journeys` green, including the duplication gate.
- PHPStan initially flagged four defensive null guards as dead code. It was right:
  `ServiceContainer::get()` throws on an unknown key and `Plugin::container()` is non-nullable.
  Guards removed rather than suppressed.
- Branch harness (`scratchpad/repro-webp-403.php`) covers all six paths: `/serve` guard, CDN
  primary + local file, local-direct primary, legacy row same-host, legacy row host-mismatch,
  AVIF wrapper.
- Admin links verified resolving, including a stale-meta resilience check (corrupt the URL meta,
  confirm `_path` still wins).

**What this environment could NOT prove.** Local runs nginx, which ignores the `.htaccess`
`Deny from all`, so the 403 itself cannot fire here — both URLs return 200. And with no cloud
driver configured, the "variant correctly emitted on the CDN host" case cannot be produced; the
sandbox exercises the safe-degradation branch instead. **A browser pass against a real Bunny CDN
site is still outstanding** and is the one check that closes the customer's report.

Frontend browser check on the local-storage site showed no regression: grid images route through
`/serve`, so WebP is suppressed there exactly as before. Two `<img>` elements with an empty `src`
resolve to the page URL and register as "broken" both before and after — pre-existing, unrelated.

**Noticed, not fixed (out of scope):** `picture_or_img()` emits `<source>` elements with empty
`srcset` when no variant applies, rather than omitting them. Harmless (browsers skip them) and
identical before and after this change, but it is untidy markup worth a follow-up.
