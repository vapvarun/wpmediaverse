# Cleanup Plan: Access-rules backend removal

**Date:** 2026-07-07
**Target version:** deprecate in a future 2.x minor, hard-remove in 3.0.0
**Owner:** Varun
**Reviewer:** _TBD — required before any code is removed_
**Status:** PENDING (tracking stub — do NOT execute yet)

---

## Context — what already happened in 2.0.0

The per-media access-rules **UX is already gone** as of 2.0.0:

- Member create/manage modal removed (shared-ui-frame.php / view.js) — members get simple
  Facebook-style privacy only.
- Admin access-rules screen retired (commit `b5d5dd1`): removed the "Access rules" row-action
  link, the `?view=access` dispatch, the write-handler call, and the private methods
  `MediaListPage::render_access()` / `handle_access_rule_actions()` / `redirect_to_access()`.
  Verified unreachable by link AND direct URL AND direct POST.
- `templates/admin/access-rules.php` flagged `@deprecated 2.0.0`, left as an orphaned dead file.

**ENFORCEMENT was intentionally left fully live** so the 50+ production sites that may hold
real rows in `mvs_access_rules` keep gating media — removing it would silently expose
restricted media (security regression) and touch schema in a non-major (Production Rule #4).

This plan covers the *remaining* backend, to be removed the right way across ≥2 majors.

---

## Goal

Remove the now-UX-orphaned access-rules backend: REST write surface, service write/builder
methods, the `mvs_access_rules` / `mvs_access_grants` tables, `manage_mvs_access` cap, and the
"custom (access rules)" privacy level. Fold any residual gating into standard privacy.
Reason: dead-code removal / API simplification after the 2.0.0 member-flow simplification.

---

## Pre-conditions (all MUST be checked before any code is removed)

- [ ] Inventory: `audit/cleanup/access-rules-inventory.json` lists every callsite
- [ ] Bridge check: `audit/cleanup/access-rules-bridges.json` — confirm Pro does NOT consume
      `access_rules` service key or `AccessController` routes (grep `free_services_consumed`)
- [ ] Usage proof: `audit/cleanup/access-rules-usage-proof.md` — how many live sites actually
      have rows in `mvs_access_rules`? If non-trivial, a one-way migration to standard privacy
      is required BEFORE the table is dropped
- [ ] Test coverage: each removed symbol has an existing or newly-added test
- [ ] Backward-compat strategy decided per symbol (below)
- [ ] Risk register filled below

---

## Symbols to remove

| Symbol | File | Type | Bridge? | Removal strategy |
|---|---|---|---|---|
| `AccessController` write routes (POST/PUT/DELETE) | `includes/REST/Controller/AccessController.php` | REST routes | verify | deprecate → hard-remove |
| `AccessController` `/access/options` | same | REST route | verify | deprecate → hard-remove |
| `AccessRulesService::add_rule()` / `delete_rule()` / `get_rules()` / `get_builder_options()` | `includes/Services/AccessRulesService.php` | methods | no | deprecate → hard-remove |
| `AccessRulesService::filter_privacy_can_view()` + `has_active_rules()` + prefetch | same | enforcement | no | **keep until table drop**; remove last |
| `mvs_access_rules`, `mvs_access_grants` | `includes/Core/Migrator.php` | tables | — | one-way migration + drop (major only) |
| `manage_mvs_access` | `includes/Capabilities/MediaCapabilities.php` | capability | no | deprecate → hard-remove (Production Rule #2) |
| "custom (access rules)" privacy level | privacy option lists | enum value | no | fold into standard privacy |
| `templates/admin/access-rules.php` | template | file | no | already `@deprecated 2.0.0`; delete in 3.0.0 |

---

## Phases

### Phase 1: Soft-remove with deprecation (a future 2.x minor)
- Mark REST write routes + service write/builder methods `@deprecated`, add `E_USER_DEPRECATED`
  triggers. Routes keep working (read the old path) per Production Rule #1.
- Ship the one-way "access rules → standard privacy" migration behind a WP-CLI command so site
  owners can retire their rules on their own schedule.
- Enforcement (`filter_privacy_can_view`) stays untouched.

### Phase 2: Hard-remove (3.0.0)
- Drop the write routes, service methods, cap, privacy level, template file.
- Run the migration in `Migrator` (major-only, documented one-way), then drop the two tables.
- Remove enforcement LAST, only after the tables are gone.
- Document under `Breaking changes` in the 3.0.0 changelog; 30-day advance notice.

---

## Verification gates

- [ ] `composer ci` green
- [ ] `bin/architecture-checks.sh` exits 0
- [ ] `audit/derived/cross-plugin-coupling.json` regenerated; no `consumed_by` becomes empty
- [ ] All journeys pass on Free-only AND Free+Pro
- [ ] Manual smoke: public / members / friends / only-me / (any residual custom-rule) media all
      resolve correctly after each phase
- [ ] Pro manifest regenerated; zero new orphan listeners

---

## Rollback plan

1. Revert commit SHA: _filled in after merge_
2. Re-enable the deprecation shim if hard-removed too early
3. Patch release + release-notes comms

---

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A live site has real `mvs_access_rules` rows; dropping the table exposes restricted media | medium | high | Enforcement stays until Phase 2 migration folds rules into standard privacy; drop table only after migration |
| Pro or a customer mu-plugin calls `AccessController` / `access_rules` | low | high | Bridge-check `free_services_consumed`; deprecate ≥1 major with shim |
| Customer WP-CLI script references `manage_mvs_access` | low | medium | Keep cap read for ≥2 majors; document ahead |
| Admin needs to clear a stale rule between 2.0.0 and Phase 1 (no GUI now) | medium | low | WP-CLI (`Commands.php`) + REST delete route still work |

---

## What this plan does NOT do

- Does not touch enforcement or the tables before the migration exists
- Does not remove anything in 2.0.0 beyond the already-shipped UX
- Does not rename or move files
- Does not change behavior for rules already stored (they keep gating until Phase 2)
