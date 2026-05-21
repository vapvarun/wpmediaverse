# Local Testing & Pre-Push Checklist (Pro)

Every change that touches PHP, composer, or the build pipeline must pass the checks below before it is pushed. `phpcs`, `phpstan`, and `phpunit` being green **does not** prove the plugin activates - Pro #9788342062 on 2026-04-15 was a fatal-on-activation bug that shipped past all three green gates.

The Free plugin has the canonical version of this document at `../wpmediaverse/docs/LOCAL_TESTING.md`. Read that first. This file only covers the Pro-specific additions.

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

## 2. Fresh-clone fatal check (required if the change touches `vendor/`, `composer.json`, `wpmediaverse-pro.php`, or any class the autoloader references)

```bash
cd /tmp
rm -rf mvs-pro-verify
git clone -b <your-branch> <repo-url> mvs-pro-verify
cd mvs-pro-verify
php -r 'require "vendor/autoload.php"; echo "autoload OK\n";'
```

This is the check that would have caught #9788342062 in five seconds. Run it before marking any PR ready.

---

## 3. Pro-specific vendor rules

Pro's `vendor/` directory is **much smaller** than Free's because Pro does **not** commit dev-dependency files. Only `vendor/composer/*`, `vendor/easy-digital-downloads/edd-sl-sdk/`, and `vendor/woocommerce/action-scheduler/` are tracked in git.

This means a Pro dev worktree always needs `composer install` to populate dev deps (phpunit, phpcs, phpstan) after a fresh clone, but those files are gitignored so they cannot be committed by accident.

### The 2026-04-15 fatal - cautionary tale

The fatal happened because the committed `vendor/composer/autoload_static.php` still contained 441 dev-dep class references pointing to `myclabs/deep-copy/…`, `phpunit/phpunit/…`, `yoast/phpunit-polyfills/…` - files that were never committed. On a fresh clone composer autoload tried to `require` them and died.

Root cause: at some point in Pro history, someone ran `composer install` (dev + prod), composer regenerated `autoload_static.php` with dev refs, and that file got committed. Every fresh clone after that hit a fatal.

**The fix (commit `c20a6c7`) ran `composer dump-autoload --no-dev --classmap-authoritative`** and committed only the regenerated autoload files - dropping dev refs from 441 to 0.

### Rule: for any composer/autoload change in Pro

1. Make the change (e.g. bump a prod dep in `composer.json`).
2. Run `composer update <vendor/package>` (with dev deps installed - the default).
3. **Before staging**, run:
   ```bash
   composer dump-autoload --no-dev --classmap-authoritative
   ```
   This regenerates `autoload_static.php` / `autoload_classmap.php` / etc. without any dev-dep references, matching Pro's committed vendor layout.
4. `git diff vendor/composer/` - the diff should only add/remove references to files that exist under the tracked `vendor/` subdirectories (`easy-digital-downloads`, `woocommerce`). If you see any reference to `myclabs`, `phpunit`, `phpstan`, `sebastian`, `yoast`, `phar-io`, `doctrine`, `theseer`, or `nikic` - stop, that file is dev-only.
5. Run the fresh-clone fatal check in §2.
6. Commit `composer.json`, `composer.lock`, and only the regenerated `vendor/composer/*.php` files.
7. Restore dev deps via plain `composer install` so your local worktree stays usable.

### Never run these in a Pro dev worktree without the §2 check afterward
- `composer install --no-dev` - wipes dev deps, leaves you unable to run phpunit/phpcs/phpstan
- `composer dump-autoload` (without `--no-dev`) - regenerates autoload with dev refs, which if committed will fatal on fresh clones because Pro's `vendor/` does not ship dev files

Pro's workflow differs from Free's here because Free commits all dev-dep files (~15 MB bloat in the git repo). Pro stays lean by gitignoring them, which is the right call - but it means the autoload files must be regenerated with `--no-dev` whenever they change.

---

## 4. Dist ZIP build verification

Pro's release ZIP is built via the same Gruntfile pattern as Free:

```bash
cd wp-content/plugins/wpmediaverse-pro
npx grunt dist
```

This runs `composer-prod` (which runs `composer install --no-dev`), builds assets, copies files, compresses to `dist/wpmediaverse-pro-{version}.zip`, then runs `composer-restore` to put your worktree back.

Test the resulting ZIP by extracting it outside the plugin directory and copy-installing into a fresh WordPress install - it should activate clean with Free already active. If `composer-restore` fails partway through `grunt dist`, run `composer install` manually to recover your dev worktree.

---

## 5. Pro-specific activation testing

Pro extends Free via the `mvs_loaded` action (see section 2 of `CLAUDE.md`). This introduces three failure modes that are unique to Pro and must be tested whenever you touch `wpmediaverse-pro.php`, `includes/Core/Plugin.php`, or any service container registration:

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
- [ ] Fresh-clone fatal check passed (§2): `php -r 'require vendor/autoload.php'` OK on clean clone
- [ ] Any vendor/composer/ changes regenerated with `composer dump-autoload --no-dev` (§3)
- [ ] Pro-specific activation sequence tested (§5) - all four Pro/Free state transitions clean
- [ ] `composer run phpcs` clean (or existing baseline unchanged)
- [ ] `composer run phpstan` clean (or existing baseline unchanged)
- [ ] `./vendor/bin/phpunit` green
- [ ] Browser-verified every feature touched on real pages at 1440px and 390px
```
