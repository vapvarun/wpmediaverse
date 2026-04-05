# MediaRepository Extraction — Design Spec

**Date:** 2026-04-05
**Status:** Design Approved
**Scope:** Both `wpmediaverse` (Free) and `wpmediaverse-pro` (Pro)

---

## Context

WPMediaVerse has ~100 direct `$wpdb` queries touching `mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`, and `mvs_media_views` tables scattered across 20+ files. The `MediaMeta` class (`includes/Services/MediaMeta.php`) already centralizes core CRUD for index and meta tables, but ~40 READ/COUNT queries are duplicated across admin pages, CLI, BuddyPress integration, and REST controllers.

**Goal:** Evolve `MediaMeta` into a proper `MediaRepository` that is the single point of access for all media data operations. Update all callers in both Free and Pro plugins. No backwards-compat alias — clean break.

---

## Approach

Rename `WPMediaVerse\Services\MediaMeta` → `WPMediaVerse\Repository\MediaRepository`. Move file from `includes/Services/MediaMeta.php` to `includes/Repository/MediaRepository.php`. Add ~15 new methods consolidating scattered queries. Update all callers in both plugins.

---

## New File Structure

```
includes/Repository/
└── MediaRepository.php    (renamed from Services/MediaMeta.php, extended with new methods)

includes/Services/
└── MediaMeta.php          (DELETED — no alias, clean break)
```

---

## MediaRepository API

### Existing Methods (from MediaMeta — signatures unchanged)

| Method | Purpose |
|--------|---------|
| `get(int $media_id, string $key): mixed` | Get single field (routes to index or meta table) |
| `set(int $media_id, string $key, mixed $value): void` | Upsert single field |
| `set_many(int $media_id, array $data): void` | Bulk upsert multiple fields |
| `delete(int $media_id, string $key): void` | Delete single meta field |
| `get_all(int $media_id): array` | Get all fields (index + meta merged) |
| `exists(int $media_id): bool` | Check if media exists |
| `get_author(int $media_id): int` | Get post_author |
| `get_permalink(int $media_id): string` | Build media URL from slug |
| `insert(array $data): int\|false` | Insert new media record |
| `generate_unique_slug(string $title): string` | Generate unique slug |
| `delete_all(int $media_id): void` | Cascade delete (index + meta) |

### New Methods

#### Lookup Methods

```php
/**
 * Get a single published media by slug.
 * Replaces: TemplateLoader:201-213, MediaController:213
 */
public function get_by_slug( string $slug ): ?array;

/**
 * Batch fetch multiple media by IDs. Avoids N+1.
 * Replaces: TemplateHelpers:320 pattern
 */
public function get_batch( array $media_ids ): array;

/**
 * Find a media_id by meta key/value pair.
 * Replaces: BuddyPressIntegration:2485
 */
public function find_by_meta( string $meta_key, string $meta_value ): ?int;
```

#### Count Methods

```php
/**
 * Count published media.
 * Replaces: CLI:33, OverviewPage, BPIntegration:602
 */
public function count_published(): int;

/**
 * Count media by a specific author.
 * Replaces: BPIntegration:602, BPIntegration:796
 */
public function count_by_author( int $user_id, string $status = 'publish' ): int;

/**
 * Count media by moderation status.
 * Replaces: ModerationService:154
 */
public function count_by_moderation( string $status ): int;

/**
 * Get all moderation status counts.
 * Replaces: CacheService:176, ModerationService:275
 */
public function get_moderation_counts(): array;

/**
 * Count media in a BuddyPress group.
 * Replaces: BPIntegration:1412
 */
public function count_by_group( string $group_id ): int;
```

#### Paginated Query Methods

```php
/**
 * Paginated media query with filters.
 * Replaces: MediaListPage:92, CollectionService:161, StoryService:142
 *
 * @param array $args {
 *     @type string   $status            'publish', 'pending', 'trash', etc.
 *     @type string   $moderation_status 'approved', 'flagged', 'pending'
 *     @type int      $author            Filter by post_author
 *     @type string   $media_type        'image', 'video', 'audio'
 *     @type string   $privacy           'public', 'private', 'followers'
 *     @type string   $search            Search title/description
 *     @type string   $orderby           Column to sort by (default: created_at)
 *     @type string   $order             ASC or DESC (default: DESC)
 *     @type int      $per_page          Items per page (default: 20)
 *     @type int      $page              Page number (default: 1)
 *     @type string   $meta_key          Filter by meta key
 *     @type string   $meta_value        Filter by meta value (requires meta_key)
 * }
 * @return array { items: array, total: int, pages: int }
 */
public function query( array $args = array() ): array;
```

#### Stats Methods

```php
/**
 * Get stats for a single media item.
 * Replaces: StatsService:28, CacheService:91
 */
public function get_stats( int $media_id ): ?array;

/**
 * Get aggregated stats for a user.
 * Replaces: StatsService:62, CacheService:127
 */
public function get_user_stats( int $user_id ): array;

/**
 * Initialize stats row for new media (all zeros).
 * Replaces: UploadService:630
 */
public function init_stats( int $media_id ): bool;

/**
 * Increment a stat counter (views, downloads, reactions, comments, shares).
 * Replaces: ReactionService:198, CommentService:266, ShareService:33, StatsService:141, SignedUrlService:318
 */
public function increment_stat( int $media_id, string $column ): bool;

/**
 * Record a view/download event.
 * Replaces: StatsService:128, SignedUrlService:305
 */
public function record_event( int $media_id, int $user_id, string $ip_hash, string $event_type = 'view' ): void;

/**
 * Prune old view events.
 * Replaces: StatsService:109
 */
public function prune_events( int $days_old = 90 ): int;
```

#### Lifecycle Methods

```php
/**
 * Full cascade delete: index + meta + stats + views + related.
 * Replaces: MediaMeta:386 + scattered deletes in MediaListPage:427, BulkController:147, GDPRService:363
 */
public function delete_cascade( int $media_id ): bool;

/**
 * Trash a media item (set status = 'trash').
 * Replaces: MediaListPage:416
 */
public function trash( int $media_id ): bool;

/**
 * Restore a trashed media item (set status = 'publish').
 * Replaces: MediaListPage:421
 */
public function restore( int $media_id ): bool;
```

---

## Service Container Registration

```php
// In Plugin::register_services()
$container->register('media_repository', function() {
    return new \WPMediaVerse\Repository\MediaRepository();
});

// Keep backwards compat key
$container->register('media_meta', function( $c ) {
    return $c->get('media_repository');
});
```

---

## Callers to Update

### Free Plugin (18 files)

| File | Current Pattern | New Pattern |
|------|----------------|-------------|
| `Core/TemplateLoader.php:201-213` | Direct `$wpdb` SELECT by id/slug | `$repo->get_by_slug()` or `$repo->get_all()` |
| `Core/TemplateHelpers.php:239,320` | Direct `$wpdb` meta count, batch stats | `$repo->count_by_group()`, `$repo->get_batch()` |
| `Admin/OverviewPage.php:627,640` | Direct `$wpdb` COUNT, SUM | `$repo->count_published()`, `$repo->get_user_stats()` |
| `Admin/MediaListPage.php:80,92,416,421,427` | Direct `$wpdb` count, paginate, trash, restore, delete | `$repo->query()`, `$repo->trash()`, `$repo->restore()`, `$repo->delete_cascade()` |
| `Admin/StatsPage.php:76` | Direct `$wpdb` JOIN for top media | `$repo->query()` with stats join |
| `REST/Controller/MediaController.php:213` | Direct `$wpdb` slug lookup | `$repo->get_by_slug()` |
| `REST/Controller/StatsController.php:103` | `MediaMeta::exists()` | `$repo->exists()` |
| `REST/Controller/TagController.php:228` | Direct `$wpdb` JOIN for tag media | Keep (taxonomy-specific join) |
| `REST/Controller/BulkController.php:147` | Direct `$wpdb` DELETE stats | `$repo->delete_cascade()` |
| `Services/StatsService.php:28,62,109,128,141` | Direct `$wpdb` stats queries | Delegate to `$repo->get_stats()`, `get_user_stats()`, `prune_events()`, `record_event()`, `increment_stat()` |
| `Services/ModerationService.php:154,275` | Direct `$wpdb` moderation counts | `$repo->count_by_moderation()`, `$repo->get_moderation_counts()` |
| `Services/CacheService.php:91,127,176` | Direct `$wpdb` stats + moderation | `$repo->get_stats()`, `$repo->get_user_stats()`, `$repo->get_moderation_counts()` |
| `Services/UploadService.php:234,630` | `MediaMeta::insert()` + direct stats init | `$repo->insert()` + `$repo->init_stats()` |
| `Services/StoryService.php:85,135,142` | Direct `$wpdb` paginate + count | `$repo->query()` |
| `Services/CollectionService.php:140,161` | Direct `$wpdb` paginate + count | `$repo->query()` with collection join |
| `Services/SignedUrlService.php:305,318` | Direct `$wpdb` INSERT view + UPDATE stats | `$repo->record_event()` + `$repo->increment_stat()` |
| `Social/ReactionService.php:198` | Direct `$wpdb` UPDATE stats | `$repo->increment_stat()` |
| `Social/CommentService.php:266` | Direct `$wpdb` UPDATE stats | `$repo->increment_stat()` |
| `Social/ShareService.php:33,78` | Direct `$wpdb` UPDATE + SELECT stats | `$repo->increment_stat()`, `$repo->get_stats()` |
| `Integrations/BuddyPressIntegration.php:602,796,1412,2475,2485` | Direct `$wpdb` counts + lookups | `$repo->count_by_author()`, `$repo->count_by_group()`, `$repo->find_by_meta()` |
| `CLI/Commands.php:33,37` | Direct `$wpdb` counts | `$repo->count_published()`, `$repo->get_user_stats()` |
| `Services/GDPRService.php:363` | Direct `$wpdb` DELETE stats | `$repo->delete_cascade()` |

### Pro Plugin (12+ files)

Update `use WPMediaVerse\Services\MediaMeta;` → `use WPMediaVerse\Repository\MediaRepository;` and update all static/instance calls.

| File | Change |
|------|--------|
| `Admin/MigrationPage.php` | Import + method calls |
| `CLI/ImportRtMedia.php` | Import + method calls |
| `CLI/ImportMediaPress.php` | Import + method calls |
| `CLI/ImportBuddyBoss.php` | Import + method calls |
| `Challenges/ChallengeService.php` | Import + method calls |
| `Video/TranscodeService.php` | Import + method calls |
| `Captions/TranscriptionService.php` | Import + method calls |
| `Analytics/AnalyticsService.php` | Import + method calls |
| (+ any others found during implementation) | |

---

## Testing Strategy

1. **Unit tests for new methods** — Each new repository method gets a test in `tests/unit/MediaRepositoryTest.php`
2. **Rename existing test** — `tests/unit/MediaMetaTest.php` → `tests/unit/MediaRepositoryTest.php`
3. **Integration verification** — Run full test suite after migration, verify no regressions
4. **Manual smoke test** — Upload media, view stats, check admin pages, run CLI commands

---

## What Does NOT Change

- Database table structures (no migrations)
- REST API responses
- Hook signatures
- Admin UI behavior
- Pro plugin behavior (same operations, different import path)

---

## Verification Plan

1. Run `composer run phpcs` — no new violations
2. Run `composer run phpstan` — no new errors
3. Run `composer test` — all existing tests pass
4. Grep for any remaining `MediaMeta` references: `grep -r "MediaMeta" includes/` should return zero in Free plugin
5. Grep for any remaining direct `mvs_media_index` queries outside repository: should be minimal (only taxonomy joins)
6. Browser test: upload media, view explore page, check admin overview, run moderation
