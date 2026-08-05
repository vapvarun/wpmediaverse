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
| Lightbox Edit-Media modal | title/description/privacy/allow-download fields prefilled from media, save → REST PUT → live update | save disabled while title empty; ESC closes without saving |
| Lightbox Download button | downloads original asset; increments `mvs_media_stats.downloads` once per click; rate-limited at 30/min/user | hidden when global `mvs_allow_downloads` off OR per-media `allow_download='0'` |
| Lightbox Fullscreen button | enters native Fullscreen API on the image panel; F key toggles | exit on ESC or F again; toolbar still operable in fullscreen |
| Upload modal popular tag pills | top-8 popular tags rendered as clickable chips that append to the tag input | hidden when no tags exist anywhere |
| Explore search autocomplete | top-8 title matches as a dropdown after 250ms debounce; ArrowDown/Up/Enter/ESC keyboard nav | hidden on `<2` chars or zero-result |
| Per-media edit modal — `?autologin=1` | Edit cog visible on dashboard cards (`/my-media/`) only when `can_edit`; opens prefilled modal | not visible on Explore / Album / Collection cards |
| Admin All Media — Bulk Actions toolbar | multi-select, Move-to-Trash / Restore / Delete-permanently (context-aware to current filter); success notice with count | bulk submit with 0 selected → friendly error, no destructive call |
| Block: `mvs/member-photos` | auto-resolves user (explicit ID → BP displayed user → post author → current user); media grid renders | logged-out + no userId → "no user resolved" empty state |
| Block: `mvs/pdf-viewer` | iframe with `#view=FitH` URL fragment; toolbar toggle; lazy-load | 5 distinct empty states: no id / not found / wrong type / privacy fail / asset missing |
| Single-media OG + Twitter Card meta | `og:title`/`og:image`/`og:description`/`twitter:card` injected on `wp_head` priority 5 | absent on non-media pages |
| Frontend chat-panel | renders or not per `mvs_chat_panel_visibility` (`everywhere` / `mvs_pages` / `bp_pages` / `disabled`) | `disabled` mode → no `.mvs-chat-panel` markup at all |
| BP notification surface (BP active) | only the BP nav bell renders MVS notifications | dashboard-content `.mvs-notification-bell` suppressed (no double-render) |
| Site Health → `wpmediaverse_video_posters` test | appears in WP Site Health; reports ffmpeg availability (via `PosterService::is_ffmpeg_available()`) and whether posterless videos fall back to the bundled default SVG | meaningful status string, never a blank/fatal test row |

Render rule (standing): every row above must produce visible output in both populated and empty branches. No bare `return;` in render paths. This includes all 9 registered Gutenberg blocks (`mvs/*`) and all 12 shortcodes (`[mvs_*]`).

---

## 2. User actions that must produce correct state transitions

### Regression locks (specific visual specs that have already regressed once — do NOT drift)

| Surface | Locked spec | Regression history |
|---------|-------------|-------------------|
| Activity composer preview (1 image) | Compact tile 120-150px wide, 1:1 aspect, `max-height: 150px`. Consistent with multi-image grid cells. Never a "hero preview". | Regressed in `ba9f711` (2026-04-22) to 200-320px hero; restored in `04175ec` (2026-04-24). |
| Activity composer preview (2-6 images) | CSS Grid with per-count column templates (`mvs-preview-grid-2` → 2col, `-3` → 3col, `-4` → 2x2, `-5/6` → 3col). Collapses to 2col at ≤640px. | — |
| Streak badge in display name | Must carry both `title` AND `aria-label` with identical copy. `wp_kses` allowlists for `span` in display-name render paths must include `aria-label`. | F3 (5 surfaces, fixed 2026-04-23). |
| Plugin-mapped URL render paths | Must emit `mvs-frontend` CSS + `mvs-lucide` JS on every page that uses plugin markup. Integrations that enqueue their own scripts MUST register-if-absent + enqueue `mvs-lucide` when any `data-lucide` attribute is in their output. | Missed on 404 (fixed `c5231b9`) and BP activity composer (fixed `e466cf9`). Architectural fix pending — central `Plugin::ensure_frontend_assets()` helper so integrations don't re-solve the same puzzle. |
| Activity composer attach-media button | Proper button with visible `.mvs-activity-media-btn__label` text "Attach media", Lucide `image-plus` icon pinned to 18px via `.mvs-activity-media-btn svg` rule, `aria-label` on the button. Icon-only bare-box states are a bug — see note in `ActivityFormIntegration::activity_post_media_button()`. | Rendered as icon-only bare box (no label + no SVG size rule) until `<sha-tbd>`. Customer screenshot flagged the empty outlined box twice. |
| Activity composer attach-media + privacy alignment | `#mvs-activity-media-btn` and `#mvs-activity-privacy` render on the same row inside `.mvs-activity-media-btn-wrap` (flex row, 10px gap). Both at `min-height: 36px`, matching `4px` border-radius, `13px` font-size, `1px` border. Select uses custom chevron SVG, not native UA arrow. `yDelta` between their top edges must be 0px on wb-reign-theme. | Regressed repeatedly because raw CSS rules targeting `<select>` inside `#whats-new-form #whats-new-options` in Reign's `main.min.css` have specificity `(3,1,3)` with `height: 42px`; our rule must anchor at `#buddypress #whats-new-form #whats-new-options #mvs-activity-privacy.mvs-activity-privacy` (specificity `5,1,0`) and force `height: auto`. |
| BP CSS file ownership | **All BP-specific CSS lives in `assets/css/bp-integration.css`**, scoped under `#buddypress` (and `.activity-list` where an AJAX-injected activity stream can render outside `#buddypress`). `frontend.css` is for generic plugin frontend only: design tokens, templates, shortcodes, blocks, dashboard, single-media, lightbox. `ActivityFormIntegration`, `ProfileTabIntegration`, `GroupTabIntegration` all enqueue both stylesheets. A new BP rule added to `frontend.css` is a bug — move it to `bp-integration.css`. | Entire BP rule set (~2500 lines across 5+ sections) was initially accumulated in `frontend.css` because `ActivityFormIntegration` only enqueued `mvs-frontend`. Dead `.theme-flavor` selectors, duplicate `.mvs-activity-media-btn` class/ID pairs, and a broken dangling `.theme-flavor` selector merging into a sibling rule all landed as consequence. Migrated in commits `8f63b3b` → `df15593` (2026-04-24). |
| Lightbox 6-reaction a11y | Each of `Like / Love / Haha / Wow / Sad / Angry` carries `aria-label` (sentence-form), `aria-pressed` toggle, and the emoji span has `aria-hidden="true"`. Group wrapper has `role="group" aria-label="Reactions"`. Toolbar buttons (Share / Open / Favorite / Report / Download / Fullscreen) all carry `aria-label`. `:focus-visible` outline on `.mvs-lightbox-action / -close / -nav` so keyboard nav is visible. | A11y pass 2026-05-03 (`51d95ba` + `c250f75`). Drift = screen-reader-only users see emoji glyphs only, no semantic action name. |
| Share button must NOT show `window.prompt` fallback | `lightboxShare` action: try `navigator.share()` → `navigator.clipboard.writeText()` → toast on failure. Never `window.prompt()`. The third popup ("Copy this link:") is a shipping bug. | Removed during 1.2.0 RC walkthrough — customer flagged the ugly browser-native prompt screenshot. |
| `mvs_pro_*` option prefix on Pro side | Every Pro-owned setting carries `mvs_pro_` prefix. Activation migration copies any unprefixed `mvs_*` value once on first 1.2.0 boot then deletes the old key. | Renamed in 1.2.0 to avoid Free namespace collision. A regression here = duplicate options + drift. |
| Block render must enqueue Layout assets (Pro Rule 6) | Any Pro block whose `render.php` instantiates a `WPMediaVersePro\Frontend\Layouts\*` class MUST call `$layout->enqueue_assets()` in the same file. Idempotent — `wp_enqueue_*` dedupes by handle. | Enforced by `bin/coding-rules-check.sh` Rule 6. The bug class shipped briefly in 1.2.0 — feed blocks instantiated Layout but never enqueued per-layout CSS, so SVG icons rendered at viewBox-default size. Locked 2026-05-03. |

---

| Action | Expected state changes | Expected signals |
|--------|------------------------|------------------|
| Upload photo | row in `mvs_media_index` + `mvs_media_meta` (all 3 thumb sizes, back-filled for small sources) + an `mvs_activity` row for every privacy level **except `private` and `dm`** (fc31bf0) | `mvs_media_uploaded` fires once |
| Upload posterless video | row + meta; REST `thumbnail_url` falls back to the bundled default poster SVG (never `''`); grid tile renders an `<img>` (no blank/black tile) | `mvs_media_uploaded` fires once |
| Edit media categories (REST `PUT`) | `wp_set_object_terms()` + `category` cache meta derived from submitted term IDs; persists across a persistent-object-cache miss; only an empty array clears | none; `wp_set_object_terms()` error → 500 `mvs_categories_not_saved` |
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
| Edit own media via lightbox modal | `PUT /mvs/v1/media/{id}` updates title/description/privacy + `allow_download` meta; lightbox card refreshes in place | none if title is empty (button gated) |
| Lightbox download | `POST /mvs/v1/media/{id}/download`; `mvs_media_stats.downloads` increments by 1 | rate-limited to 30/min/user → 429 |
| Lightbox share (native or copy) | `POST /mvs/v1/media/{id}/share`; `mvs_media_stats.shares` increments | toast confirms; no `window.prompt` ever fires |
| Lightbox fullscreen | Fullscreen API enters/exits on the image panel; `F` shortcut toggles | ESC exits; toolbar still operable in fullscreen |
| Click popular tag pill in upload modal | tag appended to comma-separated tags input (no duplicates) | — |
| Type in Explore search | `GET /mvs/v1/media?s=&per_page=8` after 250ms debounce; suggestion dropdown opens | no network on `<2` chars |
| Bulk move to Trash (admin) | selected `mvs_media_index` rows flipped to `status='trashed'`; `mvs_media_deleted` does NOT fire | redirect with `?mvs_bulk_done=N&mvs_bulk_action=trash` notice |
| Bulk Restore from Trash filter | rows flipped back to `status='published'` | redirect notice |
| Bulk Delete permanently | rows + meta + stats removed; files removed from disk; `mvs_media_deleted` fires per-row | confirmation prompt before submit |

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
| `mvs_signed_url_ttl` | signed URLs expire after that many seconds (private media). PUBLIC media on the local driver gets a render-stable signed URL + `Cache-Control: public, max-age` instead — filters `mvs_stable_public_urls` (opt out → rolling expiry), `mvs_public_media_max_age` (default 604800), `mvs_public_local_file_url`, `mvs_public_local_thumbnail_url`. Private stays `no-store`. Cloud drivers serve public via direct CDN and bypass `/serve`. |
| `mvs_grid_columns` | `/media/` renders with correct column count |
| `mvs_items_per_page` | feed pagination window matches |
| `mvs_thumbnail_style` | square vs original thumbnail shape |
| `mvs_thumbnail_size` | grid/feed thumbnail variant served (routed via `SettingsHelper::get_grid_thumb_size_key()` across MediaController, FavoriteController, media-grid + explore-feed render.php, TemplateHelpers). **Default changed `large` → `medium` in 1.7.0.** Lightbox still loads full/large regardless. |
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
| `mvs_pro_battle_win_xp` (Pro) | XP the battle winner earns (default 100; Gamification settings). Snapshotted as `xp_win` on battle creation; `CompetePointsBridge` `mvs_battle_win` case awards this, not the WB Gamification flat default. |
| `mvs_pro_streak_freeze_cost` (Pro) | points debited (atomically, via `PointsEngine::debit()`) when a member buys a streak freeze via `POST /streaks/buy-freeze` |
| `mvs_pro_ffmpeg_path` (Pro) | transcoding uses that binary |
| `mvs_pro_s3_*` / `mvs_pro_bunny_*` (Pro) | storage driver uses those credentials |
| `mvs_pro_google_vision_key` / `mvs_pro_aws_*` (Pro) | AI vision provider reachable |
| `mvs_chat_panel_visibility` | `everywhere` (default) / `mvs_pages` (only `is_mvs_page`) / `bp_pages` (only `is_bp_page`) / `disabled` — controls `Plugin::render_chat_panel()` output. `mvs_should_render_chat_panel` filter wraps the resolved decision. |
| `mvs_allow_downloads` | global gate — when off, the lightbox Download button is hidden everywhere AND `record_download` REST refuses with 403. Per-media `allow_download` meta still gates further when global is on. |

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
| `mvs_activity` | `ActivityService::on_upload()` — fires for every upload privacy level **except `private` and `dm`**, which return early (fc31bf0). BuddyNext DM attachments upload as `private`, so writing a row for them would put DM contents in the activity stream. Corrected 2026-08-05: this table previously claimed the opposite and cited the same commit. | feed sort "Following", profile activity stream |
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

**Shortcodes (12, namespace `mvs_`):** gallery, upload, album, player, stats, dashboard, collection, profile_edit, explore_feed, lock_overlay, member_photos, pdf_viewer.

**Blocks (9 registered, namespace `mvs/`):** media-grid, explore-feed, album-viewer, media-player, media-upload, media-stats, lock-overlay, member-photos, pdf-viewer. (`story-viewer` source exists but is intentionally not in `BLOCKS` for 1.2.0 — Story create-flow + REST endpoint deferred to 1.2.1.)

---

## 7. Out of scope

- WP home `/`
- Theme-owned pages
- Other plugins' pages / content
- Anything behind `wp-admin/` that isn't on the WPMediaVerse menu

---

Everything in sections 1–5 above is what a release must be able to demonstrate. `MANUAL-UX-QA.md` is the procedural expansion of section 1 for step-by-step walking. `RENDER-STATE-RULES.md` names section 1's "empty state" column as a standing rule. This doc is the flat one — if a new feature needs a row, add it here first.
