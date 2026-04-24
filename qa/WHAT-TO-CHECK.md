# WPMediaVerse — What to check

A flat inventory of the surfaces, actions, states, and data relationships that must work. Not a process, not a plan — just the list. The AI agent (or human reviewer) figures out *how* to verify each one.

When you add a feature, add a line here. When the AI finishes a pass, it should be able to say "row N — OK / broken / not applicable" for every row below.

---

## 1. Surfaces that must render correctly (with real content + empty state)

| Surface | Populated state | Empty state |
|---------|-----------------|-------------|
| `/media/` explore grid | real thumbnails, metadata, pagination | friendly "nothing shared yet" with upload CTA |
| `/media/` explore with `?s=` zero results | — | "no results for {term}" + popular tags + browse-all |
| `/media/` explore with `?mvs_tag=X` | filtered feed | "no media tagged X" + clear-filter |
| `/media/{slug}/` single media | media player + metadata + tags + actions | plugin-branded 404 |
| `/media/@{user}/` profile | header + grid + follower counts | "@user hasn't uploaded yet" |
| `/media/@{user}/page/2/` | same, paginated | — |
| `/media/edit-profile/` | form (logged in) | redirect / gate (logged out) |
| `/album/{slug}/` | album cover + items | private → lock; public empty → "no items in album" |
| `/collection/{slug}/` | collection items | smart rules evaluate to zero → "no items match rules" |
| `/my-media/` dashboard | 4 tabs + quota | each tab has its own distinct empty state |
| `/my-media/` anon | — | premium auth gate (lucide icons, primary CTA, redirect_to) |
| `/compete/` hub | 3 cards with counts | anon sees "log in to participate" |
| `/media/battles/` | battle list | "no active battles" + create-battle CTA |
| `/media/challenges/` | challenge list | "no active challenges" |
| `/media/tournaments/` | bracket list | "no tournaments in registration" |
| `/messages/` | conversation list + composer | "start a conversation" CTA |
| BP `/members/{user}/media/` | real thumbnails (not page URL) | empty state, no broken img tags |
| BP `/groups/{slug}/media/` | group media | "no media in this group" |
| Lightbox | image + sidebar (reactions + favorites + comments + share) | state resets cleanly between items |
| Admin WPMediaVerse Overview | stat cards with numbers | seeded-zero state with guidance |
| Admin Moderation Queue | flagged items with actions | "queue clear" celebration state |
| Admin Stats | charts rendering | no-data placeholders, not blank canvas |
| Admin All Media | table + filters + pagination | filtered-empty vs truly-empty distinct |
| Admin Pro Competitions Dashboard | active counts + quick links | "create your first challenge" CTA |
| Admin Pro Quota & Credits | package list + credit log | "no packages yet" with create CTA |
| Admin Pro Theme Library | themes grid | — (default themes seeded on activation) |
| Admin Pro Migration Tool | detected counts | "no migrable data detected" |

Render rule (standing): every row above must produce visible output in both populated and empty branches. No bare `return;` in render paths. This includes all 8 Gutenberg blocks (`mvs/*`) and all 8 shortcodes (`[mvs_*]`).

---

## 2. User actions that must produce correct state transitions

| Action | Expected state changes | Expected signals |
|--------|------------------------|------------------|
| Upload photo | row in `mvs_media_index` + `mvs_media_meta` (all 3 thumb sizes, back-filled for small sources) | `mvs_media_uploaded` fires once |
| Upload gallery (2-6 images) | one index row + meta group, all thumbs | same action once |
| Upload oversize/disallowed file | no DB row, specific error message | none |
| Delete own media | rows removed from index + meta + stats | `mvs_media_deleted` fires, file removed from disk |
| React (emoji) | row in `mvs_reactions`, count increments | `mvs_reaction_toggled` fires, UI updates optimistically |
| Switch reaction emoji | previous row replaced, counts adjust | — |
| Unreact (same emoji twice) | row deleted, count decrements | — |
| Favorite | row in `mvs_favorites` | `mvs_favorite_toggled` fires |
| Comment | row in `wp_comments` scoped to media | `mvs_comment_created` fires |
| Edit own comment within 15 min | comment updated | — |
| Delete own comment | comment + child comments removed | — |
| Follow user | row in `mvs_follows`, notification to target | `mvs_user_followed` fires once (idempotent) |
| Unfollow | row removed, counts adjust | `mvs_user_unfollowed` fires |
| @mention in comment | mention becomes link, target gets notification | — |
| Report media | row in `mvs_reports` | `mvs_report_submitted` fires |
| Block user | row in `mvs_blocks`, blocked user's content hidden | `mvs_user_blocked` fires |
| Create album | post in `wp_posts` type `mvs_album` | — |
| Reorder album items | `mvs_album_items.sort_order` updated atomically, no gaps | — |
| Set album cover | cover meta updated, album page reflects immediately | — |
| Set privacy (public/members/private) | `mvs_media_index.privacy` updated, feed visibility honors it | — |
| Grant access to private media | row in `mvs_access_grants` | — |
| Generate signed URL | signed URL that validates + expires per `mvs_signed_url_ttl` | — |
| DM: send text | row in `mvs_messages`, conversation bumped | `mvs_message_sent` fires |
| DM: send media attachment | message row + media reference | — |
| DM: read receipts | `mvs_conversation_participants.last_read_at` updates | `mvs_conversation_read` fires |
| DM: block user | DMs from blocked user rejected | — |
| Boost media (Pro) | `mvs_boosts` row created, points deducted | — |
| Enter challenge (Pro) | `mvs_competition_entries` row created | `mvs_challenge_entry_submitted` fires |
| Vote on entry (Pro) | `mvs_competition_votes` row (unique per voter + entry) | — |
| Start battle (Pro) | competition + 2 entries, opponent notified | `mvs_battle_created` fires |
| Register for tournament (Pro) | entry row, bracket generated when capacity hit | `mvs_tournament_created` fires |
| Win/advance (Pro) | XP awarded via gamification trigger | — |
| Buy streak freeze (Pro) | `_mvs_streak_freezes` decremented | — |
| Upload at quota (Pro) | upload blocked with upgrade CTA | — |
| Transcoding job (Pro) | `_mvs_transcodes` meta populated with outputs | `mvs_pro_transcode_complete` fires |
| Caption generation (Pro) | `_mvs_captions` meta populated with VTT+SRT URLs | `mvs_pro_captions_generated` fires |

---

## 3. Admin settings that must wire through to frontend behavior

For each: saving it should change what users see or what the system allows.

| Setting key | Frontend effect |
|-------------|-----------------|
| `mvs_max_upload_size` | uploads over limit rejected with specific error |
| `mvs_allowed_file_types` | disallowed MIME rejected with reason |
| `mvs_default_privacy` | new uploads inherit it |
| `mvs_duplicate_action` | warn / skip / allow behavior honored |
| `mvs_strip_exif` | EXIF removed from uploaded files |
| `mvs_signed_url_ttl` | signed URLs expire after that many seconds |
| `mvs_grid_columns` | `/media/` renders with correct column count |
| `mvs_items_per_page` | feed pagination window matches |
| `mvs_thumbnail_style` | square vs original thumbnail shape |
| `mvs_page_explore` / `mvs_page_upload` / `mvs_page_dashboard` | plugin links target those pages |
| `mvs_ai_provider` + `mvs_openai_api_key` | AI moderation uses that provider |
| `mvs_ai_auto_moderate` | uploads trigger moderation automatically |
| `mvs_moderation_auto_action` | flagged media gets correct auto-action |
| `mvs_watermark_type` + `mvs_watermark_text` + `mvs_watermark_position` | uploaded image has watermark overlay |
| `mvs_dm_access` | DM to non-followers blocked (or allowed) per level |
| `mvs_dm_min_age` | accounts newer than N days can't DM |
| `mvs_show_online_status` | online dot shown or hidden |
| `mvs_comment_edit_window` | edit window enforced |
| `mvs_pro_feed_layout` (Pro) | `/media/` renders with selected layout template |
| `mvs_battles_enabled` / `..._challenges_` / `..._tournaments_` / `..._boosts_` / `..._streaks_` | frontend pages for that feature present when on, absent when off |
| `mvs_pro_ffmpeg_path` (Pro) | transcoding uses that binary |
| `mvs_pro_s3_*` / `mvs_pro_bunny_*` (Pro) | storage driver uses those credentials |
| `mvs_pro_google_vision_key` / `mvs_pro_aws_*` (Pro) | AI vision provider reachable |

Every key must have at least one reader in `templates/` or `includes/**/Service.php`. A setting with no reader is dead weight — flag it.

---

## 4. Data stores that must have matched writers + readers

| Store | Writer | Reader |
|-------|--------|--------|
| `mvs_media_index.*` | `UploadService::handle()` | every render path, feed, profile, BP tab |
| `mvs_media_meta` thumb_* | `UploadService::generate_thumbnails()` (with back-fill for sub-1024 sources) | `TemplateHelpers::get_thumb_url()` fallback chain |
| `mvs_reactions` | `ReactionService::toggle()` | feed card counts, lightbox reaction bar |
| `mvs_favorites` | `FavoriteService::toggle()` | favorites tab, heart icon state |
| `mvs_follows` | `FollowService::follow()` | profile counts, "Follows you" badge |
| `mvs_notifications` | every service that fires a notification | notification bell + list |
| `mvs_activity` | `ActivityService::log()` | feed sort "Following", profile activity stream |
| `mvs_reports` | `ReportService::submit()` | admin moderation tab |
| `mvs_access_rules` + `mvs_access_grants` | admin + `AccessRulesService` | `PrivacyService::can_view()` |
| `mvs_conversations` + `_participants` + `_messages` | `MessagingService::send()` | messages UI, unread counts |
| `mvs_competitions` + `_entries` + `_votes` + `_matches` | `BattleService` / `ChallengeService` / `TournamentService` | `/compete/`, detail pages, admin managers |
| `mvs_boosts` | `BoostService::create()` | feed ranking, boost indicator |
| `mvs_quota_packages` + `mvs_credit_log` | admin | `QuotaService::can_upload()`, usage widget |
| `_mvs_current_streak` user meta | streak cron | streak badge filter, streak widget |

Any store written but never read = wasted I/O + confusing data. Any store read but never written = silent zero everywhere. Either direction is a bug.

---

## 5. Cross-layer contracts that must agree

When any of these diverge, UI silently breaks (the envelope-drift class):

- REST response shape ↔ JS consumer property access (e.g. `response.data.items` vs `response.items`)
- Admin settings select options ↔ service `in_array()` whitelists (same key list in both places)
- Block attribute schema ↔ `render.php` expected attributes
- Hook signatures ↔ callers (filter `mvs_*` arg count + types match)
- Cron schedule names ↔ `wp_schedule_event()` callers (typos = silent no-op)

---

## 6. Plugin-owned URLs + surfaces recap

**Free frontend:** `/media/`, `/media/{slug}/`, `/media/@{user}/`, `/media/edit-profile/`, `/album/{slug}/`, `/collection/{slug}/`, `/my-media/`, `/messages/`, BP `/members/{user}/media/`, BP `/groups/{slug}/media/`.

**Pro frontend:** `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/`, and `/media/` under each of 4 layout modes (instagram / flickr / pinterest / dribbble) in addition to the default grid.

**Free admin:** Overview, Settings (8 tabs), Moderation, Stats, Logs, All Media, Setup Wizard.

**Pro admin:** Competitions Dashboard, Challenge Manager, Tournament Manager, Battle Monitor, Quota & Credits, Theme Library, Migration, Gamification Settings, License, Pro settings tabs (AI / S3 / BunnyCDN / FFmpeg), Moderation's User Reports tab, Stats' Video Analytics tab.

**Shortcodes (8, namespace `mvs_`):** gallery, upload, album, player, stats, dashboard, collection, profile_edit.

**Blocks (8 registered, namespace `mvs/`):** media-grid, explore-feed, album-viewer, media-player, media-upload, media-stats, story-viewer, lock-overlay.

---

## 7. Out of scope

- WP home `/`
- Theme-owned pages
- Other plugins' pages / content
- Anything behind `wp-admin/` that isn't on the WPMediaVerse menu

---

Everything in sections 1–5 above is what a release must be able to demonstrate. `MANUAL-UX-QA.md` is the procedural expansion of section 1 for step-by-step walking. `RENDER-STATE-RULES.md` names section 1's "empty state" column as a standing rule. This doc is the flat one — if a new feature needs a row, add it here first.
