# Video Chapters

> **Requires MediaVerse Pro** - This feature is available exclusively in the Pro version.

MediaVerse Pro stores chapter markers for video files and tracks each viewer's resume position so they can pick up where they left off. Resume works in the website's video player. Chapters are stored and delivered through the API and the mobile app; the website player does not show them yet.

## How Chapters Work

Chapters are a list of titles with start times, saved per video through the API. They are returned with the video's data, so an app or custom player can draw chapter markers. There is no screen for editing chapters and the website player does not show chapter markers or a chapter list.

---

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/`

### GET /media/{id}/chapters

Retrieve the chapter list for a media item.

**Response:**

```json
{
  "chapters": [
    { "time_seconds": 0, "title": "Introduction" },
    { "time_seconds": 145, "title": "Main Topic" },
    { "time_seconds": 312, "title": "Conclusion" }
  ]
}
```

`time_seconds` is in seconds from the start of the video. An entry can also carry an optional `thumbnail_url`. Chapters are returned in time order. The response also includes the `media_id`.

### PUT /media/{id}/chapters

Replace the chapter list for a media item. Requires ownership of the media, or the `moderate_mvs_media` capability. Not `edit_mvs_media`: every role including Subscriber holds that one, and it means "your own media", never anyone's.

**Body:**

```json
{
  "chapters": [
    { "time_seconds": 0, "title": "Introduction" },
    { "time_seconds": 145, "title": "Main Topic" },
    { "time_seconds": 312, "title": "Conclusion" }
  ]
}
```

Sending an empty array (`"chapters": []`) removes all chapters from the media item.

**Response:** `200 OK` with the media id and the updated chapter list. Chapters can only be set on video items.

---

## Resume Playback

MediaVerse Pro remembers where each signed-in member stopped watching. When the member plays the same video again in the Media Player block, it jumps to the saved spot and briefly shows **Resumed at X:XX**.

- Resume applies to videos longer than 2 minutes, once the member is more than 5 seconds in.
- The position is saved about every 15 seconds while the video plays, and when the member leaves the page.
- Watching to 95% of the video clears the saved position.
- Positions are stored on the server, so they follow the member across devices.
- Visitors who are not signed in do not get resume.

### REST API

#### GET /media/{id}/resume

Get the resume position for the current authenticated user.

**Response:**

```json
{
  "media_id": 42,
  "position": 187
}
```

`position` is `null` when nothing is saved for this user and media item.

#### POST /media/{id}/resume

Save or update the resume position. The player calls this endpoint as the video plays.

**Body:**

```json
{ "position": 187 }
```

`position` is in seconds. A position at or beyond 95% of the video's duration clears the saved position instead.

**Response:** `200 OK` with the media id and the stored position.

#### DELETE /media/{id}/resume

Clear the resume position for the current user.

**Response:** `204 No Content`.

---

## How Chapters Are Stored

Chapters are managed through the REST API (`PUT /media/{id}/chapters`) and stored per media item as JSON. They are returned with the media REST response. Each entry is an object with `time_seconds` (seconds from the start), a `title`, and an optional `thumbnail_url`.
