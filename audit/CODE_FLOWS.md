# WPMediaVerse — Code Flows

**Generated:** 2026-04-29 · **1.2.0 flows added:** 2026-05-03

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

## Flow 7 — Lightbox Download (1.2.0)

**Trigger:** user opens the lightbox and clicks the toolbar Download button.

```
Browser (src/blocks/shared-ui/view.js)
  └─ User clicks .mvs-lightbox-action[data-action="download"]
     └─ JS handler lightboxDownload(state)
        ├─ Pre-check: state.allow_download === true && global mvs_allow_downloads === true
        │   (button is hidden via CSS if either is off — defense-in-depth client + server)
        ├─ POST /wp-json/mvs/v1/media/{id}/download   (line 1196 of shared-ui/view.js)
        │   (no AbortController — fire-and-forget; 30/min rate limit)
        └─ MediaController::record_download($request)
           ├─ check_user_ip() + RateLimiter (30/min per user/IP)
           ├─ MediaRepository::find($id)  → 404 if missing
           ├─ Privacy gate: PrivacyService::can_view($media_id, $current_user_id)
           │   └─ apply_filters('mvs_privacy_can_view', …)  ← Pro PrivacyUI filter
           ├─ Global toggle: get_option('mvs_allow_downloads', true)  → 403 if off
           ├─ Per-media toggle: mvs_media_meta.allow_download !== '0'  → 403 if off
           ├─ INSERT INTO mvs_media_views (media_id, user_id, event_type='download', ip_hash, created_at)
           ├─ INSERT INTO mvs_media_stats (media_id, downloads=1) ON DUPLICATE KEY UPDATE downloads = downloads + 1
           └─ JSON response { success: true, downloads: <new_count> }
     └─ JS triggers actual file download via window.location = signed_url
        (signed URL was already on the lightbox card — no second REST call)
```

**Key files:**

| Layer | File |
|---|---|
| REST | `includes/REST/Controller/MediaController.php::record_download` |
| Privacy gate | `includes/Services/PrivacyService.php::can_view` |
| Stats writer | `includes/Repository/MediaRepository.php::increment_stat('downloads')` |
| Frontend JS | `src/blocks/shared-ui/view.js` (line 1196 area) |
| Settings | `mvs_allow_downloads` (display group) + per-media `allow_download` meta |

**Failure modes:** Privacy 403 → toast "You don't have access". Rate-limit 429 → toast "Slow down". Network hang → no recovery (UX polish deferred to 1.2.1; see `audit/derived/rest-hang-risks.json`).

**Why both global toggle + per-media toggle:** the global toggle is a tenant-wide on/off; the per-media toggle is the author's per-asset choice (settable from the Edit modal). Both must be `true` for the download to record + the file to be served. The UI hides the button if either is off — but the REST endpoint re-validates server-side because hiding alone is not a security boundary.

---

## Flow 8 — Per-media Edit modal (1.2.0)

**Trigger:** user clicks the cog icon on a media card in their dashboard.

```
Browser (src/blocks/shared-ui/view.js)
  └─ User clicks .mvs-media-card-cog
     └─ JS handler openEditModal(mediaId)
        ├─ state.editModalMediaId = mediaId
        ├─ GET /wp-json/mvs/v1/media/{id}   (line 438)
        │   └─ MediaController::get_item($request)
        │      └─ Returns { id, title, description, privacy, allow_download, … }
        ├─ Populate modal fields: title, description, privacy <select>, allow_download <input type="checkbox">
        └─ Display modal (.mvs-edit-modal.is-open)
  └─ User edits + clicks Save
     └─ PUT /wp-json/mvs/v1/media/{id}   (line 485)
        └─ MediaController::update_item($request)
           ├─ permission_callback → MediaCapabilities::can_edit($user, $media_id)  (author OR edit_others_mvs_media)
           ├─ Sanitize: title (sanitize_text_field), description (wp_kses_post), privacy (enum)
           ├─ Sanitize: allow_download (rest_sanitize_boolean) → '1' or '0'
           ├─ MediaRepository::update($id, [ 'title', 'description', 'privacy' ])
           ├─ MediaRepository::set_meta($id, 'allow_download', $allow_download ? '1' : '0')
           └─ apply_filters('mvs_media_response', $data, $media_id) → 200 OK
        └─ JS refresh: re-fetch lightbox card data, close modal, toast "Saved"
```

**Key files:**

| Layer | File |
|---|---|
| REST | `includes/REST/Controller/MediaController.php::update_item` (PUT handler — `allow_download` param added 1.2.0) |
| Repository | `includes/Repository/MediaRepository.php::update` + `set_meta` |
| Frontend | `src/blocks/shared-ui/view.js` (line 438 GET, 485 PUT, 463 modal open) |

**Failure modes:** validation error 400 → field-level error inline. Permission denied 403 → toast "Not allowed". Hang → modal stays open (see hang-risks cache); recommend 10s AbortController in 1.2.1.

**Schema note:** `allow_download` is `string '1'|'0'` in the DB (not bool) because all `mvs_media_meta` values are TEXT. `prepare_item_for_response()` casts to bool for the response: `'allow_download' => ( '0' !== get_meta($id, 'allow_download') )` — so absent meta defaults to `true`.

---

## Flow 9 — PDF Viewer block render (1.2.0)

**Trigger:** page contains a `mvs/pdf-viewer` block (or `[mvs_pdf_viewer]` shortcode).

```
PHP render
  └─ src/blocks/pdf-viewer/render.php
     └─ State machine — 5 server-side states:
        ├─ 1. !$media_id → render_block_empty_state("Pick a PDF media item.")  ← editor placeholder
        ├─ 2. !$media   → render_block_empty_state("Media not found.")           ← deleted media
        ├─ 3. mime not application/pdf → render_block_empty_state("Not a PDF.")  ← wrong type
        ├─ 4. !PrivacyService::can_view($id) → render_block_empty_state("Restricted.")  ← privacy fail
        ├─ 5. !($signed_url = MediaUrl::for_file($id)) → render_block_empty_state("Asset missing.")  ← storage missing
        └─ Happy path: emit
            <iframe
              src="<?= esc_url( $signed_url . '#view=FitH&toolbar=' . ( $show_toolbar ? '1' : '0' ) ) ?>"
              width="100%"
              height="<?= esc_attr( max(200, min(1400, $height)) ) ?>"
              loading="lazy"
              title="<?= esc_attr( $title ) ?>"
            />
```

**Key files:**

| Layer | File |
|---|---|
| Block render | `src/blocks/pdf-viewer/render.php` |
| Empty states helper | `includes/Core/TemplateHelpers.php::render_block_empty_state` (Coding Rule #11) |
| Signed URL | `includes/Services/MediaUrl.php::for_file` |
| Privacy | `includes/Services/PrivacyService.php::can_view` |
| Editor | `src/blocks/pdf-viewer/index.js` + `block.json` (apiVersion 3, attrs `mediaId`/`height`/`showToolbar`) |

**Why URL fragment instead of params:** `#view=FitH&toolbar=...` is the standard PDF Open Parameters spec — supported by Chrome's built-in viewer, Firefox PDF.js, Safari Preview, and Edge. The fragment is browser-side, not server-side, so it works with our signed-URL token (which lives in the query string) without colliding.

**Why iframe + signed URL instead of `<embed>`:** `<embed>` does not honor URL fragments consistently across browsers. iframe respects `#view=FitH` everywhere. The signed-URL token in the query string ensures the upload `.htaccess` deny-all is bypassed only with valid auth.

**Mobile note:** below 640px viewport the iframe still renders at the configured height. iOS Safari uses Apple's PDF preview which ignores `#view=FitH` — viewer drops to default zoom. Not a regression — same behavior as `<a href>` to a PDF.

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
