---
journey: gdpr-export-erase
plugin: wpmediaverse
priority: critical
roles: [administrator]
covers: [MV-PRV-001, MV-PRV-002, MV-PRV-003, MV-PRV-004, MV-PRV-008, MV-PRV-009, gdpr-export, gdpr-erase, remove-data-on-uninstall, privacy-policy-content]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-admin access as an administrator"
  - "A test member with a full spread of data: media (including a private item), comments, reactions, follows, favorites, reports filed, DM history (sent messages)"
estimated_runtime_minutes: 8
---

# Tools > Export/Erase Personal Data cover every MediaVerse data category consistently, and the site's Privacy Policy gets accurate suggested content

**Why this matters**: `GDPRService` registers export/erase from ONE `MemberDataMap` so the exporter and eraser can never disagree about what "all their data" means — a real defect class the plugin fixed by replacing an older hand-written eraser pair (see MV-PRV-004).

## Setup

- Test member `$MEMBER` with: a public media item, a private media item, a comment, a reaction, a follow relationship (both directions), a favorite, a filed report, and at least one sent DM message to another member.
- Note `$MEMBER`'s email address for the export/erase request forms.

## Steps

### 1. "MediaVerse (all data)" exporter is registered and named (MV-PRV-001)
- **Action**: wp-admin → Tools → Export Personal Data → request an export for `$MEMBER`'s email.
- **Expect**: the exporter list includes "MediaVerse (all data)" by NAME (`wpmediaverse-all` registered via `wp_privacy_personal_data_exporters`) — visible in the list, not merely inferred to have run.
- **Action**: run the export ("Send Export Link" or download directly if available); open the resulting file.
- **Expect**: it includes `$MEMBER`'s media, social data (reactions/follows/favorites), sent DM message content, and the usage ledger / error-log references where they mention the member — everything the code comment calls out as easy to forget.
- **Action**: for a member with 100+ rows across categories (simulate if practical), request the export.
- **Expect**: the exporter's callback accepts a `$page` argument and completes across multiple pages rather than timing out on one giant pass.

### 2. "MediaVerse Media" exporter includes PRIVATE items (MV-PRV-002)
- **Action**: in the same (or a fresh) export request, locate the "MediaVerse Media" section.
- **Expect**: `wpmediaverse-media` lists EVERY media item `$MEMBER` authored, UNFILTERED — the private item from Setup IS present. An exporter that silently dropped private items would under-disclose (an Article 15 risk the code explicitly guards against).
- **Action**: request an export for a member with ZERO media.
- **Expect**: the "MediaVerse Media" section is still present, just empty — not omitted entirely (consistent structure across members).

### 3. "MediaVerse Social Data" exporter — scoped correctly, not over- or under-claimed (MV-PRV-003)
- **Action**: locate the "MediaVerse Social Data" section in the export.
- **Expect**: covers reactions, follows, and favorites. It does NOT include comments (WordPress core's own comment exporter covers those) and does NOT include reports filed by the member (those are covered only by "MediaVerse (all data)", MV-PRV-001) — confirm both these exclusions hold, since an earlier version of this catalog entry claimed otherwise.
- **Expect**: DM message content is NOT in this section either — sent-message content appears only under "MediaVerse (all data)" (and only the member's OWN sent messages, never the other participant's).
- **Action**: request an export for a large account's reactions/follows/favorites.
- **Expect**: `wpmediaverse-social` does NOT paginate — it completes in a single page regardless of volume (confirm it doesn't drop rows on a large account despite the single pass).

### 4. "MediaVerse" eraser — one eraser, bidirectional cleanup (MV-PRV-004)
- **Action**: Tools → Erase Personal Data → request erasure for `$MEMBER`'s email; run it to completion.
- **Expect**: exactly ONE MediaVerse eraser (`wpmediaverse`) runs, progressing/completing visibly on the Erase Personal Data screen.
- **Action**: as a SECOND member who had followed/blocked/reported `$MEMBER` before erasure, check your own `mvs_follows`/`mvs_blocks`/`mvs_reports` rows referencing `$MEMBER`.
- **Expect**: those rows referencing the now-erased member are cleaned up too — NOT just `$MEMBER`'s own rows (bidirectional cleanup).
- **Action**: with `$MEMBER` having been an active participant in a DM conversation with a non-erased Member C (who is still active), re-run erasure for `$MEMBER`.
- **Expect**: the conversation thread is NOT destroyed for Member C as long as Member C remains an active participant; `$MEMBER`'s own participation row and every message THEY sent are PERMANENTLY deleted (not anonymized) — Member C sees that side of the conversation disappear, but the thread and Member C's own messages remain intact.
- **Action**: if erasing `$MEMBER` would leave a conversation with zero participants at all, check that thread.
- **Expect**: it is cleaned up entirely.
- **Action**: re-run the erasure request for the SAME (already-erased) member.
- **Expect**: a clean no-op, not an error.

### 5. Remove Data on Delete — uninstall cross-reference (MV-PRV-008)
- **Action**: `wp option update mvs_delete_data_on_uninstall 1`; note the current table count: `wp db query "SHOW TABLES LIKE '${TABLE_PREFIX}mvs_%'" | wc -l` (should be 23 per `Migrator::tables()`).
- **Action**: (destructive — run on a disposable test site only) uninstall the plugin via wp-admin → Plugins → Delete (after deactivating).
- **Expect**: ALL 23 `mvs_*` tables are dropped, sourced LIVE from `Migrator::tables()` (not a second hand-maintained list that can drift), plus related options/postmeta/capabilities. Uploaded files under `wp-content/uploads/wpmediaverse/` and the pages the plugin created are NOT touched either way.
- **Action**: if WPMediaVerse Pro is still installed at uninstall time, repeat.
- **Expect**: Free's uninstall does NOT drop tables Pro still needs.

### 6. Privacy Policy content suggestion (MV-PRV-009)
- **Action**: wp-admin → Settings → Privacy → open the Privacy Policy page editor (create one if the site prompts for it).
- **Expect**: a MediaVerse-suggested content block appears (via `add_privacy_policy_content()` on `admin_init`); clicking to add it inserts the suggested text into the policy.
- **Action**: read the inserted text against what the plugin actually does per this catalog.
- **Expect**: it accurately covers EXIF/location stripping, reactions/comments/follows/favorites/mentions, that DMs are stored so participants can read them, view tracking, that AI tagging/moderation (if enabled) sends uploaded images to OpenAI, device push tokens for the mobile app, and a plain-English note of what erasure keeps and why (reports, usage records, shared conversations) — report any inaccuracy found against the plugin's actual current behavior, not just against the text itself.

## Pass criteria

ALL of the following hold:
1. "MediaVerse (all data)" is named and listed, paginates for large accounts, and includes every data category including the easy-to-forget ones.
2. "MediaVerse Media" includes private items unfiltered and shows an empty-but-present section for a member with none.
3. "MediaVerse Social Data" covers exactly reactions/follows/favorites (not comments, not reports, not DM content) and does not paginate.
4. The single "MediaVerse" eraser cleans up the erased member's own data AND other members' rows referencing them; DM conversations survive for a remaining active participant while the erased member's own messages are permanently removed; a conversation left with zero participants is cleaned up; re-running on an already-erased member is a no-op.
5. "Remove Data on Delete" drops exactly the live table list at uninstall, never touches uploaded files or created pages, and never drops tables Pro still needs.
6. The suggested Privacy Policy content is present, insertable, and factually accurate against current plugin behavior.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| "MediaVerse (all data)" missing from the exporter list | `wp_privacy_personal_data_exporters` filter not hooked | `includes/Services/GDPRService.php::register_exporters()` |
| Private media absent from the "MediaVerse Media" export | export query filtering by privacy instead of pulling everything | `includes/Services/GDPRService.php::export_media()`, `includes/Repository/MediaRepository.php::author_media_export_rows()` |
| DM content leaks into "MediaVerse Social Data" | scope creep in the social exporter | `includes/Services/GDPRService.php::export_social()` |
| Another member's `mvs_follows`/`mvs_blocks`/`mvs_reports` row referencing the erased member survives | eraser only scoped to the erased member's OWN rows | `includes/Services/GDPRService.php::erase_member()` |
| Uninstall leaves a stray `mvs_*` table | a table added to `Migrator::tables()` without the uninstall routine re-reading the live list | `Migrator::tables()`, the plugin's `uninstall.php` |
| Privacy Policy suggestion missing or stale | `add_privacy_policy_content()` not hooked on `admin_init`, or text not updated alongside a new data-collecting feature | `includes/Services/GDPRService.php` |
