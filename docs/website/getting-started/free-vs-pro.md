# Free vs Pro Comparison

MediaVerse Free is a full-featured media platform. MediaVerse Pro unlocks advanced tools for professional communities, monetization, and engagement.

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
| Local storage (WordPress uploads folder) | Yes | Yes |
| Fair-use storage limit per member | Yes | Yes |
| Amazon S3 cloud storage | -- | Yes |
| BunnyCDN cloud storage | -- | Yes |
| Cloudflare R2 cloud storage | -- | Yes |
| DigitalOcean Spaces cloud storage | -- | Yes |
| **Feed Layouts** | | |
| Grid, justified rows and list layouts | Yes | Yes |
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
| Collections (smart collections built from rules) | Yes | Yes |
| Manual collections with the Save picker | -- | Yes |
| Stories (24-hour ephemeral posts, seen-by receipts) | -- | Yes |
| Gallery groups (multi-photo posts) | Yes | Yes |
| **Privacy & Moderation** | | |
| Public / Members Only / Private | Yes | Yes |
| Friends Only privacy level | Yes (needs BuddyPress Friends) | Yes |
| Group Members Only privacy level | Yes (set when posting in a group) | Yes |
| Custom privacy (specific users) | Yes (enforced) | Yes (set through the API) |
| Album-level privacy inheritance | Yes | Yes |
| Bulk privacy change on selected items | Yes | Yes |
| Privacy presets (saved choices, API only) | -- | Yes |
| AI content moderation (OpenAI) | Yes | Yes |
| Google Cloud Vision moderation | -- | Yes |
| AWS Rekognition moderation | -- | Yes |
| Claude (Anthropic) moderation | -- | Yes |
| User reporting | Yes | Yes |
| User blocking | Yes | Yes |
| GDPR data export & erasure | Yes | Yes |
| **Video** | | |
| Video upload & playback | Yes | Yes |
| Video chapter markers (shown in the mobile app and the API) | -- | Yes |
| Resume playback (pick up where you left off) | -- | Yes |
| Auto-captions via OpenAI Whisper (shown in the web players) | -- | Yes |
| Video analytics & heatmaps | -- | Yes |
| **Image Processing** | | |
| Text watermarking | -- | Yes |
| Logo watermarking with positioning | -- | Yes |
| Watermark opacity, text size and color | -- | Yes |
| Compress uploaded images | Yes | Yes |
| **Gamification** | | |
| Photo Challenges (themed competitions) | -- | Yes |
| 1v1 Photo Battles | -- | Yes |
| Single-elimination Tournaments | -- | Yes |
| Media Boosts (spend points for visibility) | -- | Yes |
| Upload Streaks with milestones | -- | Yes |
| Weekly Autopilot (auto-create challenges) | -- | Yes |
| Points integration with wb-gamification | -- | Yes |
| **Storage Limits** | | |
| Fair-use storage limit per member (MB), site-wide + per-member override | Yes | Yes (inherited) |
| **User Profiles** | | |
| Public profile page (/media/@username/) | Yes | Yes |
| Follow / Message buttons | Yes | Yes |
| Custom avatar upload | Yes | Yes |
| Profile editing (name, bio, avatar) | Yes | Yes |
| **Admin Tools** | | |
| Media overview dashboard | Yes | Yes |
| Media list with bulk actions | Yes | Yes |
| Settings (General, Display, Messages, Storage, AI, Moderation, Mobile App, Webhooks) | Yes | Yes |
| Webhooks | Yes | Yes |
| Stats page (uploads, views, reactions) | Yes | Yes |
| Competitions dashboard | -- | Yes |
| Challenge manager with challenge themes | -- | Yes |
| Tournament bracket manager | -- | Yes |
| Battle monitor | -- | Yes |
| Video analytics dashboard | -- | Yes |
| Analytics dashboard (traffic and engagement) | -- | Yes |
| Stories manager | -- | Yes |
| **BuddyPress Integration** | | |
| Profile media tab | Yes | Yes |
| Group media tab | Yes | Yes |
| Activity stream media | Yes | Yes |
| Notifications (likes, comments, follows) | Yes | Yes |
| **Gutenberg Blocks** | | |
| Core blocks (Media Grid, Player, Album, Upload, Stats, Explore Feed, Member Photos, PDF Viewer) | Yes (8) | Yes (inherited) |
| Pro feature blocks (Tournament, Challenge, Battle, Leaderboard, Compete Hub) | -- | Yes (5) |
| Pro stories and document blocks (Stories, Document Embed, Document List) | -- | Yes (3) |
| Pro feed-layout blocks (Instagram, Flickr, Pinterest, Dribbble) | -- | Yes (4) |
| Pro list blocks (Tournaments List, Challenges List, Battles Active) | -- | Yes (3) |
| Mix layouts per-page | -- | Yes |
| **Developer** | | |
| REST API (100+ endpoints, `mvs/v1` namespace) | Yes | Yes |
| Pro REST API (90+ additional endpoints, `mvs-pro/v1` namespace) | -- | Yes |
| Lightbox download + share REST endpoints | Yes | Yes |
| 150+ action and filter hooks | Yes | Yes |
| Template override system | Yes | Yes |
| Custom storage driver API | Yes | Yes |
| WP-CLI commands (`wp mvs` namespace) | Yes | Yes |
| Migration tools (rtMedia, MediaPress, BuddyBoss) | -- | Yes |
| Import screen (per-platform cards) | -- | Yes |
| **Accessibility** | | |
| WCAG 2.1 AA pass on customer-facing UI | Yes | Yes |
| `aria-label` on icon buttons + `aria-pressed` on toggles | Yes | Yes |
| `:focus-visible` outlines + keyboard navigation | Yes | Yes |
| Theme dark-mode support (BuddyX Pro / generic class-based) | Yes | Yes |
| **Integrations** | | |
| BuddyPress 12+ | Yes | Yes |
| wb-gamification | -- | Yes |
| OpenAI (moderation + captions) | Yes | Yes |
| Google Cloud Vision | -- | Yes |
| AWS Rekognition | -- | Yes |
| Claude (Anthropic) | -- | Yes |
| Amazon S3 | -- | Yes |
| BunnyCDN | -- | Yes |
| Cloudflare R2 | -- | Yes |
| DigitalOcean Spaces | -- | Yes |
| BuddyNext | Yes | Yes |

### A note on the privacy rows

Privacy is the one area where "Free vs Pro" is not a clean on/off split, so it is worth stating precisely:

- **Free enforces every privacy level.** MediaVerse accepts Public, Members, Friends, Group, Space, Only me and Custom, and checks the level every time someone tries to view a file. If a media item carries one of those levels - set by an import, the API, an album that passes its privacy down, or Pro before a licence lapsed - Free honours it. Your members' private media does not become visible because Pro is inactive.
- **Free's upload form offers four choices**: Public, Members, Friends (only when the BuddyPress Friends component is active) and Only me. Media posted into a BuddyPress group is limited to that group automatically. Group, Space and Custom have no picker in the stock upload form.
- **Free can change privacy on many items at once**: select items in My Media or Explore, then use the privacy menu in the bulk bar. **Allow Users to Set Privacy** switches this off for members.
- **Pro adds API features, not a new screen**: saved privacy presets, a bulk-privacy endpoint, plain-language descriptions of each level in API responses, and the ability to set Custom (a list of specific people) on an item.
- **Album-level privacy inheritance is Free.** When media is added to an album, each item takes the more restrictive of the two privacy levels, and MediaVerse asks before it changes an album to a stricter level.

There is no "Followers Only" media privacy level you can set in either plugin. "Followers only" appears as a **direct-message** choice (under **Who can send messages**), which controls who may open a conversation with you - not who can see your media.

## What You Get Free

MediaVerse Free is not a stripped-down trial. It is a complete media platform with:

- Full upload system with drag & drop, bulk upload, and duplicate detection
- Albums, collections, and gallery groups
- Complete social layer: follows, reactions, comments, favorites, mentions, sharing
- Built-in direct messaging with voice messages, file sharing, and read receipts
- User profiles with follow/message buttons and media grids
- AI content moderation and tagging via OpenAI
- A fair-use storage limit per member
- BuddyPress integration (profiles, groups, activity, notifications)
- Full REST API with 100+ endpoints
- GDPR compliance (data export + erasure)
- User blocking and reporting

## What Pro Adds

MediaVerse Pro is for sites that need professional-grade features:

- **Visual identity** - Choose from Instagram, Pinterest, Flickr, or Dribbble layouts to match your community's style
- **Scale** - Offload media to Amazon S3, BunnyCDN, Cloudflare R2 or DigitalOcean Spaces for CDN delivery and room to grow
- **Video intelligence** - Chapters, resume playback, auto-captions, and engagement analytics
- **Engagement** - Gamification system with challenges, battles, tournaments, boosts, and streaks that keep users coming back
- **Privacy** - Saved privacy presets, a bulk privacy endpoint and Custom (specific people) access through the API
- **AI** - Google Vision, AWS Rekognition, and Claude (Anthropic) for auto-tagging and advanced content moderation
- **Migration** - Import from rtMedia, MediaPress, or BuddyBoss with one WP-CLI command

## Upgrading

1. Purchase a Pro license at [wbcomdesigns.com](https://wbcomdesigns.com/downloads/mediaverse-pro/)
2. Upload and activate the `wpmediaverse-pro.zip` plugin
3. Enter your license key at **MediaVerse > Settings > License**
4. Pro features activate immediately - no data migration needed, no settings lost

Pro needs MediaVerse Free installed and active. Install both plugins at the same version. If your licence expires, Pro features keep working; the licence controls updates and support (adding or sharing documents is the one exception).
