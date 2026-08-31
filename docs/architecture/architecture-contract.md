# WPMediaVerse Free ↔ Pro Architecture Contract

**Owners**: Free = `wpmediaverse`, Pro = `wpmediaverse-pro`. Both ship together but are independently activatable. Free MUST function with Pro absent. Pro MUST NOT crash the site if Free is missing - it should self-deactivate with an admin notice.

**Authority**: This contract is enforced by `bin/architecture-checks.sh` (Pro plugin) and consumed by both plugins' local-CI gate. Findings that violate the contract block release.

**Companion data** (both live in the Free plugin - Pro is deliberately doc-free):
- `audit/pro/manifests/manifest.json` - Pro's surface (REST, hooks, admin pages, free_filters_hooked, free_services_consumed)
- `audit/manifests/manifest.json` - Free's surface
- `bin/hook-manifest-drift.php` - compares both manifests against the code in both directions (fired-but-undocumented, documented-but-never-fired)

---

## Part A - The 11 invariants

### Invariant 1: Free has no runtime dependency on Pro

Free must function with `defined('MVS_PRO_VERSION') === false`. Free MAY check for Pro and conditionally render Pro-only UI affordances, but MUST NOT call `\WPMediaVersePro\…` classes or functions. Pro MAY check for Free and refuse to boot if `defined('MVS_VERSION') === false`.

**Enforcement**: `check_a1_no_pro_imports_in_free` - grep Free's `includes/` for `WPMediaVersePro\\` or `wpmediaverse-pro` references.

### Invariant 2: Pro waits for Free deterministically

Pro's bootstrap (`wpmediaverse-pro.php`) registers a callback on `do_action('mvs_loaded')`, which Free fires after its ServiceContainer is fully populated. Pro MUST NOT instantiate any class that consumes Free services before this hook fires. Pro's `Plugins-Required: WPMediaVerse` header (or activation guard) handles the must-have side; the runtime gate handles the timing side.

**Enforcement**: `check_a2_pro_boots_via_mvs_loaded` - confirm `add_action('mvs_loaded', ...)` exists in `wpmediaverse-pro.php`.

### Invariant 3: Pro extends Free via documented hooks only

No source modification, no monkey-patching, no reflection-based class swapping. The 5 documented extension patterns (entry hook, free_service, admin tab injection, storage drivers, AI providers - see CLAUDE.md §2) are the complete extension surface.

**Enforcement**: Manual review (no automated check; static analysis can't tell "documented" from "undocumented" hooks).

### Invariant 4: Free settings accessed only through `Plugin::free_service()`

Pro MUST NOT call `get_option('mvs_*')` directly except in three allow-listed cases: (a) feature toggles `mvs_*_enabled` Pro itself owns, (b) license-related Pro-owned options, (c) Pro-prefixed options. All other `mvs_*` reads go through a Free service with a typed accessor.

**Enforcement**: `check_a4_no_direct_get_option_for_free_settings` - grep Pro's `includes/` for `get_option\s*\(\s*['"]mvs_[a-z_]+` and exclude the allow-list.

### Invariant 5: REST namespace isolation

Free registers under `mvs/v1`; Pro registers under `mvs-pro/v1`. Separate namespaces, so a `methods × path` collision is structurally impossible - the check exists to catch a Pro controller that reaches back into `mvs/v1`.

**Enforcement**: `check_a5_no_route_collisions` - read both manifests' `rest.endpoints[].route`, normalize, assert pairwise disjoint per HTTP method.

### Invariant 6: DB boundary

Free owns 23 `mvs_*` tables; Pro owns 13. Cross-plugin writes go through the owner's manager class (e.g., Pro uses `MediaRepository::insert_media()` to add media rows, never raw `INSERT INTO wp_mvs_media_index`). Pro tables are documented in `audit/pro/manifests/manifest.tables.json` - note that only four of them carry an `mvs_pro_` prefix, so name alone does not tell you the owner.

**Enforcement**: `check_a6_pro_does_not_write_free_tables` - grep Pro's `includes/` for `INSERT INTO|UPDATE|DELETE FROM` against Free table names. Skip read queries - they're allowed.

### Invariant 7: AJAX action namespaces don't collide

Both plugins use the `mvs_` action prefix. The single-source-of-truth is the union of both manifests' `ajax[].action`. PR-time check ensures no Pro AJAX handler shadows a Free handler with the same action key.

**Enforcement**: `check_a7_no_ajax_action_collisions` - read both manifests' ajax[].action arrays, assert disjoint.

### Invariant 8: CPT ownership exclusive

Free registers `mvs_album` and `mvs_collection` CPTs. Pro registers no CPTs. Each plugin's `register_post_type()` calls live exclusively in the owning plugin.

**Enforcement**: `check_a8_pro_registers_no_cpts` - grep Pro's `includes/` for `register_post_type\s*\(\s*['"]mvs_`.

### Invariant 9: Custom capabilities have safe fallbacks

When Pro checks a Free-owned capability (e.g., `moderate_mvs_media`), it MUST also accept `manage_options` as a fallback so Pro features don't lock out admins on installs where the cap was never granted.

**Enforcement**: Manual review (pattern is `current_user_can(X) || current_user_can('manage_options')`).

### Invariant 10: Asset handle namespace separation

Free's wp_enqueue_* handles use the prefix `mvs-`. Pro's use `mvs-pro-`. Shared third-party libs (e.g., chart.js) are registered ONCE by the lower-numbered owner (Free if both ship the same lib). Pro's enqueue is conditional: `wp_script_is('mvs-chartjs', 'registered')` short-circuits a duplicate register.

**Enforcement**: `check_a10_pro_handles_prefixed` - grep Pro's `wp_enqueue_*` and `wp_register_*` calls; flag handles not starting with `mvs-pro-` (with exception list for known shared deps).

### Invariant 11: Hook arg-signature compatibility

For every Pro listener consuming a Free hook (cross-referenced from the two `manifest.hooks.json` files), Pro's `add_action`/`add_filter` `$accepted_args` and the listener function signature MUST match Free's firer's `args_signature` declared in Free's `hooks_fired[]`. Mismatch examples: Free fires `(int $post_id, WP_REST_Request $request)`, Pro listener registered with `$accepted_args=2` and treats arg 2 as `array` → fatal `TypeError`.

**Enforcement**: `check_a11_hook_signatures_match` - read both manifests' hooks_fired and Pro's add_action/add_filter call sites; compare arg counts; flag drift to `static_analysis.hook_signature_drift[]`.

---

## Part B - Security

- License logic lives in Pro only - `includes/License/License.php` (read-only status wrapper over the EDD SL SDK options) and `includes/Core/LicenseManager.php` (the activate/deactivate form and settings tab). Free has none.
- The licence is an **updates gate, not a feature gate**: `License::is_valid()` drives the settings badge and the update channel, never feature registration. The single exception is `Documents\DocumentLicense`, which refuses document WRITES on a lapsed licence while leaving reads, route registration and admin-capability holders untouched. Do not add a second one.
- Signed-URL signing key MUST NOT be exposed to Pro - Pro requests signed URLs through Free's `SignedUrlService::sign($media_id)` API.
- Nonces created in Pro with `wp_create_nonce('mvs_pro_*')` MUST be verified with the same action name; never share nonces across plugin boundaries.

## Part C - Performance

- Pro features that hook into hot paths (`mvs_media_response`, `mvs_thumbnail_sizes`) MUST short-circuit early when their feature toggle is OFF. Pattern: `if (!$this->enabled) return $original;`.
- Pro MUST NOT add cron events to existing Free schedules; use a Pro-prefixed schedule (`mvs_pro_*`).

## Part D - i18n

- Free text domain: `wpmediaverse`. Pro text domain: `wpmediaverse-pro`. Strings stay in their plugin's domain.
- Pro MUST NOT register translations on Free's domain.

## Part E - Compatibility

- Both plugins target PHP ≥ 7.4 and WP ≥ 6.5. Any version bump in one MUST be coordinated with the other. The lower of the two governs the tested matrix.

## Part F - Lifecycle

- On Pro deactivation, Free continues to function. Pro features become unavailable; data in Pro tables remains untouched (no destructive uninstall).
- On Pro uninstall (delete from WP admin), Pro tables MAY be dropped per uninstall.php - but only Pro-owned tables.
- On Free deactivation while Pro is active, Pro MUST self-deactivate via the activation guard; show admin notice "WPMediaVerse Pro requires WPMediaVerse to be active."

## Part G - PR-time checklist

Before merging a PR that touches the Free/Pro boundary:

- [ ] `bin/architecture-checks.sh` exits 0
- [ ] If a new Free hook is added: PR updates Free's `audit/manifests/manifest.hooks.json` with `args_signature`; `php bin/hook-manifest-drift.php` exits 0
- [ ] If a new Pro listener is added against an existing Free hook: arg-count and shape match Free's firer
- [ ] If a new shared asset (JS/CSS) is added: handle prefix verified (Pro = `mvs-pro-`); duplicate-register guard added
- [ ] If a new REST route is added: `methods × path` doesn't collide with the other plugin's manifest entries

---

## Skipped invariants (do not apply to ServiceContainer DI)

None. All 11 invariants apply. Specifically NOT skipped despite the briefing's mention: invariants 3 and 9 use manual review (no automated check) but they're applicable; the script skips automation, not enforcement.

The skill's Phase 2.5.11–2.5.14 (adapter/registry undefined-call detection, registry bypasses, suppressed baseline inventory, registry strategy classification) ARE skipped - Pro's Quota module has an `Adapters/` directory but those are integration shims (MemberPress, PMP, WC), not registry-pattern slots. There's no `Adapter` interface that abstracts a swappable implementation in WPMediaVerse Pro.
