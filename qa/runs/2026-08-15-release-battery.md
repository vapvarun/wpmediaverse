# 2026-08-15 — 2.4.0 release battery (PARTIAL)

Run against `http://mediaverse.local` (Free + Pro + BuddyPress + BuddyNext all active,
bunnycdn the active storage driver). Recorded because the cycle's own commits kept saying
"cert not run", "combo smoke not run" — this closes some of that and states plainly what
is still open.

**Verdict: not a release sign-off.** What ran, passed. Most of the journey suite did not run.

---

## 1. Functional certification — PASS

Local CI skips this stage for want of `MVS_WP_PATH`, so it had not run all cycle.

| Command | Result |
|---|---|
| `wp mvs cert` | **69 pass / 0 fail / 0 hole** |
| `wp mvs-pro cert` | **58 pass / 0 fail / 0 hole** |

Pro is 58, up from the 57 recorded on 2026-08-11 — one more covered surface, from this
cycle's document work.

## 2. WP-CLI — PASS, after fixing a break this cycle introduced

Every command migrated by the Rule 7 work was run against live data. This is also how the
infinite-recursion regression (`9838e63d`) was found — **after** it had been pushed.

| Command | Result |
|---|---|
| `moderation-stats` | 181 approved, matches the table |
| `reindex --batch-size=50` | walked all 181 rows, created 79 stats rows |
| `regenerate-thumbnails --dry-run --only-missing` | found 68 images |
| `optimize-bulk --dry-run --limit=3` | 3 images |
| `backfill_ai --dry-run --limit=3` | 3 media |
| `relocalize-private --dry-run` | 2 non-public rows, both already clean |
| `cleanup-local --dry-run` | kept a local file whose cloud copy failed verification |
| `migrate-storage local→bunnycdn --dry-run` | 1 would move, 1 already there |
| `cloud-thumbs-backfill --dry-run` | 2 images |
| `generate-video-thumbnails` | stops at its ffmpeg guard — not installed here |

## 3. Query parity — PASS

Every query moved by the Rule 7 migration was diffed against its pre-migration SQL on live
data: 11 comparisons, all equal **after** fixing one that was not. `MediaTypes::ALL` had
been used to mean "no type filter" and silently omits `legacy_document`; the live table has
one such row, which is the only reason it was caught.

## 4. Journey suite — 4 of 34

The runner emits a runbook for per-journey agent execution (Playwright + curl + mysql per
step); it is not a single automated pass. One journey was run in full rather than sampling
several shallowly, chosen by blast radius:
`document-never-in-media-surface` (**critical**) — its own fail-diagnostics name "a caller
overriding `media_types`" as the cause of a document leak, and the Rule 7 work introduced
exactly such an override.

**Result: PASS** on every step run.

Three more followed, all of the security set, chosen because this cycle touched privacy
resolution (the `space` level) and every media query (Rule 7):

| Journey | Priority | Result |
|---|---|---|
| `private-media-local-and-gated` | critical | **PASS** |
| `anonymous-cannot-modify` | critical | **PASS** |
| `blocked-member-cannot-interact` | critical | **PASS** |

**`private-media-local-and-gated`** was run as a real upload, not an inspection of existing
rows: a fresh private image ingested through `UploadService::handle()` while **bunnycdn was
the active driver** kept its original and all three thumbnails on the local host, emitted no
`b-cdn.net` URL, and was invisible to another subscriber and to anonymous — in `can_view()`
and in both feeds.

**`blocked-member-cannot-interact`** is the 2.1.0 incident sentinel and the one that most
needed doing properly. Run over real HTTP with **Application Passwords for two distinct
identities** — never `wp_set_current_user()` twice in one process, which the journey names
as the harness limitation that let the original bug ship. **9/9 denials** (comment, reaction,
favourite, share, follow, open conversation, message, DM reaction, accept — all 403
`mvs_blocked`) and, just as important, **5/5 for the bystander control**: an uninvolved
member still gets 201/200 on the same five calls, so the gate discriminates rather than
refusing everyone. Credentials revoked and the setup password scrambled afterwards.

Full records: `audit/journey-runs/2026-08-15-1816/` (gitignored — run artifacts).

### A journey defect found and fixed

`anonymous-cannot-modify` steps 3 and 4 sent `{"emoji":...}` and `{"body":...}`. Neither is a
parameter of its route — they are `reaction_type` and `content`. WordPress validates required
params **before** running the permission callback, so the documented payloads returned **400
`rest_missing_callback_param`**, which satisfies a pass criterion of "non-2xx" while never
once exercising the auth gate those steps exist to prove. Corrected, and the pass criteria now
name specific codes rather than "non-2xx". With correct payloads both return 401
`mvs_unauthorized`.

That is the same failure shape as the decayed album fixture above, and the second instance of
it today: **a step that passes without testing anything.**

Two things worth carrying forward:

- **The album injection fixture had decayed again.** No `mvs_album_items` row pointed at the
  seed document, so step 5 — the journey's own "strongest check" — would have passed while
  proving nothing. Restored before asserting. Second decay of this fixture; see the
  journey's own 2026-08-09 incident note. Its Setup is load-bearing, not scaffolding.
- **Every non-document `/media/<slug>/` 301s to an activity permalink here, and that is
  correct.** It is `BuddyNext\Bridges\WPMediaVerseBridge::redirect_single_media` answering
  `mvs_single_media_redirect`. It also confirms the 2.4.0 signature change — passing the
  media type so a host can answer differently for a document than a photo — works live,
  since documents are exempted and photos are not. Consequence: the "a photo's back link
  still reads Explore" half of step 12 cannot be asserted on a BuddyNext site. It needs a
  standalone install.

## 5. What has NOT been run

Stated rather than implied by a green summary:

- **30 journeys, 11 of them critical** — upload, messaging reliability, signed-URL expiry,
  private-community gate, storage switch, activation-creates-pages, album privacy and
  pagination. This cycle's changes do not touch most of them directly; none have been
  re-proved either.
- **Combo browser smoke** (`qa/.last-smoke-pass.json`) — the release gate in
  `bin/build-release.sh` reads it and will refuse to package without a fresh green pass.
- **Plugin Check / WordPress.org packaging** on the built zip.
- **A real upgrade run** from 2.3.1 to 2.4.0 on a populated site — Migrator v29 stamps
  `drive_type`/`drive_id` on every row and has not been exercised as an upgrade this cycle.

## 6. Recommendation

2.4.0 is not ready to tag on this evidence. The gap is verification, not known defects:
nothing failed today. The four run today were chosen by exposure and all passed, including the two that would
have caught a privacy leak from this cycle's work. The shortest honest path to a tag is the
combo smoke, the remaining 11 critical journeys, and an upgrade rehearsal from 2.3.1.
