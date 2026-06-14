---
journey: media-update-categories-persist
plugin: wpmediaverse
priority: high
roles: [administrator, author]
covers: [rest-update, taxonomy-sync, category-meta, contract-saved-but-applied]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one media item the test user can edit (can_edit:true)"
  - "At least one mvs_category term exists"
estimated_runtime_minutes: 3
---

# Editing a media item's categories actually persists them

**Why this journey exists**: `POST /mvs/v1/media/{id}` with `{categories:[id]}` used to return HTTP 200 while the category vanished on read-back for a subset of items (the "saved but not applied" contract bug). Root cause: `update_item()` re-read the freshly-written terms with `get_the_terms()` and, when a persistent-object-cache shard transiently missed, took a destructive else-branch that wiped the cached `category` meta to `[]`. The fix derives the cached names from the submitted term IDs instead of a re-read. This journey is the regression guard. (1.7.0, Basecamp card "Media update endpoint accepts categories but silently drops it".)

## Steps

### 1. Capture a term + an editable media id
- **Action**: `wp eval '$t=get_terms(["taxonomy"=>"mvs_category","hide_empty"=>false,"number"=>1]); echo $t[0]->term_id." ".$t[0]->name;'`
- **Action**: pick a `MEDIA_ID` whose author is the test user (or run as admin).
- **Expect**: a valid `TERM_ID` + `TERM_NAME`.

### 2. POST the category
- **Action**: `curl -X POST -H 'Content-Type: application/json' -H 'X-WP-Nonce: $NONCE' -d '{"categories":[$TERM_ID]}' $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID`
- **Expect**: HTTP 200.

### 3. Read it back
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID`
- **Expect**: `categories` array contains `TERM_NAME` (NOT `[]`).

### 4. Read it back again (cache-stability check)
- **Action**: repeat step 3.
- **Expect**: `categories` still contains `TERM_NAME` — stable across reads.

### 5. Clear categories
- **Action**: `curl -X POST -H 'Content-Type: application/json' -H 'X-WP-Nonce: $NONCE' -d '{"categories":[]}' $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID`
- **Expect**: HTTP 200, then GET shows `categories: []` (intentional clear still works).

### 6. OPTIONS documents the contract
- **Action**: `curl -X OPTIONS $SITE_URL/wp-json/mvs/v1/media/$MEDIA_ID`
- **Expect**: the EDITABLE endpoint's `args` now list `categories`, `tags`, `title`, `description`, `slug`, `privacy`, `allow_download` (previously only `id`).
