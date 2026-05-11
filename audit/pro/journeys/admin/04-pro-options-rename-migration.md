---
journey: pro-options-rename-migration
plugin: wpmediaverse-pro
priority: critical
roles: [administrator]
covers: [a4-namespace-isolation, options-renamed-v1, migrator-idempotency]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse Pro v1.2.0+ active"
  - "wp-cli available"
estimated_runtime_minutes: 3
---

# Pro options rename to mvs_pro_* prefix migrates legacy values (Item 2)

**Why this journey exists**: Pre-1.2.0 Pro stored several site options under
the `mvs_*` prefix shared with Free, violating architecture invariant A4
(option namespace isolation between Free and Pro). 1.2.0 renames six
options to the `mvs_pro_*` prefix and ships a one-time activation
migration in `Core\Migrator::migrate_renamed_options_v1()`. This journey
asserts that:

1. A legacy value set under the old key is preserved under the new key.
2. The legacy key is then deleted (so Pro deactivation cleans cleanly).
3. The migration is idempotent (running it twice does not overwrite a
   value the user changed via the renamed admin field after upgrade).
4. The guard flag `mvs_pro_options_renamed_v1` blocks re-runs.

## Scope of the rename

| Old (legacy `mvs_*`) | New (`mvs_pro_*`) |
|---|---|
| `mvs_boost_cost_per_100` | `mvs_pro_boost_cost_per_100` |
| `mvs_boost_max_impressions` | `mvs_pro_boost_max_impressions` |
| `mvs_boost_expiry_days` | `mvs_pro_boost_expiry_days` |
| `mvs_connector_flickr_app_key` | `mvs_pro_connector_flickr_app_key` |
| `mvs_connector_flickr_app_secret` | `mvs_pro_connector_flickr_app_secret` |
| `mvs_streak_freeze_cost` | `mvs_pro_streak_freeze_cost` |

## Setup

- Site: `$SITE_URL`
- DB: any current state.

## Steps

### 1. Reset migration flag and seed a legacy value
```bash
wp option delete mvs_pro_options_renamed_v1
wp option delete mvs_pro_boost_cost_per_100
wp option update mvs_boost_cost_per_100 75
```

### 2. Trigger the migration
```bash
wp eval '\WPMediaVersePro\Core\Migrator::run();'
```

### 3. Confirm value moved
```bash
wp option get mvs_pro_boost_cost_per_100   # → 75
wp option get mvs_boost_cost_per_100        # → "Could not get 'mvs_boost_cost_per_100' option. Does it exist?"
wp option get mvs_pro_options_renamed_v1   # → 1
```

### 4. Confirm idempotency — running again does not overwrite a newer value
```bash
wp option update mvs_pro_boost_cost_per_100 999   # admin updates new key
wp option update mvs_boost_cost_per_100 1         # something seeds the old key again
wp eval '\WPMediaVersePro\Core\Migrator::run();'  # re-run migration
wp option get mvs_pro_boost_cost_per_100          # → 999 (NOT 1 — flag short-circuits)
```

### 5. Reset flag and confirm "don't overwrite an existing new value" branch
```bash
wp option delete mvs_pro_options_renamed_v1
wp option update mvs_pro_boost_cost_per_100 999
wp option update mvs_boost_cost_per_100 1
wp eval '\WPMediaVersePro\Core\Migrator::run();'
wp option get mvs_pro_boost_cost_per_100   # → 999 (preserved — admin's intent newer)
wp option get mvs_boost_cost_per_100        # → not found (legacy still cleaned)
```

## Pass criteria

ALL of the following hold:

1. After step 3, `mvs_pro_boost_cost_per_100` equals the legacy value `75`.
2. After step 3, the legacy `mvs_boost_cost_per_100` is gone.
3. After step 3, `mvs_pro_options_renamed_v1` equals `1`.
4. Step 4 leaves `mvs_pro_boost_cost_per_100` at `999` (flag prevents re-run).
5. Step 5 leaves `mvs_pro_boost_cost_per_100` at `999` AND clears the legacy key
   (the "don't overwrite a newer value" branch fired).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| New key empty after migration | `update_option(`/`get_option(` typo or wrong key in map | `includes/Core/Migrator.php::RENAMED_OPTIONS_V1` |
| Legacy key still present after migration | `delete_option()` skipped (early-return on null check too aggressive) | `includes/Core/Migrator.php::migrate_renamed_options_v1` |
| Migration runs every page load | Flag not set after migration runs | check `update_option( self::OPTIONS_RENAMED_FLAG_V1, 1, false );` |
| Newer admin-saved value got clobbered | Map-iteration overwrites unconditionally | check the `null === $new_value || false === $new_value` guard |
