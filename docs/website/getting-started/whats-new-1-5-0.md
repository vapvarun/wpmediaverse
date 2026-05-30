# What's New in 1.5.0

WPMediaVerse 1.5.0 closes two privacy and access bugs around non-public media, heals video posters that were stored with the wrong path before this version, and unifies the upload-and-serve pipeline so the underlying bug pattern cannot recur. There are no new customer-facing features; this is a correctness and robustness release.

> **Included in Free.** Every item on this page applies to the free version. Pro picks up these fixes automatically because Pro builds on top of free — Pro 1.5.0 itself ships no code changes, it is a lockstep version bump. Install both updates together when running Pro.

## Non-public uploads render their own thumbnails

Members, Friends, Only Me, Group, and Custom-access media no longer return a 403 for their own thumbnails after upload. The owner, and any viewer who has been granted access, now sees the thumbnail correctly — on local storage and on every cloud driver (BunnyCDN, S3, R2, Spaces).

## Private uploads leave zero public footprint

Private media is now fully invisible to everyone except its owner:

- No BuddyPress activity entry is created for a private upload.
- The profile activity tab no longer surfaces broken thumbnail cards for private items.
- The profile media tab badge no longer counts private items when someone else is viewing.
- The Explore grid stays clean of private media.

Other non-public levels (Members, Friends, Group, Custom) keep their normal audience-discovery behavior — this change is specifically about media marked **Private**.

## Legacy video posters heal automatically

Videos and audio uploaded before 1.5.0 could store a poster path pointing at the wrong subdirectory, which showed up as a broken still frame on cards, in the lightbox, and in feed previews. Database migration **v15** runs once on the first page load after updating and re-derives the correct poster path from the on-disk file location. It is idempotent and safe to let run.

For cloud-storage sites, the migration is cloud-aware: it respects CDN-authoritative paths so existing cloud video posters keep resolving. If a poster still looks broken after the update, run `wp mvs cloud-thumbs-backfill` to push any local-only poster variants to the active cloud driver.

## One unified read path for media URLs

Theme overrides and shortcode users can now call the same `Core\MediaUrl` helper that the plugin's own templates use, so custom integrations no longer have to know anything about the signed-URL plumbing. See [Template Overrides](../developer-guide/template-overrides.md) for how to use it from a theme.

## One unified upload pipeline

Image variants, video posters, audio cover art, and WebP/AVIF siblings now all flow through a single writer, so the file layout is identical for every media type. WebP and AVIF generation in particular was collapsed into one shared publisher, removing a duplicate-write footgun where the two paths could disagree about the destination directory. Adding a new output format in the future is now one extension point instead of five.

## Shorter-lived thumbnail links, now filterable

Thumbnails embedded in long-lived surfaces (notification emails, RSS) are minted with a one-hour access TTL. Sites that cache those surfaces at the CDN for longer can widen the window with the new [`mvs_broadcast_thumbnail_ttl`](../developer-guide/hooks-filters.md#mvs_broadcast_thumbnail_ttl) filter.

## Under the hood

The upload and read pipeline is now backed by five focused services — `MediaUrl`, `VariantSpec`, `StorageRouter`, `MediaVariantWriter`, and `PosterService`. Existing methods are kept as shims for at least two releases per the deprecation policy, so custom code calling the old paths keeps working.

## Upgrade notes

- Update Free to 1.5.0 first, then Pro to 1.5.0 if you run it.
- Migrator v15 runs automatically on the first request after the update. No manual action is needed.
- Cloud-storage sites with pre-1.5.0 video uploads: if any poster still appears broken after the update, run `wp mvs cloud-thumbs-backfill`.
