# MediaRepository Extraction — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract and evolve `MediaMeta` into `MediaRepository` — the single point of access for all media data operations across both Free and Pro plugins.

**Architecture:** Rename `WPMediaVerse\Services\MediaMeta` → `WPMediaVerse\Repository\MediaRepository`. Keep all 11 existing static methods unchanged. Add ~15 new static methods consolidating scattered `$wpdb` queries. Update all callers in both plugins. No backwards-compat alias (no users yet).

**Tech Stack:** PHP 8.1+, WordPress `$wpdb`, PHPUnit 9.6

**Spec:** `docs/superpowers/specs/2026-04-05-media-repository-design.md`

---

## File Structure

### Create
- `includes/Repository/MediaRepository.php` — Evolved from MediaMeta with new methods

### Delete
- `includes/Services/MediaMeta.php` — Replaced by Repository/MediaRepository.php

### Modify (Free Plugin — `wpmediaverse/`)
- `includes/Core/Plugin.php` — Service container registration
- `includes/Core/TemplateLoader.php` — Use get_by_slug()
- `includes/Core/TemplateHelpers.php` — Use count_by_group(), get_batch()
- `includes/Admin/OverviewPage.php` — Use count_published()
- `includes/Admin/MediaListPage.php` — Use query(), trash(), restore(), delete_cascade()
- `includes/Admin/StatsPage.php` — Use query() with stats
- `includes/Services/UploadService.php` — Use insert(), init_stats()
- `includes/Services/StatsService.php` — Delegate to repository stats methods
- `includes/Services/ModerationService.php` — Use count_by_moderation(), get_moderation_counts()
- `includes/Services/CacheService.php` — Use get_stats(), get_user_stats(), get_moderation_counts()
- `includes/Services/StoryService.php` — Use query()
- `includes/Services/CollectionService.php` — Use query()
- `includes/Services/SignedUrlService.php` — Use record_event(), increment_stat()
- `includes/Services/GDPRService.php` �� Use delete_cascade()
- `includes/Social/ReactionService.php` — Use increment_stat()
- `includes/Social/CommentService.php` — Use increment_stat()
- `includes/Social/ShareService.php` — Use increment_stat(), get_stats()
- `includes/REST/Controller/MediaController.php` — Use get_by_slug()
- `includes/REST/Controller/StatsController.php` — Use exists()
- `includes/REST/Controller/BulkController.php` — Use delete_cascade()
- `includes/Integrations/BuddyPressIntegration.php` — Use count_by_author(), count_by_group(), find_by_meta()
- `includes/CLI/Commands.php` — Use count_published()
- `tests/unit/MediaMetaTest.php` → `tests/unit/MediaRepositoryTest.php`

### Modify (Pro Plugin — `wpmediaverse-pro/`)
- All files importing `WPMediaVerse\Services\MediaMeta` — update to `WPMediaVerse\Repository\MediaRepository`

---

### Task 1: Create MediaRepository with Existing Methods

**Files:**
- Create: `wpmediaverse/includes/Repository/MediaRepository.php`

- [ ] **Step 1: Create Repository directory and file**

Create `includes/Repository/MediaRepository.php` by copying `includes/Services/MediaMeta.php` with these changes:
1. Change namespace from `WPMediaVerse\Services` to `WPMediaVerse\Repository`
2. Change class name from `MediaMeta` to `MediaRepository`
3. Update class docblock to say "Centralized media data repository"
4. Keep ALL existing methods exactly as-is (get, set, set_many, delete, get_all, exists, get_author, get_permalink, insert, generate_unique_slug, delete_all)
5. Keep all phpcs:ignore comments

- [ ] **Step 2: Verify the file compiles**

Run: `php -l includes/Repository/MediaRepository.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add includes/Repository/MediaRepository.php
git commit -m "refactor: create Repository/MediaRepository from Services/MediaMeta"
```

---

### Task 2: Add Lookup Methods

**Files:**
- Modify: `wpmediaverse/includes/Repository/MediaRepository.php`

- [ ] **Step 1: Add get_by_slug() method**

Add after the `exists()` method:

```php
/**
 * Get a single published media item by slug.
 *
 * @param string $slug Media slug.
 * @param string $status Status to match (default 'publish').
 * @return array|null Media data or null if not found.
 */
public static function get_by_slug( string $slug, string $status = 'publish' ): ?array {
    global $wpdb;

    $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s AND status = %s LIMIT 1",
            $slug,
            $status
        ),
        ARRAY_A
    );

    return $row ?: null;
}
```

- [ ] **Step 2: Add get_batch() method**

```php
/**
 * Batch fetch multiple media items by IDs.
 *
 * @param int[] $media_ids Array of media IDs.
 * @return array Associative array keyed by media_id.
 */
public static function get_batch( array $media_ids ): array {
    global $wpdb;

    if ( empty( $media_ids ) ) {
        return array();
    }

    $media_ids    = array_map( 'absint', $media_ids );
    $placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );

    $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            ...$media_ids
        ),
        ARRAY_A
    );

    $result = array();
    foreach ( $rows as $row ) {
        $result[ (int) $row['media_id'] ] = $row;
    }

    return $result;
}
```

- [ ] **Step 3: Add find_by_meta() method**

```php
/**
 * Find a media_id by meta key/value pair.
 *
 * @param string $meta_key   Meta key to search.
 * @param string $meta_value Meta value to match.
 * @return int|null Media ID or null if not found.
 */
public static function find_by_meta( string $meta_key, string $meta_value ): ?int {
    global $wpdb;

    $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $meta_value
        )
    );

    return $id ? (int) $id : null;
}
```

- [ ] **Step 4: Commit**

```bash
git add includes/Repository/MediaRepository.php
git commit -m "feat(repo): add lookup methods — get_by_slug, get_batch, find_by_meta"
```

---

### Task 3: Add Count Methods

**Files:**
- Modify: `wpmediaverse/includes/Repository/MediaRepository.php`

- [ ] **Step 1: Add count methods**

Add all 5 count methods:

```php
/**
 * Count published media items.
 *
 * @return int
 */
public static function count_published(): int {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = %s",
            'publish'
        )
    );
}

/**
 * Count media items by a specific author.
 *
 * @param int    $user_id User ID.
 * @param string $status  Status to match (default 'publish').
 * @return int
 */
public static function count_by_author( int $user_id, string $status = 'publish' ): int {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = %s",
            $user_id,
            $status
        )
    );
}

/**
 * Count media items by moderation status.
 *
 * @param string $status Moderation status ('approved', 'flagged', 'pending', 'rejected').
 * @return int
 */
public static function count_by_moderation( string $status ): int {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE moderation_status = %s",
            $status
        )
    );
}

/**
 * Get counts for all moderation statuses.
 *
 * @return array Associative array of status => count.
 */
public static function get_moderation_counts(): array {
    global $wpdb;

    $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        "SELECT moderation_status, COUNT(*) as cnt FROM {$wpdb->prefix}mvs_media_index GROUP BY moderation_status",
        ARRAY_A
    );

    $counts = array();
    foreach ( $rows as $row ) {
        $counts[ $row['moderation_status'] ] = (int) $row['cnt'];
    }

    return $counts;
}

/**
 * Count media in a BuddyPress group.
 *
 * @param string $group_id Group ID.
 * @return int
 */
public static function count_by_group( string $group_id ): int {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta mm
             INNER JOIN {$wpdb->prefix}mvs_media_index mi ON mi.media_id = mm.media_id
             WHERE mm.meta_key = 'group_id' AND mm.meta_value = %s AND mi.status = 'publish'",
            $group_id
        )
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/Repository/MediaRepository.php
git commit -m "feat(repo): add count methods — published, by_author, by_moderation, by_group"
```

---

### Task 4: Add Stats Methods

**Files:**
- Modify: `wpmediaverse/includes/Repository/MediaRepository.php`

- [ ] **Step 1: Add stats methods**

```php
/**
 * Get stats for a single media item.
 *
 * @param int $media_id Media ID.
 * @return array|null Stats array or null.
 */
public static function get_stats( int $media_id ): ?array {
    global $wpdb;

    $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT views, downloads, reactions, comments, shares, updated_at
             FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
            $media_id
        ),
        ARRAY_A
    );

    return $row ?: null;
}

/**
 * Get aggregated stats for a user across all their media.
 *
 * @param int $user_id User ID.
 * @return array { total_media, total_views, total_downloads, total_reactions, total_comments, total_shares }
 */
public static function get_user_stats( int $user_id ): array {
    global $wpdb;

    $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "SELECT
                COUNT(*) as total_media,
                COALESCE(SUM(s.views), 0) as total_views,
                COALESCE(SUM(s.downloads), 0) as total_downloads,
                COALESCE(SUM(s.reactions), 0) as total_reactions,
                COALESCE(SUM(s.comments), 0) as total_comments,
                COALESCE(SUM(s.shares), 0) as total_shares
             FROM {$wpdb->prefix}mvs_media_index i
             LEFT JOIN {$wpdb->prefix}mvs_media_stats s ON s.media_id = i.media_id
             WHERE i.post_author = %d AND i.status = 'publish'",
            $user_id
        ),
        ARRAY_A
    );

    return $row ?: array(
        'total_media'     => 0,
        'total_views'     => 0,
        'total_downloads' => 0,
        'total_reactions' => 0,
        'total_comments'  => 0,
        'total_shares'    => 0,
    );
}

/**
 * Initialize stats row for new media (all zeros).
 *
 * @param int $media_id Media ID.
 * @return bool True on success.
 */
public static function init_stats( int $media_id ): bool {
    global $wpdb;

    return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prefix . 'mvs_media_stats',
        array(
            'media_id'  => $media_id,
            'views'     => 0,
            'downloads' => 0,
            'reactions' => 0,
            'comments'  => 0,
            'shares'    => 0,
        ),
        array( '%d', '%d', '%d', '%d', '%d', '%d' )
    );
}

/**
 * Increment a stat counter.
 *
 * @param int    $media_id Media ID.
 * @param string $column   Column name (views, downloads, reactions, comments, shares).
 * @return bool True on success.
 */
public static function increment_stat( int $media_id, string $column ): bool {
    global $wpdb;

    $allowed = array( 'views', 'downloads', 'reactions', 'comments', 'shares' );
    if ( ! in_array( $column, $allowed, true ) ) {
        return false;
    }

    return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}mvs_media_stats SET `{$column}` = `{$column}` + 1, updated_at = %s WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            current_time( 'mysql', true ),
            $media_id
        )
    );
}

/**
 * Set a stat counter to an exact value.
 *
 * @param int    $media_id Media ID.
 * @param string $column   Column name.
 * @param int    $value    New value.
 * @return bool True on success.
 */
public static function set_stat( int $media_id, string $column, int $value ): bool {
    global $wpdb;

    $allowed = array( 'views', 'downloads', 'reactions', 'comments', 'shares' );
    if ( ! in_array( $column, $allowed, true ) ) {
        return false;
    }

    return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prefix . 'mvs_media_stats',
        array(
            $column      => $value,
            'updated_at' => current_time( 'mysql', true ),
        ),
        array( 'media_id' => $media_id ),
        array( '%d', '%s' ),
        array( '%d' )
    );
}

/**
 * Record a view or download event.
 *
 * @param int    $media_id   Media ID.
 * @param int    $user_id    User ID (0 for anonymous).
 * @param string $ip_hash    Hashed IP address.
 * @param string $event_type 'view' or 'download'.
 */
public static function record_event( int $media_id, int $user_id, string $ip_hash, string $event_type = 'view' ): void {
    global $wpdb;

    $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prefix . 'mvs_media_views',
        array(
            'media_id'   => $media_id,
            'user_id'    => $user_id,
            'ip_hash'    => $ip_hash,
            'event_type' => $event_type,
            'created_at' => current_time( 'mysql', true ),
        ),
        array( '%d', '%d', '%s', '%s', '%s' )
    );
}

/**
 * Prune old view/download events.
 *
 * @param int $days_old Delete events older than this many days (default 90).
 * @return int Number of deleted rows.
 */
public static function prune_events( int $days_old = 90 ): int {
    global $wpdb;

    return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mvs_media_views WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        )
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/Repository/MediaRepository.php
git commit -m "feat(repo): add stats methods — get_stats, user_stats, increment, events, prune"
```

---

### Task 5: Add Lifecycle Methods

**Files:**
- Modify: `wpmediaverse/includes/Repository/MediaRepository.php`

- [ ] **Step 1: Add trash, restore, and delete_cascade methods**

```php
/**
 * Trash a media item.
 *
 * @param int $media_id Media ID.
 * @return bool True on success.
 */
public static function trash( int $media_id ): bool {
    global $wpdb;

    return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prefix . 'mvs_media_index',
        array(
            'status'     => 'trash',
            'updated_at' => current_time( 'mysql', true ),
        ),
        array( 'media_id' => $media_id )
    );
}

/**
 * Restore a trashed media item.
 *
 * @param int $media_id Media ID.
 * @return bool True on success.
 */
public static function restore( int $media_id ): bool {
    global $wpdb;

    return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prefix . 'mvs_media_index',
        array(
            'status'     => 'publish',
            'updated_at' => current_time( 'mysql', true ),
        ),
        array( 'media_id' => $media_id )
    );
}

/**
 * Full cascade delete: index + meta + stats + views.
 *
 * @param int $media_id Media ID.
 * @return bool True on success.
 */
public static function delete_cascade( int $media_id ): bool {
    global $wpdb;

    $wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( $wpdb->prefix . 'mvs_media_views', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( $wpdb->prefix . 'mvs_media_meta', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    return true;
}
```

- [ ] **Step 2: Update delete_all to call delete_cascade**

Replace the existing `delete_all` method body to delegate to `delete_cascade`:

```php
public static function delete_all( int $media_id ): void {
    self::delete_cascade( $media_id );
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/Repository/MediaRepository.php
git commit -m "feat(repo): add lifecycle methods — trash, restore, delete_cascade"
```

---

### Task 6: Update Service Container + Delete Old File

**Files:**
- Modify: `wpmediaverse/includes/Core/Plugin.php`
- Delete: `wpmediaverse/includes/Services/MediaMeta.php`

- [ ] **Step 1: Update Plugin.php imports**

At the top of Plugin.php, find:
```php
use WPMediaVerse\Services\MediaMeta;
```
Replace with:
```php
use WPMediaVerse\Repository\MediaRepository;
```

- [ ] **Step 2: Add service container registration**

In the `register_services()` method, add after the last `$container->register()` call:

```php
$container->register(
    'media_repository',
    function () {
        return new MediaRepository();
    }
);
```

- [ ] **Step 3: Update any MediaMeta references in Plugin.php**

Search for `MediaMeta::` in Plugin.php and replace with `MediaRepository::`. Also update any docblock references.

- [ ] **Step 4: Delete the old file**

```bash
rm includes/Services/MediaMeta.php
```

- [ ] **Step 5: Verify autoloading**

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; new WPMediaVerse\Repository\MediaRepository();"
```

- [ ] **Step 6: Commit**

```bash
git add includes/Core/Plugin.php includes/Repository/MediaRepository.php
git rm includes/Services/MediaMeta.php
git commit -m "refactor: register MediaRepository in container, delete old MediaMeta"
```

---

### Task 7: Migrate Free Plugin Callers — Admin Pages

**Files:**
- Modify: `wpmediaverse/includes/Admin/OverviewPage.php`
- Modify: `wpmediaverse/includes/Admin/MediaListPage.php`
- Modify: `wpmediaverse/includes/Admin/StatsPage.php`

For each file:
1. Replace `use WPMediaVerse\Services\MediaMeta;` with `use WPMediaVerse\Repository\MediaRepository;`
2. Replace `MediaMeta::` calls with `MediaRepository::` calls
3. Replace direct `$wpdb` queries touching `mvs_media_index`/`mvs_media_stats` with repository method calls:
   - `SELECT COUNT(*) FROM mvs_media_index WHERE status = 'publish'` → `MediaRepository::count_published()`
   - `SELECT COUNT(*) FROM mvs_media_index WHERE {filters}` → `MediaRepository::count_by_moderation()` or `count_by_author()`
   - Paginated queries → `MediaRepository::query()` (keep inline if query() doesn't cover exact pattern yet)
   - `UPDATE ... SET status = 'trash'` → `MediaRepository::trash($media_id)`
   - `UPDATE ... SET status = 'publish'` → `MediaRepository::restore($media_id)`
   - `DELETE` cascades → `MediaRepository::delete_cascade($media_id)`

- [ ] **Step 1: Migrate OverviewPage.php**

Read the file, find all `$wpdb` queries and `MediaMeta::` calls. Replace with repository methods.

- [ ] **Step 2: Migrate MediaListPage.php**

Read the file, replace trash/restore/delete operations and count queries.

- [ ] **Step 3: Migrate StatsPage.php**

Read the file, replace stats join queries.

- [ ] **Step 4: Verify no syntax errors**

```bash
php -l includes/Admin/OverviewPage.php
php -l includes/Admin/MediaListPage.php
php -l includes/Admin/StatsPage.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/Admin/OverviewPage.php includes/Admin/MediaListPage.php includes/Admin/StatsPage.php
git commit -m "refactor(admin): migrate admin pages to MediaRepository"
```

---

### Task 8: Migrate Free Plugin Callers — Services

**Files:**
- Modify: `wpmediaverse/includes/Services/UploadService.php`
- Modify: `wpmediaverse/includes/Services/StatsService.php`
- Modify: `wpmediaverse/includes/Services/ModerationService.php`
- Modify: `wpmediaverse/includes/Services/CacheService.php`
- Modify: `wpmediaverse/includes/Services/StoryService.php`
- Modify: `wpmediaverse/includes/Services/CollectionService.php`
- Modify: `wpmediaverse/includes/Services/SignedUrlService.php`
- Modify: `wpmediaverse/includes/Services/GDPRService.php`

For each file:
1. Replace `use WPMediaVerse\Services\MediaMeta;` with `use WPMediaVerse\Repository\MediaRepository;`
2. Replace `MediaMeta::` with `MediaRepository::`
3. Replace direct `$wpdb` queries with repository methods:
   - Stats SELECT → `MediaRepository::get_stats()` or `get_user_stats()`
   - Stats INSERT (init) → `MediaRepository::init_stats()`
   - Stats UPDATE (increment) → `MediaRepository::increment_stat()`
   - Views INSERT → `MediaRepository::record_event()`
   - Views DELETE (prune) → `MediaRepository::prune_events()`
   - Moderation counts → `MediaRepository::count_by_moderation()` or `get_moderation_counts()`
   - Stats DELETE → included in `MediaRepository::delete_cascade()`

- [ ] **Step 1: Migrate UploadService.php** — Replace MediaMeta import + stats init
- [ ] **Step 2: Migrate StatsService.php** — Delegate stats queries to repository
- [ ] **Step 3: Migrate ModerationService.php** — Replace moderation count queries
- [ ] **Step 4: Migrate CacheService.php** — Replace stats and moderation queries
- [ ] **Step 5: Migrate StoryService.php** — Replace paginated media queries
- [ ] **Step 6: Migrate CollectionService.php** — Replace collection item queries
- [ ] **Step 7: Migrate SignedUrlService.php** — Replace record_event and increment_stat
- [ ] **Step 8: Migrate GDPRService.php** — Replace delete cascade
- [ ] **Step 9: Verify no syntax errors**

```bash
for f in UploadService StatsService ModerationService CacheService StoryService CollectionService SignedUrlService GDPRService; do
  php -l "includes/Services/${f}.php"
done
```

- [ ] **Step 10: Commit**

```bash
git add includes/Services/*.php
git commit -m "refactor(services): migrate all services to MediaRepository"
```

---

### Task 9: Migrate Free Plugin Callers — REST, Social, Integration, CLI

**Files:**
- Modify: `wpmediaverse/includes/REST/Controller/MediaController.php`
- Modify: `wpmediaverse/includes/REST/Controller/StatsController.php`
- Modify: `wpmediaverse/includes/REST/Controller/BulkController.php`
- Modify: `wpmediaverse/includes/Social/ReactionService.php`
- Modify: `wpmediaverse/includes/Social/CommentService.php`
- Modify: `wpmediaverse/includes/Social/ShareService.php`
- Modify: `wpmediaverse/includes/Integrations/BuddyPressIntegration.php`
- Modify: `wpmediaverse/includes/Core/TemplateLoader.php`
- Modify: `wpmediaverse/includes/Core/TemplateHelpers.php`
- Modify: `wpmediaverse/includes/CLI/Commands.php`

Same pattern as previous tasks:
1. Replace imports
2. Replace `MediaMeta::` with `MediaRepository::`
3. Replace direct `$wpdb` queries with repository methods

Key replacements:
- MediaController slug lookup → `MediaRepository::get_by_slug()`
- BulkController delete → `MediaRepository::delete_cascade()`
- ReactionService stats update → `MediaRepository::set_stat()` (sets exact value, not increment)
- CommentService stats update → `MediaRepository::set_stat()`
- ShareService stats → `MediaRepository::increment_stat()` and `get_stats()`
- BPIntegration user counts �� `MediaRepository::count_by_author()`
- BPIntegration group counts → `MediaRepository::count_by_group()`
- BPIntegration meta lookup → `MediaRepository::find_by_meta()`
- TemplateLoader slug/id lookup �� `MediaRepository::get_by_slug()` or `get_all()`
- CLI counts → `MediaRepository::count_published()`

- [ ] **Step 1: Migrate REST controllers** (MediaController, StatsController, BulkController)
- [ ] **Step 2: Migrate Social services** (ReactionService, CommentService, ShareService)
- [ ] **Step 3: Migrate BuddyPressIntegration** (5 query replacements)
- [ ] **Step 4: Migrate TemplateLoader and TemplateHelpers**
- [ ] **Step 5: Migrate CLI/Commands.php**
- [ ] **Step 6: Verify no syntax errors across all files**
- [ ] **Step 7: Commit**

```bash
git add includes/REST/Controller/*.php includes/Social/*.php includes/Integrations/*.php includes/Core/TemplateLoader.php includes/Core/TemplateHelpers.php includes/CLI/Commands.php
git commit -m "refactor: migrate REST, social, BP, templates, CLI to MediaRepository"
```

---

### Task 10: Migrate Pro Plugin Callers

**Files:**
- All Pro files importing `WPMediaVerse\Services\MediaMeta`

- [ ] **Step 1: Find all Pro files importing MediaMeta**

```bash
grep -rl "WPMediaVerse\\\\Services\\\\MediaMeta" /path/to/wpmediaverse-pro/includes/
```

- [ ] **Step 2: Replace imports in each file**

For every file found:
```php
// OLD:
use WPMediaVerse\Services\MediaMeta;
// NEW:
use WPMediaVerse\Repository\MediaRepository;
```

- [ ] **Step 3: Replace method calls in each file**

Replace all `MediaMeta::` with `MediaRepository::` in each file.

- [ ] **Step 4: Verify no syntax errors**

```bash
find /path/to/wpmediaverse-pro/includes/ -name "*.php" -exec php -l {} \;
```

- [ ] **Step 5: Commit in Pro plugin**

```bash
cd /path/to/wpmediaverse-pro/
git add includes/
git commit -m "refactor: migrate all MediaMeta imports to MediaRepository"
```

---

### Task 11: Update Tests

**Files:**
- Rename: `wpmediaverse/tests/unit/MediaMetaTest.php` → `tests/unit/MediaRepositoryTest.php`

- [ ] **Step 1: Rename test file**

```bash
mv tests/unit/MediaMetaTest.php tests/unit/MediaRepositoryTest.php
```

- [ ] **Step 2: Update test class**

In the test file:
1. Replace `use WPMediaVerse\Services\MediaMeta;` with `use WPMediaVerse\Repository\MediaRepository;`
2. Replace class name `MediaMetaTest` with `MediaRepositoryTest`
3. Replace all `MediaMeta::` with `MediaRepository::`

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/phpunit tests/unit/MediaRepositoryTest.php
```
Expected: All existing tests pass.

- [ ] **Step 4: Run full test suite**

```bash
./vendor/bin/phpunit
```
Expected: All tests pass, no regressions.

- [ ] **Step 5: Commit**

```bash
git add tests/unit/MediaRepositoryTest.php
git rm tests/unit/MediaMetaTest.php
git commit -m "test: rename MediaMetaTest to MediaRepositoryTest"
```

---

### Task 12: Final Verification + CLAUDE.md Update

**Files:**
- Modify: `wpmediaverse/CLAUDE.md`

- [ ] **Step 1: Verify no remaining MediaMeta references in Free plugin**

```bash
grep -r "MediaMeta" includes/ --include="*.php"
```
Expected: Zero results (all migrated).

- [ ] **Step 2: Verify no remaining MediaMeta references in Pro plugin**

```bash
grep -r "MediaMeta" /path/to/wpmediaverse-pro/includes/ --include="*.php"
```
Expected: Zero results.

- [ ] **Step 3: Run phpcs**

```bash
composer run phpcs
```

- [ ] **Step 4: Run phpstan**

```bash
composer run phpstan
```

- [ ] **Step 5: Update CLAUDE.md**

In the Module Map table, add:
```
| `Repository\` | Central data access layer | `MediaRepository` |
```

In the Service Container Keys table, add:
```
| `media_repository` | MediaRepository | Plugin.php:XXX |
```

Remove `MediaMeta` from the Services row.

Update the Known Debt table to note that MediaRepository extraction is DONE.

- [ ] **Step 6: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md — MediaRepository extraction complete"
```
