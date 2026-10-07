# Integrations

MediaVerse connects with other plugins and outside services out of the box. No custom code, no middleware - configure credentials in settings and the integration activates.

## Integrations Admin Page (1.8.0)

Open the **Integrations** card on the **MediaVerse** Overview screen and click **View integrations** for a visual view of the Wbcom plugin family - the products designed to work alongside MediaVerse. Each card shows the product logo, a short "why you'd want this" description, and a status badge (**Connected**, **Installed, inactive**, or **Not installed**).

- **Install free** - installs and activates the companion plugin's free version in one click, without leaving the page. If the plugin is already installed but off, the button reads **Activate**.
- **Learn more** - links out to the product's page on the Wbcom store.
- Every companion plugin works standalone - installing one from this page does not tie it to MediaVerse, and MediaVerse simply lights up the matching integration when it detects the companion is active.

This page lists BuddyNext, Jetonomy, WB Gamification, Learnomy, WP Career Board and Listora; it's separate from the third-party service integrations (AI providers, cloud storage) documented below, which are configured under **Settings**.

## Integration Map

| Integration | Plugin | What It Does | Free | Pro |
|-------------|--------|-------------|:----:|:---:|
| **BuddyPress** | Free | Profile media tabs, group media, activity stream, notifications | Yes | Yes |
| **BuddyNext** | Free (separate plugin) | Feed cards, profile and space media tabs, notifications and emails for media | Yes | Yes |
| **WB Gamification** | Free (separate plugin) | Points, badges, leaderboards for competitions (optional - competitions run without it, only points need it) | -- | Yes |
| **Amazon S3** | Pro | Store all media files on S3 with CDN delivery | -- | Yes |
| **BunnyCDN** | Pro | Store and deliver media via BunnyCDN edge network | -- | Yes |
| **OpenAI** | Free | AI content moderation, auto-tagging, description generation | Yes | Yes |
| **Google Cloud Vision** | Pro | Image labeling and safe-search checks | -- | Yes |
| **AWS Rekognition** | Pro | Object and scene labeling, content moderation | -- | Yes |
| **OpenAI Whisper** | Pro | Automatic video/audio transcription to WebVTT captions | -- | Yes |
| **Claude (Anthropic)** | Pro | Image analysis, tagging and content moderation | -- | Yes |
| **Cloudflare R2** | Pro | Store media on Cloudflare R2 | -- | Yes |
| **DigitalOcean Spaces** | Pro | Store media on DigitalOcean Spaces | -- | Yes |
| **Webhooks** | Free | Send real-time HTTP notifications on media events | Yes | Yes |

## Community & Social

### BuddyPress

MediaVerse is the most complete media solution for BuddyPress communities. The integration activates automatically when BuddyPress is detected - no configuration needed.

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

BuddyNext is a separate community plugin, not a theme. When it is active, MediaVerse works with it automatically:

- A public upload publishes a card into the BuddyNext feed.
- Members get a Media tab on their BuddyNext profile, and space owners can switch on Media and Files tabs for a space.
- BuddyNext sends the notifications and emails for media activity, and MediaVerse sends none of its own activity emails, so a member gets one email per event.
- MediaVerse hides its own chat panel and Messages page, which BuddyNext already provides, and My Media drops its Edit profile section.

BuddyNext and BuddyPress are alternatives. Run one or the other. See [BuddyNext Integration](../buddynext/overview.md) and [Activity Stream Media](../buddypress/activity-media.md#buddynext-is-a-separate-path).

### wb-gamification **(Pro)**

**This integration requires the separate, free WB Gamification plugin.** Points are only earned or spent when WB Gamification is installed and active. Without it, the competition features still run fully - members can create and enter Challenges, Battles, and Tournaments, vote, and see winners - but no points are awarded for wins or streaks, and the point-spending controls (such as Media Boosts) stay hidden. Every MediaVerse award/spend path is guarded, so Pro works correctly whether or not WB Gamification is present.

When WB Gamification is active, MediaVerse Pro feeds it points for these competition outcomes via the `wb_gam_points_for_action` filter:

| Action | When | Default points |
|--------|------|-----------|
| Win a challenge (1st) | First place in a challenge | 200 |
| Win a challenge (2nd) | Second place | 100 |
| Win a challenge (3rd) | Third place | 50 |
| Enter a challenge | Submit an entry that meets the rules | 10 |
| Win a tournament round | Advance one bracket round | configurable |
| Win a tournament | Tournament champion | configurable |
| Reach a streak milestone | Hit a daily-upload streak threshold | configurable |

Challenge point values are configured **per competition** when you create a Challenge (1st / 2nd / 3rd / participation), not as a single global table. WB Gamification handles the points ledger, badges, leaderboards, and leveling - MediaVerse Pro only tells it which competition outcome occurred.

Get the free plugin: [WB Gamification](https://wbcomdesigns.com/downloads/wordpress-gamification-plugin/).

## Cloud Storage

### Amazon S3 **(Pro)**

Offload every upload to an S3 bucket. Files are served from S3 directly (or via CloudFront if configured).

**What you get:**
- Unlimited storage (pay-as-you-go with AWS)
- Global CDN delivery when paired with CloudFront
- Private and members-only media is served through signed addresses
- Automatic retry (3 attempts) on upload failure
- Connection test button in admin to verify credentials

**Setup:** Go to **MediaVerse > Settings > Storage**, choose **Amazon S3** under **Where files are stored**, and enter your bucket name, region, access key ID, secret access key and an optional CloudFront / CDN domain. Click **Test S3 Connection** to verify. New uploads go to S3 immediately.

**Security:** Store credentials in `wp-config.php` instead of the database:
```php
define( 'MVS_PRO_AWS_ACCESS_KEY', 'AKIA...' );
define( 'MVS_PRO_AWS_SECRET_KEY', '...' );
```

See [Cloud Storage](../pro-features/cloud-storage.md) for IAM policy and full setup guide.

### BunnyCDN **(Pro)**

Store and deliver media through BunnyCDN's global edge network.

**What you get:**
- Delivery from BunnyCDN's edge network
- Simpler setup than AWS (one storage zone and one pull zone)

**Setup:** Go to **MediaVerse > Settings > Storage**, choose **BunnyCDN**, and enter the storage zone name, storage zone password, storage region and pull zone hostname. Click **Test BunnyCDN Connection** to verify. Cloudflare R2 and DigitalOcean Spaces are set up the same way on that screen.

### Custom Storage Drivers

MediaVerse uses a `StorageDriverInterface` that any developer can implement. Build drivers for Google Cloud Storage, Wasabi, Backblaze B2, or any other service.

See [Custom Storage Drivers](../developer-guide/custom-storage-drivers.md) for the interface spec.

## AI & Machine Learning

### OpenAI (GPT + Vision)

Built into the free plugin. Uses the OpenAI API for:

- **Content moderation** - Automatically flag inappropriate uploads before they appear on the site
- **Auto-tagging** - AI suggests relevant tags based on image content
- **Description generation** - Generate alt text and descriptions for accessibility
- **Monthly budget cap** - **Monthly AI Budget ($)** stops AI calls when the estimate reaches the limit (default 10, 0 means no limit)

**Setup:** Paste your OpenAI API key into **MediaVerse > Settings > AI**. You can also define `MVS_OPENAI_API_KEY` in `wp-config.php`. Turn on **Auto-Analyze Uploads** there for tags and descriptions, and **AI Moderation** under **MediaVerse > Settings > Moderation** to check uploads.

### Google Cloud Vision **(Pro)**

Adds Google's image analysis capabilities:

- **Label detection** - Identify objects, locations, activities in photos
- **Safe search** - Detect explicit, violent, or medical content
- **Image properties** - Details such as dominant colors
- A circuit breaker pauses calls after repeated failures

**Setup:** Go to **MediaVerse > Settings > AI**, set **AI Provider** to **Google Vision**, and paste your Google Cloud API key.

### AWS Rekognition **(Pro)**

Adds Amazon's image and video analysis:

- **Object and scene detection** - Identify objects and scenes with confidence scores
- **Content moderation** - Flag suggestive, violent, or explicit content
- A circuit breaker pauses calls after repeated failures

**Setup:** Go to **MediaVerse > Settings > AI**, set **AI Provider** to **AWS Rekognition**, and enter an AWS access key ID, secret access key and region. These are separate from your S3 storage credentials. The IAM user needs the AmazonRekognitionReadOnlyAccess policy.

### Claude (Anthropic) **(Pro)**

Adds Claude as an AI provider for image analysis, tagging and content moderation.

**Setup:** Go to **MediaVerse > Settings > AI**, set **AI Provider** to **Claude (Anthropic)**, and enter your API key.

### OpenAI Whisper **(Pro)**

Automatic speech-to-text transcription for video and audio uploads:

- Generates WebVTT caption files
- Captions show as subtitles in the video players
- Transcription runs in the background
- Choose a default language (12 languages) or Auto-detect

**Setup:** Go to **MediaVerse > Settings > AI**, find **Auto-Captions & Transcription**, and turn on **Auto-generate on Upload**. It uses the same OpenAI API key as the OpenAI provider.

## Storage Limits

MediaVerse is not a membership/commerce plugin, so it has no MemberPress, Paid Memberships Pro
or WooCommerce integration for upload quotas - that whole system (packages, credits,
membership-plugin mapping) was removed in 2.6.0. What replaces it is one optional storage
allowance: set a **Fair-use storage limit per member (MB)** on **MediaVerse > Settings > General** (0 = no
limit), and optionally override it for one member on their wp-admin profile.

## Webhooks

MediaVerse can send real-time HTTP POST notifications to external services when events occur:

| Event | Payload |
|-------|---------|
| Media uploaded | Media ID, file URL, author, type, privacy |
| Media deleted | Media ID |
| Comment posted | Comment ID, media ID, author, content |
| Reaction added | Media ID, user ID, reaction type |
| Moderation status changed | Media ID and the new moderation status |

**Use cases:**
- Notify a Slack channel when new media is uploaded
- Trigger a Zapier workflow on media events
- Sync media metadata to an external CMS or DAM
- Log moderation actions to an audit system

**Setup:** Add webhook URLs at **MediaVerse > Settings > Webhooks**. Each webhook can filter by event type.

See [Webhooks](../settings/webhooks.md) for payload formats and authentication.

## WordPress Core

### Site Health

MediaVerse adds these tests to **Tools > Site Health**:

- **MediaVerse Database Tables** - Verifies all custom tables exist
- **MediaVerse Upload Directory** - Checks that the wpmediaverse upload directory is writable
- **MediaVerse Required Pages** - Confirms the Dashboard, Explore, and Upload pages are assigned
- **MediaVerse Media Privacy** - Checks that private files cannot be fetched directly by their address
- **MediaVerse Template Overrides** - Warns when your theme has outdated copies of MediaVerse templates

### GDPR / Privacy Tools

- **Export Personal Data** - Exports all user media, comments, reactions, favorites, DMs, and follow relationships
- **Erase Personal Data** - Removes all of the above when processing an erasure request
- **Privacy Policy** - Suggests privacy policy text via WordPress's built-in privacy policy tool

See [GDPR & Privacy Compliance](../features/gdpr-privacy.md).

### REST API

MediaVerse exposes 100+ REST endpoints in the free plugin and 90+ additional endpoints in Pro, all under the `mvs/v1` and `mvs-pro/v1` namespaces. Any external application, mobile app, or headless frontend can consume the full API.

See [REST API Reference](../developer-guide/rest-api.md) and [Pro REST API Reference](../developer-guide/pro-rest-api.md).

### WP-CLI

CLI commands for automation, migration, and maintenance:

```
wp mvs stats              # Show media stats
wp mvs migrate            # Run or check database migrations
wp mvs reindex            # Make sure every media item has a stats row
wp mvs cache-flush        # Flush all caches
wp mvs prune-views        # Clean old view records
wp mvs cleanup-expired    # Remove expired access grants
wp mvs moderation-stats   # Show moderation queue stats
wp mvs optimize <id>      # Optimize one media item's image
wp mvs migrate-storage    # Migrate files between storage drivers
```

See [WP-CLI Commands](../developer-guide/wp-cli.md).

### Gutenberg Blocks

Registered by the free plugin (in the **MediaVerse** block category):

| Block | Description |
|-------|-------------|
| Media Upload | A drag-and-drop media upload form |
| Media Grid | Display a grid of media items |
| Media Player | Video and audio player for media items |
| Album Viewer | Display an album with its media items |
| Media Stats | Display a media statistics dashboard |
| Explore Feed | A discover/explore feed showing trending and recent media |
| Member Photos | A member's photos. Auto-detects the displayed BuddyPress member, the post author, or the current user |
| PDF Viewer | Embed a PDF inline using the browser's native viewer, under the same privacy as other media |

There is no Profile Edit block - profile editing ships as the `[mvs_profile_edit]` shortcode only.

See [Gutenberg Blocks](../features/blocks.md).

### Shortcodes

For classic editor and page builders:

Free shortcodes:

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
| `[mvs_member_photos]` | A member's photos |
| `[mvs_pdf_viewer]` | Inline PDF viewer |
| `[mvs_usage_history]` | The member's storage/usage history |

Pro adds `[mvs_document]` plus its compete and connector-feed shortcodes.

See [Shortcodes](../features/shortcodes.md).
