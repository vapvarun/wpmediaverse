# BuddyPress Integration Split — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 2,811-line `BuddyPressIntegration.php` into 7 focused classes under `includes/Integrations/BuddyPress/`, each with a single responsibility and under 630 lines.

**Architecture:** Create a `BuddyPressManager` orchestrator that conditionally loads 6 sub-integrations based on active BP components. Shared helpers go in `MediaDisplayHelper`. The original file is deleted after all methods are extracted.

**Tech Stack:** PHP 8.1+, BuddyPress API, WordPress hooks

**Spec:** `docs/superpowers/specs/2026-04-05-bp-integration-split-design.md`

---

## File Structure

### Create (7 files)
- `includes/Integrations/BuddyPress/MediaDisplayHelper.php` — Shared static helpers
- `includes/Integrations/BuddyPress/NotificationIntegration.php` — BP notifications
- `includes/Integrations/BuddyPress/ActivityContentIntegration.php` — Legacy activity transforms
- `includes/Integrations/BuddyPress/ActivitySyncIntegration.php` — Activity recording
- `includes/Integrations/BuddyPress/ActivityFormIntegration.php` — Activity post form
- `includes/Integrations/BuddyPress/ProfileTabIntegration.php` — User profile media tab
- `includes/Integrations/BuddyPress/GroupTabIntegration.php` — Group media tab
- `includes/Integrations/BuddyPress/BuddyPressManager.php` — Orchestrator

### Delete
- `includes/Integrations/BuddyPressIntegration.php`

### Modify
- `includes/Core/Plugin.php` — Update import and service container registration

---

## Execution Strategy

Each task creates one new file by extracting methods from `BuddyPressIntegration.php`. The original file stays intact until all extractions are done (Task 8 deletes it). This means during tasks 1-7, both old and new files exist — the new files are created but not yet wired up. Task 8 wires everything and deletes the original.

**CRITICAL:** Every task must read the CURRENT `BuddyPressIntegration.php` to copy the EXACT method code. Do NOT write methods from memory — read them from the source file and copy precisely, only changing the class/namespace.

---

### Task 1: Create MediaDisplayHelper (shared utilities)

**Files:**
- Create: `includes/Integrations/BuddyPress/MediaDisplayHelper.php`

- [ ] **Step 1: Read source methods**

Read `includes/Integrations/BuddyPressIntegration.php` and find these two methods:
- `get_media_type_label()` (~lines 2216-2230)
- `get_media_thumbnail_html()` (~lines 2001-2053)

- [ ] **Step 2: Create MediaDisplayHelper.php**

Create the file with namespace `WPMediaVerse\Integrations\BuddyPress`. Class `MediaDisplayHelper` with both methods as `public static`. Copy the exact method bodies from the source. Update any `$this->` references to `self::` if they call each other.

- [ ] **Step 3: PHP lint**

```bash
php -l includes/Integrations/BuddyPress/MediaDisplayHelper.php
```

- [ ] **Step 4: Commit**

```bash
git add includes/Integrations/BuddyPress/MediaDisplayHelper.php
git commit -m "refactor(bp): extract MediaDisplayHelper with shared thumbnail/label utilities"
```

---

### Task 2: Create NotificationIntegration (simplest, 6 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/NotificationIntegration.php`

- [ ] **Step 1: Read source methods**

Read `BuddyPressIntegration.php` and find these 6 methods:
- `notify_reaction()` (~lines 1815-1834)
- `notify_comment()` (~lines 1845-1865)
- `notify_mentions()` (~lines 1873-1895)
- `register_notification_component()` (~lines 1903-1906)
- `register_notification_filters()` (~lines 1911-1939)
- `format_notifications()` (~lines 1954-1987)

- [ ] **Step 2: Create NotificationIntegration.php**

Namespace: `WPMediaVerse\Integrations\BuddyPress`

Class with `init()` method registering these 5 hooks:
```php
add_action( 'mvs_reaction_added', array( $this, 'notify_reaction' ), 10, 3 );
add_action( 'mvs_comment_created', array( $this, 'notify_comment' ), 10, 3 );
add_action( 'mvs_mentions_created', array( $this, 'notify_mentions' ), 10, 2 );
add_filter( 'bp_notifications_get_registered_components', array( $this, 'register_notification_component' ) );
add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format_notifications' ), 10, 8 );
add_action( 'bp_nouveau_notifications_init_filters', array( $this, 'register_notification_filters' ) );
```

Copy all 6 method bodies exactly from source. Replace any `MediaMeta::` with `MediaRepository::` (should already be done from previous refactor). Add `use WPMediaVerse\Repository\MediaRepository;` import.

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/NotificationIntegration.php
git add includes/Integrations/BuddyPress/NotificationIntegration.php
git commit -m "refactor(bp): extract NotificationIntegration — 6 methods, 5 hooks"
```

---

### Task 3: Create ActivityContentIntegration (stateless, 11 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/ActivityContentIntegration.php`

- [ ] **Step 1: Read source methods**

Read `BuddyPressIntegration.php` and find these methods:
- `enhance_activity_media_content()` (~lines 2360-2428)
- `inject_video_player_in_activity()` (~lines 2307-2346)
- `render_activity_media_thumbnail()` (~lines 2636-2675)
- `resolve_imported_thumbnail()` (~lines 2440-2456)
- `transform_rtmedia_content()` (~lines 2505-2619)
- `find_media_by_meta_key()` (~lines 2468-2494)
- `allow_mvs_activity_tags()` (~lines 2240-2295)
- `get_mvs_id_from_file_url()` (~lines 2797-2810)
- `transform_legacy_media_content()` (~line 2683, deprecated wrapper)
- `transform_mediapress_activity()` (~line 2690, deprecated wrapper)
- `inject_imported_media_thumbnail()` (~line 2743, deprecated wrapper)

- [ ] **Step 2: Create ActivityContentIntegration.php**

`init()` registers 4 hooks:
```php
add_filter( 'bp_get_activity_content_body', array( $this, 'enhance_activity_media_content' ), 0, 2 );
add_action( 'bp_activity_entry_content', array( $this, 'render_activity_media_thumbnail' ) );
add_filter( 'bp_get_activity_content_body', array( $this, 'inject_video_player_in_activity' ), 0, 2 );
add_filter( 'bp_activity_allowed_tags', array( $this, 'allow_mvs_activity_tags' ) );
```

Copy all 11 method bodies. Any calls to `$this->get_media_thumbnail_html()` or `$this->get_media_type_label()` become `MediaDisplayHelper::get_media_thumbnail_html()` / `MediaDisplayHelper::get_media_type_label()`.

Import: `use WPMediaVerse\Repository\MediaRepository;`

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/ActivityContentIntegration.php
git add includes/Integrations/BuddyPress/ActivityContentIntegration.php
git commit -m "refactor(bp): extract ActivityContentIntegration — 11 methods, 4 hooks"
```

---

### Task 4: Create ActivitySyncIntegration (stateful, 11 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/ActivitySyncIntegration.php`

- [ ] **Step 1: Read source methods**

Read `BuddyPressIntegration.php` and find:
- Properties: `$recorded_uploads` (line ~30), `$upload_in_progress` (line ~39), `$posting_to_activity` (static, line ~421)
- Methods:
  - `register_activity_actions()` (~lines 107-137)
  - `format_activity_action_upload()` (~lines 146-204)
  - `format_activity_action_comment()` (~lines 213-229)
  - `mark_upload_in_progress()` (~lines 237-239)
  - `flag_activity_upload()` (~lines 247-256)
  - `record_upload_activity()` (~lines 266-323)
  - `maybe_record_publish_activity()` (~lines 336-338)
  - `reassign_activity_to_group()` (~lines 351-377)
  - `update_activity_with_album()` (~lines 390-411)
  - `sync_media_comment_to_activity()` (~lines 438-476)
  - `find_media_upload_activity()` (~lines 484-534)

- [ ] **Step 2: Create ActivitySyncIntegration.php**

Include all 3 properties. `init()` registers 7 hooks:
```php
add_action( 'mvs_before_media_insert', array( $this, 'mark_upload_in_progress' ) );
add_action( 'mvs_media_uploaded', array( $this, 'flag_activity_upload' ), 5 );
add_action( 'mvs_media_uploaded', array( $this, 'record_upload_activity' ) );
add_action( 'mvs_comment_created', array( $this, 'sync_media_comment_to_activity' ), 10, 5 );
add_action( 'mvs_album_items_added', array( $this, 'update_activity_with_album' ), 10, 3 );
add_action( 'mvs_media_group_assigned', array( $this, 'reassign_activity_to_group' ), 10, 2 );
add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );
```

Replace `$this->get_media_thumbnail_html()` calls with `MediaDisplayHelper::get_media_thumbnail_html()`. Replace `$this->get_media_type_label()` with `MediaDisplayHelper::get_media_type_label()`.

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/ActivitySyncIntegration.php
git add includes/Integrations/BuddyPress/ActivitySyncIntegration.php
git commit -m "refactor(bp): extract ActivitySyncIntegration — 11 methods, 7 hooks, state management"
```

---

### Task 5: Create ActivityFormIntegration (5 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/ActivityFormIntegration.php`

- [ ] **Step 1: Read source methods**

- `activity_post_media_button()` (~lines 2058-2072)
- `enqueue_activity_media_scripts()` (~lines 2077-2115)
- `attach_media_to_activity()` (~lines 2124-2182)
- `attach_media_to_group_activity()` (~lines 2195-2208)

- [ ] **Step 2: Create ActivityFormIntegration.php**

`init()` registers 4 hooks:
```php
add_action( 'bp_activity_post_form_options', array( $this, 'activity_post_media_button' ) );
add_action( 'bp_enqueue_scripts', array( $this, 'enqueue_activity_media_scripts' ) );
add_action( 'bp_activity_posted_update', array( $this, 'attach_media_to_activity' ), 10, 3 );
add_action( 'bp_groups_posted_update', array( $this, 'attach_media_to_group_activity' ), 10, 4 );
```

Replace `$this->get_media_thumbnail_html()` with `MediaDisplayHelper::get_media_thumbnail_html()`.

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/ActivityFormIntegration.php
git add includes/Integrations/BuddyPress/ActivityFormIntegration.php
git commit -m "refactor(bp): extract ActivityFormIntegration — 4 methods, 4 hooks"
```

---

### Task 6: Create ProfileTabIntegration (7 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/ProfileTabIntegration.php`

- [ ] **Step 1: Read source methods**

- `add_profile_tab()` (~lines 539-586)
- `update_media_tab_count()` (~lines 591-617)
- `render_profile_media_tab()` (~lines 622-625)
- `render_profile_albums_tab()` (~lines 631-639)
- `profile_media_content()` (~lines 644-863)
- `profile_albums_content()` (~lines 864-972)
- `profile_single_album_content()` (~lines 973-1179)

- [ ] **Step 2: Create ProfileTabIntegration.php**

`init()` registers hooks:
```php
add_action( 'bp_setup_nav', array( $this, 'add_profile_tab' ), 100 );
add_action( 'bp_template_redirect', array( $this, 'update_media_tab_count' ) );
```

Replace helper calls with `MediaDisplayHelper::` static calls. Copy all method bodies exactly.

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/ProfileTabIntegration.php
git add includes/Integrations/BuddyPress/ProfileTabIntegration.php
git commit -m "refactor(bp): extract ProfileTabIntegration — 7 methods, profile media tab"
```

---

### Task 7: Create GroupTabIntegration (6 methods)

**Files:**
- Create: `includes/Integrations/BuddyPress/GroupTabIntegration.php`

- [ ] **Step 1: Read source methods**

- `add_group_tab()` (~lines 1183-1207)
- `render_group_media_tab()` (~lines 1212-1227)
- `render_group_sub_tabs()` (~lines 1234-1248)
- `group_media_content()` (~lines 1253-1468)
- `group_albums_content()` (~lines 1473-1588)
- `group_single_album_content()` (~lines 1589-1807)

- [ ] **Step 2: Create GroupTabIntegration.php**

`init()` registers:
```php
add_action( 'bp_setup_nav', array( $this, 'add_group_tab' ), 100 );
```

Replace helper calls with `MediaDisplayHelper::` static calls.

- [ ] **Step 3: PHP lint + commit**

```bash
php -l includes/Integrations/BuddyPress/GroupTabIntegration.php
git add includes/Integrations/BuddyPress/GroupTabIntegration.php
git commit -m "refactor(bp): extract GroupTabIntegration — 6 methods, group media tab"
```

---

### Task 8: Create BuddyPressManager + Wire Up + Delete Original

**Files:**
- Create: `includes/Integrations/BuddyPress/BuddyPressManager.php`
- Modify: `includes/Core/Plugin.php`
- Delete: `includes/Integrations/BuddyPressIntegration.php`

- [ ] **Step 1: Create BuddyPressManager.php**

```php
<?php
namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

class BuddyPressManager {

    public function init(): void {
        if ( ! function_exists( 'buddypress' ) ) {
            return;
        }

        if ( bp_is_active( 'activity' ) ) {
            ( new ActivitySyncIntegration() )->init();
            ( new ActivityContentIntegration() )->init();
            ( new ActivityFormIntegration() )->init();
        }

        if ( bp_is_active( 'notifications' ) ) {
            ( new NotificationIntegration() )->init();
        }

        // Profile tab is always available (core BP feature).
        ( new ProfileTabIntegration() )->init();

        if ( bp_is_active( 'groups' ) ) {
            ( new GroupTabIntegration() )->init();
        }
    }
}
```

- [ ] **Step 2: Update Plugin.php**

In `includes/Core/Plugin.php`:
1. Replace `use WPMediaVerse\Integrations\BuddyPressIntegration;` with `use WPMediaVerse\Integrations\BuddyPress\BuddyPressManager;`
2. In the service container registration, replace `BuddyPressIntegration` with `BuddyPressManager`
3. Where `init()` is called on the BP integration service, ensure it calls `->init()` on the new manager

- [ ] **Step 3: Delete original file**

```bash
git rm includes/Integrations/BuddyPressIntegration.php
```

- [ ] **Step 4: Run composer dump-autoload**

```bash
composer dump-autoload
```

- [ ] **Step 5: PHP lint all new files**

```bash
for f in includes/Integrations/BuddyPress/*.php; do php -l "$f"; done
php -l includes/Core/Plugin.php
```

- [ ] **Step 6: Verify no BuddyPressIntegration references remain**

```bash
grep -r "BuddyPressIntegration" includes/ --include="*.php"
```
Expected: zero results (except possibly comments referencing the old class for historical context).

- [ ] **Step 7: Commit**

```bash
git add includes/Integrations/BuddyPress/BuddyPressManager.php includes/Core/Plugin.php
git rm includes/Integrations/BuddyPressIntegration.php
git commit -m "refactor(bp): create BuddyPressManager orchestrator, delete original 2,811-line file"
```

---

### Task 9: Update CLAUDE.md + Final Verification

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update CLAUDE.md**

In the Module Map table, update the `Integrations\` row:
- Old: `BuddyPressIntegration, WebhookService`
- New: `BuddyPress\BuddyPressManager (+ 6 sub-integrations + MediaDisplayHelper), WebhookService`

In the Known Debt table, update the BuddyPressIntegration row:
- Old: `2,811 | God class — needs split into sub-integrations`
- New: `DONE | Split into 7 classes under Integrations/BuddyPress/`

- [ ] **Step 2: Run full test suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 3: Verify line counts**

```bash
wc -l includes/Integrations/BuddyPress/*.php
```
Expected: each file under 630 lines, total ~2,450 lines (some reduction from deduplication of shared helpers).

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md — BuddyPressIntegration split complete"
```
