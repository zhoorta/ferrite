# API (built: root, files, content, uploads and the Settings page)

A small HTTP API for scripts and other apps, first user: Magnetite, which keeps its music library in one Ferrite folder (see Magnetite's `docs/ferrite-storage.md`). Not sync, not WebDAV: list, read, add.

## Tokens

Personal access tokens (Sanctum), created in Settings > API tokens.

- **Name**, **folder** (a folder picker; "Whole drive" is possible but the dialog recommends one folder), **access** (`read` or `read + write`), **expiry** (never, 30 days, 90 days, 1 year).
- The token is shown once, with a copy button, together with the base URL of the API. Stored hashed (Sanctum default). The list shows name, folder, access, created, expires, last used (time and IP), and Revoke.
- A token reaches its folder and what is inside it, never the parents or siblings (same rule as share links, `ShareAccess::reaches`). Anything outside answers 404. Folders are addressed by id, so renaming or moving the folder does not break the token; trashing it ends the token's access (404).
- Revoked, expired or unknown: 401. A disabled owner: 403. `read` token on a write route: 403. Tokens do not need 2FA; they are the thing you give to a machine.
- v1 has no rename, move or purge through tokens. A `read + write` token can add files (until the quota is full) but not destroy any. A token created with **trash** access can also move files to the trash; they stay restorable in the web app (and count against the quota until purged), and only the signed-in owner can purge them.

## Endpoints

Base `/api/v1`, `Authorization: Bearer <token>`, JSON. Throttled per token (like share links: 120/min for reads, uploads like the drop-box limits). Every response carries `Cache-Control: no-store` except file content.

| Route | Purpose |
|---|---|
| `GET /root` | Test connection: `{name, id, access, expires_at, quota: {used, limit}}`. The name is the folder's, not its path. |
| `GET /files?cursor=` | Every file below the root, in id order, 1000 per page (the cursor is the last id, so files added meanwhile never shift a page; trashed files and folders are left out): `{id, path, size, sha256, mtime, mime}`. `path` is relative to the root, with `/`. `next_cursor` or null. Folders are implied by paths; empty folders are not listed. |
| `GET /files/{id}/content` | The bytes, through `NodeResponder`: Range, 206, `Accept-Ranges`, `ETag: "<sha256>"`, `If-None-Match` and `If-Range` honoured, `nosniff`. The disposition is `attachment`; the caller decides how to present it. |
| `DELETE /files/{id}` | Needs the `trash` ability. Moves a file to the trash and answers `{id, trashed: true}`; folders and anything outside the token's folder are 404. The activity log says which token did it. |
| `POST /uploads` | Start. `path` (relative to the root, missing folders are created), `size`, `fingerprint`. Same chunked protocol as `docs/uploads.md`. |
| `PATCH /uploads/{id}` | Chunk with `Upload-Offset`; wrong offset gives 409 with the real one. The last chunk answers 202. |
| `GET /uploads/{id}` | `receiving`, `processing`, `done` (with the file's `id`, `sha256`) or `failed` (with the reason). |
| `DELETE /uploads/{id}` | Cancel (409 while processing). |

Why `sha256` and not only `mtime`: Ferrite already stores it for dedup. It is the cache key and change detector for the caller (an unchanged path with an unchanged hash is never read again) and the `ETag`.

**Conflicts.** The API never replaces and never silently renames. `POST /uploads` fails with `409 {error: "exists", id, sha256}` if the path is taken, so a caller can tell "already there, same content" from "a different file is in the way". This is a new `on_conflict` mode (`fail`, the API default) next to the existing "keep both"; Replace stays a signed-in browser feature.

**Quota and activity.** Uploads count against the folder owner's quota (402-style `422 {error: "quota"}`), and the activity log says "uploaded through token *name*". Dedup, `FinalizeUpload`, `FailUpload` and `uploads:prune` are shared with browser uploads. Every route goes through `NodePolicy` with the token's ability, not a second permission system.

**Not in v1.** Purge, folder trashing, rename, move, mkdir on its own (uploads create folders), sharing, search, thumbnails, change feeds (`since=` with tombstones). A caller lists everything and diffs; for libraries of tens of thousands of files that is a few pages. Add `since` when a real caller feels it.

## How uploads behave (built)

- Same code as browser uploads (`StartUpload`, `AppendChunk`, `FinalizeUpload`, `CompleteUpload`, `FailUpload`, `uploads:prune`), `Api\UploadController` on top. `uploads` has `api_token_id`, `api_token_name` and `on_conflict` (`keep` for the browser, `fail` for tokens).
- **Ownership:** an upload belongs to the token that started it; any other token, even of the same account, gets 404 on it. An unfinished upload is resumed only by the same token (same path, size, fingerprint). Revoking the token orphans its uploads, which `uploads:prune` cleans up.
- **Paths:** relative to the token's folder, `/` separated; `..`, `.`, empty segments (leading slash, `//`) and control characters are 422. Names compare case-insensitively, like everywhere in Ferrite.
- **Conflicts:** `POST` answers `409 {error: "exists", id, sha256}` (sha256 null for a folder) before anything is created. If the name appears while the file is on its way, storing it fails (`422`, status `failed`, nothing kept, nothing charged), never "name (2)".
- **Response shape:** `POST` 201 (200 when resumed) with `id`, `offset`, `chunk_size`, `status`; `PATCH` with `Upload-Offset` gives the new offset, `202` for the last chunk (queue worker) or `200` with `status: done` (sync queue); `done` carries `node: {id, sha256}`. A wrong offset is 409 with the real one.
- **Activity:** "*who* uploaded *name* through the token *token*".
- **Limits per token:** reads 120/min, file content 1200/min, upload start 120/min, chunks and polling 1200/min.

## Security notes

- HTTPS only outside local development; the token is a password.
- Content is served by `NodeResponder` like every other route, so the type allowlist and `nosniff` still apply. Callers must not trust `mime` for anything but display.
- Token routes send `Referrer-Policy: no-referrer`, are rate limited per token and per IP, and write to the activity log. Failed token guesses count against the IP, like password guesses on share links.
- Encryption at rest (step 15): all reads go through the shared decrypting stream, so Range offsets map to plaintext.

## Build order

1. Schema and Sanctum: `personal_access_tokens` with `folder_id`; ability middleware; `GET /root`.
2. `GET /files` and `GET /files/{id}/content` (read-only tokens), Settings UI to create and revoke.
3. Uploads with write tokens and `on_conflict=fail`.
4. Pest (reads and uploads are covered in `tests/Feature/Api`): scope (outside the folder, parent, sibling, trashed, moved), abilities, expiry and revocation, listing pagination, Range and `If-Range`, conflict 409 with same and different content, quota, activity entries.
