---
journey: settings-general-tab
plugin: wpmediaverse
priority: high
roles: [administrator, member]
covers: [MV-SET-001, MV-SET-002, MV-SET-003, MV-SET-004, MV-SET-005, MV-SET-006, MV-SET-010, MV-SET-011, MV-SET-012, MV-SET-015, MV-SET-033, MV-SET-034, MV-SET-035, settings-general-tab]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WP-CLI + mysql_query access for option/DB checks"
  - "A non-admin member test account, and a second disposable install for the destructive MV-SET-006 step"
estimated_runtime_minutes: 15
---

# General tab settings persist and each gates its own real behavior

**Why this journey exists**: General/Mobile-App/Webhooks are 13 individually small owner-facing settings bundled here because each is a one-setting, one-behavior check, not a flow. A settings screen that "saves" but whose value is never read by the pipeline it names is a dead toggle — this journey proves each option round-trips through `wp_options` (or user meta) AND changes what the plugin actually does, not just what the screen shows.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Settings screens: `admin.php?page=mvs-settings-general` (storage limit, upload size, allowed types, dup detection, EXIF, uninstall, default privacy, allow-set-privacy, who-can-upload, email toggles, pages); `admin.php?page=mvs-settings-app` (Mobile App tab, MV-SET-015); `admin.php?page=mvs-settings-webhooks` (MV-SET-035).
- A member test account with no `manage_mvs_settings` capability.
- Test assets: a JPEG with GPS EXIF data; a small MP4; a disposable WP install for the MV-SET-006 uninstall step.

## Steps

### 1. MV-SET-001 — Fair-use storage limit per member
- **Action**: Set "Fair-use storage limit per member (MB)" (`mvs_storage_limit_mb`) to `5`, save, then upload as a member until used storage plus the new file exceeds 5 MB.
- **Assert**: `wp option get mvs_storage_limit_mb` == `5`; the over-limit upload is refused with a specific "over your storage limit" message, not a generic failure; setting a per-user override via the member's profile screen (blank = falls back to site limit, `0` = unlimited) always wins over this site-wide value.

### 2. MV-SET-002 — Max Upload Size
- **Action**: Set "Max Upload Size" (`mvs_max_upload_size`) to a low byte value (e.g. `1048576` = 1 MB); save; attempt to upload a larger file as a member.
- **Assert**: option persists in bytes; the oversized upload is rejected with a message naming the limit, not a generic 500/silent failure. Confirm the known gap: no on-screen warning exists if this value exceeds PHP's own `upload_max_filesize`/`post_max_size` — that's a documented limitation, not a new bug to file.

### 3. MV-SET-003 — Allowed File Types
- **Action**: Untick `video/mp4` in "Allowed File Types" (`mvs_allowed_file_types`); save; upload an MP4. Then untick every box and attempt to save.
- **Assert**: the MP4 upload is refused, message names the allowed types; a PDF upload is still hard-refused regardless of ticks (`UploadService::hard_refused_mimes()`). Saving with zero boxes ticked is refused outright — the previous selection is kept and the notice "Pick at least one file type..." appears (never a silent empty-allowlist or empty-blocklist).

### 4. MV-SET-004 — Duplicate Detection
- **Action**: Set `mvs_duplicate_action` to `skip` ("Block the upload"), save, re-upload an identical file (same bytes). Repeat with `warn` and `allow`.
- **Assert**: `skip` refuses the re-upload with a stated reason; `warn` allows it but shows a visible on-screen duplicate warning (not just a log entry) with a way to proceed or cancel; `allow` performs no hash check at all.

### 5. MV-SET-005 — Remove location from photos (EXIF strip)
- **Action**: Confirm `mvs_strip_exif` is on (default); upload the GPS-tagged JPEG; download the stored original and inspect its EXIF.
- **Assert**: GPS IFD is gone; camera make/model, exposure, and IPTC/XMP copyright/credit survive. Files stored before the setting was toggled are never rewritten retroactively when the toggle changes later.

### 6. MV-SET-006 — Remove Data on Delete (uninstall)
- **Action**: On the disposable install, confirm the description text warns of irreversibility BEFORE ticking; tick `mvs_delete_data_on_uninstall`; deactivate then delete the plugin from the Plugins list.
- **Assert**: all 23+ `mvs_*` tables, plugin options, related postmeta and role capabilities are gone after delete; uploaded files and the pages MediaVerse created are untouched either way (this setting never touches them). Separately, confirm the untucked default (leave the box off, delete the plugin) leaves tables/media intact (reinstalling shows old data).

### 7. MV-SET-010 — Default Privacy Level
- **Action**: Set `mvs_default_privacy` to `members`; save; upload as a member without touching the privacy control (or with MV-SET-011 off).
- **Assert**: the new upload's privacy is `members`; changing this setting never touches already-uploaded media, only future uploads.

### 8. MV-SET-011 — Allow Users to Set Privacy
- **Action**: Turn `mvs_allow_user_privacy` off; open the upload form as a member; attempt `PUT /mvs/v1/media/{id}` changing that member's own media privacy to a different level; then re-send the SAME level.
- **Assert**: the privacy selector disappears from the upload form, album create/update, and the BuddyPress activity form/picker — one switch, all surfaces; the CHANGE attempt returns `403 mvs_privacy_locked`; re-sending the SAME level still succeeds; a `manage_mvs_settings` holder keeps full control regardless.

### 9. MV-SET-012 — Who can upload media
- **Action**: Untick Subscriber in "Who can upload media"; save; log in as a Subscriber and attempt `POST /mvs/v1/media` and check the frontend upload control.
- **Assert**: the Subscriber role's `upload_mvs_media` capability is actually removed (check the role's real capability, e.g. `wp role list-caps subscriber`, not just the `mvs_upload_roles` option value — the catalog explicitly warns the option is transport-only); the API returns `403 mvs_forbidden`; the frontend upload control is hidden/disabled; Administrators can always upload regardless of this list.

### 10. MV-SET-015 — Mobile App tab
- **Action**: On `admin.php?page=mvs-settings-app`, turn off "App Sign-In" (`mvs_app_password_login`); attempt `POST /mvs/v1/auth/app-password` as a member; set a Terms of Service URL and Abuse Contact Email; check `GET /mvs/v1/app/config`.
- **Assert**: the app-password EXCHANGE endpoint refuses while off; `app/config` reflects the Terms URL and Abuse Contact Email (empty contact falls back to site admin email); an Application Password already issued BEFORE the toggle was turned off keeps working — this toggle gates only the exchange endpoint, never WordPress's own native Application Password auth for already-issued credentials.

### 11. MV-SET-033 — Email notification toggles
- **Action**: Turn on "Report reviewed" (`mvs_email_report_outcome`); resolve a report on a member's content; confirm the member receives an email. Turn it off, repeat, confirm no email. Separately confirm defaults: fresh install has all three (`mvs_email_battle_invite`, `mvs_email_document_shared`, `mvs_email_report_outcome`) ON; a site updated from 2.5.x has them OFF.
- **Assert**: the toggle gates the actual email send, not just the stored option; the fresh-install-vs-update default split holds (an update must never start mailing members who never opted in).

### 12. MV-SET-034 — Pages (Explore/My Media/Upload/Explore Documents)
- **Action**: Confirm each Pages field already points at a real published page (set by Activator at install — see MV-WIZ-005); change one (e.g. Explore) to a different published page containing `[mvs_gallery]`; save; follow the Overview page's "Explore Page" quick link.
- **Assert**: the quick link follows the newly saved page id; an unset (`0`) field on the settings screen shows "Missing" with a clear next action, never a plain broken link; `mvs_page_explore_documents` correctly stays unset (`0`) on a Free-only fresh install with no Pro documents and no legacy rows — this is expected, not a bug.

### 13. MV-SET-035 — Webhook Configuration
- **Action**: On `admin.php?page=mvs-settings-webhooks`, add a webhook for `media.uploaded`; save; upload a media item; inspect the received payload at the receiving endpoint. Then make the endpoint return 500 and repeat the trigger.
- **Assert**: the payload carries a signature header and the documented event shape; with Action Scheduler present (bundled under `libs/`), up to 3 retries are attempted on a failing endpoint; confirm there is genuinely no "send test event" control anywhere in the settings UI — an admin can only verify wiring via a real triggering event (documented gap, not a new bug).

## Pass criteria

ALL hold:
1. Every option above persists to `wp_options` (or user/role state where noted) byte-for-byte after save + reload.
2. Each setting changes real plugin behavior, not just its own stored value — verified by the paired action in each step (refusal message, capability change, email sent/not-sent, control shown/hidden).
3. Destructive or irreversible settings (MV-SET-006) show their warning at tick-time, not only at the point of no return.
4. No setting silently no-ops when saved with an edge-case value (empty allowlist, zero limit, unset page) — each has a defined, visible behavior.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Setting reverts after reload | Duplicate `register_setting()` or sanitizer overwrite | `includes/Admin/Settings/GeneralSettingsRegistrar.php`, `Sanitizers.php` |
| Storage/upload-size limit not enforced | Upload pipeline reads stale option or wrong filter | `includes/Services/UploadService.php` |
| Privacy lock (MV-SET-011) not enforced on PUT | REST guard missing the `mvs_privacy_locked` check | `includes/REST/Controller/MediaController.php`, `includes/Services/PrivacyService.php` |
| Role still uploads after unticking (MV-SET-012) | Capability not removed on save, only option written | `includes/Capabilities/MediaCapabilities.php::apply_role_selection()` |
| App-password exchange still works while off | Toggle not checked at the exchange route | `includes/Auth/AppConnect.php` |
| Email not sent despite toggle on | Notification dispatcher doesn't check the option, or template missing | `includes/Social/NotificationService.php`, mailer templates |
| Webhook payload missing signature / no retry | Dispatcher signing or Action Scheduler wiring | `includes/Integrations/WebhookService.php` |
| Pages quick link stays on old page | Overview page reads a cached option | `includes/Admin/OverviewPage.php` |
