---
journey: stories-moderation
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-STY-010, MV-STY-011, stories, cron, big-site-readiness]
prerequisites:
  - "Both plugins active; mvs_stories_enabled = 1"
  - "WP-CLI available for timestamp manipulation and cron triggering"
  - "manage_mvs_settings capability for the admin walkthrough"
estimated_runtime_minutes: 6
---

# Stories expire on schedule (bounded at scale), and force-expire from admin reaches the identical end state

**Why this journey exists**: the hourly `mvs_story_cleanup` cron only clears
story-specific meta (`is_story`, `story_started_at`, `story_expires_at`) — the
underlying media item and its comments/reactions/views must survive untouched.
On a large site with >2,000 stories expiring in the same window, cleanup must
process in bounded batches with an async hand-off rather than one huge
unbounded pass. The admin's manual "Force expire" is a second entry point to
the same end state and must not diverge from natural cron expiry (e.g. leaving
stray meta the other path cleans up).

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.
- Stories admin page: `wp-admin` > MediaVerse > Stories.

## Steps

### 1. Empty state
- **Action**: with zero active stories, visit the Stories admin page.
- **Expect**: "No active stories right now" with the lifecycle line "Stories are ephemeral 24-hour posts members share from the app or the site. While a story is live it appears here, where you can see who has viewed it and force-expire it if needed."

### 2. Populated table
- **Action**: create a story, revisit the page.
- **Expect**: row shows Author (avatar+name), Title (or "#<media id>" if untitled), Status badge "Active", Expires (formatted date/time), Seen by (count), and a "Force expire" row action.

### 3. Force expire — destructive confirm, Cancel is the default focus
- **Action**: click "Force expire" on a row.
- **Expect**: confirm dialog "End this story now? It will disappear for everyone immediately." with destructive-styled "Force expire" confirm; Cancel is the first focusable element and gets native default focus (no `autofocus` override on either button) — same shared confirm-dialog markup as Battle Monitor / Tournament Manager.

### 4. Force expire outcome
- **Action**: confirm.
- **Expect**: page reloads with "Story expired." success notice; the row is gone.

### 5. Force expire clears the SAME meta the cron would, nothing more
- **Action**: `mysql_query` (or `wp post meta list`) on the affected media item's `is_story`/`story_started_at`/`story_expires_at` meta after force-expire.
- **Expect**: all three cleared; the underlying media item, comments, reactions and views are untouched.
- **On fail**: the Force-expire handler diverging from the cron's cleanup logic — compare `includes/Admin/StoriesPage.php` force-expire handler against `includes/Stories/*Service`'s cron cleanup method.

### 6. Natural cron expiry reaches the identical end state
- **Action**: create a story with `duration_hours=1`; adjust its DB timestamp to be already expired, or wait; trigger `wp cron event run mvs_story_cleanup` (or wait for WP-Cron).
- **Expect**: identical meta-clear outcome to step 5 — story disappears from bar and admin list, media item survives.

### 7. Bounded batching at scale (>2,000 expiring stories)
- **Action**: seed >2,000 stories all past their expiry in the same hourly window (via `wp eval` loop), trigger the cron.
- **Expect**: one tick processes in bounded batches; any remainder hands off to an async continuation rather than one long unbounded pass — confirm via an Action Scheduler pending action for the remainder, mirroring the pattern in `system/01-streak-daily-check-bounded.md`.
- **On fail**: the story-cleanup cron handler — check for a batch-size constant and an async continuation, not an unbounded `get_col()`.

### 8. Permission and nonce guards on the admin surface
- **Action**: as a user WITHOUT `manage_mvs_settings`, load the Stories admin page URL directly; then attempt the Force-expire handler with a missing/mismatched nonce.
- **Expect**: `wp_die("You do not have permission to access this page.")` for the page; "You do not have permission to do this." for the handler; nonce mismatch rejected by `check_admin_referer` before anything runs.

### 9. Pagination and batched "seen by" counts at scale
- **Action**: with hundreds of active stories, load the admin list.
- **Expect**: Previous/Next pagination ("Page X of Y"), never an unbounded dump; the page's "seen by" counts are fetched in one batched query, not one query per row.

## Pass criteria

1. Empty and populated states render the documented copy exactly.
2. Force-expire shows the destructive confirm with Cancel as default focus, and clears exactly the story meta, leaving the media item and its social data untouched.
3. Natural cron expiry and manual force-expire reach an identical end state.
4. A large expiring backlog (>2,000) processes in bounded batches with an async continuation, never one unbounded pass.
5. Permission and nonce guards hold on both the page and the handler.
6. The admin list paginates and batches its "seen by" counts at scale.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Media item or its comments/reactions disappear on expiry | cleanup deleting more than story meta | cron cleanup method |
| Force-expire leaves stray meta the cron would have cleared | the two paths call different cleanup logic | `includes/Admin/StoriesPage.php` vs the cron handler |
| One cron tick times out / exhausts memory at scale | unbounded query, no batch size | story-cleanup cron handler |
| Admin list is one giant unpaginated table | missing pagination on the Stories admin screen | `includes/Admin/StoriesPage.php` |
| Non-privileged user reaches the page or the handler | capability/nonce check missing or misplaced | `includes/Admin/StoriesPage.php` |
