---
journey: document-single-page
plugin: wpmediaverse
priority: normal
roles: [subscriber, anonymous]
covers: [MV-MED-019, document-single-page, documents-master-switch]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "The documents master switch enabled — either WPMediaVerse Pro active (owns the admin toggle) or a mu-plugin doing `add_filter('mvs_documents_enabled', '__return_true')`"
  - "A public document (PDF) uploaded, media_type = 'document'"
estimated_runtime_minutes: 4
---

# A document's single page renders a preview + download, and 404s cleanly when the documents switch is off

**Free vs. Pro note**: Free ingests documents but does not render document CONTENTS — the filter that answers `mvs_document_viewer_html` with a tiered preview (browser PDF frame / server-rendered text / card) lives in Pro. On Free alone (documents switch on via a code-level filter, no Pro), a document's page always falls back to the honest name/size/download card, never a broken preview box. This journey runs both branches.

## Setup

- `$DOC_ID` / `$DOC_SLUG`: a public document.
- Confirm the switch is really on: `wp eval 'var_dump(\WPMediaVerse\Core\Plugin::documents_enabled());'` must print `true`.

## Steps

### 1. Public document renders (Pro tiered viewer, if Pro is active)
- **Action**: `playwright_navigate $SITE_URL/media/$DOC_SLUG/` logged out.
- **Expect**: HTTP 200. If Pro's `mvs_document_viewer_html` filter is attached, a preview renders inside the page (not only a bare download link) — a browser PDF frame or server-rendered content per the tier. A Download button and, for those permitted to grant it, a Share control are present.

### 2. Free-only fallback: honest card, not a broken box
- **Action**: same page with Pro INACTIVE (documents switch on only via the mu-plugin filter).
- **Expect**: `mvs_document_viewer_html` returns `''`, so the fallback `.mvs-doc-card` renders: file name, type + size (e.g. "PDF document, 240 KB"), and a Download link — never a blank or broken box.

### 3. Missing underlying file
- **Action**: `mysql_query "UPDATE wp_mvs_media_meta SET meta_value='' WHERE media_id=$DOC_ID AND meta_key='file_url'"` (or otherwise force the stored file to resolve empty); reload the page.
- **Expect**: **Check** — confirm in the browser what actually renders. The fallback card's Download link is conditioned on `'' !== $mvs_file_url` (`templates/media-single.php`), so the Download link itself correctly disappears; independently verify whether an explicit "the file for this document is missing" message is shown, since no such literal string was found in `templates/media-single.php` during this pass — the catalog's UX expectation may depend on Pro's viewer supplying that message. Note whichever is actually true; do not assume the catalog's wording without seeing it render.
- **Action**: restore the meta value afterward.

### 4. Downloads still honor the site-wide + per-item toggle
- **Action**: with `mvs_allow_downloads` off, reload.
- **Expect**: same as MV-MED-009 — the Download link on the fallback card checks `mvs_allow_downloads` AND the item's own `allow_download` meta (`$mvs_dl_allowed` in `templates/media-single.php`); it is absent when either is off, not shown-then-403.

### 5. Documents master switch OFF — the whole page 404s
- **Action**: turn the switch off (deactivate Pro, or remove the mu-plugin filter so `mvs_documents_enabled()` returns its real default `false`); reload `$SITE_URL/media/$DOC_SLUG/`.
- **Expect**: a full branded 404 for the WHOLE page — not a page that renders with a broken Download button. Per the code's own comment, this is "the same answer a Free-only site has always given." The underlying `mvs_media_index` row is untouched; switching the flag back on brings the page back immediately with no data loss.

### 6. A document never appears in the Explore grid or admin All Media as a broken tile
- **Action**: with the switch either on or off, `playwright_navigate $SITE_URL/media/` (Explore) and `wp-admin` → All Media.
- **Expect**: `$DOC_ID` never appears as a tile/row in either surface — the positive media-type predicate excludes documents from every grid (documents are ingested but Explore never lists them).

## Pass criteria

ALL of the following hold:
1. With the documents switch on: a public document's page returns 200 and renders EITHER Pro's tiered preview or Free's honest name/size/download card — never blank.
2. A missing underlying file at minimum hides the Download link (verify whether an explicit missing-file message also renders; report the actual behavior).
3. The fallback card's Download link honors both the site-wide and per-item download toggles.
4. With the documents switch off, the ENTIRE page 404s (not a half-broken render); the DB row is untouched and the page returns the moment the switch is re-enabled.
5. A document never appears as a grid tile on Explore or in admin All Media, switch on or off.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Page renders with a dead Download button while the switch is off | the `documents_enabled()` guard in `serve_single_media` was bypassed or removed | `includes/Core/TemplateLoader.php` (the `in_array($mvs_media_type, ['document','legacy_document']) && ! documents_enabled()` branch) |
| Fallback card shows nothing at all for a missing file | the `.mvs-doc-card` unconditional meta block regressed | `templates/media-single.php` |
| Download link shown despite `mvs_allow_downloads` off | `$mvs_dl_allowed` computation removed/bypassed | `templates/media-single.php` |
| A document tile appears on Explore | the media-type exclusion in the grid query regressed | `includes/Repository/MediaRepository.php::query()` |
