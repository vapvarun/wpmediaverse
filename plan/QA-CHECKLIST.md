# WPMediaVerse v1.0.0 — Manual QA Checklist

> Test each item. Mark PASS/FAIL. Note any issues.

---

## 1. Upload Flows

### 1.1 Upload via FAB Button
- [ ] Click (+) FAB on explore page → modal opens
- [ ] Select "Photo" tab → pick image → preview shows
- [ ] Add title, description, tags → select privacy → click Upload
- [ ] Success toast appears → media visible in explore feed
- [ ] Verify thumbnail generated (3 sizes in admin → All Media)

### 1.2 Upload via FAB — Gallery
- [ ] Click (+) FAB → select "Gallery" tab
- [ ] Select 2-4 images → previews show
- [ ] Upload → gallery post appears as single card with badge

### 1.3 Upload via FAB — Video
- [ ] Click (+) FAB → select "Video" tab
- [ ] Select video file → upload completes
- [ ] Video card appears in feed

### 1.4 Upload via BP Activity Form
- [ ] Go to /activity/ → click media attachment button (📎)
- [ ] Select 1 image → preview shows in form
- [ ] Type text + click "Post Update"
- [ ] Activity appears with text + media image
- [ ] Image has data-mvs-media-id attribute (inspect element)

### 1.5 Upload via BP Activity — Multi Image
- [ ] Post activity with 3 images → grid layout displays (mvs-activity-grid-3)
- [ ] Each image clickable for lightbox

### 1.6 Upload via BP Activity — Group
- [ ] Go to group page → post activity with image
- [ ] Media appears in group activity stream
- [ ] Media appears in /groups/{slug}/media/ tab

---

## 2. Explore / Feed

### 2.1 Explore Grid
- [ ] /media/ loads with Instagram feed layout
- [ ] Images display with thumbnails (not original size)
- [ ] Author name + avatar on each card
- [ ] Like count shows
- [ ] Description with "more" button for long text
- [ ] Comment preview shows (if comments exist)
- [ ] Timestamp shows (e.g. "3 hours ago")

### 2.2 Stories Bar
- [ ] Story circles show at top of explore page
- [ ] Users with recent uploads appear

### 2.3 Pagination
- [ ] Scroll past 10 items → more load or pagination shows

---

## 3. Lightbox (Interactivity API — Explore/Instagram)

### 3.1 Open
- [ ] Click image on explore → lightbox opens with dark overlay
- [ ] Image displays full size
- [ ] Author avatar + name + link in sidebar
- [ ] View count shows

### 3.2 Reactions
- [ ] Click 👍 → count increments, button highlights
- [ ] Click ❤️ → switches reaction (previous deactivates)
- [ ] Click same reaction again → removes it (count decrements)

### 3.3 Favorites
- [ ] Click "Favorite" → changes to "Favorited" with filled heart
- [ ] Click again → unfavorites

### 3.4 Comments
- [ ] Type comment → click Post → comment appears in list
- [ ] Comment shows: avatar + author name (clickable) + text
- [ ] "No comments yet" message hides after first comment

### 3.5 Share
- [ ] Click Share → "Copied!" feedback (or native share dialog)

### 3.6 Open Link
- [ ] Click "Open" → navigates to /media/{slug}/ single page

### 3.7 Gallery Navigation
- [ ] Open gallery post → prev/next arrows visible
- [ ] Click arrows → cycles through gallery images
- [ ] Position indicator shows (e.g. "2 / 4")

### 3.8 Close
- [ ] Click X → lightbox closes
- [ ] Click dark overlay → closes
- [ ] Press Escape → closes
- [ ] Body scroll restored after close

---

## 4. BP Lightbox (Clone Approach — Activity/Profile/Group Pages)

### 4.1 Open from Activity
- [ ] Click media image in BP activity → lightbox opens
- [ ] Same sidebar layout as explore lightbox
- [ ] Author avatar + name display

### 4.2 Reactions on BP
- [ ] Toggle reaction → count updates
- [ ] Re-fetch confirms server state matches

### 4.3 Favorites on BP
- [ ] Toggle favorite → state persists on reload

### 4.4 Comments on BP
- [ ] Post comment → appears in lightbox comment list
- [ ] Comment also appears as BP activity comment (one-way sync)
- [ ] Avatar + profile link on each comment

### 4.5 Multi-Image Activity
- [ ] Open lightbox on multi-image activity → gallery nav works
- [ ] All media in same activity share lightbox navigation

### 4.6 Old Activity Posts (No data-mvs-media-id)
- [ ] Click image on older activity → lightbox still opens (slug fallback)

---

## 5. Single Media Page

### 5.1 Display
- [ ] /media/{slug}/ loads correctly
- [ ] Image/video/audio renders
- [ ] Title, description, author, date show
- [ ] Tags displayed as clickable links

### 5.2 Social Actions
- [ ] Reactions bar — 6 emojis with counts
- [ ] Favorite button toggles
- [ ] Share button copies link
- [ ] Report button visible

### 5.3 Comments
- [ ] Comment form visible (logged in)
- [ ] Post comment → appears with avatar + author link
- [ ] Edit own comment → inline edit form
- [ ] Delete own comment → removed

### 5.4 Follow
- [ ] Follow button shows for non-owner
- [ ] Click Follow → changes to "Following"
- [ ] Click again → unfollows

### 5.5 Privacy
- [ ] Private media → shows lock message for non-owners
- [ ] Members-only → visible when logged in, hidden when logged out

---

## 6. Dashboard (/my-media/)

### 6.1 Layout
- [ ] Profile header with avatar, username, View/Edit Profile
- [ ] 4 tabs: Media, Albums, Favorites, Collections

### 6.2 Media Tab
- [ ] Shows user's uploaded media
- [ ] Each item: thumbnail, title, privacy badge, Edit/Delete buttons
- [ ] Upload area ("Drop files here or click to upload")

### 6.3 Albums Tab
- [ ] Shows user's albums (or empty state)
- [ ] Create new album form

### 6.4 Favorites Tab
- [ ] Shows media user has favorited

### 6.5 Collections Tab
- [ ] Shows user's collections

### 6.6 Storage Quota
- [ ] Shows Images/Videos/Audio counts
- [ ] Shows quota limit (Unlimited or specific)

---

## 7. BuddyPress Profile Tab

### 7.1 Navigation
- [ ] /members/{user}/media/ → Media tab active in profile nav
- [ ] Shows media count badge (e.g. "Media 9")

### 7.2 Grid
- [ ] User's media displays in grid
- [ ] Each image has stats overlay (views, likes, comments)
- [ ] Click image → lightbox opens

### 7.3 Sub-tabs
- [ ] "Media" sub-tab (default)
- [ ] "Albums" sub-tab

### 7.4 Upload
- [ ] "Upload Media" button visible on own profile

---

## 8. BuddyPress Group Tab

### 8.1 Navigation
- [ ] /groups/{slug}/media/ → Media tab active in group nav
- [ ] Sub-tabs: Media | Albums

### 8.2 Content
- [ ] Shows media uploaded via group activity
- [ ] Empty state: "No group media yet" for groups with no media
- [ ] Click image → lightbox opens

### 8.3 Upload
- [ ] "Upload Media" button visible for group members

---

## 9. Admin Pages

### 9.1 Overview
- [ ] /wp-admin/admin.php?page=wpmediaverse → stats cards show
- [ ] Total Media, Albums, Pending Review, Total Views, Storage Used
- [ ] Quick links: Add Media, Settings, Moderation, Stats
- [ ] Import Demo Data button works

### 9.2 All Media
- [ ] /wp-admin/admin.php?page=mvs-media → table with columns
- [ ] Columns: Thumb, Title, Author, Type, Privacy, Status, Date
- [ ] Filters: type dropdown, privacy dropdown, search
- [ ] Pagination works
- [ ] View/Trash row actions work

### 9.3 Settings
- [ ] 5 tabs: General, Display, Permissions, AI & Moderation, Webhooks
- [ ] Settings save and persist
- [ ] Role permissions editable

### 9.4 Stats
- [ ] Stats page loads with charts/metrics

### 9.5 Moderation
- [ ] Moderation queue page loads
- [ ] Approve/reject actions work (if flagged content exists)

---

## 10. Comment Sync (Media → BP Activity)

### 10.1 Single Media Activity
- [ ] Post comment on media via lightbox
- [ ] Navigate to linked BP activity → comment appears there

### 10.2 Multi-Media Activity
- [ ] Post comment on media #1 of a 3-image activity
- [ ] Post comment on media #2 of same activity
- [ ] Both comments appear on the same BP activity

### 10.3 No Infinite Loop
- [ ] After posting 1 comment → only 1 BP activity comment created
- [ ] Check /wp-admin/admin.php?page=bp-activity → no duplicate flood

---

## 11. Edge Cases

### 11.1 Logged Out
- [ ] Explore page → media visible (public)
- [ ] Click image → lightbox opens (no social actions)
- [ ] Single media page → visible, "Log in to comment"
- [ ] Private media → not visible or shows lock

### 11.2 Non-Owner
- [ ] Cannot edit/delete another user's media
- [ ] Can react, comment, favorite
- [ ] Follow button visible

### 11.3 Mobile (390px viewport)
- [ ] Explore grid responsive
- [ ] Lightbox fills screen
- [ ] Upload modal usable
- [ ] BP activity media sized correctly

---

## 12. Data Integrity

### 12.1 Thumbnails
- [ ] New upload → check mvs_media_meta for thumb_large, thumb_medium, thumb_thumb
- [ ] Thumbnail files exist on disk at uploads/wpmediaverse/YYYY/MM/

### 12.2 No WP Attachments
- [ ] New upload → no new row in wp_posts with post_type='attachment' for this media
- [ ] No attachment_id in mvs_media_meta for new uploads

### 12.3 Media Deletion
- [ ] Delete media from dashboard → removed from mvs_media_index + meta + stats
- [ ] File removed from disk
