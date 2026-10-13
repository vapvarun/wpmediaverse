---
journey: bp-group-media-scope
plugin: wpmediaverse
priority: high
roles: [subscriber, other-member, anonymous]
covers: [bp-group-media-scope]
prerequisites:
  - "BuddyPress active with the Groups component (on the Docker site, enable it for the run, then disable)"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 8
---

# A group's Media tab and GET /media?group= list only that group (Basecamp 10398572501)

One rule decides a group's media list: `MediaRepository::group_scope_clause()`. The item's
`group_id` meta, the listing's privacy rule, plus `group` items for members of that group. A
private or hidden group lists nothing for non-members (BuddyPress moderators excepted). The group
tab (`GroupTabIntegration::fetch_media_ids()` through `query()`), its Load More
(`?group=<id>&group_covers=1`) and the app all use it.

## Steps

### 1. Public group, more than one page (Member A)
- **Setup**: a public group with 13+ items from Member A (or set `mvs_items_per_page` low for the
  run), one of them shared with the group only, and one gallery.
- **Action**: as Member A (a member) open the group's Media tab; press Load More until it ends.
- **Expect**: every item of the group once, the group-only item included, nothing from outside the
  group; the gallery is one tile with a badge; Load More requests carry `group=<id>`.

### 2. Visitor and non-member
- **Action**: the same tab, and `GET /mvs/v1/media?group=<id>`, logged out and as Member B (not a member).
- **Expect**: the group's items except the group-only one; nothing from outside the group.

### 3. Private group
- **Action**: a private group holding one public item; `GET /mvs/v1/media?group=<id>` as the visitor,
  as Member B and as a member.
- **Expect**: visitor and Member B get an empty list; the member gets the item.

### 4. Restore
- Remove the test group_id tags and groups, reset the page size, turn Groups off again if it was off.

## Pass criteria
- Steps 1-3 hold. **Fails on 2.6.1 / 8d86e49e**: Load More appends site-wide media, and the REST
  route has no group filter.

## Fail diagnostics
- Outsiders on page 2: the Load More button lacks `data-group` (`GroupTabIntegration::load_more_data_attrs()`)
  or `assets/js/frontend/load-more.js` does not send `group`.
- A private group's items reach a non-member: the visibility gate in `group_scope_clause()`.
