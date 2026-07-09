# What's New in 2.0.0

WPMediaVerse 2.0.0 is a major release: upload watermarking, a full pass to make the entire frontend translation-ready, privacy hardening across albums, collections, and protected media, and a batch of activity, moderation, and image-pipeline fixes.

> **Included in Free.** Every item on this page applies to the free version. Paired with WPMediaVerse Pro 2.0.0 - install and test both together.

## Upload watermarking engine

Every upload now flows through a single, consistent watermark stamp point - at upload time and at file-replace time, so there's no way to bypass it by replacing a file after the fact. WPMediaVerse Free ships the engine and the option schema; **WPMediaVerse Pro** adds the Settings UI (Watermark Type, Logo, Position, Opacity) and the renderer that actually draws the mark. See [Watermarking: Free vs Pro](../settings/display.md#watermarking-free-vs-pro). Watermark stamping now fails closed - if the renderer can't draw the mark, the upload is logged as an error instead of silently serving an un-watermarked original.

## Full frontend translation readiness

Every Interactivity API store, the shared-UI store, classic scripts, and the messaging module now ship translatable strings through a standard i18n delivery pipeline. Previously, roughly 165 JavaScript strings inside Interactivity modules were English-locked regardless of the site's language setting - the entire customer-facing frontend is translation-ready now.

## Privacy hardening: albums, collections, protected media

- **Album and collection privacy** is enforced at the database level, so visitor pagination counts stay correct and private items never leak into a count or a listing.
- **Protected media** shows a proper login gate in the media slot instead of a 404, while still returning the correct 403 status to unauthorized requests.
- **Private collection single views** and their REST responses are gated - previously they could leak the collection's title and structure to unauthorized viewers.
- **Album and collection privacy-stub rows** no longer show up as broken tiles on Explore, the media grid, or the media feed, and their index row is purged on delete.

## Explore feed scope filters

The media listing endpoint gains `scope=followers` and `scope=self`, working across every layout (grid and every Pro feed layout) - not just the default. See [Explore Feed Scope Filters](../features/social-features.md#explore-feed-scope-filters-200).

## Album creation from the upload modal

You can create a new album directly from the upload modal instead of navigating away to create one first. See [Media Upload](../features/media-upload.md#upload-modal-190-200).

## Activity, lightbox, and notification fixes

- Favorite, Save, Share, and Download buttons work in the media lightbox inside the BuddyPress activity stream.
- Batch uploads to an album now show a "View all photos" link in the activity entry.
- BuddyPress notifications are cleaned up when media is deleted, and commenting on activity media no longer sends a duplicate notification. See [BuddyPress Notifications](../buddypress/notifications.md#200-update---no-double-notify-on-activity-comments).
- Direct-message reactions can be removed and are correctly attributed.
- Moderation actions no longer corrupt unrelated posts.

## Lossless GPS/EXIF strip

GPS location data is stripped from photos without a lossy re-encode, preserving image quality and the colour profile.

## Moderation default changed to Flag

The **When AI Flags Content** default changed from Delete permanently to Flag for review, so a fresh install can never auto-delete uploads before an admin has chosen otherwise. See [AI & Moderation Settings](../settings/ai-moderation.md).

## Admin menu rename: "Media Moderation"

The admin sidebar label **Moderation** is now **Media Moderation**, to avoid ambiguity with WordPress's general moderation terminology. See [AI & Moderation Settings](../settings/ai-moderation.md#moderation-queue).

## App pages use the theme's no-sidebar template

App pages (Dashboard, Explore, Upload, and similar plugin-owned pages) now use the active theme's no-sidebar page template for a cleaner full-width layout, when the theme provides one.

## BuddyNext: media links open their activity post

When BuddyNext is active, media links and `/media/{slug}/` URLs redirect to the BuddyPress activity entry the media was posted in, instead of rendering as a separate public page. A BuddyNext-side settings toggle switches back to dedicated media pages if you want a standalone page per item. See [Activity Stream Media](../buddypress/activity-media.md#buddynext-media-links-open-their-activity-post-200).

## Under the hood

New filter `mvs_suppress_bp_comment_notification` controls the BuddyPress comment-notification bridge (see [Notifications](../buddypress/notifications.md)). `MediaRepositoryInterface` now declares `get_url_for_viewer()` for viewer-aware URLs to the full original file. Filterable avatar (`mvs_user_avatar_url`) and profile-link (`mvs_user_profile_url`) seams were added for BuddyNext integration. The dead watermark preview/serve system and the 1.9.0 admin access-rules UI were both removed - access-rule enforcement continues in the backend via the REST API.

## Upgrade notes

- Pairs with WPMediaVerse Pro 2.0.0 - install and test both together.
- No manual action needed for the privacy or watermark changes - they apply automatically on update.
- If you relied on the 1.9.0 access-rules admin screen, switch to managing access rules via the [Privacy & Access Control REST API](../features/privacy-access-control.md).
- If your site prefers standalone media pages under BuddyNext, use BuddyNext's settings toggle after updating (the new default sends media links to the activity feed).
