# WPMediaVerse

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue?logo=wordpress)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF?logo=php)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.3.0-brightgreen)](https://github.com/vapvarun/wpmediaverse/releases/tag/v1.3.0)
[![BuddyPress](https://img.shields.io/badge/BuddyPress-Compatible-orange)](https://buddypress.org/)
[![Gutenberg](https://img.shields.io/badge/Gutenberg-12%20Blocks-purple)](https://developer.wordpress.org/block-editor/)
[![REST API](https://img.shields.io/badge/REST%20API-80%2B%20Endpoints-red)](https://developer.wordpress.org/rest-api/)

**The media layer your community site is missing.** Custom database tables, AI moderation, direct messaging, and a full social layer — without requiring BuddyPress. The only WordPress media plugin that doesn't store uploads in `wp_posts`.

**Built by** [vapvarun](https://github.com/vapvarun) & [Wbcom Designs](https://wbcomdesigns.com/)

[Try Live Demo](https://app.instawp.io/launch?s=wpmediaverse&d=v2) · [Download Free](https://store.wbcomdesigns.com/wpmediaverse/) · [Get Pro](https://store.wbcomdesigns.com/wpmediaverse-pro/) · [Documentation](https://store.wbcomdesigns.com/wpmediaverse/docs/) · [Announcement](https://vapvarun.com/why-i-built-wpmediaverse/)

> **Why WPMediaVerse?** Every other WordPress media plugin (rtMedia, MediaPress, BuddyBoss Media) stores uploads in `wp_posts`. On active communities, that table grows into tens of thousands of mixed rows. WPMediaVerse uses 21 dedicated, indexed MySQL tables — media queries never touch your posts, pages, or products. Performance stays predictable at 100,000+ media items.

---

## What's New in 1.3.0 (May 2026)

Major release. Automatic image optimization, modern WebP and AVIF formats, cloud storage migration tools, FULLTEXT search at scale, security hardening, and dozens of fixes. Bundles all work from the unreleased 1.2.1 and 1.2.2 branches.

**Image performance jump**

- New: Automatic image optimization on every upload. JPEGs, PNGs, and GIFs are re-encoded for smaller file size with hidden camera data stripped. Most uploads drop 10 to 30 percent without any visible quality change.
- New: WebP image format support. Every uploaded image gets a second copy in WebP, around 25 to 35 percent smaller than JPEG. Modern browsers automatically use the smaller file; older browsers keep using the original.
- New: AVIF image format support for even smaller files. AVIF is roughly 30 percent smaller than WebP again. Opt in from Settings, Storage tab.
- New: Frontend serves WebP across every surface. Explore grid, BuddyPress activity feed, dashboard cards, single-media view, and the lightbox all swap in WebP automatically when the visitor's browser supports it.
- New: Private images now also serve WebP and AVIF. Access-rule-protected media gets the same modern-format speed boost as public media.
- New: WP-CLI commands to optimize existing media. Run `wp mvs optimize 123` on one item or `wp mvs optimize-bulk` across the whole library. Resume-safe if interrupted.
- New: Compatible with EWWW, Imagify, Smush, and ShortPixel through a single extension point.

**Storage management**

- New: Cloud storage migration tools. Move existing local media to S3 or BunnyCDN in batches, then clean up the local copies after verification. New WP-CLI command `wp mvs migrate-storage --from=local --to=bunnycdn` handles the bulk move with idempotent resume support.
- New: Direct CDN URLs for public media. New setting on the Storage tab: when enabled on a cloud-storage install, public images load directly from your CDN edge instead of being proxied through WordPress.

**Admin + tools**

- New: New Optimization column on the All Media admin listing. Shows percent saved per file. Row actions Optimize and Details. The Details page shows everything stored about a file with inline buttons to re-optimize, repair thumbnails, or move to trash.
- New: Default video poster for videos without an embedded cover image. Previously these showed a black frame. Now they render a clean placeholder.
- New: Audio card design. Audio with embedded cover art shows the cover; audio without art shows a unique waveform image generated from the file id.
- New: Filename strategy setting. New uploads can be saved with hashed filenames or sanitized original names.
- New: Faster search at scale. Explore search now uses a FULLTEXT index for 3+ character queries, returning results across 100,000+ media items in milliseconds.
- New: Opt-in usage telemetry to help us prioritize features. Default off. No personal data, file names, or content ever leaves your site.

**Security + stability**

- Fix: BuddyPress activity privacy now follows media privacy. Previously a non-public media uploaded to a BP activity would leak the activity card (composer text, timestamp, author) to the public stream.
- Fix: REST per-page hardening across all list endpoints. Callers can no longer request unbounded result sets to slow the site.
- Fix: Most MP4 video uploads now generate proper poster images on managed WordPress hosts.
- Fix: Cloud storage uploads now generate thumbnails reliably.
- Fix: Animated GIFs stay animated through the optimization pipeline.
- Fix: PHP 8.4 and PHP 8.5 compatibility — all deprecation warnings cleared.

[Full release notes](https://github.com/vapvarun/wpmediaverse/releases/tag/v1.3.0) · [Changelog](#changelog) (below)

---

## What's New in 1.2.0 (May 2026)

The "complete the experience" release. Every UX gap from 1.1.x closed before shipping. WCAG 2.1 AA pass on every customer-facing surface.

- New: Member Photos block and shortcode (`mvs/member-photos`, `[mvs_member_photos]`). Auto-detects whose photos to render: explicit `userId`, BuddyPress displayed user, post author, or current user.
- New: PDF Viewer block and shortcode (`mvs/pdf-viewer`, `[mvs_pdf_viewer]`). Browser-native PDF embed with configurable height and toolbar toggle. Five distinct empty states.
- New: Sort options on Media Grid: Most Popular, Most Viewed, Most Reactions, Random, plus asc/desc and per-author filter.
- New: Search autocomplete on Explore. Debounced 250 ms, top-8 title matches, full keyboard navigation.
- New: Lightbox Download and Fullscreen. Toolbar buttons plus `F` keyboard shortcut. Download count tracked, rate-limited at 30 per minute per user.
- New: Per-media Edit modal. Cog icon on your own dashboard cards opens a prefilled modal for title, description, privacy, and allow-download. Live update without reload.
- New: Open Graph and Twitter Card meta on every `/media/{slug}/` so links unfurl correctly in Slack, Twitter, LinkedIn, and Discord.
- New: Popular tag pills in the upload modal, per-tile filename and remove buttons, and an audio-fallback icon.
- New: Bulk Actions on All Media. Multi-select plus a context-aware action menu (Restore + Delete permanently in the Trash filter, otherwise Move to Trash).
- New: Chat panel visibility setting. Choose Everywhere, WPMediaVerse pages only, BuddyPress pages only, or Disabled. Filter `mvs_should_render_chat_panel` for fine-grained overrides.
- New: Global "Allow downloads" toggle under Media Display. Single switch hides the lightbox button site-wide and refuses the REST endpoint.
- New: 6-reaction lightbox bar gains `aria-label` and `aria-pressed`. Toolbar buttons all gain `aria-label` and `:focus-visible` outlines.
- New: Block render forms get `aria-label` (placeholder is not a label, per WCAG).
- New: `Core\SettingsHelper` canonical static accessor for paired-plugin settings reads.
- New: `SettingsContractTest` catches register_setting drift at unit-test time instead of at customer save-time.
- New: Block standard alignment. All 9 registered Free blocks share Spacing, Border, Shadow, and Visibility panels with Pro and wbcom-essential.
- New: `BaseBPTabIntegration` extracted. A single bug fix on either BuddyPress tab now propagates to both.
- Fix: BuddyPress notification dedup. Notifications mirror to the BP bell only, no double-render on the dashboard.

[Full release notes](https://github.com/vapvarun/wpmediaverse/releases/tag/v1.2.0) · [Changelog](#changelog) (below)

---

## What Can You Build?

WPMediaVerse is designed to power **any media-centric WordPress site**:

| Use Case | How |
|----------|-----|
| **Photo Sharing Community** | BuddyPress profiles + groups + reactions + albums |
| **Portfolio Gallery** | Media grid blocks + albums + privacy controls |
| **Digital Asset Library** | Upload + organize + search + access rules |
| **Membership Media Portal** | Signed URLs + lock overlay + payment bridges |
| **Client File Delivery** | Access codes + signed URLs + download tracking |
| **Stock Photo Marketplace** | Watermark preview + purchase gates + checkout |
| **Team Collaboration** | Group media + albums + activity stream + notifications |
| **Social Intranet** | BuddyPress + reactions + comments + mentions + favorites |
| **Learning Platform Media** | Albums as course media + privacy per role |
| **Video/Audio Hub** | Media player block + view tracking + playlists |

---

## Feature Overview

### Upload & Storage
- Drag & drop uploader with MIME validation and size limits
- Automatic EXIF GPS stripping for privacy protection
- Duplicate detection via file hash
- Extension blocklist and double-extension blocking
- Storage driver pattern — local default, extensible to **S3**, **BunnyCDN**, or custom via filter (Pro)
- Organized file storage in `wp-content/uploads/wpmediaverse/`

### Media Organization
- **Albums** — Ordered collections with cover images, drag-to-reorder, auto-cover fallback
- **Playlists** — Audio albums with sequential playback
- **Smart Collections** — Auto-curated based on rules (type, tag, date range, author)
- **Stories** — Time-limited Instagram-style media with auto-cleanup via WP Cron
- **Tags & Categories** — Full taxonomy support with tag cloud, merge, rename, and autocomplete

### Privacy & Access Control
| Level | Description |
|-------|-------------|
| `public` | Visible to everyone |
| `loggedin` | Visible to logged-in users |
| `friends` | Visible to BuddyPress friends only |
| `group` | Visible to group members only |
| `private` | Visible to owner only |
| `custom` | Fine-grained access rules |

- Access rules engine supporting: **role**, **capability**, **membership**, **purchase**, **subscription**, **redemption code**
- **Signed URLs with HMAC-SHA256** for time-limited, gated media delivery — every thumbnail surface routes through signed URLs as of 1.1.3
- Range request support for video/audio streaming through signed URLs
- Lock overlay block with blurred preview for paywalled content
- Payment bridge with hooks for Stripe/WooCommerce integration

### Direct Messaging
- 1:1 private messaging between users
- Group conversations with multi-user participants
- Read receipts with per-user last-read state
- Message reactions, edit, delete (within configurable window)
- Media attachments via existing upload pipeline
- BP notification dispatch + REST polling transport

### Social Layer
| Feature | Details |
|---------|---------|
| **Reactions** | 6 types: like, love, haha, wow, sad, angry — one per user per media |
| **Comments** | Threaded via WP comment system, with mention support |
| **Favorites** | Bookmark media into personal collections |
| **Mentions** | @mention users in comments — triggers notifications |
| **Follows** | User-to-user follow relationships |
| **Sharing** | Generate share links for Facebook, Twitter, LinkedIn, email |
| **Stats** | Views, downloads, reactions — per-media and per-user aggregation |
| **Activity** | BuddyPress activity stream with thumbnails and lightbox |
| **Notifications** | In-app + BuddyPress notification queue |
| **Reports** | User-submitted abuse reports with moderator review |
| **Blocks** | User-to-user blocklist for DM/comment safety |

### AI-Powered Moderation
- **OpenAI Vision** (GPT-4) for automatic content analysis and tagging
- Provider abstraction — bring your own AI via `mvs_ai_providers` hook
- Budget tracking with monthly limits and cost-per-analysis controls
- Auto-actions: flag, hide, or reject based on moderation score
- Admin moderation queue with approve/reject workflow
- Async processing via **Action Scheduler** (sync fallback when unavailable)

### BuddyPress Integration

Deeply integrated with BuddyPress — all features activate automatically when BP is detected:

| Feature | Profile | Groups |
|---------|---------|--------|
| Media grid with upload | `/members/{user}/media/` | `/groups/{slug}/media/` |
| Albums with create | `/members/{user}/media/albums/` | `/groups/{slug}/media/albums/` |
| Single album with add media | `/members/{user}/media/albums/{slug}/` | `/groups/{slug}/media/albums/{slug}/` |
| Sub-tab navigation | Media \| Albums | Media \| Albums |
| Activity on upload | "uploaded a new photo: X" | "uploaded a new photo: X in the group Y" |

**Additional BP features:**
- Activity media attachment — Photo/Video button in "What's New" form (1-6 per post)
- Multi-image grid layout in activity stream (Facebook-style)
- Full-viewport lightbox on activity media — images, video player, audio player (reactions, comments, favorites, share)
- BP notifications for reactions, comments, mentions, and DMs
- Media count badge on profile tab ("Media 49")
- Activity filter dropdown includes Media Uploads

### Gutenberg Blocks

12 blocks, all powered by the **WordPress Interactivity API** — zero legacy JavaScript:

| Block | Slug | Description |
|-------|------|-------------|
| Media Upload | `mvs/media-upload` | Drag & drop file uploader with REST integration |
| Media Grid | `mvs/media-grid` | Filterable grid with lightbox and lazy loading |
| Media Player | `mvs/media-player` | Video/audio player with view tracking |
| Album Viewer | `mvs/album-viewer` | Album display with ordered items |
| Story Viewer | `mvs/story-viewer` | Instagram-style stories carousel |
| Media Stats | `mvs/media-stats` | User stats dashboard cards |
| Media Social | `mvs/media-social` | Social actions bar (reactions, comments, share) |
| Explore Feed | `mvs/explore-feed` | Public explore feed with search and filters |
| Explore View | `mvs/explore-view` | Explore page layout with browse modes |
| Dashboard View | `mvs/dashboard-view` | User dashboard (My Media, Albums, Favorites) |
| Shared UI | `mvs/shared-ui` | Lightbox shell (image/video/audio), FAB uploader, shared components |
| Lock Overlay | `mvs/lock-overlay` | Paywall overlay with blurred preview + unlock prompt |

12 more Pro blocks coming in 1.2.0 (Pro layout feeds + tournament/challenge/battle/leaderboard/compete-hub blocks). See [Roadmap](#-roadmap--120-in-development).

### Shortcodes

8 shortcodes — drop media features into any page or widget:

```
[mvs_gallery]                    Filterable media grid
[mvs_gallery columns="4" count="12" type="image" category="nature"]

[mvs_upload]                     Upload form
[mvs_upload max_files="10" show_privacy="true"]

[mvs_album id="123"]            Album viewer
[mvs_album id="123" columns="3" show_title="true"]

[mvs_player id="456"]           Media player
[mvs_player id="456" autoplay="false" loop="true"]

[mvs_stats]                      Stats dashboard
[mvs_stats views="true" downloads="true" reactions="true" top_media="5"]

[mvs_dashboard]                  User dashboard (My Media / Albums / Favorites)

[mvs_collection id="789"]       Smart collection viewer

[mvs_profile_edit]               In-place profile editor
```

### Template Override System

Copy templates to your theme for full customization:

```
your-theme/wpmediaverse/
├── media-single.php     Single media item (social UI, owner actions)
├── album.php            Single album display
├── explore.php          Explore/archive page (search + tag cloud)
├── dashboard.php        User dashboard (My Media / Albums / Favorites)
├── messages.php         Direct message inbox + thread view
└── shortcodes/          Per-shortcode template parts
```

### REST API

80+ endpoints across **18 controllers** under `mvs/v1/` for full headless/decoupled usage:

| Endpoint Group | Routes | Methods |
|----------------|--------|---------|
| Media | `/media`, `/media/{id}`, `/media/{id}/view`, `/me/media` | GET, POST, PUT, DELETE |
| Albums | `/albums`, `/albums/{id}`, `/albums/{id}/items`, `/albums/{id}/reorder` | GET, POST, PUT, DELETE |
| Collections | `/collections`, `/collections/{id}`, `/collections/{id}/rules` | GET, POST, PUT, DELETE |
| Reactions | `/media/{id}/reactions` | GET, POST, DELETE |
| Comments | `/media/{id}/comments`, `/media/{id}/comments/{cid}` | GET, POST, DELETE |
| Favorites | `/media/{id}/favorite`, `/me/favorites` | GET, POST, DELETE |
| Follows | `/users/{id}/follow`, `/me/following`, `/me/followers` | GET, POST, DELETE |
| Tags | `/tags`, `/tags/cloud`, `/tags/merge`, `/tags/{id}` | GET, POST, PUT |
| Stats | `/media/{id}/stats`, `/me/stats` | GET |
| Profiles | `/profiles/{username}`, `/me/profile` | GET, PUT |
| Notifications | `/me/notifications` | GET, PUT |
| Activity | `/activity`, `/activity/{id}/media` | GET, POST |
| Access Rules | `/media/{id}/rules`, `/media/{id}/grant`, `/me/grants` | GET, POST, DELETE |
| Signed URLs | `/media/{id}/signed-url`, `/serve` | GET |
| Moderation | `/moderation`, `/moderation/counts`, `/moderation/{id}/approve` | GET, POST |
| Bulk | `/media/bulk` | POST |
| Reports | `/media/{id}/report` | POST |
| Users | `/users`, `/users/{id}` | GET |

All endpoints support proper authentication (nonce + cookie), validation, rate limiting middleware, and error handling.

### Admin Dashboard

| Page | Description |
|------|-------------|
| **Overview** | At-a-glance stats (media count, albums, pending, total views), quick action links, recent uploads, system status |
| **Settings** | 5 tabs: General, Display (grid/pagination/thumbnails), Permissions (role x capability matrix), AI & Moderation, Webhooks |
| **Moderation** | Review flagged/pending media with approve/reject, AI analysis results, flag pills |
| **Stats** | Top media by views, reactions summary, AI usage metrics |
| **All Media** | Custom listing page with filters, search, bulk actions (replaces CPT edit.php) |
| **Log Viewer** | Internal error log with severity filter |
| **Setup Wizard** | First-run configuration walkthrough |

### WP-CLI Commands

```bash
wp mvs migrate                # Run pending database migrations
wp mvs prune-views            # Clean old view tracking records
wp mvs cleanup-expired        # Remove expired stories and grants
wp mvs reindex                # Rebuild the media index table
wp mvs cache-flush            # Clear all plugin caches
wp mvs moderation-stats       # Moderation queue counts
wp mvs import-rtmedia         # Import from rtMedia (use --dry-run first)
wp mvs stats                  # Media statistics overview
```

### Webhooks

Outbound event webhooks for integrating with external systems:

- Events: `media.uploaded`, `media.deleted`, `media.moderated`, `media.reaction`, `media.comment`, `media.purchased`
- HMAC-SHA256 signed payloads for verification
- Async delivery via Action Scheduler
- Configurable URLs and secrets via Settings > Webhooks

---

## Installation

### From ZIP
1. Download the [latest release](https://github.com/vapvarun/wpmediaverse/releases/latest)
2. Upload to **Plugins > Add New > Upload Plugin**
3. Activate

### From Source
```bash
cd wp-content/plugins/
git clone https://github.com/vapvarun/wpmediaverse.git
cd wpmediaverse
composer install
npm install && npm run build
```

### After Activation
1. Three pages are auto-created: **Explore Media**, **My Media**, **Upload Media**
2. Go to **MediaVerse > Settings** to configure upload limits, display, and permissions
3. (Optional) Add your OpenAI API key in **Settings > AI & Moderation**
4. (Optional) BuddyPress features activate automatically when BP is active

---

## Architecture

```
wpmediaverse/
├── includes/
│   ├── Core/             Bootstrap, ServiceContainer, Migrator, TemplateHelpers
│   ├── PostTypes/        Album + Collection custom post types
│   ├── Taxonomies/       mvs_tag, mvs_category
│   ├── Services/         Upload, Storage, AI, Moderation, Stats, Cache, Album, GDPR, Health
│   ├── Social/           Reactions, Comments, Favorites, Mentions, Shares, Follows, Activity, Notifications, Reports
│   ├── Messaging/        DM service, controllers, REST polling transport, notification listener
│   ├── REST/             18 controllers + RateLimiter middleware
│   ├── Repository/       MediaRepository (single owner of media data access)
│   ├── Capabilities/     MediaCapabilities (role x capability matrix)
│   ├── Integrations/     BuddyPress (7 focused classes), Webhooks
│   ├── Admin/            Overview, Settings (5 classes), Stats, Moderation, MediaList, LogViewer, SetupWizard
│   ├── Blocks/           BlockRegistrar
│   ├── Shortcodes/       8 shortcodes
│   └── CLI/              WP-CLI commands
├── src/blocks/           12 Gutenberg blocks (JSX + Interactivity API)
├── templates/            Overridable frontend templates (album, explore, dashboard, messaging, single)
├── assets/
│   ├── css/              Frontend + admin stylesheets
│   └── js/               Card builders, BP activity media attachment
└── languages/            i18n ready (POT regenerated each release)
```

### Design Principles
- **Service Container** — Lazy-loaded dependencies, no singletons or globals
- **PSR-4 Autoloading** — Clean namespace mapping via Composer
- **Custom Tables** — 21 indexed tables for fast queries without `wp_posts`/postmeta JOINs
- **Interactivity API** — 100% modern WordPress frontend, zero legacy JS
- **Driver Pattern** — Swap storage backends without changing core code (Pro)
- **Hook-First** — Filter/action hooks throughout for extensibility
- **Privacy by Default** — EXIF stripping, signed URLs, configurable access
- **REST-Only Frontend** — Zero `admin-ajax.php` calls from public-facing JS

### Custom Database Tables (21)

All prefixed with `{$wpdb->prefix}mvs_`. Defined in `includes/Core/Migrator.php`.

| Table | Purpose |
|-------|---------|
| `mvs_media_index` | Authoritative media record (no CPT dependency) |
| `mvs_media_meta` | Arbitrary key-value metadata for media |
| `mvs_media_views` | Per-user view tracking |
| `mvs_media_stats` | Aggregated media statistics |
| `mvs_reactions` | Emoji reactions on media |
| `mvs_favorites` | User favorites/bookmarks |
| `mvs_follows` | User-to-user follow relationships |
| `mvs_mentions` | @mention records |
| `mvs_activity` | Activity feed entries |
| `mvs_notifications` | User notification queue |
| `mvs_reports` | Content/user abuse reports |
| `mvs_blocks` | User block list |
| `mvs_access_rules` | Per-media access control rules |
| `mvs_access_grants` | Granted access tokens |
| `mvs_album_items` | Album-to-media mapping |
| `mvs_error_log` | Internal error/debug log |
| `mvs_conversations` | DM conversation threads |
| `mvs_conversation_participants` | Conversation membership |
| `mvs_messages` | Individual DM messages |
| `mvs_message_reactions` | Reactions on DM messages |
| `mvs_transactions` | Credit/monetization transactions |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.5+ |
| PHP | 7.4+ (tested on 8.1, 8.2, 8.3, 8.4) |
| BuddyPress | 12.0+ (optional) |
| OpenAI API | GPT-4 Vision (optional, for AI moderation) |

---

## Frequently Asked Questions

**Does this require BuddyPress?**
No. WPMediaVerse works standalone. BuddyPress features (activity, profile/group tabs, friend privacy, notifications) activate automatically when BP is detected.

**Can I use this for a membership site?**
Yes. Use access rules (role, capability, or purchase-based) combined with signed URLs and the lock overlay block to gate premium media content.

**What file types are supported?**
Images (jpg, png, gif, webp, svg), video (mp4, webm, mov), audio (mp3, wav, ogg, flac), and documents (pdf, doc, zip). Configurable via settings. PDF inline preview block coming in 1.2.0.

**Can I import from rtMedia?**
Yes. Use `wp mvs import-rtmedia --dry-run` to preview, then run without `--dry-run` to import. Handles media, albums, album-media relationships, and engagement (reactions, comments, views) as of 1.2.0.

**How do I add a custom AI provider?**
```php
add_action( 'mvs_ai_providers', function( $ai_service ) {
    $ai_service->register_provider( 'my-provider', new MyCustomProvider() );
});
```
Your provider must implement `WPMediaVerse\Services\AIProviderInterface`.

**How do I add a custom storage driver?**
```php
add_filter( 'mvs_storage_driver', function() {
    return new MyS3Driver();
});
```
Your driver must implement `WPMediaVerse\Services\StorageDriverInterface`.

---

## Changelog

### 1.1.3 — Apr 29, 2026

**New**
- **Facebook-style fullscreen lightbox** — full-viewport with full-resolution images. New "Lightbox Image Size" setting (Original / Large / Medium / Auto).
- Real video previews in grids — phone videos (MP4/MOV) get proper preview thumbnails extracted from the video itself; screen recordings without an embedded cover get a live first-frame browser preview.
- Activity images now have proper breathing room in BuddyPress activity cards.
- "Upload Page" admin setting (was missing in 1.1.2).

**Improved**
- **Every thumbnail flows through signed URLs** — album covers, grid items, lightbox previews, and BP activity media all consistently routed through the access-controlled signed-URL endpoint.
- Consistent grid layout regardless of media type (mixed photo/video/audio uploads have uniform card sizes).
- Lucide icon system across feeds, dashboards, lightbox, and BP activity stream.
- Album covers behave — auto-fallback to first photo, picking a cover persists.
- Trash icons only appear on owner's own profile/group tabs (never on public Explore/Albums/Collections).

**Fixed**
- Logged-in users can view shared media (privacy `loggedin` case was missing).
- Thumbnails always generate at all 3 sizes; small images backfilled from original.
- BP activity media renders reliably (two-layer recovery for stripped HTML).
- Lightbox Favorite button shows just one heart (no duplicated emoji prefix).
- Carry-over fixes from 1.1.2: 5-column grid renders 5 cols, Stats date filters update counts, new tags appear in tag cloud immediately, lightbox Share single icon, Favorite visible to all signed-in users.
- Demo data installer routes through real upload pipeline.

**Under the hood**
- Unified thumbnail pipeline shared by Free and Pro.
- Upload failures now visible to admins via error log instead of failing silently.
- Block editor blocks load correctly via WordPress Interactivity API on block-rendered pages.
- `shared-ui-frame.css` simplified by 160 lines after lightbox refactor (renamed from `shared-ui-shell.css` in 1.2.1).
- Hardened uninstall.

### 1.1.2 — Apr 22, 2026
- Fixed grid columns=5 rendering, Stats page date filters, tag cloud counts, lightbox Favorite/Share polish.
- New: Add Tag button, sortable Tag admin columns, mobile back buttons, 44×44 touch targets, iOS safe-area FAB, bottom-sheet modals, sticky single-media action bar, skeleton loaders, optimistic UI feedback, mobile tab snap-scroll, in-plugin Lucide icons, custom notification type filter.
- Fixed: tag bulk-delete error, tag pagination count, sort preserved through bulk actions, default Allowed File Types ticked on fresh install, album categories fully wired, demo cleanup removes seeded tags, per-upload privacy honored on every surface, full GDPR cascade on user delete.

### 1.1.1 — Apr 8, 2026
- Fixed single media interactivity (comments, reactions, favorites, follow, report).
- Signed URL serving wired across all media files.
- Anonymous lightbox view fixed (no more 401/403).
- Notification titles use mvs_media_index, not WordPress post title.
- Reaction counts properly sync to mvs_media_index on add/remove.
- Delete cascade cleans up all related rows.

### 1.1.0 — Apr 5, 2026
- Initial public release with full feature set.

→ [Full release history on GitHub](https://github.com/vapvarun/wpmediaverse/releases)

---

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. **Run `bash bin/ci-local.sh` before pushing** — it runs the same gates as CI (PHP lint matrix, PHPCS, PHPStan, duplicate-method sniff). Saves the round-trip to GitHub.
4. Commit your changes
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

### Local CI gate

```bash
bash bin/ci-local.sh           # full check
bash bin/ci-local.sh --quick   # default PHP only
bash bin/ci-local.sh --staged  # only against staged files
```

---

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

**Made with care by [Wbcom Designs](https://wbcomdesigns.com/)** — Building BuddyPress & WordPress community solutions since 2016.
