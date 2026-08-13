# Bundled runtime libraries

Third-party code that WPMediaVerse **ships and loads at runtime**. Committed to
the repository on purpose, and copied into the release zip on purpose.

`vendor/` is the opposite: dev and build tooling only (phpcs, phpstan, phpunit),
gitignored, and excluded from the zip. Nothing under `vendor/` is loaded on a
customer site — the autoloader for `WPMediaVerse\` is hand-written in
`wpmediaverse.php`.

## Why these live here rather than in Composer

They used to be Composer dependencies, and the two build paths disagreed about
whether the result shipped:

| | shipped `vendor/`? |
|---|---|
| `grunt dist` | yes — copied it, minus dev packages named one by one |
| `.distignore` | no — `/vendor` excluded wholesale |

`.distignore` is what wp-plugin-qa, WordPress.org SVN tagging and third-party
packagers read. A zip built that way had no autoloader, so `plugins_loaded`
called a class that did not exist: HTTP 500 on the front page **and** on
`wp-admin/plugins.php`, with WP-CLI refusing to start as well. Every recovery
route through WordPress was closed.

Moving the runtime dependencies here removes the disagreement rather than
picking a winner. It is also the pattern BuddyNext and Learnomy already use.

## What is here

| Directory | Package | Loaded from | Why bundled |
|---|---|---|---|
| `action-scheduler/` | `woocommerce/action-scheduler` ^3.7 | `wpmediaverse.php`, at plugin load | Async background work has to run on a standalone install. It was declared in `composer.json` and never loaded, so `WebhookService`, `StorageCleanupService` and `StorageRepairService` sat behind `function_exists( 'as_*' )` guards that were always false unless Pro or WooCommerce happened to be active. |
| `edd-sl-sdk/` | `easy-digital-downloads/edd-sl-sdk` (from `github.com/vapvarun/edd-sl-sdk`) | `wpmediaverse.php`, guarded on completeness | Plugin updates. Loaded only when `src/Versions.php` is also present — a partial extract that keeps the entry file but drops `src/` fatals inside the SDK. Licensing gates updates only, never features, so an incomplete SDK degrades to "updates off". |

Action Scheduler is bundled **unscoped**, deliberately: the global `as_*()` API
and cross-plugin version negotiation are the point. If another active plugin
ships a newer copy, `ActionScheduler_Versions` picks the highest one. It must be
loaded no later than `plugins_loaded`, which is why the entry file requires it
directly rather than waiting for a hook.

## Updating one

Download the release, replace the directory wholesale, commit it. Do not add
these back to `composer.json` — `BootGuardTest` asserts they are not there, and
the two build paths would start disagreeing again.

Pro carries its own copies under `wpmediaverse-pro/libs/` and loads them the
same way. `require_once` makes the double load safe when both are active.
