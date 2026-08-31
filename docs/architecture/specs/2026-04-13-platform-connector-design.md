# WPMediaVerse Platform Connector Framework - Design Spec

> **STATUS: IMPLEMENTED (Pro, as of 2.4.0).** Verified in code:
> `wpmediaverse-pro/includes/Connectors/` (`ConnectorInterface`, `ConnectorManager`,
> `ConnectorRESTController`, `OAuthHelper`) plus `includes/Integrations/Flickr/`
> (`Client`, `Connector`, `Mapper`). `ConnectorInterface::export_item()` and the
> `export` capability exist, so the two-way direction is present. Later connectors
> named below (Unsplash, 500px, Google Photos) are NOT built - Flickr is the only
> shipped one.

**Date:** 2026-04-13
**Status:** Implemented
**Scope:** Pro plugin only
**First Connector:** Flickr (two-way sync)

---

## 1. Problem

Users want to connect external photo platforms (Flickr, later Unsplash, 500px, Google Photos) to WPMediaVerse - import their existing photos and auto-push new uploads. Currently the Pro plugin has layout skins (Instagram/Flickr/Dribbble CSS themes) but zero external data integration.

## 2. Approach

Build a **generic Connector Framework** in Pro, then implement Flickr as the first connector. The framework defines interfaces; each platform is a class implementing those interfaces.

**Auth model: Hybrid (Option 3)**
- Default: Plugin ships a built-in Flickr API key for one-click connection
- Advanced: Users can enter their own key for dedicated rate limit (3,600 req/hr per key)
- If shared key hits rate limits, prompt user to switch to their own key
- OAuth access tokens are always per-user regardless of which API key is used

---

## 3. Architecture

### 3.1 Connector Framework (Generic)

**Location:** `wpmediaverse-pro/includes/Connectors/`

```
Connectors/
├── ConnectorInterface.php      # Contract all connectors implement
├── ConnectorManager.php        # Registry, OAuth routing, lifecycle
├── ConnectorSettingsPage.php   # Admin UI: "Connected Accounts" tab
├── ConnectorRESTController.php # REST endpoints for browser-side operations
├── OAuthHelper.php             # Shared OAuth 1.0a utilities
└── Flickr/
    ├── Connector.php           # Implements ConnectorInterface
    ├── Client.php              # Low-level API wrapper
    └── Mapper.php              # Maps Flickr fields ↔ WPMediaVerse fields
```

As shipped, the Flickr classes dropped the redundant `Flickr` prefix (the namespace
already carries it) and live at `includes/Integrations/Flickr/`, not
`includes/Connectors/Flickr/`. `ConnectorSettingsPage.php` was never created; the
Connected Accounts UI lives in `includes/Admin/ProSettings.php` +
`templates/admin/connectors-settings.php`. Read every `FlickrConnector::` /
`FlickrClient` / `FlickrMapper` below as `Connector::` / `Client` / `Mapper`.

### 3.2 ConnectorInterface

```php
interface ConnectorInterface {
    // Identity
    public function get_id(): string;          // 'flickr'
    public function get_label(): string;       // 'Flickr'
    public function get_icon_url(): string;

    // Auth
    public function has_default_credentials(): bool;    // true if plugin ships built-in key
    public function get_custom_auth_fields(): array;    // Fields for user's own key (API key, secret)
    public function get_api_credentials( int $user_id ): array;  // Resolves: custom key → plugin key
    public function start_auth( int $user_id, bool $use_custom = false ): string;  // Returns redirect URL
    public function handle_callback( array $params, int $user_id ): bool;
    public function disconnect( int $user_id ): void;
    public function is_connected( int $user_id ): bool;
    public function validate_connection( int $user_id ): bool;
    public function is_using_custom_key( int $user_id ): bool;

    // Import (Remote → WPMediaVerse)
    public function list_remote( int $user_id, array $args = [] ): array;  // Paginated
    public function list_remote_albums( int $user_id ): array;
    public function import_item( int $user_id, string $remote_id ): int|WP_Error;  // Returns media_id

    // Export (WPMediaVerse → Remote)
    public function export_item( int $user_id, int $media_id ): string|WP_Error;  // Returns remote_id
    public function sync_metadata( int $user_id, int $media_id, string $remote_id ): bool;

    // Capabilities
    public function supports( string $feature ): bool;  // 'import', 'export', 'albums', 'video', 'delta_sync'
}
```

### 3.3 ConnectorManager

- Hooks into `mvs_loaded` to bootstrap
- Registers connectors via `mvs_connectors` filter
- Routes OAuth callbacks: `site.com/?mvs_oauth_callback=flickr`
- Provides `ConnectorManager::get( 'flickr' )` to other services
- Listens to `mvs_media_uploaded` webhook for auto-export

### 3.4 API Key Resolution

```
Plugin-level (wp_options) — as SHIPPED the prefix is mvs_pro_connector_flickr_
(Connector::OPTION_PREFIX); Migrator renames the legacy mvs_connector_flickr_*
options to it:
  mvs_pro_connector_flickr_app_key       → Built-in plugin API key (ships with Pro)
  mvs_pro_connector_flickr_app_secret    → Built-in plugin API secret

User-level override (wp_usermeta):
  mvs_connector_flickr_custom_key    → User's own Flickr API key (optional)
  mvs_connector_flickr_custom_secret → User's own Flickr API secret (optional)
```

**Resolution order:** If user has `custom_key` set → use it. Otherwise → use plugin-level `app_key`. This is handled by `Integrations\Flickr\Connector::get_api_credentials( $user_id )` — the class is
named `Connector` inside the `Flickr` namespace, not `FlickrConnector`. The user-meta prefix is
`Connector::META_PREFIX` = `mvs_connector_flickr_`, which is why user meta and site options do not
share a prefix.

### 3.5 Token & Settings Storage (Per-User)

```
wp_usermeta:
  mvs_connector_flickr_oauth_token   → OAuth access token (encrypted)
  mvs_connector_flickr_oauth_secret  → OAuth token secret (encrypted)
  mvs_connector_flickr_nsid          → Flickr user NSID
  mvs_connector_flickr_username      → Flickr display name
  mvs_connector_flickr_using_custom  → bool: using own key vs plugin key
  mvs_connector_flickr_auto_export   → bool: auto-push new uploads to Flickr
  mvs_connector_flickr_default_privacy → 'match' (use MVS privacy) or specific Flickr level
  mvs_connector_flickr_default_album → Flickr photoset ID or empty
  mvs_connector_flickr_last_delta    → ISO timestamp of last delta sync
```

### 3.5 External Link Tracking

**New meta keys in `mvs_media_meta`:**

| Key | Value | Purpose |
|-----|-------|---------|
| `external_source` | `flickr` | Where this media was imported from |
| `external_id` | `52636789012` | Flickr photo ID (permanent) |
| `external_url` | `https://www.flickr.com/photos/user/52636789012` | Link back to source |
| `external_synced_at` | `2026-04-13T10:30:00Z` | Last sync timestamp |

**Lookup:** `MediaRepository::find_by_meta( 'external_id', $flickr_photo_id )` for dedup on import.

---

## 4. Flickr Connector - Detailed Flow

### 4.1 Setup Flow (One-Time Per User)

**What the user sees:**

```
┌──────────────────────────────────────────────────────┐
│  🔗 Flickr                                           │
│                                                       │
│  [Connect with Flickr]  ← one click, uses plugin key │
│                                                       │
│  ▸ Use your own API key (recommended for heavy usage  │
│    - get one free at flickr.com/services/apps)        │
│    API Key:    [________________________]             │
│    API Secret: [________________________]             │
│    [Connect with My Key]                              │
└──────────────────────────────────────────────────────┘
```

**Quick connect flow (plugin key):**

```
  1. User clicks "Connect with Flickr"
  2. FlickrConnector::start_auth( $user_id ) →
     - Resolves API credentials: plugin-level app_key (from wp_options)
     - Fetches request token from flickr.com/services/oauth/request_token
     - Stores request token + secret in transient (5 min TTL, keyed by user_id)
     - Returns redirect URL to flickr.com/services/oauth/authorize?perms=write
  3. User sees Flickr authorization screen:
     "WPMediaVerse wants to access your Flickr account (write permission)"
     [OK, I'LL AUTHORIZE IT]  [NO THANKS]
  4. User authorizes → Flickr redirects to:
     site.com/?mvs_oauth_callback=flickr&oauth_token=X&oauth_verifier=Y
  5. ConnectorManager catches the callback, routes to FlickrConnector::handle_callback() →
     - Retrieves request token from transient
     - Exchanges for access token at flickr.com/services/oauth/access_token
     - Encrypts and stores tokens in wp_usermeta
     - Calls flickr.test.login → gets NSID + username
     - Stores NSID, username, using_custom=false in wp_usermeta
     - Deletes transient
     - Redirects back to Connected Accounts tab with ?connected=flickr
  6. Page reloads, now shows:
```

**Connected state:**

```
┌──────────────────────────────────────────────────────┐
│  ✓ Flickr - Connected as @username                    │
│                                                       │
│  Using: Plugin key (shared)                           │
│  ⚠ For heavy usage, use your own key for faster sync  │
│                                                       │
│  Auto-push new uploads to Flickr: [ ] enabled         │
│  Default privacy on Flickr: [Match WPMediaVerse ▼]    │
│  Default album: [None ▼]                              │
│                                                       │
│  [Import from Flickr]  [Disconnect]                   │
└──────────────────────────────────────────────────────┘
```

**Custom key flow:**

```
  1. User expands "Use your own API key"
  2. Goes to flickr.com/services/apps/create/apply
     - Picks "Apply for a Non-Commercial Key" (instant approval)
     - Gets API Key + Secret
  3. Pastes both → clicks "Connect with My Key"
  4. Same OAuth flow but uses the user's credentials
  5. Stores custom_key + custom_secret in wp_usermeta, sets using_custom=true
  6. Connected state shows "Using: Your own key (dedicated rate limit)"
```

**Switching keys after connection:**

```
  If user is connected with plugin key and wants to switch:
  1. Disconnect first
  2. Enter own key → reconnect
  Token must be re-issued because OAuth tokens are bound to the API key that created them.
```

**Rate limit nudge:**

```
  When a Flickr API call returns HTTP 429 or error code 99 (rate limit):
  - If using_custom=false: show admin notice:
    "Flickr sync is temporarily slow due to shared usage.
     For faster sync, connect with your own API key. [Learn how]"
  - If using_custom=true: show "Rate limit reached. Try again in a few minutes."
```

### 4.2 Import Flow (User-Initiated)

```
User → Dashboard → "Import from Flickr" button
  1. Opens modal/panel
  2. JS calls REST: GET /mvs-pro/v1/connectors/flickr/photos?page=1&per_page=20
  3. ConnectorRESTController → FlickrConnector::list_remote()
     - Calls flickr.people.getPhotos with user_id=me
     - extras=description,tags,url_m,url_o,original_format,date_taken,license,media
     - Returns paginated list with thumbnails
  4. User browses, optionally filters by album:
     GET /mvs-pro/v1/connectors/flickr/albums
     GET /mvs-pro/v1/connectors/flickr/albums/{id}/photos
  5. User selects photos, clicks "Import Selected"
  6. JS calls REST: POST /mvs-pro/v1/connectors/flickr/import
     Body: { photo_ids: ["123", "456", "789"] }
  7. Per photo, FlickrConnector::import_item():
     a. Check dedup: MediaRepository::find_by_meta('external_id', $photo_id)
        → If exists, skip (return existing media_id)
     b. flickr.photos.getInfo → full metadata
     c. flickr.photos.getSizes → get largest available URL (prefer original)
     d. Download file via wp_remote_get() to temp
     e. UploadService::handle() → creates mvs_media_index record
     f. Map and store metadata:
        - title, description → mvs_media_index
        - tags → wp_set_object_terms + MediaRepository::set (dual-write!)
        - privacy → map Flickr privacy to MVS privacy level
        - EXIF → MediaRepository::set (if available via flickr.photos.getExif)
     g. Store external link:
        - MediaRepository::set( $id, 'external_source', 'flickr' )
        - MediaRepository::set( $id, 'external_id', $flickr_photo_id )
        - MediaRepository::set( $id, 'external_url', $flickr_page_url )
        - MediaRepository::set( $id, 'external_synced_at', current_time('c') )
     h. Fire action: do_action( 'mvs_media_imported', $media_id, 'flickr', $flickr_photo_id )
  8. Return results: { imported: 3, skipped: 0, failed: 0, details: [...] }
```

### 4.3 Export Flow (Auto + Manual)

**Auto-export (on upload):**
```
User uploads media to WPMediaVerse
  → mvs_media_uploaded webhook fires
  → ConnectorManager checks: does this user have auto_export enabled for any connector?
  → If yes, FlickrConnector::export_item():
    a. Get file_url from MediaRepository
    b. Download file to temp (or use local path)
    c. POST multipart to https://up.flickr.com/services/upload/
       - photo: file bytes
       - title: media title
       - description: media description
       - tags: space-separated tags
       - is_public/is_friend/is_family: mapped from MVS privacy
    d. Parse response → get Flickr photo_id
    e. Store external link in mvs_media_meta (same keys as import)
    f. Optionally: flickr.photosets.addPhoto if user has a default album set
    g. Fire action: do_action( 'mvs_media_exported', $media_id, 'flickr', $flickr_photo_id )
```

**Manual export:**
```
User → Media single page → "Push to Flickr" button (or dashboard bulk action)
  → Same flow as auto-export but user-triggered via REST endpoint
  POST /mvs-pro/v1/connectors/flickr/export
  Body: { media_ids: [123, 456] }
```

### 4.4 Privacy Mapping

| WPMediaVerse | Flickr | is_public | is_friend | is_family |
|---|---|---|---|---|
| public | Public | 1 | 0 | 0 |
| members | Friends+Family | 0 | 1 | 1 |
| friends (BP) | Friends | 0 | 1 | 0 |
| private | Private | 0 | 0 | 0 |
| group | Friends+Family | 0 | 1 | 1 |
| custom | Private (safe default) | 0 | 0 | 0 |

User can override with `mvs_connector_flickr_default_privacy` = 'match' or a forced level.

### 4.5 Delta Sync (Optional, User-Triggered)

Not automatic cron. User clicks "Check for updates" button:

```
1. Read last sync timestamp from user_meta: mvs_connector_flickr_last_delta
2. Call flickr.photos.recentlyUpdated with min_date = last_delta
3. For each returned photo:
   a. Check if external_id exists in mvs_media_meta
   b. If yes → update title, description, tags, privacy from Flickr
   c. If no → show as "new on Flickr, import?" (don't auto-import)
4. Update mvs_connector_flickr_last_delta = now
```

---

## 5. REST API Endpoints (Pro)

All under namespace `mvs-pro/v1/connectors`.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/connectors` | List available connectors + connection status |
| POST | `/connectors/{id}/connect` | Start OAuth (returns redirect URL) |
| POST | `/connectors/{id}/disconnect` | Revoke connection |
| GET | `/connectors/{id}/status` | Check connection validity |
| GET | `/connectors/{id}/photos` | List remote photos (paginated) |
| GET | `/connectors/{id}/albums` | List remote albums |
| GET | `/connectors/{id}/albums/{album_id}/photos` | List album photos |
| POST | `/connectors/{id}/import` | Import selected photos |
| POST | `/connectors/{id}/export` | Export selected media |
| POST | `/connectors/{id}/sync` | Delta sync check |

Permission: All require `is_user_logged_in()` + user must have the connector connected.

---

## 6. Admin UI

### 6.1 Connected Accounts Tab (Admin Settings)

Added as a new tab in Pro Settings page. Gated by `mvs_connectors_enabled` option (follows Pro feature toggle pattern from CLAUDE.md Section 5).

```
Connected Accounts
├── Flickr
│   ├── Not connected:
│   │   ├── [Connect with Flickr] (one-click, plugin key)
│   │   └── ▸ Use your own API key (expandable)
│   │       ├── API Key: [____________]
│   │       ├── API Secret: [____________]
│   │       └── [Connect with My Key]
│   ├── Connected:
│   │   ├── ✓ Connected as @username
│   │   ├── Using: Plugin key / Your own key
│   │   ├── Auto-push new uploads: [checkbox]
│   │   ├── Default privacy: [Match WPMediaVerse ▼]
│   │   ├── Default album: [None ▼] (fetched from Flickr on load)
│   │   └── [Import from Flickr] [Disconnect]
├── (Future: Unsplash, 500px, Google Photos - greyed out "Coming Soon")
└── Footer: "This product uses the Flickr API but is not endorsed
    or certified by SmugMug, Inc." (TOS requirement)
```

### 6.2 Dashboard Import Panel

In the user's WPMediaVerse dashboard:

```
Import from Flickr [button]
  → Opens modal:
  ├── Tab: All Photos | Albums
  ├── Grid of Flickr thumbnails with checkboxes
  ├── Pagination
  ├── "Import Selected (3)" button
  └── Progress bar during import
```

### 6.3 Media Single Page

If media has `external_source`:
```
Source: Flickr - View on Flickr [link]
Last synced: Apr 13, 2026
[Sync Now] [Push Updates to Flickr]
```

---

## 7. Flickr API Constraints & Mitigations

| Constraint | Impact | Mitigation |
|---|---|---|
| 3,600 req/hr per API key | Shared plugin key = bottleneck at scale | Hybrid: plugin key for easy onboarding, user's own key for heavy usage. Rate limit nudge when shared key is throttled. |
| Original photo access | User must enable original downloads in Flickr prefs | Fall back to largest available size; show warning if original unavailable |
| OAuth 1.0a (not 2.0) | More complex signing | OAuthHelper utility class handles signing |
| Tokens don't expire | Good - no refresh flow needed | Validate with flickr.auth.oauth.checkToken periodically |
| PuSH is Pro-only + experimental | Can't rely on webhooks from Flickr | User-triggered delta sync via flickr.photos.recentlyUpdated |
| Free Flickr: 1,000 photo limit | User may run out of space | Check flickr.people.getUploadStatus before export; show warning |
| 30 photos per page in app (TOS) | Display limit | Respect in browse UI; import itself has no limit |
| HMAC-SHA1 signing | PHP needs hash_hmac | Standard PHP, no extensions needed |
| "Not endorsed by SmugMug" notice | TOS requirement | Add notice in Connected Accounts tab footer |

---

## 8. Data Flow Diagram

```
┌─────────────┐     OAuth 1.0a      ┌───────────────┐
│  User's WP   │ ◄──────────────── │  Flickr API    │
│  Dashboard    │                    │  (user's key)  │
└──────┬───────┘                    └───────┬────────┘
       │                                     │
       │  "Import from Flickr"               │
       │  ────────────────────►              │
       │  list_remote() → flickr.people.getPhotos
       │  ◄──── thumbnails + metadata ──────│
       │                                     │
       │  "Import Selected"                  │
       │  ────────────────────►              │
       │  import_item() per photo:           │
       │    flickr.photos.getInfo            │
       │    flickr.photos.getSizes           │
       │    download file                    │
       │    UploadService::handle()          │
       │    store external_id in meta        │
       │  ◄──── media_id ──────────────────│
       │                                     │
       │  User uploads to WPMediaVerse       │
       │  mvs_media_uploaded hook fires      │
       │  ────────────────────►              │
       │  export_item():                     │
       │    POST up.flickr.com/upload        │
       │    store flickr photo_id in meta    │
       │  ◄──── flickr_photo_id ───────────│
       │                                     │
       │  "Check for updates"                │
       │  ────────────────────►              │
       │  flickr.photos.recentlyUpdated      │
       │  ◄──── changed photos ────────────│
       │  update local metadata              │
```

---

## 9. Future Connectors

The framework supports any platform. Planned:

| Platform | Auth | Import | Export | Notes |
|---|---|---|---|---|
| Flickr | OAuth 1.0a | Yes | Yes | First connector |
| Unsplash | OAuth 2.0 | Yes | No (read-only API) | Source attribution required |
| 500px | OAuth 2.0 | Yes | Yes | Similar to Flickr |
| Google Photos | OAuth 2.0 | Yes | Yes | Requires Google Cloud project |
| Dropbox | OAuth 2.0 | Yes | Yes | File-based, not photo-specific |

Each = one new class implementing ConnectorInterface + a platform Client + a Mapper.

---

## 10. Verification Plan

1. **Unit tests:** FlickrClient mock responses, FlickrMapper field mapping, privacy mapping
2. **Integration test:** Connect → list → import 1 photo → verify mvs_media_index + meta + taxonomy
3. **Export test:** Upload to MVS → auto-push → verify Flickr photo_id stored
4. **Dedup test:** Import same photo twice → second time should skip
5. **Delta sync test:** Modify title on Flickr → run sync → verify MVS title updated
6. **Disconnect test:** Disconnect → tokens cleared, auto-export disabled
7. **Rate limit test:** Verify error handling when 3,600/hr exceeded (mock 429 response)
8. **Browser test:** Full flow in Playwright - connect, browse, import, verify in dashboard
