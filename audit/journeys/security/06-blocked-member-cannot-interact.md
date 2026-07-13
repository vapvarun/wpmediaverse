---
journey: blocked-member-cannot-interact
plugin: wpmediaverse
priority: critical
roles: [subscriber, subscriber]
covers: [block-enforcement, rest-gate, safety, suspension-gate]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Two subscriber members exist (the blocker and the blocked), plus one uninvolved bystander"
  - "The blocker owns at least one public media item"
estimated_runtime_minutes: 6
---

# A blocked member cannot interact with the person who blocked them

**Why this journey exists**: This is the regression sentinel for the 2.1.0 block-gate incident. `RestGuards`'s own docblock said it plainly — Application Passwords are minted by WordPress core and bypass any plugin login gate, so block enforcement has to live on the REST side — and it was applied to exactly **3 of ~112 write endpoints**. Verified on a live site, a blocked member could still favourite and share their blocker's media, challenge them to a battle, view their story (leaving a footprint in the owner's viewer list), pull them into a group, react to their DMs, and vote on their challenge entries.

Nothing caught it. `wp mvs cert` passed 66/66 the whole time, because it only dispatches **GET** and asserts non-500 — every one of these is a write. The CLI suite could not catch it either: `tests/cli/runner.php` calls `wp_set_current_user(1)` **once, globally**, so every assertion in it runs as the same user. A suite that can only express "me acting on your stuff" can never detect "you, *because* you blocked me, must be denied acting on mine."

**That is the class of bug this journey exists to catch: cross-actor authorization.** It requires two real, distinct identities and a relationship between them. It cannot be expressed as a single request, a single option flip, or a route merely responding.

A second failure mode is baked in here too, because it bit twice during the fix: **the gate fails open when a resolver cannot find its target.** Both times the cause was an assumed field name — `wp_posts.post_author` (which is `0` on MediaVerse media) and then `user_id` on `MessagingService::get_participants()` (whose rows are keyed `id`). In each case the gate resolved the owner to nobody, had nothing to check, and passed. The route still classified as `gated`, and every classification test stayed green. **So this journey asserts the 403, not the classification.**

## Setup

- **Blocker** (owns the media): `wp user create journey_blocker journey_blocker@example.test --role=subscriber --user_pass=journey-pass`
- **Blocked**: `wp user create journey_blocked journey_blocked@example.test --role=subscriber --user_pass=journey-pass`
- **Bystander** (never involved; the control): `wp user create journey_bystander journey_bystander@example.test --role=subscriber --user_pass=journey-pass`
- Blocker uploads one public image (reuse the fixture from `customer/01-media-upload-public.md`). Note its `$MEDIA_ID`.
- Blocker and Blocked exchange at least one DM **before** the block, so there is shared history to react to. (Creating the conversation after the block is correctly refused — that makes a useless fixture.)
- Blocker blocks Blocked: `POST /mvs/v1/users/{BLOCKED_ID}/block` as the Blocker.

> Run every request as a **real authenticated identity** — Application Password or `?autologin=<login>`. Do **not** use `wp_set_current_user()` inside a single process for both parties; that is precisely the harness limitation that let this bug ship.

## Steps

### 1. Every between-member write is denied (as **Blocked**)

Each must return **403** with code **`mvs_blocked`**:

| # | Request |
|---|---|
| 1.1 | `POST /mvs/v1/media/{MEDIA_ID}/comments` |
| 1.2 | `POST /mvs/v1/media/{MEDIA_ID}/reactions` |
| 1.3 | `POST /mvs/v1/media/{MEDIA_ID}/favorite` ← was open |
| 1.4 | `POST /mvs/v1/media/{MEDIA_ID}/share` ← was open |
| 1.5 | `POST /mvs/v1/users/{BLOCKER_ID}/follow` |
| 1.6 | `POST /mvs/v1/conversations` with the Blocker as recipient |
| 1.7 | `POST /mvs/v1/conversations/{CONVO_ID}/messages` |
| 1.8 | `POST /mvs/v1/messages/{MESSAGE_ID}/reactions` ← was open |
| 1.9 | `POST /mvs/v1/conversations/{CONVO_ID}/accept` ← was open |

Pro (skip any whose feature toggle is off):

| # | Request |
|---|---|
| 1.10 | `POST /mvs-pro/v1/battles` naming the Blocker as `opponent_id` ← was open |
| 1.11 | `POST /mvs-pro/v1/battles/{ID}/accept`, `/submit`, `/vote` ← was open |
| 1.12 | `POST /mvs-pro/v1/stories/{MEDIA_ID}/view` ← was open |
| 1.13 | `POST /mvs-pro/v1/media/{MEDIA_ID}/collections` ← was open |
| 1.14 | `POST /mvs-pro/v1/groups` naming the Blocker in `participant_ids` ← was open |
| 1.15 | `POST /mvs-pro/v1/challenges/{ID}/entries/{ENTRY_ID}/vote` on the Blocker's entry ← was open |

### 2. The safety valves stay open (as **Blocked**)

These must **NOT** be 403. Blocking must never become a shield for the blocker, or a trap for the blocked:

| # | Request | Why it must work |
|---|---|---|
| 2.1 | `POST /mvs/v1/media/{MEDIA_ID}/report` | An abuser must not be able to block their victim to suppress the report |
| 2.2 | `POST /mvs/v1/users/{BLOCKER_ID}/report` | Same |
| 2.3 | `DELETE /mvs/v1/users/{BLOCKER_ID}/follow` | You can always withdraw |
| 2.4 | `DELETE /mvs/v1/media/{MEDIA_ID}/favorite` | Retraction of something you already did |
| 2.5 | `DELETE /mvs/v1/media/{MEDIA_ID}/reactions` | Retraction. Create and delete used to share one permission callback, so this was wrongly **denied** — the gate pointed the wrong way |
| 2.6 | `DELETE /mvs/v1/messages/{MESSAGE_ID}/reactions` | Retraction |
| 2.7 | `POST /mvs/v1/conversations/{CONVO_ID}/decline` | Refusing contact must always be possible |
| 2.8 | `POST /mvs-pro/v1/battles/{ID}/decline` | Refusing a challenge |
| 2.9 | `POST /mvs-pro/v1/groups/{ID}/leave` | Leaving a group that contains someone you since blocked |

### 3. The bystander is unaffected (the control)

As **Bystander**, repeat 1.1–1.5 against the Blocker's media. Every one must succeed (200/201). A gate that denies everyone is not a gate; it is an outage.

### 4. A suspended member cannot write at all

- Suspend the Bystander: **Users → hover the row → Suspend** (or tick *Suspended* on their profile screen).
- As **Bystander**, every write must now return **403 `mvs_account_suspended`** — including writes to their *own* resources (`POST /mvs/v1/albums`, `POST /mvs/v1/media`).
- Reads must still return **200**. Suspension is not a ban; they keep their account and content and can still browse.
- **Do this over an Application Password**, not a cookie. That is the whole point: core's `wp_authenticate_application_password()` never runs the `authenticate` filter chain, so `wp_authenticate_spam_check()` never fires and no login gate can stop them.
- **Restore** them and confirm writes work again.

## Pass criteria

- Every request in §1 returns **403 `mvs_blocked`**.
- Every request in §2 returns something **other than 403**.
- Every request in §3 succeeds.
- §4: writes 403 `mvs_account_suspended` while suspended (via App Password), reads 200, and Restore reverses it.

## Fail diagnostics

- **A §1 request returns 200** — the route is not classified `gated` in `RestGate::map()` (Free) or the `mvs_rest_gate_map` filter (Pro). Add it, and add the expected mode to `RestGateTest::provide_expected_modes()` in the same commit.
- **A §1 request returns 200 but the route *is* classified `gated`** — the resolver found no target, so the gate had nothing to check and passed. This is the fail-open mode, and it has bitten twice. Check the field names the resolver reads; grep the logs for the `mvs_rest_gate_unresolved` action, which fires exactly for this.
- **A §2 request returns 403** — a safety valve or a retraction has been wrongly gated. This is worse than a missing gate: it means a victim cannot report their abuser, or cannot take back something they already did.
- **A §3 request returns 403** — the gate is denying uninvolved members. Likely a resolver returning the wrong user (e.g. resolving an owner to `0`, which then matches everyone).
- **§4 passes over a cookie but fails over an Application Password** — you have tested the wrong thing. The cookie path was never the hole.
