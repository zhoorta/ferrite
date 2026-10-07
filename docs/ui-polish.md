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
