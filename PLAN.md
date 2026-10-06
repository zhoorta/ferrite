# Shed: plan

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
- [x] 2. Browser UI: list, create folder, rename, move, trash and restore (actions in `app/Actions/Nodes`; permanent delete and auto-purge come with blob deletion in step 3/4; 85 tests)
- [x] 3. Upload: chunked and resumable for all sizes, folder upload, quota (see `docs/uploads.md`; browser UI not yet checked by hand, multi-GB test open; 109 tests)
- [x] 4. Download, streaming with Range, folder ZIP, previews and thumbnails (see `docs/serving-files.md`; preview modal not yet checked by hand; added `maennchen/zipstream-php`; 159 tests)
- [x] 5. Share links, then sharing with users (see `docs/sharing.md`; dialog and guest page not yet checked by hand; 206 tests)
- [ ] 6. Storage disks (SFTP, S3), name search, activity log
- [ ] 7. Docker image, install docs, pre-publication security review

## Risks

- Large uploads: PHP and proxy limits, assembling chunks; test with multi-GB files.
- Serving user files from the app's own domain (HTML/SVG scripts): `nosniff`, forced download except for an allowlist, ideally a separate domain.
- Share tokens: long random, rate limits, constant-time password checks.
- Moving a folder into itself, name collisions.
- Livewire speed with thousands of files: paginate or virtualize.
