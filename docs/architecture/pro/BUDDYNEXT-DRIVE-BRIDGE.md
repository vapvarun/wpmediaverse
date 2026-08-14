# BuddyNext ← WPMediaVerse: the Space document-drive bridge

**For the BuddyNext team.** Everything MediaVerse owes you is built and live. This is what
to implement on your side, what each answer controls, and how to prove it works.

You should not need to read MediaVerse's source to do this. If you do, that is a gap in
this document — say so and it gets fixed here.

- **Verified against running code:** 2026-08-14
- **Requires:** WPMediaVerse Pro 2.4.0 (drive filters land in 2.4.0)
- **Where your code goes:** `buddynext/includes/Integrations/WPMediaVerseBridge.php`
- **Full design:** `wpmediaverse/plan/document-library.md` §22 (REST contract), §23 (gap
  analysis), §18 (the four questions below)

---

## 1. What you are being asked

MediaVerse holds an **opaque drive id** and asks you about it. It does not know what a Space
is, cannot enumerate them, and must never learn to. Four filters, all live:

| Filter | Fired at | You return |
|---|---|---|
| `mvs_document_drives_for_user` | `REST/DocumentController.php:367` | The drives this member can see |
| `mvs_document_drive_access` | `Documents/PermissionService.php:1320` | `none` \| `read` \| `write` \| `own` |
| `mvs_document_drive_label` | `REST/DocumentController.php:406` | A human name for a drive |
| `mvs_document_drive_visible` | `REST/AbstractDocumentController.php:243` | Whether they may be *told it exists* |

They are frozen. `WPMediaVersePro\Documents\DriveContract` holds the constants, and its
docblocks are the normative description if this file and the code ever disagree.

---

## 2. The four signatures

### 2.1 `mvs_document_drive_access` — the important one

```php
add_filter(
    'mvs_document_drive_access',
    function ( string $level, string $drive_type, int $drive_id, int $user_id ): string {
        if ( 'space' !== $drive_type ) {
            return $level;                    // not ours — leave it alone
        }

        // $drive_id is YOUR space id. MediaVerse never interprets it.
        if ( ! buddynext_space_exists( $drive_id ) ) {
            return 'none';
        }

        $role = buddynext_space_role( $drive_id, $user_id );   // your vocabulary

        switch ( $role ) {
            case 'admin':     return 'own';    // may administer, share, delete anything
            case 'moderator': return 'write';  // see question 4 below
            case 'member':    return 'write';  // may upload  — or 'read' if you decide not
            default:          return 'none';
        }
    },
    10,
    4
);
```

**It returns a level, not a boolean, and that is the whole point.** A bool cannot express
"may contribute but does not own", which is exactly what a Space member is. The ladder is
`none` → `read` → `write` → `own`, weakest first (`DriveContract::ACCESS_LEVELS`).

Return `$level` untouched for drive types you do not own. Another integration may be
answering for them.

**An unrecognised return falls back to `none`,** verified at `PermissionService.php:1322` —
so a typo (`'edit'`, `'admin'`, `true`) closes the drive rather than opening it. That is the
safe direction, but it fails *silently*: if a Space that should be readable is answering
404/403, check your return values against the four literals above before looking anywhere
else.

The result is also **memoised per request per (user, drive)**, so a filter that consults
something changing mid-request will not be re-asked.

### 2.2 `mvs_document_drive_visible` — the security-critical one

Consulted **only after access has already resolved to `none`**. It decides which refusal a
closed drive gives:

| You return | MediaVerse answers | Meaning to the member |
|---|---|---|
| `true` | **403** `mvs_drive_read_only` / `mvs_document_forbidden` | "It exists and is not yours" |
| `false` | **404** `mvs_drive_not_found` | "As far as you are concerned there is nothing here" |

```php
add_filter(
    'mvs_document_drive_visible',
    function ( bool $visible, string $drive_type, int $drive_id, int $user_id ): bool {
        if ( 'space' !== $drive_type ) {
            return $visible;
        }

        // A PRIVATE space is listed in your directory, so 403 tells a member
        // nothing they could not already read.
        // A SECRET space is listed nowhere. Answering 403 for it CONFIRMS THE
        // SPACE EXISTS to anyone who guesses an id — so it must be 404.
        return buddynext_space_is_discoverable( $drive_id, $user_id );
    },
    10,
    4
);
```

**Defaults to `false`**, so a bridge that never answers leaks nothing. If you implement only
one filter carefully, make it this one.

### 2.3 `mvs_document_drives_for_user` — what to show in a picker

```php
add_filter(
    'mvs_document_drives_for_user',
    function ( array $drives, int $user_id ): array {
        foreach ( buddynext_spaces_for_user( $user_id ) as $space ) {
            $drives[] = array(
                'type' => 'space',
                'id'   => (int) $space->id,
            );
        }

        return $drives;
    },
    10,
    2
);
```

Return only drives this member should know about. This feeds `GET /drives`, which the app
and the drive picker read.

### 2.4 `mvs_document_drive_label` — breadcrumbs and pickers

```php
add_filter(
    'mvs_document_drive_label',
    function ( string $label, string $drive_type, int $drive_id ): string {
        return 'space' === $drive_type
            ? buddynext_space_name( $drive_id )
            : $label;
    },
    10,
    3
);
```

Cosmetic. Wrong labels are ugly; wrong access is a leak. Do this one last.

---

## 3. Four decisions that are yours, not ours

MediaVerse builds without the answers — the contract can express any of them. But your
bridge cannot be written without deciding:

1. **Does every Space get a drive automatically, or does an owner enable it?**
2. **Do `secret` Spaces get drives at all?** If yes, an unauthorised caller must get **404,
   never 403** — see 2.2.
3. **Does a child Space inherit its parent's drive?**
4. **Does `moderator` imply `edit`? May a plain `member` upload, or only read?**

---

## 4. Proving it works

Run each row as a real member, not as an administrator. Administrators pass everything and
prove nothing.

| Test | Expect |
|---|---|
| Member of a public Space opens its drive | 200, documents listed |
| Same member uploads | 200 if you returned `write`, 403 `mvs_drive_read_only` if `read` |
| Non-member opens a **private** Space drive | **403** — it is listed in your directory, so this leaks nothing |
| Non-member opens a **secret** Space drive | **404** — 403 would confirm it exists |
| Non-member guesses a document id inside either | **404** `mvs_document_not_found` |
| Space admin deletes another member's document | 200 if you returned `own` |
| Member whose role has `use_mvs_documents` revoked | **403** `mvs_documents_unavailable`, *whatever your filters return* |

That last row matters: **feature access runs before drive authority and your bridge cannot
override it.** A member whose role had documents switched off must stay switched off, even
if they are a Space admin. Layer 1 refuses before any drive is resolved, which is also what
lets it refuse without leaking whether a Space exists.

Full refusal-code table: `plan/document-library.md` §22.

---

## 5. What MediaVerse owes you and has NOT finished

**Honest gap, so you do not discover it mid-implementation.**

There is **no `space` privacy level**. `DocumentSettings::PRIVACY_VALUES` is
`private | members | public`. So a document on a Space drive can be:

- `private` — only the uploader (plus grants)
- `members` — **every signed-in member of the site**, not of the Space
- `public` — anyone

There is currently no way to say *"visible to this Space and only this Space"*. Your filters
control **who may reach the drive**, which is most of the way there — a non-member gets 404
or 403 at the drive before privacy is consulted. But a document set to `members` is readable
by any signed-in member who reaches it through another route.

Tracked on our side. It does not block you starting: the filters are stable and the level
will be additive.

---

## 6. What is already done, so you do not rebuild it

| | State |
|---|---|
| `drive_type` / `drive_id` on every document | Built — Migrator v29 stamps them |
| `can_write_drive` — contribute distinct from own | Built |
| `GET /drives` | Built |
| `?drive=space:N` on the documents list | Built |
| Departing-member reassignment (T1) | Built — a Space keeps its files when someone leaves |
| Trash, restore, sharing, search, folders | Built, drive-agnostic |

**Not yours to build:** MediaVerse's Space Documents UI does not exist and is not planned —
you own the tabs and views. Do not set `post_author` to a Space id; it is always a real
person, for quota and GDPR.

---

## 7. If something does not work

Two failure modes account for most of it:

1. **Your filter never runs.** Check the priority and argument count — all four need their
   `$accepted_args` (4, 4, 2, 3 respectively). A filter registered with the default `1` gets
   only the first argument and silently returns the wrong thing.
2. **You answered for a drive type you do not own.** Always guard on
   `'space' !== $drive_type` and return the incoming value unchanged.

The refusal codes are frozen and machine-readable (`DriveContract::FROZEN`) — if a client
sees a code not in that list, that is our bug, not yours.
