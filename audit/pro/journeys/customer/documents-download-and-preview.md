---
journey: documents-download-and-preview
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber, anonymous]
covers: [MV-DOC-006, MV-DOC-007, MV-DOC-008, MV-DOC-009, documents-preview, documents-download]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "One document of each type: PDF, plain text/Markdown/CSV, Office/ODF/RTF, and an unsupported/archive type (zip)"
estimated_runtime_minutes: 6
---

# Every document tier gets the download or preview it promises — never a blank panel

**Why this journey exists**: documents have four preview tiers (inline PDF, server-rendered text, download-only card, and the plain download route itself), each with a distinct failure mode if the wrong tier's handling leaks into another. Tier 2 in particular exists specifically to avoid serving raw `.md`/`.html`/`.csv` inline, which would be stored-XSS-with-a-download-button.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>` owning one document of each type.

## Steps

### 1. Download always serves as attachment
- **Action**: `curl -i $SITE_URL/wp-json/mvs-pro/v1/documents/{id}/download` for any type.
- **Expect**: `Content-Disposition: attachment` regardless of type, so nothing executes in the site's origin. Works even on an unlicensed site (reads are never licence-gated).
- **On fail**: download route's header logic.

### 2. Denied download is a clean 404, never 403
- **Action**: request `/download` for a document you have no view access to.
- **Expect**: 404, not 403 (never confirm existence to a caller who shouldn't know it exists). Same for a trashed document, including as its own owner.

### 3. PDF preview streams inline (tier 1)
- **Action**: open a PDF document's permalink or `GET /documents/{id}/preview`.
- **Expect**: renders embedded via pdf.js, no download prompt required to view; PDF is the ONLY mime ever served inline. Back-link on the permalink reads "Documents" pointing at the documents archive, not "Explore" — confirm a photo's single page still says "Explore" (regression risk both ways).
- **On fail**: preview route's mime allowlist; permalink back-link template.

### 4. PDF preview missing-file / malformed states are clean
- **Action**: point the preview at a document whose underlying file is missing on disk; then a malformed PDF.
- **Expect**: "the file for this document is missing", never a blank panel, for both cases.

### 5. Text/Markdown/CSV renders server-side HTML (tier 2)
- **Action**: open a text/Markdown/CSV document's permalink or `/preview`.
- **Expect**: server-rendered HTML; the raw file bytes never leave the server (no raw `.md`/`.html`/`.csv` served inline).
- **On fail**: tier-2 handler bypassing server rendering.

### 6. Malformed/oversized text degrades gracefully
- **Action**: open a malformed CSV and an oversized text document.
- **Expect**: still renders a readable page, not a blank one.

### 7. Office/ODF/RTF/archive gets a download-only card (tier 3/4)
- **Action**: open a Word/Excel/PowerPoint/ODF/RTF document, then a zip.
- **Expect**: no preview attempt, no LibreOffice conversion — a card with type, size, author, and a working Download button, clearly explaining this is download-only, never looking like a broken preview.
- **On fail**: tier 3/4 fallback card template.

## Pass criteria

1. `/download` always serves `attachment`, works unlicensed, and denied/trashed both 404 (never 403).
2. PDF previews inline via pdf.js; missing/malformed files show the specific "missing" message.
3. Text/Markdown/CSV render server-side HTML only — never raw bytes inline.
4. Office/ODF/RTF/archive show the clean download-only card, never a blank/broken preview attempt.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Denied download returns 403 | existence confirmed by mistake | download route permission logic |
| Raw `.md`/`.csv` served inline | tier-2 handler skipped, mime served directly | preview route mime dispatch |
| PDF back-link says "Explore" | permalink template shared with photo single-page without a document branch | document permalink template |
| Office file attempts a broken preview instead of the card | tier-3/4 fallback missing for that mime | preview route fallback dispatch |
