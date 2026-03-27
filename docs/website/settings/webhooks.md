# Webhooks

Access these settings at **Media > Settings > Webhooks**.

Webhooks allow WPMediaVerse to send signed HTTP POST notifications to external services when media events occur.

[screenshot: Webhooks settings tab showing webhook URL list with event checkboxes]

## Supported Events

| Event | Triggered When |
|-------|---------------|
| `media.uploaded` | A new media file is successfully uploaded |
| `media.updated` | An existing media post is saved/updated |
| `media.deleted` | A media post is permanently deleted |
| `media.moderated` | A media item's moderation status changes (approved, flagged, rejected) |
| `media.reaction` | A user adds a reaction to a media item |
| `media.comment` | A user posts a comment on a media item |

## Adding a Webhook

1. Go to **Media > Settings > Webhooks**.
2. Click **Add Webhook**.
3. Enter the **URL** that should receive the POST request.
4. Select which **events** should trigger this webhook.
5. Optionally enter a **secret key** for signature verification.
6. Click **Save Webhooks**.

[screenshot: Add webhook form with URL field, event checkboxes, and secret key field]

## Payload Format

All webhook payloads are sent as JSON via HTTP POST with these headers:

```
Content-Type: application/json
X-WPMediaVerse-Event: media.uploaded
X-WPMediaVerse-Signature: sha256=HMAC_SIGNATURE
```

### Example: media.uploaded Payload

```json
{
  "event": "media.uploaded",
  "timestamp": "2025-03-27T12:00:00Z",
  "site_url": "https://yoursite.com",
  "data": {
    "media_id": 123,
    "title": "My Photo",
    "media_type": "image",
    "file_url": "https://yoursite.com/wp-content/uploads/wpmediaverse/2025/03/photo.jpg",
    "privacy": "public",
    "author_id": 1,
    "created_at": "2025-03-27T12:00:00Z"
  }
}
```

## Verifying Webhook Signatures

Use the secret key you configured to verify that requests are from WPMediaVerse:

```php
$payload   = file_get_contents( 'php://input' );
$signature = $_SERVER['HTTP_X_WPMEDIAVERSE_SIGNATURE'] ?? '';
$secret    = 'your-webhook-secret';

$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

if ( ! hash_equals( $expected, $signature ) ) {
    http_response_code( 401 );
    exit( 'Invalid signature' );
}
```

## Retry Behavior

Failed webhook deliveries (non-2xx HTTP response or connection timeout) are retried up to 3 times with exponential backoff. Failed attempts are logged in the AI/webhook log visible at **Media > AI Logs**.
