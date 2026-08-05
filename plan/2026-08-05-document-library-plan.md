# Document Library — implementation plan (2026-08-05)

Planning only; **no code changed yet**. Architecture + schema: `docs/architecture/specs/2026-08-05-document-library.md`.
UX diagram: https://claude.ai/code/artifact/0c324ec6-fb21-4a35-8406-4b46300cbacc
Target: **Free 2.5.0 + Pro 2.5.0, paired.** Consumer: **BuddyNext only.**

**Acceptance bar: 100% app-ready.** BuddyNext builds its own experience against this engine and
owns all Space-side semantics. MediaVerse ships a REST surface a native client can drive
completely (Part F).

This document covers what the spec does not: the **built-in file viewers**, the **real UX per
screen** (states, interactions, empty/error paths), and the **execution order with effort**.

---

## PART A — File viewers

The product requirement: *"all file viewer in built, pdf docs or general file type so things must
be useful for people."* A document library where every file is a download link is a filing
cabinet, not a product.

This forces a revision to the spec. The spec said **"forced download, never inline in v1"** —
correct as a security default, wrong as a product. The resolution is not "allow inline
everywhere"; it is **four tiers, where only one of them ever serves the raw file inline**.

### Tier 1 — Native browser (PDF)

**Already built.** `src/blocks/pdf-viewer/` renders an `<iframe>` at a signed URL and its own
header says it *"honors the same privacy gate as other media."* `SignedUrlService::serve()`
already lists `application/pdf` in its `$safe_types` whitelist and already sets `nosniff`.

Reused as-is. Two hardening items (see Security below), neither blocking.

| Format | Mechanism | Bundle cost |
|---|---|---|
| `.pdf` | `<iframe>` → signed URL → browser's native viewer | 0 |

### Tier 2 — Server-rendered (never serves the raw file)

The safest tier: PHP reads the file and emits **sanitized HTML**. The browser never receives the
original bytes as a document, so there is nothing to execute.

| Format | Render | Guard |
|---|---|---|
| `.md` | Parsedown (single-file, MIT) → `wp_kses_post()` | `setSafeMode(true)` **and** `setMarkupEscaped(true)`; raw HTML in markdown is never honoured |
| `.txt` | `<pre>` + `esc_html()` | refuse above 1 MB, offer download instead |
| `.csv` | `str_getcsv()` → `<table>`, every cell escaped | cap at 500 rows, footer says "showing first 500 of N" |

Markdown is the one people will actually notice — README-style notes rendering properly is most
of what makes a doc library feel finished. It is also the easiest place to introduce stored XSS,
which is why both Parsedown safety flags are non-negotiable, not either/or.

### Tier 3 — Client-side parse (raw bytes never navigated to)

Browsers cannot render Office formats natively. The file is fetched as an `ArrayBuffer` and
parsed **in JS**; the browser never treats it as a document, so there is no inline-execution
surface at all.

| Format | Library | Size | Loading |
|---|---|---|---|
| `.docx` | mammoth.js → HTML → sanitize | ~150 KB | lazy, on "Preview" click only |
| `.xlsx` | SheetJS → grid | ~400 KB | lazy, on "Preview" click only |

**Lazy-loading is the whole point.** ~550 KB is unacceptable on every page load and fine as a
one-time fetch when someone actually opens a spreadsheet. Nothing ships in the main bundle.

Mammoth's HTML output still passes through `wp_kses_post()`-equivalent sanitization client-side
before insertion — a `.docx` can carry embedded HTML.

### Tier 4 — No preview, and honest about it

| Formats | Why |
|---|---|
| `.doc`, `.xls`, `.ppt` (legacy) | Binary, macro-capable, no viable JS parser. Opaque bytes, download only |
| `.pptx` | No usable client-side renderer worth 400 KB |
| `.odt`, `.ods`, `.odp` | ODF renderers are heavy and immature; revisit post-v1 |
| `.rtf` | Low value, non-trivial to render safely |

These get a **metadata card**, not a broken viewer: type icon, filename, size, uploader, modified
date, and a prominent Download. An empty grey box that says "preview unavailable" is worse than
a well-designed card that never pretended.

### Explicitly rejected: Office Online / Google Docs viewers

The obvious shortcut is `view.officeapps.live.com/op/embed.aspx?src=<url>` or
`docs.google.com/gview?url=<url>`. **Both are disqualified for this product**, and it is worth
recording why so nobody re-proposes it:

1. **They require the file to be publicly reachable on the internet.** Every document in this
   library is access-controlled; making files public to render them destroys the entire
   permission model.
2. **They ship customer documents to a third party.** Contracts, invoices, IDs. For EU sites this
   is a GDPR processor relationship nobody signed up for.

A self-hosted library that silently uploads private files to Microsoft to draw them is not a
feature, it is an incident.

### Security reconciliation — what changed from the spec

The spec's blanket "always `Content-Disposition: attachment`" is replaced by **two endpoints
with different rules**:

| Endpoint | Disposition | Types allowed |
|---|---|---|
| `GET /documents/{id}/download` | **`attachment`, always, no exceptions** | every type |
| `GET /documents/{id}/preview` | `inline` | **PDF only** — plus server-rendered HTML for tier 2, which is not the file |

Inline exposure is therefore limited to exactly one format: PDF. Tier 2 never serves the file.
Tier 3 fetches bytes but never navigates to them. Tier 4 has no preview endpoint at all.

Headers on `/preview` — unchanged from the spec otherwise:

```
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; sandbox
X-Frame-Options: SAMEORIGIN          (it is framed by our own page)
Cache-Control: private, no-store
```

**Two hardening items on the existing pdf-viewer block** (pre-existing, not introduced here):

- The `<iframe>` at `src/blocks/pdf-viewer/render.php:123` has **no `sandbox` attribute**. Adding
  `sandbox` is defence-in-depth. It needs cross-browser testing — over-restrictive sandbox flags
  break some native PDF viewers — so it is a tested change, not a blind one.
- The block writes inline cosmetic styles (`style="width:100%; height:…; border:…"`), which is a
  Coding Rule 19 violation predating that rule. Move to `style.css` when the file is next touched
  (Rule 15 debt tax makes this the right moment).

---

## PART B — Real UX, screen by screen

Every screen states its empty, loading, and error paths — Coding Rule 11 forbids a bare `return`
in a render path, and the big-site checklist requires all three on every async surface.

### B1 — Drive / folder view

**Layout:** breadcrumb, toolbar, column headers, rows. Folders sort above files; the chosen sort
then applies within each group.

| Element | Behaviour |
|---|---|
| Breadcrumb | Parsed from `folders.path` — costs no query. Each segment navigates |
| Upload | Opens the existing shared-ui modal, `uploadMode: 'document'`, current folder pre-set |
| New folder | Inline row at top of list, input focused, Enter commits, Esc cancels |
| Type filter | `doc_type` facet — backed by `KEY by_type` |
| Sort | name / size / modified |
| Row click | Folder → navigate. File → single view |
| Row hover | Reveals overflow menu: Preview, Download, Share, Rename, Move, Delete |
| Multi-select | Checkbox column → bulk move / delete / download |

**States**

- **Empty (own drive):** "Nothing here yet" + Upload CTA + New folder. Never a bare table.
- **Empty (shared drive, read-only):** "No files have been added to this Space yet." No CTA — an
  upload button a member cannot use is a bug report waiting to happen.
- **Empty (filtered):** "No PDFs in this folder" + Clear filter. Distinct from a truly empty
  folder; conflating them makes people think their files vanished.
- **Loading:** skeleton rows matching final row height, so nothing shifts on arrival.
- **Error:** inline row-region message + Retry. Breadcrumb and toolbar stay usable.
- **Concurrency:** a file deleted by someone else while listed → row shows "No longer available",
  fades on next refresh. Never a 500.

**Mobile (≤ 480px):** columns collapse to name + size, secondary meta moves under the name,
overflow menu becomes a sheet. 40px minimum tap target. Verified at 390px.

### B2 — Single document view

Mirrors `media-single.php` section-for-section. Only the preview panel is new.

| Region | Source |
|---|---|
| Header row (avatar, name, date, breadcrumb) | `.mvs-media-header-row` — reused |
| Privacy gate (lock glyph + login CTA) | `.mvs-media-gate` — reused |
| **Preview panel** | **new** — tiered per Part A |
| Description | `.mvs-media-description` — reused |
| Social bar (reactions, favourite, report) | `.mvs-social-wrapper`, `object_type='document'` |
| Inline edit (name, privacy, description, **move**) | `.mvs-inline-edit` + one new field |

**Preview panel states**

- **Loading (tier 3):** "Preparing preview…" with a spinner while the library lazy-loads. Never
  a blank box.
- **Too large:** above the preview ceiling (default 25 MB, filterable) → metadata card +
  "This file is too large to preview" + Download. Do not attempt and hang.
- **Parse failure:** falls back to the tier 4 metadata card with "Preview unavailable" — the
  download must always still work. A broken preview must never block getting the file.
- **No preview available (tier 4):** metadata card, by design, no apology text.

### B3 — Quick preview overlay

From the folder list, Preview opens an overlay rather than navigating — the Drive behaviour
people expect. Reuses the existing lightbox shell in `shared-ui-frame.php`.

Arrow keys move to the previous/next **previewable** file in the folder (skipping tier 4, since
stepping into a dead panel is pointless). Esc closes. Download and Share are in the overlay
header. Focus is trapped; focus returns to the originating row on close.

### B4 — Share / permissions modal

| Section | Content |
|---|---|
| People | Avatar, name, permission dropdown (View / Comment / Edit), Remove |
| Add | User search (typeahead) or role picker, permission dropdown, Add |
| Link | **Off by default.** Toggle → Copy link, expiry picker, Revoke |
| Footer | "Anyone in <Space> can view" when inherited from a Space drive |

The link section shows the raw token **once**, at mint time, with an explicit "Copy now — you
will not see this again." The token is stored hashed; there is no way to show it later. Say so in
the UI rather than letting people discover it.

When the site setting `mvs_pro_documents_anon_links` is off, the anonymous option is **absent**,
not present-and-disabled. A disabled control invites a support ticket asking how to enable it.

### B5 — Upload

Existing modal, existing dropzone, existing previews (`generatePreviews()` already renders
filename + icon for non-image types). New: destination folder shown and changeable, per-file
progress, and per-file failure that does not abort the batch — one rejected `.exe` must not
discard nine valid PDFs.

Rejection copy names the reason: **"terms.exe — file type not supported"**, not "upload failed".

### B6 — Admin screen

List across all drives; filter by drive / owner / `doc_type` / status; sort by size and date;
storage totals via `AdminAggregatesService` (Coding Rule 16 — never raw `SUM`/`COUNT`); trash
purge; orphan-file cleanup. Admin HTML in `templates/admin/` only (Rule 4).

---

## PART C — Interlinking: one product, not two plugins

Two earlier requirements read as contradictory and are not. Pinning the line precisely, because
getting it wrong in either direction is a bug:

| Never mix | Always interlink |
|---|---|
| A document never renders as a tile in a media grid | Documents get a tab in the same dashboard as media |
| A document never enters the media lightbox | One search box returns both |
| `mvs_media_index` never holds a document row | One activity feed, one notification stream |
| No media template is edited | One upload button routes to both |
| Folder view is rows; media grid stays tiles | Same reactions, favourites, comments UI |

**Separate storage and rendering; shared navigation and social.** A member should never think
"the documents thing" — they should think "my stuff", some of which happens to be files.

### The precedent already exists

Free's `src/blocks/dashboard-view/view.js` carries `isMediaTab`, `isAlbumsTab`,
`isFavoritesTab`, `isCollectionsTab` — **and `isChallengesTab`, `isBattlesTab`,
`isTournamentsTab`, `isConnectorsTab`, which are Pro features.** Pro gamification already lives
as tabs inside the Free member dashboard. Nobody experiences Challenges as a separate plugin.
Documents follows the identical path.

Likewise `Integrations/BuddyPress/ProfileTabIntegration::add_profile_tab()` registers a profile
nav item with `slug => 'media'` via `bp_core_new_nav_item()`, and its own comment notes this
*"covers BuddyNext as well, since it exposes the same `bp_*` functions."* A `documents` nav item
is the same call with a different slug.

### Interlink points

| # | Surface | What happens | Cost |
|---|---|---|---|
| 1 | **Member dashboard** | "Documents" tab beside Media / Albums / Favourites / Collections | small |
| 2 | **Profile nav** | `documents` nav item alongside `media`, same `bp_core_new_nav_item()` path | small |
| 3 | **Search** | One query, results grouped "Media" / "Documents" — never interleaved in one grid | medium |
| 4 | **Activity feed** | Uploads and shares post to `mvs_activity` with a document verb | small |
| 5 | **Notifications** | Share / comment / mention through `NotificationService` | small |
| 6 | **Social** | Same reactions, favourites, comments — `object_type='document'` | free (Phase 6) |
| 7 | **Upload** | One button, auto-routing | ~5 JS lines |
| 8 | **Profile counts** | Stats read "142 media · 38 documents", one row | small |

### The Free-side improvement worth making now

Those Pro tab getters are **hardcoded into Free's dashboard store** — Free ships
`isChallengesTab` for a feature Free does not have. It works, but every new Pro tab grows Free's
knowledge of Pro, which is the wrong direction across the boundary (Coding Rule 10 in spirit).

Since Phase 0 already opens Free, add a proper seam:

```php
$tabs = apply_filters( 'mvs_dashboard_tabs', $tabs );
```

mirroring the `mvs_moderation_tabs` / `mvs_stats_tabs` filters Pro already uses for admin tabs.
Documents registers through it; the existing hardcoded getters stay put (Production Rule 1 — no
removal in the release that deprecates), and Pro's gamification tabs migrate onto the filter when
next touched.

This is the difference between adding a ninth hardcoded getter and leaving the pattern better
than we found it.

### Profile section — documents support

The member profile/dashboard gains:

- **Documents tab** — the folder view in miniature: recent files, quick filter by type, "Open
  drive" link to the full view. Not a second file manager.
- **Storage line** — "2.1 GB of 5 GB used" spanning media **and** documents together, because
  that is how a quota is actually experienced. Requires the quota-pool decision (open question 4).
- **Counts** — one stats row covering both.
- **Empty state** — "No documents yet" + Upload, only when the viewer owns the profile. On
  someone else's profile with nothing shared, the tab is absent rather than empty.

## PART D — Execution order

Effort is one developer, excluding QA and browser verification.

| # | Phase | Scope | Effort |
|---|---|---|---|
| 0 | **Free: `object_type` + seams** | 6 tables + widened unique keys + backfill; services take `$object_type = 'media'`; add `mvs_dashboard_tabs` filter. Ships alone, nothing observable changes | ~2.5 d |
| 1 | **Pro: engine** | Migrator v11, `DocumentTypes`, `DocumentService`, `FolderService`, `PermissionService`, delivery endpoint, drive-seam filters, WP-CLI seed, **cloud storage, presigned delivery, Site Health bucket check (Part G)** | ~9 d |
| 2 | **Pro: REST + app contract** | Full surface, schemas, `COUNT(*)` pagination, `/app/config` documents block, `/me/drives` + `/me/documents`, ETags, Application-Password-only verification (Part F) | ~4 d |
| 3 | **Viewers** | Tier 1 wire-up, tier 2 renderers, tier 3 lazy loaders, tier 4 card; tier-2 HTML also returned over REST for the app | ~4 d |
| 4 | **Admin** | List screen, filters, aggregates, purge | ~2 d |
| 5 | **Feature parity** | Reactions, comments, favourites, views/stats, tags, moderation, reports against `object_type='document'`; GDPR export/erase; scan seam `mvs_document_scan_file`; metadata stripping | ~4.5 d |
| 5b | **Text extraction + search** | Extract at upload, FULLTEXT column + index, search route (F8/F9) | ~3 d |
| 6 | **Frontend** | Folder view, single view, overlay, share modal, upload routing (~5 JS lines), `documents.css`. **Stands down under `mvs_buddynext_active`** | ~5 d |
| 7 | **Interlinking** | Dashboard tab, profile nav item, unified search, activity + notifications, profile counts (Part C) | ~3 d |
| 8 | **Space drives** | Resolver logic; BN implements the bridge | ~2 d |

**Order changed 2026-08-05: the app contract now precedes the frontend.** BuddyNext builds its
own experience, so the REST surface is the product and MediaVerse's own templates are the
standalone fallback for non-BN sites. Phases 2–3 are what BN actually consumes; phase 6 is what a
site without BN falls back to.

This does **not** let the frontend be dropped — Coding Rule 18 requires all three entry points,
and a table shipped without member-facing UI is the "half-cooked feature" the rule names. It
changes the order, not the scope.

**Phase 0 ships first and alone.** It is the only irreversible-feeling step (schema on 50+ live
sites), it changes nothing observable, and getting it in early means every later phase builds on
settled ground.

**Nothing member-visible ships until Phase 4.** Rule 18 requires all three entry points; phases
1–3 are deliberately incomplete and must not be tagged as a release on their own.

---

## PART E — Verification (blocking)

Beyond the spec's list:

- **Every tier-4 format shows the metadata card**, not a broken viewer, across all six.
- **Preview failure never blocks download** — corrupt a `.docx`, confirm the card + working
  download.
- **Markdown XSS**: an `.md` containing `<script>`, `<img onerror>`, and a `javascript:` link
  renders inert. This is the single highest-risk item in Part A.
- **CSV with 50,000 rows** renders the first 500 with an honest footer, does not hang.
- **`/preview` refuses every non-PDF raw type** — request it for `.docx`, `.exe`, `.doc`; all
  must fail, not stream.
- **Lazy bundles are absent from the main page load** — verified in the network panel, not by
  reading the build config.
- **390px browser verification of every screen in Part B**, per-item, not batched.

---

## PART F — App-readiness contract

**This is the acceptance bar.** BuddyNext builds its own experience; MediaVerse ships an engine a
native client can drive completely. A document feature that only works through a PHP template is
a failed deliverable, not a partial one.

Coding Rule 18 already states it: *"every member-facing feature must be fully drivable through
REST alone — complete CRUD, auth that works outside the cookie/nonce browser context, consistent
response shapes, honest pagination. A feature that only works through a PHP template or
admin-ajax is app-blocking."*

### What already exists (reuse, do not rebuild)

| Surface | Status |
|---|---|
| `GET /mvs/v1/app/config` | App discovery endpoint — documents must advertise here |
| `POST /mvs/v1/auth/app-password` | Application Password issuance |
| `Auth/AppConnect.php` | Decides which door owns the site. **When BuddyNext is active it points the app at BN's bridge** and registers our scheme in BN's allowlist |
| `Auth/AppAuthorizeAccess.php` | Repairs core's authorize screen for standalone sites |
| `/mvs/v1/me/*` family | 14 endpoints — `media`, `favorites`, `notifications`, `profile`, `stats`, `grants`… |
| `POST /mvs-pro/v1/push/register-device` | Expo push token registry |

**Auth needs no new work.** The credential is a core Application Password, the door is already
chosen by `AppConnect`, and BN already owns that door on BN sites. Documents inherit all of it.

### What documents must add

| Requirement | Detail |
|---|---|
| **Capability advertisement** | `/app/config` gains a `documents` block: enabled flag, allowed types, max size, preview tiers, anonymous-link policy. **The app must never hardcode the format list** — it reads what the site allows |
| **`/me/` family members** | `GET /me/drives`, `GET /me/documents` — matching the shape of `/me/media` exactly, not a new convention |
| **Complete CRUD** | Every action in Part B reachable over REST: create/rename/move/trash folders and documents, grant/revoke permissions, mint links |
| **Honest pagination** | `X-WP-Total` / `X-WP-TotalPages` from a real `COUNT(*)` on every list route |
| **Consistent errors** | `mvs_document_*` codes with proper HTTP status; never a 200 carrying a failure (Coding Rule 20) |
| **No cookie/nonce dependency** | Every route works with an Application Password and nothing else. This is the single most common way a feature ships app-blocked |
| **Upload from app** | Multipart `POST /documents` with `doc_type`. Large-file behaviour needs a decision — see below |
| **Push on share** | Permission grant fires a notification reaching the device via the existing registry |
| **Preview over REST** | Tier 2 returns rendered HTML as JSON, so the app renders it in a webview without a second auth hop. Tier 1/3 return a signed URL the app opens directly |

### Two app-specific gaps worth naming now

**Large uploads — the ceiling is the host's, not ours.** No plugin can accept more than
`upload_max_filesize` / `post_max_size` / nginx `client_max_body_size` allow, so MediaVerse must
not invent a limit. The existing pattern is already correct and documents reuse it verbatim:
`FieldRenderer.php:61` reads `wp_max_upload_size()` as `$server_max`, and `UploadService` enforces
the site owner's `mvs_max_upload_size` option on top.

So: `mvs_document_max_size` option, **effective value = `min( option, wp_max_upload_size() )`**,
and that effective number is what `/app/config` reports. The app then rejects an oversized file
locally with a real message instead of uploading for two minutes into a `413`.

Chunked/resumable upload is the only technique that gets *past* a host limit, since each chunk
sits under it. Out of scope for v1 — noted here so the endpoint is shaped to allow adding it
later without breaking the contract.

**List caching.** The app will poll folder listings. `ETag` / `If-None-Match` on list routes lets
it get a cheap `304` instead of re-downloading 200 rows. Small to add now, awkward to retrofit.

### App-readiness verification (blocking)

Run against a real device or a clean HTTP client — **not** a logged-in browser, because a browser
session hides every cookie dependency:

- Drive every Part B action end-to-end using **only** an Application Password: no cookies, no
  nonce, no `X-WP-Nonce` header
- Upload, preview, download, share, revoke, move, delete — all over REST
- `/app/config` reports the documents block correctly with the feature both on and off
- Every list route returns correct `X-WP-Total` against a seeded 2000-document drive
- A permission grant produces a push notification on a registered device
- Every error path returns a non-200 status with a `mvs_document_*` code

## PART G — Cloud storage for documents

MediaVerse already has cloud storage (BunnyCDN, R2, `Services\CloudOps`, three WP-CLI migration
subcommands). Documents should use it. **There is one rule in the way, and it exists for a good
reason.**

### The conflict

`StorageService::get_driver_for_privacy()` is a single line:

```php
return 'public' === $privacy ? $this->get_driver() : $this->get_local_driver();
```

Only **public** media is cloud-eligible; everything else is forced to local disk. The 1.4.0 notes
record why: *"private/restricted uploads + thumbnails + variants never leave local disk"*, and
`is_cloud_hosted_url()` declines raw R2 hosts because they are *"never public."*

The reason is sound. A cloud driver's `url()` returns a **publicly fetchable CDN URL**. Put a
private file behind one and the privacy gate is bypassed — anyone with the URL has the file.

**Documents default to `private`.** So under the current rule, documents would never use cloud at
all, and the requirement fails silently.

### The resolution: separate *where it is stored* from *how it is delivered*

Those two are coupled today because `url()` is the only delivery path. For documents they split:

| Document privacy | Stored | Delivered |
|---|---|---|
| `public` | cloud (if configured) | direct CDN URL via `url()` — same as media today |
| everything else | **cloud (if configured)** | **presigned short-TTL URL**, or gated stream where the driver can't sign. `url()` is never called |

**Presigned delivery is in scope for v1** (owner decision, 2026-08-05). R2 supports presigned
URLs and BunnyCDN has token authentication, so a private document can be served straight from the
edge through a short-lived unguessable URL instead of being proxied through PHP.

The fallback path still exists and still matters: `StorageDriverInterface::download( $path,
$local_dest )` was added in 1.3.0 for CloudOps, so any driver that cannot sign is served by
pulling bytes to the origin and streaming them under the permission check. **No plain cloud URL
is ever emitted for a non-public document on either path.**

### Adding signing without breaking existing drivers

`StorageDriverInterface` is a **published contract** — third-party drivers implement it. Adding
an abstract method to it would fatal every one of them on upgrade, which Production Rules 1 and 2
exist to prevent.

So signing goes in a **separate, optional interface** that drivers opt into:

```php
interface SignedDeliveryInterface {
    /** @return string|null Null when this driver cannot sign this path. */
    public function signed_url( string $path, int $ttl ): ?string;
}
```

The delivery layer does an `instanceof` check and falls back to streaming. `LocalDriver` does not
implement it (local files are already served by the gated endpoint); the cloud drivers do. Nothing
existing breaks, and third-party drivers keep working untouched.

### The trade-off presigned URLs carry

This is worth being explicit about, because it moves away from a position MediaVerse took
deliberately in 1.4.0: *"non-public `/serve` re-checks `can_view` per request — closes the
signed-URL-as-bearer-token gap for private media."*

**A presigned cloud URL cannot re-check anything.** Permission is evaluated once, at mint time,
and the URL is a bearer token until it expires — revoke someone's access and their unexpired URL
still works.

| | Permission checked | CDN offload |
|---|---|---|
| Gated stream through PHP | **every request** | none |
| Presigned cloud URL | **once, at mint** | full |

Mitigations, all of which the delivery layer must implement:

- **Short TTL** — default 300 s, filterable via `mvs_document_signed_url_ttl`. Long enough to
  start a download, short enough that a leaked URL is worthless.
- **Minted per request**, never cached or reused across users.
- **Never logged** — the URL is a credential; it must not reach `mvs_error_log` or access logs
  the site owner shares.
- **Site setting to force streaming** for owners who want per-request revocation over CDN
  performance. Documents are exactly the content type where some customers will choose
  correctness over speed, and that should be their call, not ours.

This needs a document-specific driver resolver — deliberately *not* privacy-gated, because the
gate now lives in the delivery layer instead:

```php
// Documents: driver by configuration, not by privacy.
// Privacy is enforced at delivery, where url() is never reachable
// for a non-public document.
public function get_driver_for_document( int $document_id ): StorageDriverInterface
```

**Media behaviour is untouched.** `get_driver_for_privacy()` keeps its current semantics; this is
an additional method, not a changed one (Production Rules 1 and 3).

### Remaining cost: the streaming fallback

Where a driver cannot sign, delivery falls back to cloud → origin → client, so the site pays
bandwidth twice and gets no CDN offload. Acceptable, and unchanged from what private media costs
through `/serve` today.

`download()` also writes to a local path first, so a 100 MB fallback download costs a disk write
plus a read — the interface has no streaming variant. v1 unlinks the temp file after the response;
a `stream()` method can join `SignedDeliveryInterface` later if the fallback path ever gets hot.

With presigned delivery in v1, this path only runs on drivers that don't support signing, so it
is the exception rather than the norm.

### The operational trap: bucket visibility

The local driver is protected by an unguessable path plus `.htaccess`/`web.config` deny rules.
**On cloud, the equivalent protection is a private bucket** — and that is site-owner
configuration MediaVerse does not control.

If someone points documents at a **public-read** bucket, every document is world-readable
regardless of the permission model, and nothing in the plugin would notice.

So: a **Site Health check** that verifies the configured document bucket is not public-read, and
a settings-screen warning when it is. There is precedent — 1.7.0 added the
`wpmediaverse_video_posters` Site Health test via `HealthCheckService` for exactly this kind of
"the environment is misconfigured and the feature silently misbehaves" case.

This is not optional polish. It is the difference between "documents are private" being true and
being merely intended.

### Work this adds

| Item | Effort |
|---|---|
| `get_driver_for_document()` + delivery-layer gate | ~0.5 d |
| `SignedDeliveryInterface` + R2 / BunnyCDN implementations | ~1.5 d |
| Streaming fallback via `download()` + temp-file cleanup | ~1 d |
| Force-streaming site setting + TTL filter | ~0.25 d |
| Site Health bucket-visibility check + settings warning | ~0.5 d |
| WP-CLI: migrate documents between drivers (mirror `migrate-storage`) | ~0.5 d |

Folded into Phase 1 (engine), raising it from ~5 d to ~9 d.

## PART H — Media feature catalogue → document decisions

Cloud was missed in the first three drafts because the plan was assembled feature-by-feature as
things came up, instead of from a complete inventory. This part is that inventory: **every media
capability in the manifests**, with an explicit decision for documents. Nothing gets omitted by
accident again — only on purpose, in writing.

Source: `audit/manifests/manifest.json` (Free v2.3.0 — 115 REST routes, 41 settings, 20 WP-CLI
subcommands, 4 cron jobs, 10 capabilities) and the Pro mirror.

### H1 — File lifecycle

| # | Media capability | Document decision |
|---|---|---|
| 1 | Upload (multipart) | **Yes** — `POST /documents`, `doc_type` required |
| 2 | **Replace file** (`/media/{id}/replace`) | **Yes** — this *is* the explicit replace that makes "no versioning" safe. Same-name upload creates a new row; replacing is a deliberate act on an existing document |
| 3 | Delete / trash | **Yes** — soft trash + purge |
| 4 | **Bulk operations** (`/media/bulk`) | **Yes** — bulk move / delete / download. A drive without bulk actions is unusable at 200 files |
| 5 | **Duplicate detection** (`file_hash`, `mvs_duplicate_action`) | **Yes** — reuse the pattern; warn on identical hash in the same drive |
| 6 | Filename strategy (`mvs_filename_strategy`) | **Yes** — reuse |
| 7 | **Metadata stripping** (`mvs_strip_exif`) | **Yes — and this is more important for documents than for photos.** See H9 |
| 8 | Max upload size | **Yes** — `min( option, wp_max_upload_size() )` |

### H2 — Storage (the part that was missed)

| # | Media capability | Document decision |
|---|---|---|
| 9 | Storage driver local / cloud (`mvs_storage_driver`) | **Yes** — Part G |
| 10 | Signed URL TTL (`mvs_signed_url_ttl`) | **Yes** — plus `mvs_document_signed_url_ttl` |
| 11 | `wp mvs migrate-storage` | **Yes** — document equivalent |
| 12 | `wp mvs cleanup-local` / `relocalize-private` / `repair-storage` | **Yes** — all three have document equivalents |
| 13 | `wp mvs cloud-thumbs-backfill` | **N/A** — no thumbnail pipeline (see H10) |
| 14 | Orphan file cleanup cron (`mvs_cleanup_media_files`) | **Yes** — same cron, document sweep |

### H3 — Delivery and rendering

| # | Media capability | Document decision |
|---|---|---|
| 15 | Signed-URL delivery | **Yes** — Part G |
| 16 | Download + `mvs_allow_downloads` | **Yes** — but a document library where the owner can disable download is close to pointless; default on |
| 17 | Thumbnails / WebP / AVIF variants | **N/A for the file** — but see H10 |
| 18 | Image optimization pipeline | **N/A** |
| 19 | Video poster generation | **N/A** |
| 20 | Watermarking | **No** — PDF watermarking is a different problem; out of scope |
| 21 | Lightbox | **Replaced** by the preview overlay (B3) |

### H4 — Organization

| # | Media capability | Document decision |
|---|---|---|
| 22 | Albums | **Yes → folders**, plus hierarchy |
| 23 | **Collections with rules** (`/collections/{id}/rules`) | **Deferred, deliberately.** Rule-based smart folders ("everything tagged invoice, PDF, this year") are genuinely useful for documents — but they are a feature on top of a working drive, not part of one. Post-v1 |
| 24 | Tags + `/tags/cloud` + `/tags/merge` | **Yes** — WP taxonomy, register for documents |
| 25 | Categories | **Yes** — same |
| 26 | Reorder (`/albums/{id}/reorder`) | **No** — folders sort by name/size/date. Manual ordering in a filesystem is a misfeature |
| 27 | Album cover (`/albums/{id}/cover`) | **No** — folders show a type icon; a "cover image" for a folder of invoices is noise |

### H5 — Social

| # | Media capability | Document decision |
|---|---|---|
| 28 | Reactions | **Yes** — `object_type='document'` |
| 29 | Comments + `mvs_comment_edit_window` | **Yes** — WP comments, second `comment_type` |
| 30 | Favorites | **Yes** |
| 31 | Social share (`/media/{id}/share`) | **Yes** for public documents; suppressed for non-public |
| 32 | Mentions | **Yes** — inside comments |
| 33 | Follows | **N/A** — user-level, already inherited |
| 34 | Activity feed | **Yes** — upload/share verbs |
| 35 | Notifications | **Yes** |

### H6 — Access, privacy, compliance

| # | Media capability | Document decision |
|---|---|---|
| 36 | Privacy levels | **Yes** — same vocabulary, `space` replaces `group`, default `private` |
| 37 | Access **rules** (`/media/{id}/rules`, price/currency) | **Deferred** — `object_type` added so paid documents stay possible; not wired in v1 |
| 38 | Access **grants** (`/media/{id}/grant`) | **Separate system** — document permissions are their own table (manifest Finding 4) |
| 39 | Group assignment (`/media/{id}/group`) | **Yes → Space**, via the drive seam |
| 40 | Custom access (user id list) | **Yes** — subsumed by permissions |
| 41 | Capabilities (10 `*_mvs_media` caps) | **Yes** — a parallel document set, not reuse. `delete_others_mvs_media` must not grant document deletion |
| 42 | **GDPR export / erase** (`Privacy\MemberDataMap`, `MemberPurger`) | **Yes — mandatory, not optional.** A document library is precisely where a member's personal data lives. Export must include their documents; erase must remove them |
| 43 | Community privacy gate (`mvs_rest_require_auth`) | **Yes** — Pro registers `mvs-pro/v1` via `mvs_rest_gated_route_prefixes` |

### H7 — Moderation and analytics

| # | Media capability | Document decision |
|---|---|---|
| 44 | Moderation queue + status | **Yes** — `moderation_status` column |
| 45 | Reports (`/media/{id}/report`) | **Yes** — `mvs_reports` is already polymorphic, zero schema work |
| 46 | Auto-hide threshold | **Yes** — reuse |
| 47 | Views + retention + `wp mvs prune-views` | **Yes** — views *and* downloads tracked separately; a download is the meaningful metric for a file |
| 48 | Stats (`/media/{id}/stats`) | **Yes** |
| 49 | Pro per-media analytics | **Optional**, post-v1 |

### H8 — AI, integration, ops

| # | Media capability | Document decision |
|---|---|---|
| 50 | AI auto-describe / auto-tag | **Post-v1, high value.** Extracted text (H11) makes auto-summary and auto-tagging far better for documents than image captioning ever was for photos |
| 51 | AI moderation | **Post-v1** — text moderation is cheap and accurate once text is extracted |
| 52 | Webhooks | **Yes** — document events |
| 53 | BuddyPress / BN activity bridge | **Yes** |
| 54 | Blocks | **`document-list` block**; `pdf-viewer` reused |
| 55 | Shortcodes | **Yes** — one document-list shortcode |
| 56 | `wp mvs reindex` / `cache-flush` / `cert` | **Yes** — all three |
| 57 | Telemetry | Inherited, no work |

---

### What a *file* needs that media never did

The catalogue above is "what media has." This is the other half of the question — the
requirements that only appear once the content type is a document. **These are not in the media
codebase at all**, so none of them can be inherited.

| # | Requirement | Why it has no media equivalent | v1? |
|---|---|---|---|
| **F1** | **Folder hierarchy** | Albums are flat; a filesystem is not | **Yes** |
| **F2** | **Move between folders** | Media has no "move" — an item's album is set at add-time | **Yes** |
| **F3** | **Breadcrumbs / path** | Nothing to traverse in a flat model | **Yes** |
| **F4** | **Permission *levels*** (view / comment / edit) | Media access is effectively binary | **Yes** |
| **F5** | **Link sharing with revocable tokens** | Media has signed URLs, but no shareable, revocable, per-recipient grant | **Yes** |
| **F6** | **Preview tiers** | Browsers render images natively; documents do not | **Yes** (Part A) |
| **F7** | **Virus / malware scanning** | An image is low-risk; `.doc` macros and malicious PDFs are not. **MediaVerse should not bundle a scanner, but must expose the seam**: `mvs_document_scan_file` filter, returning `WP_Error` to reject, so owners can wire ClamAV or an API. Shipping a document library with no scan hook at all is the gap a security reviewer will find first | **Yes — seam only** |
| **F8** | **Text extraction** | No analogue. Prerequisite for F9 and for AI | **Yes** |
| **F9** | **Full-text search inside files** | Media search covers title/description/tags — the *content* is pixels. For documents the content is the point. A doc library you cannot search inside is a folder on a server | **Yes** |
| **F10** | Page count / word count metadata | Meaningless for media | Nice-to-have |
| **F11** | Checkout / edit locking | Media is never collaboratively edited in place | **No** — out of scope |

**F7 and F9 are the two that change the product**, and both were absent from every earlier draft.

**On F9 (search).** Extract text at upload, store it in a dedicated column, and add a FULLTEXT
index — the same move 1.3.0 already made for media search ("FULLTEXT search" in the 100k-readiness
work). Extraction: PDF via a text layer parse, `.docx`/`.xlsx` by reading the XML inside the zip
(no library needed — they are zip containers, which H-section work already has to open for type
verification), `.md`/`.txt`/`.csv` directly. Legacy binary formats get no extraction and are
searchable by filename only, which the UI should say rather than silently return nothing.

**On H1 #7 (metadata stripping).** `mvs_strip_exif` strips camera data from photos. Documents
carry worse: `.docx` and `.xlsx` embed **author names, company, tracked changes, and deleted
comments**; PDFs carry author, producer, and often the full editing history. A member uploading
an invoice can leak their accountant's name, and a shared contract can carry the other side's
internal comments. **Offer the same stripping for documents, default on**, with the caveat stated
plainly in the setting: stripping rewrites the file, so the stored copy is not byte-identical to
what was uploaded. That trade-off is the site owner's to make, which is why it is a setting and
not a hard-coded behaviour.

## Resolved and delegated

**Resolved — quota: shared pool.** `QuotaService::get_usage()` reads `_mvs_storage_used` user
meta (a running counter, not a SUM), so documents increment the same counter and storage is
genuinely shared. The profile storage line ("2.1 GB of 5 GB") works across both types.

One detail this exposes: usage also tracks `image_count` / `video_count` / `audio_count`, and
`deduct_credit()` is keyed by media type. **Recommendation: documents consume storage only, no
per-type credit in v1.** Adding a `document_count` would mean a schema change to
`mvs_quota_packages` for a limit nobody has asked for; the storage ceiling already caps abuse. If
per-document limits are wanted later, add the column then.

**Delegated to BuddyNext — no longer blocking MediaVerse.** Space semantics are answered inside
BN's bridge against the `mvs_document_drive_*` filters. MediaVerse holds an opaque id and asks;
it does not need to know the answers to build:

1. Auto-drive per Space vs owner opt-in; whether `secret` Spaces get drives; child-Space inheritance
2. Whether `moderator` implies `edit`; whether a plain `member` may upload

MediaVerse's only obligation is that **the filter contract can express whatever BN decides** —
which is why `mvs_document_drive_access` takes `($owner_type, $owner_id, $user_id)` and returns a
permission, rather than a boolean.

## Still open (MediaVerse-side, non-blocking)

Both have safe defaults; neither holds up Phase 0.

1. **Site-wide library upload rights** — admins only, or any member holding a capability?
   *Default if unanswered:* `mvs_manage_documents` required, i.e. admins only. Widening later is
   additive; narrowing later takes something away from people who had it.
2. **Anonymous link TTL cap** — beyond the site-level on/off switch, should there be a maximum
   expiry a member can choose? *Default if unanswered:* 30 days, filterable via
   `mvs_document_link_max_ttl`. A never-expiring public link to a private document is the kind of
   default that turns into a support incident.
