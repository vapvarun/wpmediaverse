# WPMediaVerse Pro

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro extends the free plugin with advanced layout modes, cloud storage, video processing, AI providers, quota management, and granular privacy controls.

![WPMediaVerse Pro license key entry screen](../images/admin-overview.png)

## Requirements

- WPMediaVerse (free) 1.5.0 or higher installed and activated (Pro halts with an admin notice on older Free)
- WordPress 6.5+
- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.4+

## Installation

1. Install and activate the free **WPMediaVerse** plugin first.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Upload the `wpmediaverse-pro.zip` file and click **Install Now**.
4. Click **Activate Plugin**.
5. Go to **Media > License** and enter your license key.
6. Click **Activate License**.

![Media License settings page with activation status](../images/admin-overview.png)

All Pro features run as soon as the plugin is activated. The license key is used only to unlock automatic updates - it does not gate any feature, so every Pro capability works regardless of license state.

## Updating Pro

When an update is available, WordPress shows it in the standard **Plugins** screen. The updater requires a valid active license. If your license has expired, your installed Pro features keep working - only automatic updates are paused. Download the latest ZIP from your account at [wbcomdesigns.com](https://wbcomdesigns.com/my-account/) and upload it manually.

## 1.2.0: All Pro features now Gutenberg blocks

Every Pro feature now ships as a first-class Gutenberg block - drop-in, configurable in the editor, no shortcodes-as-the-only-option. Each block has a matching `[mvs_pro_*]` shortcode for classic editor and template-tag use.

| Block | Handle | Notes |
|-------|--------|-------|
| Tournament | `mvs/pro-tournament` | Configurable `tournamentId` attribute. |
| Tournaments List | `mvs/pro-tournaments-list` | Lists active tournaments. |
| Challenge | `mvs/pro-challenge` | Configurable `challengeId` attribute. |
| Challenges List | `mvs/pro-challenges-list` | Lists active and upcoming challenges. |
| Battle | `mvs/pro-battle` | Configurable `battleId` attribute. |
| Battles Active | `mvs/pro-battles-active` | Currently running battles. |
| Instagram Feed | `mvs/pro-instagram-feed` | Instagram-style square grid layout. |
| Flickr Feed | `mvs/pro-flickr-feed` | Flickr-style justified rows layout. |
| Pinterest Feed | `mvs/pro-pinterest-feed` | Pinterest-style masonry layout. |
| Dribbble Feed | `mvs/pro-dribbble-feed` | Dribbble-style card grid layout. |
| Leaderboard | `mvs/pro-leaderboard` | Top performers across competitions. |
| Compete Hub | `mvs/pro-compete-hub` | Combined challenges + battles + tournaments dashboard. |

**Layout flexibility:** because each layout (Instagram, Flickr, Pinterest, Dribbble) is its own block, admins can mix layouts on different pages - e.g. a Pinterest feed on the home page and an Instagram feed on a member directory - instead of being locked to one site-wide layout setting.

### MigrationPage admin restructure

The migration tool admin page is now a generic shell that hosts per-platform cards (rtMedia, MediaPress, BuddyBoss). Two pre-existing detection bugs were fixed in the same pass: the **Imported** count was always `0` regardless of actual progress, and the MediaPress dedup query was running against an undefined `$wpdb`. Migrations now report accurate counts and skip already-imported items correctly.

## Pro Feature Categories

| Category | Page | What It Adds |
|----------|------|--------------|
| Layout Modes | [layout-modes.md](layout-modes.md) | Instagram, Pinterest, Flickr, and Dribbble feed layouts |
| Cloud Storage | [cloud-storage.md](cloud-storage.md) | Amazon S3 and BunnyCDN storage drivers |
| Video Transcoding | [video-transcoding.md](video-transcoding.md) | Async FFmpeg transcoding to 720p/480p/360p and HLS |
| Video Chapters | [video-chapters.md](video-chapters.md) | Chapter markers and resume playback |
| Auto-Captions | [auto-captions.md](auto-captions.md) | OpenAI Whisper transcription and WebVTT captions |
| Watermarking | [watermarking.md](watermarking.md) | GD-based text and logo watermarks on media |
| Video Analytics | [video-analytics.md](video-analytics.md) | Play event tracking, heatmaps, and retention reports |
| AI Providers | [ai-providers.md](ai-providers.md) | Google Cloud Vision and AWS Rekognition support |
| Quotas | [quotas.md](quotas.md) | Per-user storage and count limits with membership integration |
| Advanced Privacy | [advanced-privacy.md](advanced-privacy.md) | Multi-level privacy, presets, album inheritance, and bulk updates |
| Connected Accounts | [connected-accounts.md](connected-accounts.md) | Connect Flickr to import/export photos and auto-push new uploads |

## User Reports (Pro Moderation)

Pro adds a **Reports** view that surfaces user-submitted abuse reports on media and members - the complaints your community files, as opposed to the free [Moderation Queue](../features/ai-moderation.md), which is about AI/auto-flagged content awaiting an approve/reject decision.

- **Where:** **MediaVerse > Moderation**, in the **User Reports** tab. (It is also reachable directly at `admin.php?page=mvs-reports`.)
- **Who:** any user with the `moderate_mvs_media` capability.
- **What it lists:** every report row - date, reporter, target type (media or user), the target, the reason code, and a details excerpt. A status filter switches between **Pending**, **Resolved**, and **Dismissed**, each with a live count.
- **Actions:** on a pending report you can **Resolve** (mark handled) or **Dismiss** (no action needed). Both are nonce-protected and capability-checked.

> **Note for the parent agent:** this section documents the Pro Reports admin page inline. If the docs site wants it discoverable on its own, it could later be split into a dedicated `pro-features/reports.md` page - the content above is self-contained and ready to lift out.

## License Management

| Setting | Location | Description |
|---------|----------|-------------|
| License Key | Media > License | Your product license key from wbcomdesigns.com |
| Activation Status | Media > License | Shows active, inactive, or expired |
| Deactivate License | Media > License | Release the activation to use on another site |

A single license activates one site. Purchase additional activations from your account dashboard if you need to run Pro on multiple sites.
