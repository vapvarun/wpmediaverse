# WPMediaVerse (Free) — Master Roadmap

> Single source of truth. Updated: 2026-03-29
> Architecture: Custom tables ONLY. No wp_postmeta. CPT for permalinks/admin only.

---

## BLOCKER: Postmeta → Custom Table Migration

**173 postmeta calls across 29 files** must be moved to `mvs_media_index`.

### Step 1: Expand mvs_media_index schema

Current columns: media_id, post_author, media_type, privacy, moderation_status, created_at

Add columns for ALL 21 postmeta keys:
```sql
ALTER TABLE mvs_media_index ADD COLUMN
    attachment_id bigint unsigned DEFAULT NULL,
    file_url varchar(500) DEFAULT '',
    file_path varchar(500) DEFAULT '',
    file_type varchar(50) DEFAULT '',
    file_size bigint unsigned DEFAULT 0,
    file_hash varchar(64) DEFAULT '',
    width int unsigned DEFAULT NULL,
    height int unsigned DEFAULT NULL,
    exif_raw text DEFAULT NULL,
    album_id bigint unsigned DEFAULT NULL,
    album_type varchar(20) DEFAULT '',
    group_id bigint unsigned DEFAULT NULL,
    group_position int unsigned DEFAULT 0,
    media_group varchar(50) DEFAULT '',
    group_cover tinyint(1) DEFAULT 0,
    bp_activity_id bigint unsigned DEFAULT NULL,
    ai_status varchar(20) DEFAULT '',
    ai_description text DEFAULT NULL,
    ai_tags text DEFAULT NULL,
    ai_confidence float DEFAULT NULL,
    ai_moderation text DEFAULT NULL,
    is_story tinyint(1) DEFAULT 0,
    story_expires_at datetime DEFAULT NULL
```

Collection-specific meta (_mvs_collection_rules, _mvs_collection_type) stays on mvs_collection CPT since collections are a different post type.

### Step 2: Create MediaMeta helper class

```php
class MediaMeta {
    public static function get( int $media_id, string $key );
    public static function set( int $media_id, string $key, $value );
    public static function delete( int $media_id, string $key );
    public static function get_all( int $media_id ): array;
}
```

This reads/writes from `mvs_media_index` instead of `wp_postmeta`.

### Step 3: Replace all 173 calls

Search-and-replace across 29 files:
- `get_post_meta( $id, '_mvs_file_url', true )` → `MediaMeta::get( $id, 'file_url' )`
- `update_post_meta( $id, '_mvs_file_url', $val )` → `MediaMeta::set( $id, 'file_url', $val )`
- `delete_post_meta( $id, '_mvs_file_url' )` → `MediaMeta::delete( $id, 'file_url' )`

### Step 4: Migrate existing data

One-time script: read all `_mvs_*` postmeta, write to mvs_media_index columns, then delete from wp_postmeta.

### Step 5: Restore mvs_error_log table

LoggerService, LogViewerPage, HealthCheckService depend on it. Re-add to free plugin Migrator.

---

## DONE (this session)

- [x] Settings page Jetonomy card layout
- [x] All 45 admin UX audit issues fixed
- [x] Menu cleaned to 7 items
- [x] Capabilities fixed (edit/trash actions)
- [x] Dead tables dropped (21 → 26 clean tables)
- [x] Unified competition schema created
- [x] Plans consolidated
- [x] Architecture decision: custom tables only, no postmeta

---

## TODO: After Postmeta Migration

- [ ] DM integration (move from Pro to Free)
- [ ] Gamification hooks
- [ ] Admin page pagination
- [ ] Pre-release checklist
