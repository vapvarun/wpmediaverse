# M1 + M2 Completion Plan — 2026-05-17

Target: finish data-access hygiene in 1.3.0. Per user decision today, 1.2.4
and 1.2.5 are skipped; we go straight to 1.3.0. Customer fleet is still
experimenting, so we refactor cleanly without feature-flag soak.

## Already done (this session)

| Commit | Surface | Call sites |
|---|---|---|
| Free `9c5cb55` | 4 Free templates: media-single, album, collection, explore | 4 of 5 (1 deferred — see Tier D) |
| Pro `ea25593`  | 5 Pro profile.php files: user-profile, pinterest, instagram, dribbble, flickr | 10 of ~20 (rest in Tier D) |

New helpers added to Free `MediaRepository`:
- `query_by_author($user_id, $args)` — full rows with status/moderation/limit/offset
- `count_by_author($user_id, $status, $moderation_status)` — extended with optional 3rd arg
- `get_meta_values($id, $key)` — multi-row meta read
- `get_collection_media_ids($collection_id, $limit)` on FavoriteService
- `get_items_with_data($album_id, $status)` on AlbumService

## Remaining scope

### Tier A — Template partials (M1 Pro tail, trivial)

Same uniform pattern as the profile.php files we just shipped. Each is one
`count` + one `listing` against `mvs_media_index`. Time: ~30 min total.

| File | Lines | Pattern |
|---|---|---|
| `templates/partials/stories-bar.php` | 2 sites | author listing for stories rail |
| `templates/partials/feed-card.php` | 2 sites | per-card lookup (likely thumbnail / author resolve) |
| `templates/layouts/instagram/partials/stories-bar.php` | 18-19 | same as above |
| `templates/layouts/instagram/partials/feed-card.php` | 97-98 | same as above |

**Verification**: render `/members-2/varundubey/` (instagram layout default), check stories bar + feed cards display unchanged.

---

### Tier B — Pro `includes/` reads, simple FK lookups (M2, low risk)

These read a single row from `mvs_media_index` by primary key — easiest M2 surface.
All can use existing `MediaRepository::get($id, $key)` / `get_all($id)`.
Time: ~45 min total.

| File | Lines | Current query | Replacement |
|---|---|---|---|
| `includes/Challenges/ChallengeService.php` | 829-830 | `SELECT cover_media_id FROM mvs_media_index WHERE media_id = %d` (per code comment, this is the FK lookup) | `$repo->get($cover_media_id, 'file_url')` then resolve thumb |
| `includes/Tournaments/TournamentService.php` | 913-914 | Same FK lookup pattern | Same fix |
| `includes/Challenges/ChallengeNotificationListener.php` | 315 | Single-row read | `$repo->get($id, $key)` |
| `includes/Privacy/PrivacyUIService.php` | 338 | Single-row read | `$repo->get($id, $key)` |
| `includes/Analytics/AnalyticsService.php` | 397 | Single-row read | `$repo->get($id, $key)` |

**Verification per file**: each has a specific user-visible surface — admin
panel for ChallengeManager, etc. Visit the surface, confirm data still
renders.

---

### Tier C — Pro `includes/` reads, batched + complex (M2, medium risk)

These do batch reads or joins across `mvs_media_index` + `mvs_media_meta`.
Need either new MediaRepository helpers or carefully-staged refactors.
Time: ~90 min total.

| File | Lines | What it does | New helper needed? |
|---|---|---|---|
| `includes/Admin/CloudOpsManager.php` | 170, 248 | Storage management batch queries (next-N to migrate / delete) | Probably yes — `MediaRepository::get_local_media_batch($limit, $offset)` for cloud-migration scans |
| `includes/Admin/ReportManager.php` | 208-209, 258 | Report queue admin — joins to mvs_media_index for context | Maybe — could use existing `get_batch` after a separate IDs query |
| `includes/REST/CompeteSummaryController.php` | 432-433 | Competition summary aggregate | Likely — competition-specific aggregate, may live in Pro |
| `includes/Quota/QuotaService.php` | 538-539 | Storage size sum by user (`SUM(file_size)`) | Yes — `MediaRepository::storage_size_by_author($user_id)` |
| `includes/Video/TranscodeController.php` | 309-310, 319 | Transcode status report — DISTINCT count + join | Yes — `MediaRepository::count_by_meta_value($key, $value)` or accept domain-specific direct query as Pro-internal exception |
| `includes/Connectors/ConnectorRESTController.php` | 836-837 | Connector-side media listing | Likely existing helpers cover |
| `includes/Integrations/AbstractBatchImporter.php` | 136-137 | Dedup check during import — read meta for hash match | Yes — `MediaRepository::find_by_hash($hash)` (might already exist as `find_by_meta`) |

**Verification per file**: each has a corresponding admin/CLI surface. Test
the surface end-to-end.

---

### Tier D — Defer to 1.3.x or 1.4.0

Complex aggregations with conditional joins / dynamic SQL / subquery patterns
that need a small query-builder before they fit cleanly behind
`MediaRepository`. Same reason the simple count/listing pattern was easy
to refactor but the explore aggregation wasn't.

| Surface | Files | Why deferred |
|---|---|---|
| Free explore listing | `templates/explore.php:340-368` | Dynamic `{$where}` + conditional joins for search/tag/cat/profile + gallery_exclude NOT IN subquery |
| Pro feed-body aggregations | `templates/layouts/{dribbble,flickr,pinterest}/feed-body.php` + `templates/instagram-feed.php` | Same shape as Free explore listing |
| Pro layout profile.php listings with `gallery_exclude` | `dribbble/profile.php`, `flickr/profile.php` | NOT IN subquery to filter non-cover gallery items |
| RtMedia / MediaPress / BuddyBoss migration admins | `Integrations/*/MigrationAdmin.php` | Migration-time dedup checks. Each platform has specific shapes. Lower priority because admin-only + one-shot per site. |
| Platform importers | `Integrations/*/Importer.php`, `CLI/ImportThumbnailTrait.php` | CLI-only; runs once per migration. Low blast radius. |

**Design needed**: a `MediaRepository::query()` builder accepting an array
of filters (tags, cats, search, profile, gallery_cover_only, status,
moderation, limit, offset, orderby). Once that exists, all of Tier D's
listings collapse to one call.

---

## Execution order

1. **Tier A** (Pro template partials) — 30 min, zero risk, immediate UX coverage
2. **Tier B** (simple FK lookups) — 45 min, low risk, easy to verify
3. **Tier C** (batched / complex reads) — 90 min, medium risk, needs new repository helpers
4. **Tier D** (query-builder work) — defer to dedicated session, scoped as "Query Builder + Listing Consolidation" in 1.3.x or 1.4.0

Total Tier A+B+C: ~2.75 hours of focused work.

## Risk control

- WPCS + PHPStan clean before commit
- One commit per tier (or per file for Tier C)
- Browser smoke after each tier (Tier A: profile page, Tier B: admin manager UIs, Tier C: each affected admin surface)
- Refresh `audit/cleanup/boundary-violations.json` after Tier C to confirm count drop
- Each commit body lists files touched + verification done

## Stop conditions

Stop and revert if:
- Browser smoke shows a fatal on any refactored surface
- PHPStan baseline grows
- WPCS errors appear that aren't pre-existing
- Boundary check shows MORE violations after refactor (means a refactor introduced new direct $wpdb)

## Out of scope

- Free `MediaRepository`'s own direct `$wpdb` (it OWNS the tables, that's the right place)
- Pro services querying Pro-owned tables (not a cross-plugin boundary)
- Anything in Tier D until the query-builder lands
