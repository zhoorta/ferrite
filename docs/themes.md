# Themes

Eleven themes, picked in Settings > Appearance and stored per account in `users.palette` (default `plum`). The list lives in `User::PALETTES`.

- `light` is the cream theme. Every other theme is dark: the layouts set `class="dark"` on `<html>` unless the palette is `light`, and `app.css` sets `color-scheme` to match.
- Dark palettes only redefine the `zinc-700/800/900/950` tokens. Plum is the base in the `.dark` block; the rest are `.dark[data-palette='…']` overrides in `resources/css/app.css`. Add a palette by adding a CSS block, an entry in `User::PALETTES` and a card in the appearance page.
- Signed-in pages get `data-palette` from the server. Guest pages (sign-in, share links) use the last palette that browser saw, kept in `localStorage` (`shed.palette`) by the script in `partials/head.blade.php`.
- Colours: terracotta accent, cream text, warm paper grain (`.cozy-bg`). Fonts: Nunito for text, Fraunces for headings (Bunny Fonts via `vite.config.js`).
- `<dialog>` resets its text colour, so `app.css` makes dialogs inherit the page colour. Keep that when touching modals.
