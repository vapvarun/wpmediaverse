# Cleanup Plan: MVS_CSS::generate consolidation — **SUPERSEDED**

**Date:** 2026-05-17
**Target version:** N/A
**Owner:** Claude session 2026-05-17
**Reviewer:** Varun (self-review of framework output)
**Status:** ⚠️ **SUPERSEDED — reasoning below was wrong.**

> **Correction (2026-05-17, later same day):** The "Pro must work standalone"
> argument in the original decline rationale was incorrect. Pro's plugin
> header declares `Requires Plugins: wpmediaverse` and the runtime
> `mvs_pro_check_requirements()` bails on missing `MVS_VERSION` — Pro is
> never active without Free. Coding Rule #10 (no `use WPMediaVerse\\...`
> imports in Pro) is about indirection for refactor safety, not standalone
> capability.
>
> Consolidation IS valid. The original analysis below is preserved for
> historical context. Tracked as **M3-REVISIT** in
> `plan/2026-05-17-stabilization-sweep.yaml#/execution/open` — pending
> execution once user signs off.

---

## ⚠️ Below this line: original (wrong) analysis preserved for the record

---

## What triggered this plan

`audit/cleanup/duplicates.json` flagged `MVS_CSS::generate` as the **#1 cross-plugin duplicate** with **1288 normalized tokens identical** between:

- `wpmediaverse/includes/Blocks/MVS_CSS.php` (Free)
- `wpmediaverse-pro/includes/Blocks/MVS_CSS.php` (Pro)

By raw byte count this is the highest-leverage consolidation target in the codebase. Doing it would remove ~50% of the cross-plugin maintenance burden in a single PR by my naive count.

That's exactly why the framework's pre-conditions matter — naive counts lie.

---

## Inventory (Gate 1 of the cleanup template)

### Where MVS_CSS is defined

```
wpmediaverse/includes/Blocks/MVS_CSS.php             namespace WPMediaVerse\Blocks
wpmediaverse-pro/includes/Blocks/MVS_CSS.php          namespace WPMediaVerse\Pro\Blocks (presumed)
```

Two classes, different namespaces, same body.

### Callsites (consumers of MVS_CSS::generate / MVS_CSS::add)

| Location | Plugin | Notes |
|---|---|---|
| `wpmediaverse/includes/Blocks/BlockRegistrar.php:55` — `MVS_CSS::init()` | Free | Boot-time wiring |
| `wpmediaverse/build/blocks/*/render.php` — 11 files calling `\WPMediaVerse\Blocks\MVS_CSS::add(...)` | Free | Per-block server-side render |
| `wpmediaverse-pro/build/blocks/*/render.php` (presumed) | Pro | Per-block render (Pro blocks) |
| `wpmediaverse/includes/Blocks/StandardAttributes.php:185` — docblock reference | Free | Comment only, no runtime call |

### Class docblock — explicit design intent

From `wpmediaverse/includes/Blocks/MVS_CSS.php:3-13`:

> MVS_CSS — Server-side CSS generation for dynamic blocks.
>
> **Mirrors the Pro counterpart** at `wpmediaverse-pro/includes/Blocks/MVS_CSS.php`. **Both plugins share the same `mvs` prefix and the same `.mvs-block-{uniqueId}` selector convention**, so a single `<style id="mvs-block-styles">` block in `wp_footer` carries CSS for every Wbcom block on the page regardless of which plugin emitted it.
>
> Originally ported from wbcom-essential v4.5.0 `plugins/gutenberg/includes/class-wbe-css.php`.

**This duplication is by design and documented in the code itself.**

---

## Bridge check (Gate 1.5)

```bash
jq '.bridges[] | select(.symbol | tostring | contains("MVS_CSS"))' \
    audit/cleanup/bridges.json
```

Returns empty. MVS_CSS is not in our bridge inventory because it's not a public hook, REST route, capability, or option.

But the **bridge inventory is one of several gates, not the only one.** Other gates that apply here:

| Gate | Verdict |
|---|---|
| Class docblock declares intentional parallel implementation | ⚠ **STOP — design intent documented** |
| Pro depends on Free being active? | No — Coding Rule #10: "Pro: never import Free classes directly" |
| Pro renders blocks when Free is deactivated? | Yes — Pro registers its own blocks via its own `BlockRegistrar` → needs its own `MVS_CSS` |
| Cross-plugin call possible? | No — Pro classes never reach into Free's namespace |
| Shared `.mvs-block-{uid}` selector convention enables ONE `<style>` block on `wp_footer`? | Yes — collector pattern requires shape compatibility, not class identity |

---

## Why the cleanup was declined

If we consolidated MVS_CSS into a single Free class and made Pro call into it:

1. **Pro would break when Free is deactivated** — direct violation of Coding Rule #10 ("Pro: never import Free classes directly. Pro hooks into mvs_loaded and uses ServiceContainer"). Pro must function with `defined('MVS_VERSION') === false`.

2. **Single point of failure** for both plugins' block CSS — a bug in the consolidated class breaks every block in both plugins, not just one.

3. **Onboarding cost for the Pro plugin's contributors** — they'd need to understand they can't modify Pro's CSS rendering without considering Free's blocks.

4. **No customer benefit** — the "one `<style>` block in `wp_footer`" optimization already works via the shared `mvs-block-{uid}` selector convention. Both classes contributing to that style block from different namespaces is the intended pattern.

The 1288 tokens of duplication is **buying** plugin independence + customer guarantee that Pro keeps working through Free deactivation. The cost of removing the duplication is the cost of those guarantees.

---

## What we learned (process value)

The framework worked exactly as designed:

1. ✅ **`audit/cleanup/duplicates.json` surfaced the candidate** (1288 tokens, top of the list)
2. ✅ **Inventory phase read the docblock** and immediately surfaced "intentional parallel implementation"
3. ✅ **Bridge check passed** (not a public hook) — but the OTHER gates caught it
4. ✅ **Cleanup template's pre-conditions** ("Pre-condition: backward compat strategy decided") forced the question "what happens when Free is deactivated?"
5. ✅ **No code moved.** The 50+ customer sites are safe.

**The framework saved roughly half a day of work that would have ended in a regression.** That's the whole point of the discipline.

---

## Applied lesson — re-evaluating the other 11 cross-plugin duplicates

`audit/cleanup/duplicates.json` lists 11 more cross-plugin matches. By the same logic, most are likely intentional parallel implementations following the same Coding Rule #10 pattern. Specifically suspect (same design):

| Candidate | Likely verdict |
|---|---|
| `Shortcodes::init` (144 tokens, Free+Pro) | Intentional — each plugin registers its own shortcodes |
| `StandardAttributes::visibility_classes` (71 tokens, Free+Pro) | Intentional — same selector convention |
| `AdminController::register_routes` (43 tokens, Free+Pro) | Intentional — REST routing boilerplate; different namespaces required |
| Constructor stubs (36 tokens × multiple) | Intentional — same dependency injection shape |

**Each of the other 11 needs its own inventory + design-intent review before any consolidation decision.** Most will reach the same "declined — intentional" conclusion.

The ONES that might be real cleanup targets:

- Utility functions that don't reference any Free state (e.g., a `format_bytes()` helper)
- Pure data transforms (sanitizers, validators)
- Anything Pro could legitimately consume via the service container

Those need:
- Class-level docblock check ("does the code say it's intentionally duplicated?")
- Coding Rule #10 check ("is it OK for Pro to import this Free class?")
- Customer-impact check ("does Pro need to work without Free?")

If all three pass, draft a real cleanup plan for that specific symbol.

---

## Verification gates (would have applied if executed)

These would have run if we proceeded:

- [ ] `composer ci` green
- [ ] Pro's blocks render correctly with Free deactivated (Coding Rule #10 invariant)
- [ ] No new entries in `audit/cleanup/duplicates.json` (i.e., we didn't introduce parallel implementations elsewhere)
- [ ] BP activity feed renders both Free and Pro blocks
- [ ] Single `<style id="mvs-block-styles">` element still appears once on `wp_footer`

But we didn't run them because we never wrote the code. **Declined > regressed.**

---

## Risk register (would have applied)

| Risk | Why it killed this plan |
|---|---|
| Pro stops rendering blocks when Free is deactivated | Coding Rule #10 violation — Pro must work standalone |
| Theme overrides break (`mvs-block-styles` ID consumer) | Themes hooking the footer style block expect that ID to exist regardless of which plugin emitted it |
| Customer sites running Pro-only configurations | These exist; consolidation would break them |
| Future Pro release that diverges from Free | Pro might need a CSS extension Free doesn't have — parallel implementation makes that easy; consolidation would require filter hooks |

---

## Disposition

**No code changes. No commits beyond this plan document.** The plan itself is committed so future sessions encountering `audit/cleanup/duplicates.json` see the verdict and don't re-open the question.

If a future session has a strong reason to revisit (e.g., Free 2.0 changes the block CSS contract anyway), reopen the plan with a new date and a fresh inventory pass.
