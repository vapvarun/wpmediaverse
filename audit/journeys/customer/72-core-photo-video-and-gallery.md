---
journey: core-photo-video-and-gallery
plugin: wpmediaverse
priority: critical
roles: [subscriber, other-member, anonymous, administrator]
covers: [core-media-photo, core-media-video, gallery-member-visibility]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Run on BOTH environments: mediaverse.local (nginx, cloud storage) and the Apache wp-env site (local storage, direct delivery on)"
estimated_runtime_minutes: 15
---

# A member's photo and video show, play and stay private when asked (core paths 1, 2, 3, 4, 7)

The product is bought for this: upload, see it in My Media and Explore, open it, play it, and trust
that "private" means private. Check it at BOTH levels every release: the code flow below, then the
browser as each role. A surface found in code but not walked, or seen in the browser but not
explained by the code, is a gap to close before calling it done.

## Code flow to confirm (read before walking)

- Upload: `UploadService::handle()` (`includes/Services/UploadService.php`), random 16-hex name
  (`Services/FilenameStrategy.php`), REST `POST /mvs/v1/media` (`MediaController::create_item`,
  permission `upload_mvs_media`).
- One read gate: `PrivacyService::can_view()` (`includes/Services/PrivacyService.php`). Single page
  `Core/TemplateLoader.php` (branded 404 for a hidden item), REST `get_item_permissions_check`,
  file URLs `SignedUrlService::generate()` / `/serve` re-checks `can_view` on every request.
- Video: faststart `Mp4Faststart::apply()`, poster `generate_video_poster_thumbnails()` (cover art,
  else the browser frame, else the default SVG).
- Gallery: a multi-file upload is one `media_group`; listings hide non-cover members through
  `MediaRepository::gallery_exclude_subquery()`.

## Setup

- Member A (owner), Member B (other member), a logged-out visitor, admin.
- Files: a unique JPG, a 4-second H.264 MP4 with audio, uploaded TOGETHER (one gallery).

## Steps

### 1. Upload (subscriber)
- **Action**: Member A uploads the JPG and MP4 together from `/upload-media/`.
- **Expect**: "2 file(s) uploaded successfully!"; `mvs_media_index` rows public + approved with
  random-name `file_path`; one `media_group`, photo `group_position` 0, video 1.

### 2. My Media (owner)
- **Action**: open `/my-media/`.
- **Expect**: both items, "2 items"; the photo loads; the video tile shows its poster and a play icon.

### 3. Explore and lightbox (visitor)
- **Action**: logged out, open Explore. Click the gallery tile.
- **Expect**: one tile with the gallery badge "2" and reaction/comment counts; lightbox "1 / 2"
  shows the photo, "Next" shows "2 / 2" and the video PLAYS (currentTime advances); the side panel
  says "Log in to comment"; tapping a reaction shows "Please log in to react." with Log in.

### 4. Single pages (visitor)
- **Action**: open `/media/<photo-slug>/` and `/media/<video-slug>/`.
- **Expect**: 200; the photo renders; the `<video>` loads metadata and plays.

### 5. Private (owner makes the photo private)
- **Action**: set the photo to Only me (or, where the owner locks privacy, admin sets it).
- **Expect** as Member B and as the visitor: Explore no longer shows it; `/media/<photo-slug>/` 404;
  `GET /mvs/v1/media/{id}` 404; reactions/comments routes 404; the old direct file path 403/404.

### 6. Gallery siblings stay findable (Basecamp 10392589060, fixed in 2.6.2)
- **Action**: with the cover private (step 5), and again after DELETING the cover, open Explore as
  the visitor; also `GET /mvs/v1/media?group_covers=1`.
- **Expect**: the still-public video is listed itself (it was a gallery member, not private). With
  the cover public again, the gallery is one tile with its count badge and the video is behind it.
- **Watch the slug**: a re-uploaded file name gets a `-1` suffix; grep the real slug.

### 7. Restore
- Delete the test items via `MediaRepository::delete_cascade()` (never raw SQL).
