# WPMediaVerse — Core Paths (the 60–70%)

> **What nearly every owner uses, ranked.** QA walks these first, every cycle,
> as the named role, on a clean install. Bug priority follows this list (see
> `docs/standards/owner-questions.md` → Triage): a defect on a core path outranks
> one on an edge regardless of who reported it.
>
> **Seeded from evidence, confirmed by a human once.** Evidence used: the four
> pages activation creates (`/my-media/`, `/explore-media/`, `/upload-media/`,
> `/explore-document/`), the 14 shortcodes, the `priority: critical` journeys,
> the Basecamp columns, and the free/pro split. Re-confirm at each major release;
> the ledger will tell you when the ranking has drifted (`C-4`).

Last confirmed: **UNCONFIRMED — seeded 2026-09-09, needs the plugin owner's sign-off**

| # | Flow (owner's words) | Role | Surface | Why it is core (evidence) | Journey | Free/Pro |
|---|---|---|---|---|---|---|
| 1 | A member uploads a photo and it shows in Explore | subscriber | `/upload-media/` → `/explore-media/` | Activation creates both pages; journey `01` is critical; the whole product | customer/01 | Free |
| 2 | A member opens their own dashboard and sees what they uploaded | subscriber | `/my-media/` | Activation page; every other flow starts here | customer/* | Free |
| 3 | A visitor browses public media without logging in | anonymous | `/explore-media/`, single | Public feed is the acquisition surface | security/02 | Free |
| 4 | A member sets an item private and nobody else can open it | owner + other | single, REST | Privacy is the #1 trust promise; journey `05` critical | security/05 | Free |
| 5 | A member reacts / comments on someone else's media | member-other | single, lightbox | Social layer — the reason to pick this over the media library | customer/* | Free |
| 6 | An owner moderates: approve, reject, handle a report | administrator | wp-admin `mvs-moderation` (Reports is its `user-reports` tab; `mvs-reports` still resolves) | Owners must be able to police the community | admin/04 | Free |
| 7 | A member uploads a video and it plays with the plugin's player | subscriber | single, `[mvs_player]` | Video is the headline feature vs competitors | customer/* | Free |
| 8 | A member groups media into an album | subscriber | `album.php`, `[mvs_album]` | CPT created on activation; organisation is core | customer/* | Free |
| 9 | Documents: upload, share with a specific member, they can open it | owner + other | `/explore-document/`, `documents.php` | Activation page; `use_mvs_documents` granted to every role; recent hot-spot | security/07, 08 | Free (share: Pro) |
| 10 | Members message each other (DM) | owner + other | `messages.php` | Engine moved to Free 2026-03; social retention | customer/13 | Free |
| 11 | Owner changes a setting and the frontend reflects it (quota, privacy default, moderation on/off) | administrator | wp-admin `wpmediaverse` → frontend | 64 options; O-1/O-3 are the top owner complaints | admin/11 | Free |
| 12 | With BuddyPress: upload shows in the activity stream and profile tab | subscriber | BP activity, profile | The largest install base runs BP | customer/* | Free |
| 13 | Owner assigns a quota package and a member hits the limit honestly | admin + subscriber | Pro quota page → upload | Pro's first monetisation feature | pro | Pro |
| 14 | Storage switch (S3 / Bunny) — existing media keeps serving | administrator | Pro settings → frontend | Card #10029395885 history; road-block class when wrong | admin/08 | Pro |

## Edge (walk after core — welcome findings, never the starting point)

| Flow | Role | Surface | Why it is edge |
|---|---|---|---|
| Stories, smart collections, playlists | subscriber | templates | Used by a minority; no activation page |
| AI moderation / captions / transcoding | administrator | Pro | Needs external keys; most installs never enable |
| Scheduled publishing, comment edit window | subscriber | dashboard | Nice-to-have on top of flow 1 |
| Webhooks, Abilities API, CLI | developer | — | D-lens, not owner-lens |
| Migration from rtMedia / MediaPress / BuddyBoss | administrator | importer | One-time, per-site |

## Zero-config check (C-2)

The first thing a new owner would try, with **no settings touched**:
**activate → log in as a subscriber → `/upload-media/` → pick a JPEG → it appears
on `/explore-media/`** — must complete on a fresh activate.
