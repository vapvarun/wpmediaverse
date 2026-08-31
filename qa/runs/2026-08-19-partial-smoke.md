# 2026-08-19 — 2.4.0 combo walk (PARTIAL)

Run against `http://mediaverse.local` (Free + Pro + BuddyPress + BuddyNext active, bunnycdn the
active storage driver, **licence active**). Baseline described in `qa/MODEL-SITE.md`.

**Verdict: not a release sign-off, and `qa/.last-smoke-pass.json` was deliberately NOT written.**
Everything walked passed. Roughly half the runbook was not walked, and a green gate artifact
covering a half-walk is exactly the artifact that let this release get to 2026-08-19 believing it
had been smoke-tested.

---

## 0. Two defects in the QA machinery itself, found before any check ran

Both are fixed in this session's commits. Both mattered more than anything the walk found.

**The release gate could not tell a stale report from a fresh one.** `bin/build-release.sh` checked
version, mode, and zero from-origin failures. None of those notices a report that predates the code
it claims to have walked — and the version had not moved, so the version match said nothing. The
combo report dated 2026-08-11 passed every check while document previews, the licence gate, an
index fix and a live PHP warning had all landed after it. The gate now requires the report to be
**newer than the last commit touching plugin source in either repo**, and requires the core-flow
section to record real passes, because zero failures is not evidence when nothing ran. Verified
against the stale report: correctly rejected.

**The runbook told its reader to write the report where nothing reads it.** The output contract
named `docs/qa/.last-smoke-pass.json`; that directory does not exist and the gate reads `qa/`. An
agent following the runbook literally would write its report into the void, leave whatever stale
pass was already there, and the walk would look green the whole way through. Corrected, with the
reason recorded inline so the next reader does not "tidy" it back.

## 1. What was walked, and passed

| Check | Result | Evidence |
|---|---|---|
| Preconditions | PASS | Free 2.4.0 = Pro 2.4.0, fixtures clean (0 rows removed), `debug.log` baselined at 0 |
| `C.anon.explore-feed` | PASS | 70 items, real thumbnails served from `mediaverse1.b-cdn.net`, tag chips, sort controls, join-CTA for anon |
| `C.anon.search-empty-state` | PASS | `/media/?s=zzznoresults999` → empty state with **Browse all media** + 8 tag chips (the F5 regression surface) |
| `C.anon.tag` unknown slug | PASS | 200, clean empty state, no fatal |
| `C.anon.dashboard-gate` | PASS | `/my-media/` anon → `redirect_to` round-trip + "Log in to continue" |
| Signed / CDN delivery | PASS | 200 `image/jpeg`, 139,009 bytes |
| Pro document routes vs anon | PASS | `/documents`, `/folders`, `/drives`, `/me/shared` all 401 `mvs_unauthorized` — the frozen contract code, not a 500 |
| Document drive, licensed | PASS | 62 documents / 22 folders, upload control, new-folder form, trash link, bulk bar + 25 tick-boxes, 26 row menus, in-drive search, both tabs. No read-only note (correct while licensed) |
| Drive sub-surfaces | PASS | `/trash/`, `/shared/`, `?q=`, `/page/2/` all 200 |
| Trash view | PASS | Header, "Back to my documents", Location column, row menu carrying restore |
| Document renditions | PASS | `wp mvs render-documents` → 3 rendered, 0 failed |
| Licence gate, both states | PASS | Read-only verified as a member at 1280px and 390px in dark mode; stale form across a lapse refused and created nothing; activating restored every control. Cert oracle proves it at release time |
| Index change | PASS | Drive listing intact after the `drive_listing` fix; measured 8,032 → 234 rows examined at OFFSET 1000 on a 30k fixture |
| `wp mvs cert` | PASS | 69 / 0 / 0 |
| `wp mvs-pro cert` | PASS | 59 / 0 / 0 (58 + the new licence oracle) |
| `debug.log` across the walk | PASS | **Zero** plugin-origin entries after every surface above |

## 1b. Member write flows (second pass, driven by Application Password)

Driven over real HTTP with an Application Password — **no cookie, no nonce** — which is the
app-drivability contract Pro CLAUDE.md rule 6 makes mandatory. Credential revoked afterwards and
verified gone.

| Check | Result | Evidence |
|---|---|---|
| App-password auth | PASS | `/me/media`, `/documents`, `/drives` all 200. `/drives` → `can_write: true` while licensed |
| `C.member.upload-public` | PASS | 201; row `public`/`publish`/`approved`/`image`; **all three rungs** (thumb/medium/large) plus WebP siblings and `_path` metas; stats row created; visible to anon in `/mvs/v1/media`; original + large both 200 from the CDN |
| `C.member.grid-thumbnail-size` | PASS | `thumbnail_url` came back at the **1024 (large)** rung, which is the 1.8.0 contract |
| `C.member.upload-rejections` | PASS | `.txt` → 400 `mvs_unsupported_file_type` with a human message, and **zero rows created** |
| `C.member.edit-categories-persist` | **PARTIAL** | `OPTIONS` lists all 7 editable args (not id-only). PUT `[142]` → 200 `['Nature']`, GET back `['Nature']`, PUT `[]` clears. **But this baseline has no persistent object cache**, and the original bug was a persistent-cache miss taking a destructive else-branch — so the happy path is proved and the bug's own precondition is not |
| `C.member.delete-own` | PASS | 204; index, meta and stats rows all gone; files removed from local disk AND from BunnyCDN storage (`exists()` → no) once the queued `mvs_cleanup_media_files` action ran |
| `C.member.albums-lifecycle` 1–3, 6 | PASS | Create 201; add item 200 `{"added":1}`; item listed; **card shows "1 item" with a real cover thumbnail** — the collections-class trap is not present. Delete 204 purged `mvs_album_items` and **left the member's media intact** |

### The one thing worth escalating from this pass

**Deleted files keep serving from the CDN edge for up to 30 days.** Delete works correctly at both
storage layers — local disk and the BunnyCDN storage zone (verified with the driver's own
`exists()`, which answers `no`). But the object stays retrievable at its public URL because no
cache purge is issued on delete, and the response carries
`cache-control: public, max-age=2592000`. For media whose URL was already public the exposure is
bounded, and every CDN-backed site behaves this way unless it purges — but "I deleted it and it is
still online" is a real support ticket and a GDPR-adjacent one. **Not a broken contract** (the
runbook asks for the file to be removed, and it is), so it is recorded as an enhancement rather
than a failure: issue a purge on delete, or document the TTL.

I checked this properly before reporting it. The first reading — CDN 200 after delete — looked like
a leak; the storage driver said otherwise. Reporting the first reading would have been crying wolf.

## 1c. Debug log across the write flows

One `Fatal error`, and it is **`for`-origin, not ours**: an Action Scheduler deadlock
(`Unable to claim actions … Deadlock found`) thrown from
`plugins/buddynext/libs/action-scheduler/`. Worth noting rather than dismissing, because **both
plugins bundle Action Scheduler against the same tables**, and this is almost certainly what made
`wp action-scheduler run` refuse with "too many concurrent batches" during this walk. It did not
originate in `wpmediaverse/` or `wpmediaverse-pro/` and is not a blocker for this release.

No `from`-origin entries at any point in either pass.

## 2. Two things that looked like defects and were not

Recorded because both will look like defects again to the next reader.

**The black tile on Explore.** `Func-CjV7-photo` and `Func-LPTy-photo` are **1×1 pixel** QA fixtures
stretched to tile size — flat colour blocks, not broken thumbnails. Verified via `naturalWidth`:
both loaded, both 1×1. The neighbouring `Journey blocker public` is a real 800×533 JPEG that loads
fine. This is the "pixel-sample files, never trust titles" case.

**`/explore-media/?s=…` returning 404.** The canonical surface is `/media/`, a rewrite the plugin
owns; `explore-media` is the backing PAGE, and appending `?s=` to a page URL makes WordPress treat
the request as a site search, which never reaches the plugin template. `/media/?s=…` is 200 and
renders the empty state correctly. Not a bug — the runbook's URL was right and my first sweep's was
not.

## 3. What was NOT walked

Stated rather than implied by a green table.

- **`C.anon.single-media` — BLOCKED BY ENVIRONMENT, not skipped by choice.** `/media/<slug>/` 301s
  to `/p/1557/`, BuddyNext's activity permalink, via
  `WPMediaVerseBridge::redirect_single_media`. That is correct behaviour here, and it means the
  plugin's own single-media template — og:image / og:title / twitter:card, the canonical share
  target, its auth-gated actions — **cannot be asserted on this install at all**. It needs a
  standalone non-BuddyNext site. The 2026-08-15 battery hit the same wall from the other side.
- **Section A, fresh install** — needs the Docker pristine-install run, still outstanding.
- **Section B, upgrade** — walked 2.3.1 → 2.4.0 on 2026-08-15 and passed, but that predates the
  Migrator change in this session (`add_index_if_missing` now rebuilds a degraded index), so it
  needs redoing before the tag.
- **Member write flows still unwalked after the second pass** — the 16-cell privacy matrix, bulk
  trash/restore/delete through the UI, album reorder/set-cover/privacy (steps 3–5), lightbox and
  its edit modal, video poster fallback, activity composer and preview, streak badge,
  reactions/favourites/comments. Upload, rejections, delete-own, categories-persist, grid
  thumbnail size and albums 1–3/6 are covered in §1b.
- **Section D regression guards** and the rest of **Section E**.
- **Section F cross-browser** — Firefox, Safari desktop, Safari iOS 390px. Tooling is Chromium-only;
  permanently manual.

## 4. Recommendation

Unchanged from 2026-08-15, and now with a gate that enforces it: the shortest honest path to a tag
is a **complete** combo walk plus the 11 critical journeys, then Plugin Check and the pristine
Docker install. Nothing failed today. The gap is coverage, not known defects — and the gate will
now refuse to package on a report older than the code, which is the specific way this release
nearly shipped unverified.
