# WPMediaVerse — Code Flows

**Generated:** 2026-04-29

End-to-end request paths for the high-traffic features. Use this when debugging "why is X showing the wrong value" — trace from the browser entry point down to the DB query and back. For the full surface inventory, see [`FEATURE_AUDIT.md`](FEATURE_AUDIT.md).

---

## Flow 1 — Media upload (REST)

**Trigger:** user drags a file into the upload component (block / shortcode / BP form).

```
Browser
  └─ FormData multipart POST → /wp-json/mvs/v1/media (REST)
     └─ MediaController::create_item()
        ├─ permission_callback → MediaCapabilities::can_upload($user)
        ├─ validate file → UploadService::validate($file)
        │   ├─ size (mvs_max_upload_size)
        │   ├─ MIME (mvs_allowed_file_types)
        │   └─ EXIF strip (mvs_strip_exif)
        ├─ StorageService::resolve_driver()
        │   └─ apply_filters('mvs_storage_driver')  ← Pro hooks S3/BunnyCDN
        ├─ $driver->store($source, $rel_path)
        ├─ INSERT INTO mvs_media_index
        ├─ UploadService::generate_thumbs($media_id, $file_path)
        │   ├─ wp_get_image_editor()->multi_resize()
        │   ├─ MediaRepository::set('thumb_*')
        │   └─ do_action('mvs_after_thumbnail_generation')
        ├─ if (mvs_ai_auto_analyze) AIService::analyze($media_id)
        │   └─ provider->analyze_image(MediaUrl::for_file($id))   ← signed URL (1.1.3)
        ├─ if (mvs_ai_auto_moderate) AIService::moderate($media_id)
        ├─ do_action('mvs_media_uploaded', $media_id, $path, $user_id)
        │   └─ ActivitySyncIntegration: mirrors to BP activity
        │   └─ NotificationService: notifies followers
        └─ apply_filters('mvs_media_response', $data, $media_id)
            └─ Plugin::maybe_sign_file_url() → signs file_url (always)
```

**Key files:**

| Layer | File | Lines |
|---|---|---|
| REST | `includes/REST/Controller/MediaController.php` | `create_item()` |
| Validation + storage | `includes/Services/UploadService.php` | 1–911 |
| Storage driver | `includes/Services/StorageService.php` + `LocalDriver.php` | full |
| AI hooks | `includes/Services/AIService.php` | `analyze()`, `auto_tag()`, `moderate()` |
| Always-sign | `includes/Core/Plugin.php` | `maybe_sign_file_url():743` |

**Failure modes:** AI analyze fails → status set to `failed`, upload still succeeds. Storage write fails → 500 + DB roll-back. EXIF strip fails → upload aborts.

---

## Flow 2 — Media render in a grid (block / shortcode)

**Trigger:** page renders a `wpmediaverse/media-grid` block or `[mvs_gallery]` shortcode.

```
PHP render
  └─ src/blocks/media-grid/render.php
     └─ MediaRepository::list($args)  → SELECT FROM mvs_media_index
        └─ apply_filters('mvs_media_response', $row, $id)
           └─ Plugin::maybe_sign_file_url()  → signed file_url
     └─ For each row:
        ├─ TemplateHelpers::media_thumbnail($id, $opts)
        │   └─ TemplateHelpers::get_thumb_url($id, 'large')
        │       └─ SignedUrlService::generate_thumbnail($id, $user_id, 'large')  ← signed
        └─ Output <img src="<signed>">
Browser
  └─ src/blocks/media-grid/view.js  (Interactivity API)
     ├─ Lightbox open: actions.openLightbox(mediaId)
     │   └─ TemplateHelpers::get_lightbox_url($id)
     │       └─ MediaUrl::for_file($id)  ← signed full URL
     └─ Reaction toggle: REST POST /reactions
```

**Key files:**

| Layer | File |
|---|---|
| Block render | `src/blocks/media-grid/render.php` |
| Helper | `includes/Core/TemplateHelpers.php:get_thumb_url`, `get_lightbox_url` |
| Signing | `includes/Services/SignedUrlService.php`, `includes/Services/MediaUrl.php` |
| Frontend JS | `src/blocks/media-grid/view.js`, `src/blocks/shared-ui/view.js` |

---

## Flow 3 — Signed-URL serve

**Trigger:** browser requests a signed URL (e.g. `?mvs_serve=1&media=42&token=...&exp=...`).

```
Browser → /wp-json/mvs/v1/serve?...
  └─ SignedUrlController::serve_file()
     ├─ Validate signature (HMAC-SHA256 vs MVS_SIGNED_URL_KEY)
     ├─ Check expiry
     ├─ AccessRulesService::can_access($media_id, $user_id)
     │   └─ if (paywalled & !granted) → 403
     ├─ if (gated & no permission & has watermark) → serve watermark preview instead
     │   └─ WatermarkService::get_preview($id) → apply_filters('mvs_generate_watermark')  ← Pro hooks
     ├─ Resolve filesystem path via $driver->get_full_path($rel)
     └─ readfile() with apply_filters('mvs_serve_file_headers')
```

**Key files:**

| Layer | File |
|---|---|
| Serve endpoint | `includes/REST/Controller/SignedUrlController.php` |
| Token gen/verify | `includes/Services/SignedUrlService.php` |
| Access rules | `includes/Services/AccessRulesService.php` |
| Watermark | `includes/Services/WatermarkService.php` |

**Why this matters:** the upload directory is `.htaccess` deny-all. Every public-facing URL must reach this endpoint or 403.

---

## Flow 4 — DM message send

**Trigger:** user types a message in the messaging UI and hits Send.

```
Browser (assets/js/messaging.js)
  └─ POST /wp-json/mvs/v1/conversations/{id}/messages
     └─ MessagingController::create_message()
        ├─ check_auth + participant check
        ├─ MessagingService::send($conversation_id, $sender_id, $content)
        │   ├─ ReportService::is_blocked($sender, $recipient)? → 403
        │   ├─ FollowService::is_following()? (if mvs_dm_access=followers)
        │   ├─ INSERT INTO mvs_messages
        │   ├─ UPDATE mvs_conversations.last_message_at
        │   └─ do_action('mvs_message_sent')
        │       └─ NotificationListener: pushes notification (Free + Pro extension point)
        └─ JSON response
```

**Key files:**

| Layer | File |
|---|---|
| Controller | `includes/Messaging/MessagingController.php` |
| Service | `includes/Messaging/MessagingService.php` |
| Transport | `includes/Messaging/RestPollingTransport.php` |
| Frontend | `assets/js/messaging.js` |

---

## Flow 5 — BuddyPress activity media render

**Trigger:** page renders a BP activity stream entry that contains MVS media.

```
PHP render
  └─ bp_get_activity_content_body filter (priority 0)
     └─ ActivityContentIntegration::enhance_activity_media_content($content, $activity)
        ├─ Detect legacy markup (rtMedia / MediaPress / BuddyBoss)
        ├─ For each <img>/<video>/<audio> extracted from saved content:
        │   ├─ Resolve $media_id via get_mvs_id_from_file_url($src)
        │   ├─ if ($media_id) → MediaUrl::for_file($media_id)  ← signed
        │   ├─ else          → MediaUrl::resolve($src)         ← signed if /wpmediaverse/
        │   └─ Emit MVS-styled <div class="mvs-activity-media …">
        └─ Inject inline video player for MVS video activities
```

**Today's 1.1.3 patch:** every src extracted from saved HTML now flows through `MediaUrl` instead of being re-emitted raw. Saved activities created before the always-sign filter no longer 403.

**Key files:**

| Layer | File |
|---|---|
| Content transform | `includes/Integrations/BuddyPress/ActivityContentIntegration.php` |
| Helpers | `includes/Integrations/BuddyPress/MediaDisplayHelper.php` |
| Signing | `includes/Services/MediaUrl.php` |

---

## Flow 6 — Album / Profile / Group cover image

**Trigger:** page renders a profile or group "Media" tab (BP integration).

```
ProfileTabIntegration::render_tab() / GroupTabIntegration::render_tab()
  └─ AlbumService::get_cover_url($album_id)
     └─ AlbumService::resolve_media_image_url($media_id)
        ├─ TemplateHelpers::get_thumb_url($id, 'large') (preferred)
        └─ MediaUrl::for_file($id) (fallback)
  └─ Output <img src="<signed cover_url>" …>
```

**Key files:**

| Layer | File |
|---|---|
| Cover resolver | `includes/Services/AlbumService.php:resolve_media_image_url` |
| Tab render | `includes/Integrations/BuddyPress/{Profile,Group}TabIntegration.php` |

---

## Cross-cutting: how Pro extends a Free flow

Pro's bootstrap hooks `mvs_loaded` after Free finishes init. Pro reaches into Free state via `\WPMediaVersePro\Core\Plugin::free_service('key')` (delegates to `ServiceContainer`). It never imports Free namespaces directly (sole exception: `MediaRepository`, acknowledged tech debt).

Common extension touchpoints:

| Free filter / action | Pro consumer |
|---|---|
| `mvs_storage_driver` | Returns `S3Driver` or `BunnyCDNDriver` based on `mvs_storage_provider` setting |
| `mvs_ai_providers` | Registers `GoogleVisionProvider`, `RekognitionProvider` on the AIService |
| `mvs_generate_watermark` | `Watermarker::generate()` — uses `$file_path`, writes preview to `wp-content/uploads/wpmediaverse/previews/` |
| `mvs_stats_tabs` | Injects "Video Analytics" admin tab |
| `mvs_moderation_tabs` | Injects "User Reports" admin tab |
