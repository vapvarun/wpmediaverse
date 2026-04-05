# BuddyPress Integration Split — Design Spec

**Date:** 2026-04-05
**Status:** Design Approved
**Scope:** `wpmediaverse` (Free plugin only)

---

## Context

`includes/Integrations/BuddyPressIntegration.php` is 2,811 lines handling 6 distinct responsibilities: activity syncing, activity content transformation, profile tabs, group tabs, notifications, and activity form integration. It has 48 methods and 23 hooks registered in a single `init()`.

**Goal:** Split into 7 focused classes (1 orchestrator + 6 sub-integrations) under `includes/Integrations/BuddyPress/`, each under 500 lines with a single responsibility.

---

## New File Structure

```
includes/Integrations/BuddyPress/
├── BuddyPressManager.php          (~80 lines)  — Orchestrator
├── ActivitySyncIntegration.php     (~380 lines) — Records BP activities
├── ActivityContentIntegration.php  (~520 lines) — Transforms legacy activity HTML
├── ProfileTabIntegration.php       (~340 lines) — User profile media tab
├── GroupTabIntegration.php         (~630 lines) — Group media tab
├── NotificationIntegration.php     (~150 lines) — BP notifications
├── ActivityFormIntegration.php     (~220 lines) — Activity post form media upload
└── MediaDisplayHelper.php          (~130 lines) — Shared thumbnail/label helpers
```

Delete: `includes/Integrations/BuddyPressIntegration.php`

---

## Class Responsibilities

### BuddyPressManager
- Namespace: `WPMediaVerse\Integrations\BuddyPress`
- Replaces `BuddyPressIntegration` as service container entry
- `init()` checks BP component availability, instantiates sub-integrations
- No business logic — purely orchestration

### ActivitySyncIntegration
- Owns: `$recorded_uploads`, `$upload_in_progress`, `$posting_to_activity`
- 11 methods: register_activity_actions, format_activity_action_upload/comment, mark_upload_in_progress, flag_activity_upload, record_upload_activity, maybe_record_publish_activity, reassign_activity_to_group, update_activity_with_album, sync_media_comment_to_activity, find_media_upload_activity
- 7 hooks: mvs_before_media_insert, mvs_media_uploaded (x2), mvs_comment_created, mvs_album_items_added, mvs_media_group_assigned, bp_register_activity_actions

### ActivityContentIntegration
- Stateless (no properties)
- 11 methods: enhance_activity_media_content, inject_video_player_in_activity, render_activity_media_thumbnail, resolve_imported_thumbnail, transform_rtmedia_content, find_media_by_meta_key, allow_mvs_activity_tags, get_mvs_id_from_file_url, transform_legacy_media_content, transform_mediapress_activity, inject_imported_media_thumbnail
- 4 hooks: bp_get_activity_content_body (x2), bp_activity_entry_content, bp_activity_allowed_tags

### ProfileTabIntegration
- Stateless
- 7 methods: add_profile_tab, update_media_tab_count, render_profile_media_tab, render_profile_albums_tab, profile_media_content, profile_albums_content, profile_single_album_content
- 2 hooks: bp_setup_nav, bp_template_redirect

### GroupTabIntegration
- Stateless
- 6 methods: add_group_tab, render_group_media_tab, render_group_sub_tabs, group_media_content, group_albums_content, group_single_album_content
- 2 hooks: bp_setup_nav

### NotificationIntegration
- Stateless
- 6 methods: notify_reaction, notify_comment, notify_mentions, register_notification_component, register_notification_filters, format_notifications
- 5 hooks: mvs_reaction_added, mvs_comment_created, bp_notifications_get_registered_components, bp_notifications_get_notifications_for_user, bp_nouveau_notifications_init_filters

### ActivityFormIntegration
- Stateless
- 5 methods: activity_post_media_button, enqueue_activity_media_scripts, attach_media_to_activity, attach_media_to_group_activity, get_media_thumbnail_html (if not shared)
- 4 hooks: bp_activity_post_form_options, bp_enqueue_scripts, bp_activity_posted_update, bp_groups_posted_update

### MediaDisplayHelper
- Static utility class (no hooks, no init)
- 2 methods: get_media_thumbnail_html(), get_media_type_label()
- Used by: ActivitySyncIntegration, ActivityFormIntegration, ProfileTabIntegration, GroupTabIntegration

---

## Service Container Update

```php
// In Plugin::register_services()
$container->register('integration.buddypress', function() {
    return new \WPMediaVerse\Integrations\BuddyPress\BuddyPressManager();
});
```

---

## Migration Strategy

1. Create `includes/Integrations/BuddyPress/` directory
2. Create `MediaDisplayHelper.php` first (shared, no dependencies)
3. Create each sub-integration one at a time, moving methods from the original
4. Create `BuddyPressManager.php` as the orchestrator
5. Update `Plugin.php` import and service container registration
6. Delete `BuddyPressIntegration.php`
7. Verify all hooks still fire in the same order

---

## What Does NOT Change

- All hook names and signatures (mvs_* hooks, bp_* hooks)
- All BP activity types (mvs_media_upload, mvs_media_comment)
- All notification formats and component name ('wpmediaverse')
- All tab URLs and slugs (/media/, /albums/)
- All REST endpoint URLs used by JS
- Database queries (same SQL, just in different files)
- Pro plugin (no changes needed — Pro doesn't import BuddyPressIntegration)

---

## Verification Plan

1. `grep -r "BuddyPressIntegration" includes/` — zero results after migration
2. `php -l` on all new files
3. Browser test: Upload media → check BP activity appears
4. Browser test: Visit user profile → media tab shows with count
5. Browser test: Visit group → media tab shows
6. Browser test: React to media → BP notification appears
7. Browser test: Post activity with media attachment → media displays
8. Run full test suite: `./vendor/bin/phpunit`
