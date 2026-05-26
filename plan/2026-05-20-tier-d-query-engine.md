# TIER-D — MediaRepository query engine (code-level refactor plan)

Supersedes the "TIER-D" item in `plan/2026-05-17-1.4.0-plan.md` with a
code-level design. Goal unchanged: finish the data-access migration so no
template or Pro file builds raw SQL against `mvs_media_index` /
`mvs_media_meta`. This plan chooses a **single query engine** over a
per-consumer patch, because the same listing SQL is currently copy-pasted
across 6 sites and is already drifting.

## Principle

One place builds index-listing SQL. New public methods AND the subset of
existing helpers that share the shape route through it. Bespoke queries that
do NOT share the shape are left alone — consolidating them would be
over-engineering, not refactoring.

## Current state — the duplication we are removing

| Fragment | Copies today | Risk |
|---|---|---|
| status + moderation + author + ORDER created_at DESC + LIMIT/OFFSET | explore + 5 Pro feeds + `query_by_author` + `query_recent` | drift |
| Privacy clause (anon / member / moderator) | explore + 3 Pro feeds (4×) | **drift — security-sensitive** |
| Gallery-exclude `NOT IN (subquery)` | explore + 5 Pro feeds (verbatim ×6 less instagram) | drift |
| Conditional tag/category `term_relationships` join | explore + 3 Pro feeds | drift |

**Confirmed drift bug:** `dribbble/feed-body.php` omits
`moderation_status = 'approved'`, so the Dribbble layout shows flagged /
pending / rejected media that flickr + pinterest correctly hide. The engine
fixes this by construction (shared default), gated by an escape-hatch filter.

## Target architecture

### 1. Internal engine (private)

```php
// The ONLY place mvs_media_index listing SQL is assembled.
private function build_query_parts( array $args ): array {
    // returns [ 'join' => string, 'where' => string, 'params' => array, 'distinct' => bool ]
}
```

Arg schema (all optional, safe defaults):

| Arg | Type | SQL |
|---|---|---|
| `status` | string `'publish'` | `m.status = %s` (`''` skips) |
| `moderation_status` | string `''` | `m.moderation_status = %s` (`''` skips) |
| `author_id` | int `0` | `m.post_author = %d` (`0` skips) |
| `search` | string `''` | `(m.title LIKE %s OR m.description LIKE %s)` via `esc_like` |
| `tag_tt_id` | int `0` | conditional `INNER JOIN term_relationships tr` + `tr.term_taxonomy_id = %d` → sets `distinct` |
| `category_tt_id` | int `0` | conditional `INNER JOIN term_relationships trc` + `trc.term_taxonomy_id = %d` → sets `distinct` |
| `privacy` | `'any'`\|`'public'`\|`'visible'` | via `build_privacy_where()` |
| `viewer_id` | int `0` | required when `privacy='visible'` |
| `exclude_non_cover_group` | bool `false` | `m.media_id NOT IN (<gallery subquery>)` |
| `since` | string `''` | `m.created_at >= %s` (folds in `query_recent`) |
| `orderby` | string `'created_at'` | **allowlist**: `created_at\|media_id\|title` |
| `order` | string `'DESC'` | **allowlist**: `ASC\|DESC` |
| `limit` | int `20` | `LIMIT %d` |
| `offset` | int `0` | `OFFSET %d` |

### 2. Privacy — single source of truth (private)

```php
private function build_privacy_where( string $mode, int $viewer_id ): array {
    // 'any'     => [ '', [] ]                          (moderator / no filter)
    // 'public'  => [ "m.privacy = 'public'", [] ]      (anon)
    // 'visible' => [ "(m.privacy='public' OR m.privacy='members' OR m.post_author=%d)", [ $viewer_id ] ]
}
```

Lives in the Repository layer (never up-call PrivacyService — that inverts
the layer order). This is the one fragment whose drift would leak private
media, so it must have exactly one implementation + a dedicated test.

### 3. Gallery-exclude — single source of truth (private)

One private method returning the static `NOT IN (SELECT ... media_group ...
group_position != '0')` subquery string (no params). Replaces 6 verbatim copies.

### 4. Public surface (additive — no removals/renames)

```php
public function query( array $args = array() ): array       // full mvs_media_index rows, like get_batch
public function query_count( array $args = array() ): int    // COUNT(DISTINCT m.media_id) when distinct, else COUNT(*)
```

`query_count` mirrors `query`'s args exactly so list + count never diverge.

### 5. Fold-in (consolidate, don't add a 13th path)

Re-implement on top of `build_query_parts()`, each guarded by a SQL-equality
diff test (below):

- `query_by_author()` → `query([ author_id, status, moderation_status, limit, offset ])`
- `count_by_author()` → `query_count([ author_id, status, moderation_status ])`
- `count_published()` → `query_count([ status => 'publish' ])`
- `query_recent()` → `query([ status => 'publish', since, limit ])`

**Left untouched (bespoke, not in scope):** `get_group_media_ids` (CAST
position ordering), `query_by_meta` / `count_by_meta` (meta-join domain),
`query_public_cloud_candidates` (status-IN + file_path/file_url filters),
`find_*_by_meta`, aggregates, stats, mutations.

### 6. Escape-hatch filters (Production Rule #3)

Each migrated consumer passes its args through a filter before query:
`mvs_explore_query_args`, `mvs_feed_query_args` (layout in context),
`mvs_profile_query_args`. A site can restore prior behavior (incl. old
Dribbble moderation behavior) with a one-line `add_filter`.

## Safety net — prove the refactor is invisible

`build_query_parts()` is pure (returns strings + params, runs no query), so a
PHPUnit test asserts the **fully-prepared SQL string is byte-identical** to
each current consumer's hand-written SQL, for every viewer mode (anon /
member / moderator) and filter combo. This converts "trust me" into a diff.
Only the Dribbble case is allowed to differ (the intended moderation fix),
asserted explicitly.

## Migration sequence (one commit each, browser-verify per item)

1. Engine + `query()`/`query_count()` + privacy + gallery helpers. Acceptance
   tests written first (red), incl. SQL-injection on `orderby`/`order`.
2. Fold in the 4 compatible helpers (SQL-equality diff green).
3. Migrate `explore.php` (richest case). Smoke all/search/tag/category/profile
   + logged-out vs member.
4. Migrate flickr → pinterest → dribbble feeds (dribbble closes the leak).
   Smoke each; verify Dribbble hides flagged/pending.
5. Migrate dribbble + flickr profile galleries (`exclude_non_cover_group`).
6. Run `bin/cleanup-boundary-check.sh` both repos → expect 0 violations.

## Impact on existing sites

- **Refactor + fold-in:** zero visible change — SQL byte-identical, pinned by
  the equality test. Additive public methods (Rules #1/#2/#8). No schema
  change (#4), no template removed (#5).
- **Privacy centralization:** zero impact if identical (proven by test); the
  single highest-value durability win since it removes a 4× drift surface on
  security-sensitive SQL.
- **Dribbble moderation fix:** only affects sites that use the Dribbble layout
  AND actively moderate media into non-`approved` states (`moderation_status`
  defaults to `approved`, so default configs see no change). Reversible via
  `mvs_feed_query_args`.
- **Theme-overridden templates:** a site overriding a feed template in its
  theme keeps its own SQL — the migration reaches only the plugin's copies.
  Not a breakage; noted so we don't claim 100% reach.

## Acceptance (done definition)

- `bin/cleanup-boundary-check.sh` = 0 direct-`$wpdb`-on-Free-tables in both repos.
- SQL-equality test green for all folded helpers + all migrated consumers
  (Dribbble delta asserted intentionally).
- Per-consumer browser smoke passes.
- `composer ci` green in both repos before each push.

## Out of scope (explicit)

- Result caching (`wp_cache_set_last_changed`) — privacy makes results
  viewer-dependent; a naive cache risks cross-viewer leakage. Separate,
  careful follow-up, not bundled here.
- Migration admins #7-9 — already routed through repository; remaining point
  meta reads are covered by `count_by_meta` / `find_by_meta`. No work.
- Removing the now-thin public helpers — they stay (Pro consumes them);
  deprecation, if ever, follows the 2-major-version rule.
