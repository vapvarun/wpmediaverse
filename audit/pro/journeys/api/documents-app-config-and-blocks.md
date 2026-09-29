---
journey: documents-app-config-and-blocks
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, subscriber, anonymous]
covers: [MV-DOC-044, MV-DOC-045, documents-app-config, documents-blocks]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "A page with mvs/pro-document-embed pointed at a private document, and mvs/pro-document-list pointed at a mixed-privacy folder"
estimated_runtime_minutes: 6
---

# The app config never draws a tab it will get 403'd from; a block's viewer permission is resolved fresh, never baked in at insert time

**Why this journey exists**: `enabled`/`writable` in `/app/config` are two SEPARATE signals a native client must read correctly — conflating them means a client either hides a working feature or offers a broken one. The two document blocks carry the same risk in template form: permission resolved at insert time instead of view time would leak a private document to whoever loads the page.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>` (editor); unrelated viewer `?autologin=<viewer>`.

## Steps

### 1. `enabled` reflects the per-user capability, not a site-wide constant
- **Action**: `GET /wp-json/mvs/v1/app/config` as a licensed, capable member; then as a member without `use_mvs_documents`.
- **Expect**: `documents.enabled` differs correctly per caller — never a fixed site-wide value.

### 2. `writable` is a separate signal from `enabled`
- **Action**: call `/app/config` as the same capable member on an UNLICENSED site.
- **Expect**: `enabled: true` (reads still work) but `writable: false` — a client should hide upload/attach controls specifically when `writable` is false while still showing the enabled library.

### 3. `privacy_levels` omits `space` without BuddyNext
- **Action**: call `/app/config` with BuddyNext inactive.
- **Expect**: `privacy_levels` in the `documents` block does not include `space`.

### 4. Payload change-detection updates when settings change
- **Action**: note the config's ETag/hash; change a document setting (e.g. max size); re-fetch.
- **Expect**: the change-detection value updates appropriately — a stale client caching `enabled`/`writable` across a licence-state or setting change must be able to detect the drift.

### 5. Document embed block: viewer permission resolved live
- **Action**: insert `mvs/pro-document-embed` with `documentId` set to a private document; view the published page as the editor, then as the unrelated viewer.
- **Expect**: editor sees the document as configured; the unrelated viewer sees nothing / a permission-appropriate empty state — permission is resolved fresh on every page view, never baked in at insert time.

### 6. Document list block: per-viewer filtering
- **Action**: insert `mvs/pro-document-list` with `folderId` set to a folder containing mixed-privacy documents; view as editor, then as the unrelated viewer.
- **Expect**: each viewer sees only the subset of documents in that folder they may open.

### 7. Neither block ever confirms existence to a denied viewer
- **Action**: as the unrelated viewer, inspect the page for any error/permission-denied callout.
- **Expect**: a clean, unremarkable absence — never an error that confirms a private document exists on the page.

### 8. A missing/trashed reference renders a clean empty state
- **Action**: point either block at a `documentId`/`folderId` that no longer exists or was trashed.
- **Expect**: clean empty/missing state, never a PHP notice or blank block.

### 9. 390px rendering of the list block
- **Action**: view the list block at 390px.
- **Expect**: rows do not overflow or clip.

## Pass criteria

1. `/app/config`'s `documents.enabled` and `documents.writable` are correctly independent, per-user, per-licence-state.
2. `privacy_levels` omits `space` when BuddyNext is inactive; change-detection reflects real setting changes.
3. Both blocks resolve viewer permission live, never at insert time, and never leak existence through an error callout.
4. A missing/trashed reference and 390px width both render cleanly.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `writable` mirrors `enabled` instead of the licence state | app-config resolver conflating the two signals | Documents `/app/config` contributor |
| Embed block shows a private document to an unrelated viewer | permission baked in at insert/save time instead of render time | `mvs/pro-document-embed` render callback |
| Missing document reference throws a PHP notice | no existence/trashed guard before rendering | block render callback |
