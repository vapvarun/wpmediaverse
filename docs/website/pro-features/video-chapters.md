# Video Chapters

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro lets you add chapter markers to video files and tracks each viewer's resume position so they can pick up where they left off.

![Video player showing chapter markers on the progress bar](../images/lightbox.png)

## How Chapters Work

Chapter markers appear on the video player's progress bar as clickable tick marks. A chapter list panel opens alongside the player, showing each chapter title and its timestamp. Clicking a chapter jumps the video to that position.

Chapter data is stored per media item via the REST API and rendered into the player on page load.

---

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/`

### GET /media/{id}/chapters

Retrieve the chapter list for a media item.

**Response:**

```json
{
  "chapters": [
    { "time": 0, "title": "Introduction" },
    { "time": 145, "title": "Main Topic" },
    { "time": 312, "title": "Conclusion" }
  ]
}
```

`time` is in seconds from the start of the video.

### PUT /media/{id}/chapters

Replace the chapter list for a media item. Requires ownership or `edit_others_mvs_media`.

**Body:**

```json
{
  "chapters": [
    { "time": 0, "title": "Introduction" },
    { "time": 145, "title": "Main Topic" },
    { "time": 312, "title": "Conclusion" }
  ]
}
```

Sending an empty array (`"chapters": []`) removes all chapters from the media item.

**Response:** `200 OK` with the updated chapter list.

---

## Resume Playback

WPMediaVerse Pro tracks the furthest playback position reached by each authenticated user. When a user returns to a video they have partially watched, the player offers a **Resume from X:XX** prompt.

Resume positions are stored server-side, not in browser storage, so they persist across devices.

### REST API

#### GET /media/{id}/resume

Get the resume position for the current authenticated user.

**Response:**

```json
{
  "position": 187,
  "updated_at": "2025-03-28T09:14:00Z"
}
```

Returns `404` if no resume position exists for this user and media item.

#### POST /media/{id}/resume

Save or update the resume position. The player calls this endpoint as the video plays.

**Body:**

```json
{ "position": 187 }
```

`position` is in seconds. The endpoint accepts updates only when the new position is greater than the stored one, preventing backwards seeks from overwriting progress.

**Response:** `200 OK`.

#### DELETE /media/{id}/resume

Clear the resume position for the current user. This is called when the user watches to the end of the video or manually dismisses resume tracking.

**Response:** `204 No Content`.

---

## Adding Chapters via WP Admin

1. Go to **Media > All Media** and open the media item you want to edit.
2. Scroll to the **Chapters** meta box.
3. Click **Add Chapter**, enter the timestamp (in `M:SS` or `H:MM:SS` format) and a title.
4. Repeat for each chapter.
5. Click **Update**.

![Chapters meta box in the media edit screen](../images/admin-media-list.png)

## Importing Chapters from a File

You can import chapters from a plain text file using the WP-CLI command:

```bash
wp mvs chapters import --media_id=123 --file=/path/to/chapters.txt
```

The file format is one chapter per line: `HH:MM:SS Chapter Title`. Lines starting with `#` are ignored.
