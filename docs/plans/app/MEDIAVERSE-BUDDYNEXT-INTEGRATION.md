# MediaVerse ↔ BuddyNext Integration Plan

> **STATUS: PLAN (2026-07-07). Not verified against 2.4.0 code, and it describes a
> second plugin (BuddyNext) that is not in this repo.** The one MediaVerse-side
> seam that definitely ships is the `mvs_buddynext_active` filter. Everything else
> here is intent.

Date: 2026-07-07 · Context: BuddyNext free 1.0.5 installed on mediaverse.local. MV = MediaVerse, BN = BuddyNext.

## Principle — neither profile replaces the other

- **MV owns the media domain** and keeps its dedicated **`/media/@{nicename}/` media profile** (canonical handle; the 2.0.0 `user_login`→`user_nicename` fix already made this stable and enumeration-safe). This is never removed.
- **BN owns identity + the unified member profile** (login/register/2FA/social, the member's home).
- Integration = **wiring, not replacement.** Users move between "who someone is" (BN) and "their media" (MV) without dead ends.

## Site-owner setting (the option you asked for)

New MV setting **`mvs_member_profile_mode`** (Settings → MediaVerse → Community), only meaningful when BN is active:

| Mode | `/media/@{nicename}/` | BN profile | When |
|---|---|---|---|
| **Standalone** (default; forced when BN inactive) | Dedicated MV media profile renders | untouched | BN not installed/active, or owner wants MV self-contained |
| **Keep both** (recommended) | Dedicated MV media profile renders **+ a header link "View full profile → BN"** | BN profile gains a **"Media" tab** that embeds the member's MV grid and links to `/media/@` | Owner wants both surfaces, cross-linked |
| **BN media tab** | Canonical-redirects to the BN profile's **Media tab** | BN profile's Media tab IS the member's media home | Owner wants ONE profile surface (BN), MV media nested inside it |

Fallback rule: **BN inactive → always Standalone.** No setting can produce a broken/lost state.

## The single wiring seam

Every member reference in MV already routes through **`TemplateHelpers::get_user_profile_url( $user_id )`** (centralized in 2.0.0). This becomes the ONE place that honors the mode:

```
get_user_profile_url($id):
  if BN inactive or mode == standalone  → home_url('/media/@'.nicename.'/')     // MV dedicated
  if mode == keep_both                  → home_url('/media/@'.nicename.'/')     // MV, with BN back-link in header
  if mode == bn_media_tab               → bn_profile_url($id) . 'media/'         // BN Media tab
```

Author links, People search results, @mentions, notifications, share cards, and the app all inherit correct routing automatically — no per-callsite changes.

BN side (only when BN active + mode ≠ standalone): register a **"Media" profile nav tab** via BN's profile-nav API that renders the member's MV media grid (reusing the `mvs/member-photos` block / grid renderer). One integration class: `Integrations\BuddyNext\ProfileMediaTab`, gated on `function_exists('buddynext')` / the BN load hook — mirrors the existing `Integrations\BuddyPress\` pattern; MV never hard-depends on BN.

## "Don't feel lost" guarantees

1. **Bidirectional links** — MV media profile header → "View full profile" (BN); BN Media tab → "See all media" (`/media/@`).
2. **Consistent handle** — the same `@{nicename}` identifies the member on both surfaces.
3. **Notifications** resolve through the same seam, so a media notification lands on the media surface the owner chose, not a generic feed.
4. **No dead ends** — `/media/@` always renders something real (fixes QA #10, below), and the BN tab always has a "See all" escape hatch.

## Prerequisite fix — QA #10 (blocks this plan)

`/media` → People → a member currently renders a **blank page** (header/footer collapsed + stray "+"). The dedicated media profile MUST render for Standalone and Keep-both to work. Fix the member-media-profile template/route so `/media/@{nicename}/` renders the grid reliably. **This is the gating MV bug for the integration.**

## QA batch (2026-07-07) — MV vs BN ownership

**MV bugs, fixed in MV (independent of BN):**
- #1 legacy media-comment rows leaking onto posts → cleanup Migrator (new comments already `comment_post_ID=0`).
- #3 members-only/private images missing from the member Media tab (show in /media) → privacy filter on the profile grid.
- #6 "enter an album name" error when a name was entered → album-create validation.
- #7 visitors see a comment form + dead reactions on /media → hide write-affordances for users who can't act (show only Download/Open).
- #13 editing image title/description/tags doesn't save → media-edit persistence.

**Solved by BN wiring (this plan):**
- #10 broken member page → dedicated MV media profile renders + routes per mode (prerequisite fix above).
- Member/profile links, People→member, mentions → the single seam.

**BN / other plugins (not MV):** #5 login redirect (BN auth), #9 spaces (Jetonomy), #11/#12 topic/badge notification routing (Jetonomy/gamification/BP), #14/#15 forum replies & activity (Jetonomy/BP).

**Partial/shared:** #2 messages icon count (MV messaging ↔ theme/BN menu), #4 cover-image error toast (confirm MV vs BP cover), #8 dark mode (MV admin CSS gaps + theme-wide).

## Build order

1. **Fix #10** — dedicated media profile renders (unblocks everything).
2. **`mvs_member_profile_mode` setting** + make `get_user_profile_url()` mode-aware (seam already centralized).
3. **`Integrations\BuddyNext\ProfileMediaTab`** — BN Media tab embedding the MV grid (gated on BN active).
4. **Header back-links** both directions (Keep-both mode).
5. Verify all 3 modes with BN active + BN inactive fallback.

Reference: MV keeps the `Integrations\BuddyPress\` pattern (7 focused classes) as the template for the BN integration package.
