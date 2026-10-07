# UI polish plan

From a visual review on 2026-10-07 (file list in Plum, Light and B&W, plus the login page). Not started.

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
