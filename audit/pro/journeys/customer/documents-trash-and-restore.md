---
journey: documents-trash-and-restore
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber]
covers: [MV-DOC-013, MV-DOC-014, MV-DOC-015, MV-DOC-016, MV-DOC-017, MV-DOC-018, documents-trash, documents-restore]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on; licence active (or admin exemption)"
  - "Member A (uploader), Member B (documents admin with manage_mvs_documents), Member C (unrelated, previously held an edit-level share grant on A's document)"
estimated_runtime_minutes: 8
---

# Trash revokes shares as it goes; restore is stricter than edit; the listing IS the permission boundary

**Why this journey exists**: this is the one area with a documented prior regression — bulk restore once let an edit-level share grantee restore something the single restore route and the trash listing both refused. Restore must be strictly narrower than edit, everywhere it's exposed.

## Setup

- Site: `$SITE_URL`; Member A `?autologin=<A>`, Member B (admin) `?autologin=<B>`, Member C `?autologin=<C>`.
- Member A owns a document; Member C previously held an `edit` share grant on it (grants are cleared on trash anyway — this is the point).

## Steps

### 1. Trashing revokes shares and warns about it
- **Action**: as Member A, `DELETE /wp-json/mvs-pro/v1/documents/{id}` (trash).
- **Expect**: status flips to trashed; ALL active shares on it are cleared as a side effect. Confirm message: "Moved to trash. It is no longer shared with anyone. You can put it back from Trash." The UI should show a confirm step before trashing, naming this side effect — a member sharing with a collaborator must understand trashing breaks that access.
- **On fail**: `Documents/` trash handler share-revocation.

### 2. Trashing an already-trashed document is a no-op, not an error trap
- **Action**: trash the same document again (simulating a concurrent second trash).
- **Expect**: no-op/gone response, not an error.

### 3. Restore as the uploader
- **Action**: as Member A, `POST /wp-json/mvs-pro/v1/documents/{id}/restore`.
- **Expect**: "Restored." if the parent folder is not trashed.

### 4. Restore blocked by a trashed parent folder
- **Action**: trash the document AND its containing folder; as Member A, attempt restore.
- **Expect**: refused with "Restore the folder it was in first." — never restores into limbo.

### 5. Restore as documents-admin (non-uploader)
- **Action**: trash a document owned by Member A; as Member B (admin, `manage_mvs_documents`), open the trash view and restore it.
- **Expect**: succeeds regardless of authorship; Member B's trash listing shows every restorable trashed document, not just their own.

### 6. Restore refused for an unrelated member — including a former edit grantee
- **Action**: as Member C (previously held an `edit` share grant, now cleared by trashing), attempt `POST /documents/{id}/restore` directly by id.
- **Expect**: refused, "You don't own that document." The document must never even appear in Member C's own trash listing.
- **On fail**: restore permission check is reusing the pre-trash grant list instead of the stricter owner/admin-only rule.

### 7. Trash listing scopes to what each viewer may restore
- **Action**: seed trash with documents from both Member A and Member B; list `GET /documents?status=trash` as Member A, then as documents-admin.
- **Expect**: Member A sees only their own trashed documents; the admin sees all — the listing IS the permission boundary shared by the restore route and bulk restore.

### 8. A trashed document 404s for everyone, including former viewers
- **Action**: as a member who previously had view access to the document before it was trashed, request it directly.
- **Expect**: 404.

### 9. Permanent delete actually removes the file from disk
- **Action**: permanently delete a trashed document (Free's media permanent-delete path); check the documents storage directory on disk.
- **Expect**: the file is genuinely gone — this is a fix over prior behaviour where permanent delete reported success but left the file on disk. A document that had a prior "replaced" archive (from `documents-drive-basics.md` step 6) must have that archive cleaned up too, not just the current file.
- **On fail**: the file-orphan cleanup hook Pro attaches to Free's permanent-delete event.

## Pass criteria

1. Trashing clears all active shares and states this in its confirmation.
2. Restore succeeds for the uploader and for documents-admin, is blocked by a trashed parent folder, and is refused for anyone else — including a pre-trash edit grantee.
3. The trash listing scope matches exactly what each viewer can restore, no drift.
4. A trashed document 404s universally, including for former viewers.
5. Permanent delete removes the on-disk file and any replaced-file archive.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Former edit grantee can restore | restore permission reuses the general edit-authority check instead of owner/admin-only | restore route permission callback |
| Trash listing shows a document the viewer then can't restore | listing query and restore-authority check drifted apart | trash listing query vs. restore permission |
| Permanent delete leaves the file on disk | orphan-cleanup hook not attached, or missed the replaced-file archive | file-orphan delete hook |
| Restoring into a trashed folder succeeds | parent-trashed guard missing | restore handler folder-state check |
