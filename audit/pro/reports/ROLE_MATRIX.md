# WPMediaVerse Pro — Role Matrix

**Generated:** 2026-05-03 · **Plugin version:** 1.2.0

Capability vs. feature grid. Pro adds **no** new custom capabilities — it gates on WordPress core caps only. Rule 1 (`bin/coding-rules-check.sh`) rejects any `current_user_can()` with a custom-ability slug.

## Capabilities used

| Cap | Used by | Why |
|---|---|---|
| `manage_options` | All Pro admin pages, all 6 AJAX handlers, `transcode` REST routes, `assign_package` REST | Pro is admin-configured; non-admins never see settings UI or trigger destructive operations |
| `edit_posts` | Block editor access for all 12 Pro blocks; admin-only "pick a tournament" inline notice in block render.php (`Tournaments\Renderer::render_single`'s zero-id branch) | Block-editor convention — anyone who can edit posts can insert blocks |
| (logged-in) | Battle accept/decline, challenge entry submit, challenge entry vote, tournament register, tournament match vote, my-quota GET, my-boosts GET, create-boost POST, privacy/settings, compete-summary | Standard authenticated REST surface |
| (public) | Battles list/detail, challenges list/detail/entries/results, tournaments list/detail/bracket, video chapters | Read-only public surfaces — Free's URL signing protects served media |
| `media owner OR manage_options` | Captions GET/POST, video heatmap GET | Owner-or-admin pattern for media-scoped data |

## Feature × role grid

|  | Anonymous | Subscriber | Author / Editor (`edit_posts`) | Admin (`manage_options`) |
|---|---|---|---|---|
| Browse battles / challenges / tournaments | yes | yes | yes | yes |
| Submit a challenge entry | no | yes | yes | yes |
| Vote in a challenge / battle / tournament match | no | yes | yes | yes |
| Register for a tournament | no | yes | yes | yes |
| Accept / decline a battle invite (as opponent) | no | yes | yes | yes |
| Insert any of the 12 Pro Gutenberg blocks in a post | no | no | yes | yes |
| Configure block attributes in the editor | no | no | yes | yes |
| See the admin-only "Pick a tournament/challenge/battle in the block sidebar" inline notice | no | no | yes | yes |
| Use any `[mvs_pro_*]` shortcode in published content | no | no | yes (in own posts) | yes |
| Create a battle | no | yes (with quota) | yes | yes |
| Create a challenge | no | no | no | yes |
| Create a tournament | no | no | no | yes |
| Cancel a challenge | no | (creator only) | (creator only) | yes |
| Spend points on a boost | no | yes | yes | yes |
| View own quota / boosts | no | yes | yes | yes |
| View another user's quota | no | no | no | yes |
| Assign a quota package to a user | no | no | no | yes |
| Manage captions on own media | no | yes | yes | yes |
| Start a transcode job | no | no | no | yes |
| View video heatmap (own video) | no | yes | yes | yes |
| View video heatmap (any video) | no | no | no | yes |
| Run a connector (Flickr / etc.) OAuth + import | no | yes (per-user OAuth tokens) | yes | yes |
| Configure connector preferences (admin-side defaults) | no | no | no | yes |
| Test S3 / BunnyCDN connection (AJAX) | no | no | no | yes |
| Run a migration batch (rtMedia / MediaPress / BuddyBoss) | no | no | no | yes |
| Open Migration Tools page | no | no | no | yes |
| Open Pro Settings / Quota / Theme Library / Reports / Analytics / Competitions admin pages | no | no | no | yes |

## Block-editor access (Section §14 of FEATURE_AUDIT)

The 12 Pro blocks are visible in the block inserter to any role with `edit_posts`. Attribute panels (the 20 standard layout/spacing/border/shadow/visibility attrs + each block's specific attrs) are editor-only by definition.

**Frontend display gating** is independent of editor access:

- Competition blocks (`pro-tournament` / `pro-tournaments-list` / `pro-challenge` / `pro-challenges-list` / `pro-battle` / `pro-battles-active`) require their corresponding `mvs_*_enabled` feature toggle. When the toggle is off, an editor-or-higher viewer sees an admin-only "feature not enabled" notice; everyone else sees empty output.
- Compete-hub block requires ANY of the three competition toggles on; same admin-only-notice pattern when fully off.
- Feed blocks (`pro-instagram-feed` / `pro-flickr-feed` / `pro-pinterest-feed` / `pro-dribbble-feed`) and the leaderboard block do not gate on a feature toggle — they render whenever the block is on the page.

## Rationale for not adding custom caps

Pro hews to WordPress core caps (`manage_options`, `edit_posts`, plus authenticated-vs-public REST split) because:

1. Admin role-management plugins (Members, User Role Editor, etc.) work out-of-the-box without per-plugin cap registrations.
2. The feature surface fits the core taxonomy — admin tools, content creation, and authenticated-user actions — without needing finer cuts.
3. Rule 1 in the local-CI gate rejects any `current_user_can('mvs_…')` regression and forces this discipline at PR time.

## Cross-reference

- `wp-plugin-development` skill, "Capability rules" addendum: aligned with the WP core cap-only stance.
- Free plugin `audit/ROLE_MATRIX.md` (when present): same cap-only rule applies on the Free side.
- `bin/coding-rules-check.sh` Rule 1: hard-fails on custom-cap drift.
