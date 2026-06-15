# WPMediaVerse Free — Flow & Data Audit
**Date:** 2026-06-15  
**Branch:** 1.7.0-dev  
**Audit scope:** All 21 custom tables, all flows, Rule 18 compliance, 1.7.0 delta coverage

---

## 1. Data-Store Matrix (Rule 18 — Three Entry Points)

| # | Table | Frontend Entry Point | Admin Entry Point | REST Entry Point | Verdict |
|---|-------|---------------------|-------------------|-----------------|---------|
| 1 | `mvs_media_index` | `templates/explore.php`, `templates/media-single.php`, `templates/album.php`, `templates/collection.php`, all grid blocks | `Admin/MediaListPage.php` (All Media) | `GET/POST/PUT/DELETE /media`, `GET /media/{id}` | **3/3 WIRED** |
| 2 | `mvs_media_meta` | `TemplateHelpers::get_thumb_url()`, `media_thumbnail()`, `render_grid_item()` | `Admin/MediaListPage.php` (Details row action) | `GET /media/{id}` (serialized into response), `PUT /media/{id}` (allow_download, category cache) | **3/3 WIRED** |
| 3 | `mvs_media_views` | `SignedUrlService::serve()` — view inserted on every proxy serve | `Admin/StatsPage.php` reads aggregate counts | `GET /media/{id}/stats` (aggregate), `POST /media/{id}/download` writes a download view row | **3/3 WIRED** — minor note: raw per-user view rows have no admin list; only aggregates are surfaced |
| 4 | `mvs_media_stats` | Stat counters shown on single-media page + dashboard stats tab | `Admin/StatsPage.php` (top media, total views, downloads) | `GET /media/{id}/stats`, `GET /me/stats`, `POST /media/{id}/download` (increments downloads) | **3/3 WIRED** |
| 5 | `mvs_reactions` | Lightbox reaction bar (`src/blocks/media-social/view.js`), feed card reaction counts | `Admin/ModerationQueue.php` — reports show reaction context; no dedicated reaction admin list | `GET /media/{id}/reactions`, `POST /media/{id}/reactions`, `DELETE /media/{id}/reactions` | **3/3 WIRED** — admin coverage is indirect (no dedicated reaction list page) |
| 6 | `mvs_favorites` | Dashboard "Favorites" tab, heart icon state on cards and lightbox | `Admin/OverviewPage.php` shows favorite count in stats | `GET /me/favorites`, `GET/POST/DELETE /media/{id}/favorite` | **3/3 WIRED** |
| 7 | `mvs_follows` | Profile page follower/following counts, follow-toggle button (`assets/js/frontend/profile-actions.js`) | `Admin/OverviewPage.php` shows follow counts | `POST/DELETE /users/{id}/follow`, `GET /users/{id}/followers`, `GET /users/{id}/following` | **3/3 WIRED** |
| 8 | `mvs_mentions` | Mention links auto-rendered in comment text (CommentService); target gets in-app notification | **NO ADMIN ENTRY POINT** — no admin page lists or manages mentions | No dedicated REST mentions endpoint — mentions are written inside CommentService and surfaced only via notification; no `GET /mentions` route | **1/3 WIRED** — RULE 18 VIOLATION. Admin: missing. REST: no direct endpoint. Frontend: read-back is via notification UI only, not a mentions list. |
| 9 | `mvs_activity` | `ActivityController` exposes `GET /feed` and `GET /users/{id}/activity` — the explore "Following" sort is planned but the Explore template does NOT call the activity REST endpoint (it queries `mvs_media_index` directly with the standard args). The activity feed is accessible via REST but no frontend template or block currently renders from it. | **NO ADMIN ENTRY POINT** — no admin page reads `mvs_activity` | `GET /feed`, `GET /users/{id}/activity` exist in `ActivityController` | **1.5/3 WIRED** — REST exists but no frontend template/block renders from `/feed`; no admin view. The data store accumulates writes (via `ActivityService::log()`) but is not visibly surfaced to any user without the Pro explore-feed layout modes. RULE 18 VIOLATION — missing frontend render and admin entry points. |
| 10 | `mvs_notifications` | Dashboard notification bell (`templates/partials/dashboard-content.php`), BP notification bridge | **NO DEDICATED ADMIN PAGE** — Overview shows unread count but no admin notification list | `GET /me/notifications`, `POST /me/notifications/read`, `GET /me/notifications/count` | **2.5/3 WIRED** — missing: admin can't browse/manage the notification queue. Minor gap. |
| 11 | `mvs_reports` | Frontend `[Report]` action on media cards and user profiles | `Admin/ModerationQueue.php` — full moderation queue with approve/reject | `POST /media/{id}/report`, `POST /users/{id}/report`, `GET /moderation`, `GET /moderation/counts`, `POST /moderation/{id}/approve`, `POST /moderation/{id}/reject` | **3/3 WIRED** |
| 12 | `mvs_blocks` | Block-user action on user profiles, blocked user's content hidden | **NO ADMIN ENTRY POINT** — admin cannot view or manage the block list | `POST /users/{id}/block`, `GET /me/blocked` | **2/3 WIRED** — missing admin view of the user block list. Minor operational gap (support use case). |
| 13 | `mvs_access_rules` | `src/blocks/lock-overlay/render.php` reads rules to gate content | `Admin/CollectionMetaBox.php` shows smart-collection rules (note: this is COLLECTION rules, not per-media access rules) — per-media access rules have no admin UI page | `GET /media/{id}/rules`, `POST /media/{id}/rules`, `DELETE /media/{id}/rules/{rule_id}` | **2/3 WIRED** — admin entry point is missing (no admin list page for per-media access rules; `CollectionMetaBox` manages collection smart-rules, not access rules). RULE 18 VIOLATION. |
| 14 | `mvs_access_grants` | `src/blocks/lock-overlay/render.php` checks grants via `AccessRulesService::can_access()` | `Admin/MediaListPage.php` — no dedicated grants column or list; `GDPRService` + `UserDeletionService` clean it up | `POST /media/{id}/grant` (admin can grant tokens) | **2/3 WIRED** — no admin list page for viewing who has been granted access per media. Minor gap. |
| 15 | `mvs_album_items` | `templates/album.php` renders items, album-viewer block | `Admin/MediaListPage.php` shows album membership; Album CPT in wp-admin | `GET /albums/{id}` (includes items), `POST /albums/{id}/items`, `PUT /albums/{id}` (cover/title) | **3/3 WIRED** |
| 16 | `mvs_error_log` | Not surfaced to members (internal only — by design) | `Admin/LogViewerPage.php` — full log viewer with search/filter | No REST endpoint (internal + admin-only) | **INTENTIONAL EXCEPTION** — internal cache table. Admin-only is correct. |
| 17 | `mvs_conversations` | `templates/messages.php`, `assets/js/messaging.js` | **NO ADMIN ENTRY POINT** — admins cannot browse conversations | `GET /me/conversations`, `POST /conversations`, `GET/PATCH/DELETE /conversations/{id}` | **2/3 WIRED** — admin entry point missing. Operational gap for support (moderators cannot review reported DM content). |
| 18 | `mvs_conversation_participants` | Same as conversations — embedded in conversation UI | No admin view | Embedded in conversation REST responses | **2/3 WIRED** — same gap as conversations |
| 19 | `mvs_messages` | `assets/js/messaging.js` renders message threads | **NO ADMIN ENTRY POINT** — moderators cannot view messages | `GET/POST /conversations/{id}/messages`, `PUT/DELETE /conversations/{id}/messages/{msg_id}` | **2/3 WIRED** — missing admin moderation surface for messages. |
| 20 | `mvs_message_reactions` | Emoji reaction buttons on DM messages | No admin view | `POST /conversations/{id}/messages/{msg_id}/reactions` | **2/3 WIRED** — minor; expected for this table type |
| 21 | `mvs_transactions` | **NO FRONTEND SURFACE** — Pro is the intended consumer; Free only creates the table via Migrator | **NO ADMIN ENTRY POINT in Free** — Pro's QuotaPage would manage it | **NO REST ENDPOINT in Free** | **0/3 WIRED** — RULE 18 VIOLATION. This table is created by the Free Migrator but is entirely owned by Pro. It ships as dead weight in Free. Documented in FEATURE_AUDIT.md ("Pro consumes") but not flagged as an intentional exception in the manifest. |

### Summary

| Status | Count | Tables |
|--------|-------|--------|
| **3/3 Fully wired** | 9 | mvs_media_index, mvs_media_meta, mvs_media_views, mvs_media_stats, mvs_reactions, mvs_favorites, mvs_follows, mvs_reports, mvs_album_items |
| **2.5/3 Minor gap** | 4 | mvs_notifications, mvs_blocks, mvs_access_grants, mvs_message_reactions |
| **2/3 Missing one leg** | 4 | mvs_conversations, mvs_conversation_participants, mvs_messages, mvs_access_rules |
| **1.5/3 Missing frontend + admin** | 1 | mvs_activity |
| **1/3 Critical — two legs missing** | 1 | mvs_mentions |
| **0/3 Dead weight in Free** | 1 | mvs_transactions |
| **Intentional exception** | 1 | mvs_error_log |

**Rule 18 violations: 4 confirmed** (mvs_mentions, mvs_activity, mvs_access_rules, mvs_transactions).

---

## 2. Flow Inventory

### Flow 1 — Media Upload (REST)
**Trigger:** user uploads file via block / shortcode / BP form  
**Path:** `POST /mvs/v1/media` → `MediaController::create_item()` → `UploadService::validate()` → `StorageService::resolve_driver()` → `$driver->store()` → `INSERT mvs_media_index` → `generate_thumbs()` (writes `mvs_media_meta`) → `PosterService` (for video) → AI analyze/moderate (optional) → `mvs_media_uploaded` action → `ActivityService::on_upload()` → `INSERT mvs_activity` (public media only; private skipped per 1.7.0 fc31bf0) → `ActivitySyncIntegration` (BP mirror) → `apply_filters('mvs_media_response')` → signed URL response  
**Wiring:** complete. All table writes verified.

### Flow 2 — Media Grid Render (block/template)
**Trigger:** page renders `media-grid` block or `[mvs_gallery]` shortcode  
**Path:** `render.php` / `explore.php` → `MediaRepository::prefetch($ids)` (1.7.0 N+1 fix) + `AccessRulesService::prefetch_active_rules($ids)` → per-row `render_grid_item()` → `TemplateHelpers::media_thumbnail($id, opts)` → `SettingsHelper::get_grid_thumb_size_key()` (1.7.0: now 'medium' default) → signed thumbnail URL  
**1.7.0 changes:** thumbnail size routes through configured setting; empty video poster_url falls back to bundled SVG.

### Flow 3 — Signed-URL Serve
**Trigger:** browser requests `/wp-json/mvs/v1/serve?...`  
**Path:** `SignedUrlController::serve_file()` → HMAC-SHA256 validate → expiry check → `AccessRulesService::can_access()` → watermark preview if gated + no permission → `$driver->get_full_path()` → `readfile()` with cache headers  
**1.7.0 changes:** `resolve_expiry()` gives PUBLIC media a render-stable monthly-bucketed expiry (cacheable); `emit_cache_headers()` sends `Cache-Control: public, max-age=604800` for public vs `no-store` for private.

### Flow 4 — Media Update (categories, privacy, title)
**Trigger:** `PUT /mvs/v1/media/{id}` from lightbox Edit modal or REST client  
**Path:** `MediaController::update_item()` → permission check → sanitize → `MediaRepository::update()` (title/desc/privacy/slug) → if categories: `wp_set_object_terms()` → derive names from submitted IDs (1.7.0 bug fix) → `MediaRepository::set('category', json)` → if tags: `wp_set_object_terms()` → response via `mvs_media_response` filter  
**1.7.0 change:** categories no longer silently dropped on persistent-cache miss; route `args` now declare all editable fields.

### Flow 5 — Reaction Toggle
**Trigger:** user clicks reaction emoji in lightbox or media card  
**Path:** `POST /mvs/v1/media/{id}/reactions` → `ReactionController` → `ReactionService::toggle()` → upsert `mvs_reactions` → fire `mvs_reaction_toggled` → `NotificationService::create()` for media owner → return updated counts  
**Wiring:** complete.

### Flow 6 — Comment Create + Mention
**Trigger:** user submits comment form  
**Path:** `POST /mvs/v1/media/{id}/comments` → `CommentController` → `CommentService::create()` → `wp_insert_comment()` → parse `@mention` → `MentionService::process()` → `INSERT mvs_mentions` → `do_action('mvs_mentions_created')` → `NotificationService::on_mentions()` → `INSERT mvs_notifications` per mentioned user  
**Gap:** `mvs_mentions` has no admin viewer and no REST endpoint to list a user's mentions. The table accumulates data but is only readable as notifications. RULE 18 VIOLATION.

### Flow 7 — Favorite Toggle
**Trigger:** user clicks heart icon  
**Path:** `POST /mvs/v1/media/{id}/favorite` → `FavoriteController` → `FavoriteService::toggle()` → upsert `mvs_favorites` → `NotificationService` for owner → response  
**Wiring:** complete; Dashboard Favorites tab reads via `GET /me/favorites`.

### Flow 8 — Follow/Unfollow
**Trigger:** follow button on user profile  
**Path:** `POST /users/{id}/follow` → `FollowController` → `FollowService::follow()` → `INSERT mvs_follows` → `NotificationService` for followee → response  
**Read:** profile page shows counts + `GET /users/{id}/followers` / `/following`.  
**Wiring:** complete.

### Flow 9 — Notification Create + Read
**Trigger:** any social action (reaction, comment, mention, follow, message)  
**Path:** relevant service → `NotificationService::create()` → `INSERT mvs_notifications` → `do_action('mvs_notification_created', $id, $user_id, $type, $actor_id, $media_id, $message, $link)` (1.7.0: appends $message + $link from `build_message_and_link()`) → read back via `GET /me/notifications` → REST formats via same `build_message_and_link()` so wording never drifts from the hook  
**Wiring:** complete; BP bridge via `NotificationIntegration`.

### Flow 10 — Report + Moderation
**Trigger:** user clicks Report on media or user  
**Path:** `POST /media/{id}/report` → `ReportService::submit()` → `INSERT mvs_reports` → if auto-hide threshold reached: privacy change → `mvs_media_privacy_changed` action  
**Admin:** `ModerationQueue` reads and allows approve/reject via `POST /moderation/{id}/approve`.  
**Wiring:** complete.

### Flow 11 — Block User
**Trigger:** Block action on user profile  
**Path:** `POST /users/{id}/block` → `ReportController` → `ReportService::block()` → `INSERT mvs_blocks` → blocked user's content filtered from feeds via FollowService + MediaController  
**Gap:** no admin view of block list; no unblock path via admin UI. `GET /me/blocked` is the only read path.

### Flow 12 — DM / Messaging
**Trigger:** user opens messages UI and sends a message  
**Path:** `POST /conversations` or `GET /me/conversations` → `MessagingController` → `MessagingService::send()` → check blocks + DM access setting → `INSERT mvs_messages`, UPDATE `mvs_conversations.last_message_at` → `mvs_message_sent` action → `NotificationListener::on_message_sent()` → `INSERT mvs_notifications`  
**Transport:** REST polling via `RestPollingTransport` (no WebSocket).  
**Gap:** no admin conversation browser/moderator view. Support cannot investigate reported DM abuse.

### Flow 13 — Album Create + View
**Trigger:** user creates album from dashboard or API  
**Path:** `POST /albums` → `AlbumController` → `AlbumService::create()` → `wp_insert_post('mvs_album')` → response  
**Items:** `POST /albums/{id}/items` → `INSERT mvs_album_items`  
**Frontend:** `templates/album.php` → album-viewer block  
**Wiring:** complete.

### Flow 14 — Collection + Smart Collection
**Trigger:** user or admin creates collection  
**Path:** `POST /collections` → `CollectionController` → `CollectionService::create()` → `wp_insert_post('mvs_collection')`  
**Smart rules:** set via `Admin/CollectionMetaBox.php` (admin; rules stored as post meta, not `mvs_access_rules`)  
**Frontend:** `templates/collection.php` evaluates rules to populate grid  
**Wiring:** complete; `mvs_collection_media_ids` filter exposes items to Pro.

### Flow 15 — Signed URL + Privacy Gate
**Path:** `SignedUrlService::generate()` → HMAC sign with `MVS_SIGNED_URL_KEY` → URL with `mvs_exp`, `mvs_sig`, `mvs_uid`; `serve()` validates → checks privacy and access rules  
**Wiring:** complete.

### Flow 16 — AI Describe/Tag/Moderate
**Trigger:** media upload when `mvs_ai_auto_analyze` or `mvs_ai_auto_moderate` is on  
**Path:** `UploadService` → `AIService::analyze()` → `OpenAIProvider::analyze_image(MediaUrl::for_file($id))` → write AI description to meta, write AI tags to taxonomy → if moderation: `AIService::moderate()` → budget gate → provider → result → `ModerationService::apply_action()` (hide/flag/remove)  
**Admin:** `Admin/StatsPage.php` AI review tab; `templates/admin/ai-review.php`  
**Wiring:** complete.

### Flow 17 — BuddyPress Activity Bridge
**Trigger:** `mvs_media_uploaded` action (public media only after 1.7.0 fc31bf0)  
**Path:** `ActivitySyncIntegration::on_media_upload()` → `bp_activity_post_update()` → content transform on read via `ActivityContentIntegration::enhance_activity_media_content()` → `MediaUrl::for_file()` signs URLs  
**Wiring:** complete; private media correctly excluded from BP activity.

### Flow 18 — BuddyPress Profile/Group Media Tab
**Trigger:** visiting BP member or group media tab  
**Path:** `ProfileTabIntegration` / `GroupTabIntegration` → registers nav item → `render_tab()` → queries `mvs_media_index` by user/group meta → `templates/explore.php` variant  
**Wiring:** complete.

### Flow 19 — BuddyPress Notifications Bridge
**Trigger:** `mvs_notification_created` action  
**Path:** `NotificationIntegration::on_notification()` → `bp_notifications_add_notification()` with `component=wpmediaverse` → `format_notifications_for_user()` formats display  
**Wiring:** complete; 1.7.0 ensures message/link match the REST output.

### Flow 20 — Activity Feed (Following Sort)
**Trigger:** `GET /mvs/v1/feed` or `GET /users/{id}/activity`  
**Path:** `ActivityController` → `ActivityService::get_feed()` → query `mvs_activity` with follow-graph JOIN  
**Gap:** NO frontend template or block currently renders from this endpoint in the Free plugin. The Explore template renders from `mvs_media_index` directly. The `/feed` endpoint exists but is not wired to any Free frontend surface. Activity accumulates in `mvs_activity` but a "Following feed" tab is not rendered anywhere. This is a dead REST endpoint from the user's perspective.

### Flow 21 — Access Rules + Lock Overlay
**Trigger:** site owner adds access rules to media via REST  
**Path:** `POST /media/{id}/rules` → `AccessRulesService::create()` → `INSERT mvs_access_rules`; `POST /media/{id}/grant` → `INSERT mvs_access_grants`  
**Frontend read:** `src/blocks/lock-overlay/render.php` → `AccessRulesService::get_rules()` → show locked/unlocked UI  
**Admin gap:** no admin page to view all rules or grants across the site.

### Flow 22 — Stats Aggregation
**Trigger:** view event (serve), download, share, reaction, comment  
**Path:** each event increments `mvs_media_stats` via `MediaRepository::increment_stat()`; `mvs_media_views` row inserted for view/download events  
**Admin:** `StatsPage` renders aggregate charts and top-media lists from `mvs_media_stats`  
**REST:** `GET /media/{id}/stats`, `GET /me/stats`  
**Wiring:** complete.

### Flow 23 — GDPR Export/Import
**Path:** `GDPRService::export()` dumps all `mvs_*` data for user; fires `mvs_export_started` → archive → download  
**Import:** `GDPRService::import()` parses archive → re-insert rows; fires `mvs_import_completed`  
**Wiring:** complete (CLI + admin triggered).

### Flow 24 — Orphaned File Cleanup
**Path:** media delete → `mvs_media_files_orphaned` action → `StorageCleanupService` → `mvs_cleanup_media_files` Action Scheduler job → `StorageService::delete_everywhere()` → removes from local + cloud  
**Wiring:** complete (1.6.0).

**Total flows mapped: 24**

---

## 3. 1.7.0 Coverage Requirements

These are the new test assertions the smoke runbook and journey files MUST verify for 1.7.0:

### Card 1 — Categories silent-drop fix (commit d20d999)

1. `PUT /mvs/v1/media/{id}` with `{"categories":[TERM_ID]}` returns HTTP 200 AND `GET /mvs/v1/media/{id}` subsequently shows that term name in `categories[]` — NOT an empty array. Verify on a site with Redis/Memcached or WP object cache active.
2. `PUT` with `{"categories":[]}` returns 200 AND subsequent GET shows `categories: []` (clear operation still works).
3. `OPTIONS /mvs/v1/media/{id}` lists `categories`, `tags`, `title`, `description`, `slug`, `privacy`, `allow_download` in the route's `args` — was `id`-only before 1.7.0.
4. If `wp_set_object_terms()` returns a `WP_Error`, the response is HTTP 500 with `code: mvs_categories_not_saved` (not a silent 200).
5. Journey file: `audit/journeys/customer/11-media-update-categories-persist.md` — run all steps.

### Card 2 — Grid thumbnail size (commit d20d999 + c888fd7)

6. With default settings (`mvs_thumbnail_size = medium`), the `thumbnail_url` returned by `GET /mvs/v1/media` uses the medium-size variant, not the 1024px large variant.
7. Changing `mvs_thumbnail_size` to `large` in Settings causes `GET /mvs/v1/media` to return large-variant URLs.
8. The `src/blocks/media-grid/render.php` and `src/blocks/explore-feed/render.php` both call `SettingsHelper::get_grid_thumb_size_key()` (grep confirms; verify with the step in journey 15).
9. `templates/explore.php`, `templates/album.php`, and `templates/collection.php` use `render_grid_item()` / `media_thumbnail()` which delegate to the configured size — not hardcoded `large`.
10. Lightbox still loads original/full-size image (not the grid thumbnail) — confirmed by checking `get_lightbox_url()` call path.
11. Journey file: `audit/journeys/customer/12-grid-thumbnail-size-srcset.md` — run all steps.

### Card 3 — Blank video poster fallback (commit d20d999)

12. A video media item with no generated poster (no ffmpeg, no getID3 cover) returns a non-empty `thumbnail_url` in `GET /mvs/v1/media/{id}` and `GET /mvs/v1/media` — the value must be the bundled SVG default, not `''`.
13. `TemplateHelpers::default_video_poster_url()` returns a non-empty URL pointing at the plugin's bundled SVG asset.
14. A video tile in the explore grid renders an `<img>` tag with the fallback SVG poster — no blank/black tile.
15. The new Site Health test `wpmediaverse_video_posters` appears in WP Site Health and shows a meaningful status about ffmpeg availability.
16. `PosterService::is_ffmpeg_available()` returns `false` on environments without ffmpeg and `true` on those with it — verify by calling it in a WP-CLI eval.

### Card 4 — Public media cacheable on local driver (commit d20d999)

17. With local storage driver active, generate two signed URLs for the same PUBLIC media item — they must be identical (stable `mvs_exp` value). `wp eval` test: `$a=$s->generate($ID,0); $b=$s->generate($ID,0); echo ($a===$b?"STABLE":"UNSTABLE");` must print `STABLE`.
18. `curl -sI $PUBLIC_SERVE_URL` returns `Cache-Control: public, max-age=604800` and a far-future `Expires` header — no `no-store`.
19. `curl -sI $PRIVATE_SERVE_URL` returns `Cache-Control: no-store, no-cache` (unchanged).
20. Adding `add_filter('mvs_stable_public_urls','__return_false')` reverts the public URL to rolling expiry — opt-out works.
21. Adding `add_filter('mvs_public_media_max_age', fn()=>86400)` changes the `max-age` value to 86400 — filter is respected.
22. Note: this test ONLY applies to the local driver. Cloud drivers (S3/BunnyCDN) serve public media via direct CDN URL and bypass `/serve` entirely.
23. Journey file: `audit/journeys/customer/14-public-media-cacheable-local.md` — run all steps.

### Card 5 — BuddyNext notification contract (commit d20d999)

24. Fire `NotificationService::create()` for a `media_reaction` type — capture the `mvs_notification_created` action with an `add_action(..., 10, 7)` listener — assert: `count(func_get_args()) === 7`, arg[5] (message) is a non-empty string, arg[6] (link) is a valid URL.
25. The message text matches what `GET /me/notifications` returns for the same notification (same wording from `build_message_and_link()`).
26. An existing 5-arg listener (`add_action('mvs_notification_created', $cb, 10, 5)`) still fires without PHP warning/error (backward-compatible appended args).
27. Test all notification types: `media_reaction`, `new_comment`, `new_mention`, `new_follower`, `new_message` — each produces a meaningful message string and a non-empty link.
28. Journey file: `audit/journeys/customer/13-notification-hook-message-link.md` — run all steps.

### N+1 Performance fix (commit c888fd7)

29. On a site with 12+ public media items, a WP-CLI eval running the prefetch + grid render loop produces `$wpdb->num_queries` delta of ≤ 12 (target ~6) — NOT ≈ 170.
30. All five render paths (`templates/explore.php`, `templates/album.php`, `templates/collection.php`, `src/blocks/media-grid/render.php`, `src/blocks/explore-feed/render.php`) contain both `prefetch()` and `prefetch_active_rules()` calls before their render loops — grep check.
31. Access control is not regressed: a private media item is NOT visible to anonymous on the explore grid after the prefetch optimization.
32. Journey file: `audit/journeys/customer/15-grid-render-query-budget.md` — run all steps.

### Private media activity row (commit fc31bf0)

33. Upload a media item with `privacy = private` — confirm NO row is written to `mvs_activity` for it (query `mvs_activity WHERE media_id = $MEDIA_ID` should return 0 rows).
34. Upload a media item with `privacy = public` — confirm a row IS written to `mvs_activity`.
35. A DM media attachment (uploaded as private) does NOT appear in any public activity feed.
36. Changing a private media item's privacy to public DOES NOT retroactively add an activity row (no back-fill — the fix is upload-time only).

---

## 4. Top Correctness Risks

### Risk 1 — CRITICAL: `mvs_activity` table is a write-only store on Free
`ActivityService::log()` inserts into `mvs_activity` on every upload, reaction, comment, and follow. `ActivityController` exposes `GET /feed` and `GET /users/{id}/activity`. However, **no Free frontend template or block renders from either of these endpoints**. The Explore template queries `mvs_media_index` directly. The dashboard has no activity feed tab. No block renders the `/feed` endpoint. Every row written to `mvs_activity` in a Free-only install is effectively unreadable by end users. This violates Rule 18 and misleads any mobile app developer who discovers `GET /feed` exists and assumes it has a UI counterpart.

### Risk 2 — HIGH: `mvs_mentions` has no REST read endpoint or admin UI
`MentionService` writes `mvs_mentions` rows on every `@mention` in a comment. The rows are never read back directly — they only trigger a notification. There is no `GET /me/mentions`, no admin mentions page, and no frontend "Mentions" tab. This means: (a) users cannot see a list of media they were mentioned in without scrolling notifications; (b) admins cannot audit mentions; (c) a mobile app client cannot build a "Mentions" screen. The table is effectively write-only from all three entry-point dimensions.

### Risk 3 — HIGH: `mvs_transactions` created in Free Migrator, zero consumers in Free
Migrator creates `mvs_transactions` as part of the standard schema install on every Free activation. No Free service reads or writes this table. All consumers are in the Pro plugin. This means every Free-only site has a dead table taking up schema space and potentially confusing site admins or developers who inspect the database. It should either be moved to the Pro Migrator or documented as an intentional exception in `manifest.tables.json`.

### Risk 4 — MEDIUM: Access rules have no admin UI for cross-site management
`mvs_access_rules` and `mvs_access_grants` have full REST CRUD (`AccessController`) and are consumed by the lock-overlay block. However, there is no admin page where a site owner can see all access rules across all media in one place, or see who has been granted access to gated content. The only write path to `mvs_access_rules` admin-side is via REST (no admin form). A site owner cannot manage access grants without API access — Rule 18 violation and a practical operational gap.

### Risk 5 — MEDIUM: DM moderation is a blind spot
`mvs_conversations`, `mvs_messages`, and `mvs_message_reactions` have full REST coverage and a working frontend UI. But there is no admin entry point. A site admin or moderator cannot: (a) view a reported conversation thread, (b) delete abusive messages outside of the sender/recipient endpoints, or (c) see the conversation list for a flagged user. Given that `ReportService` allows users to report other users (which would typically trigger a DM review), this gap leaves moderation incomplete.

### Risk 6 (bonus) — LOW: `resolve_expiry()` bucket rotation timing edge case
The 1.7.0 public-URL stability fix buckets expiry to `floor(time() / MONTH_IN_SECONDS) * MONTH_IN_SECONDS + YEAR_IN_SECONDS`. This rotates at the start of each month — meaning all public media cache keys change simultaneously on the 1st of each month, causing a full-site cache miss storm. On large sites this could spike origin load for 15-30 minutes. Mitigation: use a per-media-ID salt to spread rotation across the month. No current mechanism exists for this.

---

## Appendix — 1.7.0 Journey Map

| Journey ID | File | Covers |
|-----------|------|--------|
| J-11 | `audit/journeys/customer/11-media-update-categories-persist.md` | Card 1: category silent-drop fix |
| J-12 | `audit/journeys/customer/12-grid-thumbnail-size-srcset.md` | Card 2: grid thumbnail size |
| J-13 | `audit/journeys/customer/13-notification-hook-message-link.md` | Card 5: BuddyNext notification contract |
| J-14 | `audit/journeys/customer/14-public-media-cacheable-local.md` | Card 4: public media cacheable |
| J-15 | `audit/journeys/customer/15-grid-render-query-budget.md` | N+1 performance fix |
| MISSING | — | Card 3: blank video poster fallback — no journey file exists yet |
| MISSING | — | Private media activity row (fc31bf0) — no journey file exists yet |

**Two 1.7.0 fixes lack journey coverage: the video poster fallback and the private-media activity-row exclusion.**
