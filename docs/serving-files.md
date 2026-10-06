# Serving files

User files are the main security surface: they are served from the app's own origin, so anything the browser renders inline could run with the viewer's session. All of it goes through `NodeResponder`; routes are in `NodeFileController`.

| Route | What it does |
| --- | --- |
| `nodes/{node}/download` | Always `Content-Disposition: attachment`. |
| `nodes/{node}/preview` | Inline only for types in `FileKind`, otherwise an attachment. |
| `nodes/{node}/thumbnail` | 256px JPEG of an image, cached on the node's disk as `thumbnails/<key>.jpg`; 404 when none. |
| `nodes/{node}/zip` | Folder as a ZIP, streamed (no temp file, stored without compression), trash excluded. |

Rules that every response follows:

- `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: no-referrer`.
- The MIME type comes from the server's own sniffing at upload, never from the client.
- Inline allowlist: JPEG, PNG, GIF, WebP, AVIF, PDF, MP4/WebM/Ogg video, common audio, and text. Text-like types, HTML and XML included, are sent as `text/plain`. SVG is not on the list, so it downloads.
- Inline responses other than PDF also get `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`. (A sandbox CSP stops browsers' built-in PDF viewer, so PDFs go without.)
- Trashed files, and files inside trashed folders, are not served, not even to the owner.
- Permission is `NodePolicy::view` (owner, or a share on the node or an ancestor).

Downloads support a single `Range` (`bytes=a-b`, `a-`, `-n`), answer `416` for unsatisfiable ranges, ignore multi-range requests, and use the SHA-256 as the `ETag` (`If-None-Match` gives `304`). Seekable streams (local disk) are seeked; others are read and skipped.

Thumbnails use GD. Images over 30 MB, over 40 megapixels, or that would not fit in `memory_limit` get none. Video thumbnails, and previews of Office files, are not supported.

## Hardening still recommended

Serve user files from a separate domain (for example `files.example.com`, no cookies) so that even a bypass cannot reach the app's session. That needs signed, short-lived URLs and is planned with share links.
