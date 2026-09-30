---
journey: documents-drive-basics
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber]
covers: [MV-DOC-001, MV-DOC-002, MV-DOC-003, MV-DOC-004, MV-DOC-005, documents-drive, documents-upload, documents-licence]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled absent or 1"
  - "Auto-login mu-plugin available"
  - "A subscriber-role member with use_mvs_documents; a second admin/manage_mvs_documents user"
  - "A Pro license key available to activate/deactivate for the licence steps"
estimated_runtime_minutes: 8
---

# Opening the drive, uploading, and replacing a document's bytes

**Why this journey exists**: `wpmediaverse-pro/audit/journeys/admin/03-documents-settings-and-role-gate.md` already proves the capability-off case (§5) — no drive, 403 from every route. This journey proves the capability-ON path actually works end to end: opening the drive, uploading as a licensed member, the exact licence-locked refusal an ordinary member gets, the admin exemption from that same lock, and replacing a document's bytes without disturbing its identity.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>`; admin `?autologin=admin`.
- Member has `use_mvs_documents`.

## Steps

### 1. Opening the drive, empty and populated
- **Action**: as the member, `GET /wp-json/mvs-pro/v1/documents` and `GET /wp-json/mvs-pro/v1/folders`; then visit `/my-media/documents/`.
- **Expect**: with nothing uploaded, the drive renders an explanatory empty state (copy mentions files are private until shared), not a blank panel. Logged out: `GET /documents` returns 401 `mvs_unauthorized`. As a role without `use_mvs_documents`: 403 `mvs_documents_unavailable`.
- **On fail**: `includes/Documents/` REST gate, `templates/*documents*` empty-state markup.

### 2. Upload as a licensed member
- **Action**: with the Pro licence active, `POST /wp-json/mvs-pro/v1/documents/upload` a file within allowed type/size.
- **Expect**: 201 with the new document row, landing at the configured default privacy unless `privacy` is sent explicitly.
- **On fail**: `Documents/DocumentIngestService::handle()`.

### 3. Upload refused on an unlicensed site, ordinary member
- **Action**: deactivate the Pro licence (`wp option delete wpmediaverse-pro_license_key` or use the License tab). As the ordinary member, `POST /documents/upload` again.
- **Expect**: 403 `mvs_documents_read_only`, message: "This site is not accepting changes to documents at the moment. Your files are still here — you can open, download and share them as before." The route still exists (never 404). A logged-out caller to the same route still gets 401 first, not the licence answer.
- **On fail**: the licence-gate ordering in the upload permission callback.

### 4. Admin/documents-manager is exempt, same unlicensed state
- **Action**: as admin (or a `manage_mvs_documents` user), upload while still unlicensed.
- **Expect**: succeeds identically to a licensed site, no special messaging.
- **On fail**: the `manage_options`/`manage_mvs_documents` exemption check is missing or is a cached role snapshot rather than per-request.

### 5. Reactivating the licence immediately unlocks writes
- **Action**: reactivate the licence; as the ordinary member, retry the upload from step 3 without anything else changing.
- **Expect**: succeeds on the very next request — no caching lag.

### 6. Replacing a document's bytes
- **Action**: as the document's owner (licensed, or admin exemption), `POST /wp-json/mvs-pro/v1/documents/{id}/replace` with a new file.
- **Expect**: bytes swap; id/slug/title/folder/privacy/grants are unchanged. Old file archived, recoverable 30 days, then permanently deleted.
- **On fail**: `Documents/` replace handler.

### 7. Replace refused for a view/comment-level grantee
- **Action**: share the document at `view` level with a third member (see `documents-sharing.md`); as that grantee, attempt `/replace`.
- **Expect**: clear permission error, not a hidden/disabled control with no explanation.

### 8. `doc_type` mismatch is refused, not corrected
- **Action**: submit an upload/replace whose declared `doc_type` doesn't match the actual file's real type.
- **Expect**: refused, never silently reinterpreted as the correct type.

## Pass criteria

1. Drive opens with the correct empty/populated state and the right 401/403 for logged-out/uncapped viewers.
2. Licensed upload succeeds; unlicensed ordinary-member upload gets the exact `mvs_documents_read_only` message and the route never 404s.
3. Admin/documents-manager uploads succeed unlicensed.
4. Reactivating the licence unlocks writes on the very next request.
5. Replace swaps bytes only, preserves identity, and is refused for sub-edit grantees.
6. A `doc_type` mismatch is refused, never auto-corrected.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Drive shows blank panel instead of empty state | empty-state template missing/removed | `templates/` drive partial |
| Read-only message is generic, not the reassuring exact string | licence-gate message not wired to `mvs_documents_read_only` | Documents upload permission callback |
| Admin also refused unlicensed | exemption check missing `manage_options`/`manage_mvs_documents` | Documents upload permission callback |
| Reactivation needs a reload/second request | licence state cached | `License::is_valid()` caller in Documents gate |
| Replace changes folder/privacy/grants | replace handler touching fields it shouldn't | Documents replace handler |
