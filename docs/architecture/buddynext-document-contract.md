# The document REST contract — for BuddyNext

**Status: FROZEN as of 2026-08-12 (PR1).** Verified against BuddyNext **1.1.5** (`71511f48`) and
WPMediaVerse Free + Pro **2.4.0**.

BuddyNext consumes `mvs/v1` and `mvs-pro/v1` and nothing else — no shared PHP, no template
overrides, no direct table reads. So everything BN needs has to be **discoverable through the API**,
not merely enforced behind it. This document is the part BN can rely on not moving.

The freeze lives in code as well as here: `WPMediaVersePro\Documents\DriveContract`, enforced by
`bin/coding-rules-check.sh` **Rule 8** — a refusal code emitted by any document surface and not
declared in the contract fails the build. A frozen list that nothing asserts drifts the first time
somebody adds a route in a hurry.

---

## 1. Four layers, asked in this order

| # | Layer | Question | Decided by | State |
|---|---|---|---|---|
| 1 | Feature access | May this account have a library at all? | `use_mvs_documents` → `mvs_user_can_use_documents` | **shipped** |
| 2 | Drive authority | May they read / write / administer THIS drive? | `mvs_document_drive_access` | **PR3** |
| 3 | Privacy | May they read THIS document? | `PermissionService::can_view()` + grants | **shipped** |
| 4 | Ownership | Whose upload is this, for quota and GDPR? | `post_author`, always a real person | **shipped** |

**The order is load-bearing.** Layer 1 runs before any drive is resolved, which is what lets it
refuse without leaking whether a Space exists. Layer 2 must never answer layer 1 — a bridge
answering the drive filters must not be able to re-grant a member the feature their role switched
off.

**Layer 4 is never a scoping mechanism.** `post_author` is the uploader, always a WP user, never a
Space id.

---

## 2. Refusal codes — frozen

A client cannot behave well if every failure is a 403.

| Situation | Status | Code | What the client should do |
|---|---|---|---|
| Not signed in | 401 | `mvs_unauthorized` | send to sign-in |
| Documents not available to this account | 403 | `mvs_documents_unavailable` | **hide the tab.** Do not retry, do not offer sign-in |
| Site toggle off | route absent | `rest_no_route` | hide |
| Drive not visible (incl. secret Space) | **404** | `mvs_drive_not_found` | treat as no such drive |
| Drive visible, contents not readable | 403 | `mvs_drive_forbidden` | offer the way in (join / request), not the library |
| Drive visible and readable, not writable | 403 | `mvs_drive_read_only` | show the library, hide upload |
| Document not readable | **404** | `mvs_document_not_found` | treat as missing |
| Document readable, not editable | 403 | `mvs_document_forbidden` | show it, hide edit |
| Type refused | 400 | `mvs_document_type_not_allowed` | name the type (`data.doc_type`) |
| Too large | 400 | `mvs_document_too_large` | check `documents.max_size` first, refuse locally |
| Link sharing off | 403 | `mvs_link_sharing_disabled` | hide the option |
| Scanner rejected the file | 400 | `mvs_document_scan_failed` | not a type problem — do not suggest another format |

**403 vs 404 is not cosmetic.** 403 means "exists, not for you"; 404 means "you may not know whether
it exists". Feature access is 403 because the account's own permission is not a secret from the
account. A secret Space is 404 because its existence *is* the secret.

Anything not in this table: treat as "refused, show the message" rather than branching on it. The
full declared set is `DriveContract::DECLARED`.

### Two of these are not emitted yet — deliberately

`mvs_drive_not_found` and `mvs_drive_read_only` arrive with PR3. They are frozen **ahead** of the
code that raises them because BN cannot write the "hide upload, keep the library" branch after the
fact without a second coordinated release. Build against them now; they will start appearing.

### Known gaps this freeze records rather than hides

Two things in the shipped code do not match this table yet. Both are PR3's to fix, and both are
written down so BN is not surprised:

1. **`mvs_forbidden` (403) is emitted for "That is not your drive"** in `FolderController` on folder
   create and folder write. Under this contract that situation is `mvs_drive_read_only` (403) when
   the drive is visible, or `mvs_drive_not_found` (404) when it is not. **As it stands it would leak
   a secret Space's existence** the moment Spaces ship, because a generic 403 confirms the drive is
   there. PR3 must split it.
2. **`mvs_grant_user_unknown` carries 400 in `PermissionService` and 404 in `PermissionController`**
   — the same code with two statuses depending on which path produced it. A client branching on the
   code sees inconsistent statuses. Not frozen in the table above for that reason; pick one in PR3.

---

## 3. Drive-authority filters — frozen names

Answered by the BuddyNext bridge (PR4). Kept **separate** from the feature-access filters
(`mvs_documents_enabled`, `mvs_user_can_use_documents`) — see the layering rule above.

| Filter | Returns | Job |
|---|---|---|
| `mvs_document_drives_for_user` | array of drive descriptors | which drives this viewer can see |
| `mvs_document_drive_access` | `none` \| `read` \| `write` \| `own` | authority over ONE drive |
| `mvs_document_drive_label` | string | human label for breadcrumbs and pickers |

**`mvs_document_drive_access` returns a level, not a bool.** A bool cannot express "may contribute
but does not own", which is exactly the gap that makes Space uploads impossible today.

`mvs_document_owns_drive` and `mvs_document_can_grant` **become derived** from the access level and
are kept for at least two majors (Production Rule 2). Two independent sources for one question is
how a bridge ends up granting write through one filter and denying it through the other.

---

## 4. Which BuddyNext function answers what

Verified against BN 1.1.5. Naming these here is the point of PR1 — it stops PR4 re-deriving
visibility and disagreeing with BN's own router.

| Contract question | BN function |
|---|---|
| Does this drive exist for this viewer? (the 404 decision) | `Spaces\SpaceVisibility::can_view_space()` |
| May they see what is inside? (the read decision) | `Spaces\SpaceVisibility::can_view_content()` |
| What is their role in this space? | `Spaces\SpaceMemberService::get_role()` |
| Roles across many spaces, without N queries | `Spaces\SpaceMemberService::prime_viewer_roles()` |
| May they administer sharing? | `Core\PermissionService::can_manage_space()` |

**Answer from `SpaceVisibility`, never from `bn_spaces.type` directly.** BN's own router comments say
the decision "comes from the canonical resolver, so this route and the REST contract agree", and
hidden-ness is registry-driven (`SpaceTypeRegistry::is_hidden_from_non_members()`) rather than
hardcoded to `secret` — so a site with a custom space type gets the right answer for free.

### The mapping

The membership vocabulary, as actually stored:

```
bn_spaces.type          ENUM('open','private','secret')
bn_space_members.role   ENUM('owner','moderator','member')     -- note: no 'admin'
bn_space_members.status ENUM('active','pending','invited','banned')
```

| BN state | `mvs_document_drive_access` | Refusal, if any |
|---|---|---|
| role `owner` | `own` | — |
| role `moderator` | `write` — `own` only where `can_manage_space()` is the sharing authority | — |
| role `member`, status active | **`write` or `read` — BN's call per space** | — |
| status `pending` / `invited` | `none` | as non-member below |
| status `banned` | `none` | as non-member below |
| non-member, `can_view_content()` true (open space) | `read` | `mvs_drive_read_only` on write |
| non-member, `can_view_content()` false but space visible (private space) | `none` | **`mvs_drive_forbidden` 403** |
| non-member, `can_view_space()` false (secret space) | `none` | **`mvs_drive_not_found` 404** |

`SpaceMemberService::get_role()` already filters `status = 'active'` and returns null otherwise, so
pending / invited / banned collapse to "no role" without the bridge testing status separately.

### Verified against seeded BN data, 2026-08-12

`wp buddynext demo seed` on BN 1.1.5 produced 14 spaces — 11 open, 3 private, 1 secret — and BN's
own resolver was run over every one of them:

```
                     can_view_space()          can_view_content()
                     anon  outsider  insider   anon  outsider  insider
open    (11 spaces)  ✓     ✓         ✓         ✓     ✓         ✓
private ( 3 spaces)  ✓     ✓         ✓         ✗     ✗         ✓
secret  ( 1 space )  ✗     ✗         ✓         ✗     ✗         ✓
```

**That middle row is why `mvs_drive_forbidden` exists.** A private space is visible — BN shows its
name and a join button — while its contents are not readable by a non-member. §22's original table
had only "not visible → 404" and "read-only → 403", and neither fits.

The same run confirms `get_role()` returns `null` for a non-member and the stored role for an active
one, and that the demo community uses only `owner` and `member` — no `admin`, as the schema says.

**That decision is now made, in §7's bridge: a plain `member` maps to `write`.** An active member of
a space may add to that space's library — the same thing they may already do to its feed — and
`own` stays with the owner alone. It is one line in `drive_access()` if a site wants it otherwise.

---

## 5. `/app/config` is the discovery surface

`GET /mvs/v1/app/config` → `documents` reports `enabled` (**per user**), `max_size`,
`allowed_types`, `allowed_mimes`, `default_privacy`, `anonymous_links`, `preview_tiers`,
`max_folder_depth`, `search`.

**Every value is asked of the resolver that enforces it.** Config that disagrees with enforcement
produces a client that draws a control and then meets a refusal — five separate instances of that
were fixed in 2.4.0, the most recent being `preview_tiers`, which had gone stale and was
under-advertising 7 of 11 document types.

**The app must never hardcode any of this.** A client carrying its own copy of the format list, the
size ceiling or the preview tiers disagrees with the server the moment an owner changes a setting.

---

## 6. What is NOT frozen yet

Departing-member reassignment (§23 G5/G6, PR5). The drive columns, `GET /drives` and the `?drive=`
parameter shipped in PR2 and PR3 and are covered by §3 and §7.

---

## 7. The bridge — drop-in, and verified against real spaces

**BuddyNext owns this file; nothing in it has been applied to BuddyNext.** It is written against
BN branch `1.1.5` (3 commits past the `v1.1.4` tag — the version header still reads 1.1.4) and was
executed against that branch's real `bn_spaces` / `bn_space_members` rows, so the behaviour below is
measured rather than intended. Suggested home: `includes/Bridges/SpaceDriveBridge.php`, registered
where the existing `WPMediaVerseBridge` is.

### Two things to know before reading it

**1. Composing `membership_map()` with `prime_viewer_roles()` corrupts BuddyNext's own cache.**
The first returns `space_id => array( role, status )`; the second expects `space_id => role` and
casts with `(string)`. Passing one straight into the other writes the literal string `"Array"` into
the role cache for every space, and `get_role()` then returns `"Array"` — not null, so **every
viewer reads as a contributing member** — for the rest of the request, to BuddyNext's own callers as
much as to this bridge. Both calls are individually correct, which is why review does not catch it.
The bridge flattens the map first. If BuddyNext would rather fix the shape mismatch at source, that
is the better long-term answer and this bridge simplifies accordingly.

**2. A banned member of an OPEN space keeps `read`.** `get_role()` filters `status = 'active'`, so
banned collapses to "no role", and an open space's content is readable without a role. That matches
what BuddyNext shows on the space page itself, so it is consistent rather than a hole — but if a ban
is meant to remove read access to the library too, that is a decision to make explicitly, and it
belongs in `can_view_content()` where the space page would pick it up as well.

### The implementation

```php
<?php
/**
 * Space document drives — the BuddyNext side of the WPMediaVerse drive contract.
 *
 * WPMediaVerse never queries `bn_*`. It asks four filters and this answers them
 * from BuddyNext's OWN canonical resolvers, so the drive and the space page can
 * never disagree about who may see what.
 *
 * Contract: wpmediaverse/docs/architecture/buddynext-document-contract.md
 *
 * @package BuddyNext
 */

namespace BuddyNext\Bridges;

use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Answers WPMediaVerse's document-drive filters for `space:<id>` drives.
 */
class SpaceDriveBridge {

	/**
	 * Space service.
	 *
	 * @var SpaceService|null
	 */
	private $spaces = null;

	/**
	 * Membership service.
	 *
	 * @var SpaceMemberService|null
	 */
	private $members = null;

	/**
	 * Hydrated space rows, keyed by id, for this request.
	 *
	 * Every filter below needs the row, and a drive listing asks about each of a
	 * member's spaces in one page load.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $space_cache = array();

	/**
	 * Register the four filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'mvs_document_drive_access', array( $this, 'drive_access' ), 10, 4 );
		add_filter( 'mvs_document_drive_visible', array( $this, 'drive_visible' ), 10, 4 );
		add_filter( 'mvs_document_drives_for_user', array( $this, 'drives_for_user' ), 10, 2 );
		add_filter( 'mvs_document_drive_label', array( $this, 'drive_label' ), 10, 3 );
	}

	/**
	 * Resolve `none|read|write|own` for one space drive and one viewer.
	 *
	 * The ladder, in the order the questions actually settle:
	 *
	 *   no space row            -> none  (WPMediaVerse answers 404)
	 *   cannot view content     -> none  (private space, non-member)
	 *   role owner              -> own
	 *   role moderator | member -> write (an active member may contribute)
	 *   otherwise               -> read  (open space, not a member)
	 *
	 * `get_role()` already filters `status = 'active'`, so pending, invited and
	 * banned rows collapse to "no role" without being tested separately here —
	 * a banned member of an OPEN space therefore keeps `read`, which is what
	 * BuddyNext shows them on the space page itself.
	 *
	 * @param string $level      Level resolved so far.
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Drive id (the space id).
	 * @param int    $user_id    Viewer.
	 * @return string
	 */
	public function drive_access( $level, $drive_type, $drive_id, $user_id ): string {
		if ( 'space' !== (string) $drive_type ) {
			return (string) $level;
		}

		$space = $this->space( (int) $drive_id );

		if ( null === $space ) {
			return 'none';
		}

		// The READ decision is BuddyNext's, from the same resolver its own
		// templates and REST routes use — never re-derived from `type`.
		if ( ! SpaceVisibility::can_view_content( $space, (int) $user_id ) ) {
			return 'none';
		}

		$role = $this->members()->get_role( (int) $drive_id, (int) $user_id );

		if ( 'owner' === $role ) {
			return 'own';
		}

		if ( null !== $role ) {
			// moderator and member both contribute. Sharing authority is a
			// separate question and stays with `can_manage_space()`.
			return 'write';
		}

		// Content is readable without membership — an open space.
		return 'read';
	}

	/**
	 * Whether the viewer may be told this space EXISTS.
	 *
	 * Decides 403 vs 404 for a drive they cannot open, and it is a different
	 * question from access: a PRIVATE space is listed in the directory with a
	 * join button, so 403 tells a non-member nothing they could not already
	 * read. A SECRET space is listed nowhere, and answering 403 for it would
	 * confirm the space exists to anyone who guessed an id.
	 *
	 * `can_view_space()` is registry-driven (`is_hidden_from_non_members()`),
	 * not hardcoded to `secret`, so a custom space type is handled for free.
	 *
	 * @param bool   $visible    Default false.
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Drive id (the space id).
	 * @param int    $user_id    Viewer.
	 * @return bool
	 */
	public function drive_visible( $visible, $drive_type, $drive_id, $user_id ): bool {
		if ( 'space' !== (string) $drive_type ) {
			return (bool) $visible;
		}

		$space = $this->space( (int) $drive_id );

		return null !== $space && SpaceVisibility::can_view_space( $space, (int) $user_id );
	}

	/**
	 * The space drives this member can reach.
	 *
	 * Only spaces they ACTIVELY belong to. An open space they have merely read
	 * access to is deliberately absent: this list is "your libraries", and
	 * padding it with every open space on the site would bury the member's own
	 * drives under a site directory.
	 *
	 * @param array $drives  Drives resolved so far.
	 * @param int   $user_id Viewer.
	 * @return array
	 */
	public function drives_for_user( $drives, $user_id ): array {
		$drives  = is_array( $drives ) ? $drives : array();
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return $drives;
		}

		$space_ids = $this->members()->spaces_for_user( $user_id );

		if ( empty( $space_ids ) ) {
			return $drives;
		}

		// One priming call instead of a role lookup per space (the N+1 the
		// contract calls out).
		//
		// THE TWO APIS DO NOT SHARE A SHAPE, and composing them naively corrupts
		// BuddyNext's own cache rather than this bridge's: `membership_map()`
		// returns `space_id => array( role, status )` while `prime_viewer_roles()`
		// expects `space_id => role` and casts with `(string)`, so passing the map
		// straight through writes the literal string "Array" into the role cache
		// for every space. `get_role()` then returns "Array" — not null, so every
		// viewer reads as a contributing member — for the rest of the request, to
		// BuddyNext's own callers as much as to this one. Caught by running the
		// bridge against real spaces; it is invisible to review because both calls
		// are individually correct.
		//
		// Flattened here, honouring `get_role()`'s own semantics: only an ACTIVE
		// row is a role, and everything else primes as '' ("not a member"), which
		// is the value that function caches for a miss.
		$roles  = $this->members()->membership_map( $user_id, $space_ids );
		$primed = array();

		foreach ( $roles as $mapped_id => $membership ) {
			$primed[ (int) $mapped_id ] = ( 'active' === (string) ( $membership['status'] ?? '' ) )
				? (string) ( $membership['role'] ?? '' )
				: '';
		}

		$this->members()->prime_viewer_roles( $user_id, $primed );

		foreach ( $space_ids as $space_id ) {
			$space = $this->space( (int) $space_id );

			if ( null === $space ) {
				continue;
			}

			$drives[] = array(
				'type'  => 'space',
				'id'    => (int) $space_id,
				'label' => (string) ( $space['name'] ?? '' ),
			);
		}

		return $drives;
	}

	/**
	 * A human label for a space drive.
	 *
	 * @param string $label      Label resolved so far.
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Drive id (the space id).
	 * @return string
	 */
	public function drive_label( $label, $drive_type, $drive_id ): string {
		if ( 'space' !== (string) $drive_type ) {
			return (string) $label;
		}

		$space = $this->space( (int) $drive_id );

		return null === $space ? (string) $label : (string) ( $space['name'] ?? $label );
	}

	/**
	 * Hydrate a space row once per request.
	 *
	 * @param int $space_id Space id.
	 * @return array<string, mixed>|null
	 */
	private function space( int $space_id ): ?array {
		if ( ! array_key_exists( $space_id, $this->space_cache ) ) {
			$this->space_cache[ $space_id ] = $this->spaces()->get( $space_id );
		}

		return $this->space_cache[ $space_id ];
	}

	/**
	 * Space service.
	 *
	 * @return SpaceService
	 */
	private function spaces(): SpaceService {
		if ( null === $this->spaces ) {
			$this->spaces = new SpaceService();
		}

		return $this->spaces;
	}

	/**
	 * Membership service.
	 *
	 * @return SpaceMemberService
	 */
	private function members(): SpaceMemberService {
		if ( null === $this->members ) {
			$this->members = new SpaceMemberService();
		}

		return $this->members;
	}
}
```

### Measured against the seeded community

15 spaces (11 open, 3 private, 1 secret), real memberships, no simulation:

```
refusals   secret space,  outsider  -> 404 mvs_drive_not_found
           private space, outsider  -> 403 mvs_drive_forbidden
           private space, member    -> 200, folders listed
           open space,    outsider  -> readable, no refusal

ladder     owner                    -> own
           member (active)          -> write
           member (pending)         -> read   on an open space
           member (banned)          -> read   on an open space  (see note 2)
           non-member, open         -> read
           non-member, private      -> none
           anyone, secret           -> none

/drives    lists only spaces the member actively belongs to, with the same
           access level the ladder resolves, and never a drive at access=none

cache      get_role() returns 'owner' both before and after /drives primes
           through the bridge — BuddyNext's cache is left as it was found
```

`/drives` deliberately lists only spaces the member **belongs to**, not every open space on the
site: the route answers "your libraries", and padding it with a site directory would bury the
member's own drives.
