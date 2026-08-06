=== WPMediaVerse ===
Contributors: vapvarun, wbcomdesigns
Tags: media, gallery, buddypress, social media, albums
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 2.3.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The media layer your community site is missing. Custom database tables, AI moderation, and a full social layer - without requiring BuddyPress.

== Description ==

**[Try Live Demo](https://app.instawp.io/launch?s=wpmediaverse&d=v2)** | **[Get Pro](https://store.wbcomdesigns.com/wpmediaverse-pro/)** | **[Documentation](https://store.wbcomdesigns.com/wpmediaverse/docs/)**

WPMediaVerse is a complete media platform for WordPress - built on custom database tables, not wp_posts. Your community gets photo uploads, albums, reactions, comments, follows, direct messaging, AI moderation, and a full lightbox experience. Your site stays fast no matter how many uploads come in.

**Why WPMediaVerse?**

Every other WordPress media plugin (rtMedia, MediaPress, BuddyBoss Media) stores uploads in wp_posts. On active communities, that table grows into tens of thousands of mixed rows. WPMediaVerse uses three dedicated, indexed tables - media queries never touch your posts, pages, or products.

**What You Get (Free)**

* **Custom Table Architecture** - Three indexed tables keep media separate from WordPress core data
* **Media Uploads** - Drag & drop with MIME validation, EXIF stripping, duplicate detection, thumbnail generation
* **Albums & Collections** - Ordered albums with cover images, smart collections with auto-curation rules
* **Social Layer** - Reactions (6 types), threaded comments, favorites, @mentions, follow/unfollow, sharing
* **Direct Messaging** - Text and media messaging between members, no third-party service needed
* **AI Moderation** - OpenAI Vision scans uploads automatically. Flag, quarantine, or reject before they go public
* **Privacy Controls** - 6 levels per upload: public, members-only, friends-only, group, private, custom
* **Explore Feed** - Public media grid with filtering by tag, album, user, and media type
* **Lightbox** - Full-screen with reactions, comments, favorites, share, gallery navigation - no page reload
* **BuddyPress Integration** - Activity uploads (1-6 per post), profile/group media tabs, lightbox in feed
* **9 Gutenberg Blocks** - Media grid, upload, player, album viewer, explore feed, member photos, and more
* **80+ REST API Endpoints** - 23 controllers covering every operation for headless/decoupled builds
* **18 WP-CLI Commands** - Bulk operations, migrations, cache management, moderation
* **8 Shortcodes** - Drop media features into any page or widget
* **Webhooks** - Outbound event webhooks with HMAC-SHA256 signing via Action Scheduler
* **GDPR** - Full data export and erasure via WordPress privacy tools

**Pro Adds**

* 5 layout modes (Grid, Instagram, Pinterest, Flickr, Dribbble)
* Photo Challenges, 1v1 Battles, Tournament Brackets
* Points, Streaks, Boosts gamification engine
* S3 and BunnyCDN cloud storage drivers
* Video transcoding with HLS adaptive streaming
* Auto-captions via Whisper AI
* Per-user storage quotas (MemberPress, WooCommerce, PMPro integration)
* Voice messages, read receipts, typing indicators in DMs
* Google Vision + AWS Rekognition moderation
* Migration importers (rtMedia, MediaPress, BuddyBoss)

**For Developers**

* PSR-4 architecture with service container and lazy-loaded dependencies
* 80+ action and filter hooks for extensibility
* Template override system - copy to your theme and customize
* AI provider abstraction - bring your own provider
* Storage driver pattern - local, S3, BunnyCDN, or custom
* WordPress Interactivity API - zero legacy JavaScript

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

* `[mvs_gallery]` - Media grid
* `[mvs_upload]` - Upload form
* `[mvs_album id="123"]` - Album viewer
* `[mvs_player id="456"]` - Media player
* `[mvs_stats]` - Stats dashboard
* `[mvs_dashboard]` - User dashboard
* `[mvs_collection]` - Collection display
* `[mvs_profile_edit]` - Profile editor

== Screenshots ==

1. **Explore Page** - Instagram-style media grid with search and tag cloud filtering.
2. **Dashboard** - User media management with albums, favorites, and collections tabs.
3. **Single Media** - Full media view with reactions, comments, favorites, and sharing.
4. **Album View** - Album page with cover image, item grid, and sequential playback.
5. **Admin Overview** - At-a-glance stats, quick links, recent uploads, and system status.
6. **Settings** - Tabbed settings with upload limits, display options, permissions, and AI config.
7. **BuddyPress Profile** - Media tab on user profiles with album support.
8. **Moderation Queue** - AI-flagged media review with approve/reject workflow.

== Changelog ==

= 2.3.1 - August 2026 =

Images served from a CDN render again, video covers show up, and uploading gains the fields it was missing.

* New      - The profile and group Media tabs now have title, caption, tags and privacy fields on upload, matching every other upload screen.
* New      - The lightbox now has an Edit button on your own media, so a title or caption can be added after uploading.
* New      - Album contents can now be read over the REST API. Asking for an album's items previously returned "not found", so apps and custom clients could not list an album.
* Improve  - Migrating media to or from cloud storage now repoints every stored variant URL, so nothing is left pointing at the previous location.
* Improve  - Grids of videos no longer download part of every video file just to draw a thumbnail.
* Improve  - Opening a photo full size now loads the 1024px version instead of the untouched original, so the first view is much faster. Sites that want the original back can switch Lightbox Image Size in Display settings.
* Improve  - Media grid rendering tightened.
* Improve  - Reaction buttons on a single media page now report their pressed state to screen readers, matching the lightbox.
* Fix      - Migrating media with WP-CLI now moves thumbnails and WebP/AVIF files too, not just the original. Previously every derived image was left behind and broke once the media pointed at the CDN. Sites migrated from the admin screen were never affected.
* Fix      - Backfilling cloud thumbnails now also uploads the full-size WebP and AVIF files, which repairs single media pages left broken by an earlier migration.
* Fix      - Uploading several files at once from the Upload page or the member dashboard now posts one activity item with all the images, instead of a separate feed entry per file. The upload modal already worked this way.
* Fix      - A multi-file upload from the member dashboard now keeps its description, so the grouped activity item is no longer posted without its caption.
* Fix      - The "Upload 0 file(s)" bar no longer appears on the member dashboard when no files are selected.
* Fix      - Video covers no longer render blank. Grids were forcing the browser to paint the frame at 0.1 seconds, which covered up the generated poster, so any video opening on a fade-in, title card or white intro showed an empty tile. Posters and the bundled fallback cover are now what you see.
* Fix      - Videos uploaded from the Upload page or the member dashboard now get a cover captured in the browser, so a site without ffmpeg installed no longer ends up with cover-less videos.
* Fix      - WebP and AVIF images no longer return 403 on sites that serve media from a CDN. Variant URLs now resolve through the same storage driver as the JPEG instead of a stale local path.
* Fix      - Admin media details links to a variant file now point at the file's real location, so they open instead of failing on CDN-backed sites.
* Fix      - A media item can no longer be saved without a title. The Save button stays disabled while the field is empty, and the API refuses a blank title instead of storing one.
* Fix      - The album and media-player blocks now explain themselves when they point at something missing, instead of rendering an empty space. Editors see the reason; visitors see nothing.
* Fix      - The storage panel no longer counts private media as being in the cloud. The three tiles now add up to the library total.

= 2.3.0 - August 2026 =

Photos taken on a phone keep their orientation, media added to a private album stays private, and chat gets an Archived tab.

* New      - Archived conversations tab in chat. Archiving hides a conversation without deleting it, and it returns on its own when the other member writes to you again.
* New      - Members can sign in to a native app with their existing WordPress password; the site issues an Application Password behind the scenes.
* New      - App Sign-In setting on the General tab, so a site owner can turn that password exchange off without writing code. On by default.
* Improve  - Images uploaded from a phone are rotated to match their EXIF Orientation, on upload and on Replace file, so portrait shots stop appearing sideways.
* Improve  - Message-request counts are read from actual pending requests, so the badge no longer shows 0 while requests are waiting.
* Improve  - Chat messages are grouped by day with Today and Yesterday headings.
* Improve  - Plugin pages on block themes now render the site header, navigation and footer. Previously they appeared with no site chrome at all, which made a single media page feel disconnected from the site.
* Fix      - Media added to a private album now inherits the album's privacy instead of staying publicly visible.
* Fix      - Paginated Explore and member pages returned HTTP 404 while displaying the correct content, which kept them out of search results and stopped caches storing them.
* Fix      - Plugin pages no longer write a PHP deprecation notice to the debug log on every load under a block theme.
* Fix      - Tall portraits are no longer clipped in the lightbox.
* Fix      - The activity feed no longer distorts single images by cropping them to a fixed height.
* Fix      - Double-clicking Post no longer publishes the same comment twice.
* Fix      - Album counts ignore media that is in the trash.
* Fix      - Opening a conversation no longer sends the other member a "sent you a message" notification when no message was sent.
* Fix      - Demo data can be removed and re-imported. The importer previously refused to run once demo data had been deleted, and reported that refusal as a success.
* Fix      - The "Also share as a story" toggle no longer appears when WPMediaVerse Pro is inactive to handle it.
* Fix      - The album dropzone is visible on sites without BuddyPress, and album assets load correctly under client-side navigation.
* Security - POST /auth/app-password is classified in the REST write gate.
* Dev      - New filters: mvs_apply_exif_orientation, mvs_album_inherit_privacy, mvs_comment_duplicate_window, mvs_dm_unarchive_on_activity. New action: mvs_media_privacy_clamped_by_album.
* Dev      - Removed 7 generated -rtl.css stylesheets and the rtlcss build step. Nothing loaded them, and serving them would double-flip right-to-left layouts, which already render correctly without them.
* Dev      - The moderation queue route is GET /moderation. The REST reference previously documented /moderation/queue, which returns 404.
* Dev      - Documentation now covers every REST route, hook, setting, shortcode, block and WP-CLI command. Four watermark hooks that never existed in the code were removed from the reference.
* Compat   - Paired with WPMediaVerse Pro 2.3.0. Install both updates together.

= 2.2.1 - July 2026 =

* Fix      - Fatal error on activity feeds containing media imported from BuddyBoss. The feed crashed instead of rendering when an activity stored its attachment ids as a list.
* Fix      - Chat messages sometimes did not arrive until the member reloaded or switched threads. A message sent in the same second as the live-update cursor was skipped, and the chat never asked for it again.
* Fix      - Chat reactions on an already-delivered message sometimes never appeared for the other member until they reloaded the page. A reaction added in the same second as the live-update cursor was skipped, which lost roughly one reaction in five.
* Fix      - Deleting a conversation and messaging the same member again no longer leaves the other member with two chat threads. Delete now clears your copy of the history and the pair keeps a single conversation.
* Fix      - The conversation list shows a Photo, Video, or Audio placeholder when the newest message is a media attachment with no text, instead of a blank line.
* Fix      - Media action buttons on BuddyPress profile tabs and the My Media dashboard (Upload Media, Create Album, Edit Media, collections) are readable in dark mode with visible hover states.
* Fix      - Opening an album and clicking Share no longer shows a link icon next to the share icon.
* Fix      - The chat composer's attachment chip stayed light grey in dark mode.
* Compat   - Aligned with WPMediaVerse Pro 2.2.1. Install both updates together.

= 2.2.0 - July 2026 =

* New      - Private-community gate: when the host community is private, the whole REST surface - Free and Pro namespaces - requires login, so nothing leaks to logged-out visitors. Developers: mvs_rest_require_auth, mvs_rest_can_access, mvs_rest_gated_route_prefixes.
* Improve  - Reactions added to an already-delivered message now appear for the other member in real time, without a page reload.
* Improve  - DM attachments accept media files only - image, video, and audio. Site owners can extend the list with the mvs_dm_allowed_file_types filter.
* Improve  - Chat shows an upload chip with a progress spinner for video and audio attachments, and Send waits until the upload finishes so a message can never go out without its file.
* Fix      - Follower and following counts on member profiles update immediately after Follow or Unfollow, on every layout.
* Fix      - The lightbox Favorite heart fills and turns red when favorited - the state toggled but the color was stuck.
* Fix      - The Settings saved notice can be dismissed, fades out on its own, and no longer follows you across settings sections.
* Fix      - Fatal error on BuddyBoss and older BuddyPress where bp_get_group_url() does not exist.
* Dev      - Messaging service accepts an optional backdated created_at on conversations and messages, so a migration can replay a source DM history with its original dates. Live behavior is unchanged when the argument is absent.
* Compat   - Aligned with WPMediaVerse Pro 2.2.0. Install both updates together.

= 2.1.0 - July 2026 =

Member safety and privacy release: block, suspend, and report tools, member-initiated account deletion, and a complete GDPR export and erase map. Paired with WPMediaVerse Pro 2.1.0 - install and test both together.

* New      - Members can delete their own account, with a confirmation step and a grace window they can cancel.
* New      - Report tools are on by default, and Free now has a moderation reports queue for site owners.
* New      - Site owners can suspend a member, and every member has a reachable blocked-members list.
* New      - GDPR personal-data export and erasure cover every member-bearing table through one privacy map.
* New      - A configurable Large image size, plus a WP-CLI command to regenerate thumbnails after changing it.
* Improve  - Every REST write between members passes through one block-and-suspension gate, so a blocked or suspended member cannot act on another.
* Improve  - Erasing a member keeps shared records - the reports filed about them, and conversations others are still in - and anonymises them instead of destroying the evidence or the thread.
* Improve  - The app config endpoint exposes the site's terms, privacy, and safety surface for the mobile app.
* Improve  - REST timestamps are normalised to UTC ISO-8601 across the API, and reactions now report their timestamp.
* Improve  - Direct messaging reaches app parity, with accessibility and SEO fixes on the messaging surface.
* Improve  - Automatic image compression is now off by default, so uploaded photos keep their original quality unless you turn it on; removing hidden GPS and camera data still happens automatically, with no quality loss.
* Fix      - A page whose template another plugin resolved to null no longer triggers a fatal error on the front end.
* Fix      - The direct-message typing indicator now works on sites without a persistent object cache.
* Fix      - Dark mode no longer renders body text near-black on dark surfaces, and media surfaces pair foreground with background.
* Dev      - Added mvs_conversation_participants.typing_until and a rank_scan index on mvs_media_index (Migrator v22).
* Compat   - Lockstep with WPMediaVerse Pro 2.1.0. Install and test both together.

= 2.0.0 - July 2026 =

Major release: upload watermarking, full frontend translation readiness, privacy hardening across albums, collections, and protected media, plus a batch of activity, moderation, and image-pipeline fixes. Paired with WPMediaVerse Pro 2.0.0 - install and test both together.

* New      - Admin-global watermark stamped into every uploaded image at upload time, with a single stamp owner and no replace-file bypass.
* New      - Create a new album directly from the upload modal.
* New      - Explore feed scope filters for "followers" and "self" across all layouts.
* New      - Viewer-aware URLs for the full original file via the new get_url_for_viewer() method.
* New      - Filterable avatar and profile-link seams for BuddyNext integration.
* Improve  - The whole frontend is now translation-ready: every Interactivity store, the shared-ui store, classic scripts, and the messaging module ship translatable strings, with a standard i18n delivery pipeline.
* Improve  - Album and collection privacy is enforced at the database level, so visitor pagination counts stay correct and private items never leak.
* Improve  - Protected media shows a login gate in the media slot instead of a 404, and keeps the correct 403 status.
* Improve  - Private collection single views and their REST responses are now gated; they previously leaked title and structure.
* Improve  - Moderation auto-action defaults to Flag instead of Delete.
* Improve  - Direct-message reactions can be removed and are correctly attributed.
* Improve  - App pages use the theme's no-sidebar page template for a cleaner full-width layout.
* Improve  - The admin sidebar label "Moderation" is now "Media Moderation" to avoid ambiguity.
* Fix      - Album and collection privacy-stub rows no longer appear as broken tiles on Explore, the media grid, or the media feed, and their index row is purged on delete.
* Fix      - Favorite, Save, Share, and Download buttons work in the media lightbox inside the BuddyPress activity stream.
* Fix      - Batch uploads to an album show a "View all photos" link in the activity entry.
* Fix      - BuddyPress notifications are cleaned up when media is deleted, and commenting on activity media no longer sends a duplicate notification.
* Fix      - GPS location data is stripped from photos without a lossy re-encode, preserving image quality and the colour profile.
* Fix      - Moderation actions no longer corrupt unrelated posts.
* Fix      - Watermark stamping fails closed instead of passing an unstamped image through on error.
* Dev      - New filter mvs_suppress_bp_comment_notification controls the BuddyPress comment-notification bridge.
* Dev      - MediaRepositoryInterface now declares get_url_for_viewer().
* Dev      - Removed the dead watermark preview and serve system and retired the admin access-rules UI; access-rule enforcement stays in the backend.
* Compat   - Lockstep with WPMediaVerse Pro 2.0.0. Install and test both together.

= 1.9.0 - July 2026 =

* New      - Interests and personalized onboarding: members choose interests, get suggested people to follow, and see an interest-aware feed.
* New      - Per-conversation message search in direct messages.
* New      - Direct messages run through the same content moderation as posts and comments, so blocked words can't be planted in a DM.
* New      - Followers and following open in a modal from the profile counts, and member profiles gain an overflow menu with Report.
* New      - Manage blocked members from Edit Profile.
* New      - "Also share as a story" option in the upload flow.
* New      - Access-rules admin screen for gating media, with watermark display wired to the configured logo.
* New      - Native-app readiness across the mvs/v1 API: /app/config, interest endpoints, and viewer-aware fields.
* New      - Child themes can override MediaVerse templates; member partials, messages, and layout pages resolve theme-first.
* New      - AI moderation adds the Anthropic (Claude) provider, owner-configurable flag criteria, and custom flag terms beyond the built-in categories.
* New      - Masonry (original aspect ratio) is now the default Explore grid style, with an escape hatch back to the fixed grid.
* Improve  - Media links open the activity they were posted in by default. /media/{slug}/ redirects to that feed post, so media is not a separate public page; switch to dedicated media pages under Settings if you want a standalone page per item. Applies when BuddyNext is active.
* Improve  - Simplified upload modal: the type tabs are gone and the media type is auto-detected.
* Improve  - Non-public media now uses a viewer-aware thumbnail URL so previews respect visibility.
* Improve  - Gated images show the watermarked variant unblurred (the watermark is the protection); older imports self-heal through an automatic storage-path repair.
* Fix      - Media comments no longer leak onto unrelated posts or pages. They were stored against the post that shared the media's numeric ID, so a comment on an image could appear under a post with the same ID and inflate its comment count; existing comments are repaired automatically on update.
* Fix      - Competition pages (Compete, Battles, Challenges, Tournaments) now keep their MediaVerse styling when BuddyNext is active, instead of rendering unstyled.
* Fix      - Smart collections report their real item count in the list.
* Fix      - The media feed no longer returns empty when group covers are combined with trending or popular sorting.
* Fix      - AI moderation now runs when only auto-moderate is enabled (it was skipped before).
* Fix      - Theme-defense fixes for BuddyX and Reign (tab text, lightbox open) and Instagram action buttons hardened against theme button styles.
* Fix      - Cloud storage display and migration are more robust across admin operations.
* Dev      - Functional certification engine (wp mvs cert): boot smoke across every REST route plus dead-toggle oracles, gated at 100% coverage in CI.
* Dev      - Resolved all WP.org Plugin Check errors (30 to 0).

= 1.8.0 - June 2026 =

Configurable AI moderation with a new Claude provider, working image and text watermarks, cloud-storage display and migration fixes with an automatic one-time repair, a Wbcom Integrations page, member blocking, a masonry media grid (now the default), and faster client-side navigation. No database schema change.

* New      - Integrations page lists the Wbcom plugin family with product logos, store links, and a one-click companion installer.
* New      - REST POST /conversations accepts an as_request flag to open a conversation as a pending message request the recipient accepts or declines, so native apps can start message requests through mvs/v1 alone.
* New      - Members can block or unblock another member from their profile (Block toggle next to Follow and Message); blocking already hid that member's media from the feed and refused follows and direct messages, but there was previously no way to do it from the frontend.
* New      - AI moderation criteria: AI Flag Criteria checkboxes (nudity, violence, hate, self-harm, drugs, spam) let owners choose what the AI flags, plus a Custom Flag Terms field to add their own (e.g. weapons, gambling, political content). All categories enabled by default.
* New      - Claude (Anthropic) AI provider for image analysis, tagging, and moderation, alongside OpenAI, Google Vision, and AWS Rekognition.
* New      - "Delete permanently" auto-action for AI-flagged content removes the media and its files from local and cloud storage.
* New      - Masonry media grid: the Explore and media-grid feed shows every image at its native aspect ratio, packed with no cropping or gaps. Set Thumbnail Style to Square in Settings to keep uniform cropped tiles.
* Improve  - The Explore lightbox now opens in place instead of stacking over the previous screen.
* Improve  - Explore and the dashboard share one REST client with document-level event delegation, so Load More and other actions keep working after client-side navigation.
* Improve  - Dark mode now follows the active BuddyX/Reign theme toggle (data-bx-mode) and uses shared elevation tokens.
* Improve  - The AI settings tab shows only the selected provider's credentials (pick a provider, see its key and model); the per-call cost is plugin-managed instead of a manual field.
* Improve  - The masonry (original aspect ratio) grid is now the default Thumbnail Style; sites that prefer the uniform square crop can restore it in Settings > Display or with the mvs_default_thumbnail_style filter.
* Improve  - Grid tiles now serve the larger thumbnail so they stay sharp on HiDPI/retina screens; the smaller size visibly upscaled inside the bigger masonry tiles. Byte-conscious sites can drop back with the mvs_grid_thumb_size_key filter.
* Fix      - Selecting Watermark Type "Image" now applies the configured logo; the chosen attachment is passed to the watermark renderer instead of being dropped.
* Fix      - Restricted (gated) images now display the watermarked preview to visitors without access, instead of the plain blurred thumbnail that was shown before. Watermarking applies to images only, never video or audio.
* Fix      - Like, comment, bookmark, share, and other action buttons on the Instagram feed layout now run their action instead of opening the media lightbox.
* Fix      - The dashboard, create-modal, and chat tabs plus the lightbox reaction bar and action buttons keep their flat, readable styling (with visible labels) under themes that restyle plain buttons (BuddyX, Reign), instead of turning into filled colored buttons or invisible white-on-white text.
* Fix      - Direct-message attachments are scoped to their conversation so only participants can view them.
* Fix      - A new message is delivered again after both members had deleted the conversation.
* Fix      - The BuddyPress component-link cleanup no longer removes live navigation owned by another community plugin; it is off by default and never deletes a link that resolves to a real page.
* Fix      - Lightbox Share now works on non-HTTPS sites by falling back to a temporary-textarea copy.
* Fix      - The new-chat search no longer autofocuses on load, which was causing a page scroll-jump.
* Fix      - Media replace reprocesses variants, custom post type archives render, and settings defaults apply correctly.
* Fix      - Paginated Explore and member profile pages (page 2 and beyond) now return HTTP 200 instead of a soft 404, so search engines keep them indexed and page caches serve them.
* Fix      - Secondary buttons such as View Entries and Browse no longer borrow the active theme's primary button color, so the label stays readable instead of low-contrast text on a colored fill.
* Fix      - Smart collections with more than one rule of the same type now work. Two rules of the same kind (e.g. media type Image plus Video, or two tags) are matched with OR instead of AND, so the collection returns items and shows a real cover instead of resolving to zero and showing the placeholder; rules of different kinds still combine with AND.
* Fix      - The demo-data importer reuses an existing same-named collection instead of stacking duplicate copies when it is run more than once.
* Fix      - "Followers only" online-status visibility now hides presence from non-followers; previously the option behaved like "Everyone".
* Fix      - Video and audio play analytics record from the main media page, not only the media-player block (the player wires the Pro events endpoint when Pro is active).
* Fix      - Video tiles show the video's first frame instead of a generic placeholder on My Media, the explore-feed block, and the Pinterest/Flickr/Dribbble/Instagram layout grids (including Load More), matching the Explore grid. All client-side thumbnail renderers now follow the same video-first contract as the server.
* Fix      - The story viewer plays video and audio stories instead of showing a broken image; it renders the correct element per media type.
* Fix      - Media uploaded before a cloud service was connected now displays correctly: each file is served from where it actually lives instead of a fabricated CDN URL that returned 404. Switching between cloud services also keeps existing media readable until it is migrated.
* Fix      - Migrating storage moves a media's thumbnails and other variants alongside the original, and only flips the media to the new service once the full set has transferred, so thumbnails keep working after a Migrate all instead of 404ing on the new service.
* Fix      - "Free up server space" never deletes a local file whose cloud copy cannot be verified, preventing permanent loss of thumbnails that were not uploaded.
* Fix      - Storage paths left inconsistent by earlier versions are repaired automatically in the background after update: media imported from another plugin (which pointed at the original file in place) is copied into the library so it displays, and thumbnails left on the server after an older cloud migration are moved to the cloud. The repair runs in small batches, only touches affected media, and can be disabled with the mvs_storage_repair_enabled filter or run manually with wp mvs repair-storage.
* Fix      - The standalone /messages/ page now loads the conversation list; it enqueues the shared REST client and defers to BuddyNext when active.
* Fix      - Removed a dead webhook event (media.updated) and dead activity types that could never fire.
* Dev      - New mvs_apply_watermark_preview filter to skip the watermark for specific uploaders or roles; the default watermark position is now bottom-right.
* Dev      - New filters mvs_ai_moderation_terms (add AI flag terms in code) and mvs_ai_cost_per_call (per-provider cost estimate); the messaging transport resolves MessagingService via the container.
* Dev      - New UploadService::sideload_external_file() is the canonical way to bring an outside file into the library as a relative path; new mvs_storage_repair_enabled filter and wp mvs repair-storage command gate and run the storage-path repair.
* Dev      - New mvs_default_thumbnail_style filter and SettingsHelper::get_thumbnail_style() resolve the grid thumbnail style (escape hatch for the square-to-original default change); the original mode now renders as a CSS-column masonry.
* Dev      - New filters mvs_strip_dead_bp_links, mvs_dead_bp_link_patterns, mvs_dm_denial_message, and mvs_dm_denial_reason; MessagingService::find_or_create_conversation() gains a force_request option; WatermarkService::get_config() now exposes image_id for the Pro renderer.
* Dev      - The frontend was refactored onto a shared window.mvsRest client plus a router store and region partials for client-side navigation.
* Dev      - New mvs_collections_enabled filter lets a collections backend render a "Save to collection" control next to the favorite heart; the lightbox exposes the actions.lightboxOpenCollections action and dispatches an mvs-collections-click event carrying the current media id.
* Dev      - Inline styles in blocks, BuddyPress activity renderers, and frontend templates were moved to tokenized stylesheet classes, so theme and child-theme CSS can target them.
* Compat   - Pairs with WPMediaVerse Pro 1.8.0. Install both updates together.

= 1.7.0 - June 2026 =

Performance and reliability pass: a large media-grid query reduction plus seven QA-driven fixes. No database schema change.

* Improve  - Media grid render cut from about 170 database queries per page to about 6 by prefetching media meta and access rules before the grid loop.
* Improve  - Grid and feed thumbnails now honor the configured thumbnail size, and the default is now medium instead of large.
* Fix      - Updating a media item's categories no longer silently drops them on a persistent object cache miss; the editable media REST route now declares its real fields.
* Fix      - Posterless videos now show the bundled default poster in grids and REST responses instead of a blank tile.
* Fix      - Private media now records an activity row so it appears in the owner's own activity, still privacy gated for everyone else.
* Improve  - Public media served from the local driver now sends a stable URL with Cache-Control public headers so browsers and CDNs can cache it; private media stays no-store.
* Dev      - The mvs_notification_created action now passes the rendered message and link, keeping BuddyNext and other listeners in sync.
* Dev      - New filters mvs_stable_public_urls, mvs_public_media_max_age, mvs_public_local_file_url, mvs_public_local_thumbnail_url. New Site Health test for missing video posters. The mvs_thumbnail_size default changes from large to medium.
* Compat   - Pairs with WPMediaVerse Pro 1.7.0. Install both updates together.

= 1.6.0 - June 2026 =

Privacy hardening across every surface, a large messaging upgrade with group-conversation support, AI-assisted alt text and tagging, and a long list of QA-driven fixes.

* New      - Group-conversation messaging engine with participant roles. Pro 1.6.0 builds group DMs on top of it.
* New      - Per-user messaging controls. Members choose who can DM them and whether their online status is visible, from the dashboard profile editor.
* New      - AI describe results now double as image alt text, with an admin review surface to accept or re-run AI output per media item.
* New      - WP-CLI command wp mvs backfill_ai runs AI describe and tagging on media uploaded before AI was enabled.
* New      - Upload review step. Dashboard and block uploads stage files for a title/description/tags details screen before the upload starts.
* New      - Friends privacy option available in the frontend upload, edit, and album privacy selects.
* New      - Media poster name on grids links to the uploader's profile.
* New      - Async storage-cleanup cycle reclaims local and cloud files when media is deleted, with retry and logging for failed deletes.
* Improve  - Uploads are limited to image, video, and audio. PDF and document uploads are rejected, including via the replace endpoint, and the custom MIME types UI is removed.
* Improve  - Media filenames are hashed by default so uploaded file names no longer leak original names.
* Improve  - Message delete is idempotent, returns precise HTTP codes, and removes the message from the thread and conversation preview immediately.
* Improve  - Notifications dropdown header stays visible while scrolling, and admin data tables scroll horizontally on mobile.
* Improve  - Report UI is Pro-only. The free plugin hides report buttons behind the mvs_reports_enabled filter.
* Fix      - Members-only and private media no longer leak to logged-out or unauthorized viewers across 8 render surfaces and 2 REST routes, including BuddyPress album tabs and activity.
* Fix      - Smart collections with multiple rules resolved to 0 items because query parameters were bound out of order. Tag and category rules also survive the edit modal now instead of being corrupted to names.
* Fix      - Comment @mentions fire notifications, and duplicate reaction, DM, and mention notifications are gone.
* Fix      - Deleting media, albums, collections, or users cleans up every dependent row, including GDPR erasure paths.
* Fix      - Explore Load More renders all media types with thumbnails, and the Load More stack is registered globally for shortcode and block pages.
* Fix      - Single media pages no longer double-count video and audio views.
* Fix      - Permissions matrix covers all roles, survives updates, and can no longer lock out administrators.
* Fix      - Allowed File Types settings persist when a type is unchecked, webhook event selection cannot silently reset, and the webhook HMAC secret survives an empty save.
* Fix      - Activity composer no longer collides with the options row, and activity-form uploads surface errors and accept media tags.
* Fix      - Video poster metadata no longer writes the video URL as a thumbnail, and posters render at the large size.
* Fix      - Album creation slug collisions no longer drop privacy and album type.
* Fix      - Page creation on activation never edits site navigation menus.
* Fix      - Missing thumbnail files no longer break media grids. When a resized variant is absent on disk, the original image is served instead of an error.
* Fix      - Public media now displays correctly on page-cached hosts. Expired-but-authentic image URLs in cached HTML still serve public files; non-public media keeps the strict expiry window. Disable via the mvs_serve_expired_public_urls filter.
* Security - PDF-upload bypass through the media replace endpoint is closed, and replace honors the same type allowlist as upload.
* Dev      - New filters mvs_collection_media_ids, mvs_reports_enabled, mvs_media_alt_text, mvs_hold_uploads_for_moderation, mvs_profile_privacy_levels, and mvs_serve_expired_public_urls; new actions mvs_album_deleted and mvs_collection_deleted; mvs_media_deleted now fires once from the delete cascade.
* Compat   - Aligned with WPMediaVerse Pro 1.6.0. Install both updates together.

= 1.5.0 - May 2026 =

Non-public uploads now render their own thumbnails. Upload and serve pipeline unified so the bug pattern cannot recur. Legacy broken video posters heal automatically on update.

* Fix     - Non-public uploads no longer 403 their own thumbnails after upload. Members, Friends, Only Me, Group, and Custom-access media now serve correctly to the owner and to viewers granted access, on local storage and on every cloud driver.
* Fix     - Private uploads now leave zero public footprint. No BuddyPress activity entry is created for private media, the profile activity tab does not surface broken thumbnail cards, the profile media tab badge no longer counts private items for other viewers, and the explore grid stays clean. Other non-public privacy levels (Members, Friends, Group, Custom) keep their audience-discovery semantics.
* Fix     - Video poster thumbnails for items uploaded before 1.5.0 are healed on update. Database migration v15 re-derives the poster path meta from the on-disk file location for video and audio rows so cards, lightbox, and feed previews render the correct still frame.
* Improve - One unified read path for media URLs. Theme overrides and shortcode users can call the same Core MediaUrl helper that templates use, so custom integrations no longer have to know about signed URL plumbing.
* Improve - Upload pipeline produces one consistent file layout for every media type. Image variants, video posters, audio cover art, and WebP and AVIF siblings all flow through the same writer so adding a new format in the future is one extension point, not five.
* Improve - WebP and AVIF sibling generation collapsed to one shared publisher. Removes a duplicate-write footgun where the WebP and AVIF paths could disagree about the destination directory.
* Dev     - New services MediaUrl, VariantSpec, StorageRouter, MediaVariantWriter, PosterService consolidate the upload and read pipeline. Existing methods kept as shims for at least two releases per the deprecation policy.
* Dev     - Database migration to version 15 backfills thumb_size_path meta for video and audio rows where pre-1.5.0 uploads recorded the wrong subdirectory. Idempotent. Includes a posters fallback probe for sites whose URL meta also diverged.
* Dev     - New filter mvs_broadcast_thumbnail_ttl controls the TTL for thumbnails embedded in long-lived surfaces like notification emails and RSS. Defaults to one hour. Filter target for sites that cache at the CDN for longer.
* Compat  - Paired with WPMediaVerse Pro 1.5.0. Install both updates together when running Pro.

= 1.4.0 - May 2026 =

New cloud storage options, driver-agnostic media URLs, four release-blocking bug fixes, and a centralized media query layer.

* New     - Cloudflare R2 and DigitalOcean Spaces are now selectable cloud storage drivers from Settings, Storage. The drivers ship in WPMediaVerse Pro; the Storage Driver setting lists them on every install.
* New     - Driver-agnostic media URLs. Each item now resolves its display URL from the currently active storage driver every time the page renders, so switching between cloud providers (or back to local) no longer breaks images across the site. Path information is the source of truth; URLs are computed at read time.
* New     - WP-CLI command wp mvs relocalize-private heals legacy non-public media whose URL meta still points at an old cloud bucket. Idempotent and safe to re-run.
* Improve - Private and restricted media always stays on your server and is never uploaded to cloud storage. Only public media is eligible for the cloud, so private uploads cannot reach a public bucket.
* Improve - Public media stored on the cloud is served directly from its CDN automatically, with no extra display setting to enable.
* Improve - Explore, profile, and feed listings now build their database queries through one shared, centrally tested layer, so privacy and gallery-grouping rules behave identically across every view.
* Improve - BuddyPress activity uploads now capture and send a real first-frame thumbnail for videos. Safari and Bing show the actual first frame instead of a blank player when a video has no embedded cover.
* Fix     - BuddyPress activity composer privacy dropdown now actually applies. Picking Members, Friends, or Only Me no longer leaves the activity visible to logged-out viewers on the sitewide stream.
* Fix     - Non-public uploads no longer 403 their own thumbnails. Members, Friends, Only Me, and Group uploads now serve correctly through the gated endpoint regardless of which storage driver is active.
* Fix     - Bulk album uploads from the BuddyPress profile and group tabs now produce one grouped activity entry per batch instead of one per file. Matches the existing dashboard album upload behavior.
* Fix     - Enabling a cloud storage service no longer breaks existing media. Each item displays from where its file actually lives instead of being repointed at the newly selected service.
* Fix     - Videos in the BuddyPress activity feed always show a poster image. Cover-less videos previously rendered a blank player in Safari and Bing.
* Dev     - Database migration to version 14 backfills driver-agnostic path meta for every existing media item. Idempotent; safe on partial reruns.
* Dev     - New filters mvs_serve_public_cloud_direct, mvs_public_cloud_thumbnail_url, and mvs_public_cloud_file_url to control or rewrite direct cloud URLs. New mvs_explore_query_args filter to adjust the Explore and profile feed query. Integration event hooks for gamification, activity, and notification consumers are documented in the developer guide.
* Compat  - Paired with WPMediaVerse Pro 1.4.0. Install both updates together when running Pro.

= 1.3.0 - May 2026 =

Major release. Automatic image optimization, modern WebP and AVIF formats, cloud storage tools, FULLTEXT search at scale, security hardening, and dozens of fixes. Bundles all work from the unreleased 1.2.1 and 1.2.2 branches.

* New     - Automatic image optimization on every upload. JPEGs, PNGs, and GIFs are re-encoded for smaller file size with hidden camera data stripped. Most uploads drop 10 to 30 percent without any visible quality change.
* New     - WebP image format support. Every uploaded image gets a second copy in WebP, around 25 to 35 percent smaller than JPEG. Modern browsers automatically use the smaller file; older browsers keep using the original.
* New     - AVIF image format support for even smaller files. AVIF is roughly 30 percent smaller than WebP again. Opt in from Settings, Storage tab. Default off because AVIF encoding takes longer than WebP.
* New     - Frontend serves WebP across every surface. Explore grid, BuddyPress activity feed, dashboard cards, single-media view, and the lightbox all swap in WebP automatically when the visitor's browser supports it.
* New     - Private images now also serve WebP and AVIF. Access-rule-protected media gets the same modern-format speed boost as public media.
* New     - Cloud storage migration tools. Move existing local media to S3 or BunnyCDN in batches, then clean up the local copies after verification. New WP-CLI command "wp mvs migrate-storage --from=local --to=bunnycdn" handles the bulk move with idempotent resume support.
* New     - Direct CDN URLs for public media. New setting on the Storage tab (default off): when enabled on a cloud-storage install, public images load directly from your CDN edge instead of being proxied through WordPress. Cuts WordPress out of the hot path for image requests.
* New     - WP-CLI commands to optimize existing media. Run "wp mvs optimize 123" on one item or "wp mvs optimize-bulk" across the whole library. Resume-safe if interrupted.
* New     - New Optimization column on the All Media admin listing. Shows percent saved per file at a glance. Row actions Optimize and Details added. The Details page shows everything stored about a file with inline buttons to re-optimize, repair thumbnails, or move to trash.
* New     - Filename strategy setting. New uploads can be saved with hashed filenames or sanitized original names. Hashed mode keeps the user-facing filename visible in downloads and the REST API; only the on-disk file uses the hash. Existing files are never renamed.
* New     - Faster search at scale. The Explore search now uses a FULLTEXT index for 3+ character queries, returning results across 100,000+ media items in milliseconds instead of seconds. Sites that cannot enable FULLTEXT continue working on the existing LIKE search.
* New     - Automatic view-event cleanup. View tracking events older than 90 days are pruned daily. Window is configurable per site (0 to disable, max 730 days). Keeps the database lean on long-running sites.
* New     - Default video poster for videos without an embedded cover image. Previously these showed a black frame. Now they render a clean placeholder.
* New     - Audio card design. Audio with embedded cover art shows the cover; audio without art shows a unique waveform image generated from the file id.
* New     - Compatibility with EWWW, Imagify, Smush, and ShortPixel image optimizers through a single extension point. If you already use one of these, leave it active; it runs alongside the built-in optimization.
* New     - Opt-in usage telemetry to help us prioritize features. Default off. No personal data, file names, or content ever leaves your site. Counters stay local.
* Improve - Explore feed shows newest media first. Albums no longer pin to the top of page one because they are static containers. Album pages remain accessible by their permalinks.
* Improve - Per-request media cache for activity feeds and dashboards. Each media item is loaded from the database once per page even when rendered many times. Drops query count on busy pages.
* Improve - Production stability commitments documented for site owners. The plugin will deprecate features through proper notice periods of at least two major versions instead of removing them without warning.
* Fix     - Security: BuddyPress activity privacy now follows media privacy. Previously a non-public media uploaded to a BP activity would leak the activity card (composer text, timestamp, author) to the public stream. Activity visibility is now derived from the strictest of the media and album privacy settings.
* Fix     - Security: REST per-page hardening across all list endpoints. Callers can no longer request unbounded result sets to slow the site. Maximum is filterable for trusted environments.
* Fix     - Cloud storage uploads now generate thumbnails reliably. Some cloud-driver uploads previously failed to produce thumbnails silently.
* Fix     - Image optimization never makes a file larger. If the optimized version is bigger than the source, the original is kept.
* Fix     - Animated GIFs stay animated. The optimization pass now detects animated GIFs and skips them so they aren't flattened to their first frame.
* Fix     - Broken thumbnail icons no longer appear for videos and audio when no poster image is available. Videos fall back to their first frame; audio falls back to the music icon.
* Fix     - Most MP4 video uploads now generate proper poster images on managed WordPress hosts. Previously some uploads silently fell back to a low-quality thumbnail because of how managed hosting environments configure server binaries.
* Fix     - Cleared all PHP 8.4 and PHP 8.5 compatibility warnings. The plugin runs cleanly on the latest PHP versions.
* Dev     - New action hook mvs_media_privacy_changed fires when a media's privacy column is updated. Useful for activity adapters and audit logs.
* Dev     - New StorageDriverInterface::download($path, $local_dest) method on Local, S3, and BunnyCDN drivers. Third-party storage drivers must implement it.
* Compat  - Paired with WPMediaVerse Pro 1.3.0. Install both updates together when running Pro.

= 1.2.0 =
* New: Member Photos block + shortcode (`mvs/member-photos`, `[mvs_member_photos]`) - auto-detects whose photos to show: explicit `userId` → BP displayed user → post author → current user. Drop it into a BP profile, an author template, or a regular page and it just works.
* New: PDF Viewer block + shortcode (`mvs/pdf-viewer`, `[mvs_pdf_viewer]`) - embeds PDFs uploaded to WPMediaVerse using the browser's native PDF viewer (`#view=FitH`); inspector exposes height (200–1400 px) and toolbar toggle. Five distinct empty states (no id / not found / not a PDF / no permission / asset missing) - never a blank rectangle.
* New: More sort options on Media Grid - added "Most Popular", "Most Viewed", "Most Reactions", and "Random". Asc/Desc direction toggle exposed in the inspector (hidden when sort = Random). New `userId` attribute on `mvs/media-grid` and `user_id` attr on `[mvs_gallery]` filter to one author.
* New: Search autocomplete on the Explore feed - type two or more characters and a top-8 title-match dropdown opens (debounced 250 ms). Full keyboard support: ArrowDown / ArrowUp / Enter / ESC. ARIA combobox + listbox semantics so screen readers announce matches as you type.
* New: Lightbox Download button - toolbar button next to Share + Open. Counts each download in `mvs_media_stats.downloads`; rate-limited at 30/min/user via the central `RateLimiter`. New `POST /mvs/v1/media/{id}/download` REST endpoint.
* New: Per-media Edit modal - click the Edit button on your own dashboard cards to change title, description, privacy, and allow-download per-media. Save → live update without reload. `PUT /mvs/v1/media/{id}` now accepts `allow_download` (boolean) and `prepare_item_for_response` emits it (defaults `true` when meta absent).
* New: Member Photos card - redesigned hero card with avatar + display name + handle + bio + stats (photos / followers / following) + View Profile + Follow/Following toggle. Container-query responsive: switches to vertical stack at <520 px container width (so it fits a sidebar widget) and remains compact at 320 px.
* New: "Update URL slug" opt-in checkbox - present in the per-media Edit modal AND on `/media/{slug}/` inline-edit form, sitting beside Privacy on the same row. Off by default - title edits leave the URL stable. Tick to regenerate the slug from the new title; if you're currently viewing the old URL, the page redirects to the new one automatically (no 404 on reload).
* New: Open Graph + Twitter Card meta on every `/media/{slug}/` page - `og:title` / `og:type` / `og:url` / `og:site_name` / `og:description` / `og:image` / `og:image:alt` plus `twitter:card=summary_large_image` + `twitter:title/description/image`. Paste a media URL into Slack / Twitter / LinkedIn / Discord and it unfurls correctly.
* New: Popular tag pills in the upload modal - top-8 most-used tags surface as click-to-add chips below the tags input. Clicking a pill appends to the comma-separated input and de-dupes silently.
* New: Upload modal polish - preview tiles show filename + per-tile (×) remove button; audio files get an audio-fallback icon (no broken-image SVG).

* New: Bulk Actions on All Media - multi-select header/footer checkboxes + a Bulk Actions toolbar. Action menu is context-aware to the active filter: in the Trash filter → Restore + Delete permanently; otherwise → Move to Trash. Capability + `wp_nonce_field('mvs_bulk_media')` gates on submit; success notice with count + action.
* New: Chat panel visibility setting under Direct Messages - pick where the floating chat panel renders: Everywhere (default) / WPMediaVerse pages only / BuddyPress pages only / Disabled. New `mvs_should_render_chat_panel` filter wraps the resolved decision so themes / add-ons can fine-tune by URL pattern.
* New: Global "Allow downloads" toggle under Media Display - single switch that hides the new lightbox Download button site-wide AND makes the `record_download` REST endpoint refuse with 403. Per-media `allow_download` meta still gates further when the global is on.

* Fix: Lightbox Share no longer falls back to a `window.prompt()` "Copy this link:" popup when neither `navigator.share` nor clipboard write is available - instead a toast error renders. `mvs_media_stats.shares` now also increments via the new `POST /mvs/v1/media/{id}/share` REST endpoint.
* New: 6-reaction accessibility in the lightbox - Like / Love / Haha / Wow / Sad / Angry each carry sentence-form `aria-label` and `aria-pressed` toggles; the emoji span is `aria-hidden`; the wrapper carries `role="group" aria-label="Reactions"`. Toolbar buttons (Favorite / Share / Download / Open / Report) all gain `aria-label`. `:focus-visible` outline on `.mvs-lightbox-action / -close / -nav` so keyboard nav is visible.
* Fix: Lightbox toolbar fits 5 actions on one row - the previous layout used inline-flex + per-button padding 24 px + `margin-left: auto` on Report which overflowed the 380 px sidebar (~414 px content) and produced a horizontal scrollbar. Now `flex: 1` + `space-between` distributes evenly; the toolbar always fits at desktop AND on mobile. Below 768 px the lightbox stacks vertically (image on top, sidebar full-width below) and below 380 px labels collapse to icons-only.
* New: Block render forms a11y - explore-feed search input, media-upload file input + privacy select + title/description/tags inputs all gain `aria-label` (placeholder ≠ label per WCAG).
* New: Search-mode toggle a11y - Media / People toggle on `templates/explore.php` gets `role="tablist"` + `role="tab"` + `aria-selected` semantics; search input gets a screen-reader label.
* New: BuddyPress notification dedup - restored `NotificationIntegration` (mirrors `mvs_notification_created` to BP's `bp_notifications_add_notification`) and added a `function_exists('buddypress')` guard around the dashboard's `.mvs-notification-bell` markup so BP-active sites render notifications in the BP nav bell only - never twice.

* Fix: Moderation webhooks now fire reliably. Two listeners (`WebhookService::on_media_moderated` + `CacheService::on_moderation_change`) were registered against `mvs_media_moderated`, but the firer in `ModerationService::set_status()` uses `mvs_moderation_changed` (the established hook name; `LoggerService` already used it). Result: customers using outbound webhooks for moderation approve/reject events were getting zero events delivered, and the moderation-status cache stayed stale. Both listeners renamed to the correct hook name. Affected since: 1.0.0.
* Fix: `mvs_reaction_removed` action now fires when a user un-reacts. The action existed conceptually (cache invalidation listener was registered) but `ReactionService::remove()` never fired it, so the media-stat cache stayed warm with the old reaction count after an un-react. The reaction count itself was correct (re-read from DB), but cached aggregates lagged.
* Fix: `mvs_share_recorded` action now fires from the new `record_share` REST endpoint so the cache invalidation listener clears the media-stat row. Without this, share counts in feed cards lagged behind reality until the cache TTL expired.
* Fix: Search autocomplete on the Explore feed now aborts in-flight requests when a newer keystroke arrives. Previously, typing fast (e.g. "ne" then "new" within 250 ms) could leave the slower "ne" results visible if its response landed second - a classic race condition. Each keystroke now spawns an `AbortController`-equipped fetch and supersedes any in-flight request.

* Fix: Title edit no longer changes the URL slug. Editing a media title and saving used to silently regenerate the slug - meaning the URL the user just had in their address bar 404'd on reload, and any inbound links / social shares / search-engine cache pointing at the old URL stopped working. Slug now stays stable; admins can opt into a slug change explicitly via the new "Update URL slug" checkbox in the Edit modal and on `/media/{slug}/`.

* Fix: BuddyPress activity no longer renders the same image twice. A Phase 8 "linkage table" code path was appending its rendered grid even when the activity content already contained the inline grid markup - so every composer-posted activity rendered each image twice on the activity permalink page. The render filter now uses inline content as the authoritative copy and only falls back to the linkage path when content is empty.

* Fix: Author profile URLs no longer leak BuddyPress mention HTML. Five sites (Instagram feed cards, leaderboard, dashboard "View Profile" button, follower notifications, and a sibling Instagram card template) built `/media/@user/` URLs inline. When BuddyPress's `bp_activity_at_name_filter` ran on the surrounding output, the `@user` substring inside the URL was rewritten into a full BP mention `<a class='bp-suggestions-mention' …>@user</a>` - corrupting the URL with literal HTML and producing dead links. All five now route through the canonical `TemplateHelpers::get_user_profile_url()` which resolves to the BuddyPress profile when BP is active and the plugin's `/media/@user/` route otherwise.

* Fix: Lightbox `Favorite` button is no longer rendered twice in the action toolbar. Earlier 1.2.0 builds rendered a duplicate Favorite button on certain page contexts. Single button now, with the label flipping between "Favorite" and "Favorited" via the `aria-pressed` state.

* Fix: Demo data importer now runs end-to-end on every install. The `seed-demo-data.php` script (and its sibling `populate-showcase.php` + `cleanup-demo-data.php`) called `MediaRepository::*` static-style; the repository is a container service with instance methods only, so every demo seed attempt fataled with `cannot be called statically`. All 14 call sites swept to the canonical container-resolved instance API. Running the demo seeder now produces 50 media items + 5 demo users + 5 albums + 159 reactions + 30 comments + 40 favorites + 20 follows + 3 reports cleanly.

* Fix: `AlbumService::create()` added - the service had `add_items` / `get_items` / `set_cover` etc. but no top-level `create()` method, so any non-REST caller (seeder, future WP-CLI command, theme code) had to repeat the `wp_insert_post('mvs_album')` + privacy + group_id + categories meta writes inline. Centralised. The `AlbumController::create_item` REST endpoint now delegates to it.

* Fix: `PUT /media/{id}` `allow_download` flag now accepts every body encoding. The flag was previously read only from the JSON body via `$request->get_json_params()` - fine for JS apiFetch (the dominant path), but form-encoded clients and internal `$request->set_param()` calls silently dropped the flag. Now read via `$request->get_param()` which covers all sources uniformly.

* Fix: `mvs_should_render_chat_panel` filter passes the resolved visibility setting as a second argument, so callbacks can scope their override by the admin's chosen mode (`everywhere` / `mvs_pages` / `bp_pages`). Backward-compatible: existing 1-arg callbacks keep working; the new arg is just ignored if not declared.

* Fix: Stats block fits on narrow phones. The `mvs/media-stats` block grid used a 180 px minimum track which pushed the block 11 px past its container on viewports below ~390 px. Switched to `minmax(min(180px, 100%), 1fr)` so the minimum collapses gracefully; below 480 px cards stack one per row.

* Fix: DM access dropdown (Settings → Social → "Who can send me direct messages") no longer silently reverts "Nobody" or "Mutual followers only" to "Everyone" on save. Same root cause silently flipped the "Show online status" preference. The save path looked successful (admin notice "Settings saved." appeared) but the option stored a different value than the dropdown showed. After upgrading to 1.2.0, please reopen Settings → Social and confirm your preferred DM access and online-status visibility - the dropdown now reflects the saved value byte-for-byte. Affected since: 1.1.0. Commit: `d986525`.

* New: `Core\SettingsHelper` - canonical static accessor for paired-plugin settings reads. First slot covers the page-id family (`dashboard` / `explore` / `upload`) plus `mvs_thumbnail_size` and `mvs_openai_api_key`. Pro and themes must use this instead of direct `get_option('mvs_page_*')` reads (Free invariant A4).
* New: Hook signatures now carry full type-annotated arg shapes in `audit/manifest.json` (`args_signature[]` on 14 of 22 hooks); enables Pro arch-check A11 to detect cross-plugin contract drift.
* New: `SettingsContractTest` enforces register_setting whitelist alignment - settings registration drift is now caught at unit-test time rather than at customer save-time.
* New: Block standard alignment (Phase 7) - Free's 9 registered Gutenberg blocks now share the same Spacing / Border / Shadow / Visibility inspector panels as Pro and wbcom-essential. `WPMediaVerse\Blocks\StandardAttributes` injects the 20 standard layout attrs via the `block_type_metadata` filter; `WPMediaVerse\Blocks\MVS_CSS` collects per-instance scoped CSS keyed off `mvs-block-{uniqueId}` and dumps it on `wp_footer`. Pro's `src/shared/` tree (17 files) ported with text-domain swaps.
* New: `BaseBPTabIntegration` extracted (Phase 5 P2.4) from `ProfileTabIntegration` + `GroupTabIntegration` - a single bug fix on either BP tab now propagates to both. Net delta -109 lines.

= 1.1.3 =
* Fix: Lightbox now opens the original full-size image instead of the low-res grid thumbnail. New Display setting "Lightbox Image Size" lets admins pick Original / Large / Medium / Auto (defaults to Original)
* Fix: Lightbox opens full-viewport in Facebook-style layout - image fills the left panel, social sidebar on the right; close button (X) correctly positioned and visible over the image panel
* Feature: Video uploads now get a real poster thumbnail extracted from the file's embedded cover atom via WP core's getID3 - no ffmpeg required. Works for phone-shot MP4/MOV; screen recordings without an embedded cover fall through to a native `<video preload="metadata">` preview in the grid so the browser paints the first frame - matching what the single media view already does
* Refactor: Media thumbnail rendering consolidated into a single source of truth. New `TemplateHelpers::media_thumbnail()` (PHP) and `mvsCardBuilders.buildThumbnail()` (JS) helpers used by Explore, BP activity, album/collection viewer, My Media dashboard, BP profile and group tabs, Pro Pinterest/Dribbble/Flickr/Instagram layouts. One branching logic for image / video-with-poster / native video preview / audio card / generic placeholder - every surface now handles each media type identically
* Enhancement: All close, dismiss, and navigation icons in the lightbox, upload modal, and toast notifications replaced with proper Lucide icons (rounded caps, correct paths). Lightbox CSS consolidated into frontend.css as a single source of truth
* Fix: Hardcoded emoji characters (play triangle, music note) replaced with inline Lucide SVGs across grids, dashboard, BP activity audio cards, and BP upload preview. WordPress was auto-converting the Unicode chars to emoji images, which looked different across browsers and didn't match the plugin's Lucide-based design
* Fix: Video, audio, and generic placeholders now share a unified frame (aspect-ratio + gradient background) so grids never collapse based on media type - any mix of image/video/audio uploads renders with consistent cell sizing
* Fix: BuddyPress activity stream thumbnails render reliably - defensive `file_url` fallback in `MediaDisplayHelper` when custom `thumb_*` meta is missing, and a path-5 recovery in `ActivityContentIntegration` that rebuilds the grid from `_mvs_media_ids` meta when an activity's saved content lost its markup
* Fix: Delete action no longer leaks onto public grids. The per-item trash icon now only renders on BuddyPress profile and group media tabs (where `show_actions` is explicitly opted in); Explore, Albums, and Collections never show it
* Fix: Settings sidebar brand icon is now clearly visible - the eyebrow-text rule was cascading gray onto the logo SVG, making it blend into the red gradient
* Fix: Dead meta-key reads cleaned up. `ActivityContentIntegration` and Pro's `TranscriptionService` were looking up `attachment_id` meta (dropped in migration v8) - both now use `wp_attachment_id` which importers actually write
* Feature: Thumbnail pipeline centralized in `UploadService::generate_thumbnails()` (now public). Pro CLI importers and MigrationPage delegate here via `Plugin::free_service('upload')`, so Free uploads and Pro imports share identical fallback, sizing, and logging. New `mvs_thumbnail_sizes` filter lets themes/add-ons tune sizes without patching
* Fix: Every upload path now guarantees all three thumbnail sizes (`thumb_large`, `thumb_medium`, `thumb_thumb`). WP's `multi_resize()` skips sizes that would upscale the source, so small images (under 1024px) used to leave `thumb_large` empty - now the pipeline backfills any missing size with `file_url` (the original IS the largest version)
* Fix: Demo data importer (`seed-demo-data.php` / Overview admin button) now routes through `UploadService::handle()` instead of inserting rows directly, so demo content exercises the full real-upload pipeline - including thumbnail generation, video poster extraction, `mvs_media_uploaded` hook, and LoggerService
* Fix: Silent failures in the thumbnail pipeline are now logged to `mvs_error_log` via `LoggerService` - missing source file, GD/Imagick unavailable, and `multi_resize()` returning empty each write a warning with the media ID for diagnostics
* Fix: Missing "Upload Page" setting added to Settings → General → Pages. The option was read in 3 places but had no admin UI, so custom [mvs_upload] shortcode pages could not be assigned
* Fix: Album cover selection now persists - picking a cover from the album edit page writes to the post meta instead of silently no-op'ing, and the album preview shows the chosen image
* Fix: Albums without an explicitly pinned cover fall back to the first image in the album so they never render with a broken/placeholder cover
* Fix: Lightbox "Favorite" label no longer ships with a duplicated heart emoji prefix - the Lucide icon renders alone as intended
* Fix: 5 free bug cards carried over from 1.1.2 - grid columns=5 rendering, stats page filter date ranges, tag cloud count accuracy, lightbox Favorite visibility for signed-in users, lightbox Share double-icon
* Fix: Thumbnails no longer return 403 errors for logged-in users; album cover thumbnails go through signed URL service for consistent access control
* Build: shared-ui Gutenberg blocks (view.js) now build as true ES modules via `npm run build` so the Interactivity API hydrates correctly - fixes `window.wp.interactivity is undefined` on block-rendered pages
* Security: `uninstall.php` now has an `ABSPATH` guard alongside the existing `WP_UNINSTALL_PLUGIN` check
* Docs: Service method docblocks (@param / @return) restored across Album, Moderation, and Tag services

= 1.1.2 =
* Fix: Setting Grid Columns to 5 now actually renders 5 columns on the Explore page, single-album view, collections, and dashboard grids (was collapsing to a single column because the 5-column CSS rule was missing)
* Fix: Stats page Today / This Week / This Month / All Time filters now change the Media count and Albums count - previously these cards ignored the date range and looked identical for every filter
* Fix: New tags now show up in the Explore tag cloud immediately after upload (tag count used to stay at 0 because WordPress couldn't count media stored in our custom table - now counted correctly for both tags and categories, plus a one-time backfill for existing tags)
* Fix: Favorite button in the lightbox is now visible to all signed-in users, including the media owner (matches the behaviour of the single-media page)
* Fix: Lightbox Share button no longer shows two icons - now uses the same clean Lucide icon set as the single-media page (Favorite / Share / Open / Report all unified)
* New: Add New Tag button on the Tags admin screen
* New: Sortable columns on the Tags admin table
* New: Back button on every detail page on mobile
* New: Touch targets now meet Apple's 44×44 minimum everywhere on mobile
* New: Floating upload button respects the iOS safe area (no more overlap with the home bar)
* New: Bottom-sheet modals on mobile - slide up from the bottom with a drag handle, like native iOS apps
* New: Sticky action bar on the single-media page on mobile - Like, Share, Edit, and Delete stay pinned to the bottom with a blurred backdrop
* New: Skeleton loaders while content is loading (smoother than spinners)
* New: Instant visual feedback when you tap Like, Favorite, or Follow - the button rolls back automatically if the action fails
* New: Tab strips on mobile now scroll horizontally with an edge fade and snap to the active tab
* New: Compact icon-with-tooltip buttons on dense action rows on mobile
* New: Lucide icons now ship with the plugin - no longer dependent on the active theme to load them
* New: Filters to let extensions register custom notification types (`mvs_notification_types`, `mvs_notification_message`)
* Fix: Bulk-deleting tags no longer produces an error
* Fix: Tag admin pagination count now matches the actual number of tags
* Fix: Sort order is preserved when using bulk actions on tags
* Fix: Deleting a tag now redirects back to a valid page instead of an error page
* Fix: Lightbox opens instantly from media grids - no extra loading delay
* Fix: Moderation queue's AI Flagged tab now correctly lists flagged media (was showing empty)
* Fix: Duplicate-upload "Warn" mode now actually shows a warning when a matching file already exists
* Fix: Fresh installs now have the default Allowed File Types ticked on first visit
* Fix: Album categories are now fully wired - create, update, filter by category, and see category links on single-album pages
* Fix: Demo data cleanup now also removes the tags created by the demo seeder
* Fix: "Allow per-upload privacy" admin setting is now honoured on every upload surface - block editor, BuddyPress activity form, and the backend upload handler
* Fix: BuddyPress activity upload now shows a privacy selector once a file is selected, when per-upload privacy is enabled
* Fix: User deletion and GDPR erasure now clean up all related data - access rules, access grants, view history, direct messages, and conversation participants - so no orphan records are left behind
* Security: Tag management screens now verify user capability and nonce on every action
* Enhancement: Updated translation template (POT) with all new strings

= 1.1.1 =
* Fix: Single media page - comments, reactions, favorites, follow, and report now work (Interactivity API store loading)
* Fix: Signed URL serving for all media files - images and videos load correctly with .htaccess protection
* Fix: Anonymous users can now view public media in lightbox without 401/403 errors
* Fix: Notification titles show correct media name from mvs_media_index (not WordPress post title)
* Fix: Notification owner lookup uses MediaRepository instead of get_post_field()
* Fix: DM notifications now fire when messages are sent via REST API
* Fix: Favorite notifications now fire on toggle
* Fix: Reaction counts properly sync to mvs_media_index on add/remove
* Fix: Delete cascade cleans up reactions, favorites, comments, mentions, album items, notifications, and activity
* Fix: Privacy enforcement on REST API - anonymous users blocked from members/private media
* Fix: Block bypass prevented - cannot follow a blocked user
* Fix: Profile Message button respects recipient-level DM privacy setting
* Fix: Messaging page dark mode uses theme data-theme attribute instead of OS prefers-color-scheme
* Fix: Messages page auto-loads conversations on /messages/ (was blank)
* Fix: Chat header avatar hidden when no conversation selected (no broken image)
* Fix: Report modal spacing between dropdown and buttons
* Fix: Allowed file types admin setting wired to frontend upload UIs
* Fix: Album upload links files to album after creation
* Fix: Album mode allows multiple file selection
* Fix: Admin grid columns setting is now the source of truth for all media-grid blocks
* Fix: Thumbnail style setting applied to explore and dashboard grids
* Fix: ID column added to admin All Media table
* Fix: Video thumbnail preview in upload modal (canvas frame capture)
* New: CLI command `wp mvs generate-video-thumbnails` - batch generates video thumbnails via ffmpeg
* New: Auto-generate video thumbnails from browser during upload
* New: Improved audio placeholder in media grid (gradient background, larger icon)
* Enhancement: Social settings docs updated to match actual implementation
* Enhancement: 609 CLI test assertions across 30 suites (was 271 across 14)

= 1.1.0 =
* New: Unified Load More across all layouts (event delegation, no page reloads)
* New: Full-grid lightbox navigation (prev/next browses all loaded items)
* New: Unified Moderation page - AI Flagged, Pending, User Reports (Pro), Resolved tabs
* New: Unified Stats page - Overview + Video Analytics (Pro) tab
* New: Activity logging for uploads, moderation, reports, and user actions
* New: Settings page header bar with version badge and Setup Wizard link
* New: Lightbox video and audio playback - native player controls for all media types
* New: Upload capabilities granted to all roles on activation (including custom and BuddyPress roles)
* Enhancement: Complete admin UX overhaul following wbcom-modern-admin rulebook
* Enhancement: CSS design token system - 20 semantic tokens replace 90+ hardcoded hex values
* Enhancement: Lightbox works for logged-out users (read-only reactions, comments visible)
* Enhancement: REST API tag, category, search, scope, group_covers filters + stats in response
* Enhancement: Cleaner admin menu - Migration, Reports, Analytics hidden from sidebar
* Enhancement: FAB upload button only shows on MVS pages for focused UX
* Enhancement: BuddyPress activity lightbox supports video and audio inline playback
* Fix: Delete button not working (state binding mismatch in confirm dialog)
* Fix: Media single URLs with underscores redirecting to wrong page
* Fix: Setup wizard page mapping using wrong page IDs after site reset
* Fix: Grid columns setting ignored due to block.json default override
* Fix: Toast notification bindings across all templates
* Fix: Notification items missing clickable links
* Fix: Album cover image quality upgraded from medium to large
* Fix: Share button link generation with proper error handling
* Fix: Default privacy not applied to new uploads
* Fix: BP Activity form script loading from wrong path
* Fix: Stats REST endpoint returns zeros for new media (was 404)
* Fix: Confirm dialog dynamic button labels (Report/Delete)
* Fix: Report dialog with reason dropdown selector
* Fix: Third-party notice suppression on all admin pages including CPTs
* Fix: Interactivity API shared-ui loading from build path
* Fix: moderation_status filter on explore page
* Fix: Unified per_page to use mvs_items_per_page setting everywhere
* Fix: Accessibility - visible focus rings, aria-current, aria-label, reduced-motion
* Fix: Removed all inline styles from admin PHP templates (14 instances)
* Security: Sanitized $_SERVER['REQUEST_URI'] in login redirect
* Security: Webhook SSL verify uses wp_is_local_environment for local dev
* Cleanup: Removed dead infinite scroll code
* Cleanup: Removed unused setup wizard permissions step

= 1.0.0 =
* Initial release - complete media platform for WordPress
* Custom database tables (mvs_media_index, mvs_media_meta, mvs_media_stats) - zero wp_posts pollution
* 38 features across core platform, social layer, BuddyPress integration, and developer tools
* 6-level privacy system with BuddyPress-aware fallback
* Full social layer: reactions (6 types), threaded comments, favorites, follows, DMs, @mentions, sharing
* AI moderation with OpenAI Vision - flag, quarantine, or reject uploads automatically
* 9 Gutenberg blocks powered by WordPress Interactivity API
* 8 shortcodes for embedding media features anywhere
* BuddyPress integration: activity uploads (1-6 per post), profile/group media tabs, lightbox in activity feed
* 80+ REST API endpoints across 23 controllers
* 18 WP-CLI commands for bulk operations and maintenance
* Outbound webhooks with HMAC-SHA256 signing via Action Scheduler
* Template override system
* GDPR data export and erasure

== Upgrade Notice ==

= 1.3.0 =
Major release. Automatic image optimization, WebP and AVIF support, cloud storage migration tools, FULLTEXT search at scale, and a security fix for BuddyPress activity privacy. Strongly recommended for all sites.

= 1.2.0 =
Restores DM-access and online-status privacy preferences - they previously silently reverted to "Everyone" on save. Reopen Settings → Social after upgrading to confirm your saved values.

= 1.1.3 =
Fixes lightbox full-resolution images and full-viewport layout. Upgrade recommended for all users.

= 1.0.0 =
Initial release.
