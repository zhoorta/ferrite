# Ferrite: plan

Self-hosted, web-only file storage. Folders, chunked upload, previews, share links, trash, multiple users with quotas, Flysystem disks (local, S3, SFTP). Laravel 13 + Livewire + Flux. A Google Drive replacement for personal use and small teams, published as free software (AGPL-3.0).

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
- [x] 7. Docker image, install docs, pre-publication security review (see `docs/install.md`, `docs/security.md`; Docker image written; 318 tests)
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
  - [x] SFTP against a real Hetzner Storage Box subaccount (real server, 2026-10): Test passes (folder must exist; a subaccount logs in at `/home`), uploads store through the `uploads` worker, stopping and starting the worker leaves an upload at "Storing the file…" until it is back, a 500 MB MP4 uploaded (about 5 min in total; copy phase not timed), sha256 of the download matches, preview plays and seeks quickly, deleting removes the blob. Also a 3000 MB test against a local OpenSSH server (`tests/Feature/Remote/SftpDiskTest.php`, opt-in via `FERRITE_TEST_SFTP_*`). Largest file on the real box: 500 MB
  - [x] Docker image built and run end to end (2026-10-07, Docker 29): migrations, scheduler and both queue workers start, login, a 60 MB chunked upload (12 chunks, last one 202) finished by the `uploads` worker, download hash identical, 206 Range correct, data survives a container restart. Needed one fix: `composer dump-autoload` failed in the build because `storage/framework/views` did not exist (`storage/*` is in `.dockerignore`)
  - [ ] Still to do: S3 against a real service
- [x] 13b. Open source release: AGPL-3.0 `LICENSE`, project page at `/` for guests (`FERRITE_LANDING`), demo mode with throwaway accounts (`FERRITE_DEMO`, `demo:prune`; no sharing, uploads limited to small images/PDF/text, 60-minute lifetime, abuse banner), see `docs/public-site.md`
  - [x] Docker base image moved to PHP 8.5 (FrankenPHP `1-php8.5-bookworm`), rebuilt and run end to end (2026-10-07, PHP 8.5.11): both workers and the scheduler start, admin created with `ferrite:user`, login, a 60 MB chunked upload (12 chunks, last 202) finished by the `uploads` worker, download hash identical, 206 Range correct, restart keeps the data
  - [x] Prebuilt image: `.github/workflows/docker.yml` publishes `ghcr.io/zhoorta/ferrite` (amd64, arm64; tags on `v*` give `1.2.3`, `1.2`, `latest`; main gives `edge`); the compose file pulls it and falls back to building. After the first run, set the package to public in GitHub (Packages > ferrite > settings) and link it to the repo
  - [x] CONTRIBUTING, SECURITY, issue and PR templates; history checked for secrets (clean, 2026-10-07)
  - [x] Public demo deployed, private vulnerability reporting enabled, v0.1.0 tagged (2026-10-07) and the container package made public
- [x] 14. Content search (see `docs/content-search.md`; tested on SQLite, MySQL 8.4 and 9 and MariaDB 11; the CI `mysql` job has not run yet; not yet checked by hand in the browser; 474 tests):
  - [x] Extraction in a queued job on its own low-priority queue (`search`, served by the default worker after `default`, so no extra worker), once per blob (sha256): plain text, Markdown, code, CSV and JSON read directly (first 1-2 MB, valid UTF-8 only); PDF through `pdftotext` (add poppler-utils to the Docker image); remote disks are copied to temp first
  - [x] Index, behind one small interface with a driver per database (Ferrite runs on SQLite by default and on MySQL/MariaDB): `node_contents` (blob key, text, status, extracted_at) on both. SQLite: an FTS5 virtual table, ranking and `snippet()`. MySQL/MariaDB: an InnoDB FULLTEXT index on a LONGTEXT column, `MATCH ... AGAINST`, snippet cut and highlighted in PHP; note the minimum token size (3) and the stopword list in the docs. Extraction, queue and the permission join are shared. Tests run on SQLite in CI; add a MySQL job (or a documented local run) for the other driver. Meilisearch/Typesense through Scout rejected for now (extra service); a separate SQLite file for the index rejected (permissions cannot be joined across databases, needs pdo_sqlite on MySQL installs)
  - [x] Search joins the index to `nodes`, limited to what the user may see (owned, shared directly or through an ancestor), trashed excluded; results with a highlighted snippet next to the name results
  - [x] Housekeeping: backfill command (`search:index`), reindex on content change, delete the index row with the last node of a blob, mark too-big/binary/failed files so they are not retried, `FERRITE_SEARCH_CONTENTS=false` turns indexing off (an env switch, not an admin screen); rows nobody uses are removed by the daily `search:prune`
  - [x] Docs: extracted text sits unencrypted in the database (`docs/security.md`); not in scope: OCR for scans and images, Office formats (DOCX/XLSX are zip+XML, possible later)

- [x] 17. Upload conflicts: ask before an upload runs into an existing name (see `docs/uploads.md`)
  - [x] `POST /uploads/conflicts` reports files and folders with the same name, also inside existing folders, and folders that will be merged
  - [x] Dialog: Replace, Keep both, Skip existing, Cancel (Merge when only a folder exists); guests of upload links are never asked
  - [x] Replace swaps the content of the same node (links and favorites stay), keeps the quota right and removes the old blob; the activity log says "replaced"
  - [x] Tests for the check, replace, quota, editors in shared folders and drop-box links; the dialog was tried in headless Chrome (replace, keep both, skip, cancel)

- [ ] 15. Encryption at rest (optional per disk; write `docs/encryption.md` when it lands). Server-side only: protects a stolen disk, a leaked bucket or backup, not someone holding the server and the key. Per-user keys and browser end-to-end encryption rejected (break share links, thumbnails, search, Drive import; password reset loses files)
  - [ ] Format: chunked authenticated encryption (libsodium secretstream, e.g. 64 KiB chunks) so ranged reads, preview seeking and ZIP streaming decrypt only the chunks they need; header with version and nonce; a per-blob random data key wrapped by the master key
  - [ ] Key: dedicated `FERRITE_FILE_KEY` in `.env`, not `APP_KEY` (rotating one must not break the other); key id stored per blob so rotation can re-wrap data keys without rewriting files; document backing it up (losing it means losing every encrypted file)
  - [ ] `storage_disks.encrypt` flag, set at creation; the flag cannot be flipped on a disk that already holds blobs (use the migration command below); the blob stores its own `encrypted` marker so mixed disks still read correctly
  - [ ] Write path: encrypt in the queued upload finish job and in copy; sha256 for dedup stays over the plaintext, computed before encryption; thumbnails and extracted text read the plaintext through the decrypting stream
  - [ ] Performance: do the sha256 and the encryption in the same single pass of the finish job (no extra read or write of a multi-GB file); constant memory (one chunk at a time). Measured on a development laptop with PHP libsodium, 64 KiB chunks: encrypt about 400 MB/s (1 GiB in 2.7 s), sha256 about 200 MB/s; network is the bottleneck for users. Record the numbers in `docs/encryption.md` and re-measure on the real server (slow CPUs may be 100-200 MB/s)
  - [ ] Read path: one decrypting stream wrapper used by download, Range, preview, ZIP and share links; `Content-Length` and Range offsets map from plaintext to ciphertext positions; remote disks keep reading at an offset
  - [ ] Command `files:encrypt` (and `files:rekey`) to convert existing blobs in place, resumable, as a queued job with progress
  - [ ] Tests: round trip, Range at chunk boundaries, tampered chunk rejected, wrong key, mixed disk, dedup across encrypted blobs, rekey
  - [ ] Docs: say what it does and does not protect; extracted search text and thumbnails stay unencrypted unless handled (decide: encrypt thumbnails, and offer to skip content indexing on encrypted disks); full-disk encryption on the host as the simpler alternative

- [x] 16. Drop-box links (see `docs/sharing.md`; checked by hand; 449 tests): a guest uploads into one folder, sees nothing (details in `docs/sharing.md` when it lands):
  - [x] Schema: `shares.kind` (`view` | `dropbox`, default `view`), `max_bytes` (nullable cap per link); a drop-box link targets a folder only; password and expiry as for view links
  - [x] Guest page: drop zone on the existing chunked upload protocol through a token-scoped endpoint; no listing of the folder, only what this session sent; no rename, delete or overwrite; clashing names get a suffix
  - [x] Limits: counts against the folder owner's quota, per-link cap, rate limit per IP (like password guesses); revoked, expired, unknown and trashed answer 404
  - [x] Safety: blobs still served only through `NodeResponder`; guest uploads go through `FinalizeUpload`; activity log entry for the owner (e-mail later)
  - [x] UI: "Create upload link" in the share dialog for folders, shown as its own kind in the link list
  - [x] Tests: no listing leak, no access outside the folder, cap and quota enforced, revoked/expired/trashed 404, name clash, password gate
  - Not in scope: download limits, QR codes, recipient e-mail, access history (separate small ideas)

## Ideas (not scheduled)

Suggested next: S3 against a real service. The remote SFTP test command (fill in the Storage Box user; it writes only under `/ferrite-test/run-…` and removes it):

```sh
FERRITE_TEST_SFTP_HOST=u123.your-storagebox.de FERRITE_TEST_SFTP_PORT=23 \
FERRITE_TEST_SFTP_USER=u123 FERRITE_TEST_SFTP_KEY=~/.ssh/id_ed25519 \
FERRITE_TEST_SFTP_ROOT=/ferrite-test FERRITE_TEST_SFTP_MB=3000 \
php artisan test --compact tests/Feature/Remote
```

- Small: Recent view and type filters; "select all N items" with infinite scroll; keyboard shortcuts (Delete, Esc, Cmd/Ctrl+A, F2).
- Medium: e-mail notifications (link opened, drop-box upload); configurable trash retention; duplicate finder (sha256 already stored).
- Bigger: versioning (the thing most missed after a month); API tokens instead of WebDAV. (Content search moved to step 14.)
- API for scripts and other apps (not scheduled, wait for a real use; not sync, not WebDAV): personal access tokens (Sanctum) created in settings, scopes read-only or read-write, optionally limited to one folder; endpoints for list, download with Range, upload, mkdir, move and trash, calling the existing `app/Actions/Nodes` and `NodePolicy`; uploads reuse the chunked resumable protocol (`docs/uploads.md`); same quota, activity log and rate limits; must go through the shared decrypting stream wrapper of step 15.
- Google Drive import (one-way, not scheduled): "Connect Google Drive" via OAuth, then copy the Drive tree into Ferrite (folders to folder nodes, files downloaded and stored as normal blobs; Docs/Sheets/Slides exported to DOCX/XLSX/PPTX or PDF). Nothing stays on Drive afterwards, so no sync or ownership problems; serves "replace Google Drive". Needs the `drive.readonly` scope; Google treats that as restricted, so each deployment creates its own Google Cloud project and enters client ID and secret (like Nextcloud and rclone), and the refresh token is kept encrypted per user and revoked on Disconnect (note in `docs/security.md`). Run as a queued job with progress, skip or rename on name conflicts, dedup by sha256 as usual.
- Google Drive as a storage disk or browse-in-place (not planned): a Flysystem Drive adapter would only hold Ferrite's own random-key blobs; browsing existing files needs nodes Ferrite does not own (delete, rename, quota and rescan rules, Docs export, Drive changes API). Revisit only after the import exists and there is demand.
- Follow-ups from the favorites and usage work: a "clean up" shortcut on the Usage page (select the biggest files and trash them, or link to the duplicate finder); sorting and bulk actions on the Favorites page; a per-disk bar for stored vs counted bytes on the admin Storage page; show uploads that failed while the tab was closed (today only a live tab sees the failure).

## Risks

- Large uploads: PHP and proxy limits, assembling chunks; the final copy to a remote disk runs in a queue job, so it needs a worker and enough temp space (see `docs/install.md`).
- Serving user files from the app's own domain (HTML/SVG scripts): `nosniff`, forced download except for an allowlist, ideally a separate domain.
- Share tokens: long random, rate limits, constant-time password checks.
- Moving a folder into itself, name collisions.
- Livewire speed with thousands of files: paginate or virtualize.
