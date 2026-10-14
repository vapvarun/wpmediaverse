# MediaVerse

A media platform for WordPress with albums, reactions, comments, direct messaging, AI moderation and optional BuddyPress integration. Media lives in its own database tables, not in `wp_posts`.

## What it does

**Uploads and library**
- Photo, video and audio uploads with a MIME allowlist, a dangerous-extension blocklist, and SHA-256 duplicate detection.
- Location data is stripped from photos by default, and thumbnails are generated at several sizes. WebP and AVIF copies can be generated (AVIF is off by default).
- Full-text search across the library, tags and categories, a tag merge tool and bulk actions in the admin.
- Media is stored in 23 dedicated, indexed tables (`mvs_*`), so media queries do not touch posts, pages or products.

**Organisation**
- Albums with a cover and ordered items, including audio playlists.
- Rule-based collections, public or members-only.
- An Explore feed with filters by tag, album, member and media type, and a lightbox with reactions, comments, favorites and sharing.

**Privacy and access**
- Per-item privacy levels including public, members, logged-in, friends, group, space, private, direct message and custom.
- Signed, time-limited media URLs for gated media.

**Social**
- Six reaction types, threaded comments, favorites, @mentions, follows, share links, abuse reports and member blocklists.
- Direct messaging with text, media and voice messages, read state and typing indicators.
- In-app notifications and push notification device registration for mobile apps.

**Moderation**
- AI moderation with OpenAI: scan uploads, then flag, hide or reject by score, with a monthly budget and an admin moderation queue. Other providers can be added through the `mvs_ai_providers` hook, and Pro adds Google Vision, AWS Rekognition and Claude.

**Community and integrations**
- BuddyPress and BuddyBoss: media and album tabs on profiles and groups, photo and video uploads in the activity form, and notifications. BuddyPress is optional.
- Outbound webhooks signed with HMAC-SHA256.
- WordPress personal data export and erase support.
- The Integrations screen can install companion plugins: BuddyNext, Jetonomy, WB Gamification, Learnomy, WP Career Board and Listora.

## Who it is for

Community sites, membership sites, portfolios and any WordPress site where members upload and share media. Developers can build headless or mobile front ends on the REST API.

## Where to get it

- Product site: [wpmediaverse.com](https://wpmediaverse.com)
- Download: [wbcomdesigns.com/downloads/mediaverse/](https://wbcomdesigns.com/downloads/mediaverse/)
- MediaVerse Pro: [wbcomdesigns.com/downloads/mediaverse-pro/](https://wbcomdesigns.com/downloads/mediaverse-pro/)

MediaVerse is not listed on WordPress.org.

## Documentation

- [Documentation on wpmediaverse.com](https://wpmediaverse.com/docs/)
- [Documentation hub](https://docs.wbcomdesigns.com/products/mediaverse/)
- [Release notes](https://wbcomdesigns.com/release-notes/mediaverse/)

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Download the plugin from the [download page](https://wbcomdesigns.com/downloads/mediaverse/).
2. In WordPress go to **Plugins > Add New > Upload Plugin**, upload the zip and activate it. Activation creates the Explore, Dashboard and Upload pages.
3. Open **MediaVerse > Settings** (`admin.php?page=mvs-settings`) to set upload limits, privacy defaults, storage and AI moderation.
4. Add the blocks or shortcodes to your pages, or use the pages created on activation.

## For developers

**Identifiers**

| Item | Value |
|---|---|
| PHP namespace | `WPMediaVerse` |
| Function, option and hook prefix | `mvs_` |
| Text domain | `wpmediaverse` |
| Database tables | `{wpdb prefix}mvs_*` |
| REST namespace | `mvs/v1` |
| Theme template directory | `{your-theme}/wpmediaverse/` |

**Blocks** (8, registered by `Blocks\BlockRegistrar`, under the `mvs/` namespace): `media-upload`, `media-grid`, `media-player`, `album-viewer`, `media-stats`, `explore-feed`, `member-photos` and `pdf-viewer`.

**Shortcodes** (14): `[mvs_gallery]`, `[mvs_upload]`, `[mvs_album]`, `[mvs_player]`, `[mvs_stats]`, `[mvs_dashboard]`, `[mvs_collection]`, `[mvs_profile_edit]`, `[mvs_documents]`, `[mvs_explore_feed]`, `[mvs_lock_overlay]`, `[mvs_member_photos]`, `[mvs_pdf_viewer]` and `[mvs_usage_history]`.

**REST API.** Namespace `mvs/v1`, with controllers for media, albums, collections, comments, reactions, favorites, follows, tags, profiles, users, notifications, devices, reports, moderation, signed URLs, stats, activity, messaging and auth. See the [REST API guide](https://wpmediaverse.com/docs/developer-guide/rest-api/).

**Hooks.** Filters and actions use the `mvs_` prefix: [hooks and filters](https://wpmediaverse.com/docs/developer-guide/hooks-filters/). Storage drivers and AI providers are pluggable: [custom storage drivers](https://wpmediaverse.com/docs/developer-guide/custom-storage-drivers/).

**Template overrides.** Copy a template into `{your-theme}/wpmediaverse/`. See [template overrides](https://wpmediaverse.com/docs/developer-guide/template-overrides/).

**WP-CLI.** The `wp mvs` command has these subcommands: `stats`, `migrate`, `prune-views`, `cleanup-expired`, `reindex`, `cache-flush`, `backfill-activity-thumbnails`, `faststart`, `regenerate-thumbnails`, `moderation-stats`, `sync-activity-privacy`, `migrate-storage`, `cloud-thumbs-backfill`, `backfill-placeholder-color`, `cleanup-local`, `relocalize-private`, `optimize`, `optimize-bulk` and `repair-storage`. See the [WP-CLI guide](https://wpmediaverse.com/docs/developer-guide/wp-cli/).

**Build from source**

```bash
npm install
npm run build        # blocks, built into build/blocks
npm run start        # watch mode
npm run lint:js
npm run lint:css
npm run test:e2e     # Playwright
composer ci          # local CI runner
```

## Support and licence

- Support: [wbcomdesigns.com/support/](https://wbcomdesigns.com/support/)
- Licence: GPL-2.0-or-later. See [LICENSE](LICENSE).

Built by [Wbcom Designs](https://wbcomdesigns.com) and [vapvarun](https://github.com/vapvarun).
