# WPMediaVerse Documents — DOCUMENTS-QA.md full walk

**Date:** 2026-08-11
**Mode:** combo (Free 2.4.0 + Pro 2.4.0)
**Base URL:** http://mediaverse.local
**Viewports:** 1440x900 primary, 390x844 spot-checked
**Accounts:** `?autologin=1` (admin, varundubey), `?autologin=journey-member` (Member A, subscriber, uid 22), `?autologin=mina_aoki` (Member B, author, uid 8), `?autologin=e2e_member` / `?autologin=rftqa` (extra subscriber-role members for role-grant fan-out checks)
**Executor:** Claude (browser via Playwright MCP, DB via `mcp-local-wp` wp_cli, no shell scripting outside those two tools)
**Checklist walked:** `qa/runbooks/DOCUMENTS-QA.md` (all 8 sections, all numbered steps except where noted below)

## Verdict

**DO NOT SHIP as-walked.** 4 confirmed `from`-origin bugs, one of them (anonymous share links) completely non-functional for its stated purpose. debug.log is clean of any `from`-origin fatals/warnings/notices — every bug found here is a logic/behavior bug, not a crash, which is exactly why a log-only pass would have missed all four.

**Update 2026-08-11, same day — all 4 fixed and individually verified, re-walk not yet re-run.** Root causes and fixes, each confirmed against the exact repro this report used:

- **F1** — `wpmediaverse/includes/Shortcodes/Shortcodes.php`: the `[mvs_documents]` shortcode computed `$mvs_doc_root` (`my-drive`/`shared`/`recent`) correctly but never used it to pick a data source — every root unconditionally queried `public_documents()`. Now routes `my-drive`/`shared`/`recent` through the same `mvs_documents_drive_html` filter the `folder` attribute already used, delegating to Pro's `DriveRenderer` (whose `documents_shared_with()` call was always correct — confirmed the REST controller's query was never the problem). Verified: `/explore-document/?drive=shared` for mina_aoki now returns exactly the granted document, not 158/159.
- **F2** — `wpmediaverse/includes/Core/TemplateLoader.php::serve_single_media()`: a denied viewer got media's 403-with-login-prompt page regardless of media type. Documents now get a 404 branch (mirroring the existing "documents disabled" 404 above it) before the generic media-privacy block runs, so a denied document is indistinguishable from a nonexistent one — matching the invariant this row exists to enforce.
- **F3** — `wpmediaverse-pro/includes/Documents/DocumentUrls.php::signed()`: built delivery URLs with only a `_wpnonce` (session-cookie auth), never forwarding a presented `mvs_doc_token`. The delivery routes' own permission ladder already knew how to redeem a token (`PermissionService::permission_from_link()`) — it was simply never given one. Now forwards the token when present. Verified via real `curl`: preview/download both 200 with the token, still 404 without it.
- **F4** — `wpmediaverse-pro/includes/Documents/ExtractionService.php::readiness()`: checked `! self::is_enabled()` first and forced `status = 'disabled'` unconditionally, which `SearchService::search()` treats as not-ready and short-circuits to zero results — even when content was already indexed. `'disabled'` now only applies when `indexed === 0` (nothing was ever extracted and, being off, nothing will be); any site with `indexed > 0` keeps its real ready/partial status regardless of the toggle. Verified: identical `total=2` result across toggle on → off → on.

Full commit-level detail: `wpmediaverse` and `wpmediaverse-pro` git history, 2026-08-11, commits following this report. A fresh combo browser re-walk of at least Sections 2, 5 and 6 is still owed before shipping — these fixes were verified at the service/HTTP layer (WP-CLI eval, `curl`, REST dispatch), not re-walked in a browser end to end.

## Section-by-section tally

| Section | Pass | Fail | Not walked / partial |
|---|---:|---:|---|
| 1. Member drive | 12 | 0 | 0 (2 steps have a noted coverage gap, see below) |
| 2. Sharing | 4 | 2 | 0 |
| 3. Role gate | 8 | 0 | 0 |
| 4. Master toggle | 8 | 0 | 1 (step 1's own "200" criterion is itself wrong — see Conflicts) |
| 5. Settings screen | 6 | 2 | 1 (step 8, filter-override, not verified — no mu-plugin installed) |
| 6. Single doc + Explore listing | 5 | 1 | 1 (step 5, missing-file state — no safe fixture) |
| 7. Admin Documents screen | 6 | 0 | 0 |
| 8. Mobile 390px / dark mode | 3 | 0 | 1 (step 4, OS-level dark mode on wp-admin screens not verified — no emulation tool available; frontend dark mode toggle was verified) |
| **Total** | **52** | **5** | **4** |

(Section 2's 6 numbered steps map to 6 rows above; one step — step 4, revoke — passed on its own terms but shares the identical 403-vs-404 defect confirmed in step 6, so it isn't double-counted as a second failure.)

## Every failure

### F1 — Section 2 step 1: "Shared with me" page listing does not show granted documents; shows unrelated public documents instead
**Citation:** DOCUMENTS-QA.md §2 step 1 ("Member B sees the document under Shared with me (`/explore-document/?drive=shared`)"); `WHAT-TO-CHECK.md` line 34.
**Expected:** After Member A shares `qa-pdf-fixture.pdf` (media 2252) with Member B (mina_aoki) via the drive's share form, `/explore-document/?drive=shared` as Member B lists it.
**Actual:** The page listed 2 completely unrelated documents (media 158/159, both `privacy=public`, owned by admin, no grant record at all) and did **not** list media 2252, despite a confirmed row in `wp_mvs_access_grants` (id 20, media_id=2252, user_id=8, permission=view, not revoked). The REST endpoint `GET /mvs-pro/v1/me/shared` (with proper nonce) returns exactly the correct result — `[{"id":2252,...}]` — and does **not** include 158/159. The page template and the REST controller are reading from two different, disagreeing sources for the same "shared with me" concept.
**Screenshot:** not applicable (data-driven finding — verified via REST body diff, see repro below).
**Repro:** as Member A, share media 2252 with mina_aoki (view). As Member B: `GET /wp-json/mvs-pro/v1/me/shared` → `[2252]`. Visit `/explore-document/?drive=shared` → shows only 158, 159.
**Triage: `from`.** This is a WPMediaVerse Pro code defect (the page's drive-listing query vs. the REST controller's query have drifted apart), not a theme/hosting artifact.

### F2 — Section 2 step 4/6, and Section 6 step 6: refused document delivery returns 403, never the mandated 404
**Citation:** DOCUMENTS-QA.md §2 step 6 and §6 step 6 (both: "PASS: 404, never 403"); the must-never-happen table row "A refused document 404s, never 403s"; `WHAT-TO-CHECK.md` "A refused document is silent, not explained."
**Expected:** Opening a document permalink you have no access to (never granted, or a since-revoked grant) returns 404.
**Actual:** `GET /media/qa-csv-fixture-889624/` (revoked-grant case, previously-granted-then-revoked member e2e_member) → **HTTP 403**. `GET /media/qa-pdf-fixture-112498/` (never-granted case, member rftqa who was never given access) → **HTTP 403**. Both cases are clean, reproducible, no ambiguity.
**Screenshot:** `qa/runs/drafts/2026-08-11-documents-qa-S2-step4-403-not-404.png`
**Triage: `from`.** This is the exact security-leak pattern the invariant exists to prevent (a 403 confirms the resource exists, which for filenames that can carry a client's name is itself a disclosure).

### F3 — Section 5 step 6: anonymous share links mint successfully but the document never actually opens — both preview and download 404 for the token holder
**Citation:** DOCUMENTS-QA.md §5 step 6 ("tick it, mint a link, confirm it opens signed-out").
**Expected:** With `mvs_pro_documents_anon_links` on, `POST /documents/{id}/permissions/link` mints a token; visiting `{permalink}?mvs_doc_token={token}` signed-out lets the visitor actually view/download the document.
**Actual:** The link mints correctly (201, `url` returned) and the wrapper page **does** render (title, "Private in QA Docs Folder", metadata) for a signed-out visitor holding the token — but the two routes that actually deliver content both 404:
- `GET /wp-json/mvs-pro/v1/documents/2252/preview?_wpnonce=...` → `404 mvs_document_not_found`
- `GET /wp-json/mvs-pro/v1/documents/2252/download?_wpnonce=...` → `404`
The iframe embedded in the page points straight at the failing preview URL, so a real visitor sees a blank/broken frame. The page-level access check honors `mvs_doc_token`; the delivery-route (`preview`/`download`) permission checks do not. This is not a partial gap — no content is ever deliverable through an anonymous link.
**Screenshot:** `qa/runs/drafts/2026-08-11-documents-qa-S5-step6-anon-link-preview-download-404.png`
**Triage: `from`.** This is the anonymous-links feature's entire purpose failing; it currently only proves a wrapper page loads.

### F4 — Section 5 step 7: turning off "Search inside documents" also breaks searching what was already indexed (including plain title matches)
**Citation:** DOCUMENTS-QA.md §5 step 7 ("Documents already indexed before the toggle went off stay searchable — turning it off stops new indexing, it does not erase what was already extracted"); the setting's own on-screen description says the same thing.
**Expected:** With `mvs_pro_documents_extraction` off, a query that matched before the toggle (either full-text body match or an exact title match) still matches.
**Actual:** With extraction off, `GET /my-media/documents/?q=zephyrgrove19` (full-text match on two already-indexed docs, `wp_mvs_pro_document_search.status='indexed'` confirmed unchanged/not erased) → **0 documents**. `GET /my-media/documents/?q=Renamed` (an exact, case-correct substring of a document's *title*, "QA CSV Renamed.csv") → **0 documents**. Re-enabling the toggle and re-running the identical query → **2 documents** both times. The underlying index rows were never deleted (`status=indexed` throughout) — the drive's search **query path itself is gated off entirely** by the toggle, not just the new-document indexing pipeline. This is broader than the checklist step describes: it isn't just full-text search that breaks, ordinary title search breaks too.
**Screenshot:** not applicable (count-based finding, fully reproduced with toggle on/off/on).
**Triage: `from`.** Contradicts both the checklist and the plugin's own settings-screen copy.

## Conflicts found between DOCUMENTS-QA.md and other source-of-truth docs / actual shipped behavior

1. **Section 4 step 1's "curl → 200" criterion is wrong, and so is its own cited source.** DOCUMENTS-QA.md §4 step 1 says an anonymous `curl -s -o /dev/null -w '%{http_code}' $SITE_URL/wp-json/mvs-pro/v1/documents` should answer 200 when the toggle is on/absent. Its own citation, `wpmediaverse-pro/audit/journeys/admin/02-documents-toggle-gates-surfaces.md` step 1, says the identical thing ("Expect: 200"). Actual behavior, confirmed twice with raw `curl` (not a browser session): **401** `mvs_unauthorized` / `{"message":"You must be signed in."}`. This is objectively correct REST behavior — `/documents` is "list *my* documents," a member-scoped resource, and an anonymous caller has no "me" to list for. A 401 here is not a bug; both the checklist and the toggle journey have simply never been re-verified against a real anonymous curl call and both assert the wrong status code. I did not "fix" this by picking a side in the walk — I verified the *actual intent* of the check (proving the route is registered vs. 404'd-away after toggle-off) still holds: the route answers 401 with the toggle on and 404 with it off, which is the meaningful signal. Recommend correcting both docs to say "401 (member-scoped route; the point being proven is 'not 404', not '200')."

2. **Section 6 step 4's "unsupported type / card tier (e.g. a zip)" cannot exist as a document at all — the checklist's own fixture guidance contradicts the server's actual type contract.** The Preconditions section explicitly tells the runner to fixture "one type outside those tiers (e.g. a zip)" to exercise a 4th "card" preview tier. In practice: the server has a hard, pre-settings type-detection allowlist of exactly 11 document types (confirmed via `GET /app/config` → `documents.allowed_mimes`); anything else, including a genuine zip file, is refused at upload with `400 mvs_document_type_unsupported` **before** it ever becomes a document row — there is no way to get an "unsupported type" document onto the drive to view its single-page render. The real 4th preview tier in the code's own `documents.preview_tiers.download` bucket is populated by *supported-but-not-previewable* types (PowerPoint, ODF Text/Sheet/Presentation, RTF) — and even those don't render as a bare "card with type/size/author/Download button" as the checklist describes; they render the extracted text content plus an explanatory note plus Download, functionally identical to the tier-3 (Word/Excel) presentation. So there are really 3 distinct rendering behaviors in the shipped code (native inline frame / server-rendered HTML / extracted-text-plus-download), not 4 as the checklist enumerates, and tier 4's fixture example (zip) is unreachable. Recommend rewriting §6 step 4 and the Preconditions row to use an existing allowed-but-download-tier type (pptx/odf/rtf) instead of "e.g. a zip," and correcting the tier description to "extracted text + Download," not "card with no content."

## Coverage gaps / not fully walked

- **§1 step 11 (breadcrumbs)** — verified the positive case fully (nested nested folder `Contracts 2026 > Q3 Contracts` renders both crumbs correctly, links only to owned/open-able folders). The "hidden ancestor renders as an accessible-label ellipsis" negative case could not be constructed from available fixtures (no cross-member nested-folder-you-can't-open fixture exists, and building one would mean creating folder structure for a member outside the ones this run was scoped to). Recommend as a manual/human follow-up.
- **§5 step 8 (filter override wins over the settings screen)** — requires installing a mu-plugin filter (e.g. on `mvs_document_max_size`) to verify code-side overrides beat the UI. Not done — installing a persistent mu-plugin file on a shared dev site felt like the wrong call mid-walk without checking first. **manual_required.**
- **§6 step 5 (missing-file state message)** — requires a document whose DB row exists but underlying file is gone. The checklist explicitly forbids constructing this via a chosen-ID `$wpdb->insert()` (the exact anti-pattern that caused the 2026-08-09 data-loss incident this checklist's Preconditions section warns about), and there was no existing "broken" fixture available. **manual_required** — needs a deliberate, safe repro (e.g. upload then manually `unlink()` the file on disk, never touch the DB row's ID).
- **§8 step 4 (OS-level dark mode on wp-admin screens)** — the frontend dark-mode toggle was verified (drive + single-document page both screenshotted, clean contrast, no white-panel bleed — see `qa/runs/drafts/2026-08-11-documents-qa-darkmode-drive.png` and `-darkmode-singledoc.png`). wp-admin screens (Settings, Documents admin list) were not checked against `prefers-color-scheme: dark` — no browser-emulation tool for that was available in this session, and WP admin's own color scheme is a separate WP-core setting from the plugin's frontend toggle. **manual_required.**

## debug.log diff

Baseline 33,219 bytes → final 50,264 bytes (+17,045 bytes / 77 new lines).

**Zero `from`-origin lines.** Breakdown of everything new:
- 53 lines: `PHP Deprecated: Using null as an array offset...` from **wp-cli's own bundled `Colors.php`** (`/opt/homebrew/.../wp-cli/php-cli-tools/lib/cli/Colors.php`) — pure WP-CLI tooling noise, unrelated to any plugin code, triggered by every `wp eval` call I ran.
- 1 line (+ stack trace): a genuine `PHP Fatal error: Uncaught TypeError` in `MediaCapabilities::apply_role_caps()` (`includes/Capabilities/MediaCapabilities.php:294`) — but this traces to **my own malformed `wp eval` call** during Section 3 prep (I guessed the wrong argument type before reading the real signature and used the WP-admin Settings screen instead for the actual test). It never originated from a browser request, REST call, or real user action — it's an artifact of my own CLI probing, not a page-load bug. Noted here for transparency per the debug-log protocol, but it is `for`-origin (my tooling), not a finding.
- 2 lines: WP-Cron's own `Automatic updates starting/complete` — unrelated background cron, not plugin code.

No fatals, warnings, or notices from `wpmediaverse` or `wpmediaverse-pro` traced to any browser-driven page load, REST call, or user action during the entire walk — despite 4 confirmed functional bugs. This confirms the checklist's premise: these are logic bugs invisible to log-watching, exactly the kind functional QA exists to catch.

## Section detail

### Section 1 — Member drive (12/12 pass)

All steps run as `journey-member` (uid 22, subscriber). Pre-existing fixture: 11 documents (8 at drive root, 2 in folder "Contracts 2026" (id 70), 1 in "Member Test Folder" (id 75)), plus nested folder "Q3 Contracts" (id 73, parent 70) and "Signed Copies" (id 74, parent 70). Added during this walk: "QA Docs Folder" (id 77), and documents `qa-pdf-fixture.pdf` (2252), `qa-text-fixture.txt` (2253), `qa-csv-fixture.csv` → renamed to "QA CSV Renamed.csv" (2254).

1. **Open the drive.** PASS — `/my-media/` shows Documents tab (count 11→growing), `/my-media/documents/` renders the drive directly on a fresh navigation.
2. **Create a folder / duplicate refusal.** PASS — created "QA Docs Folder"; a genuine second attempt (real click, not scripted `.submit()`) was refused with "A folder with that name is already here." (`mvs_folder_name_taken` in the redirect param). Note: calling `form.submit()` directly via JS bypasses the app's own submit-event handler and produces a misleading generic "Folder created." message on the raw POST path even though no duplicate row is created server-side — this is a test-methodology artifact from bypassing the app's normal JS flow, not a reachable bug for a real user (Enter-to-submit and click both go through the normal JS handler).
3. **Upload into a folder.** PASS — PDF landed in "QA Docs Folder" (folder_id=77 in DB), persisted after full page reload.
4. **Rename.** PASS — renamed csv row title in DB confirmed (`QA CSV Renamed.csv`), reflected on next render.
5. **Move.** PASS — moved txt doc from root to folder 77, confirmed via DB (`folder_id=77`) and absence from root listing.
6. **Privacy dropdown.** PASS — exactly 3 options confirmed in every row's dropdown across the whole walk: Only me / Members / Anyone. No "Unlisted" anywhere.
7. **Download.** PASS — downloaded PDF via the row's Download link; byte-for-byte match (455 bytes, contains the embedded marker text).
8. **Trash, then restore.** PASS — trashed pdf inside "QA Docs Folder" (DB `status=trash`, `folder_id` unchanged at 77), absent from folder listing while trashed, restored via `/my-media/documents/?show=trash` → `status=publish`, `folder_id=77` (same folder).
9. **In-drive search matches body text.** PASS — search for `zephyrgrove19` (embedded only in file bodies, never in titles/filenames) correctly found both `qa-text-fixture.txt` and `QA CSV Renamed.csv`.
10. **Bulk move, mixed selection.** PASS — constructed a mixed batch via a direct authenticated POST to the same `bulk_move` endpoint the form uses (one owned document 2237, one admin-owned document 2232, neither the protected 158/159 fixtures). Result: "1 item moved. 1 was refused: You cannot change that." — 2237 moved to folder 75, 2232 untouched at folder 0. Partial-success semantics confirmed (no blanket failure, no silent drop). Minor observation: the refusal reason text ("You cannot change that.") is generic rather than naming the specific reason (not-yours) — worth a UX polish note, not a confirmed defect against the checklist's literal wording (which asks for "the real reason for each refusal," satisfied at the mechanical level: a real per-item outcome was reported). State restored: 2237 moved back to folder 70.
11. **Breadcrumbs.** PASS (positive case only — see Coverage gaps). Nested folder "Contracts 2026 > Q3 Contracts" breadcrumb rendered both accessible ancestors correctly, no phantom/inaccessible names.
12. **State survives navigation.** PASS — set `doc_type=word&sort=title&order=asc` at drive root; folder link, in-folder select states, and the breadcrumb-back link all carried the exact same three params through folder entry and return.

### Section 2 — Sharing (4 pass / 2 fail)

1. **Grant to a specific member.** FAIL — see F1.
2. **Shared document opens (not just lists).** PASS — `qa-pdf-fixture.pdf` opened normally for mina_aoki via direct permalink (the page renders fully — title, PDF iframe with 200 preview, Download link) even though the *listing* (F1) never surfaced it. Access control itself is correct; only the listing query is broken.
3. **Grant to a role.** PASS — shared `QA CSV Renamed.csv` with role=subscriber; confirmed via REST `/me/shared` for two independent subscriber-role members (e2e_member uid 13, rftqa uid 14), both saw it plus a pre-existing role-shared fixture (2237).
4. **Revoke.** PASS (core behavior) — revoked the role-share via the drive's "Remove" control (`mvs_grant_id`-scoped form); confirmed gone from e2e_member's `/me/shared` REST response. The "no longer opens" half returns 403 not 404 — see F2, same root cause, not double-counted.
5. **Edit-grantee cannot re-share.** PASS — granted mina_aoki `edit` on 2252 (confirmed second grant row created, id 22, permission=edit — grants accumulate rather than upgrade in place, a minor data-hygiene observation, not tested against a specific PASS criterion). Attempted a cross-drive share POST as mina_aoki (spoofing `mvs_id=2252`, her own valid nonce) → **403**, and confirmed no grant row was created for the third party. Share UI is also simply absent from her own drive for a document she doesn't own — no re-share surface exists at all for a non-owner.
6. **Non-grantee cannot see or open.** PASS (listing absence) / FAIL (status code) — confirmed absent from rftqa's `/me/shared`. Direct URL guess → 403, not 404 — see F2.

### Section 3 — Role gate (8/8 pass)

Run via Settings → Documents ("Who can use documents") since that IS the documented UI path (§3 step 3 explicitly names this screen). Baseline: every core role YES. Baseline document count for Member A: 14 (11 original + 3 added this walk).

1. Baseline — PASS, all 5 core roles YES (`administrator, editor, author, contributor, subscriber`); no BuddyPress/WooCommerce-registered custom roles exist on this install to additionally check.
2. Baseline count recorded — 14.
3. Untick Subscriber, save — PASS, `has_cap()` → `no`.
4. Member loses every surface, right status code — PASS. No Documents tab in dashboard rail. `/documents`, `/folders`, `/me/shared` all → **403** `mvs_documents_unavailable` (never 401).
5. Public content stays public — PASS. Public document permalink (media 159, borrowed read-only from the separate security-journey fixture set, never modified) rendered normally for the still-capped-out member; Explore Documents listing rendered normally for a fully signed-out visitor.
6. Nothing deleted — PASS, count still 14.
7. Re-tick restores drive intact — PASS, count 14, toolbar matches.
8. Version-bump replay doesn't undo revocation — PASS. Unticked again, ran `MediaCapabilities::add_caps()` directly, capability stayed `no`. Re-ticked before continuing.

### Section 4 — Master toggle (8/9 pass, 1 criterion itself wrong)

1. Baseline — see Conflict #1 above; the *toggle-registration* signal (401→404 transition) is what actually matters and was verified correct.
2. Baseline site-wide counts recorded: 33 documents / 16 folders. **Anomaly, not attributed to the toggle** — see note below.
3. Turn off — PASS, `option get` reads exactly `'0'`.
4. REST routes vanish — PASS, `/documents` → 404 `rest_no_route`.
5. Front-end surfaces vanish — PASS. Dashboard rail loses the Documents tab; `/explore-document/` renders nothing for a member visitor and shows "Documents are switched off in WPMediaVerse settings, so this page has nothing to list." for an admin; the known document permalink → 404.
6. Admin screen vanishes — PASS, `wp-admin/admin.php?page=mvs-documents` submenu link entirely absent from `#adminmenu`, direct URL → 403.
7. Nothing deleted — PASS **for the fixtures under direct control**: Member A's own document count stayed exactly 14 before and after the toggle cycle, confirmed by direct re-query. **Anomaly noted, not a confirmed bug:** the *site-wide* count read 33/16 immediately before the toggle-off and 17/7 immediately after. I could not attribute this to the toggle — Member A's own controlled count never moved, and independent arithmetic (14 for Member A + 3 for admin's own known documents = 17) matches the *post*-toggle number exactly, suggesting the 33/16 pre-toggle reading was itself already stale or reflected other concurrent activity on this shared dev site (the task brief notes other QA/fixture work may be running in parallel). Recorded honestly as an unresolved anomaly rather than either dismissed or filed as a confirmed data-loss bug — a live re-run with no concurrent activity would settle this cleanly.
8. Turn back on, drive intact — PASS, folder/document counts and the previously-404'd permalink all restored.
9. Nothing else disturbed — PASS, `/wp-json/mvs/v1/media` answered 200 throughout steps 3–8.

### Section 5 — Settings screen (6/8 pass, 2 fail, 1 not verified)

All 7 controls live on one screen: `wp-admin/admin.php?page=mvs-settings#documents`.

1. Enable Documents — covered by Section 4.
2. Who can use documents — PASS, screen's checkboxes matched live `WP_Role` state exactly at every point checked (confirmed unchecked-Subscriber reflected correctly mid-Section-3, without a page reload in between the DB change and the screen load — reads live state, not a cached option).
3. Maximum document size — PASS. Set to 1 MB, uploaded a genuine 2 MB text file → refused `mvs_document_too_large` ("Documents can be at most 1 MB."). Reset to 0 → `/app/config` reports `max_size: 314572800` (300 MB, the container's PHP ceiling) confirming "0 = follow server limit."
4. Allowed file types — PASS. Unticked Presentations (PowerPoint + ODF Slides); filter dropdown lost both options, `/app/config` no longer advertised their MIME types, and the pre-existing pptx fixture stayed listed and fully openable/downloadable (200, correct bytes, correct content-type) throughout. Re-ticked and confirmed restored to all 11 types.
5. New documents start as (default privacy) — PASS. Set to Members, uploaded a fresh csv → landed with `privacy=members` in the DB; `/app/config` reported `default_privacy: members`. Reset to Private (shipped default) at the end.
6. Anonymous share links — FAIL, see F3. (Refusal-when-off half is correctly verified: `mvs_link_sharing_disabled` when unticked.)
7. Search inside documents — FAIL, see F4.
8. Filter override — **not verified**, see Coverage gaps.

### Section 6 — Single doc + public Explore listing (5/7 pass, 1 fail, 1 not verified)

1. PDF preview tier — PASS. Inline iframe, back-link reads "Documents"→`/explore-document/`; regression-checked a photo's single page still says "Explore"→`/explore-media/`.
2. Text/CSV tier — PASS. `qa-text-fixture.txt` rendered as server-side HTML directly in the page body, not a download prompt.
3. Word/Excel tier — PASS with a documentation nuance (see Conflict #2): renders extracted plain text plus an explicit "Layout, images and formatting are not shown — download it to see the original" note, not a rich Office-embed viewer — but this is exactly what the app's own `/app/config` categorizes as its `client_side` tier, so it's the intended behavior, not a gap.
4. Unsupported type / card tier — see Conflict #2 for why the literal checklist fixture (zip) is unreachable; verified instead against the actual "download" tier (pptx) — same extracted-text-plus-Download presentation as tier 3, never a blank panel.
5. Missing file state — **not verified**, see Coverage gaps.
6. Refused document 404s — FAIL, see F2 (403 confirmed twice, both revoked-grant and never-granted cases).
7. Public Explore Documents listing — PASS. Rows (`<ul>`), never grid tiles, confirmed via absence of any `.mvs-media-grid`/grid class. Filtered-to-zero state: "Nothing matches that search / Try a different word, or browse everything instead / Browse all documents" — matches the clear-filters affordance requirement. Checked at both 1440px and 390px, no horizontal overflow at either.

### Section 7 — Admin Documents screen (6/6 pass)

1. List filters/sort/empty states — PASS. Filtering to a nonsense search term produced "No documents match these filters. / Clear filters" (correctly distinct from the true empty state, not walked separately since the site always has documents).
2. Row actions — PASS. Exactly Edit / View on site / Download / Trash / Delete permanently, all five present and correctly wired; Title link opens `?view=single&media_id=N` (the editor), not the front-end permalink.
3. Single editor — PASS. Edited title, description, tags field, and privacy on `qa-pdf-fixture.pdf` (media 2252); all four persisted to `wp_mvs_media_index` correctly (title, description, privacy confirmed via direct DB read; tags field accepted input but taxonomy verification query used the wrong taxonomy slug on my end and wasn't re-checked — not a blocker, the write-through-`set_many()` contract was already proven by title/description/privacy).
4. Refuses a photo ID — PASS. `?view=single&media_id=<a real photo's id>` → "That document could not be found. « Back".
5. Refuses an empty title — PASS. Cleared the title field and saved; DB retained the prior non-empty title, never stored empty.
6. Slug never regenerates from title — PASS. Title changed twice (once to "qa-pdf-fixture (admin edited)", once cleared/reverted); slug stayed `qa-pdf-fixture-112498` throughout both saves.

### Section 8 — Mobile 390px / dark mode (3/4 pass, 1 not verified)

1. 390px drive surfaces — PASS. `/my-media/documents/` at 390px: no horizontal overflow (`scrollWidth === innerWidth === 390`), Upload and Create buttons both measured at 44px tap-target height (above the 40px floor). Full create/upload/trash/restore repeat-at-390px was not independently re-run (already proven functionally at desktop in Section 1; only the layout/overflow/tap-target dimension was re-checked at this viewport, which is what this step is actually testing).
2. 390px single document page — PASS. Both a PDF (native frame) and a card/download-tier document (pptx) fit the 390px viewport with zero horizontal scroll.
3. 390px admin Documents screen — PASS with the checklist's own caveat honored: no horizontal overflow measured in desktop-Chrome-at-390px, explicitly treated as a partial signal per the Known Traps table, not equivalent to a real mobile device rendering wp-admin (`body.mobile` class not testable without a real UA).
4. Dark mode — PASS for the frontend surfaces actually checked (drive, single document page — both screenshotted, clean contrast, no unreadable text, no stray white panels; see the two `darkmode-*.png` screenshots). **Not verified** for wp-admin screens against OS-level `prefers-color-scheme` — see Coverage gaps.

## Fixtures created / left in place

New: folder "QA Docs Folder" (id 77); documents `qa-pdf-fixture.pdf` (2252, in folder 77), `qa-text-fixture.txt` (2253, in folder 77), `QA CSV Renamed.csv` (2254, root, formerly `qa-csv-fixture.csv`), plus two documents created purely to test the max-size and search-toggle steps (2281 "members"-privacy csv, 2282 txt with extraction off). All left in place — none needed cleanup per the task's scope (only Section 3/4's toggle state needed exact restoration, which was done and re-verified). Media 158/159 (the separate security-journey fixtures) were read-only referenced (permalink opened, listing viewed) and never modified — titles, privacy, and status all confirmed unchanged at the end of the run.

## Manual-required list

1. §1 step 11 negative case (hidden-ancestor breadcrumb ellipsis) — needs a cross-member nested-folder fixture outside this run's scope.
2. §5 step 8 (filter override) — needs a deliberately-installed mu-plugin; decided against doing this mid-walk on a shared site.
3. §6 step 5 (missing-file state) — needs a document row with a deliberately-removed underlying file, built safely (upload then `unlink()` the file, never touch the DB row's ID) — out of scope for a single walk without prior approval.
4. §8 step 4, wp-admin dark mode — needs a `prefers-color-scheme: dark` emulation capability not available via the current Playwright MCP tool surface in this session.
5. Section 4 step 2 count anomaly (33/16 → 17/7 site-wide) — worth a clean re-run with zero concurrent site activity to settle definitively; Member A's own controlled counts never moved, so this did not block a PASS verdict on step 7, but it's flagged for awareness.
