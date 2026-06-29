# WPMediaVerse — First-Session Activation Spec (cold-start / "30-second" plan)

Status: **Phase A in implementation** (Free plugin). Branch `1.8.1`.
Goal: a brand-new user, within ~30 seconds and **before posting anything**, must (1) see great, relevant content, (2) find people to follow, (3) like something in one tap. The empty-network cold start is the #1 mass-adoption killer; this spec closes it.

## The journey → backend contract

```
Signup → Interests picker   (GET /app/interests → POST /me/interests)
       → "Follow a few"      (GET /users/suggested → tap-follow ≥3)
       → Home feed           (GET /media?orderby=trending&interests=auto)   ← full + relevant, never empty
       → double-tap to like  (POST /media/{id}/reactions — existing, optimistic via viewer fields)
       → POST /me/onboarding/complete
```

| Beat | Endpoint | Status |
|---|---|---|
| See great content | `GET /media?orderby=trending` (time-decayed score, already built) + `interests=auto` filter | ✅ exists / 🔨 add filter |
| Find someone to follow | `GET /users/suggested` | 🔨 new |
| Like with one tap | `POST /media/{id}/reactions`, viewer fields for optimistic UI | ✅ exists |
| Seed relevance | `GET /app/interests`, `GET|POST /me/interests` | 🔨 new |
| Show picker once | `onboarded` on `/me/profile` + `POST /me/onboarding/complete` | 🔨 new |

## Reuse decisions (no new schema)
- **Interests = the existing `mvs_category` taxonomy.** No new taxonomy. User picks categories; stored as user-meta `mvs_interests` (array of term IDs).
- **Trending = the existing `?orderby=trending`** query (`(reactions*3+comments*5+views)/age_hours^1.5`). Add an optional `interests=auto` join filtering to the viewer's interest categories.
- **Suggested-follows ranking from `mvs_follows` + engagement**, boosted by interest overlap. No new tables.
- **Onboarding state = user-meta `mvs_onboarded`.**

---

## Phase A — endpoints (this implementation)

### A1. Interests — `InterestsController` (Free, `mvs/v1`)
- `GET /mvs/v1/app/interests` — **public**. Top `mvs_category` terms as chips: `{ id, name, slug, count, cover_url }` (`cover_url` = one popular thumbnail from that category; cached). Ordered by `count` desc. Cached transient (`mvs_app_interests`, ~1h).
- `GET /mvs/v1/me/interests` — **auth**. `{ interest_ids: [int] }`.
- `POST /mvs/v1/me/interests` `{ interest_ids: [int] }` — **auth**. Validates IDs are real `mvs_category` terms; stores user-meta `mvs_interests`. Returns the saved set.

### A2. Suggested follows — `SuggestionService` + `GET /mvs/v1/users/suggested`
- **auth** (needs the viewer's graph to exclude). Returns up to `limit` (default 20, max 50) cards:
  `{ id, name, avatar, profile_url, follower_count, is_following: false, sample_media: [thumb,thumb,thumb] }`.
- **Ranking:** candidate pool = creators ranked by `follower_count` (COUNT over `mvs_follows.following_id`) + recent engagement; **boosted** when their media's categories overlap the viewer's interests; **excluded**: self, already-followed (`get_following_ids`), blocked.
- **Scale:** `SuggestionService` caches the global top-creators candidate list in a transient (`mvs_top_creators`, ~1h, ~200 rows); per request it just filters out the viewer's follows/blocks and applies the interest boost on that small set. No per-request full-table scan.

### A3. Onboarding flag — `ProfileController`
- Add `onboarded` (bool) to the `GET /me/profile` response.
- `POST /mvs/v1/me/onboarding/complete` — **auth**. Sets user-meta `mvs_onboarded = 1`. (Also set on first `POST /me/interests` so it's resilient.)
- App shows the interests→follow flow only while `onboarded` is false.

### A4. Interest-aware trending — `MediaController::get_items`
- New param `interests` (`auto` | omitted). When `interests=auto` **and** the viewer is logged in **and** has saved interests, add an `INNER JOIN term_relationships … term_taxonomy_id IN (<viewer interest tt_ids>)` so the feed is relevant, not just globally popular. Applies across `date|trending|popular` orderings (it modifies the shared WHERE/JOIN). Falls back to unfiltered trending when the viewer has no interests (so it's never empty).

## Stability / scale (the proven patterns)
- All aggregates (`/app/interests` covers, `/users/suggested` top-creators) are **cached transients** with bounded queries — same approach as the leaderboard cache.
- Suggested-follows never scans all users per request: cache the candidate pool, filter the viewer's graph (small set) live.
- All list responses carry **viewer fields** (canonical media / `is_following:false`) so the app does optimistic UI (instant like/follow, no spinner).
- Public reads where sensible (`/app/interests`, trending); `/users/suggested` + `/me/interests` are auth.

## Acceptance
- A logged-out user gets a full `/media?orderby=trending` feed (already true).
- A new user: `GET /app/interests` → `POST /me/interests` → `GET /users/suggested` returns real people (excluding self) → `GET /media?orderby=trending&interests=auto` returns interest-relevant media with `is_favorited`/`viewer_reaction` → one-tap like works → `POST /me/onboarding/complete` flips `onboarded`.
- 10k-user / 100k-media safe: no unbounded scans; caches in place.

## Phase B (app — not this implementation)
Onboarding screens (interest chips, "follow a few", home defaulting to trending), optimistic like/follow, "Suggested for you" inline rail when the network is thin, find-friends (contacts/social) later.

## Out of scope (per product scope)
In-app photo editing / filters (metadata-only Edit modal stands). Personalized ML ranking beyond the trending score + interest filter is a later roadmap item.
