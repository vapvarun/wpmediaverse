=== WPMediaVerse ===
Contributors: vapvarun, wbcomdesigns
Tags: media, gallery, buddypress, social media, albums
Requires at least: 6.5
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The media layer your community site is missing. Custom database tables, AI moderation, and a full social layer — without requiring BuddyPress.

== Description ==

**[Try Live Demo](https://app.instawp.io/launch?s=wpmediaverse&d=v2)** | **[Get Pro](https://store.wbcomdesigns.com/wpmediaverse-pro/)** | **[Documentation](https://store.wbcomdesigns.com/wpmediaverse/docs/)**

WPMediaVerse is a complete media platform for WordPress — built on custom database tables, not wp_posts. Your community gets photo uploads, albums, reactions, comments, follows, direct messaging, AI moderation, and a full lightbox experience. Your site stays fast no matter how many uploads come in.

**Why WPMediaVerse?**

Every other WordPress media plugin (rtMedia, MediaPress, BuddyBoss Media) stores uploads in wp_posts. On active communities, that table grows into tens of thousands of mixed rows. WPMediaVerse uses three dedicated, indexed tables — media queries never touch your posts, pages, or products.

**What You Get (Free)**

* **Custom Table Architecture** — Three indexed tables keep media separate from WordPress core data
* **Media Uploads** — Drag & drop with MIME validation, EXIF stripping, duplicate detection, thumbnail generation
* **Albums & Collections** — Ordered albums with cover images, smart collections with auto-curation rules
* **Social Layer** — Reactions (6 types), threaded comments, favorites, @mentions, follow/unfollow, sharing
* **Direct Messaging** — Text and media messaging between members, no third-party service needed
* **AI Moderation** — OpenAI Vision scans uploads automatically. Flag, quarantine, or reject before they go public
* **Privacy Controls** — 6 levels per upload: public, members-only, friends-only, group, private, custom
* **Explore Feed** — Public media grid with filtering by tag, album, user, and media type
* **Lightbox** — Full-screen with reactions, comments, favorites, share, gallery navigation — no page reload
* **BuddyPress Integration** — Activity uploads (1-6 per post), profile/group media tabs, lightbox in feed
* **13 Gutenberg Blocks** — Media grid, upload, player, album viewer, explore feed, stories, and more
* **80+ REST API Endpoints** — 17 controllers covering every operation for headless/decoupled builds
* **8 WP-CLI Commands** — Bulk operations, migrations, cache management, moderation
* **8 Shortcodes** — Drop media features into any page or widget
* **Webhooks** — Outbound event webhooks with HMAC-SHA256 signing via Action Scheduler
* **GDPR** — Full data export and erasure via WordPress privacy tools

**Pro Adds**

* 5 layout modes (Grid, Instagram, Pinterest, Flickr, Dribbble)
* Photo Challenges, 1v1 Battles, Tournament Brackets
* Points, Streaks, Boosts gamification engine
* S3 and BunnyCDN cloud storage drivers
* Video transcoding with HLS adaptive streaming
* Auto-captions via Whisper AI
* Per-user storage quotas (MemberPress, WooCommerce, PMPro integration)
* Voice messages, read receipts, typing indicators in DMs
* Google Vision + AWS Rekognition moderation
* Migration importers (rtMedia, MediaPress, BuddyBoss)

**For Developers**

* PSR-4 architecture with service container and lazy-loaded dependencies
* 80+ action and filter hooks for extensibility
* Template override system — copy to your theme and customize
* AI provider abstraction — bring your own provider
* Storage driver pattern — local, S3, BunnyCDN, or custom
* WordPress Interactivity API — zero legacy JavaScript

== Installation ==

1. Upload `wpmediaverse` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **MediaVerse > Settings** to configure upload limits, AI, and privacy defaults
4. Use the Gutenberg blocks or shortcodes to add media features to your pages

== Frequently Asked Questions ==

= Does this require BuddyPress? =

No. WPMediaVerse works as a standalone plugin. BuddyPress integration (activity feed, profile tabs, friend-based privacy) activates automatically when BuddyPress is detected.

= What AI providers are supported? =

OpenAI Vision (GPT-4) is included. Additional providers can be registered via the `mvs_ai_providers` action hook.

= Can I override the templates? =

Yes. Copy any template from `wpmediaverse/templates/` to `your-theme/wpmediaverse/` and customize it.

= How do I import from rtMedia? =

Use the WP-CLI command: `wp mvs import-rtmedia`. Run with `--dry-run` first to preview.

= What are the shortcodes? =

* `[mvs_gallery]` — Media grid
* `[mvs_upload]` — Upload form
* `[mvs_album id="123"]` — Album viewer
* `[mvs_player id="456"]` — Media player
* `[mvs_stats]` — Stats dashboard
* `[mvs_dashboard]` — User dashboard
* `[mvs_collection]` — Collection display
* `[mvs_profile_edit]` — Profile editor

== Screenshots ==

1. **Explore Page** — Instagram-style media grid with search and tag cloud filtering.
2. **Dashboard** — User media management with albums, favorites, and collections tabs.
3. **Single Media** — Full media view with reactions, comments, favorites, and sharing.
4. **Album View** — Album page with cover image, item grid, and sequential playback.
5. **Admin Overview** — At-a-glance stats, quick links, recent uploads, and system status.
6. **Settings** — Tabbed settings with upload limits, display options, permissions, and AI config.
7. **BuddyPress Profile** — Media tab on user profiles with album support.
8. **Moderation Queue** — AI-flagged media review with approve/reject workflow.

== Changelog ==

= 1.0.0 =
* Initial release — complete media platform for WordPress
* Custom database tables (mvs_media_index, mvs_media_meta, mvs_media_stats) — zero wp_posts pollution
* 38 features across core platform, social layer, BuddyPress integration, and developer tools
* 6-level privacy system with BuddyPress-aware fallback
* Full social layer: reactions (6 types), threaded comments, favorites, follows, DMs, @mentions, sharing
* AI moderation with OpenAI Vision — flag, quarantine, or reject uploads automatically
* 13 Gutenberg blocks powered by WordPress Interactivity API
* 8 shortcodes for embedding media features anywhere
* BuddyPress integration: activity uploads (1-6 per post), profile/group media tabs, lightbox in activity feed
* 80+ REST API endpoints across 17 controllers
* 8 WP-CLI commands for bulk operations and maintenance
* Outbound webhooks with HMAC-SHA256 signing via Action Scheduler
* Template override system
* GDPR data export and erasure

== Upgrade Notice ==

= 1.0.0 =
Initial release.
