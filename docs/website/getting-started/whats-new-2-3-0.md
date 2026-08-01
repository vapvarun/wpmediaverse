# What's New in 2.3.0

WPMediaVerse 2.3.0 is a correctness release. Photos taken on a phone stop landing sideways, media added to a private album stops being publicly visible, the archived-conversations tab arrives in chat, and members can sign in to the mobile app with the password they already have.

> **Included in Free.** Every item on this page applies to the free version unless marked Pro. Paired with WPMediaVerse Pro 2.3.0 - install and test both together.

## Phone photos keep their orientation

Images uploaded from a phone are rotated to match their EXIF Orientation tag before they are stored, so portrait shots no longer appear sideways in the grid, the lightbox, or an activity post. This runs on the normal upload path and on **Replace file**, and the stored width and height are re-recorded after rotation so grid layouts reserve the right space.

Sites that already normalise orientation upstream - some CDNs and phone-upload apps do - can skip the extra re-encode with the new `mvs_apply_exif_orientation` filter.

## Media added to a private album inherits its album's privacy

A member who creates a Private album and uploads into it expects the contents to be private. Adding media to an album now clamps each item to the **more restrictive** of the two privacy levels, so a public photo added to a private album becomes private, and a private photo added to a public album stays private.

Nothing is ever made *more* public by this: the clamp only tightens. Album privacy inheritance is controllable with `mvs_album_inherit_privacy`, and each clamp fires `mvs_media_privacy_clamped_by_album` for auditing.

> Album-level privacy inheritance is a **Free** feature. See [Free vs Pro](free-vs-pro.md#a-note-on-the-privacy-rows) for exactly which parts of privacy are Free and which parts Pro adds.

## Archived conversations

Chat gains a proper **Archived** tab. Archiving a conversation moves it out of your main list without deleting it, and it comes back on its own when the other person writes to you again - controllable via `mvs_dm_unarchive_on_activity`.

Alongside it, two long-standing chat annoyances are fixed:

- **Tab counts are honest.** The message-requests badge counts actual pending requests. Previously requests were counted from the main list, so the badge could read `0` while requests were waiting.
- **Day separators and message times** are grouped by day with Today / Yesterday headings.

## Sign in to the app with your existing password

Members can now sign in to a native WPMediaVerse client with their normal WordPress password, and the site issues an Application Password behind the scenes - no more copying a generated credential by hand.

The exchange endpoint is public by necessity (there is nothing to authenticate with before you have a credential) and is guarded accordingly: a site-owner switch, a TLS requirement, uniform failure responses, the suspension gate, rate limiting applied before any credential is read, and a `409` rather than a silent two-factor bypass. See [POST /auth/app-password](../developer-guide/rest-api.md#post-authapp-password).

## Fixes you will notice

- **Tall portraits are no longer clipped** in the lightbox - they scale to fit instead of being cropped.
- **The activity feed no longer distorts single images.** Aspect ratios are preserved rather than force-cropped to a fixed height.
- **Double-clicking Post no longer publishes the same comment twice.** Duplicate comments within a short window are rejected; the window is filterable via `mvs_comment_duplicate_window`.
- **Album counts ignore trashed media**, so an album no longer advertises photos that are in the trash.
- **No more phantom "sent you a message" notification** when a conversation is merely opened.
- **Demo data can be removed and re-imported.** Previously the importer refused to run once demo data had been deleted, and reported that refusal as a success.
- **Album dropzones** are visible outside BuddyPress, and album assets load correctly under client-side navigation.

## Pro 2.3.0

- **Competition transitions no longer fire twice** on sites upgraded from an earlier version, so challenges and tournaments do not double-advance.
- **Turning Boosts off unschedules its expiry job** instead of leaving a cron entry behind.
- **Quota widget polish** - the dashboard quota widget is spaced properly, and it no longer appears at all for members who have no quota package and no limits, where it previously showed a "No Package" heading above three "Unlimited" rows and an offer to contact an administrator for more storage. Site owners who use it to advertise upgrades still get it, and `mvs_pro_quota_widget_visible` forces it back on.
- **Autopilot "Run Now"** sits in a proper action row on the Competitions dashboard.

## For developers

- The complete option list now lives in [Settings Reference](../settings/settings-reference.md).
- New filters: `mvs_apply_exif_orientation`, `mvs_album_inherit_privacy`, `mvs_comment_duplicate_window`, `mvs_dm_unarchive_on_activity`, `mvs_pro_quota_widget_visible` (Pro).
- New action: `mvs_media_privacy_clamped_by_album`.
- The moderation queue route is `GET /moderation`. Earlier revisions of the REST reference showed `/moderation/queue`, which returns `404`.
- Four watermark hooks that appeared in older revisions of the hooks reference (`mvs_watermark_config`, `mvs_generate_watermark`, `mvs_watermark_invalidated`, `mvs_watermarks_invalidated_all`) do not exist in the code and have been removed from the docs. The real extension points are `mvs_watermark_enabled`, `mvs_watermark_stamp_file` and, in Pro, `mvs_watermark_font_path`.

## Compatibility

Free and Pro release in lockstep. Install WPMediaVerse 2.3.0 and WPMediaVerse Pro 2.3.0 together - Pro 2.3.0 expects the Free 2.3.0 service surface.

No database migration is required beyond the automatic Migrator run on activation, and no settings are lost on upgrade.
