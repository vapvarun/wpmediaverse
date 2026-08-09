---
journey: document-never-in-media-surface
plugin: wpmediaverse
priority: critical
roles: [administrator, subscriber, anonymous]
covers: [document-never-in-media-surface, document-library-containment, media-feed-document-refusal]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Free + Pro both active (the Pro surfaces are half the assertion)"
  - "An album with at least one media item (the fixture uses album 17)"
  - "A smart collection whose rule matches on privacy=public (the fixture uses collection 15)"
estimated_runtime_minutes: 8
---

# A document is reachable at its own URL and appears in no media surface

**Why this journey exists**: the document library shares one table with the media library
(`mvs_media_index`), so containment is a query-discipline promise rather than a structural one.
Phase 1 replaced the structural guarantee with a positive type predicate on every listing
(`MediaTypes::MEDIA_LIBRARY`); this journey is the regression net that proves the predicate is
actually applied, on every surface, at both viewports. Without it the discipline has no proof.

**Design**: `plan/document-library.md` §3.2 (positive inclusion, never exclusion) and §5
(*on a document, `public` means unlisted — reachable by URL, never discoverable*).

**Read this before editing the journey**: an absence assertion is only worth what its fixture makes
non-vacuous. Each step below records whether it is currently **load-bearing** (the surface would
render the document if the predicate were removed) or **vacuous** (the surface has no data, so it
would pass either way). Do not promote a vacuous step to load-bearing without giving it a fixture.

## Setup

- Site: `$SITE_URL`, admin via `?autologin=1`. Never fill the login form.
- **Seed the document through the service layer** — `MediaRepository`, so AUTO_INCREMENT assigns the
  id. Never `$wpdb->insert()` at a chosen `media_id`: on a populated table that id is usually taken,
  the insert fails silently, and cleanup then deletes a row it never created. That is how four real
  media rows were destroyed on the reference install.
  - Seed as `media_type=document`, `privacy=public`, `status=publish`,
    `moderation_status=approved` — the **most exposed** configuration, so every absence assertion
    below is made against the row most likely to leak.
  - Record the id in option `mvs_qa_seed_doc_id`. **Always read the option; never hard-code the id.**
    The fixture is not durable: the original `media_id=157` and its PDF were destroyed on the
    reference install on 2026-08-09 (see below) and re-seeded as `158`. A journey that assumes an id
    fails for a reason that has nothing to do with what it is testing.
  - **If the option points at a missing row, re-seed rather than skipping.** A run with no document
    on the site passes every absence assertion while proving nothing — the worst possible outcome
    for this journey, because it is green and empty.
- **Adversarial album injection** — insert one row into `mvs_album_items` pointing the album at the
  seed document. This only touches the join table and reuses the existing `media_id`, so it does not
  violate the seeding rule. The album must end with **N+1 join rows and render N items**.
- **Adversarial collection** — the fixture's collection 15 ("Public Gallery") has the single rule
  `privacy = public`, and the seed document *is* public. It therefore matches the collection's rule
  on every column except type, which is exactly the discriminator under test. No setup needed.
- Run every rendered assertion at **1280x800 and 390x844**.

## Steps

### 1. `GET /mvs/v1/media` — the default feed excludes it — LOAD-BEARING
- **Action**: `curl -s "$SITE_URL/wp-json/mvs/v1/media?per_page=100"`, anonymous.
- **Expect**: the seed id is absent; every returned `media_type` is in
  `{image, video, audio, legacy_document}`; `X-WP-Total` equals the count of non-document publishable
  rows, **not** the table's row count.
- **Why load-bearing**: this route hand-builds its own `WHERE` rather than going through
  `MediaRepository`, so it does not inherit the repository default. It is also the route the mobile
  app reads.
- **On fail**: `includes/REST/Controller/MediaController.php::get_items` — the positive
  `MediaTypes::in_clause( MediaTypes::MEDIA_LIBRARY )` branch.

### 2. `GET /mvs/v1/media?media_type=document` — refused — LOAD-BEARING
- **Action**: same route with `?media_type=document`.
- **Expect**: **HTTP 400**, code `mvs_document_route`. Not an empty 200 — a refusal is never a
  success response (Coding Rule 20), and an empty list would read as "there are no documents".
- **And**: `?media_type=image` still returns only images (the guard must not have narrowed the whole
  parameter).
- **History**: until 2.4.0 this returned the document. `media_type`'s enum has advertised `document`
  since the first release, so the change carries a Production Rule 3 escape hatch —
  `add_filter( 'mvs_media_feed_allows_documents', '__return_true' )` restores the old behaviour.
  Verify the hatch in both directions when this step is touched; an untested escape hatch is not one.
- **On fail**: `MediaController::get_items` document guard, or
  `MediaController::media_feed_allows_documents()`.

### 3. `GET /mvs/v1/me/media` — same route, same refusal — LOAD-BEARING
- **Action**: as the document's **author** (the session most likely to be granted it), request
  `/me/media` and `/me/media?media_type=document`.
- **Expect**: default list excludes the document; the explicit form is 400 `mvs_document_route`.
- **Why this needs its own step**: `get_my_items()` sets `author` and delegates to `get_items()`, so
  it inherits the guard — but that delegation is exactly the kind of thing a refactor breaks
  silently.

### 4. Explore grid — LOAD-BEARING
- **Action**: open `$SITE_URL/explore-media/` as admin and anonymous.
- **Expect**: no tile, no title, no `[data-media-id]` matching the seed id anywhere in the DOM.
- **Why load-bearing**: the document is public and approved, which is precisely Explore's inclusion
  criteria on every column but type.

### 5. Album — LOAD-BEARING (the strongest check here)
- **Action**: open the album seeded in step 0 with the injected join row.
- **Expect**: **N items rendered from N+1 join rows**, and the album's **item count text also reads
  N**. Fixture: 7 join rows, 6 tiles, "6 items".
- **Why both**: a type filter applied to the tile loop but not to the count is the common half-fix,
  and it is visible to a member as an album that claims more items than it shows.
- **On fail**: `includes/Services/AlbumService.php::album_items` / `::count_album_items` — both, since
  they are the pair that must agree.

### 6. Collection — LOAD-BEARING
- **Action**: open the `privacy = public` smart collection.
- **Expect**: the document is absent and the count matches the rendered tiles.
- **Why load-bearing**: the collection's own rule matches the document. Only the type predicate
  excludes it, so this step fails the moment that predicate is dropped.
- **On fail**: `includes/Services/CollectionService.php`, `MediaRepository::query()`.

### 7. Member dashboard / My Media — LOAD-BEARING
- **Action**: open `$SITE_URL/my-media/` as the document's author.
- **Expect**: absent from the Media tab and from the tab's count.

### 8. wp-admin → All Media — LOAD-BEARING, with a known exception
- **Action**: open `admin.php?page=mvs-media`.
- **Expect**: the document is **absent** from the default listing and the "N items" total.
- **Known exception**: `admin.php?page=mvs-media&media_type=document` **does** list it, and the type
  dropdown still offers "Document". That is accepted for now — it is an owner-only backend surface
  and, until P6.1 ships the Documents screen, it is the only way an admin can see the row exists.
  **Re-decide this step when P6.1 lands**: two backend surfaces for one store is the drift this
  codebase has been bitten by before.

### 9. BuddyPress activity stream — VACUOUS TODAY
- **Action**: open `$SITE_URL/activity/`.
- **Expect**: no activity row references the document.
- **Why vacuous**: activity rows are written at ingest, and a seeded row never had an ingest, so
  there is nothing for this step to find either way. It becomes load-bearing at **P3.4**, when a
  document can be uploaded for real. Do not read a pass here as evidence.

### 10. Pro surfaces — Instagram layout, leaderboard, challenges, tournaments, boosts, stories
- **Action**: open `$SITE_URL/compete/` and the Instagram-layout Explore variant.
- **Expect**: the document appears in none of them.
- **LOAD-BEARING AT THE WRITE, which is stronger than a read filter.** A document cannot *become* a
  competition entry or a boost in the first place, so there is no bad row for these surfaces to
  filter. Assert the four refusals directly rather than only eyeballing the pages:

  | Call | Expected |
  |---|---|
  | `ChallengeService::submit_entry( $c, $u, <doc> )` | `mvs_challenge_invalid_media` |
  | `BattleService` submit | `mvs_battle_invalid_media` |
  | `TournamentService` submit | `mvs_match_invalid_media` |
  | `BoostService::create( $u, <doc> )` | `mvs_boost_invalid_media` |

  **And each must still accept a real image** — verified 2026-08-09: document refused on all four,
  image accepted into a live challenge.
- **Why the write is the right place**: all four checked `exists() && author === user` and **not the
  type**, in four separate copies. A boost is the sharpest case — it buys placement in the *feed*,
  which is the exact surface the document library exists to stay out of. The four copies are now one
  `Support\CompetitionMedia::is_entrable()`.
- **Note on reading these pages**: `/compete/` shows "You haven't joined any competitions yet" for a
  member with no entries. That is the **My Activity panel**, not an empty site — this fixture has 8
  competitions. Do not record a personal empty state as proof the surface is empty.
- **Stories is LOAD-BEARING by code, not by render**: `StoryService::create()` refuses any media
  whose type is outside `MediaTypes::MEDIA_LIBRARY`, and the story listing query carries the same
  positive clause. Assert the refusal directly:
  - `POST /wp-json/mvs-pro/v1/media/<seed id>/story` → **400 `mvs_story_invalid_type`**. The service
    signals refusal by returning `''`; `StoryController::create_story()` is what turns that into an
    error rather than a 200 with an empty expiry (Coding Rule 20).
  - **And the same call on a real image must still succeed** — a guard that refuses everything passes
    this step while breaking the feature. Verified 2026-08-09: document refused, image accepted.
- Stories is **not** a document surface by definition (owner, 2026-08-09) — the guard is what
  enforces that rather than assuming it, and before it existed the route accepted any media id.

### 11. Lightbox — IMPLIED, not separately testable
- The lightbox opens from a grid tile. Steps 4-7 establish the document has no tile on any grid, so
  there is no path to open it. Recorded here so its absence from the list is a decision, not a gap.

### 12. Reachable at its own URL — the other half of the promise
- **Action**: open `$SITE_URL/media/<seed slug>/`.
- **Expect**: it **renders** — this must NOT 404. Containment means "not discoverable", never
  "unreachable"; design §5 is explicit that public-on-a-document means reachable by URL.
- **Today** it renders through `media-single.php` with full media chrome (reactions, share, comments)
  and no preview panel. That is expected until **P9.3** replaces the preview panel for documents.

### 13. Both viewports
- **Action**: repeat steps 4-8 at 390x844.
- **Expect**: same absences, and no horizontal page scroll
  (`document.documentElement.scrollWidth <= window.innerWidth + 1`).

### 14. Upload a real document — TODO(P3.4), MUST FAIL THIS JOURNEY UNTIL THEN
- **Action**: `POST /wp-json/mvs/v1/media` with a `.pdf`.
- **Expect once P3.4 lands**: HTTP 201, `media_type=document`, `privacy` forced to `private`, and a
  declared `doc_type` that disagrees with the resolved one gets a 400 rather than a silent fix.
- **Today**: 400 `mvs_document_not_supported`. Every ingest path calls
  `UploadService::reject_unsupported_mime()`, so no document can enter through the front door yet —
  which is why this journey seeds instead of uploading.

### 15. It appears in the drive — TODO(P9.1)
- **Action**: open `/documents/` and assert the seeded document is listed in My Drive.
- **Today**: no drive exists. This is the presence half of the journey's title, and it stays an
  explicit failing step rather than being quietly dropped — an absence-only journey would pass
  perfectly on a build where documents cannot be seen at all.

## Pass criteria

**Active** — all must hold for the journey to pass today:

1. `/media` and `/me/media` default lists exclude the document, in body and in `X-WP-Total`.
2. `?media_type=document` returns 400 `mvs_document_route` on both; `?media_type=image` still works.
3. Explore, album, collection, My Media and admin All Media (default view) all exclude it, **tiles
   and counts alike**.
4. The album renders N of N+1 join rows.
5. `POST /media/<id>/story` is refused.
6. `/media/<slug>/` still renders — containment is not unreachability.
7. Every rendered assertion holds at 390px with no horizontal scroll.

**Deferred** — steps 14 and 15 fail by design until P3.4 and P9.1 land. They are listed, not hidden:
this journey is not complete until a document can be uploaded and seen in its own surface.

## Fixture incident, 2026-08-09 — read before trusting a green run

Between two verification passes on the reference install, **19 media rows were deleted**, including
the seed document (`media_id=157`) and its PDF. Established from the evidence, not inferred:

- `wp_actionscheduler_actions` holds ~19 `mvs_cleanup_media_files` jobs all scheduled at
  **08:12:17 UTC**, and that hook is queued only by `MediaRepository::delete_cascade()`. One job's
  args name `2026/08/qa-seed-document.pdf`, so the document went through the normal cascade — this
  was a delete, not a truncate or a corrupted table.
- The deleted set is the admin-owned QA fixture media uploaded 2026-08-05/06. Demo-user media
  (users 8-12) was untouched, so the demo cleanup script — which scopes to `@demo.local` — is not
  the cause.
- **Not the test suite**: PHPUnit runs against a separate database (`wp_tests`, prefix `wptests_`),
  verified in `wp-tests-config.php`.
- `mvs_error_log` has no entry, because `delete_cascade()` does not log deletions. That absence is
  itself worth noting: **the plugin can delete a member's entire library and leave no trace in its
  own log.** Whatever triggered this, that is a gap worth closing on its own merits.
- **Trigger not established.** The window overlaps a `plugin deactivate`/`activate` cycle on Pro run
  during verification, but Pro's deactivation hooks only clear cron events and transients, so the
  cycle does not explain a cascade delete. Stated as unresolved rather than guessed.

The fixture was re-seeded through `MediaRepository` (id 158) and containment re-verified against the
new row. **The lesson for this journey**: its Setup is not optional scaffolding — it is the only
thing standing between a meaningful run and a green, empty one.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Document in `/media` default feed | positive predicate dropped from the hand-built WHERE | `includes/REST/Controller/MediaController.php::get_items` |
| `?media_type=document` returns 200 | guard removed, or the escape-hatch filter is armed on the site | `MediaController::get_items`, `MediaController::media_feed_allows_documents` |
| Album shows N+1 items | type filter on the item query but not the count, or neither | `includes/Services/AlbumService.php::album_items`, `::count_album_items` |
| Album shows N tiles but count says N+1 | the half-fix — filter applied to the loop only | same pair, they must agree |
| Document in a smart collection | rule builder bypassing the repository default | `includes/Services/CollectionService.php`, `Repository/MediaRepository.php::query` |
| Document in Explore / dashboard | a caller overriding `media_types` | `Repository/MediaRepository.php::query` — grep every `media_types` / `types` override |
| A story was created on a document | the type guard | `wpmediaverse-pro/includes/Stories/StoryService.php::create` |
| `/media/<slug>/` 404s | over-correction — the slug lookup must stay type-agnostic | `MediaController::get_items` slug branch |
