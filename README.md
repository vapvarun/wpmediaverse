# WPMediaVerse

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue?logo=wordpress)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF?logo=php)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![BuddyPress](https://img.shields.io/badge/BuddyPress-Compatible-orange)](https://buddypress.org/)
[![Gutenberg](https://img.shields.io/badge/Gutenberg-8%20Blocks-purple)](https://developer.wordpress.org/block-editor/)
[![REST API](https://img.shields.io/badge/REST%20API-40%2B%20Endpoints-red)](https://developer.wordpress.org/rest-api/)

**A multi-purpose WordPress media platform** for building photo galleries, social media hubs, digital asset libraries, membership media portals, and community-driven content sharing sites — all from a single plugin.

**Built by** [vapvarun](https://github.com/vapvarun) & [Wbcom Designs](https://wbcomdesigns.com/)

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
- Storage driver pattern — local default, extensible to **S3**, **BunnyCDN**, or custom via filter
- Organized file storage in `wp-content/uploads/wpmediaverse/`

### Media Organization
- **Albums** — Ordered collections with cover images, drag-to-reorder
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
- Signed URLs with HMAC-SHA256 for time-limited, gated media delivery
- Range request support for video/audio streaming through signed URLs
- Lock overlay block with blurred preview for paywalled content
- Payment bridge with hooks for Stripe/WooCommerce integration

### Social Layer
| Feature | Details |
|---------|---------|
| **Reactions** | 6 types: like, love, haha, wow, sad, angry — one per user per media |
| **Comments** | Threaded via WP comment system, with mention support |
| **Favorites** | Bookmark media into personal collections |
| **Mentions** | @mention users in comments — triggers notifications |
| **Sharing** | Generate share links for Facebook, Twitter, LinkedIn, email |
| **Stats** | Views, downloads, reactions — per-media and per-user aggregation |
| **Activity** | BuddyPress activity stream with thumbnails and lightbox |

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
- Activity media attachment — Photo/Video button in "What's New" form
- Multi-image grid layout in activity stream (Facebook-style)
- Instagram-style lightbox on activity images (reactions, comments, favorites, share)
- BP notifications for reactions, comments, and mentions
- Media count badge on profile tab ("Media 49")
- Activity filter dropdown includes Media Uploads

### Gutenberg Blocks

8 blocks, all powered by the **WordPress Interactivity API** — zero legacy JavaScript:

| Block | Slug | Description |
|-------|------|-------------|
| Media Upload | `mvs/media-upload` | Drag & drop file uploader with REST integration |
| Media Grid | `mvs/media-grid` | Filterable grid with lightbox and lazy loading |
| Media Player | `mvs/media-player` | Video/audio player with view tracking |
| Album Viewer | `mvs/album-viewer` | Album display with ordered items |
| Story Viewer | `mvs/story-viewer` | Instagram-style stories carousel |
| Media Stats | `mvs/media-stats` | User stats dashboard cards |
| Explore Feed | `mvs/explore-feed` | Public explore feed with search and filters |
| Lock Overlay | `mvs/lock-overlay` | Paywall overlay with blurred preview + unlock prompt |

### Shortcodes

Drop media features into any page or widget:

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
```

### Template Override System

Copy templates to your theme for full customization:

```
your-theme/wpmediaverse/
├── media-single.php     Single media item (social UI, owner actions)
├── album.php            Single album display
├── explore.php          Explore/archive page (search + tag cloud)
└── dashboard.php        User dashboard (My Media / Albums / Favorites)
```

### REST API

40+ endpoints under `mvs/v1/` for full headless/decoupled usage:

| Endpoint Group | Routes | Methods |
|----------------|--------|---------|
| Media | `/media`, `/media/{id}`, `/media/{id}/view`, `/me/media` | GET, POST, PUT, DELETE |
| Albums | `/albums`, `/albums/{id}`, `/albums/{id}/items`, `/albums/{id}/reorder` | GET, POST, PUT, DELETE |
| Collections | `/collections`, `/collections/{id}`, `/collections/{id}/rules` | GET, POST, PUT, DELETE |
| Reactions | `/media/{id}/reactions` | GET, POST, DELETE |
| Comments | `/media/{id}/comments`, `/media/{id}/comments/{cid}` | GET, POST, DELETE |
| Favorites | `/media/{id}/favorite`, `/me/favorites` | GET, POST, DELETE |
| Tags | `/tags`, `/tags/cloud`, `/tags/merge`, `/tags/{id}` | GET, POST, PUT |
| Stats | `/media/{id}/stats`, `/me/stats` | GET |
| Access Rules | `/media/{id}/rules`, `/media/{id}/grant`, `/me/grants` | GET, POST, DELETE |
| Signed URLs | `/media/{id}/signed-url`, `/serve` | GET |
| Checkout | `/checkout`, `/checkout/redeem`, `/media/{id}/pricing` | GET, POST |
| Moderation | `/moderation`, `/moderation/counts`, `/moderation/{id}/approve` | GET, POST |
| Bulk | `/media/bulk` | POST |

All endpoints support proper authentication (nonce + cookie), validation, and error handling.

### Admin Dashboard

| Page | Description |
|------|-------------|
| **Overview** | At-a-glance stats (media count, albums, pending, total views), quick action links, recent uploads, system status |
| **Settings** | 5 tabs: General, Display (grid/pagination/thumbnails), Permissions (role x capability matrix), AI & Moderation, Webhooks |
| **Moderation** | Review flagged/pending media with approve/reject, AI analysis results, flag pills |
| **Stats** | Top media by views, reactions summary, AI usage metrics |

### WP-CLI Commands

```bash
wp mvs stats                  # Media statistics overview
wp mvs migrate                # Run pending database migrations
wp mvs prune-views            # Clean old view tracking records
wp mvs cleanup-expired        # Remove expired stories and grants
wp mvs reindex                # Rebuild the media index table
wp mvs cache-flush            # Clear all plugin caches
wp mvs moderation-stats       # Moderation queue counts
wp mvs import-rtmedia         # Import from rtMedia (use --dry-run first)
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
1. Download the latest release
2. Upload to **Plugins > Add New > Upload Plugin**
3. Activate

### From Source
```bash
cd wp-content/plugins/
git clone https://github.com/vapvarun/wpmediaverse.git
cd wpmediaverse
composer install
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
│   ├── Core/            Bootstrap, ServiceContainer, Migrator
│   ├── PostTypes/       3 CPTs: mvs_media, mvs_album, mvs_collection
│   ├── Taxonomies/      2 taxonomies: mvs_tag, mvs_category
│   ├── Services/        Upload, Storage, AI, Moderation, Stats, Cache, Album
│   ├── Social/          Reactions, Comments, Favorites, Mentions, Shares
│   ├── REST/            11 controllers, 40+ routes
│   ├── Capabilities/    15 capabilities across 5 roles
│   ├── Integrations/    BuddyPress, Webhooks
│   ├── Admin/           Overview, Settings, Stats, Moderation Queue
│   └── CLI/             WP-CLI commands
├── src/blocks/          8 Gutenberg blocks (JSX + Interactivity API)
├── templates/           4 overridable frontend templates
├── assets/
│   ├── css/             Frontend + admin stylesheets
│   └── js/              BP activity media attachment
└── languages/           i18n ready
```

### Design Principles
- **Service Container** — Lazy-loaded dependencies, no singletons or globals
- **PSR-4 Autoloading** — Clean namespace mapping via Composer
- **Custom Tables** — 9 tables for fast queries without postmeta JOINs
- **Interactivity API** — 100% modern WordPress frontend, zero legacy JS
- **Driver Pattern** — Swap storage backends without changing core code
- **Hook-First** — Filter/action hooks throughout for extensibility
- **Privacy by Default** — EXIF stripping, signed URLs, configurable access

### Custom Database Tables

| Table | Purpose |
|-------|---------|
| `mvs_reactions` | Media reactions (6 types, one per user per media) |
| `mvs_favorites` | User bookmarks with optional collection |
| `mvs_media_views` | Per-user view tracking |
| `mvs_media_stats` | Aggregated stats (views, reactions, downloads) |
| `mvs_access_rules` | Fine-grained access control rules |
| `mvs_access_grants` | User grants with expiration support |
| `mvs_mentions` | @mention records linking users to media/comments |
| `mvs_album_items` | Album-media relationships with position ordering |
| `mvs_media_index` | Denormalized index for fast explore/feed queries |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.5+ |
| PHP | 7.4+ |
| BuddyPress | 12.0+ (optional) |
| OpenAI API | GPT-4 Vision (optional, for AI moderation) |

---

## Frequently Asked Questions

**Does this require BuddyPress?**
No. WPMediaVerse works standalone. BuddyPress features (activity, profile/group tabs, friend privacy, notifications) activate automatically when BP is detected.

**Can I use this for a membership site?**
Yes. Use access rules (role, capability, or purchase-based) combined with signed URLs and the lock overlay block to gate premium media content.

**What file types are supported?**
Images (jpg, png, gif, webp, svg), video (mp4, webm, mov), audio (mp3, wav, ogg, flac), and documents (pdf, doc, zip). Configurable via settings.

**Can I import from rtMedia?**
Yes. Use `wp mvs import-rtmedia --dry-run` to preview, then run without `--dry-run` to import. Handles media, albums, and album-media relationships.

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

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

**Made with care by [Wbcom Designs](https://wbcomdesigns.com/)** — Building BuddyPress & WordPress community solutions since 2016.
