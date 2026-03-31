# WPMediaVerse — FAQ Content

Organized by category. Each answer is written for the product page FAQ block.

---

## Comparison Questions

### How is WPMediaVerse different from rtMedia?

The most important difference is where data is stored. rtMedia stores uploaded photos and videos as WordPress attachments in `wp_posts` — the same table your pages, posts, and products live in. On a community with hundreds of active uploaders, that table can grow to hundreds of thousands of rows, and every media query runs across all of them.

WPMediaVerse uses three dedicated MySQL tables (`mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`) with indexes on the columns that get queried most. Media queries only touch media data.

The second difference is independence. rtMedia is built specifically for BuddyPress and depends on it to function. WPMediaVerse is a standalone media platform — BuddyPress is one integration layer, not a requirement.

---

### How is WPMediaVerse different from BuddyBoss Media?

BuddyBoss Media is part of the BuddyBoss Platform, a closed ecosystem where every component is designed to work together and is sold as a bundle. If you use BuddyBoss, you are committing to their entire platform — their theme, their app, their pricing structure.

BuddyBoss Media also stores files as WordPress attachments in `wp_posts`.

WPMediaVerse works with any WordPress theme, any page builder, and any community plugin. You are not locked in. The data lives in its own tables, not in BuddyBoss's activity tables. If you ever want to stop using WPMediaVerse, your files stay in `wp-content/uploads` and your WordPress installation is unchanged.

---

### How is WPMediaVerse different from MediaPress?

MediaPress is also BuddyPress-dependent and also stores media as WordPress attachments. Its feature set has not changed substantially in several years.

WPMediaVerse was built in 2025 with current WordPress architecture (Interactivity API, Gutenberg blocks, REST API, WP-CLI). It is not a BuddyPress plugin with media features grafted on — it is a media platform that can optionally integrate with BuddyPress.

---

### Is WPMediaVerse a fork of any existing plugin?

No. WPMediaVerse was written from scratch. The custom table architecture means none of its internals are compatible with `wp_posts`-based plugins. Migration importers exist to help you move data from rtMedia, MediaPress, or BuddyBoss Media into WPMediaVerse — but the plugin itself shares no code with any of them.

---

## BuddyPress Questions

### Do I need BuddyPress to use WPMediaVerse?

No. WPMediaVerse is a standalone media platform. It creates its own pages (`/my-media/`, `/media/explore/`, `/media/@username/`), its own upload tools, and its own social layer — follows, reactions, comments, DMs — independently of BuddyPress.

If BuddyPress is active on your site, the integration layer activates automatically. Media tabs appear on member profiles and group pages, and the activity feed upload button becomes available. If BuddyPress is not active, none of that loads and nothing breaks.

---

### If I already use BuddyPress, will this conflict with it?

No. The BuddyPress integration is a separate module that hooks into BuddyPress's standard extension points — profile tabs, group tabs, and the activity post form. It does not replace or modify any BuddyPress templates.

The one known edge case is the lightbox. When a member clicks a photo inside the BuddyPress activity feed, the WPMediaVerse lightbox opens using a clone approach — the overlay is rendered outside the WordPress Interactivity API container to avoid conflicts with BuddyPress's own event handlers. This is a deliberate architectural decision, not a workaround.

---

### Will WPMediaVerse work with BuddyBoss Platform?

The BuddyPress integration code is compatible with BuddyBoss Platform since BuddyBoss Platform is built on BuddyPress. Profile tabs, group tabs, and activity media work. The lightbox approach handles Interactivity API conflicts the same way it does on standard BuddyPress.

Full compatibility testing with BuddyBoss Platform's custom activity layout is in progress.

---

## Performance Questions

### Will WPMediaVerse slow down my site?

No — and the architecture is specifically designed to prevent that. The plugin adds zero overhead to pages that do not display media. Media queries run against indexed tables that only contain media data, so they do not affect WordPress core query performance.

On the media pages themselves, the lightbox and interactive components use the WordPress Interactivity API with JavaScript loaded only on the pages that need it. There are no global scripts and no unused CSS loaded on non-media pages.

---

### Can this handle a large community? What is the scale limit?

WPMediaVerse has been tested with tables containing over 100,000 media items without query degradation. The `mvs_media_index` table is indexed on `user_id`, `album_id`, `status`, `created_at`, and `privacy` — the columns that appear in every feed and gallery query.

For very large communities (500,000+ items), the main scale consideration is storage, not database performance. At that scale, the Pro cloud storage drivers (S3 or BunnyCDN) are the right choice to keep media off your web server's local disk.

---

### Does it work with caching plugins?

Yes. WPMediaVerse is compatible with WP Rocket, W3 Total Cache, WP Super Cache, and object caching via Memcached or Redis. The interactive elements (lightbox reactions, comments, follow buttons) use REST API calls that are not affected by page caching.

If you cache pages, the explore feed and profile pages will serve cached HTML with dynamic interactive states loaded via JavaScript after the cached page is delivered. This is the expected behavior.

---

## Data and Storage Questions

### What happens to my media files if I deactivate the plugin?

Your media files remain in `wp-content/uploads/wpmediaverse/` exactly as they were. The three database tables (`mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`) also remain in MySQL. Deactivating the plugin does not delete any data.

If you want to fully remove all WPMediaVerse data, there is an option in admin Settings under "Danger Zone" that removes tables, uploaded files, and all plugin options. This is irreversible and requires explicit confirmation.

---

### Where are uploaded files stored?

By default, files are stored in your server's `wp-content/uploads/wpmediaverse/` directory, organized by year and month. Pro users can configure S3 or BunnyCDN as the storage driver instead. When a cloud driver is active, files are uploaded directly to cloud storage and served from there or from the CDN in front of it.

---

### Can I use my own CDN with the free version?

You can point a CDN at your uploads directory independently of WPMediaVerse — any CDN that supports pull zones will work. The Pro storage drivers are for sites that want files to be written directly to cloud storage rather than stored locally first.

---

### How does storage quota management work?

Pro admins set quotas per user role or per individual user. Quotas are defined in megabytes or gigabytes. Members see their current usage and remaining quota on their dashboard. When a member reaches their quota, uploads are blocked with a message directing them to contact the admin or upgrade their membership.

Quotas integrate with MemberPress, WooCommerce Memberships, and Paid Memberships Pro — so you can automatically assign a higher quota to members who purchase an upgraded plan.

---

## Migration Questions

### Can I migrate from rtMedia?

Yes. Pro includes a migration importer for rtMedia. The importer reads your existing rtMedia data from `wp_posts` and `wp_postmeta`, copies media files to the WPMediaVerse directory structure, creates the corresponding rows in WPMediaVerse's tables, and regenerates thumbnails in the correct sizes.

Album associations, user ownership, and privacy settings are preserved where the data exists in rtMedia's schema.

---

### Can I migrate from MediaPress?

Yes. Pro includes a MediaPress importer that works similarly to the rtMedia importer. MediaPress stores data as `bp_docs` post types with BuddyPress group and user associations. The importer maps those associations to WPMediaVerse albums and user records.

---

### Can I migrate from BuddyBoss Media?

Yes. BuddyBoss Media stores media as WordPress attachments with custom meta fields. The Pro importer handles BuddyBoss Media's specific meta structure and maps media to user profiles and BuddyPress activity posts where applicable.

---

### Will migration break my existing URLs?

Media URLs will change after migration because files are moved to a new directory structure. The migrators create redirect rules for the old attachment URLs where possible. For large sites, we recommend testing migration on a staging environment first and reviewing URL impact before migrating production.

---

## Technical Questions

### Does WPMediaVerse support multisite?

Multisite support is in the roadmap. Currently, the plugin is designed for single-site installations. The custom tables are created per-site at activation, which is compatible with network activation in principle, but the admin UI and some features have not been tested in a multisite context.

---

### How does the REST API work? Is it authenticated?

WPMediaVerse's REST API follows standard WordPress REST authentication. Endpoints that read public media are unauthenticated. Endpoints that write data, read private content, or perform administrative actions require a valid authenticated user (via cookie authentication for browser clients or application passwords for server-to-server requests).

The API has 80+ endpoints across 17 controllers. Full documentation is at [docs link].

---

### Can developers hook into WPMediaVerse events?

Yes. The plugin fires 80 action hooks and filter hooks. Actions cover upload events, moderation events, social interactions (follow, reaction, comment, favorite), messaging events, and gamification outcomes. Filters cover template output, URL generation, privacy visibility, and moderation decisions.

A full hooks reference is in the developer documentation at [docs link].

---

### Does it support PHP 7.x?

WPMediaVerse requires PHP 8.0 or higher. This is because it uses named arguments, union types, and other PHP 8 features for the AI moderation integrations and REST API controllers. PHP 8.1+ is recommended.

---

### What WordPress version is required?

WordPress 6.0 or higher. The Gutenberg blocks and Interactivity API components require WordPress 6.4+ for full functionality. The REST API and core media features work on 6.0+.

---

## Licensing and Support Questions

### What does "one year of updates and support" mean?

Your Pro license is valid indefinitely — the plugin will continue to work after your license year ends. The license year covers:

- Automatic plugin updates via your WordPress dashboard
- Access to the support ticket system and email support
- Access to new Pro features released during your license year

After your license year expires, the plugin continues working but you will not receive updates or support until you renew. Renewal pricing is discounted from the initial purchase price.

---

### Can I use Pro on a client site I build?

Yes, within the license limit. A single-site license covers one production installation. The agency license (5 sites) and unlimited license are available for developers and agencies building for clients.

---

### Do you offer refunds?

Yes. If Pro does not work for your use case, contact support within 14 days of purchase for a full refund. No questions asked. If you run into a technical problem and need help resolving it before deciding, reach out to support first — we can usually fix it faster than you expect.

---

### How do I get support?

Support is available by email at support@wbcomdesigns.com and through the support ticket system in your account dashboard. Pro license holders receive priority responses. Community support for the free version is available in the WordPress.org plugin forums.
