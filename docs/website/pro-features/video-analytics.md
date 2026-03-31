# Video Analytics

WPMediaVerse Pro records play events for every video, builds per-video heatmaps, and provides a dashboard showing retention and engagement metrics.

![Video analytics dashboard showing play counts and retention curve](../images/admin-stats.png)

## How Event Tracking Works

The player fires events to the REST API as viewers interact with a video. Events are rate-limited to one event per second per session to prevent flooding. Anonymous viewers are tracked by session ID; authenticated users are tracked by user ID.

### Tracked Events

| Event | Fired When |
|-------|-----------|
| `play` | Playback starts or resumes |
| `pause` | Playback is paused |
| `seek` | User jumps to a new position |
| `complete` | Playback reaches the end of the video |
| `buffer` | Player enters a buffering state |

Events are written to the `mvs_play_events` database table.

### Database Table: mvs_play_events

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment primary key |
| `media_id` | bigint | The `mvs_media` post ID |
| `user_id` | bigint | WordPress user ID, or `0` for anonymous |
| `session_id` | varchar | Unique session identifier |
| `event` | varchar | Event type: `play`, `pause`, `seek`, `complete`, `buffer` |
| `position` | float | Playback position in seconds when the event fired |
| `created_at` | datetime | UTC timestamp |

## Settings

| Option | Option Key | Default | Description |
|--------|-----------|---------|-------------|
| Data Retention | `mvs_pro_analytics_retention_days` | `365` | Days to keep raw event rows before pruning |

Go to **Media > Settings > Analytics** to change the retention period. A daily WP-Cron job deletes rows older than the configured limit.

![Analytics settings panel showing retention days field](../images/admin-settings-video.png)

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/`

### POST /media/{id}/analytics/event

Record a single play event. Authentication is not required — anonymous events are accepted.

**Body:**

```json
{
  "event": "play",
  "position": 0,
  "session_id": "abc123xyz"
}
```

`session_id` should be a stable string generated client-side per browser session (e.g. a UUID stored in `sessionStorage`).

**Rate limiting:** Returns `429 Too Many Requests` if more than one event per second is received for the same session.

**Response:** `204 No Content`.

### GET /media/{id}/analytics/heatmap

Get the heatmap data for a video: how often each second of the video has been viewed.

**Response:**

```json
{
  "media_id": 123,
  "duration": 360,
  "heatmap": [42, 41, 40, 39, 38, 12, 12, 11, ...]
}
```

`heatmap` is an array where each index is a second of the video and the value is the view count for that second.

### GET /media/{id}/analytics/retention

Get the audience retention curve: the percentage of viewers still watching at each point in the video.

**Response:**

```json
{
  "media_id": 123,
  "retention": [100, 98, 97, 95, 60, 58, ...]
}
```

Each value is a percentage (0–100). Index 0 always starts at 100.

### GET /media/{id}/analytics/dashboard

Get aggregated statistics for a media item. Requires ownership or `moderate_mvs_media`.

**Response:**

```json
{
  "media_id": 123,
  "total_plays": 310,
  "unique_viewers": 205,
  "completion_rate": 0.42,
  "avg_watch_time": 148,
  "peak_concurrent": 12
}
```

`avg_watch_time` is in seconds. `completion_rate` is a decimal between 0 and 1.

## Viewing Analytics in WP Admin

Open a media item in **Media > All Media**, then click the **Analytics** tab in the edit screen. You can see the retention curve, heatmap, and summary statistics for the last 7, 30, or 90 days.

![Analytics tab on the media edit screen with retention curve](../images/admin-stats.png)
