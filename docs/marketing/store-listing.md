# Store Listing Copy

Ready-to-use copy for the WordPress.org listing, the Wbcom store page, and the Pro product page. Keep this file and the shipped `readme.txt` in agreement - when one changes, change both.

Tone and claim rules are in [Positioning & Messaging](positioning.md). Do not add a feature claim here that is not in [Free vs Pro](../website/getting-started/free-vs-pro.md).

## Short description

Used for the WordPress.org `Description` line. Hard limit 150 characters.

> Media community for WordPress: uploads, albums, social feeds, and direct messages, with privacy that is actually enforced.

*(139 characters.)*

## Tagline options

For the store hero and social cards. Pick one per campaign and stay with it.

- A media community that stays on your site.
- Your members' media. Your server. Your rules.
- Everything a media community needs, in one plugin.

## Long description

For the WordPress.org listing body and the store page.

---

**WPMediaVerse turns your WordPress site into a real media community.**

Members upload photos, video, and audio. They organise them into albums and collections, follow each other, react and comment, and message each other privately - all on your site, under your moderation, with the files on your own server.

**Privacy that is actually enforced**

Every media item carries one of six privacy levels: public, logged-in members, friends, group members, only me, or specific people. That level is enforced in the database query itself, so a private photo does not appear in a grid, a feed, an activity post, or a REST response - not to a logged-out visitor, and not to a member who was not meant to see it.

Albums pass their privacy down to what is inside them. Create a private album and upload into it, and the contents are private too - no separate step to forget.

**A complete social layer**

Follows, reactions, comments with mentions, favorites, sharing, and reporting. Member profiles with a media grid and follow and message buttons. An Explore feed with search, tag filtering, and infinite scroll.

**Direct messages built in**

One-to-one and group conversations, with media attachments, voice messages, read receipts, message requests, archiving, and per-member controls over who may start a conversation. No second plugin.

**Moderation that scales past friendly**

A moderation queue with approve and reject, member reporting with an auto-hide threshold, member suspension, user blocking, and optional AI content moderation through OpenAI. Duplicate detection stops the same file being uploaded repeatedly. EXIF stripping removes GPS coordinates from photos before they are published.

**Made for the WordPress you are running now**

Blocks built on the Interactivity API, block theme support, `theme.json` styling, dark mode, and full keyboard and screen reader support. Works standalone, and integrates with BuddyPress for profiles, groups, activity, and notifications.

**A REST API you can actually build on**

Over 90 REST routes covering everything the built-in screens do - not a read-only subset. Build a custom front end, a companion app, or an integration, without forking the plugin. Over 250 hooks and filters in the free plugin for everything else.

**GDPR ready**

Data export and erasure, member self-service account deletion with a grace period, and a documented map of exactly which tables hold member data.

**Free is not a trial.** Everything above is in the free plugin.

---

### Pro

WPMediaVerse Pro is for sites that have outgrown one server, or that want media to earn its keep.

- **Cloud storage** - Amazon S3, BunnyCDN, Cloudflare R2, or DigitalOcean Spaces, with signed URLs for private media
- **Video** - chapters, resume playback, auto-captions, and engagement heatmaps
- **Sell storage** - quota packages integrated with MemberPress, Paid Memberships Pro, and WooCommerce
- **Gamification** - photo challenges, head-to-head battles, tournament brackets, boosts, and streaks
- **Layout modes** - present the community as Instagram, Pinterest, Flickr, or Dribbble
- **Advanced AI** - Google Cloud Vision, AWS Rekognition, and Claude for tagging and moderation
- **Migration** - import from rtMedia, MediaPress, or BuddyBoss with a single WP-CLI command
- **Watermarking** and advanced privacy tools

## FAQ

**Does this require BuddyPress?**
No. WPMediaVerse works on a standalone WordPress site. If BuddyPress is active it integrates with profiles, groups, the activity stream, and notifications.

**Where are the files stored?**
In your uploads directory, under `wp-content/uploads/wpmediaverse/`. Pro can move them to S3, BunnyCDN, Cloudflare R2, or DigitalOcean Spaces.

**Is the free version limited?**
No. Uploads, albums, collections, the social layer, direct messages, AI moderation, BuddyPress integration, GDPR tools, and the REST API are all free. Pro adds cloud storage, video analytics and auto-captions, monetization, gamification, and layout modes.

**Can members control who sees their uploads?**
Yes - six privacy levels, enforced at the query. Site owners can also set a default and turn off member-chosen privacy.

**Will it work with my theme?**
It uses your theme's `theme.json` tokens and supports block themes and dark mode. Templates can be overridden from your theme like WooCommerce templates.

**Can I migrate from rtMedia, MediaPress, or BuddyBoss?**
Yes, with Pro - one WP-CLI command per platform, batched, with progress output and duplicate protection.

**Does it work on a big site?**
Lists, grids, and archives are paginated with indexed queries and dedicated count queries rather than loading everything into memory.

**Is there a mobile app?**
Members can sign in to a native client with their normal WordPress password. The REST API covers the full member surface.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- Pro requires the free plugin at the same version

## Screenshots

Captions for the listing gallery. Assets are in [`raw/`](raw/) and [`feature-cards/`](feature-cards/).

1. The member dashboard - every upload at a glance
2. Explore - search, tags, and infinite scroll
3. A single media item with reactions and comments
4. Albums and collections
5. Direct messages with attachments and voice notes
6. The moderation queue
7. Admin settings
8. The Compete hub - challenges, battles, and tournaments (Pro)

## Related

- [Positioning & Messaging](positioning.md)
- [Launch kit](launch-kit.md)
- Shipped changelog: `readme.txt`
