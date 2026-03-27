=== WPMediaVerse ===
Contributors: vapvarun, wbcomdesigns
Tags: media, gallery, buddypress, social media, albums
Requires at least: 6.5
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete media platform for WordPress with albums, social features, AI moderation, and BuddyPress integration.

== Description ==

WPMediaVerse transforms WordPress into a complete media sharing platform. Upload images, videos, audio, and documents with privacy controls, social features, and AI-powered moderation.

**Core Features**

* **Media Upload** — Drag & drop uploader with MIME validation, EXIF stripping, and duplicate detection
* **Albums & Playlists** — Create ordered collections with cover images and playlist support
* **Smart Collections** — Auto-curated collections based on rules (type, tag, date range)
* **Privacy Controls** — 6 privacy levels (public, logged-in, friends, group, private, custom) with BuddyPress fallback
* **Social Features** — Reactions (6 types), threaded comments, favorites, @mentions, sharing
* **AI Moderation** — Automatic content analysis via OpenAI Vision with approve/reject queue
* **Gutenberg Blocks** — 8 blocks including media grid, player, upload, album viewer, stories, and explore feed
* **Shortcodes** — 8 shortcodes for embedding media features anywhere
* **Template System** — Override templates from your theme (media-single, album, explore)
* **Monetization** — Access rules, signed URLs, payment bridges, lock overlay
* **BuddyPress Integration** — Activity feed, profile/group media tabs, notifications
* **Webhooks** — Outbound event webhooks with HMAC-SHA256 signing
* **WP-CLI** — 8 commands for management and maintenance
* **REST API** — 40+ endpoints for full headless/decoupled usage

**For Developers**

* Clean PSR-4 architecture with service container
* Comprehensive REST API with proper authentication and validation
* Filter/action hooks throughout for extensibility
* Template override system
* AI provider abstraction (bring your own provider)
* Storage driver pattern (local default, extensible to S3/CDN)

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
* Initial release
* Core media management with 3 custom post types
* 9 custom database tables for performance
* 6-level privacy system with BuddyPress support
* Social features: reactions, comments, favorites, mentions, sharing
* AI moderation with OpenAI Vision
* 8 Gutenberg blocks with Interactivity API
* 8 shortcodes
* Template override system
* Monetization: access rules, signed URLs, payment bridges
* BuddyPress integration: activity, profile, groups, notifications
* Webhook system with HMAC signing
* WP-CLI commands
* rtMedia import tool
* 40+ REST API endpoints

== Upgrade Notice ==

= 1.0.0 =
Initial release.
