# What's New in 2.4.0

MediaVerse 2.4.0 turns My Media into a set of real, linkable sections and gives documents a proper admin screen. The 2.4.1 and 2.4.2 patches that followed are covered at the end of this page.

> **Included in Free.** Every item on this page applies to the free version unless marked Pro. Paired with MediaVerse Pro 2.4.0 - install and test both together.

## Every section of My Media has its own address

Albums, Documents, Favourites and the rest are now separate addresses rather than tabs that forget where you were. A section can be linked, bookmarked, shared with a colleague and returned to, and the browser's back button behaves. The sections themselves are presented as a grouped vertical rail with counts, which stays readable as the list grows.

Editing your profile moved into the rail as a section of its own, which gives the library back the space the old profile card took above it.

## Documents get an admin screen

Site owners can open a document from the Documents screen and correct its title, slug, description, tags and privacy. Document rows offer **Edit** and **View on site** alongside Trash and Delete permanently, so the screen is no longer only destructive.

A new **Use Documents** permission decides which roles get a document library, so a site can offer photos to everyone and documents to staff only. Every role has it to begin with, including roles added by other plugins, so nothing changes until you take it away.

## Drives stay fast as they grow

A drive listing now reads its index directly instead of scanning and filtering. On a 30,000-document library one page examined 234 rows where it previously examined 8,032.

## Interface work

Panels, cards and controls no longer all draw the same outline, so screens stop reading as boxes inside boxes. Buttons, form fields, rows and the sign-in link shown to logged-out visitors all meet a minimum tap target. Text, status badges and the active tag meet a contrast floor the plugin sets for itself rather than inheriting whatever the theme supplied, and the brand colour is darkened before it is used as text so rail links stay readable on a light background.

## 2.4.1 - privacy on nginx, and theme-dependent interface fixes

Stored media could be downloaded by anyone holding the address on servers that ignore `.htaccess`, which nginx does. Site Health now checks this by asking the server instead of trusting the deny file, and prints the exact rule to add when it finds a problem. Media set to Only me, Members or Friends was affected, and so was anything made private after being shared, because the older address kept working.

Alongside it, a bulk actions bar with a Delete button and a permanent Loading indicator no longer appear on themes that do not reset the HTML `hidden` attribute, and several controls that fell under the tap-target floor on a phone were corrected.

## 2.4.2 - blocking, collections, and a real justified-row grid

The privacy fixes are the important part. Blocking someone now stops them viewing your media, your profile listing and its counts; Explore no longer showed blocked members' media on its first page; strangers can no longer react inside private conversations; moderation is enforced in the shared visibility gate; and a favourited private item no longer leaks its title.

Collections tightened up too: a collection can be public or members only, only public media or your own can be added to one, and smart collections stopped counting media the viewer cannot see.

On layout, the `original` grid now draws true justified rows, so a row fills the width instead of stretching two tiles to cover it. The label changed with it: what the settings screen calls **Justified rows** is what it actually draws. The Pinterest layout in Pro remains a masonry board, which is a different thing and keeps its name.

See the [changelog](https://wordpress.org/plugins/wpmediaverse/#developers) for the complete list.
