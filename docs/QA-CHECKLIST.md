# WPMediaVerse — Manual QA Checklist

## Prerequisites
- Local site: `wb-media.local`
- Admin user: `varundubey` (auto-login: `?autologin=1`)
- BuddyPress active with groups + activity + notifications components
- At least 2 groups with members (e.g., "The Godfather", "Gone in 60 Seconds")
- At least 2 user accounts (admin + regular member)

---

## 1. Profile Media Tab

### 1.1 Media Grid (`/members/{user}/media/`)
- [ ] Sub-tabs visible: "Media" and "Albums"
- [ ] "Media" tab is active/highlighted
- [ ] Media grid shows 3-column layout
- [ ] Each card has: thumbnail, title, hover overlay with views + reactions
- [ ] Pagination appears when >18 items
- [ ] Clicking a media card goes to single media page

### 1.2 Upload on Profile
- [ ] "Upload Media" button visible on OWN profile
- [ ] "Upload Media" button NOT visible on OTHER user's profile
- [ ] Clicking "Upload Media" shows dropzone
- [ ] Drag-and-drop files into dropzone works
- [ ] Click dropzone to open file picker works
- [ ] Preview thumbnails shown before upload
- [ ] Upload progress status shown ("Uploading 1 of N...")
- [ ] Success message shown after upload
- [ ] Page reloads and new media appears in grid
- [ ] Cancel button hides dropzone

### 1.3 Albums Grid (`/members/{user}/media/albums/`)
- [ ] Sub-tabs visible, "Albums" tab is active
- [ ] Album cards show: cover image (or fallback), item count, title
- [ ] Clicking album goes to single album view

### 1.4 Create Album on Profile
- [ ] "Create Album" button visible on OWN profile
- [ ] "Create Album" button NOT visible on OTHER user's profile
- [ ] Clicking shows form with: name, description, save, cancel
- [ ] Enter album name + save → album created
- [ ] New album appears in grid
- [ ] Error shown if name is empty

### 1.5 Single Album View (`/members/{user}/media/albums/{slug}/`)
- [ ] "Back to Albums" link navigates correctly
- [ ] Album header shows: title, description, item count
- [ ] "Add Media" button visible on OWN albums
- [ ] Upload dropzone works (same as media tab)
- [ ] Album items displayed in grid with titles
- [ ] Items link to single media pages

---

## 2. Group Media Tab

### 2.1 Media Grid (`/groups/{slug}/media/`)
- [ ] Sub-tabs visible: "Media" and "Albums"
- [ ] "Media" tab is active/highlighted
- [ ] Media grid shows 3-column layout
- [ ] Each card has: thumbnail, author avatar + name, hover overlay
- [ ] Only media with `_mvs_group_id` for THIS group shown (not all groups)
- [ ] Pagination appears when >18 items
- [ ] Clicking a media card goes to single media page

### 2.2 Upload on Group
- [ ] "Upload Media" button visible for GROUP MEMBERS
- [ ] "Upload Media" button NOT visible for NON-MEMBERS
- [ ] Clicking shows dropzone
- [ ] Upload works (drag-drop and click-to-pick)
- [ ] Uploaded media gets `_mvs_group_id` meta set
- [ ] Uploaded media gets `_mvs_privacy=group` meta set
- [ ] Uploaded media appears in group grid after reload
- [ ] Same media also appears on uploader's profile media tab

### 2.3 Albums Grid (`/groups/{slug}/media/albums/`)
- [ ] Sub-tabs visible, "Albums" tab is active
- [ ] Only albums with `_mvs_group_id` for THIS group shown
- [ ] Album cards show: cover image, item count, title
- [ ] Album links go to `/groups/{slug}/media/albums/{album-slug}/`

### 2.4 Create Album in Group
- [ ] "Create Album" button visible for GROUP MEMBERS
- [ ] "Create Album" button NOT visible for NON-MEMBERS
- [ ] Form includes hidden `mvs-bp-group-id` input
- [ ] Enter name + save → album created with `_mvs_group_id` meta
- [ ] New album appears in group albums grid
- [ ] Same album also appears on creator's profile albums tab

### 2.5 Single Album View (`/groups/{slug}/media/albums/{slug}/`)
- [ ] "Back to Albums" link → `/groups/{slug}/media/albums/`
- [ ] Album header: title, description, item count
- [ ] Verify album's `_mvs_group_id` matches current group (no cross-group access)
- [ ] "Add Media" button visible for GROUP MEMBERS
- [ ] Upload into album works, media gets group_id meta
- [ ] Album items displayed in grid

---

## 3. Activity Stream

### 3.1 Group Upload Activity
- [ ] Upload media to group → activity appears in GROUP Home tab
- [ ] Activity text: "varundubey uploaded a new photo: {title} in the group {group name}"
- [ ] Thumbnail image visible in activity content
- [ ] Activity has `component=groups` (not `wpmediaverse`)
- [ ] Activity appears in global activity feed (`/activity/`)
- [ ] "Media Uploads" filter in group activity dropdown works

### 3.2 Profile Upload Activity
- [ ] Upload media on profile → activity appears in member activity
- [ ] Activity text: "varundubey uploaded a new photo: {title}"
- [ ] Thumbnail image visible in activity content
- [ ] Activity has `component=wpmediaverse`

### 3.3 Activity Media Attachment (What's New)
- [ ] Personal activity: Photo/Video button → attach media → post → image visible in activity
- [ ] Group activity: Photo/Video button → attach media → post → image visible in group activity
- [ ] Multi-image: attach 2-5 images → grid layout in activity
- [ ] Media attached via group activity gets `_mvs_group_id` meta

### 3.4 Activity Lightbox
- [ ] Click media image in activity → Instagram-style lightbox opens
- [ ] Lightbox shows: full image, reactions toggle, comments, favorites, share, "View Full Page" link
- [ ] Post a comment in lightbox → comment appears
- [ ] Toggle reaction in lightbox → reaction count updates
- [ ] Close lightbox → returns to activity

---

## 4. Permissions & Edge Cases

### 4.1 Non-Member Group Access
- [ ] Visit group media tab as non-member → grid visible, NO upload button
- [ ] Visit group albums tab as non-member → grid visible, NO create album button
- [ ] Visit group single album as non-member → items visible, NO add media button

### 4.2 Logged-Out User
- [ ] Visit group media → grid visible, NO upload button
- [ ] Visit profile media → grid visible, NO upload button
- [ ] Click reaction → login prompt shown (not silent fail)

### 4.3 Cross-Group Isolation
- [ ] Media uploaded to Group A does NOT appear in Group B's media tab
- [ ] Albums created in Group A do NOT appear in Group B's albums tab
- [ ] Single album URL with wrong group context returns error/empty

### 4.4 Plugin Without BuddyPress
- [ ] Deactivate BuddyPress → plugin still works (no fatal errors)
- [ ] Standalone pages (explore, dashboard, upload) function normally
- [ ] No BP-specific features visible (no group tabs, profile tabs)

---

## 5. Navigation & URLs

### 5.1 Profile URLs
- [ ] `/members/{user}/media/` → media grid (sub-tab: Media)
- [ ] `/members/{user}/media/all/` → same as above (BP sub-nav slug)
- [ ] `/members/{user}/media/albums/` → albums grid (sub-tab: Albums)
- [ ] `/members/{user}/media/albums/{album-slug}/` → single album

### 5.2 Group URLs
- [ ] `/groups/{slug}/media/` → media grid (sub-tab: Media)
- [ ] `/groups/{slug}/media/albums/` → albums grid (sub-tab: Albums)
- [ ] `/groups/{slug}/media/albums/{album-slug}/` → single album
- [ ] NO double slug: `/groups/{slug}/media/media/` should NOT exist (404 is OK)

### 5.3 Tab Counts
- [ ] Profile "Media" tab shows count badge (e.g., "Media 49")
- [ ] Count updates after upload

---

## 6. REST API Verification

### 6.1 Media Upload with group_id
```
POST /wp-json/mvs/v1/media
Body: FormData with file + group_id={id}
```
- [ ] Response includes media ID
- [ ] Post meta `_mvs_group_id` = group_id
- [ ] Post meta `_mvs_privacy` = "group"
- [ ] `mvs_media_index` table row has `privacy=group`

### 6.2 Album Create with group_id
```
POST /wp-json/mvs/v1/albums
Body: { title, description, group_id }
```
- [ ] Response includes album ID
- [ ] Post meta `_mvs_group_id` = group_id
- [ ] Post meta `_mvs_privacy` = "group"

### 6.3 Permission Enforcement
- [ ] Upload with group_id as non-member → group meta NOT set (media created as personal)
- [ ] Upload without auth → 401

---

## 7. Visual Consistency

### 7.1 Profile vs Group Comparison
- [ ] Upload button style identical (cloud icon + text)
- [ ] Dropzone style identical (dashed border, icon, text)
- [ ] Album grid card style identical (cover, count overlay, title)
- [ ] Single album layout identical (back link, header, add media, grid)
- [ ] Empty states have consistent style (icon + message)

### 7.2 Sub-Tab Styling
- [ ] Profile sub-tabs (BP native nav) render cleanly
- [ ] Group sub-tabs (custom nav) render cleanly
- [ ] Active tab visually distinct on both
- [ ] No broken/unstyled elements

---

## 8. Database Integrity

### 8.1 Meta Verification
```sql
-- All group media should have both metas:
SELECT p.ID, p.post_title,
  MAX(CASE WHEN pm.meta_key='_mvs_group_id' THEN pm.meta_value END) as group_id,
  MAX(CASE WHEN pm.meta_key='_mvs_privacy' THEN pm.meta_value END) as privacy
FROM wp_posts p
JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE pm.meta_key IN ('_mvs_group_id','_mvs_privacy')
AND p.post_type = 'mvs_media'
GROUP BY p.ID;
```
- [ ] Every media with `_mvs_group_id` also has `_mvs_privacy=group`
- [ ] Every album with `_mvs_group_id` also has `_mvs_privacy=group`

### 8.2 Activity Verification
```sql
SELECT id, component, type, action, item_id, secondary_item_id
FROM wp_bp_activity
WHERE type = 'mvs_media_upload'
ORDER BY id DESC LIMIT 10;
```
- [ ] Group uploads have `component=groups`, `item_id=group_id`, `secondary_item_id=media_id`
- [ ] Personal uploads have `component=wpmediaverse`, `item_id=media_id`
