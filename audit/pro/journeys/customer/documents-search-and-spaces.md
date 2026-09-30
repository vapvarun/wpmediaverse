---
journey: documents-search-and-spaces
plugin: wpmediaverse-pro
priority: normal
roles: [subscriber]
covers: [MV-DOC-028, MV-DOC-029, MV-DOC-030, MV-DOC-031, MV-DOC-032, documents-search, documents-spaces]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on; mvs_pro_documents_extraction on (default)"
  - "BuddyNext active with at least two Spaces the member can write to"
estimated_runtime_minutes: 8
---

# Search finds content everywhere except PDFs; a document can live in many spaces at once without ever leaving its home drive

**Why this journey exists**: `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` §13 only proves the extraction toggle stops new indexing; this journey proves the search ranking/PDF-exception behavior itself, and the BuddyNext-bridged multi-space linking model that has no MediaVerse-native UI and no coverage elsewhere.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>` with documents matching by title, body text, and tags, including one PDF.

## Steps

### 1. Search ranks title/content ahead of tags
- **Action**: `GET /wp-json/mvs-pro/v1/documents/search?q=<term>&drive=...` for an exact title term, a body-only term (plain-text/Office doc), and a tag-only term.
- **Expect**: title/content matches rank ahead of tag-only matches.

### 2. PDF is findable by title/tags only, never body text
- **Action**: search the PDF by a term that only exists in its title/tags; then by a term only in its body.
- **Expect**: the title/tag search finds it; the body-only search does not — extraction never runs on PDFs, by design, not a bug.

### 3. A freshly uploaded, not-yet-extracted document shows "indexing," not false "no results"
- **Action**: upload a new document and immediately search for a body term.
- **Expect**: an "indexing" state, not a misleading empty result.

### 4. Query below minimum term length is handled gracefully
- **Action**: search with a 1-character query.
- **Expect**: no crash, no full-table scan.

### 5. Space search covers files linked into it, not only ones uploaded there
- **Action**: link a document (uploaded elsewhere) into a Space via step 7; search that Space for a term unique to the linked document.
- **Expect**: it appears in results, indistinguishable from a native file.
- **Also**: confirm the public Explore search never surfaces a document reachable only through this scoped search.

### 6. Link a document into two different spaces independently
- **Action**: `POST /wp-json/mvs-pro/v1/documents/{id}/spaces` (or `/documents/link`) targeting Space A, then Space B.
- **Expect**: both succeed independently; the document appears at the root of each space's Files tab regardless of its home folder.

### 7. Link refusals: already-native, no-write-access, not-editable
- **Action**: attempt linking a document into the space it already natively lives on; into a space you can't write to; a file you don't own/can't edit.
- **Expect**: "This file already lives in this space." / not-found (deliberately, not forbidden, to avoid confirming a secret space exists) / "you can't edit this file" — each distinct.

### 8. Unlinking is scoped to moderators/owner, and is licence-gated
- **Action**: as a plain space member (not moderator, not doc owner), attempt `DELETE /documents/{id}/spaces/{space_id}`; then as the space moderator or doc owner, unlink; then repeat while the site is unlicensed.
- **Expect**: plain member refused with "You cannot remove this file from the space."; moderator/owner succeeds and the document remains intact on its home drive; unlicensed unlink is gated like other document writes (NOT on the short revoke-only exemption list).
- **On fail**: unlink route missing its authority check, or incorrectly added to the licence-exemption list.

### 9. Space deletion trashes native files, leaves linked-only files alone
- **Action**: delete a Space (with 200+ native documents and several folders) in BuddyNext; check immediately, then after Action Scheduler runs.
- **Expect**: all NATIVE documents/folders on that space's drive are trashed (not permanently deleted) through the normal trash path, processed in chunks with an Action Scheduler continuation. Documents merely LINKED into the deleted space are left completely alone.
- **On fail**: space-purge listener trashing linked files it shouldn't, or not chunking.

## Pass criteria

1. Search ranking and the PDF body-text exception behave exactly as documented; new uploads show "indexing" not false empty.
2. Space search covers linked files without leaking into public search.
3. Multi-space linking succeeds independently per space, and each refusal case is distinct.
4. Unlinking is moderator/owner-only and correctly licence-gated (not exempt).
5. Space deletion trashes only native files, via the normal trash path, leaving linked files untouched.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| PDF findable by body text | extraction running on PDFs when it shouldn't | extraction pipeline mime allowlist |
| Space search leaks into public Explore search | search scope not isolated per drive/space | documents search route |
| Unlink succeeds unlicensed for an ordinary member | unlink route wrongly added to the exemption list | licence gate exemption list |
| Space deletion permanently deletes native files, or trashes linked-only files | space-purge listener using the wrong file set or the wrong delete path | space-purge Action Scheduler listener |
