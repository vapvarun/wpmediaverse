# WPMediaVerse — CLAUDE.md

## Identity
| Property | Value |
|---|---|
| Plugin Name | WPMediaVerse |
| Slug | `wpmediaverse` |
| Text Domain | `wpmediaverse` |
| PHP Namespace | `WPMediaVerse\` |
| Function/Hook Prefix | `mvs_` |
| CPT Prefix | `mvs_` |
| Table Prefix | `{$wpdb->prefix}mvs_` |
| REST Namespace | `mvs/v1` |
| Block Namespace | `mvs/` |
| Option Prefix | `mvs_` |
| Constant Prefix | `MVS_` |
| Min PHP | 7.4 |
| Min WP | 6.5 |

## Architecture
- **Autoloading**: Composer PSR-4 (`WPMediaVerse\` → `includes/`)
- **Container**: `Core\ServiceContainer` — lazy-load, no singletons/globals
- **Migrations**: `Core\Migrator` — version-based, dbDelta for all 9 custom tables
- **Storage**: Driver pattern (`StorageDriverInterface`) — `LocalDriver` default, S3/BunnyCDN via filter

## Custom Post Types
- `mvs_media` — Media items (images, video, audio, documents)
- `mvs_album` — Albums (ordered media collections)
- `mvs_collection` — Collections (saved favorites)

## Taxonomies
- `mvs_tag` — Non-hierarchical media tags
- `mvs_category` — Hierarchical media categories

## Custom Tables (9)
`mvs_reactions`, `mvs_favorites`, `mvs_media_views`, `mvs_media_stats`, `mvs_access_rules`, `mvs_access_grants`, `mvs_mentions`, `mvs_album_items`, `mvs_media_index`

## Key Conventions
- All strings use `__()` / `esc_html__()` with domain `wpmediaverse`
- Direct DB queries use `$wpdb->prepare()` always
- File uploads go through `UploadService::handle()` — validates MIME, checks size, strips EXIF, hashes
- Privacy checked via `PrivacyService::can_view()`
- Capabilities mapped to roles in `MediaCapabilities`

## File Structure
```
includes/
├── Core/         — Bootstrap, container, migrator
├── PostTypes/    — CPT registrations
├── Taxonomies/   — Taxonomy registrations
├── Services/     — Upload, storage, AI, moderation, stats
├── Social/       — Reactions, comments, favorites, mentions, shares
├── REST/         — REST API controllers
├── Capabilities/ — Role/cap mapping
├── CLI/          — WP-CLI commands
└── Admin/        — Settings page, moderation queue
```

## REST API Routes (mvs/v1)
| Route | Methods | Controller |
|---|---|---|
| `/media` | GET, POST | MediaController |
| `/media/{id}` | GET, PUT, DELETE | MediaController |
| `/media/{id}/view` | POST | MediaController |
| `/media/{id}/access` | GET | MediaController |
| `/me/media` | GET | MediaController |
| `/albums` | GET, POST | AlbumController |
| `/albums/{id}` | GET, PUT, DELETE | AlbumController |
| `/albums/{id}/items` | POST | AlbumController |
| `/albums/{id}/reorder` | PUT | AlbumController |
| `/collections` | GET, POST | CollectionController |
| `/collections/{id}` | GET, PUT, DELETE | CollectionController |
| `/media/bulk` | POST | BulkController |
| `/media/{id}/reactions` | GET, POST, DELETE | ReactionController |
| `/media/{id}/comments` | GET, POST | CommentController |
| `/media/{id}/comments/{cid}` | DELETE | CommentController |
| `/media/{id}/favorite` | POST, DELETE | FavoriteController |
| `/me/favorites` | GET | FavoriteController |
| `/media/{id}/stats` | GET | StatsController |
| `/me/stats` | GET | StatsController |
| `/tags` | GET | TagController |
| `/tags/cloud` | GET | TagController |
| `/tags/merge` | POST | TagController |
| `/tags/{id}` | PUT | TagController |
| `/albums/{id}/items/{media_id}` | DELETE | AlbumController |
| `/albums/{id}/cover` | PUT | AlbumController |
| `/collections/{id}/rules` | PUT | CollectionController |
| `/moderation` | GET | ModerationController |
| `/moderation/counts` | GET | ModerationController |
| `/moderation/{id}/approve` | POST | ModerationController |
| `/moderation/{id}/reject` | POST | ModerationController |
| `/moderation/{id}/analyze` | POST | ModerationController |
| `/ai/usage` | GET | ModerationController |
| `/media/{id}/rules` | GET, POST | AccessController |
| `/media/{id}/rules/{rule_id}` | DELETE | AccessController |
| `/media/{id}/grant` | POST | AccessController |
| `/media/{id}/grant/{user_id}` | DELETE | AccessController |
| `/me/grants` | GET | AccessController |
| `/media/{id}/signed-url` | GET | SignedUrlController |
| `/serve` | GET | SignedUrlController |
| `/checkout` | POST | CheckoutController |
| `/checkout/redeem` | POST | CheckoutController |
| `/media/{id}/pricing` | GET | CheckoutController |

## Social Services
- **ReactionService** — Toggle reactions (like/love/haha/wow/sad/angry), syncs stats
- **CommentService** — Threaded comments via WP comments (type=mvs_comment), syncs stats
- **FavoriteService** — Idempotent favorites with optional collection, paginated listing
- **MentionService** — @mention regex parsing, store in mvs_mentions, fires mvs_mentions_created
- **ShareService** — Record shares, generate share links (facebook/twitter/linkedin/email)
- **StatsService** — Views/downloads/aggregation, user totals, pruning, download recording

## AI & Moderation Services
- **AIProviderInterface** — Contract for AI providers (analyze, tag, moderate)
- **OpenAIProvider** — GPT-4 Vision implementation via OpenAI API
- **AIService** — Orchestrator: provider registry, budget tracking, usage stats, auto-pipeline
- **ModerationService** — Queue management, approve/reject workflow, auto-actions (flag/hide/reject), logging
- **Action Scheduler** — Async AI processing on upload via `mvs_ai_process_media` hook (falls back to sync)

## Monetization Services
- **AccessRulesService** — Access rules engine (role/capability/membership/purchase/subscription/code) + grants tracking. Implicit access for role/cap/membership rules; explicit grants for purchase/subscription/code. Privacy filter at priority 20. Idempotent grants with expiration support.
- **SignedUrlService** — HMAC-SHA256 signed, time-limited URLs for gated media. Auto-generated secret, range request support for streaming, download tracking. REST responses auto-replace file_url via `mvs_media_response` filter.
- **PaymentBridgeService** — Hook-based payment abstraction. `mvs_checkout_process` filter for Stripe/WooCommerce integration, `mvs_payment_completed` / `mvs_subscription_cancelled` action hooks, code redemption, free item auto-grant.
- **WatermarkService** — Pro stub for watermarked preview images. `mvs_watermark_enabled` filter to activate, `mvs_generate_watermark` filter for Pro rendering, configurable position/opacity/text. Adds `preview_url` to REST responses at priority 30.

## Gutenberg Blocks (8)
| Block | Namespace | Description |
|---|---|---|
| media-upload | `mvs/media-upload` | Drag & drop file uploader with REST integration |
| media-grid | `mvs/media-grid` | Filterable media grid with lightbox |
| media-player | `mvs/media-player` | Video/audio player with view tracking |
| album-viewer | `mvs/album-viewer` | Album display with ordered items |
| story-viewer | `mvs/story-viewer` | Instagram-style stories (time-limited) |
| media-stats | `mvs/media-stats` | User stats dashboard cards |
| explore-feed | `mvs/explore-feed` | Public explore feed with search/filter/load-more |
| lock-overlay | `mvs/lock-overlay` | Paywall overlay with blurred preview + unlock prompt |

## Interactivity API Stores (6)
`mvs/media-upload`, `mvs/media-grid`, `mvs/media-player`, `mvs/story-viewer`, `mvs/explore-feed`, `mvs/lock-overlay`

## Shortcodes (5)
| Shortcode | Attributes | Maps To |
|---|---|---|
| `[mvs_gallery]` | columns, count, type, category, lightbox | media-grid block |
| `[mvs_upload]` | max_files, show_privacy | media-upload block |
| `[mvs_album]` | id, columns, show_title | album-viewer block |
| `[mvs_player]` | id, autoplay, loop, download | media-player block |
| `[mvs_stats]` | views, downloads, reactions, top_media | media-stats block |

## Template Override System
Templates can be overridden by copying to `your-theme/wpmediaverse/`:
- `media-single.php` — Single media item display
- `album.php` — Single album display
- `explore.php` — Explore/archive page

## Admin Pages
- **Settings** (`mvs-settings`) — General, Uploads, Storage, AI, Moderation settings
- **Moderation Queue** (`mvs-moderation`) — Review flagged/pending media with approve/reject
- **Stats Dashboard** (`mvs-stats`) — Overview stats, top media, AI usage

## Integrations
- **BuddyPressIntegration** — Activity stream (upload/react/comment), profile Media tab, group Media tab, BP notifications (reaction/comment/mention). Conditional: only loads when BuddyPress is active.
- **WebhookService** — Outbound webhooks for media events (uploaded, deleted, moderated, reaction, comment). HMAC-SHA256 signed payloads, async delivery via Action Scheduler, configurable via Settings.

## Recent Changes
| Date | Files | Description |
|---|---|---|
| 2026-03-03 | Phase 1a (all) | Initial scaffold — core, CPTs, taxonomies, caps, upload, settings, stubs |
| 2026-03-03 | Phase 1b (all) | REST API (4 controllers, 13 routes), PrivacyService, RateLimiter, PHPUnit (27 tests) |
| 2026-03-03 | Phase 2 (all) | Social layer — 5 services, 3 controllers, 6 routes, 71 tests |
| 2026-03-03 | Phase 3 (all) | Organization — AlbumService, CollectionService, StoryService, TagController, playlists, smart collections, 80 tests |
| 2026-03-03 | Phase 4 (all) | AI features — AIProviderInterface, OpenAIProvider, AIService, ModerationService, ModerationController, ModerationQueue, AI settings, 101 tests |
| 2026-03-03 | Phase 5 (all) | Blocks & Frontend — 7 Gutenberg blocks, 5 Interactivity API stores, 5 shortcodes, template override system, admin stats dashboard |
| 2026-03-03 | Phase 6 (all) | Integrations — BuddyPress (activity, profile tab, group tab, notifications), webhook system (signed payloads, async delivery, settings UI) |
| 2026-03-05 | Phase 7 Step 42 | Access rules engine + grants tracking — AccessRulesService, AccessController (6 REST routes), manage_mvs_access cap, migration v2, 16 tests |
| 2026-03-05 | Phase 7 Step 43 | Signed URLs — SignedUrlService (HMAC-SHA256, TTL, range requests), SignedUrlController (2 routes), mvs_media_response filter, 13 tests |
| 2026-03-05 | Phase 7 Step 44 | Lock overlay + payment bridges — lock-overlay block, PaymentBridgeService, CheckoutController (3 routes), code redemption, 11 tests |
| 2026-03-06 | Phase 7 Step 45 | Watermarking Pro stub — WatermarkService with filter hooks, preview_url in REST responses, config/invalidation, 13 tests |
| 2026-03-06 | Phase 8 Step 46 | Security audit batch 1 — 12 OWASP fixes: 3 IDOR, 3 auth/authz, 2 SQLi, 2 XSS, 2 header injection. PrivacyService extended to albums/collections. 13 files, 154 tests |
| 2026-03-06 | Phase 8 Step 46b | Security audit batch 2 — 8 fixes: upload dir protection (.htaccess), server-side filesize, EXIF GPS stripping, extension blocklist, double-extension block, path traversal containment, API key masking, webhook secret type. 5 files |
| 2026-03-06 | Full audit | Comprehensive audit (steps 1-46) — 10 critical fixes: EXIF GPS dead code, mvs_reaction_added hook wiring, deferred ModerationService loading, SignedUrlController privacy check, timing-safe code comparison, shortcode path allowlist, moderation count caching, webhook media.updated/purchased hooks, error output escaping, webhook secret masking. 10 files |
| 2026-03-06 | Phase 8 Steps 47a-e | Frontend functional — assets/css/frontend.css (450+ lines), wp_enqueue_scripts hook, block CSS for all 8 blocks (style.css + import + rebuild), lock-overlay build fix, social UI on media-single template (reactions, comments, favorites, share via REST API), assets/js/media-single.js (safe DOM methods). 154 tests. |
| 2026-03-06 | Phase 8 Steps 47f-50 | CacheService (wp_cache with invalidation hooks), WP-CLI (7 commands: stats, migrate, prune-views, cleanup-expired, reindex, cache-flush, moderation-stats), rtMedia import tool (batch import with album mapping), wp.org prep (readme.txt, .distignore, uninstall.php, languages/). Removed dead stubs (MonetizationService, TranscodeService). 154 tests. |
