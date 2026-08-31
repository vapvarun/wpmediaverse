# Free vs Pro Comparison

WPMediaVerse Free is a full-featured media platform. WPMediaVerse Pro unlocks advanced tools for professional communities, monetization, and engagement.

Free and Pro release in lockstep and share the same version number. See the [changelog](https://github.com/vapvarun/wpmediaverse/blob/main/readme.txt) for what shipped in each release, or [Pro feature overview](../pro-features/overview.md) for the current Pro feature set.

## Quick Comparison

| Feature | Free | Pro |
|---------|:----:|:---:|
| **Media Upload & Management** | | |
| Drag & drop upload (photo, video, audio) | Yes | Yes |
| Bulk upload | Yes | Yes |
| Title, description, tags, categories | Yes | Yes |
| EXIF stripping & duplicate detection | Yes | Yes |
| Thumbnail generation (3 sizes) | Yes | Yes |
| Custom storage path | Yes | Yes |
| Amazon S3 cloud storage | -- | Yes |
| BunnyCDN cloud storage | -- | Yes |
| **Feed Layouts** | | |
| Default grid layout | Yes | Yes |
| Instagram layout (square grid + stories) | -- | Yes |
| Pinterest layout (masonry cards) | -- | Yes |
| Flickr layout (justified gallery) | -- | Yes |
| Dribbble layout (portfolio shots) | -- | Yes |
| **Social Features** | | |
| Follow / unfollow users | Yes | Yes |
| Emoji reactions on media | Yes | Yes |
| Threaded comments | Yes | Yes |
| Favorites (save for later) | Yes | Yes |
| @Mentions in comments | Yes | Yes |
| Share to social media | Yes | Yes |
| Direct messages (1-on-1 chat) | Yes | Yes |
| Voice messages in DMs | Yes | Yes |
| Media sharing in DMs | Yes | Yes |
| Message requests & privacy controls | Yes | Yes |
| **Content Organization** | | |
| Albums with cover photos | Yes | Yes |
| Collections (curated boards) | Yes | Yes |
| Stories (24-hour ephemeral posts, seen-by receipts) | -- | Yes |
| Gallery groups (multi-photo posts) | Yes | Yes |
| **Privacy & Moderation** | | |
| Public / Members Only / Private | Yes | Yes |
| Friends Only privacy level | Yes (needs BuddyPress Friends) | Yes |
| Group Members Only privacy level | Yes (enforced) | Yes (picker UI) |
| Custom privacy (specific users) | Yes (enforced) | Yes (picker UI) |
| Album-level privacy inheritance | Yes | Yes |
| Privacy presets | -- | Yes |
| Bulk privacy updates | -- | Yes |
| AI content moderation (OpenAI) | Yes | Yes |
| Google Cloud Vision moderation | -- | Yes |
| AWS Rekognition moderation | -- | Yes |
| Claude (Anthropic) moderation | -- | Yes |
| User reporting | Yes | Yes |
| User blocking | Yes | Yes |
| GDPR data export & erasure | Yes | Yes |
| **Video** | | |
| Video upload & playback | Yes | Yes |
| Video chapter markers | -- | Yes |
| Resume playback (pick up where you left off) | -- | Yes |
| Auto-captions via OpenAI Whisper | -- | Yes |
| Video analytics & heatmaps | -- | Yes |
| **Image Processing** | | |
| Basic text watermarking | Yes | Yes |
| Logo watermarking with positioning | -- | Yes |
| Watermark opacity & font control | -- | Yes |
| **Gamification** | | |
| Photo Challenges (themed competitions) | -- | Yes |
| 1v1 Photo Battles | -- | Yes |
| Single-elimination Tournaments | -- | Yes |
| Media Boosts (spend points for visibility) | -- | Yes |
| Upload Streaks with milestones | -- | Yes |
| Weekly Autopilot (auto-create challenges) | -- | Yes |
| XP integration with wb-gamification | -- | Yes |
| **Quotas & Monetization** | | |
| Per-user upload quotas (count + storage) | -- | Yes |
| Quota packages (Free, Premium, etc.) | -- | Yes |
| MemberPress integration | -- | Yes |
| Paid Memberships Pro integration | -- | Yes |
| WooCommerce integration | -- | Yes |
| Credit transaction log | -- | Yes |
| **User Profiles** | | |
| Public profile page (/media/@username/) | Yes | Yes |
| Follow / Message buttons | Yes | Yes |
| Custom avatar upload | Yes | Yes |
| Inline profile editing | -- | Yes |
| **Admin Tools** | | |
| Media overview dashboard | Yes | Yes |
| Media list with bulk actions | Yes | Yes |
| Settings (general, display, social, AI) | Yes | Yes |
| Competitions dashboard | -- | Yes |
| Challenge manager with theme library | -- | Yes |
| Tournament bracket manager | -- | Yes |
| Battle monitor | -- | Yes |
| Video analytics dashboard | -- | Yes |
| Quota & credits management | -- | Yes |
| Media stats & insights | -- | Yes |
| **BuddyPress Integration** | | |
| Profile media tab | Yes | Yes |
| Group media tab | Yes | Yes |
| Activity stream media | Yes | Yes |
| Notifications (likes, comments, follows) | Yes | Yes |
| **Gutenberg Blocks** | | |
| Core blocks (Media Grid, Player, Album, Upload, Stats, Explore Feed, Lock Overlay, Member Photos, PDF Viewer) | Yes (9) | Yes (inherited) |
| Pro feature blocks (Tournament, Challenge, Battle, Leaderboard, Compete Hub) | -- | Yes (5) |
| Pro feed-layout blocks (Instagram, Flickr, Pinterest, Dribbble) | -- | Yes (4) |
| Pro list blocks (Tournaments List, Challenges List, Battles Active) | -- | Yes (3) |
| Mix layouts per-page | -- | Yes |
| **Developer** | | |
| REST API (100+ endpoints, `mvs/v1` namespace) | Yes | Yes |
| Pro REST API (37 additional endpoints, `mvs-pro/v1` namespace) | -- | Yes |
| Lightbox download + share REST endpoints | Yes | Yes |
| 150+ action and filter hooks | Yes | Yes |
| Template override system | Yes | Yes |
| Custom storage driver API | Yes | Yes |
| WP-CLI commands (`wp mvs` namespace) | Yes | Yes |
| Migration tools (rtMedia, MediaPress, BuddyBoss) | -- | Yes |
| MigrationPage admin (per-platform cards) | -- | Yes |
| **Accessibility** | | |
| WCAG 2.1 AA pass on customer-facing UI | Yes | Yes |
| `aria-label` on icon buttons + `aria-pressed` on toggles | Yes | Yes |
| `:focus-visible` outlines + keyboard navigation | Yes | Yes |
| Theme dark-mode support (BuddyX Pro / generic class-based) | Yes | Yes |
| **Integrations** | | |
| BuddyPress 12+ | Yes | Yes |
| wb-gamification | -- | Yes |
| MemberPress | -- | Yes |
| Paid Memberships Pro | -- | Yes |
| WooCommerce | -- | Yes |
| OpenAI (moderation + captions) | Yes | Yes |
| Google Cloud Vision | -- | Yes |
| AWS Rekognition | -- | Yes |
| Amazon S3 | -- | Yes |
| BunnyCDN | -- | Yes |

### A note on the privacy rows

Privacy is the one area where "Free vs Pro" is not a clean on/off split, so it is worth stating precisely:

- **Free enforces all six privacy levels.** `PrivacyService` gates viewing for `public`, `members`, `friends`, `group`, `private` and `custom`. If a media item carries one of those levels - set by an import, the REST API, an album inheriting its privacy, or Pro before a licence lapsed - Free honours it. Your members' private media does not become visible because Pro is inactive.
- **Free's upload form exposes three of them**, plus Friends Only when the BuddyPress Friends component is active: Public, Members, Friends (conditional) and Only Me. Group and Custom are enforced but have no picker in the stock Free upload form.
- **Pro adds the interface, not the enforcement**: a picker for all six levels with plain-language descriptions, saved presets, and bulk privacy updates across many items at once.
- **Album-level privacy inheritance is Free.** When media is added to an album, `AlbumService` clamps each item to the more restrictive of the two, filterable via `mvs_album_inherit_privacy`.

There is no "Followers Only" media privacy level in either plugin. `followers` appears only as a **direct-message access** setting (`mvs_dm_access`), which controls who may open a conversation with you - not who can see your media.

## What You Get Free

WPMediaVerse Free is not a stripped-down trial. It is a complete media platform with:

- Full upload system with drag & drop, bulk upload, and duplicate detection
- Albums, collections, and gallery groups
- Complete social layer: follows, reactions, comments, favorites, mentions, sharing
- Built-in direct messaging with voice messages, file sharing, and read receipts
- User profiles with follow/message buttons and media grids
- AI content moderation via OpenAI
- BuddyPress integration (profiles, groups, activity, notifications)
- Full REST API with 100+ endpoints
- GDPR compliance (data export + erasure)
- User blocking and reporting

## What Pro Adds

WPMediaVerse Pro is for sites that need professional-grade features:

- **Visual identity** - Choose from Instagram, Pinterest, Flickr, or Dribbble layouts to match your community's style
- **Scale** - Offload media to S3 or BunnyCDN for global CDN delivery and unlimited storage
- **Video intelligence** - Chapters, auto-captions, and engagement analytics
- **Engagement** - Gamification system with challenges, battles, tournaments, boosts, and streaks that keep users coming back
- **Monetization** - Quota packages with MemberPress/WooCommerce integration let you sell tiered upload plans
- **Privacy** - A picker UI for all six privacy levels, plus saved presets and bulk privacy management
- **AI** - Google Vision, AWS Rekognition, and Claude (Anthropic) for auto-tagging and advanced content moderation
- **Migration** - Import from rtMedia, MediaPress, or BuddyBoss with one WP-CLI command

## Upgrading

1. Purchase a Pro license at [wbcomdesigns.com](https://store.wbcomdesigns.com/wpmediaverse-pro/)
2. Upload and activate the `wpmediaverse-pro.zip` plugin
3. Enter your license key at **Media > License**
4. Pro features activate immediately - no data migration needed, no settings lost
