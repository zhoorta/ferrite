# UI polish plan

From a visual review on 2026-10-07 (file list in Plum, Light and B&W, plus the login page). Done: Ferrite default theme, old themes removed, list thumbnails now `size-5` (same as the other icons) so image rows keep the row height and the name alignment. The findings below are the original review and mostly apply to the removed themes.

## Findings

- **Plum** (default) is the weakest look: a full-screen purple-to-maroon gradient with orange accents, and low-contrast tan text for the size and date columns. The login page is worse (full-bleed gradient, soft serif heading).
- **Light** (cream and terracotta) works well. Only the glow at the top is too much.
- **B&W** is the best fit for a file manager: quiet, file names are the focus, clear white primary button.
- Small defects: the "Upload folder" button in `bw` keeps a brownish outline and the avatar stays brown; the image row (`holiday.jpg`) is a few pixels taller than the others and its icon is indented; the detailed house logo is busy at small size next to the plain wordmark.

## Plan

1. Make `bw` (or a neutral dark such as `slate`) the default dark palette; keep Plum as an option (`User::$attributes['palette']`, default in `docs/themes.md`).
2. Consider Light as the default for new accounts if some warmth is wanted.
3. Remove the full-screen gradient glow and grain from the defaults (`.cozy-bg` in `resources/css/app.css`); plain background on the login and guest pages.
4. Fix the leftover brown on the `bw` button outline and avatar.
5. Fix the taller image row and the icon indent in the file list.
6. Optional: use the sans font for headings in the app (Fraunces only on sign-in and share pages); trim the twelve themes to about four; simplify the logo at small sizes.
7. Update `docs/themes.md` when done.

## Bulk actions

- Each row (list) or tile (grid, on hover) has a checkbox; the header checkbox ticks all rows of the folder. Selection lives in `$selected` on the browser component and is cleared after an action.
- A bar appears with the count and Download (one ZIP, `GET nodes/zip?ids=1,2,3`, max 500, 403 if any item is not viewable), Move (the move dialog, now taking `$moveIds`) and Move to trash. Move and trash only show where the user may edit the folder.
- Actions run item by item: what is allowed goes through, the rest is listed in a toast ("Some items were skipped"). Ids not listed in the current folder are ignored.
- Dragging a ticked row onto a folder moves every ticked row.

## Infinite scroll

- A folder loads 100 items (`$limit`); a spinner at the end of the list calls `loadMore()` through `x-intersect` when it scrolls into view, adding 100 more. `hasMore` is a cheap `exists()` past the limit.
- Select all, preview stepping (arrows) and the "N selected" count only cover the items loaded so far.

## Copy, sorting, favorites

- **Copy** (`CopyNode`): row menu and bulk bar, using the move dialog in copy mode (`$moveMode`). Copies a file or a folder tree (not what is in the trash) into one of the user's own folders or the root; the copy is owned by the destination's owner and charged to their quota. Within one owner the copy shares the blob (deduplication); across owners (copying something shared with you) the blob is duplicated, so nobody's file is tied to someone else's. A taken name becomes "name (2)". A folder cannot be copied into itself or below itself. Logged as `copied`.
- **Sorting**: toolbar dropdown (name, size, modified, ascending or descending), remembered for the session. Folders always come first.
- **Favorites**: `favorites` table (user, node, unique). Any node the user can see can be starred from the row menu; a star marks it in the list and grid, and the Favorites page in the sidebar lists the starred nodes that are still visible (not trashed, still shared). Starring is per user and never changes access.
