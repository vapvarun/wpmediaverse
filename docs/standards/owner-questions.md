# Owner Questions — the QA question bank (ALL plugins and themes)

> **QA stands where the site owner stands.** Owners never read code. They judge a
> plugin or theme by its options, its defaults, its templates, its emails, and
> whether the thing they bought does the thing they bought it for. Every
> question here is one an owner could ask — and answer — without opening a file.
>
> **This file grows.** Every bounce, every reopened card, every "how did QA miss
> that" adds one question, tagged with the card that paid for it. Never delete a
> question; retire it with a strike and the reason. Questions are cited by id in
> verdicts (`Questions asked: O-3 O-7 T-2`) so coverage is auditable per card.

Each question has: **id · the question · how an owner would check · what counts
as evidence.** `OWNER_INVENTORY.md` (generated) feeds the O/T/E/D/A lists;
`CORE_PATHS.md` (confirmed) feeds C.

---

## O — Options & settings

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| O-1 | Does every setting do what its label says? | Toggle it, reload the frontend, look | Before/after screenshot |
| O-2 | Is every default the one a fresh owner would expect? | Fresh install, read each default, ask "would I change this on day one?" | Inventory default column vs judgement |
| O-3 | When a setting is off, is the feature *gone* — not just hidden? | Turn off, then hit the URL / REST / shortcode directly | Direct hit returns nothing or a clean denial |
| O-4 | Can the owner tell the current state without reading the database? | Look at the settings screen only | State visible on screen |
| O-5 | Does a saved setting survive an update, a migration, a theme switch? | Save, bump version / switch theme, re-read | Value unchanged |
| O-6 | Is a setting that exists in code reachable from an admin screen? (three-entry-points rule) | Inventory: option with reads but no admin string | Every own option has a UI or a documented reason |
| O-7 | Do dependent settings hide or explain themselves when their parent is off? | Turn parent off, look at children | Children hidden/disabled, or a note |
| O-8 | Does the setting name match the frontend wording? | Compare settings label to what members see | Same vocabulary |

## T — Templates & display

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| T-1 | Does it look right on a theme we did not write? | Twenty Twenty-Five + one classic theme | Screenshots on both |
| T-2 | Can the owner override a template from their theme? | Inventory: theme-override loader yes/no | Copy a template to the theme, see it used |
| T-3 | Does every shipped template render without the plugin's own CSS being special-cased? | Disable theme-specific CSS, look | Layout holds |
| T-4 | Does it hold at 390px, and in RTL? | Resize; `?lang=ar` or RTL plugin | Screenshots |
| T-5 | Does it honour the theme's colours / dark mode rather than hard-coding? | Switch theme palette / dark toggle | No raw hex bleed |
| T-6 | Are empty, loading and error states designed — not blank? | Empty site, slow network, forced failure | Three screenshots |
| T-7 | Does every plugin-created page have the site chrome (header/footer/menu) on block AND classic themes? | Visit each activation page on both | Chrome present |

## E — Emails & notifications

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| E-1 | What emails does this send, and when? Can the owner list them? | Inventory email table vs. any settings screen | A list the owner can see |
| E-2 | Does each email fire exactly once per trigger? | Trigger, check mail log | One entry |
| E-3 | Can the owner edit subject and body without code? | Look for a template/settings surface | Editable, or a filter documented |
| E-4 | Does the email say who it is from, and does the from-address match the site? | Read the headers | Site name + admin email |
| E-5 | Are in-app notifications (BuddyPress / Woo / own) cleared when acted on? | Act on the item, check the bell | Count drops |
| E-6 | Does a notification link land on the thing it names, as the right role? | Click it as the recipient | Lands correctly |

## D — Developer-friendliness

| id | Question | Check as the owner's developer | Evidence |
|---|---|---|---|
| D-1 | Is there a hook on every core path (before/after save, before render, on output)? | Inventory hooks vs CORE_PATHS | Each core path names its hooks |
| D-2 | Is every own hook documented with `@since` and its args? | Inventory documented count | Undocumented list is empty or justified |
| D-3 | Can a developer change a default without editing the plugin? | Look for a `*_default(s)` filter | Filter exists |
| D-4 | Are hook names consistent with the prefix and readable? | Scan the list | No orphans, no typos |
| D-5 | Do REST endpoints exist for everything the UI can do? (three entry points) | Inventory REST count vs UI actions | Parity, or documented gaps |
| D-6 | Is there a CLI for the bulk things (seed, migrate, recount)? | `wp <prefix>` | Commands exist |

## A — Activation & lifecycle

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| A-1 | On a clean activate, what appears? Pages, menus, roles, tables — and is each expected? | Inventory activation table; visit the site | Nothing surprising |
| A-2 | Does deactivate leave the site clean, and uninstall remove data (with a warning)? | Deactivate; uninstall on a scratch site | No orphan pages/menus; uninstall confirms |
| A-3 | Does an upgrade from the previous version keep existing data rendering? | Upgrade a seeded old site | Old content shows |
| A-4 | Does the setup wizard / first-run notice lead somewhere useful, and dismiss for good? | Activate, follow it, dismiss, reload | Gone, and stays gone |
| A-5 | Does the free/pro pair activate in either order without a fatal? | Pro first, then free; and reverse | No fatal, clear notice |

## C — Core paths (the 60–70%)

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| C-1 | Do the ranked core paths in `CORE_PATHS.md` all complete, as their named role, on a clean install? | Walk them in rank order | One line per path, PASS/FAIL |
| C-2 | Does the first thing a new owner would try work with **zero configuration**? | Activate and try rank 1 with no settings touched | Works |
| C-3 | Is the most-used feature reachable in ≤ 2 clicks from the plugin's own landing? | Count clicks | ≤ 2 |
| C-4 | Are the core paths the ones the ledger says people actually report on? | Compare `plan/basecamp/ledger.md` hot-spots to the ranking | Ranking adjusted or justified |

## P — People (the role ladder)

| id | Question | Check | Evidence |
|---|---|---|---|
| P-1 | Reproduced as the role that reported it? | `personas` + `?autologin=` | Verdict `Roles walked` row 1 |
| P-2 | Walked with **two** same-role members — owner and not-owner? | Two logins | Both rows |
| P-3 | Was admin the *last* rung, and never the only one? | Verdict | Admin row is the control |
| P-4 | Does `ROLE_MATRIX.md` agree with what you saw? If not, which is wrong? | Compare | Finding filed either way |

## Triage — three axes, then the priority

Answer all three for every card, in the verdict.

| Axis | Question | Values |
|---|---|---|
| **R — Reach** | Reproduces on a clean install, default settings, default theme? | `universal` · `site-specific` (only with their theme / plugin mix / config) |
| **I — Impact** | Can the owner or member complete the task? | `road-block` (no workaround) · `degraded` (partial or workaround) · `cosmetic` |
| **L — Location** | On a core path or an edge? | `core` (in CORE_PATHS) · `edge` |

| | core | edge |
|---|---|---|
| universal · road-block | **P0** | P1 |
| universal · degraded | P1 | P2 |
| site-specific · road-block | P1 | P2 |
| site-specific · degraded | P2 | P3 |
| cosmetic (either reach) | P2 | P3 |

**Site-specific is a finding, not a dismissal.** It means the product breaks on a
configuration a real owner runs. The follow-up is "which configuration, and do
we support it" — never "works for me".

**Edge cases are welcome, never first.** Walk C before anything marked edge.

---

## Growing this file

When a card is bounced, reopened, or a customer finds what QA did not:

1. Write the question that would have caught it, in the owner's words.
2. Give it the next id in its section, and tag it: `(card 10263273931)`.
3. Put the check in the "as the owner" column — if the check needs code, it is
   the wrong question; find the owner-visible symptom instead.
4. Sync the copy in every plugin's `docs/standards/` at its next release.

## Added 2026-09-09 — from the WPMediaVerse 2.4.x cycle

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| T-8 | Is anything meant to be hidden still visible on a theme that ships **no** `[hidden]` reset? *(card 10266317652 — Reign/BuddyX silently rescued it; Astra did not)* | On Astra or Twenty Twenty-Five, every element with `hidden` must have `offsetParent === null` | List of hidden-but-visible elements, per theme |
| O-9 | If a setting holds a value that used to be legal and no longer is, does the feature fall back **visibly**, or fail silent? *(card P6 — `feed_layout='default'` disabled the feed with no message)* | Set each enum option to a retired value, reload | Fallback shown, or an admin notice |
| P-5 | To a role that is denied an item, does it look **identical** to an item that does not exist — same status, same page? *(2026-08-11 F2 — a denied document returned a 403 login page, confirming it existed)* | Request a denied id and a nonexistent id as `member-other` and as anonymous | Same status code, same body |
| C-5 | Before writing CANNOT-REPRO, does every fixture the repro depends on actually resolve? *(card 10264236711 — "cannot reproduce" was a deleted-media fixture)* | Confirm the row exists, the slug is live, the user is the stated role | Fixture check listed in the verdict, or BLOCKED |

## Added 2026-09-09 — admin state contract and promises (from external QA feedback)

| id | Question | Check as the owner | Evidence |
|---|---|---|---|
| O-10 | Does every settings screen with nothing configured say what to do **next**, rather than showing an empty shell? | Fresh install, open each admin page | A next action (button or link) on every empty screen |
| O-11 | Is every field label a real label - not a placeholder that vanishes on focus, not the option key? | Tab through each form | Labels persist; none read like `mvs_foo_bar` |
| O-12 | When a save fails, is the error on the **field** that failed, not a page-level banner? | Submit an invalid value | Inline field error, focus moved to it |
| M-1 | Does every promise the readme or marketing makes ("reversible", "never leaves your server", "owner can always get back in") hold as a walked row? | `owner-inventory.py` lists promise candidates; confirm each into `promises[]` in qa-config | One row per promise, PASS with evidence |
| M-2 | Is every artefact that exists in code alive on the site - shortcode renders, block renders, route answers, admin page loads? | `owner-inventory.py --json`, then walk | `coverage.code_to_live` with no `missing-from-live` left unexplained |
| T-9 | Have the controls that only render under a **condition** (a first-visit prompt, a banner, an empty state, a cover-less card) been measured too, not just the always-visible ones? *(card 10266320513's sweep said "0 under the floor"; the profile-prompt close was 17×22 — it only renders for a member with no profile)* | Walk as a fresh member with nothing configured; measure every control that appears | Conditional controls listed in the sweep |
| M-3 | Is an **absence** the inventory reports actually absent, or just ungreppable? *(2026-09-09: "theme-override loader: NO" became a filed card; the loader existed and was documented — `locate_template()` built its argument from variables, so a literal-only grep saw nothing)* | Prove absence live before filing: exercise the thing the tool says is missing. Drop the file, call the hook, set the option | The live attempt, and its result, in the card |
