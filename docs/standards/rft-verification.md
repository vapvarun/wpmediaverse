# RFT Card Verification (ALL plugins — normative, for QA)

> Companion to [`qa-catalog.md`](qa-catalog.md). **The catalog defines what gets
> checked before a card is allowed INTO Ready for Testing (gate G3). This
> document defines how QA verifies a card that is ALREADY in RFT.** They are
> different jobs and must not be confused: the catalog asks "is the plugin
> healthy?", QA asks "is this specific claim true, and did fixing it break
> something else?"
>
> Applies to every Wbcom plugin/theme. Process is defined here once; plugins
> hold data only. Keep a synced copy at `docs/standards/rft-verification.md`.

---

## 0. The one rule that governs everything

**A card is a claim, not a fact — including the "Fixed" comment.**

The description, the stated root cause, the "how it was verified" note: all of
it is somebody's account of their own work. Treat every one as unverified until
you have seen it yourself. This applies to cards written by developers, by
agents, and by you.

Two real examples from MediaShield 1.3.0, both caught only because someone
re-checked:

- A card body read *"Fix: Added the missing per-video override control … plus
  the save handler."* The meta key did not exist anywhere in the repository.
  The work had not landed.
- An audit note stated *"KeyServer is entered only from Packager."* It was
  wrong — a live REST controller called it. Acting on that note would have
  broken the licence endpoint.

**If you cannot verify a claim, the verdict is BLOCKED, not PASS.**

---

## 1. The five questions

Every RFT card must answer all five. Any NO is a bounce.

| # | Question | Pass bar |
|---|---|---|
| **Q1** | Does the original defect still reproduce? | **NO** — and you reproduced it on the pre-fix state first, so you know your repro is real |
| **Q2** | Does the fix work by the mechanism it claims? | The stated cause is the actual cause. A symptom that disappears for an unexplained reason is not fixed |
| **Q3** | What else touches this code? | Every other surface with the same shape is checked, or the gap is written down |
| **Q4** | Would a site owner and an end user call this fixed? | Both, separately. See §4 |
| **Q5** | Can it regress silently? | A test or journey step exists that fails if the bug returns |

### Q1 in practice — reproduce the bug, not just the fix

The single most common QA failure is verifying that something *works now*
without ever having seen it *fail before*. That proves nothing: it may never
have been broken on your setup, or you may be testing the wrong surface.

```
1. Check out the parent of the fix commit  (git log --oneline -- <file>)
2. Reproduce the defect. Screenshot / record the failure.
3. Return to the fix. Repeat the identical steps.
4. Both observations go in the verdict.
```

If step 2 does not reproduce, **stop and say so**. That is a finding: the card
may be config-specific, already fixed elsewhere, or wrong. It is not a pass.

---

## 1.5 The role ladder — reproduce as who *saw* it, then as who *else* can

Q1 is not answered until you have reproduced **as the role the reporter was
using**. This is the single most repeated waste in card QA: the card is re-walked
from scratch every time because nobody wrote down who to log in as.

**The reporter reports a view. Owning the code is our job, not theirs.** A site
owner says "the button does nothing" — they are describing what their screen did,
accurately. Translating that into a capability, a query or a template is QA's
work, and it starts by standing where they stood.

### The ladder

Walk it top-down on the surface named in the card. Stop at the first row that
disagrees with `audit/ROLE_MATRIX.md` — that disagreement *is* the finding.

| # | Persona | Why it is its own row |
|---|---|---|
| 1 | **Reporter's role** | The claim is about this view. Everything else is context |
| 2 | **Anonymous** | Login gates, public surfaces, and what leaks before auth |
| 3 | **Member — owner** | The authenticated happy path, on their own content |
| 4 | **Member — *not* owner** | The other member's content. Where privacy and permission bugs actually live |
| 5 | **Elevated** (editor / moderator) | Only if the plugin grants mod capabilities |
| 6 | **Admin** | Last, never first — see below |

Rows 3 and 4 are **two different logins, not one**. A permission bug is invisible
with a single account: the owner can always see their own item, so "works for me"
is guaranteed and meaningless. Every plugin's persona set must contain at least
two same-role members for this reason.

### Admin is the last row, never the first

An administrator bypasses nearly every capability gate, privacy check and
ownership test in the plugin. **A permission or visibility card confirmed only as
admin is not confirmed** — it is a false PASS waiting to be re-opened. If the card
is about who can see or do something, admin is the control, not the test.

### Where the answers come from — never re-derive them

| Question | Answer lives in | Never |
|---|---|---|
| Who do I log in as? | `personas` in `docs/qa/qa-config.json` | Invent a username per card |
| Do those users exist? | `bin/qa-fixtures.sh` (idempotent, run it) | Hand-create users per card |
| What *should* each role see? | `audit/ROLE_MATRIX.md` | Reason it out from the code each time |
| Has this card been seen before? | `plan/basecamp/ledger.md` | Re-research from zero |

If any of those four is missing, that is a **BLOCKED on the plugin** (§7), not a
per-card problem. Report it once to the plugin owner and move on.

### Role is also a blast-radius axis

The ladder is not only for the repro. After a fix confirms, re-walk the rows you
skipped — a fix scoped to the reporter's role routinely breaks the row above or
below it. That sweep belongs in §3, and its result belongs in the verdict.

---

## 2. The checks, at card level

The catalog lists the full pipeline. Here is what each check means when you are
verifying **one card**, who runs it, and what "pass" looks like.

### 2.1 Functionality verify — *the primary gate*

Walk the actual user path in a real browser, at the real viewport, as the real
role. Not the REST response. Not the DOM node count.

**HTTP 200 is not verification. A DOM node being present is not verification.
A clean grep is not verification. Look at the screen.**

Pass: you performed the task a human would perform and got the outcome a human
would expect, and you have a screenshot of it.

### 2.2 Catalog check *(inventory)*

Cross-check the card's subject against `audit/manifest.json` /
`manifest.summary.json`:

- Feature the card adds → does it appear in the inventory (route, hook, setting,
  table, meta key)?
- Feature the card removes → is it *gone* from the inventory, not just the code?
- Counts in `CLAUDE.md` still match the manifest?

Pass: the inventory describes the plugin as it now is. A manifest that still
lists a deleted class is a fail, because the next agent will trust it.

> **Known trap.** The manifest generator has blind spots per plugin (Action
> Scheduler cron, wrapper-registered CPTs, `str_replace`-built tables). Read
> `notes.generator_blind_spots` before calling a count wrong.

### 2.3 Code flow check

Trace the path end to end and name each hop: **entry → handler → data → render
→ what the user sees.** Say where the card's change sits in that chain.

Pass: you can state the flow in one sentence without opening the card. If you
cannot, you have not verified it — you have read a diff.

### 2.4 Duplicate check

Ask: *is this rule written anywhere else?* Search for the literal values, not
the function name.

This is the highest-yield check in the catalogue on our codebase. In one release
cycle, the same defect class appeared four times:

| Rule | Copies found | Consequence |
|---|---|---|
| Protection level resolution | 5 | Same video protected differently by render path |
| Adaptive-platform check | 2 | — |
| Player feature-override map | 4 | A feature shipped with no per-video control |
| Platform credential decrypt | 4 | Every platform connection silently dead |

Pass: the rule has one home, or every copy is listed in the verdict.

### 2.5 Dead code check

For anything the card adds: **who calls it?** For anything it removes: **is
anything still referencing it?**

```bash
grep -rn "NewClass::\|new NewClass" includes/ src/    # must be > 0
grep -rn "DeletedThing" includes/ src/ docs/          # must be 0, or only history
```

Pass: no zero-caller additions, no dangling references. A helper added "for
later" is a fail — it is speculative until something uses it.

### 2.6 WPCS / static checks — *secondary, and never a substitute*

These run automatically at commit and push. Confirm green; do not spend
attention here.

**Severity labels from scanners are not evidence.** Verify before repeating a
number. On one MediaShield run the tooling reported *26 critical* security
findings; all 21 in the security category were false — every one was
`$wpdb->query()` on a literal string, a hardcoded identifier, or a class
constant, with nothing to prepare. Scanners match call *shape*, not data flow.

Pass: gates green, and any finding you repeat has been checked by hand.

### 2.7 Logic check

Read the change for what it does at the boundaries, not the happy path:

- empty / zero / null / missing meta
- the "unset" state vs an explicit false (`''` is not `false` — and
  `wp_localize_script` turns PHP `false` into `""`)
- first run, second run, concurrent run
- the value that is legal but rare (a path with a space; a site with 5,000 rows)

Pass: you named the edge cases and checked at least the ones a real site hits.

### 2.8 Browser journey — *last, and mandatory*

After the above, walk the journey the feature belongs to end to end. Not just
the changed screen — the flow around it.

Cover, for every surface the card touched:

- desktop **and** 390px
- light **and** dark
- logged-in **and** logged-out (where both are legal)
- the empty, error and loading states of any async surface
- zero JavaScript errors in the console

Pass: screenshots for each, attached to the card.

---

## 3. Blast radius — the check that catches the next bug

**The card is where you enter the code, never the whole of the work.**

After confirming the fix, ask: *what else is broken the same way?* Then look —
on a theme, role, viewport or state the reporter never tried.

This is where the value is. In MediaShield 1.3.0, fixing one Bunny import
address exposed that **no** platform upload had ever stored a playable address;
fixing platform detection exposed that the auto-wrap had **never** loaded its
scripts on any platform. Both were found by sweeping, not by the card.

Findings outside the card are the point. Report them, size them, let the owner
decide. Never silently drop one because "the card didn't ask."

---

## 4. Classify the claim, then judge it

Before asking *"is this fixed?"*, ask *"what kind of claim is this?"* The class
decides the verdict, the urgency, and whether QA gets to bounce it at all. Run
the four tests **in order** and stop at the first YES.

| # | Test | Class | Bounce? |
|---|---|---|---|
| 1 | Does the behaviour **contradict a stated contract**? | **Defect** | Yes, without negotiation |
| 2 | Does it **violate a published standard**, or stop a user completing the task? | **UX expectation** | Yes, cite the standard |
| 3 | Can the owner not **find / control / trust / keep** it? | **Owner expectation** | Yes, cite which of the four |
| 4 | None of the above | **Preference** | **No** — see *When QA is wrong* |

### 1. Defect — the code does not do what it says

A *stated contract* is anything the product itself promises: a button label, a
setting name, a documented behaviour, an API response shape, a capability. If
behaviour contradicts one, it is a defect regardless of how it looks.

Data saves wrong or does not persist · a control does nothing or does something
else · a failure is silent · a privacy or permission boundary leaks · a fatal or
JS error · it breaks on a supported configuration (generic theme, 390px, RTL,
JS disabled, BuddyPress inactive).

Objective and binary. **Never negotiate a defect**, and never downgrade one to
preference because the fix is inconvenient.

### 2. UX expectation — it works, but the user cannot tell or cannot finish

The mechanism is correct; the experience is not. This is real **when you can name
the standard it violates** — WCAG 2.1 AA, the portfolio's `ux-foundation`
contract (40px tap target, 3 breakpoints), or an established convention from a
comparable product.

No feedback after an action · missing empty / loading / error state · destructive
action with no confirm · sub-40px tap target · keyboard-unreachable · fails AA
contrast · the task needs knowledge the UI never gave.

If you cannot cite a standard, it is test 4, not this one. "Feels cramped" is a
preference; "44px spec, measured 28px" is a UX expectation.

### 3. Owner expectation — it works for the member, but not for the person running the site

Four questions. Any NO is real:

| | |
|---|---|
| **Find** | Is there an admin surface for it at all? A setting that exists only in code is a half-shipped feature — see the three-entry-points rule (frontend, backend, API) |
| **Control** | If the owner turns it off, does it turn off — visibly, on the frontend? |
| **Trust** | Can the owner tell the current state without reading the database? |
| **Keep** | Does it survive an update, a migration, and a theme switch? |

Owners run themes we have never seen. Behaviour that only holds on BuddyX or
Reign is broken for the majority — **test a generic theme before calling this
class satisfied.**

### 4. Preference — no contract, no standard, no missing control

"Looks empty", "feels narrow", "should be wider", "should be at the top",
"colour is off". Real feedback, not a bug, and **not QA's call to enforce.**

**Site owners and end customers decide what is right — not QA, and not us.**
QA surfaces problems; QA's *interpretation* is an input, not a verdict.

### When QA is wrong

Before bouncing anything that landed in class 4:

1. Reproduce at the **exact** viewport and configuration being judged.
2. Evaluate as the real owner and the real customer — not as "would QA accept
   this?"
3. Check a published reference (Bringhurst, Material, Tailwind, a comparable
   product). If the standard says the current behaviour is right, the bounce is
   preference wearing a bug's clothes.
4. If it is genuinely subjective, ask for a **filter or extension point** so the
   owner can override per-site, and keep the default correct for the typical
   install.

Then push back on the card with the principled reason and the citation. Do not
change a default to keep QA happy when it would hurt real users.

**Override:** if the user says "ship QA's request anyway", ship it. They are the
deciding voice over QA, over this document, and over you.

### Both audiences, separately

Whatever the class, a card is fixed only if **both** would agree:

| | Ask |
|---|---|
| **Site owner** | Can I find this? Does the control say what it does? If I turn it off, does it turn off? Is it where I would expect from WooCommerce/TEC norms? Does it survive an update? |
| **End user** | Did the thing I clicked do what it said? Was I told when it failed? Did it work on my phone? Did it work without JavaScript, or fail honestly? |

---

## 5. Verdict format

One block per card, posted as a Basecamp comment. Comparable across 40+ cards.

```
<one plain sentence for the owner: what happens, to whom, and why it was missed>

VERDICT: PASS | BOUNCE | BLOCKED | REFUTED | CONFIRMED | CANNOT-REPRO | NEEDS-INFO
Plugin/branch: <slug> @ <sha>            Env: <site, WP x.y, PHP x.y, theme>

Triage  reach: universal | site-specific (<which config>)
        impact: road-block | degraded | cosmetic
        location: core (<CORE_PATHS #>) | edge          -> priority P0-P3
Questions asked (owner-questions.md ids)   O-3 T-2 P-2 ...
Class (§4)                              defect | ux-expectation | owner-expectation | preference
Roles walked (§1.5)                     anon <y/n/na> | member-owner <> | member-other <> | elevated <> | admin <>

Q1 original reproduces on pre-fix        YES / NO / could not
Q2 fix works by its stated mechanism     YES / NO
Q3 blast radius swept                    surfaces checked: <list>
Q4 owner + end user would call it fixed  YES / NO
Q5 regression guard exists               test/journey: <name> | NONE

Evidence: <screenshots at the failing rung + the control rung, commands, before/after values>
Not covered: <what you could not check, and why>
Question added: <new owner-questions.md id> | none (say why)
```

| Verdict | Means |
|---|---|
| **PASS** | All five answered. Evidence attached. |
| **BOUNCE** | A specific, reproducible failure. Must include repro steps + evidence. "Doesn't feel right" is not a bounce — see §4. |
| **BLOCKED** | Cannot verify (env missing, fixture absent, needs an account you don't have). Say exactly what you need. Never a silent PASS. |
| **REFUTED** | The card's central claim is wrong. Attach the evidence and the correct explanation. This is a good outcome, not a failure. |

For a card in **Bugs** (a report, not a fix) the question is *"is this real?"*, so the verdict is one of:

| Verdict | Means |
|---|---|
| **CONFIRMED** | Reproduced as the reporter's role, triaged, prioritised. Ready for a developer. |
| **CANNOT-REPRO** | Walked the ladder on the named surface and config; did not reproduce. Say exactly what was tried — this is a finding, not a dismissal. |
| **NEEDS-INFO** | The card does not say who saw it, where, or on what config. Ask the specific question; do not guess. |

### The finding bar - what a BOUNCE or a new card must carry

A finding without all four is a lead, and a lead is a **comment**, not a card. Soft opinions dressed as bugs are how the Bugs column fills with noise.

| Field | Means |
|---|---|
| **Where** | Surface + role + config + a URL. "Explore, as member-other, Astra, 390px" |
| **Why wrong** | The contract it breaks: the label, the setting, the doc line, the promise id, the standard (WCAG 2.1 AA 1.4.3, the 44px token) |
| **Who it costs** | The role that hits it and what they cannot do. "A member cannot open a document shared with them" |
| **Fix pointer** | Where the same problem was already solved in this codebase - the sibling to copy. `PermissionController::can_manage_sharing()` when `the_share_button()` hand-rolled it |

**`Not covered` is mandatory and must not be empty-by-default.** A verdict that
claims total coverage is the least trustworthy kind.

---

## 6. The regression contract

**Every bounce is a missing check.** The fix commit must add the test or journey
step that would have caught it. A bug that recurs is a process failure, not bad
luck.

**Every PASS on a card with no Q5 guard is provisional.** Say so in the verdict.

---

## 7. Before you start — per-plugin prerequisites

QA cannot run this protocol without the plugin's data files. Check first:

```
docs/qa/qa-config.json     site URL, themes, key pages, and    /wp-plugin-release-qa
                           the `personas` block §1.5 reads
docs/qa/OWNER_INVENTORY.md what the owner can see (generated)   wp-card-qa/scripts/owner-inventory.py
docs/qa/CORE_PATHS.md      the 60-70%, ranked, human-confirmed   wp-card-qa template
bin/qa-fixtures.sh         idempotent persona bootstrap         wp-card-qa template
audit/ROLE_MATRIX.md       expected allow/deny per role         wp-card-qa template
plan/basecamp.yml          bug-ledger config (card history)     wp-card-qa template
audit/journeys/**/*.md     runnable walkthroughs                /wp-plugin-onboard
audit/manifest.json        inventory                            /wp-plugin-onboard
plan/INVARIANTS.yaml       architecture gates                   /wp-plugin-onboard
```

The first four are what stop a card being re-walked from scratch. Run
`bin/qa-fixtures.sh` once per environment before the first card of a session,
not once per card.

Missing files are a **BLOCKED** on the whole plugin, not on individual cards.
Report once, to the plugin owner, rather than per card.

---

## 8. Standing traps

Learned the hard way; check against this list before writing a verdict.

1. **Scanner severity is not evidence.** Verify before repeating a number.
2. **Static green never justifies RFT.** Every defect that reached customers in
   1.2.0 passed every static gate.
3. **A grep that finds nothing may be the wrong grep.** A real check looked in
   `assets/js/` for a file that lives in `assets/vendor/` and reported a working
   feature as broken.
4. **Docs and screenshots are documents too.** A screenshot showing removed
   fields is stale documentation — and nobody greps an image.
5. **A wrapper method hides callers.** `grep "Foo::bar"` misses
   `$instance->bar()`. Check both.
6. **`false` from `wp_localize_script` arrives in JS as `""`.** Compare by
   truthiness, never `!== false`.
7. **Deleting is not the same as unwiring.** Confirm the removed thing is gone
   from the inventory and the docs, not only from the code.
