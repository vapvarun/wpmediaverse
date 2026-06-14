---
journey: grid-render-query-budget
plugin: wpmediaverse
priority: high
roles: [anonymous]
covers: [n-plus-one, prefetch, request-cache, access-rules-cache, shared-host-perf]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Explore page with >= 12 public media items"
estimated_runtime_minutes: 3
---

# A grid page renders within a tight DB-query budget (no N+1)

**Why this journey exists**: The server-rendered media grid did ~14 DB queries per tile (170 for a 12-tile page) — a severe N+1 that dominated PHP render time on shared hosting. 1.7.0 batches the page via `MediaRepository::prefetch()` + `AccessRulesService::prefetch_active_rules()`, makes `get_all()`/`exists()` cache-aware, adds `get_raw()` Tier 1b (absent-key short-circuit after prefetch), and reorders `filter_privacy_can_view()` to skip the per-tile `get_post()` for rule-less media. Result: ~170 → ~6 queries/12-tile page. This journey is the regression guard — if a future change reintroduces a per-tile query, the count blows past the budget.

## Steps

### 1. Measure the render-loop query count (anonymous = worst case)
- **Action**:
  ```bash
  wp eval '
  wp_set_current_user(0);
  $th=\WPMediaVerse\Core\Plugin::container()->get("template_helpers");
  $repo=\WPMediaVerse\Core\Plugin::container()->get("media_repository");
  $ar=\WPMediaVerse\Core\Plugin::container()->get("access_rules");
  $wpdb=$GLOBALS["wpdb"];
  $ids=array_map("intval",$wpdb->get_col("SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE privacy=\"public\" AND status=\"publish\" ORDER BY media_id DESC LIMIT 12"));
  $q0=$wpdb->num_queries;
  $repo->prefetch($ids); $ar->prefetch_active_rules($ids);
  ob_start(); foreach($ids as $id){ $th->render_grid_item($id, [], ["show_author"=>true]); } ob_end_clean();
  echo "queries=".($wpdb->num_queries-$q0)."\n";
  '
  ```
- **Expect**: `queries` <= 12 for a 12-tile page (target ~6). A result near 170 (≈14/tile) means the prefetch/cache path regressed.

### 2. Templates prime the cache before their loops
- **Action**: grep the render paths.
  ```bash
  grep -l "prefetch_active_rules" templates/explore.php templates/album.php templates/collection.php src/blocks/media-grid/render.php src/blocks/explore-feed/render.php
  ```
- **Expect**: all five files prime both `prefetch()` and `prefetch_active_rules()` before their render loop.

### 3. No visual / access regression
- **Action**: `playwright_navigate $SITE_URL/explore-media/`; check images load and access is unchanged.
- **Expect**: 0 broken images, 0 empty video posters; public visible to anon; private/gated NOT visible to anon (see journey 14 / access-rules tests).
