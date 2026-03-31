# WPMediaVerse

**A complete media platform for WordPress. Your users upload, share, and discover photos and video — without touching your post table.**

---

## Hero

### Headline
Your WordPress site deserves a real media platform.

### Subheadline
WPMediaVerse gives your community a full photo and video experience — with feeds, lightboxes, direct messages, and a moderation system — built entirely on custom database tables so your site stays fast no matter how many uploads come in.

### Primary CTA
Download Free — No Account Required

### Secondary CTA
See Pro Features

---

## The Problem With Every Other Media Plugin

Most WordPress media plugins were bolt-ons. They store uploaded photos as WordPress attachments in `wp_posts` — the same table your pages and products live in. A community of 500 active uploaders can push that table into the tens of thousands of rows, running full-table scans on every media query.

rtMedia, MediaPress, and BuddyBoss Media all work this way. It was the path of least resistance in 2012. It still is today.

WPMediaVerse was designed from scratch in 2025 to do it differently.

---

## Built Different: Custom Tables, Not wp_posts

Every photo, video, stat, and piece of metadata in WPMediaVerse lives in its own indexed table — completely separate from WordPress core data.

**What that means for you:**

- Your `wp_posts` table stays clean no matter how many uploads your community makes
- Media queries hit indexed columns (`user_id`, `album_id`, `created_at`, `status`) instead of running post-type filters across a bloated table
- Sites have run WPMediaVerse with 100,000+ media items without query degradation
- Deactivating the plugin leaves your WordPress installation exactly as it was

**The three core tables:**

| Table | What it stores |
|-------|---------------|
| `mvs_media_index` | Every uploaded file: path, type, privacy, owner, counts |
| `mvs_media_meta` | EXIF data, tags, custom fields, AI moderation results |
| `mvs_media_stats` | Per-item view counts, download counts, engagement data |

No orphaned attachment records. No `wp_postmeta` rows. No hidden CPT pollution.

---

## What You Get (Key Features)

### Fully Social Media Feed
Your users get a scrollable explore page, individual profile pages at `/media/@username/`, a personal dashboard at `/my-media/`, and a full lightbox experience — reactions, comments, favorites, sharing, gallery navigation — all without leaving the page.

### Five Layout Modes
Display media the way that fits your community. Grid layout is included free. Instagram (card feed), Pinterest (masonry discovery), Flickr (photo gallery with EXIF detail), and Dribbble (portfolio showcase) are available in Pro.

### Direct Messaging With Voice
Members can message each other directly inside your site — text, voice messages, media sharing, read receipts, and typing indicators included. No third-party chat service required.

### Privacy That Actually Works
Six privacy levels per upload: public, members-only, friends-only, group-restricted, private, and custom. Users control who sees every piece of content they share.

### AI Moderation Out of the Box
OpenAI Vision integration is included in the free version. Flag, quarantine, or auto-reject uploads based on content before they ever appear publicly. Pro adds Google Vision and AWS Rekognition as additional moderation layers.

### Built for BuddyPress Communities
If you run BuddyPress, WPMediaVerse adds media tabs to member profiles and group pages, lets members attach photos to activity posts (up to 6 per post), and surfaces the full lightbox experience — reactions, comments, favorites — directly inside the activity feed. Zero configuration required.

### Developer-Ready
17 REST API controllers, 80+ endpoints, 80 action and filter hooks, 13 Gutenberg blocks, and 8 WP-CLI commands. Build on top of WPMediaVerse without reverse-engineering it.

### Monetization Integrations
Restrict uploads, premium albums, or Pro layouts behind MemberPress, WooCommerce, or Paid Memberships Pro. Twelve integrations are available in Pro.

---

## Layout Modes

WPMediaVerse ships with multiple ways to display your media library, so you can match the look and feel of your community rather than working around a fixed template.

**Grid (Free)**
Clean, even columns. Works for any community. No configuration needed.

**Instagram Feed (Pro)**
Vertical card-based feed. Each photo gets its own card with reactions, comment previews, and user attribution. Social-first. Great for communities where discovery and engagement matter.

**Pinterest / Masonry (Pro)**
Variable-height columns that fill naturally. Best for mixed-format content, inspiration boards, and communities where browsing is the main activity.

**Flickr Gallery (Pro)**
Uniform grid with detail-first lightbox. Shows EXIF data, camera info, and full-resolution access. Built for photography communities that care about the technical side of images.

**Dribbble Portfolio (Pro)**
Clean, minimal. Designed for designers and creatives who want their work to speak for itself without social noise.

Each mode uses the same underlying data — switching between them is a one-setting change, not a migration.

---

## BuddyPress: The Best Media Solution for BP Communities

WPMediaVerse is a standalone plugin that works without BuddyPress. But if you run a BuddyPress community, the integration goes deep.

**What you get with BuddyPress active:**

- Members can attach 1-6 photos or videos to any activity post
- Activity posts display attached media in a clean inline gallery
- Clicking any photo opens the full WPMediaVerse lightbox with reactions, comments, favorites, and sharing — without leaving the activity stream
- A `/media/` tab appears automatically on every member profile
- A `/media/` tab appears automatically on every group page
- Comments left on media inside BuddyPress sync back to the media item (one-way, loop-safe)
- The `mvs_user_profile_url` hook auto-detects BuddyPress and routes profile links correctly

**Why it's better than rtMedia or MediaPress:**

Both of those plugins were built specifically for BuddyPress — and they store everything in `wp_posts`. WPMediaVerse was built as a complete media platform first, with BuddyPress as one integration layer. Your media data is never tied to BuddyPress's activity tables.

---

## Who Uses WPMediaVerse

**Photography communities** — Members share full-resolution work, get EXIF display, organize into albums, and compete in photo challenges.

**Fan communities and social networks** — Members follow each other, react to uploads, share via DM, and engage in the activity feed. Works with or without BuddyPress.

**Educational platforms** — Students and instructors share project photos and videos. Privacy controls keep content visible to the right audience. MemberPress integration gates access by membership level.

**Agency client sites** — Client uploads media through the dashboard. The REST API and webhooks connect to external workflows. Quota management keeps storage costs predictable.

**Online magazines and portfolio platforms** — The Dribbble and Flickr layout modes give creative communities a professional presentation layer without custom development.

---

## Free vs Pro

| Capability | Free | Pro |
|------------|------|-----|
| Custom table architecture | Yes | Yes |
| Grid layout mode | Yes | Yes |
| Photo upload (single) | Yes | Yes |
| Albums and collections | Yes | Yes |
| Reactions, comments, favorites | Yes | Yes |
| Follow / unfollow | Yes | Yes |
| Lightbox with full interactions | Yes | Yes |
| Direct messaging (text) | Yes | Yes |
| AI moderation (OpenAI Vision) | Yes | Yes |
| REST API (80+ endpoints) | Yes | Yes |
| 13 Gutenberg blocks | Yes | Yes |
| 8 WP-CLI commands | Yes | Yes |
| GDPR export and erasure | Yes | Yes |
| BuddyPress integration | Yes | Yes |
| Instagram layout mode | No | Yes |
| Pinterest / Masonry layout mode | No | Yes |
| Flickr Gallery layout mode | No | Yes |
| Dribbble Portfolio layout mode | No | Yes |
| Multi-file upload | No | Yes |
| Video upload and playback | No | Yes |
| Video transcoding (multi-quality HLS) | No | Yes |
| Auto-captions (Whisper AI) | No | Yes |
| Video analytics (retention, heatmaps) | No | Yes |
| Cloud storage (S3, BunnyCDN) | No | Yes |
| Storage quota management | No | Yes |
| Gamification (challenges, battles, tournaments, boosts) | No | Yes |
| Direct messaging (voice messages, media sharing, read receipts) | No | Yes |
| AI moderation (Google Vision + AWS Rekognition) | No | Yes |
| Monetization integrations (MemberPress, WooCommerce, PMPro) | No | Yes |
| Migration importers (rtMedia, MediaPress, BuddyBoss) | No | Yes |
| Priority support | No | Yes |

---

## Pricing

### Free
Download from WordPress.org. No account required.

Includes the full core platform: custom tables, grid layout, uploads, albums, reactions, comments, lightbox, follows, DMs, BuddyPress integration, REST API, Gutenberg blocks, AI moderation via OpenAI, and GDPR compliance tools.

**$0 — Always free**

---

### Pro — Single Site
All Pro features on one site: all five layout modes, video transcoding, HLS streaming, auto-captions, video analytics, cloud storage (S3 + BunnyCDN), quota management, gamification engine (challenges, battles, tournaments, boosts), voice messages, read receipts, Google Vision and AWS Rekognition moderation, all 12+ monetization integrations, and migration importers.

**$[PRICE]/year — 1 site**

---

### Pro — Agency (5 Sites)
Same as Pro, licensed for up to five sites.

**$[PRICE]/year — 5 sites**

---

### Pro — Unlimited
Same as Pro, licensed for unlimited sites. Includes agency support SLA.

**$[PRICE]/year — Unlimited sites**

All Pro plans include one year of updates and support. Renewal is optional — your license does not expire if you do not renew, but you will not receive updates or support after your license year ends.

---

## Frequently Asked Questions

**Do I need BuddyPress to use WPMediaVerse?**
No. WPMediaVerse is a standalone media platform. BuddyPress is an optional integration. If BuddyPress is active, the integration layer activates automatically. If it is not, nothing breaks.

**How is this different from rtMedia?**
rtMedia stores uploaded media as WordPress attachments in `wp_posts`. WPMediaVerse uses three dedicated, indexed tables. On large communities, this is the difference between a media query taking 80ms and taking 3 seconds. rtMedia is also tied to BuddyPress. WPMediaVerse is not.

**Will it slow down my site?**
No — and this is the specific reason the custom table architecture exists. Queries run against indexed columns on tables that only contain media data. WordPress core queries are not affected. The plugin adds no overhead to pages that do not display media.

**Can I migrate from rtMedia, MediaPress, or BuddyBoss?**
Yes. Pro includes migration importers for all three. The importers copy your existing media and metadata into WPMediaVerse's tables and generate thumbnails in the correct sizes.

**What happens to my data if I deactivate the plugin?**
Your media files remain in `wp-content/uploads`. The database tables remain in MySQL. Nothing is deleted automatically. If you want to remove all data, the plugin provides a full uninstall option in admin settings that removes tables and cleans up uploaded files.

**Does it work with my page builder?**
WPMediaVerse ships with 13 Gutenberg blocks. For page builders, the REST API and shortcodes are available. Full Elementor and Beaver Builder widget support is on the roadmap.

**Can I use my own cloud storage?**
Pro supports Amazon S3 and BunnyCDN as storage drivers. Files are uploaded directly to cloud storage. Local disk storage is the default for the free version.

**How does video transcoding work?**
Pro uses FFmpeg (installed on your server, or via a configured transcoding service) to convert uploaded videos into multiple quality levels delivered via HLS. Users get the highest quality their connection supports. Auto-captions are generated via OpenAI Whisper.

**Is the REST API documented?**
Yes. The API has 80+ endpoints across 17 controllers. Full documentation is available at [docs link].

**Can I gate access by membership level?**
Yes, in Pro. WPMediaVerse integrates with MemberPress, WooCommerce Memberships, and Paid Memberships Pro. You can restrict layout modes, upload capabilities, album creation, and storage quotas by membership tier.

**What does the gamification system include?**
Pro includes photo challenges (community submissions with voting), 1v1 photo battles, tournament brackets, and a boost system where members spend earned points to increase their media's visibility. Points are awarded for 14 tracked actions: uploads, comments, likes, follows, challenge wins, battle wins, tournament victories, and more.

**Do you offer refunds?**
Yes. If Pro does not work for your use case within 14 days, contact support for a full refund.

---

## Get Started

WPMediaVerse Free is available now on WordPress.org. Download it, install it, and your community has a complete media platform in under five minutes.

If you want the full feature set — layout modes, video, gamification, cloud storage, and everything else in Pro — you can start a free trial or purchase a license below.

**[Download Free]**   **[Get Pro]**

Questions before buying? Email support@wbcomdesigns.com or use the chat below. We answer every message.
