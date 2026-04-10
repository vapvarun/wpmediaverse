# CLI Test Suite — Reorganization Plan

## Philosophy
Media is the foundation. Tests should mirror **real user journeys** — what a user does, what they expect, what they get. Not just "does endpoint return 200" but "when I upload a photo, does it appear in my dashboard, with correct title, privacy, thumbnail, and can other users see/react/comment on it?"

## Current Problems
- Tests check HTTP status codes but rarely verify **data accuracy**
- No tests for **what the user actually sees** (notification text, media titles, URLs)
- `get_post()` bug slipped through because tests didn't verify notification content
- Signed URL bug slipped through because tests don't check file accessibility
- No tests for **cross-feature integration** (upload → notification → link works)
- Files are organized by technical area, not user journey

## New Structure

One test file per REST controller + service area. Each file owns all routes for that controller.

```
tests/cli/
├── helpers.php                  # Shared utilities (keep)
├── runner.php                   # Auto-discovers test-*.php (keep)
│
├── test-media.php               # MediaController — CRUD, browse, edit, stats, view tracking, groups
├── test-upload.php              # UploadService — file upload, validation, duplicate detection, thumbnails
├── test-privacy.php             # PrivacyService — public/members/private/custom levels
├── test-reactions.php           # ReactionController — toggle, remove, list, emoji types
├── test-comments.php            # CommentController — CRUD, threading, edit window, ownership
├── test-favorites.php           # FavoriteController — toggle, list, collection filter
├── test-follows.php             # FollowController — follow/unfollow, followers/following lists
├── test-notifications.php       # NotificationController + NotificationService — all types, content, links, DM
├── test-messaging.php           # MessagingController — conversations, messages, DM privacy, polling
├── test-albums.php              # AlbumController — CRUD, items, reorder, cover
├── test-collections.php         # CollectionController — CRUD, rules
├── test-tags.php                # TagController — list, cloud, merge, rename
├── test-profiles.php            # ProfileController + UserController — CRUD, avatar, search
├── test-activity.php            # ActivityController — feed, user activity
├── test-moderation.php          # ModerationController + ReportController — queue, approve, reject, reports, blocks
├── test-access-control.php      # AccessController — rules, grants, revoke
├── test-signed-urls.php         # SignedUrlController + SignedUrlService — generate, serve, path handling
├── test-bulk.php                # BulkController — batch delete, move, privacy
├── test-stats.php               # StatsController — media stats, user stats
├── test-webhooks.php            # WebhookService — dispatch, events, SSL, failures
├── test-settings.php            # Admin settings — all options, data source integrity guards
├── test-admin.php               # Admin pages — DB tables, permissions, capabilities
├── test-competitions.php        # Pro: challenges, battles, tournaments (keep as-is)
├── test-video.php               # Pro: chapters, resume, captions, transcode (keep as-is)
├── test-pro.php                 # Pro: feature toggles, quota (keep as-is)
└── test-user-journeys.php       # E2E multi-user flows — cross-feature integration tests
```

## Coverage Target per File

| File | Controller/Service | Routes | Target Assertions |
|------|-------------------|--------|-------------------|
| test-media.php | MediaController | 9 | 25 |
| test-upload.php | UploadService | 1 | 20 |
| test-privacy.php | PrivacyService | 0 | 15 |
| test-reactions.php | ReactionController | 3 | 12 |
| test-comments.php | CommentController | 4 | 18 |
| test-favorites.php | FavoriteController | 3 | 10 |
| test-follows.php | FollowController | 5 | 15 |
| test-notifications.php | NotificationController | 3 | 20 |
| test-messaging.php | MessagingController | 4+ | 15 |
| test-albums.php | AlbumController | 7 | 20 |
| test-collections.php | CollectionController | 5 | 12 |
| test-tags.php | TagController | 4 | 12 |
| test-profiles.php | ProfileController + UserController | 7 | 15 |
| test-activity.php | ActivityController | 2 | 8 |
| test-moderation.php | ModerationController + ReportController | 10 | 25 |
| test-access-control.php | AccessController | 5 | 15 |
| test-signed-urls.php | SignedUrlController | 2 | 10 |
| test-bulk.php | BulkController | 1 | 10 |
| test-stats.php | StatsController | 2 | 8 |
| test-webhooks.php | WebhookService | 0 | 8 |
| test-settings.php | Settings + integrity | 0 | 20 |
| test-admin.php | Admin pages + DB | 0 | 15 |
| test-competitions.php | Pro challenges/battles | 3+ | 56 (existing) |
| test-video.php | Pro video features | 3+ | 18 (existing) |
| test-pro.php | Pro toggles/quota | 2+ | 17 (existing) |
| test-user-journeys.php | E2E cross-feature | 0 | 25 |
| **TOTAL** | | **~66 routes** | **~470 assertions** |

## Migration Steps

1. Extract sections from oversized files into focused files
2. Keep existing assertions — don't rewrite working tests
3. Add missing assertions for untested routes
4. Ensure every REST route has at least: success case, auth failure case, validation error case
5. Run full suite after each migration step

## Assertion Standards

Every route test should include:
- **Happy path** — correct status code + expected data
- **Auth check** — anonymous request returns 401
- **Validation** — bad input returns 400/422
- **Ownership** — user can't modify other's data (403)
- **Data accuracy** — response data matches what was created/stored
