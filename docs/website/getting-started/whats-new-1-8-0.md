# What's New in 1.8.0

WPMediaVerse 1.8.0 adds configurable AI moderation with a new Claude provider, working image and text watermarks, a masonry media grid as the new default, member blocking from the frontend, an Integrations page for the Wbcom plugin family, and a long list of QA-driven fixes across storage, video, and theme compatibility. No database schema change.

> **Included in Free.** Every item on this page applies to the free version unless marked **Pro**. Pro picks up these improvements automatically because Pro builds on top of free.

## Integrations page

A new **WPMediaVerse > Integrations** admin page lists the Wbcom plugin family with product logos, short descriptions, and store links, plus a one-click companion installer that installs and activates a companion plugin without leaving the page. See [Integrations](integrations.md#integrations-admin-page-180).

## Member blocking from the frontend

Members can block or unblock another member directly from their profile - a **Block** toggle next to Follow and Message. Blocking already hid a blocked member's media and refused their follows and DMs; this release adds the missing frontend control to actually do it. See [User Blocking & Reporting](../features/user-blocking.md).

## Configurable AI moderation + Claude provider

- **AI Flag Criteria** checkboxes let you choose which categories the AI flags (nudity, violence, hate, self-harm, drugs, spam), plus a **Custom Flag Terms** field for your own terms (e.g. weapons, gambling, political content). All categories are on by default.
- **Claude (Anthropic)** joins OpenAI, Google Vision, and AWS Rekognition as a selectable AI provider.
- **Delete permanently** is now an option for **When AI Flags Content** - removes the media and its files from local and cloud storage.
- The AI settings tab shows only the selected provider's credentials, and the per-call cost is plugin-managed instead of a manual field.

See [AI & Moderation Settings](../settings/ai-moderation.md).

## Masonry grid is now the default

The Explore and media-grid feed now shows every image at its native aspect ratio by default (masonry, no cropping or gaps). Grid tiles also serve a larger thumbnail so they stay sharp on HiDPI/retina screens. Sites that prefer the uniform square crop can restore it in **Settings > Display**, or site-wide with the `mvs_default_thumbnail_style` filter. See [Display Settings](../settings/display.md).

## Watermark fixes

Selecting Watermark Type "Image" now applies the configured logo (it was being dropped before). Restricted (gated) images show the watermarked preview to visitors without access, instead of a plain blurred thumbnail - watermarking applies to images only, never video or audio.

## Video posters everywhere

Video tiles now show the video's real first frame - instead of a generic placeholder - on My Media, the explore-feed block, and the Pinterest/Flickr/Dribbble/Instagram layout grids, including Load More, matching the Explore grid. All client-side thumbnail renderers now follow the same video-first contract as the server.

## Storage display and migration fixes

- Media uploaded before a cloud service was connected now displays from where it actually lives, instead of a fabricated CDN URL that 404'd.
- Migrating storage moves a media's thumbnails and other variants alongside the original, and only flips the media to the new service once the full set has transferred.
- "Free up server space" never deletes a local file whose cloud copy can't be verified.
- Storage paths left inconsistent by earlier versions are repaired automatically in the background after update - small batches, only affected media. Disable with the `mvs_storage_repair_enabled` filter or run manually with `wp mvs repair-storage`.

## Theme and UI fixes

- The Explore lightbox now opens in place instead of stacking over the previous screen.
- Explore and the dashboard share one REST client with document-level event delegation, so Load More and other actions keep working after client-side navigation.
- Dark mode follows the active BuddyX/Reign theme toggle.
- Like, comment, bookmark, share, and other action buttons on the Instagram layout run their action instead of opening the lightbox.
- The dashboard, create-modal, chat tabs, and lightbox action buttons keep their flat, readable styling under themes that restyle plain buttons (BuddyX, Reign).
- Lightbox Share now works on non-HTTPS sites via a fallback copy method.
- Paginated Explore and member profile pages (page 2+) return HTTP 200 instead of a soft 404, so search engines keep them indexed.

## Smaller fixes

- Smart collections with more than one rule of the same type now match with OR instead of AND (e.g. tag Image OR tag Video), so they return items instead of resolving to zero.
- "Followers only" online-status visibility now actually hides presence from non-followers.
- The story viewer plays video and audio stories correctly instead of showing a broken image.
- Direct-message attachments are scoped to their conversation so only participants can view them.
- REST `POST /conversations` accepts an `as_request` flag so a message can open as a pending request instead of landing directly in the inbox.

## Under the hood

New filters: `mvs_ai_moderation_terms`, `mvs_ai_cost_per_call`, `mvs_storage_repair_enabled`, `mvs_default_thumbnail_style`, `mvs_strip_dead_bp_links`, `mvs_dead_bp_link_patterns`, `mvs_dm_denial_message`, `mvs_dm_denial_reason`, `mvs_collections_enabled`. New `UploadService::sideload_external_file()` for bringing outside files into the library. The frontend was refactored onto a shared `window.mvsRest` client plus a router store for client-side navigation. All WordPress.org Plugin Check errors resolved (30 to 0, continued into 1.9.0).

## Upgrade notes

- Pairs with WPMediaVerse Pro 1.8.0 - install both updates together.
- No database migration runs for this release.
- If your site relies on the square-crop grid, set **Thumbnail Style** back to Square in **Settings > Display** after updating (the default flipped to Original proportions).
