# What's New in 1.9.0

WPMediaVerse 1.9.0 adds interests-based onboarding, a big native-app readiness push across the REST API, and several messaging and profile improvements, alongside a batch of QA-driven fixes.

> **Included in Free.** Every item on this page applies to the free version. Pro picks up these improvements automatically because Pro builds on top of free.

## Interests & personalized onboarding

New members can choose interests, get a ranked list of suggested people to follow, and request an interest-aware feed. See [Interests & Personalized Onboarding](../features/interests-onboarding.md) for the full REST API.

## Native-app readiness

A broad pass across `mvs/v1` makes the API fully drivable without a browser session: the `/app/config` endpoint, the interest endpoints, and viewer-aware fields on media responses. This is the foundation the official MediaVerse app and any headless client build on.

## Direct messages: search and moderation

- **Per-conversation message search** - search message content within a single conversation via `GET /conversations/{id}/messages/search`.
- **Content moderation on DMs** - messages now pass through the same `mvs_message_content_check` filter seam that guards other content types, so a host running a word-blocklist for posts and comments can apply it to DMs too.

See [Direct Messages](../features/direct-messages.md).

## Followers/following modal + profile overflow menu

Follower and following counts on a profile now open a modal listing the users, instead of requiring a separate page. Member profiles also gain an overflow menu with **Report**.

## Manage blocked members from Edit Profile

Blocked members can now be reviewed and unblocked from the Edit Profile screen, in addition to the REST API. See [User Blocking & Reporting](../features/user-blocking.md).

## "Also share as a story" on upload

The upload flow gains an option to also share the upload as a story at the same time, instead of uploading and then separately posting to Stories.

## Access-rules admin screen (retired in 2.0.0)

1.9.0 added an admin screen for managing per-media access rules, with the watermark display wired to the site's configured logo. **This screen was retired in 2.0.0** - access-rule enforcement stays in the backend (REST API and `AccessRulesService`), and rules are managed via the [Privacy & Access Control REST API](../features/privacy-access-control.md) rather than a dedicated admin UI. If you're running 1.9.0, this page still exists; update to 2.0.0 and use the REST API going forward.

## AI moderation controls (carried from 1.8.0)

The Anthropic (Claude) provider, owner-configurable AI Flag Criteria, and Custom Flag Terms shipped in 1.8.0 - see [What's New in 1.8.0](whats-new-1-8-0.md#configurable-ai-moderation--claude-provider) and [AI & Moderation Settings](../settings/ai-moderation.md).

## Masonry default (carried from 1.8.0)

The Original-proportions masonry grid became the default Thumbnail Style in 1.8.0; 1.9.0 confirms this as the default Explore grid style with the same escape hatch back to the fixed square grid (**Settings > Display**, or the `mvs_default_thumbnail_style` filter).

## Theme templates and child themes

Child themes can now override MediaVerse templates - member partials, messages, and layout pages resolve theme-first before falling back to the plugin's own templates. See [Template Overrides](../developer-guide/template-overrides.md).

## BuddyNext: media links open in the activity feed

When BuddyNext is active, media links open the activity they were posted in by default instead of a standalone media page. See [Activity Stream Media](../buddypress/activity-media.md#buddynext-media-links-open-their-activity-post-200) (documented in full under the 2.0.0 lockstep behavior, since the filter this relies on is unchanged across both releases).

## Smaller fixes

- Media comments no longer leak onto unrelated posts or pages (existing comments repaired automatically on update).
- Competition pages (Compete, Battles, Challenges, Tournaments) keep their MediaVerse styling when BuddyNext is active.
- Smart collections report their real item count in the list.
- AI moderation now runs when only Auto-Moderate is enabled (it was being skipped before).
- Theme-defense fixes for BuddyX and Reign (tab text, lightbox open); Instagram action buttons hardened against theme button styles.
- Cloud storage display and migration are more robust across admin operations.

## Under the hood

New `wp mvs cert` functional certification engine - a boot smoke test across every REST route plus dead-toggle oracles, gated at 100% coverage in CI. All WordPress.org Plugin Check errors resolved (30 to 0).

## Upgrade notes

- Pairs with WPMediaVerse Pro 1.9.0 - install both updates together.
- If you use the 1.9.0 access-rules admin screen, plan to move to the REST API workflow before or during your 2.0.0 update.
