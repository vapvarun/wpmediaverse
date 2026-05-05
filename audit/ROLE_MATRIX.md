# WPMediaVerse — Role Permission Matrix

**Generated:** 2026-04-29 · **1.2.0 actions appended:** 2026-05-03

Maps every user-facing feature to the WordPress roles that can access it. Capabilities are defined in `includes/Capabilities/MediaCapabilities.php` and assigned to roles by `MediaCapabilities::map_to_roles()`.

Legend: **C**=Create · **R**=Read · **U**=Update · **D**=Delete · **—**=No access

---

## 1. Core media features (Free)

| Feature | Anonymous | Subscriber | Contributor | Author | Editor | Admin |
|---|---|---|---|---|---|---|
| Browse public media | R | R | R | R | R | R |
| Upload media | — | C | C | C | C | C |
| Edit own media | — | U | U | U | U | U |
| Edit others' media | — | — | — | — | U | U |
| Delete own media | — | D | D | D | D | D |
| Delete others' media | — | — | — | — | D | D |
| View private/gated media | — | R (if granted) | R (if granted) | R (if granted) | R | R |
| React (emoji) | — | C | C | C | C | C |
| Comment on media | — | C | C | C | C | C |
| Edit own comment | — | U | U | U | U | U |
| Edit others' comments | — | — | — | — | U | U |
| Favorite media | — | C/D | C/D | C/D | C/D | C/D |
| Follow users | — | C/D | C/D | C/D | C/D | C/D |
| Send DM | — | C (per `mvs_dm_access`) | C | C | C | C |
| Report media/user | — | C | C | C | C | C |
| Block user | — | C/D | C/D | C/D | C/D | C/D |
| Record download (1.2.0) | R (privacy + global toggle) | R | R | R | R | R |
| Record share (1.2.0) | R (privacy gate) | R | R | R | R | R |
| Edit own media — `allow_download` toggle (1.2.0) | — | U | U | U | U | U |

Capability map:

| Capability | Roles |
|---|---|
| `read_mvs_media` | all |
| `upload_mvs_media` | subscriber+ |
| `edit_mvs_media` | subscriber+ |
| `edit_others_mvs_media` | editor, admin |
| `delete_mvs_media` | subscriber+ |
| `delete_others_mvs_media` | editor, admin |
| `publish_mvs_media` | subscriber+ |
| `moderate_mvs_media` | editor, admin |
| `manage_mvs_settings` | admin |
| `manage_mvs_access` | editor, admin |

---

## 2. Albums + Collections

| Feature | Anonymous | Subscriber | Contributor | Author | Editor | Admin |
|---|---|---|---|---|---|---|
| Browse public albums | R | R | R | R | R | R |
| Create album | — | C | C | C | C | C |
| Update own album | — | U | U | U | U | U |
| Delete own album | — | D | D | D | D | D |
| Manage album access rules | — | — | — | — | C/U/D | C/U/D |
| Create collection (private) | — | C | C | C | C | C |

---

## 3. Moderation

| Feature | Anonymous | Subscriber | Contributor | Author | Editor | Admin |
|---|---|---|---|---|---|---|
| View moderation queue | — | — | — | — | R | R |
| Approve flagged media | — | — | — | — | U | U |
| Reject flagged media | — | — | — | — | U | U |
| Delete flagged media | — | — | — | — | D | D |
| View report counts | — | — | — | — | R | R |

---

## 4. Admin pages

All admin pages require `manage_options` or `manage_mvs_settings` (admin only) — except Moderation (editor + admin).

| Page | Editor | Admin |
|---|---|---|
| Overview / Dashboard | — | R |
| All Media (admin list) | — | R/U/D |
| All Media — Bulk Trash (1.2.0) | — | D (`manage_options` OR `moderate_mvs_media`) |
| All Media — Bulk Restore from Trash (1.2.0) | — | U |
| All Media — Bulk Delete-permanently (1.2.0) | — | D |
| Settings | — | R/U |
| Stats | — | R |
| Moderation | R/U/D | R/U/D |
| Logs | — | R |
| Setup Wizard | — | R/U |

**Notes on 1.2.0 bulk actions:**
- All three bulk actions are gated by `wp_nonce_field('mvs_bulk_media')` + a capability check on each row.
- Capability fallback: if the user lacks `manage_options`, `moderate_mvs_media` is checked (so editors can also moderate via this UI when granted that custom cap).
- `permanently_delete_media()` helper: extracted from the single-row delete path; now reused for both single + bulk permanent delete (file-system + `mvs_media_index` + `mvs_media_meta` + `mvs_media_views` + `mvs_media_stats` + `mvs_album_items` cleanup).

---

## 5. Tag taxonomy

| Feature | Anonymous | Subscriber+ | Editor | Admin |
|---|---|---|---|---|
| Browse tags / tag cloud | R | R | R | R |
| Create tag | — | C | C | C |
| Rename tag | — | — | — | U |
| Delete tag | — | — | — | D |
| Merge tags | — | — | — | U |

---

## 6. Pro features (when active — see `../../wpmediaverse-pro/audit/ROLE_MATRIX.md`)

Pro inherits Free's capability model. Pro's competition + advanced features add their own permission checks:

| Feature | Anonymous | Subscriber+ | Admin |
|---|---|---|---|
| View challenges/battles/tournaments | R | R | R |
| Submit entry | — | C | C |
| Vote in battle/challenge/match | — | C | C |
| Create challenge / tournament | — | — | C |
| Cancel challenge | — | — (unless creator) | D |
| View own quota | — | R | R |
| Assign quota package | — | — | U |
| View video heatmap | — | R (own) | R |
| Generate captions | — | C (own) | C |
| Connect platform (Flickr) | — | C/D | C/D |
| Pro admin pages | — | — | R/U |
