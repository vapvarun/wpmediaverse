# Local Testing & Pre-Push Checklist (Pro)

Every change that touches PHP, composer, or the build pipeline must pass the checks below before it is pushed. `phpcs`, `phpstan`, and `phpunit` being green **does not** prove the plugin activates - Pro #9788342062 on 2026-04-15 was a fatal-on-activation bug that shipped past all three green gates.

The Free plugin has the canonical version of this document at `docs/development/LOCAL_TESTING.md` (this same `docs/` tree - Pro is intentionally doc-free). Read that first. This file only covers the Pro-specific additions.

---

## 1. Pre-push smoke test (mandatory)

Run this before every `git push`:

```bash
# From the WordPress install root:
rm -f wp-content/debug.log
wp plugin deactivate wpmediaverse-pro wpmediaverse
wp plugin activate wpmediaverse wpmediaverse-pro
grep -E "Fatal|Uncaught|deep-copy|myclabs|Class .* not found" wp-content/debug.log
```

Pro must be activated **after** Free, since Pro bootstraps inside the `mvs_loaded` action that Free fires. Activating Pro first gives you a silent no-op, not a fatal, so the order matters for accurate testing.

If you don't have WP-CLI, use the admin **Plugins** page in the same order: deactivate Pro → deactivate Free → activate Free → activate Pro.

---

## 2. Fresh-clone fatal check (required if the change touches `wpmediaverse-pro.php`, `libs/`, or any class the autoloader references)

```bash
cd /tmp
rm -rf mvs-pro-verify
git clone -b <your-branch> <repo-url> mvs-pro-verify
cd mvs-pro-verify
php -l wpmediaverse-pro.php
find includes/ templates/ -name '*.php' -exec php -l {} +
```

Then activate that clone alongside Free and confirm no fatal. A clean clone has no `vendor/` at all, which is exactly the state a customer ZIP is in. This is the check that would have caught #9788342062. Run it before marking any PR ready.

---

## 3. Pro-specific vendor rules

Pro follows the same rule as Free, and for the same reason: **the runtime never loads Composer.**

- `wpmediaverse-pro.php` registers a hand-written `spl_autoload_register` for `WPMediaVersePro\`.
- Runtime dependencies (Action Scheduler, the EDD SL SDK) are committed under `libs/` and `require_once`d directly.
- `/vendor/` is gitignored and excluded from the release ZIP (`/vendor` in `.distignore`). It carries dev and build tooling only.

So a Pro dev worktree needs `composer install` after a fresh clone to get phpunit/phpcs/phpstan, and nothing under `vendor/` is ever committed.

### The 2026-04-15 fatal - cautionary tale

The fatal happened because the committed `vendor/composer/autoload_static.php` still contained 441 dev-dep class references pointing to `myclabs/deep-copy/…`, `phpunit/phpunit/…`, `yoast/phpunit-polyfills/…` - files that were never committed. On a fresh clone composer autoload tried to `require` them and died.

Root cause: at some point in Pro history, someone ran `composer install` (dev + prod), composer regenerated `autoload_static.php` with dev refs, and that file got committed. Every fresh clone after that hit a fatal.

The immediate fix regenerated the autoload files with `--no-dev`. The **structural** fix, which is the state of the code now, was to stop shipping a Composer autoloader at all: `vendor/` was gitignored, the runtime deps moved to `libs/`, and the boot path switched to the hand-written autoloader. That removed the failure mode rather than re-tuning it, so the steps below are the current rule, not the 2026-04 workaround.

### Rule: adding or bumping a dependency in Pro

- **Runtime dependency** (needed on a customer site): it goes in `libs/`, committed, and is `require_once`d explicitly from `wpmediaverse-pro.php`. It must not rely on Composer's autoloader, which is not present in the ZIP.
- **Dev/build dependency** (phpunit, phpcs, phpstan): `composer require --dev`, commit `composer.json` + `composer.lock` only. Never stage anything under `vendor/`.
- Run the fresh-clone check in §2 either way.

---

## 4. Dist ZIP build verification

Pro's release ZIP is built via the same Gruntfile pattern as Free:

```bash
cd wp-content/plugins/wpmediaverse-pro
npx grunt dist
```

This builds assets, copies files, and compresses to `dist/wpmediaverse-pro-{version}.zip`. `vendor/`, `docs/`, `tests/`, and `bin/` are excluded; `libs/` ships.

Test the resulting ZIP by extracting it outside the plugin directory and copy-installing into a fresh WordPress install - it should activate clean with Free already active.

---

## 5. Pro-specific activation testing

Pro extends Free via the `mvs_loaded` action (see `docs/architecture/architecture-contract.md`, Invariant 2). This introduces three failure modes that are unique to Pro and must be tested whenever you touch `wpmediaverse-pro.php`, `includes/Core/Plugin.php`, or any service container registration:

| Scenario | Expected behavior |
|---|---|
| Pro active, Free inactive | Pro boots silently - no fatal, no admin notice. Pro code only runs when `mvs_loaded` fires. |
| Free active, Pro active | Normal full-feature operation. |
| Deactivate Free while Pro is active | Pro stops receiving the `mvs_loaded` hook - no fatal, no ghost code. |
| Reactivate Free (Pro still active) | Pro comes back online on the next page load - no manual reactivation needed. |

Test all four by running the WP-CLI sequence:

```bash
wp plugin deactivate wpmediaverse-pro wpmediaverse
wp plugin activate wpmediaverse-pro                   # Free inactive - must not fatal
wp plugin activate wpmediaverse                       # Free comes online
wp plugin deactivate wpmediaverse                     # Free goes away - Pro must not fatal
wp plugin activate wpmediaverse                       # Free comes back - Pro must re-attach
tail wp-content/debug.log                             # zero Fatal/Uncaught at any step
```

---

## 6. Checklist summary

Copy this into your PR description:

```
- [ ] Pre-push smoke test passed (§1): deactivate/reactivate in correct order, debug.log clean
- [ ] Fresh-clone fatal check passed (§2): clean clone lints and activates with no `vendor/` present
- [ ] No `vendor/` changes staged - it is gitignored; runtime deps belong in `libs/` (§3)
- [ ] Pro-specific activation sequence tested (§5) - all four Pro/Free state transitions clean
- [ ] `composer run phpcs` clean (or existing baseline unchanged)
- [ ] `composer run phpstan` clean (or existing baseline unchanged)
- [ ] `./vendor/bin/phpunit` green
- [ ] Browser-verified every feature touched on real pages at 1440px and 390px
```
