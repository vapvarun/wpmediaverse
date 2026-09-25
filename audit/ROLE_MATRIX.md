# WPMediaVerse — Role Matrix

> **Expected allow/deny per role, per surface.** The oracle for the role ladder
> (`wp-card-qa` §1.5) and for `wppqa_probe_roles`. When a walk disagrees with this
> table, one of the two is wrong — both outcomes are findings, neither is noise.
>
> **Derived from code, not from feature names.** Sources:
> `includes/Capabilities/MediaCapabilities.php::get_role_caps()`,
> `includes/Services/PrivacyService.php`, and the `add_submenu_page()` calls in
> `includes/Admin/*.php`. Re-derive when any of those change.

Legend: **Y** allowed · **N** denied · **O** own content only · **–** not
applicable · **?** unverified (treat as BLOCKED, not as Y)

## Capability grants

One row per capability, straight from `get_role_caps()`. Legacy plural caps
(`edit_mvs_medias` etc.) mirror their singular and are omitted.

| Capability | anonymous | subscriber | contributor | author | editor | administrator |
|---|---|---|---|---|---|---|
| `read_mvs_media` | N | Y | Y | Y | Y | Y |
| `upload_mvs_media` | N | Y | Y | Y | Y | Y |
| `publish_mvs_media` | N | Y | Y | Y | Y | Y |
| `use_mvs_documents` | N | Y | Y | Y | Y | Y |
| `edit_mvs_media` | N | O | O | O | Y | Y |
| `delete_mvs_media` | N | O | O | O | Y | Y |
| `edit_others_mvs_media` | N | N | N | N | Y | Y |
| `delete_others_mvs_media` | N | N | N | N | Y | Y |
| `moderate_mvs_media` | N | N | N | N | Y | Y |
| `manage_mvs_access` | N | N | N | N | Y | Y |
| `manage_mvs_settings` | N | N | N | N | N | Y |

Community model, not a blog: **every member role uploads and publishes
immediately** — there is no submit-for-review step (settled on Basecamp
#9962830813). A card claiming "subscriber can't publish" is describing a
defect, not a design.

Grants can be overridden per site via Settings → Permissions
(`mvs_role_caps_overrides`). Before bouncing a permission card, check that
option on the reporter's site — an owner-made override is not a bug.

## Surfaces

`member-other` is a second **subscriber** account. Ownership and role are
different questions; most permission bugs live in the gap between these two
columns.

| Surface | Route / endpoint | anonymous | member-owner | member-other | elevated (editor) | admin |
|---|---|---|---|---|---|---|
| Explore feed | `/explore-media/` | Y (public items only) | Y | Y | Y | Y |
| Explore documents | `/explore-document/` | Y (public only) | Y | Y | Y | Y |
| My Media dashboard | `/my-media/` | N → login | Y | Y (their own) | Y | Y |
| Upload | `/upload-media/` | N → login | Y | Y | Y | Y |
| Single media (public) | `media-single.php` | Y | Y | Y | Y | Y |
| Single media (private) | `media-single.php` | N | Y | **N** | Y | Y |
| Edit media | dashboard / REST | N | O | **N** | Y | Y |
| Delete media | dashboard / REST | N | O | **N** | Y | Y |
| Albums / collections | `album.php`, `collection.php` | follows item privacy | O | per privacy | Y | Y |
| Messages / DM | `messages.php` | N → login | Y | Y (own threads) | Y | Y |
| Profile edit | `profile-edit.php` | N → login | O | **N** | O | Y |
| REST `mvs/v1` read | `/wp-json/mvs/v1/*` | public items only | Y | Y | Y | Y |
| REST `mvs/v1` write | `/wp-json/mvs/v1/*` | N (401) | O | **N** (403) | Y | Y |

### wp-admin pages

Every page is registered with `manage_options`. Four render callbacks
*additionally* accept `manage_mvs_settings`; the rest do not.

| Page | Slug | subscriber–author | editor | admin |
|---|---|---|---|---|
| Overview | `wpmediaverse` | N | N | Y |
| Moderation queue | `mvs-moderation` | N | **N** (see gaps) | Y |
| Reports (Moderation tab; `mvs-reports` hidden, still routable) | `mvs-moderation&tab=user-reports` | N | N | Y |
| Stats | `mvs-stats` | N | N | Y |
| MediaVerse Logs (under Tools) | `tools.php?page=mvs-logs` | N | N | Y |
| Integrations (Overview card; hidden from the sidebar) | `mvs-integrations` | N | N | Y |
| Setup wizard | `mvs-setup` | N | N | Y |

## Visibility levels

From `PrivacyService` (rank in brackets: higher = more restrictive). Owner
always sees their own item at every level.

| Level | anonymous | member-owner | member-other | elevated | admin |
|---|---|---|---|---|---|
| `public` (0) | Y | Y | Y | Y | Y |
| `members` (20) | N | Y | Y | Y | Y |
| `loggedin` | N | Y | Y | Y | Y |
| `friends` (40) | N | Y | only if BP friends | Y | Y |
| `group` (60) | N | Y | only if BP group member | Y | Y |
| `private` (80) | N | Y | N | Y | Y |
| `space` | N | Y | ? | ? | Y |
| `dm` | N | Y | ? | ? | Y |
| `custom` (90) | N | Y | ? (grant rows) | ? | Y |

**BuddyPress inactive:** `friends` and `group` **fall back to `private`**
(`PrivacyService::check_friends()` / `check_group()`). A card reporting
"friends-only media hidden from my friend" on a site without BuddyPress is
correct behaviour — record it as REFUTED with this line.

`space`, `dm`, `custom` are marked `?` because their rules are grant-row driven
and were not derived for this matrix. Walk them, then replace the `?`.

## Known bypasses

| Bypass | Applies to | Why it is correct |
|---|---|---|
| Administrator | every capability, ownership and privacy gate | WP core `manage_options` + all `mvs_*` caps. **A privacy or permission card confirmed only as admin is not confirmed.** |
| Editor | ownership gates (`*_others_*`), moderation, access grants | Deliberate: editor is the moderator role in this plugin |

## Deliberate denials and known gaps

| Surface | Role | Denied because |
|---|---|---|
| Publish without review | any member | Not denied — community model. Card claiming otherwise is a defect |
| `friends` / `group` visibility | member-other, no BuddyPress | Falls back to `private` by design |
| Moderation queue (wp-admin) | editor | **Gap, not design.** Editor holds `moderate_mvs_media` but the page is gated on `manage_options`, so the cap is unreachable from wp-admin. Frontend/REST moderation paths should be walked before filing; if none exist, this is a defect against the three-entry-points rule |
