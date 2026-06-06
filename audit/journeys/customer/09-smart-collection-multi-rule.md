---
journey: smart-collection-multi-rule
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [collections, smart-rules, dashboard-view, basecamp-9962118482]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one published public image tagged with an mvs_tag term (seed demo data provides tag `nature` on media 4-10)"
estimated_runtime_minutes: 4
---

# Member creates a multi-rule smart collection and sees matching media

**Why this journey exists**: This is the regression test for Basecamp card 9962118482 ("Image Display Issue with Multiple Rules"). A smart collection combining a WHERE-type rule (media_type/privacy/author/date) with a JOIN-type rule (tag/category) always resolved to 0 items because `CollectionService::resolve()` collected `$wpdb->prepare()` params in rule order while the SQL interleaves all JOIN placeholders before WHERE placeholders — every value landed in the wrong placeholder. Two sibling defects shared the surface: `enrich_rule()` overwrote rule `value` with the term name in REST responses and the dashboard edit modal saved that name back (permanently corrupting the collection), and the demo seeder stored tag names instead of term IDs.

## Steps

### 1. Auto-login and open Collections tab
- **Action**: `playwright_navigate $SITE_URL/my-media/?autologin=journey_subscriber#collections`
- **Expect**: Collections tab active, "+ Create Collection" visible.

### 2. Create a multi-rule smart collection
- **Action**: Click "+ Create Collection"; Title `Journey Multi-Rule`; Type `Smart`; Rule 1 `Media Type = Image`; "+ Add Rule"; Rule 2 `Tag = nature`; Create.
- **Expect**: modal closes, card "Journey Multi-Rule" appears.

### 3. Card shows matches, not 0 (the core regression check)
- **Action**: read the card's item count and cover.
- **Expect**: item count > 0 (7 on seed data) AND the cover is a real media thumbnail `<img>`, not the 📚 placeholder. A count of 0 with matching media present means the prepare-param misalignment is back.

### 4. Stored rules hold term IDs
- **Action**: `mysql_query "SELECT meta_value FROM wp_postmeta WHERE post_id=<COLLECTION_ID> AND meta_key='_mvs_collection_rules'"`
- **Expect**: tag rule value is the numeric term ID (e.g. `"2"`), never the name.

### 5. Edit round-trip does not corrupt rules
- **Action**: click Edit on the card; confirm the Tag select shows `nature` pre-selected; click Save without changes; re-run the step-4 query.
- **Expect**: value still the numeric term ID; card count unchanged. A name (e.g. `"nature"`) here means `enrich_rule()` is overwriting `value` again instead of exposing `label`.

### 6. Legacy name-stored rules still resolve
- **Action**: GET `$SITE_URL/wp-json/mvs/v1/collections/<SEEDED_NATURE_HIGHLIGHTS_ID>?per_page=1` (seeded "Nature Highlights" stores `value: "nature"` on pre-1.6.0 sites).
- **Expect**: `total` > 0 — `resolve_term_id()` heals name/slug values without a migration.

### 7. Cleanup
- **Action**: delete `Journey Multi-Rule` via the card's Delete button, confirm in the mvsConfirm dialog.
- **Expect**: card removed.
