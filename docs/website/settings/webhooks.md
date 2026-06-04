# Webhooks

Access these settings at **WPMediaVerse > Settings > Webhooks**.

Webhooks allow WPMediaVerse to send signed HTTP POST notifications to external services when media events occur.

![Webhooks settings tab showing webhook URL list](../images/admin-settings-general.png)

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

1. Go to **WPMediaVerse > Settings > Webhooks**.
2. Click **Add Webhook**.
3. Enter the **URL** that should receive the POST request.
4. Select which **events** should trigger this webhook.
5. Optionally enter a **secret key** for signature verification.
6. Click **Save Webhooks**.

![Add webhook form with URL field and event checkboxes](../images/admin-settings-general.png)

## Payload Format

All webhook payloads are sent as JSON via HTTP POST with these headers:

```
Content-Type: application/json
X-MVS-Event: media.uploaded
X-MVS-Delivery: <uuid>
X-MVS-Signature: sha256=HMAC_SIGNATURE
```

The `X-MVS-Signature` header is only sent when a secret key is configured for the webhook.

### Example: media.uploaded Payload

```json
{
  "event": "media.uploaded",
  "timestamp": "2025-03-27T12:00:00Z",
  "site_url": "https://yoursite.com",
  "data": {
    "media_id": 123,
    "title": "My Photo",
    "author": 1,
    "url": "https://yoursite.com/media/my-photo/",
    "file_url": "https://yoursite.com/wp-content/uploads/wpmediaverse/2025/03/photo.jpg",
    "file_type": "image",
    "privacy": "public",
    "status": "publish"
  }
}
```

## Verifying Webhook Signatures

Use the secret key you configured to verify that requests are from WPMediaVerse:

```php
$payload   = file_get_contents( 'php://input' );
$signature = $_SERVER['HTTP_X_MVS_SIGNATURE'] ?? '';
$secret    = 'your-webhook-secret';

$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

if ( ! hash_equals( $expected, $signature ) ) {
    http_response_code( 401 );
    exit( 'Invalid signature' );
}
```

## Retry Behavior

Failed webhook deliveries (5xx HTTP response or connection error) are retried up to 2 times via Action Scheduler, after 5 minutes and then 10 minutes. 4xx client-error responses are not retried. After the final attempt the failure is recorded in the `mvs_webhook_failures` option.
