---
journey: battle-monitor
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-BAT-008, MV-BAT-009, battle-monitor-admin, battle-expiry]
prerequisites:
  - "Pro active; mvs_battles_enabled = 1; manage_mvs_settings capability"
  - "At least one battle in each of pending/accepted/active/voting/completed states, and one battle past its submit deadline while still pending"
estimated_runtime_minutes: 6
---

# Battle Monitor moderates safely with confirm dialogs, and unaccepted/unsubmitted battles expire instead of vanishing

## Setup

- Admin `?autologin=admin`; `admin.php?page=mvs-battles` (MediaVerse -> Photo Battles submenu, after Stats/Competitions Dashboard since 2.6.0)

## Steps

### 1. Tabs and live counts
- **Action**: open Battle Monitor.
- **Expect**: tabs Voting/Active/Pending/Completed/All, each with a live count matching the actual row counts.

### 2. Resolve requires confirmation
- **Action**: click Resolve on a voting-status battle.
- **Expect**: confirm dialog "Resolve this battle now and declare a winner?" appears before anything fires; confirming resolves it with a dismissible green success notice.
- **On fail**: `includes/Admin/BattleMonitor.php` — Resolve wired without the shared confirm-dialog pattern.

### 3. Cancel requires confirmation, safe default focus
- **Action**: click Cancel on a pending/accepted/active battle.
- **Expect**: confirm dialog "Cancel this battle? This cannot be undone." with default focus on the safe (non-destructive) option.

### 4. Delete requires confirmation
- **Action**: click Delete on any battle.
- **Expect**: confirm dialog "Permanently delete this battle and all its data?" before the delete fires.

### 5. Stale/concurrent action shows a clean notice, not a fatal
- **Action**: open Battle Monitor in two admin tabs; in tab 1, resolve a battle; in tab 2 (stale), click Resolve on the SAME now-`completed` battle.
- **Expect**: tab 2's action redirects with a specific red notice ("That battle no longer exists — it may have already been deleted." or an equivalent "already resolved" message) — never a false success and never a fatal.
- **On fail**: `includes/Admin/BattleMonitor.php` handler — missing a fresh status re-check before acting.

### 6. Cancel on an already-completed/cancelled battle
- **Action**: click Cancel on a battle that is already `completed` or `cancelled`.
- **Expect**: clean notice, no state corruption.

### 7. Pagination at scale
- **Action**: with 2000+ battles seeded (or verify the query), page through the list at 50/page.
- **Expect**: real `LIMIT`/`OFFSET` pagination, not a client-side slice of an unbounded query.
- **On fail**: `includes/Admin/BattleMonitor.php` list query.

### 8. Battle expiry — never submitted, never accepted
- **Action**: create a battle, don't accept it; wait past (or backdate) the 48-hour submit deadline; wait for/force the next tick.
- **Expect**: status becomes `expired` — a TERMINAL status alongside `completed`/`declined`, added specifically because an earlier version made expired battles vanish from every list surface.
- **On fail**: `includes/Battles/BattleService.php::resolve_expired()`.

### 9. Expired battle shows as "Expired" everywhere, not silently gone
- **Action**: check the expired battle in Battle Monitor's "All" tab and (if the participant looks) their own battles list.
- **Expect**: clearly labelled "Expired" in every list it would otherwise appear in — never silently disappears.

### 10. Zero-vote voting-status battle expiry path differs from never-submitted
- **Action**: create a battle stuck in `voting` past its `vote_deadline` with zero votes cast.
- **Expect**: resolved by the vote-count resolver (challenger wins the 0-0 tie), NOT marked `expired` — confirm the two distinct code paths (never-submitted vs never-voted) each produce their documented, different outcome.

## Pass criteria

1. Resolve/Cancel/Delete each require their own worded confirm dialog before firing.
2. A stale/concurrent action on an already-resolved/cancelled/deleted battle shows a clean notice, never a fatal or false success.
3. The list uses real server-side pagination at scale.
4. A never-accepted/never-submitted battle expires to a terminal `expired` status and is visibly labelled everywhere, never silently dropped.
5. A zero-vote voting-status battle resolves via the tie rule, not via expiry.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Resolve/Cancel/Delete fires on bare click | confirm-dialog wiring missing | `includes/Admin/BattleMonitor.php` |
| Second admin's stale Resolve click fatals | no fresh status re-check before acting | `includes/Admin/BattleMonitor.php` handler |
| List loads all rows unpaginated | missing LIMIT/OFFSET | `includes/Admin/BattleMonitor.php` list query |
| Expired battle vanishes from lists | status not in the list's WHERE clause / no `expired` case | `includes/Battles/BattleService.php::resolve_expired()` |
