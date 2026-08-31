# Integrations

WPMediaVerse connects with 12+ third-party services and plugins out of the box. No custom code, no middleware - configure credentials in settings and the integration activates.

## Integrations Admin Page (1.8.0)

Go to **WPMediaVerse > Integrations** for a visual view of the Wbcom plugin family - the products designed to work alongside WPMediaVerse. Each card shows the product logo, a short "why you'd want this" description, and a status badge (**Connected**, **Installed, activate**, or **Not installed**).

- **Install free** - installs and activates the companion plugin's free version in one click, without leaving the page.
- **Learn more** - links out to the product's page on the Wbcom store.
- Every companion plugin works standalone - installing one from this page does not tie it to WPMediaVerse, and WPMediaVerse simply lights up the matching integration when it detects the companion is active.

This page lists the family (WB Gamification, BuddyX/BuddyNext, and other Wbcom products); it's separate from the third-party service integrations (AI providers, cloud storage) documented below, which are configured under **Settings**.

## Integration Map

| Integration | Plugin | What It Does | Free | Pro |
|-------------|--------|-------------|:----:|:---:|
| **BuddyPress** | Free | Profile media tabs, group media, activity stream, notifications | Yes | Yes |
| **BuddyNext** | Free | Enhanced member directory and profile blocks | Yes | Yes |
| **WB Gamification** | Free (separate plugin) | XP points, badges, leaderboards for competitions (optional - competitions run without it, only points need it) | -- | Yes |
| **Amazon S3** | Pro | Store all media files on S3 with CDN delivery | -- | Yes |
| **BunnyCDN** | Pro | Store and deliver media via BunnyCDN edge network | -- | Yes |
| **OpenAI** | Free | AI content moderation, auto-tagging, description generation | Yes | Yes |
| **Google Cloud Vision** | Pro | Advanced image labeling, object detection, safe search | -- | Yes |
| **AWS Rekognition** | Pro | Face detection, content moderation, celebrity recognition | -- | Yes |
| **OpenAI Whisper** | Pro | Automatic video/audio transcription to WebVTT captions | -- | Yes |
| **MemberPress** | Pro | Auto-assign quota packages based on membership level | -- | Yes |
| **Paid Memberships Pro** | Pro | Auto-assign quota packages based on PMPro level | -- | Yes |
| **WooCommerce** | Pro | Sell upload quota packages as WooCommerce products | -- | Yes |
| **WordPress Webhooks** | Free | Send real-time HTTP notifications on media events | Yes | Yes |

## Community & Social

### BuddyPress

WPMediaVerse is the most complete media solution for BuddyPress communities. The integration activates automatically when BuddyPress is detected - no configuration needed.

**What users get:**
- A **Media** tab on every member profile showing their uploads in a grid
- A **Media** tab in every group where members upload and share within the group
- Media uploads appear as activity items in the BuddyPress activity stream with thumbnails
- Notifications when someone likes, comments on, or shares your media
- One-click media sharing from the lightbox directly into BuddyPress activity

**What admins get:**
- Zero configuration - activate BuddyPress and the integration works
- Media tab visibility follows BuddyPress privacy settings
- Activity items follow BuddyPress moderation rules
- Compatible with BuddyPress 12.0+

See [BuddyPress Integration](../buddypress/overview.md) for full details.

### BuddyNext

If you use the BuddyNext theme, WPMediaVerse detects it automatically and enhances the member directory with media counts and the profile layout with media grid blocks. As of 2.0.0, BuddyNext also changes how individual media links behave - see [Activity Stream Media](../buddypress/activity-media.md#buddynext-media-links-open-their-activity-post-200) for details.

### wb-gamification **(Pro)**

**This integration requires the separate, free WB Gamification plugin.** Points (XP) are only earned or spent when WB Gamification is installed and active. Without it, the competition features still run fully - members can create and enter Challenges, Battles, and Tournaments, vote, and see winners - but no points are awarded for wins or streaks, and the point-spending controls (such as Media Boosts) stay hidden. Every MediaVerse award/spend path is guarded, so Pro works correctly whether or not WB Gamification is present.

When WB Gamification is active, WPMediaVerse Pro feeds it points for these competition outcomes via the `wb_gam_points_for_action` filter:

| Action | When | Default XP |
|--------|------|-----------|
| Win a challenge (1st) | First place in a challenge | 200 |
| Win a challenge (2nd) | Second place | 100 |
| Win a challenge (3rd) | Third place | 50 |
| Enter a challenge | Submit an entry that meets the rules | 10 |
| Win a tournament round | Advance one bracket round | configurable |
| Win a tournament | Tournament champion | configurable |
| Reach a streak milestone | Hit a daily-upload streak threshold | configurable |

Challenge XP values are configured **per competition** when you create a Challenge (1st / 2nd / 3rd / participation), not as a single global table. WB Gamification handles the points ledger, badges, leaderboards, and leveling - WPMediaVerse Pro only tells it which competition outcome occurred.

Get the free plugin: [WB Gamification](https://wbcomdesigns.com/downloads/wordpress-gamification-plugin/).

## Cloud Storage

### Amazon S3 **(Pro)**

Offload every upload to an S3 bucket. Files are served from S3 directly (or via CloudFront if configured).

**What you get:**
- Unlimited storage (pay-as-you-go with AWS)
- Global CDN delivery when paired with CloudFront
- Signed URLs for private/members-only media
- Automatic retry (3 attempts) on upload failure
- Connection test button in admin to verify credentials

**Setup:** Paste your bucket name, region, access key, and secret key into **Media > Settings > Storage > Amazon S3**. Click "Test Connection" to verify. New uploads go to S3 immediately.

**Security:** Store credentials in `wp-config.php` instead of the database:
```php
define( 'MVS_PRO_AWS_ACCESS_KEY', 'AKIA...' );
define( 'MVS_PRO_AWS_SECRET_KEY', '...' );
```

See [Cloud Storage](../pro-features/cloud-storage.md) for IAM policy and full setup guide.

### BunnyCDN **(Pro)**

Store and deliver media through BunnyCDN's global edge network.

**What you get:**
- 114 edge locations worldwide
- Automatic image optimization
- Per-request pricing (no minimum commitment)
- Simpler setup than AWS (one API key)

**Setup:** Enter your storage zone name, API key, and CDN hostname into **Media > Settings > Storage > BunnyCDN**.

### Custom Storage Drivers

WPMediaVerse uses a `StorageDriverInterface` that any developer can implement. Build drivers for Google Cloud Storage, DigitalOcean Spaces, Wasabi, Backblaze B2, or any S3-compatible service.

See [Custom Storage Drivers](../developer-guide/custom-storage-drivers.md) for the interface spec.

## AI & Machine Learning

### OpenAI (GPT + Vision)

Built into the free plugin. Uses the OpenAI API for:

- **Content moderation** - Automatically flag inappropriate uploads before they appear on the site
- **Auto-tagging** - AI suggests relevant tags based on image content
- **Description generation** - Generate alt text and descriptions for accessibility
- **Monthly budget cap** - Set a dollar limit to prevent unexpected API costs

**Setup:** Paste your OpenAI API key into **Media > Settings > AI & Moderation**.

### Google Cloud Vision **(Pro)**

Adds Google's image analysis capabilities:

- **Label detection** - Identify objects, locations, activities in photos
- **Safe search** - Detect explicit, violent, or medical content
- **Text detection** - Extract text from images (OCR)
- Circuit breaker pattern prevents API hammering on failures

**Setup:** Paste your Google Cloud API key into **Media > Settings > AI & Moderation > Google Vision**.

### AWS Rekognition **(Pro)**

Adds Amazon's image and video analysis:

- **Object and scene detection** - Identify objects with confidence scores
- **Face detection** - Detect faces with attributes (smile, glasses, age range)
- **Content moderation** - Flag suggestive, violent, or explicit content
- Circuit breaker pattern with automatic recovery

**Setup:** Uses the same AWS credentials as S3 storage, or set separate credentials in **Media > Settings > AI & Moderation > AWS Rekognition**.

### OpenAI Whisper **(Pro)**

Automatic speech-to-text transcription for video and audio uploads:

- Generates WebVTT caption files
- Captions are searchable and displayed as subtitles in the video player
- Process runs asynchronously via Action Scheduler
- Supports 50+ languages

**Setup:** Enable at **Media > Settings > Video > Auto-Captions** (uses the same OpenAI API key).

## Monetization

### MemberPress **(Pro)**

Automatically assign upload quota packages based on MemberPress membership levels.

**How it works:**
1. Create quota packages in **Media > Quotas** (e.g., "Free: 50 photos", "Premium: unlimited")
2. Map each MemberPress membership to a quota package
3. When a user purchases or is assigned a membership, their quota updates automatically
4. When a membership expires, the user reverts to the default package

### Paid Memberships Pro **(Pro)**

Same automatic package assignment, but using PMPro membership levels instead of MemberPress.

### WooCommerce **(Pro)**

Sell upload quota packages as WooCommerce products:

**How it works:**
1. Create quota packages in **Media > Quotas**
2. Create a WooCommerce product and map it to a quota package
3. When a customer completes checkout, their quota package activates
4. If the order is refunded or cancelled, the package reverts to default

This lets you sell storage tiers directly from your WooCommerce store.

## Webhooks

WPMediaVerse can send real-time HTTP POST notifications to external services when events occur:

| Event | Payload |
|-------|---------|
| Media uploaded | Media ID, file URL, author, type, privacy |
| Media deleted | Media ID |
| Comment posted | Comment ID, media ID, author, content |
| Reaction added | Media ID, user ID, reaction type |
| Report submitted | Media ID, reporter ID, reason |
| Moderation changed | Media ID, old status, new status |

**Use cases:**
- Notify a Slack channel when new media is uploaded
- Trigger a Zapier workflow on media events
- Sync media metadata to an external CMS or DAM
- Log moderation actions to an audit system

**Setup:** Add webhook URLs at **Media > Settings > Webhooks**. Each webhook can filter by event type.

See [Webhooks](../settings/webhooks.md) for payload formats and authentication.

## WordPress Core

### Site Health

WPMediaVerse registers 3 custom tests in **Tools > Site Health**:

- **Database tables** - Verifies all custom tables exist and have the expected schema
- **Upload directory** - Checks that the wpmediaverse upload directory is writable
- **Required pages** - Confirms Dashboard, Explore, and Upload pages are assigned

### GDPR / Privacy Tools

- **Export Personal Data** - Exports all user media, comments, reactions, favorites, DMs, and follow relationships
- **Erase Personal Data** - Removes all of the above when processing an erasure request
- **Privacy Policy** - Suggests privacy policy text via WordPress's built-in privacy policy tool

See [GDPR & Privacy Compliance](../features/gdpr-privacy.md).

### REST API

WPMediaVerse exposes 50+ REST endpoints in the free plugin and 30+ additional endpoints in Pro, all under the `mvs/v1` and `mvs-pro/v1` namespaces. Any external application, mobile app, or headless frontend can consume the full API.

See [REST API Reference](../developer-guide/rest-api.md) and [Pro REST API Reference](../developer-guide/pro-rest-api.md).

### WP-CLI

CLI commands for automation, migration, and maintenance:

```
wp mvs stats              # Show media stats
wp mvs migrate            # Run database migrations
wp mvs reindex            # Rebuild media index
wp mvs cache-flush        # Flush all caches
wp mvs prune-views        # Clean old view records
wp mvs cleanup-expired    # Remove expired stories/tokens
wp mvs moderation-stats   # Show moderation queue stats
wp mvs optimize           # Losslessly optimize an image
wp mvs migrate-storage    # Migrate files between storage drivers
```

See [WP-CLI Commands](../developer-guide/wp-cli.md).

### Gutenberg Blocks

Registered in `BlockRegistrar::BLOCKS`:

| Block | Description |
|-------|-------------|
| Media Upload | A drag-and-drop media upload form |
| Media Grid | Display a grid of media items |
| Media Player | Video and audio player for media items |
| Album Viewer | Display an album with its media items |
| Media Stats | Display a media statistics dashboard |
| Explore Feed | A discover/explore feed showing trending and recent media |
| Lock Overlay | Paywall overlay on gated media, with blurred preview and unlock prompt |
| Member Photos | A member's photos. Auto-detects the displayed BuddyPress member, the post author, or the current user |
| PDF Viewer | Embed a PDF inline using the browser's native viewer, under the same privacy and access rules as other media |

There is no Profile Edit block - profile editing ships as the `[mvs_profile_edit]` shortcode only.

See [Gutenberg Blocks](../features/blocks.md).

### Shortcodes

For classic editor and page builders:

All registered in `Shortcodes\Shortcodes` (Free):

| Shortcode | Description |
|-----------|-------------|
| `[mvs_gallery]` | Media grid with filtering |
| `[mvs_upload]` | Upload form |
| `[mvs_album]` | An album with its media items |
| `[mvs_player]` | Video/audio player for one media item |
| `[mvs_stats]` | Media statistics |
| `[mvs_dashboard]` | User's media dashboard |
| `[mvs_collection]` | A collection's media items |
| `[mvs_profile_edit]` | Inline profile editing form |
| `[mvs_documents]` | A document drive listing (renders through Pro's Documents engine) |
| `[mvs_explore_feed]` | Explore feed with search, tags and pagination |
| `[mvs_lock_overlay]` | Paywall overlay on gated media |
| `[mvs_member_photos]` | A member's photos |
| `[mvs_pdf_viewer]` | Inline PDF viewer |
| `[mvs_usage_history]` | The member's storage/usage history |

Pro adds `[mvs_document]` plus its compete and connector-feed shortcodes.

See [Shortcodes](../features/shortcodes.md).
