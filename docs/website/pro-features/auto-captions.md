# Auto-Captions

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro transcribes video and audio files using the OpenAI Whisper API and attaches the result as a WebVTT caption file. Captions are served with the media player and can be edited via the REST API.

![Video player with captions visible and CC button](../images/lightbox.png)

## Requirements

- An OpenAI API key with access to the Whisper transcription endpoint
- The media file must be a supported audio or video format (MP3, MP4, M4A, WAV, WEBM, OGG)

## Enabling Auto-Captions

Go to **Media > Settings > Video** and configure the following options.

| Option | Option Key | Default | Description |
|--------|-----------|---------|-------------|
| Auto-Generate Captions | `mvs_pro_captions_auto` | `0` | Automatically transcribe each uploaded video or audio file |
| Caption Language | `mvs_pro_captions_language` | (auto) | Optional source-language hint passed to the provider |
| Caption Provider | `mvs_pro_captions_provider` | `whisper` | Transcription provider |

The OpenAI API key is read from the free plugin's existing **OpenAI API Key** setting (the `mvs_openai_api_key` option, configured under **Media > Settings > AI & Moderation**). There is no separate Pro Whisper key field - captions reuse the same key as the rest of the AI features.

![Auto-captions settings section with API key field](../images/admin-settings-video.png)

When **Auto-Generate Captions** is on, WPMediaVerse Pro queues a transcription job via Action Scheduler immediately after a file is stored. The caption file is saved once the Whisper API responds.

## Caption File Storage

WebVTT files are stored at:

```
/wp-content/uploads/mvs-captions/{media_id}.vtt
```

If cloud storage is active, the VTT file is also uploaded alongside the media file.

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/`

### GET /media/{id}/captions

Retrieve the caption file content and metadata for a media item.

**Response:**

```json
{
  "status": "complete",
  "language": "en",
  "vtt_url": "https://example.com/wp-content/uploads/mvs-captions/123.vtt",
  "generated_at": "2025-03-28T10:00:00Z"
}
```

`status` can be `none`, `pending`, `complete`, or `failed`.

### POST /media/{id}/captions/generate

Queue Whisper transcription for the media item. Requires ownership or admin (`manage_mvs_settings`). No body is required.

```bash
curl -X POST https://yoursite.com/wp-json/mvs-pro/v1/media/123/captions/generate \
  -H "X-WP-Nonce: NONCE"
```

**Response:** `202 Accepted` with the current status object.

### GET /media/{id}/captions/status

Poll the transcription job status for a media item.

**Response:** `200 OK` with `{ "status": "pending" | "complete" | "failed" | "none" }`.

### PUT /media/{id}/captions

Replace the caption content with edited VTT text. Use this to correct transcription errors.

**Body (JSON):**

```json
{
  "content": "WEBVTT\n\n00:00:00.000 --> 00:00:04.000\nHello and welcome.\n\n00:00:04.500 --> 00:00:09.000\nToday we are covering...",
  "language": "en"
}
```

**Response:** `200 OK` with the updated caption metadata.

### DELETE /media/{id}/captions

Remove the caption file and reset the caption status to `none`.

**Response:** `204 No Content`.

## WebVTT Format

WPMediaVerse Pro always stores captions in WebVTT format. Caption content you submit through the API must begin with the `WEBVTT` header - content that does not start with that header is rejected. There is no automatic SRT-to-VTT conversion; convert SRT to WebVTT before uploading.

Example VTT file:

```
WEBVTT

00:00:00.000 --> 00:00:04.000
Hello and welcome to this video.

00:00:04.500 --> 00:00:09.000
Today we are covering the main topic.
```

## Editing Captions

To correct a transcription, replace the stored VTT through the REST API with `PUT /media/{id}/captions`, passing the edited WebVTT content in the `content` field (see the REST API section above). The content must begin with the `WEBVTT` header.
