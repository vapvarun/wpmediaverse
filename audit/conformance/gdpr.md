# Conformance — GDPR / Data Lifecycle

**Feature:** Personal-data export, erasure, and member data lifecycle
**Repo:** free + pro
**Spec ref:** Wbcom **Data Lifecycle Standard v1.0** §9 (export + erasure, actor *and* target), §9a (ERASE/RETAIN lists + gate), "`done` must be earned"
**Live-walk:** WP Admin → Tools → Export Personal Data / Erase Personal Data
**Verdict:** usable-leave-as-is (was: **non-compliant**)

---

## Summary

MediaVerse stores members across **24 user-keyed tables** (16 Free, 8 Pro). Before 2.1.0, nine of them were invisible to both the exporter and the eraser, Pro registered neither, and the eraser reported `'done' => true` without checking.

Every one of those is now closed. Export, erasure, the account-deletion purge and the residue verifier are **all generated from one map** (`Privacy\MemberDataMap`), and a build gate (`bin/check-erasure.php`, local-CI stage 1.6b) fails on any user-keyed table that is on neither list.

---

## What was wrong

| # | Defect | Severity |
|---|---|---|
| 1 | **Pro registered no exporter and no eraser at all.** An erasure request on a Pro site silently left behind push devices, boosts, credit ledger, competition entries and votes, and playback analytics. | **P0** |
| 2 | **`mvs_pro_push_devices` survived deletion.** The site kept a live push channel to the phone of someone who had asked to be forgotten. | **P0** |
| 3 | **The eraser hard-coded `'done' => true`.** Core takes `done` at its word and emails the member to say their data is gone. It could say so while the data was still there. | **P0** |
| 4 | `mvs_transactions` (60 rows) and `mvs_error_log` (162 rows) — both Free, both user-keyed — were in no exporter and no eraser. | P1 |
| 5 | **Target columns were not erased, only actor columns.** `mvs_reports.target_id`, `mvs_notifications.actor_id`, and the reverse directions of `mvs_follows` / `mvs_blocks` left the erased member scattered through *other* members' rows (§9). | P1 |
| 6 | No gate. §9a existed in prose in the standard; nothing enforced it. | P1 |

BuddyNext's own `PrivacyTools` docblock names the hand-off explicitly — media and DMs *"must be exported / erased by WPMediaVerse's own privacy integration"*. **That contract was only half-kept:** Free honoured it, Pro did not.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Every user-keyed table is classified ERASE or RETAIN, once | contract | wired | `includes/Privacy/MemberDataMap.php` — 16 Free entries; Pro adds 8 via filter |
| 2 | Pro registers its tables through the Free seam (no duplicate exporter/eraser/purge) | free→pro | wired | `wpmediaverse-pro/includes/Privacy/ProMemberData.php`; filters `mvs_member_erase_map` / `mvs_member_retain_map`; booted at `Pro Plugin.php:406` |
| 3 | Admin runs Tools → Export Personal Data | ui→core | wired | `Services/GDPRService::register_exporters()` → `export_mapped()` |
| 4 | Export covers **every** mapped table, incl. Pro and the retained ones | service→db | wired | verified: 24 groups returned, incl. Push devices, Playback analytics, Credit history |
| 5 | Retained rows are exported **with the reason they are kept** | service | wired | `export_mapped()` appends "Why this is kept" (reason + legal basis) |
| 6 | Admin runs Tools → Erase Personal Data | ui→core | wired | `register_erasers()` → `erase_member()` |
| 7 | Purge sweeps every ERASE table, on **all** its user columns (actor *and* target) | service→db | wired | `Privacy\MemberPurger::purge()` generated from `erase_map()` |
| 8 | RETAIN tables are **anonymised**, not left intact (`user_id → 0`) | service→db | wired | `MemberPurger::purge()` UPDATE branch; verified rows survive with id 0 |
| 9 | `done` is **earned** — counted, not asserted | service→db | wired | `MemberPurger::residue()` COUNTs from the *same* map; `erase_member()` returns `done => 0 === $left` |
| 10 | Account deletion (in-app, App Store 5.1.1(v)) purges the same tables | rest→service | wired | `mvs_user_data_purged` → `MemberPurger::purge()` (`Plugin.php:253`) |
| 11 | A new user-keyed table cannot ship unclassified | ci | wired | `bin/check-erasure.php`; local-CI stage **1.6b**. Exits 1 on an unclassified table, 0 when all 24 are classified — both verified |

---

## First break

none — journey complete.

---

## The two lists

**ERASE (20)** — deleted outright.
Free: `mvs_media_index` (post_author), `mvs_media_views`, `mvs_favorites`, `mvs_reactions`, `mvs_activity`, `mvs_access_grants`, `mvs_mentions` (mentioned_user_id), `mvs_follows` (**both directions**), `mvs_blocks` (**both directions**), `mvs_notifications` (**user_id + actor_id**), `mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions`, `mvs_error_log`.
Pro: `mvs_pro_push_devices`, `mvs_pro_collection_items`, `mvs_play_events`, `mvs_boosts`, `mvs_competition_entries`, `mvs_competition_votes`.

**RETAIN (4)** — kept, anonymised, with a stated basis.

| Table | Why | Basis |
|---|---|---|
| `mvs_reports` | A report is a case **somebody else filed**. If erasing your account deleted the reports about you, an abuser could wipe the evidence by leaving — and the moderator loses the record of why they acted. | Legitimate interest (safety of other users; integrity of the moderation record). `reporter_id` / `target_id` → 0. |
| `mvs_transactions` | Usage/billing ledger the site owner may be legally required to keep. | Legal obligation (accounting). `user_id` → 0. |
| `mvs_credit_log` (Pro) | Payment ledger. | Legal obligation (accounting). `user_id` → 0. |
| `mvs_competitions` (Pro) | A competition is a **community asset other people took part in**. Erasing one participant must not destroy the event for everyone else. | Legitimate interest (integrity of a shared record). `winner_id` → 0. |

---

## Verification

Seeded a throwaway member into **every** mapped table (28 rows across 24 tables), then ran the real core exporter and eraser callbacks:

- Export: **28 items across 24 groups**, including every Pro table.
- Erase: `removed=true retained=true` **`done=true` at residue 0**.
- RETAIN tables: `still-mine=0`, rows survive **anonymised**.
- Messages returned to the member name each retained category and why.

Gate: `✓ all 24 user-keyed tables classified` (exit 0); adding an unclassified table → exit **1**.

---

## Notes on scale / contract

- The purge is **batched (500/table/pass) and resumable** — core re-calls the eraser while `done` is false, so an oversized member cannot half-erase on a timeout.
- `MemberPurger` guards on `SHOW TABLES` / `SHOW COLUMNS`, so a Pro-less install and a schema rename both degrade to a no-op rather than a fatal — and a renamed column cannot make the purge silently delete nothing.
- **The delete list and the verify list are the same list.** Two hand-maintained lists drift; one cannot.

---

## Still open

- `uninstall.php` completeness (§10) is not gated: every table the Migrator creates should be dropped on uninstall, and nothing checks it.
- Retention windows (§7) for high-volume append-only tables — `mvs_play_events`, `mvs_media_views`, `mvs_error_log` grow with activity and declare no retention window.
