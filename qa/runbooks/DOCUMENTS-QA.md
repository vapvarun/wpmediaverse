# WPMediaVerse Document Library — QA Checklist

Standalone runbook for the document library (folders, drive, sharing, role gate, settings,
single-document viewer, admin screen). Documents are a **Pro-only** feature — this checklist
assumes Free + Pro both active on matching versions ("combo" mode). Walk it end to end; you should
not need to open any other file to execute it.

Every row below is cited back to the release-gate runbook (`AGENT_SMOKE_RUNBOOK.md`, rows
`C.*documents*` / `D.documents*` / `D.document-never-in-media-surface`), the flat inventory
(`qa/inventory/WHAT-TO-CHECK.md`), the two Pro admin journeys, and the security journey. If a
citation and the live product disagree, the citation is what QA is holding the product to — file it,
don't silently accept the drift.

## How to run this

- **Base URL**: whatever Local site is currently running the Free + Pro pair (confirmed
  `http://mediaverse.local` as of the 2026-08-11 combo run; verify against your own environment
  before starting).
- **Auto-login**: `?autologin=1` logs in as admin (user 1). `?autologin=<username>` logs in as a
  specific member. **Never fill the login form by hand.**
- **Verify every member-facing behaviour as a MEMBER, never as admin.** Admin passes capability and
  privacy guards a member does not — an admin session will make a broken role gate, a broken
  sharing check, or a broken privacy filter look fine. Use a real subscriber-role account for every
  step in Sections 1, 2, 3 and 6 unless the step explicitly says "as admin".
- **Documents require combo mode.** Pro must be active on top of Free, and `MVS_PRO_VERSION` must
  equal `MVS_VERSION` (Pro fatals against a mismatched Free). If Pro is not active, every Documents
  surface should be simply absent — that is out of scope for this checklist, which assumes combo is
  already confirmed.
- Enable `WP_DEBUG` / `WP_DEBUG_LOG` for the session and watch `wp-content/debug.log`. A `Fatal
  error:` / `Warning:` / `Notice:` line that traces to `wpmediaverse` or `wpmediaverse-pro` code
  during any step below is always a blocker, regardless of whether the step's own PASS criterion
  otherwise held.

## Preconditions / fixtures

Set these up before starting. Do not seed by direct `$wpdb->insert()` at a chosen ID — on a
populated `mvs_media_index` table an inserted document can land on a real photo's row and corrupt
it; upload through the real UI or `DocumentIngestService`, letting `AUTO_INCREMENT` assign the ID.

| Fixture | Why it's needed |
|---|---|
| A subscriber-role member ("Member A") with a document drive | Every drive, sharing-grantor and role-gate step runs as this user, never as admin. |
| At least one folder in Member A's drive, containing at least one document | Covers folder-scoped listing, breadcrumbs, rename/move, in-drive search inside a folder. |
| Documents of several types: one PDF, one plain-text or CSV, one Word or Excel file, and one type outside those tiers (e.g. a zip) | Exercises all four preview tiers in Section 6. |
| A second subscriber-role member ("Member B") | Sharing target, and the "grantee cannot re-share" and "non-grantee cannot open" checks. |
| A logged-out browser session (private window) | Used for the public-document and role-gate visitor checks. |
| WP-CLI access | Needed for the role-gate and toggle steps (checking `use_mvs_documents`, `mvs_pro_documents_enabled`, row counts before/after). |
| Admin (`?autologin=1`) access | Settings screen, admin Documents screen, master toggle. |

---

## Section 1 — Member drive

Run every step as Member A. Citations: `C.member.documents-drive` (AGENT_SMOKE_RUNBOOK.md),
`WHAT-TO-CHECK.md` document-surface rows (lines ~31-37), `WHAT-TO-CHECK.md` §6 Documents URL sweep.

1. **Open the drive.** `/my-media/` shows a Documents section; open it, then confirm
   `/my-media/documents/` opens the drive directly.
   **PASS**: the Documents entry is present in the library rail and both URLs render the drive.

2. **Create a folder.** Create a folder with a chosen name; then try to create a second folder with
   the exact same name.
   **PASS**: the first folder is created and appears in the drive. The duplicate attempt is refused
   **by name** (an error naming the collision, e.g. "a folder with this name already exists") — not
   a generic failure message.

3. **Upload into a folder.** Open the folder from step 2 and upload a document while inside it.
   **PASS**: the document lands **in that folder**, not at drive root. Refresh and re-open the
   folder — the document is still there.

4. **Rename.** Rename a document's row (not the underlying file).
   **PASS**: the new name displays everywhere the document is listed (drive, breadcrumb if the
   drive shows a "current item" label, admin screen).

5. **Move.** Move a document from one folder to another (or to drive root).
   **PASS**: the document appears only in the destination location; opening the source folder no
   longer shows it.

6. **Privacy dropdown.** Open the per-row privacy control.
   **PASS**: it offers exactly three options — Only me / Members / Anyone. If it offers a fourth
   option called "Unlisted", that is a regression — see the "must never happen" table, row
   `D.documents-privacy-vocabulary`.

7. **Download.** Download a document from the drive row action.
   **PASS**: the file downloads and its contents match what was uploaded.

8. **Trash, then restore.** Trash a document that lives inside a folder, confirm it leaves the
   folder's listing, then restore it.
   **PASS**: after restore, the document is back **in the same folder** it was trashed from, not at
   drive root.

9. **In-drive search matches on text, not only title.** Search the drive for a word that appears
   inside a document's body (e.g. inside the PDF or text fixture) but not in its title or filename.
   **PASS**: the document is found. If search only matches titles, that is a defect — the drive's
   search is documented to search extracted text (see `mvs_pro_document_search` in
   `WHAT-TO-CHECK.md` §4, and the "un-indexed search" trap in the Known Traps table below).

10. **Bulk move with a mixed selection.** Multi-select several documents, including at least one you
    expect to succeed and — if you can construct one (e.g. a document you don't own, if visible) —
    one you expect to be refused.
    **PASS**: the operation reports **the real reason for each refusal**, not a blanket failure. A
    bulk action that either silently drops refused rows or refuses the whole batch on one bad row is
    a defect.

11. **Breadcrumbs.** Open a nested folder (folder inside a folder, if your fixture supports it; a
    single folder is the minimum).
    **PASS**: the breadcrumb trail names only folders you can actually open — never an ancestor
    folder you don't have permission to see. Check the underlying JSON response, not only the
    rendered crumb text; a hidden ancestor should render as an ellipsis with an accessible label,
    never a name or a link (`WHAT-TO-CHECK.md` "Breadcrumbs must never name an ancestor the viewer
    cannot open").

12. **State survives navigation.** Set a filter (type or privacy), a sort order, and a view choice
    (if the drive has one) at drive root. Open a folder, page through results if there are enough
    documents, then follow a breadcrumb back.
    **PASS**: the filter, sort, and view choice you set are still applied after opening a folder,
    paging, and returning via breadcrumb — they do not silently reset.

---

## Section 2 — Sharing

Run as Member A (grantor) and Member B (grantee) together. Citations:
`C.member.documents-privacy-and-sharing`, Pro CLAUDE.md 2026-08-10 changelog entry on sharing.

1. **Grant to a specific member.** As Member A, share a document directly with Member B.
   **PASS**: Member B sees the document under **Shared with me** (`/explore-document/?drive=shared`
   per `WHAT-TO-CHECK.md`).

2. **The shared document opens, not just lists.** As Member B, click into the shared document from
   the Shared with me list.
   **PASS**: it **opens** and renders normally. A document that appears in the Shared with me list
   but returns a 403 when opened is a known real bug class from 2.4.0 development — confirm it does
   not recur.

3. **Grant to a role.** As Member A, share a document with an entire role (e.g. Subscriber) rather
   than a named member.
   **PASS**: every member holding that role — not only Member B — sees the document under Shared
   with me.

4. **Revoke.** As Member A, revoke the grant made in step 1 or 3.
   **PASS**: the document disappears from the affected member's Shared with me list and no longer
   opens for them.

5. **A grantee with edit rights cannot re-share.** Grant Member B `edit` access (not just view) to a
   document, then as Member B try to share that same document with a third party (or check whether
   a share control is exposed at all to a non-owner).
   **PASS**: Member B can edit the document if the grant allows it, but cannot hand sharing on to
   anyone else. Sharing rights are the owner's alone — an edit grant is not a re-share grant.

6. **A non-grantee cannot see or open it.** As a third member (or a fresh account) with no grant on
   the document, confirm it does not appear in any of their listings and returns a not-found result
   if the URL is guessed.
   **PASS**: absent from listings; opening the direct URL 404s (see the "must never happen" table —
   refusals are 404, never 403, for delivery routes).

---

## Section 3 — The role gate (`use_mvs_documents`)

Run primarily as Member A, with WP-CLI checks as admin/shell. Citations:
`C.member.documents-role-gate`, `D.documents-role-gate-hides-not-deletes`,
`wpmediaverse-pro/audit/journeys/admin/03-documents-settings-and-role-gate.md` steps 1-8.

1. **Baseline: every role has the capability.** `wp eval 'foreach (array_keys(wp_roles()->get_names()) as $r) { $o = get_role($r); echo str_pad($r, 24), $o->has_cap("use_mvs_documents") ? "YES" : "no", "\n"; }'`
   **PASS**: every role prints `YES`, including any BuddyPress/WooCommerce-registered roles on the
   site — the capability was introduced on an already-shipped feature and is granted everywhere by
   default (journey 03, step 1).

2. **Record the baseline count.** `wp eval` a count of Member A's documents (e.g.
   `SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND
   post_author=<member_a_id>`). Note the number.

3. **Untick the member's role.** In Settings → Documents (or Settings → Permissions — see Section 5
   step 2), untick Member A's role under "Who can use documents" and save.
   **PASS**: `wp eval 'echo get_role("subscriber")->has_cap("use_mvs_documents") ? "YES" : "no";'`
   (adjust role name to Member A's actual role) prints `no`.

4. **The member loses every document surface — with the right status code.** As Member A, open
   `/my-media/`, then call `mvs-pro/v1/documents`, `mvs-pro/v1/folders`, and `mvs-pro/v1/me/shared`
   with a valid nonce.
   **PASS**: no Documents entry in the dashboard rail. All three routes return **403
   `mvs_documents_unavailable`** — specifically 403, not 401. Member A is signed in; a 401 would send
   them into a login loop that can never resolve, since logging in again changes nothing.

5. **Public content stays public.** While Member A is still capped out, open a **public** document's
   permalink as Member A, then sign out entirely and open the Explore Documents listing.
   **PASS**: both still render normally. The capability governs whether a member has a library of
   their own — it must never gate a read. A public document must remain visible to a signed-out
   visitor and readable by a capped-out member who could otherwise legitimately see it.

6. **Nothing was deleted.** Re-run the count from step 2.
   **PASS**: identical number. Off hides the surface; it must never touch a row.

7. **Re-tick restores the drive intact.** Re-tick Member A's role and save, then reopen
   `/my-media/documents/` as Member A.
   **PASS**: every folder and document is exactly where it was; the count in the toolbar matches
   step 2.

8. **A version bump does not undo the owner's revocation.** Untick the role again, save, then run
   `wp eval '\WPMediaVerse\Capabilities\MediaCapabilities::add_caps();'` (this is what a plugin
   version bump runs), and re-check the capability.
   **PASS**: still `no`. `add_caps()` must replay `mvs_role_caps_overrides` after re-applying
   defaults, or a routine update would silently restore access the owner deliberately revoked.
   **Re-tick the role before continuing to other sections** so you don't carry a capped-out fixture
   into later steps.

---

## Section 4 — The master toggle (`mvs_pro_documents_enabled`)

Citations: `wpmediaverse-pro/audit/journeys/admin/02-documents-toggle-gates-surfaces.md` (full
journey), `WHAT-TO-CHECK.md` §3 "Document library" filter table.

1. **Baseline.** `wp option get mvs_pro_documents_enabled` (absent or `1` is correct — an absent
   option must read as ON, since a default-off toggle introduced on a shipped feature would take
   Documents away from every existing site on upgrade). `curl -s -o /dev/null -w '%{http_code}'
   $SITE_URL/wp-json/mvs-pro/v1/documents` → **401** with no cookie (the list is member-scoped —
   `mvs_unauthorized` is correct, not a bug; **404** when the master toggle is off). A `200` here
   would mean the member-only list is world-readable. (Basecamp 10190137038.)

2. **Record what the site holds.** Count documents and folders (see the journey's step 2 query, or
   reuse Section 1/3's counting approach). Note both numbers.

3. **Turn it off.** `wp option update mvs_pro_documents_enabled 0`.
   **PASS**: `wp option get mvs_pro_documents_enabled` reads exactly `'0'`. If it reads `''`, the
   sanitizer did not run — see the Known Traps table below.

4. **REST routes vanish.** `curl -s -o /dev/null -w '%{http_code}'
   $SITE_URL/wp-json/mvs-pro/v1/documents`
   **PASS**: **404** (`rest_no_route`).

5. **Every front-end surface vanishes.** As a member: open `/my-media/documents/`, the Explore
   Documents listing, and a known document permalink.
   **PASS**: no Documents entry in the dashboard rail. The listing page renders nothing for a
   visitor (an administrator viewing it instead sees one line explaining documents are switched
   off). The document permalink returns **404** — not a page still offering a Download button that
   points at a removed route.

6. **The admin screen vanishes.** Open `wp-admin/admin.php?page=mvs-documents`.
   **PASS**: the submenu entry is gone entirely.

7. **Nothing was deleted.** Re-run the count from step 2.
   **PASS**: identical numbers.

8. **Turn it back on — the drive is intact.** `wp option update mvs_pro_documents_enabled 1`, then
   reopen `/my-media/documents/` as the member.
   **PASS**: every folder and document is exactly where it was, the toolbar count matches step 2,
   and the document permalink from step 5 renders again.

9. **Nothing else was disturbed.** Throughout steps 3-8, `curl -s -o /dev/null -w '%{http_code}'
   $SITE_URL/wp-json/mvs/v1/media` should answer 200. The documents switch must never affect media,
   albums, collections, or competitions.

---

## Section 5 — Settings screen (seven controls)

Run as admin for configuration, then verify the effect as a member/anonymous visitor. Citations:
`C.admin.documents-settings`, `D.documents-config-matches-enforcement`, journey
`03-documents-settings-and-role-gate.md` steps 3, 9-13.

The screen has seven controls. For every one of them: **(a)** it must display the value it will
actually use BEFORE any save — an unsaved default-ON toggle must not visually render as off, and
**(b)** saving it must change both what the server enforces AND what `GET /mvs/v1/app/config` →
`documents` reports, in the same direction. A config value that disagrees with enforcement is the
specific defect this section exists to catch.

1. **Enable Documents.** This is the master toggle from Section 4 — confirm it's exposed here and
   agrees with the option-level check already done.

2. **Who can use documents.** Compare this field's ticks against the role capability check from
   Section 3 step 1.
   **PASS**: they match exactly. This field must read live `WP_Role` state, not a stored option — if
   it read an option, using the Permissions matrix (an alternate screen that edits the same
   capability) would leave this screen showing stale state.

3. **Maximum document size.** Set to 1 MB, save, then upload a document larger than 1 MB as a
   member.
   **PASS**: refused with `mvs_document_too_large`. Set back to `0` and confirm the description
   explains `0` means "follow the server's own limit" — not "allow nothing". Confirm
   `GET /mvs/v1/app/config` → `documents.max_size` matches whatever you set.

4. **Allowed file types.** Untick one category (e.g. Presentations), save, then as a member open the
   drive's type filter and try uploading a file of the disallowed type.
   **PASS**: the disallowed type is gone from the filter dropdown (a filter that's guaranteed to
   return nothing reads as a broken drive) and the upload is refused by name with
   `mvs_document_type_not_allowed`. `documents.allowed_mimes` in `/app/config` no longer advertises
   that type's MIME types. A document of that type **already on the drive** is still listed and
   still opens — unticking a type only refuses new uploads.

5. **New documents start as (default privacy).** Set to "Members", save, upload a document as a
   member.
   **PASS**: the new document lands with `privacy = members`. The dropdown offers exactly the same
   three values as the per-row privacy control in Section 1 step 6 (Only me / Members / Anyone).
   `/app/config` → `documents.default_privacy` reports `members`.

6. **Anonymous share links.** With the box unticked (the shipped default), try to mint a public link
   from a document's share sheet.
   **PASS**: refused with `mvs_link_sharing_disabled`; `/app/config` → `documents.anonymous_links`
   reports `false`. Then tick it, mint a link, confirm it opens signed-out, then untick it again and
   confirm the **already-issued** link stops opening. The check runs on redemption, not only on
   mint — verify both halves.

7. **Search inside documents.** Untick, save, upload a new document, and check whether an extraction
   job was queued (e.g. via the Action Scheduler admin list, or watch `mvs_pro_document_search` for
   a new row).
   **PASS**: no new extraction job queued. Documents already indexed before the toggle went off stay
   searchable — turning it off stops new indexing, it does not erase what was already extracted.

8. **Filters still win.** If a mu-plugin or test filter overrides one of the above (e.g.
   `mvs_document_max_size`), confirm the filter's value wins over whatever the screen shows.
   **PASS**: resolution order is option first, filter last — a site that already scripts these
   settings must keep winning over the UI, or upgrading into this screen would silently reconfigure
   a site that had deliberately customized behaviour via code.

---

## Section 6 — Single document page and public Explore Documents listing

Run mixed member/anonymous. Citations: `C.member.documents-single-and-public`,
`WHAT-TO-CHECK.md` single-document and `/explore-document/` rows.

1. **PDF preview tier.** Open a PDF document's permalink (`/media/<slug>/` — the same route media
   uses).
   **PASS**: renders inline in a frame (tier 1). The back link on this page reads **"Documents"**
   and points at `/explore-document/`, not "Explore" pointing at `/explore-media/` (verify a photo's
   single page still says "Explore" — a regression that sends everything to Documents would
   otherwise pass unnoticed).

2. **Text/markdown/CSV preview tier.** Open a plain-text or CSV document.
   **PASS**: renders as server-rendered HTML (tier 2), not a raw download prompt.

3. **Word/Excel preview tier.** Open a Word or Excel document.
   **PASS**: renders via a client-side viewer (tier 3) or, if that's not configured, falls back
   cleanly to the card + Download presentation — never a blank panel.

4. **Unsupported type / card tier.** Open the fixture type outside the above three (e.g. a zip).
   **PASS**: renders as a card with type, size, author, and a working Download button (tier 4).

5. **Missing file state.** If you can simulate a document whose underlying file is gone (or use a
   known broken fixture), open its permalink.
   **PASS**: shows the message "the file for this document is missing" — never an empty box with no
   explanation.

6. **A refused document 404s, never 403s.** Attempt to open a document you have no access to (a
   private document belonging to someone else, or one for a role you're capped out of).
   **PASS**: **404**, never 403. A 403 confirms the document exists; for a filename that may carry a
   client's name, that confirmation is itself a leak.

7. **Public Explore Documents listing.** Open `/explore-document/` at desktop and at 390px.
   **PASS**: shows **rows, never grid tiles** — documents have no picture, so a grid draws them as
   broken tiles. Each row carries a type chip, size, and author. Pagination works. Filtering to a
   type with zero matches shows "nothing matches that filter" with a clear-filters affordance, not a
   silent empty grid.

---

## Section 7 — Admin Documents screen

Run as admin. Citations: `C.admin.documents-screen`, Pro CLAUDE.md 2026-08-10 changelog.

1. **List filters and sort.** Open `wp-admin → WPMediaVerse → Documents`. Use search, the type
   filter, and the privacy filter; try each sort column.
   **PASS**: each filters/sorts the table correctly. Filtered-to-zero shows "no documents match
   these filters" with a clear-filters affordance, distinct from the true empty state ("no documents
   uploaded yet").

2. **Row actions.** Confirm the row actions are exactly **Edit / View on site / Download / Trash /
   Delete permanently**, and that clicking the **Title** opens the editor (not the front-end page).
   **PASS**: all five actions work as named; Title opens `?view=single`, not the permalink.

3. **Single editor.** Open a document via `?page=mvs-documents&view=single&media_id=N`. Edit title,
   slug, description, tags, and privacy, and save.
   **PASS**: all fields save and the change reflects on the front end. The screen writes through the
   same `set_many()` / `generate_unique_slug()` / `wp_set_object_terms()` path the REST API uses —
   there is no separate code path to drift.

4. **Refuses a photo ID.** Navigate the same single-editor URL with a media ID that belongs to a
   photo, not a document.
   **PASS**: refused — the screen must check `is_document()` and reject a non-document ID.

5. **Refuses an empty title.** Clear the title field and attempt to save.
   **PASS**: refused, title not stored empty. Same guard applies on both the REST path and this
   admin path.

6. **Slug never regenerates from the title.** Note a document's current slug, edit only its title
   (leave the slug field untouched), and save.
   **PASS**: the slug is unchanged. A member (or admin, on their behalf) fixing a typo in the title
   must not silently break every link that document's slug was already shared under.

---

## Section 8 — Mobile 390px and dark mode

Every surface above (drive, sharing UI, settings screen, single document, admin screen) needs a
pass at 390px viewport and in dark mode. Do not treat this as optional or as a separate low-priority
sweep — it is part of the definition of done for this feature.

1. **390px, every drive surface.** Repeat Section 1 steps 1, 2, 3, 6, 8 (open, create folder, upload,
   privacy dropdown, trash/restore) at 390px width.
   **PASS**: no horizontal page scroll (`document.documentElement.scrollWidth <=
   window.innerWidth + 1`); folder/document rows stack or truncate sensibly; tap targets are
   reachable (40px floor).

2. **390px, single document page.** Open a PDF and a card-tier document at 390px.
   **PASS**: the preview frame and the card both fit the viewport without horizontal scroll.

3. **390px, admin Documents screen — with the right expectations.** Open the admin list table at a
   390px **browser viewport**.
   **PASS/caveat**: verify layout does not overflow horizontally, but do not treat this as
   equivalent to a real phone rendering WP admin — see the Known Traps table below. If you have
   access to an actual mobile device or device-emulation that sets `body.mobile`, prefer that for
   the admin screen specifically.

4. **Dark mode, every screen above.** Toggle the site (or OS-level) dark mode and revisit the drive,
   settings screen, single document page, and admin screen.
   **PASS**: all text remains readable (no dark text on dark background or vice versa), all controls
   remain visible, no raw white panels bleeding through a dark theme.

---

## Must-never-happen invariants

These hold across every section above. Treat a violation of any row here as release-blocking
regardless of which section you were nominally testing when you found it.

| Invariant | How to check | Citation |
|---|---|---|
| A document never appears on a media surface (Explore, the media grid, album items, activity stream, admin All Media default view) | Seed a `public`/`approved` document, visit each surface, confirm no tile/row references it. `GET /mvs/v1/media?media_type=document` must answer **400 `mvs_document_route`**, never an empty 200 or the document itself. `GET /mvs/v1/media?media_type=image` must still work normally (the guard must not have narrowed the whole parameter). | `D.document-never-in-media-surface`; security journey `07-document-never-in-media-surface.md` |
| A refused document 404s, never 403s | Attempt to open a document you have no access to, and hit its delivery route directly. Expect 404 in both cases. | `WHAT-TO-CHECK.md` "A refused document is silent, not explained"; Section 6 step 6 above |
| Turning anything off never deletes data | Toggle the master switch off/on (Section 4) and the role gate off/on (Section 3); count documents and folders before and after each. Counts must be identical at every step. | `D.documents-role-gate-hides-not-deletes`; toggle journey step 7 |
| `unlisted` appears nowhere in the privacy vocabulary | Check every privacy dropdown (drive row, settings default, admin editor) offers exactly Only me / Members / Anyone. Attempt `POST /documents/{id}` with `privacy: unlisted` — expect 400 `mvs_document_privacy_invalid`. | `D.documents-privacy-vocabulary` |
| `/app/config` never advertises what the server refuses | For each of the seven settings in Section 5, confirm `GET /mvs/v1/app/config` → `documents` moves in the same direction as what you just configured, and that the server actually enforces that value. | `D.documents-config-matches-enforcement`; `C.admin.documents-settings` |

---

## Known traps

These have produced false results in prior runs. Read this before filing a finding that matches one
of them.

- **`/explore-documents/` (plural) 404s. This is correct, not a bug.** The Explore Documents page
  slug is `explore-document`, singular. Confirmed as expected behaviour in the 2026-08-11 combo run.
  If you land on a 404 there, check the URL before filing anything.
- **Desktop Chrome at 390px is not what a real phone renders for a WP admin list table.** WordPress
  core adds a `body.mobile` class only on genuine mobile devices (detected via user agent), which
  changes admin list-table layout in ways a resized desktop browser window does not reproduce.
  Treat a 390px-wide desktop-Chrome pass on the admin Documents screen as a partial signal, not full
  confirmation — see Section 8 step 3.
- **A checkbox that POSTs nothing when unchecked, resulting in the option reading `''` instead of
  `'0'`, means the sanitizer didn't run — this is a bug, not evidence the toggle was already off.**
  An unsaved or freshly-toggled-off checkbox rendering as visually OFF while the underlying option
  is still effectively "unset/on" is the specific failure mode documented in the toggle journey
  (`mvs_pro_documents_enabled` step 3) and the settings journey (`D` table, "An unticked box appears
  to do nothing"). If a step above behaves as though a toggle you just unticked is still active,
  check the raw option value with `wp option get <key>` before concluding the feature itself is
  broken.
- **The `/explore-document/` empty-drive state deliberately mentions privacy.** `?drive=my-drive`
  with nothing in it reads "your drive is empty" and adds that uploads are private until shared —
  this is documented copy, not a stray warning to file.
- **An un-indexed search answering "no results" instead of "indexing" is the bug — an "indexing"
  state on a freshly-uploaded document is correct**, not evidence search is broken. The schema
  supports `disabled / empty / indexing / partial / ready` and lands several phases before
  extraction actually fills it.

---

## How to report

- **Severity**: use the plugin's from/for triage from `AGENT_SMOKE_RUNBOOK.md`'s failure protocol —
  `from` means our code (WPMediaVerse or WPMediaVerse Pro) is at fault and is always ours to fix;
  `for` means the failure surfaces while our plugin runs but the root cause is a theme, another
  plugin, a browser limitation, or hosting quirk, and warrants a judgement call rather than an
  automatic bug filing.
- **A finding needs a citable documented promise, or it is an Observation, not a bug.** Every row in
  this checklist cites the runbook row, inventory row, or journey step that makes the promise you're
  checking. If what you found conflicts with one of those citations, it's a bug — file it against
  that citation. If you found something that looks wrong but you cannot point to a row in this
  document, `AGENT_SMOKE_RUNBOOK.md`, `WHAT-TO-CHECK.md`, or one of the two journeys that says what
  the correct behaviour should be, record it as an **Observation** for triage, not as a confirmed
  bug — a QA opinion about layout or preference is not automatically a defect (see the "QA is not
  the source of truth" principle: site owners and customers decide what's right, QA surfaces
  problems).
- **Screenshot every failure.** Full-page screenshot at the viewport you were testing, named with
  the section/step number.
- **Note what you have NOT verified.** Say explicitly in your report which sections you completed
  and which you skipped, so the next run does not assume more coverage than actually happened.

### What prior coverage actually exists

Stated precisely, because the changelog is misleading on this point. Several 2.4.0 entries end with
"Not run: combo browser smoke" — those were written on 2026-08-08 and 2026-08-10, **before** the
smoke was run. They are historical, not current.

As of the reviewed combo run in `qa/runs/2026-08-11-combo.md`:

| | State |
|---|---|
| Fresh install and upgrade (capability grants, table creation, page auto-creation) | Verified in a clean Docker stack from the 2.3.2 release ZIPs |
| Document settings, the role gate, and `/app/config` agreement | Walked, as the deepest-verified area of that run |
| The drive itself — folders, upload, a privacy change verified through to the database | Walked |
| Single-document rendering across preview tiers | Walked |
| Sharing (grant, revoke, "Shared with me"), bulk move, in-drive search | **Not walked** — these are the real gaps this checklist closes |
| Firefox, Safari, Safari iOS | Not walked; Chromium-only tooling |

So sections 2, and the bulk-move and search steps of section 1, are the ones nobody has exercised
in a browser. Start there if you are short of time.
