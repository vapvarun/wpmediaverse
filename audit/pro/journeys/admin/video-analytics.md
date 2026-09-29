---
journey: video-analytics
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, anonymous]
covers: [MV-VID-014, MV-VID-015, video-analytics, big-site-readiness, rate-limiting]
prerequisites:
  - "Both plugins active"
  - "WP-CLI available for seeding play events and triggering cron"
estimated_runtime_minutes: 8
---

# The Analytics dashboard's copy matches its real retention window, and event ingestion rate-limits, prunes, and cleans up on delete

**Why this journey exists**: the dashboard banner claims "Data covers the last
90 days of raw events" — this must match the actual pruning cron's window
exactly, or the copy and the code silently drift. Ingestion is deliberately
`__return_true` (open to logged-out visitors, mirroring Free's own
view-counting route), so this journey also proves the per-session rate limit
holds and that deleting a media item hard-deletes its analytics rather than
leaving orphaned rows.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.
- Analytics tab: `admin.php?page=mvs-analytics` (hidden submenu under `mvs-stats`, URL-routable).

## Steps

### 1. `manage_mvs_settings` gate
- **Action**: as a user WITHOUT `manage_mvs_settings`, load the Analytics tab URL directly.
- **Expect**: `wp_die()`; the tab is not visible in the sidebar for this user either.

### 2. Empty state
- **Action**: with zero play events recorded anywhere, visit the tab.
- **Expect**: "No play events recorded yet. The player will start sending events once viewers watch your videos." plus zeroed summary cards (Plays Today, Plays This Week, Avg Engagement, Top Media Tracked).

### 3. Populated overview
- **Action**: `POST /media/{id}/events` a few times as different sessions (play/pause/complete), reload the tab.
- **Expect**: summary cards populate; Top 10 table (30-day window, sorted by engagement by default) shows Media Title (linked to permalink), Plays, a Completion % bar, an Engagement Score bar (0-100), "View Analytics" per row.

### 4. Per-media drill-down
- **Action**: click "View Analytics" on a Top-10 row (or navigate to `?media_id={id}`).
- **Expect**: heatmap (50 buckets), retention curve (sampled every 5 percentile points 0-100), completion rate, avg watch duration, engagement score, top-5 drop-off points (5-second buckets ranked by pause/seek frequency).

### 5. A deleted media item shows as "(deleted)", never a blank/broken link
- **Action**: delete a media item that has recorded plays; reload the Top 10 table if it's still cached, or wait for a fresh query.
- **Expect**: the title column reads "(deleted)" rather than a blank or broken link — see step 8 for whether the row survives at all.

### 6. Banner copy matches the real retention window exactly
- **Action**: read the banner text ("Data covers the last 90 days of raw events") and grep the pruning cron's retention constant.
- **Expect**: both say 90 days. If they ever drift, that IS a real bug — flag explicitly, don't wave it off as a copy nit.
- **On fail**: `includes/Analytics/*` pruning cron constant vs. the admin template's banner string.

### 7. Zero-duration media does not divide-by-zero
- **Action**: seed plays with zero recorded duration for a media item; view its heatmap.
- **Expect**: all-zero buckets render cleanly, no PHP warning/error.

### 8. 390px admin usability
- **Action**: `playwright_resize 390 844`; view the overview and a per-media detail page.
- **Expect**: standard `wp-admin` `wrap`/`wp-list-table` layout; bars/table don't overflow at 390px (there is no custom mobile CSS by design — confirm the default admin responsive behavior still holds).

### 9. Ingestion is open to logged-out visitors, by design
- **Action**: logged out, `POST /mvs-pro/v1/media/{id}/events` with a valid `event_type`.
- **Expect**: succeeds (`__return_true` permission), mirroring Free's own view-counting route.

### 10. Same `session_id` rate-limited within one second
- **Action**: POST the same `session_id` twice within one second.
- **Expect**: second call is silently rate-limited (1-second transient lock keyed by `session_id`), returns `{recorded:false}` with a 200 — never an error, never player-visible.

### 11. Invalid `event_type` rejected by schema
- **Action**: POST with an `event_type` not in play/pause/seek/complete/buffer.
- **Expect**: rejected before reaching the service (REST enum validation).

### 12. Deleting a media item hard-deletes its analytics
- **Action**: `mysql_query "SELECT COUNT(*) FROM wp_mvs_play_events WHERE media_id=<id>"` before and after deleting that media item.
- **Expect**: count drops to 0 after delete — `AnalyticsService::forget_media()` fires on `mvs_media_deleted` and hard-deletes every row; analytics never outlive the video.
- **On fail**: the `mvs_media_deleted` hook wiring for `AnalyticsService`.

### 13. Daily prune respects the 90-day window and batches at scale
- **Action**: seed events older than 90 days (well beyond 5000 rows if feasible, or a smaller representative batch); trigger the daily prune cron.
- **Expect**: rows older than 90 days deleted in batches of 5000, up to 50 batches (250k rows) per run — a backlog beyond that is a known, accepted ceiling needing more than one day's tick, not a bug.

## Pass criteria

1. The tab is gated by `manage_mvs_settings`; empty and populated states render the documented copy and structure.
2. A deleted media item's row reads "(deleted)", never blank/broken.
3. The dashboard's 90-day banner claim matches the pruning cron's actual constant exactly.
4. Zero-duration data never divide-by-zeros; the admin page is usable at 390px.
5. Ingestion is open to anonymous callers, rate-limits identical `session_id` events to 1/second silently, and rejects invalid `event_type` at the schema layer.
6. Deleting a media item hard-deletes its play events immediately; the daily prune respects the 90-day window with bounded batching.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Banner says 90 days but pruning uses a different window | constant drifted from the copy | `includes/Analytics/*` pruning cron + admin template |
| Deleted media's analytics rows survive | `forget_media()` not hooked to `mvs_media_deleted` | `includes/Analytics/AnalyticsService.php` |
| Same-session double-POST both record | rate-limit transient missing/keyed wrong | ingestion controller |
| Non-privileged user reaches the Analytics tab | `manage_mvs_settings` check missing | `includes/Admin/AnalyticsDashboard.php` |
| Heatmap errors on zero-duration data | no guard against divide-by-zero in bucket math | analytics heatmap builder |
