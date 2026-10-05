# WCAG 2.1 AA audit, member surfaces (2.6.1), 5 Oct 2026

Card 9822979354 (owner: full pass in 2.6.1). Code audit of Free + Pro 2.6.1
(Pro @ c4af95c). Each item is re-verified before it is fixed. A = Level A /
keyboard or screen-reader blocker, AA = AA failure, nice = best practice.
Tick items as they land, with the commit.

## 1. Lightbox / shared UI (Free shared-ui-frame.php, src/blocks/shared-ui/view.js)
- [ ] A  Edit media modal: no focus in / Tab trap / focus restore (view.js openEditModal ~956, close ~983, keydown ~2070). Copy uploadModalFocus pattern.
- [ ] A  Confirm dialog (shared-ui-frame.php ~780): role=alertdialog, aria-modal, aria-labelledby; Escape + Tab trap + focus restore (view.js showConfirm, handleLightboxKeydown ~2079).
- [ ] A  Pro collection picker (assets/js/collection-picker.js ~115) appended to body outside the aria-modal lightbox; one Escape closes both. Append inside the dialog; stopPropagation on Escape.
- [ ] A  Captions: no <track> anywhere though Pro makes .vtt (vtt_url). Lightbox video (~494), media-single.php ~391, media-player/render.php ~142, IG card video.
- [ ] AA Edit modal error not announced / not linked (shared-ui-frame.php ~416, #mvs-edit-title ~359).
- [ ] AA Lightbox comment edit textarea (~707) unlabeled.
- [ ] nice Upload progress text (~246) role=status; prev/next SVG aria-hidden (~485, ~512); shared-ui-frame.css lacks prefers-reduced-motion (loaded alone).

## 2. Uploaders
- [ ] A  media-upload block dropzone (src/blocks/media-upload/render.php ~153) has no role/tabindex/keydown; input display:none.
- [ ] AA same block: error/progress/success messages not announced (~233/237/240).
- [ ] nice upload icon SVG aria-hidden.

## 3. My Media dashboard (templates/partials/dashboard-content.php)
- [ ] A  Unnamed fields (bare <label>): collection modal ~1401/1406/1411, rule selects ~1471/1483, edit modal ~1537/1546, album modal ~1654/1659/1671.
- [ ] A  Album picker items (~1707) click-only div.
- [ ] A  "Replace File" (~1622) label around hidden input, unreachable by keyboard.
- [ ] A  Notification bell (~402, non-BP/BN sites only): role=button without key handling, no aria-expanded, dropdown nested inside.
- [ ] AA ×-only remove buttons: tag pill ~1598, rule ~1497.
- [ ] AA Manual/Smart toggles (~1445/1451) no aria-pressed.
- [ ] nice tag autocomplete click-only (~1610); upload status (~858) not live; tab roles incomplete (~639); card img alt bound to title (~924/1072/1175/1712) - REST has alt.

## 4. Documents drive (Pro)
- [ ] AA Upload button focus ring never shows: CSS sibling selector but label precedes input (document-drive.css ~437, DriveRenderer ~350/367).
- [ ] AA Upload queue (assets/js/document-upload.js ~373/261) re-renders whole live region each progress event; focused Cancel/Retry destroyed.
- [ ] AA Share type-ahead (document-drive-share.js ~34) lacks role=combobox/aria-expanded/aria-controls/listbox id.

## 5. Compete (Pro templates/*-body.php)
- [ ] A  Media-pick buttons nameless (battles-body ~317, tournaments-body ~253, challenges-body ~137); no selected state.
- [ ] AA Feedback not announced (challenges ~168, battles ~202, tournaments ~325, compete-hub ~128).
- [ ] AA View swap without focus move (challenges ~91, tournaments ~88); battles-store.js ~210 already does it.
- [ ] nice status tab roles; Challenge Someone aria-expanded; aria-label on plain span (compete-hub ~114).

## 6. Grid cards + Pro layouts
- [ ] A  Pinterest cards role=button click-only (pinterest/feed-body ~195, profile ~224, card-builders.js ~510).
- [ ] AA Flickr tile role=button dead tab stop (flickr/feed-body ~201); author link inside aria-hidden info (~228/252).
- [ ] A  IG mute button nameless (instagram/partials/feed-card.php ~206).
- [ ] A  IG video autoplay without pause, ignores reduced motion (~197).
- [ ] AA IG comment input placeholder-only (~434); like/favourite no aria-pressed (~299/351).
- [ ] AA Explore search suggestions: highlight invisible/unannounced (explore-feed render.php ~106, view.js ~189); filter buttons aria-pressed.
- [ ] nice IG boost button named by title only (~344).
- Alt still from title (finish the alt contract): IG audio cover feed-card.php ~218; instagram.js ~577; Free card-builders.js ~406/522/637/735; explore-feed view.js ~33; media-grid render.php ~317 + explore-feed render.php ~178 (render_grid_thumbnail 3rd arg); album-viewer render.php ~119; BuddyPress MediaDisplayHelper ~156/180; media-grid lightbox JSON lacks 'alt' (~281). Compete alt (nice).
- [x] Pro layouts media_thumbnail alt (7 sites) - Pro c4af95c.

## 7. Contrast
- [ ] AA frontend.css ~327 --mvs-text-muted fallback #a7aaad (~2.3:1) -> #646970.
- [ ] AA member-photos/style.css ~382/388 #999 on white -> var(--mvs-text-secondary).

## 8. Weak focus (nice)
- [ ] frontend.css ~6068 #mvs-search-input:focus{outline:none} beats its :focus-visible ring; ~4763 collection search; instagram.css ~700 comment input.

## Already fine (checked)
Lightbox dialog focus/trap/restore/Escape; upload modal; toasts live region; reaction buttons; dashboard modals; dashboard dropzone keyboard; drive row menus, labels, notices, navs, reduced motion; Pro boost modal; compete hub progressbar + battle form; Dribbble/profile grid links; IG gallery controls; Free <img> alts; global reduced-motion rule; global focus ring; Pro layout CSS contrast.
