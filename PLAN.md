# Ferrite: plan

Self-hosted, web-only file storage. Folders, chunked upload, previews, share links, trash, multiple users with quotas, Flysystem disks (local, S3, SFTP). Laravel 13 + Livewire + Flux. Intended to replace Google Drive for personal use, and possibly be published as open source (no other Livewire/Flux project of this kind found, Oct 2026; closest: gyaaniguy/personal-drive, Laravel + React).

This file is the map: decisions, status, next steps. Detail goes in `docs/`.

## Scope

- **v1:** folders, upload (chunked, resumable, folder upload), download, ZIP of a folder, rename, move, copy, trash with restore and auto-purge, previews (image, PDF, video, audio, text), share links (password, expiry), sharing with users, multi-user with quota, 2FA and passkeys, search by name, storage disks (local, S3, SFTP).
- **Not in v1:** sync clients, WebDAV, office previews, real-time collaboration, in-content search, versioning.

## Data model

- `users` (quota_bytes, used_bytes, role)
- `storage_disks` (name, driver, encrypted config, is_default)
- `nodes`: files and folders in one table (owner_id, parent_id, type, name, disk_id, path, size, mime, sha256, trashed_at); unique name per parent among non-trashed
- `shares` (node_id, token, password_hash, expires_at, allow_download, revoked_at)
- `node_user` (node_id, user_id, permission view|edit)
- `uploads` (chunked upload state), `activity`, `favorites`

Decisions: blobs under random keys so rename/move only touch the database; trash is a soft delete inherited by children; permissions via `NodePolicy` (owner, user share on the node or an ancestor, or a valid share token); quota updated in the same transaction as node insert/delete.

## Build order

- [x] 0. Scaffold: Laravel 13, Livewire starter kit, Pest (34 tests passing)
- [x] 1. Schema, auth (2FA, passkeys), `nodes` tree, `NodePolicy` (2FA and passkeys came with the starter kit; `shares`, `uploads`, `activity`, `favorites` tables land with their steps; 46 tests)
- [x] 2. Browser UI: list, create folder, rename, move, trash and restore (actions in `app/Actions/Nodes`; permanent delete, empty trash and 30-day auto-purge added later)
- [x] 3. Upload: chunked and resumable for all sizes, folder upload, quota (see `docs/uploads.md`; browser UI checked by hand, multi-GB test open; 109 tests)
- [x] 4. Download, streaming with Range, folder ZIP, previews and thumbnails (see `docs/serving-files.md`; preview modal checked by hand; added `maennchen/zipstream-php`; 159 tests)
- [x] 5. Share links, then sharing with users (see `docs/sharing.md`; dialog and guest page checked by hand; 206 tests)
- [x] 6. Storage disks (SFTP, S3), name search, activity log (see `docs/storage-search-activity.md`; S3/SFTP only tested up to adapter and error handling, not against a real server; 263 tests)
- [x] 6b. Admin users (quota, disable, delete), registration policy, permanent delete (see `docs/users.md`; 301 tests)
- [x] 7. Docker image, install docs, pre-publication security review (see `docs/install.md`, `docs/security.md`; Docker image written but not built/run end to end: no Docker on the dev machine; 318 tests)
- [x] 8. Cosy design: warm cream and terracotta look, Nunito and Fraunces, twelve selectable themes with Plum as default (see `docs/themes.md`; 330 tests)

- [x] 8b. Deduplication: identical files of one owner share a blob, deleted with its last node (see `docs/uploads.md`; 338 tests)
- [x] 9. UI polish: new default Ferrite theme, the twelve non-vintage themes removed, image row thumbnails sized like the other icons (see `docs/ui-polish.md`)
- [x] 11. Bulk actions: tick rows (list and grid, select all), then download as one ZIP, move or trash; dragging a ticked row moves all ticked rows (see `docs/ui-polish.md`; checked by hand)
- [x] 12. Copy, sorting and favorites (see `docs/ui-polish.md`; checked by hand)
- [x] 13. Usage page: quota, breakdown by type, biggest folders and files, trash, deduplication savings; stored vs counted bytes per disk for admins (see `docs/ui-polish.md`)
- [x] 10. Large files on remote disks (Hetzner Storage Box via SFTP, port 23; see `docs/uploads.md`, `docs/storage-search-activity.md`, `docs/install.md`):
  - [x] Uploads finish in a queued job (`FinalizeUpload`) with a "processing" state in the UI; the last chunk request answers at once (202). The Docker image runs a queue worker
  - [x] Clean up on failure: no orphaned remote file without a `nodes` row, no upload stuck at 100% (`FailUpload`, `uploads:prune`, Retry in the UI)
  - [x] Real seeking on SFTP (`SftpStream`, offset reads) and a byte range on S3, instead of reading and discarding up to the offset
  - [x] Temp disk need and proxy/PHP timeouts documented in `docs/install.md`
  - [ ] Test S3 and SFTP against a real server with a multi-GB file: SFTP done against a local OpenSSH server with 3000 MB (`tests/Feature/Remote/SftpDiskTest.php`, opt-in via `FERRITE_TEST_SFTP_*`); still to do: the real Storage Box, S3, and the Docker image end to end (queue worker included)
- [ ] 14. Content search (details in the list below; write `docs/content-search.md` when it lands):
  - [ ] Extraction in a queued job on its own low-priority queue, once per blob (sha256): plain text, Markdown, code, CSV and JSON read directly (first 1-2 MB, valid UTF-8 only); PDF through `pdftotext` (add poppler-utils to the Docker image); remote disks are copied to temp first
  - [ ] Index: SQLite FTS5 (`node_contents` table with blob key, text, status, extracted_at, plus the FTS5 virtual table), ranking and `snippet()`; Meilisearch/Typesense through Scout rejected for now (extra service)
  - [ ] Search joins the index to `nodes`, limited to what the user may see (owned, shared directly or through an ancestor), trashed excluded; results with a highlighted snippet next to the name results
  - [ ] Housekeeping: backfill command (`search:index`), reindex on content change, delete the index row with the last node of a blob, mark too-big/binary/failed files so they are not retried, admin switch to turn indexing off
  - [ ] Docs: extracted text sits unencrypted in the database (`docs/security.md`); not in scope: OCR for scans and images, Office formats (DOCX/XLSX are zip+XML, possible later)

## Ideas (not scheduled)

Suggested next: run the remote test against the real Storage Box (rest of step 10), then the Docker image. Command (fill in the Storage Box user; it writes only under `/ferrite-test/run-…` and removes it):

```sh
FERRITE_TEST_SFTP_HOST=u123.your-storagebox.de FERRITE_TEST_SFTP_PORT=23 \
FERRITE_TEST_SFTP_USER=u123 FERRITE_TEST_SFTP_KEY=~/.ssh/id_ed25519 \
FERRITE_TEST_SFTP_ROOT=/ferrite-test FERRITE_TEST_SFTP_MB=3000 \
php artisan test --compact tests/Feature/Remote
```

- Small: Recent view and type filters; "select all N items" with infinite scroll; keyboard shortcuts (Delete, Esc, Cmd/Ctrl+A, F2); upload conflict choice (keep both, replace, skip).
- Medium: upload-only drop-box links for guests; e-mail notifications (link opened, drop-box upload); configurable trash retention; duplicate finder (sha256 already stored).
- Bigger: versioning (the thing most missed after a month); API tokens instead of WebDAV. (Content search moved to step 14.)
- Also: build the Docker image once to prove the install docs.
- Follow-ups from the favorites and usage work: a "clean up" shortcut on the Usage page (select the biggest files and trash them, or link to the duplicate finder); sorting and bulk actions on the Favorites page; a per-disk bar for stored vs counted bytes on the admin Storage page; show uploads that failed while the tab was closed (today only a live tab sees the failure).

## Risks

- Large uploads: PHP and proxy limits, assembling chunks; the final copy to a remote disk runs in a queue job, so it needs a worker and enough temp space (see `docs/install.md`).
- Serving user files from the app's own domain (HTML/SVG scripts): `nosniff`, forced download except for an allowlist, ideally a separate domain.
- Share tokens: long random, rate limits, constant-time password checks.
- Moving a folder into itself, name collisions.
- Livewire speed with thousands of files: paginate or virtualize.
