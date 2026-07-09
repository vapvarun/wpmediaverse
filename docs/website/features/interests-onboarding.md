# Interests & Personalized Onboarding

> **Included in Free** - This feature is available in the free version of WPMediaVerse.


New members pick a handful of interests, get a ranked list of creators to follow, and see a feed that leans toward what they picked - instead of landing on an empty explore page with nobody to follow and nothing personalized to see.

## What You Can Do

- Choose interests from the site's existing media categories (`mvs_category` taxonomy) during first-session activation
- See a ranked "people you may know" list, boosted toward creators who post in your chosen interests
- Request an interest-aware media feed that favors your picks over a plain chronological/popular feed
- Skip the picker entirely and mark onboarding done without choosing anything

## How It Works

1. On first login, a client shows the available interest chips - up to 40 of the site's most-used categories, each with a cover photo and post count
2. The member taps the interests they care about and saves
3. The site immediately has a ranked follow-suggestion list ready, weighted toward creators active in those interests
4. Requesting the feed with the interest-aware flag on returns media favoring the member's saved interests first

Today this ships as a REST API surface (built for the official MediaVerse app and for headless/custom frontends) rather than a bundled on-page picker screen in the default web templates - any client that speaks REST can build the picker UI on top of it.

## For Site Owners

Nothing to configure - interests reuse the categories you already use for tagging media (`mvs_category`), so there's no separate taxonomy to maintain. The candidate pool for follow suggestions and the public interest list are both cached (default 1 hour) so this stays cheap on busy sites.

## REST API

**Base URL:** `/wp-json/mvs/v1/`

### GET /app/interests

Public (no authentication). Returns the available interest chips, ranked by post count.

**Response:**

```json
[
  { "id": 12, "name": "Landscape", "slug": "landscape", "count": 340, "cover_url": "https://yoursite.com/..." }
]
```

Up to 40 categories. Cache TTL is filterable via `mvs_app_interests_cache_ttl` (default 1 hour, seconds; `0` disables caching).

---

### GET /me/interests

Requires a logged-in user. Returns the current user's saved interest IDs.

**Response:**

```json
{ "interest_ids": [12, 45] }
```

---

### POST /me/interests

Requires a logged-in user. Save the member's interest picks.

**Body:**

```json
{ "interest_ids": [12, 45] }
```

Only real `mvs_category` term IDs are kept; anything else is silently dropped. Saving also marks the member as onboarded (see below).

---

### POST /me/onboarding/complete

Requires a logged-in user. Flags the viewer as onboarded without requiring them to pick any interests - the "skip" path.

**Response:**

```json
{ "onboarded": true }
```

The `onboarded` flag is also surfaced on the member's own profile response so a client only shows the activation flow once.

---

### GET /users/suggested

Requires a logged-in user (anonymous requests fall back to pure popularity ranking). Returns "people you may know" - creators ranked by follower count, boosted toward creators posting in the viewer's saved interests, excluding the viewer, anyone already followed, and anyone blocked.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | `20` | Maximum suggestions (1-50) |

Candidate pool cache TTL is filterable via `mvs_suggestions_cache_ttl` (default 1 hour, seconds; `0` disables caching).

---

### Interest-aware media feed

Pass `interests=auto` on the media listing endpoint (`GET /mvs/v1/media`) to narrow results toward the logged-in viewer's saved interest categories. Requires a logged-in user with at least one saved interest; anonymous viewers and members with no saved interests are unaffected (the feed never comes back empty because of this parameter).

## Related

- [Social Features](social-features.md) - follow/unfollow and the explore feed's `scope` parameter
- [Native App Readiness](../getting-started/whats-new-1-9-0.md) - interests shipped as part of the 1.9.0 native-app-readiness push
