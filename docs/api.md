# API (built: root, files, content, uploads and the Settings page)

A small HTTP API for scripts and other apps, first user: Magnetite, which keeps its music library in one Ferrite folder (see Magnetite's `docs/ferrite-storage.md`). Not sync, not WebDAV: list, read, add.

## Tokens

Personal access tokens (Sanctum), created in Settings > API tokens.

- **Name**, **folder** (a folder picker; "Whole drive" is possible but the dialog recommends one folder), **access** (`read`; `read + write`; `read + write + replace`; `read + write + trash`; or all four), **expiry** (never, 30 days, 90 days, 1 year).
- The token is shown once, with a copy button, together with the base URL of the API. Stored hashed (Sanctum default). The list shows name, folder, access, created, expires, last used (time and IP), and Revoke.
- A token reaches its folder and what is inside it, never the parents or siblings (same rule as share links, `ShareAccess::reaches`). Anything outside answers 404. Folders are addressed by id, so renaming or moving the folder does not break the token; trashing it ends the token's access (404).
- Revoked, expired or unknown: 401. A disabled owner: 403. `read` token on a write route: 403. Tokens do not need 2FA; they are the thing you give to a machine.
- Abilities are separate on purpose. `write` adds files, moves, renames and makes folders but never overwrites or destroys; `replace` lets an upload overwrite an existing file (with a version kept, see below); `trash` moves files and folders to the trash, lists the trash and restores from it. The browser approval for apps (`/connect`) preselects all four abilities. v1 has no purge through tokens. A `read + write` token can add files (until the quota is full) and move them between folders of its own folder, but not destroy any. A token created with **trash** access can also move files to the trash; they stay restorable in the web app (and count against the quota until purged), and only the signed-in owner can purge them.

## Connecting an app (browser approval)

The native apps do not ask for a password or a pasted token. They send you to the browser, where you sign in as usual (2FA included), and the server hands them a token:

1. The app generates a random `verifier` (43 to 128 characters of `A-Za-z0-9-._~`) and a `challenge`, the base64url (no padding) SHA-256 of it. It opens `/connect?client=Ferrite for Mac&device=MacBook&redirect=ferrite://auth&challenge=…&state=…` in a web authentication session (`ASWebAuthenticationSession` on Apple, a Custom Tab on Android). `state` is optional and comes back unchanged.
2. The user signs in, confirms the password (like creating a token in Settings), picks the **folder**, **access** and **expiry**, and allows or cancels. The page only returns to an address listed in `FERRITE_CONNECT_REDIRECTS` (default `ferrite://auth`, comma-separated); anything else shows "This link is not valid", so a crafted link cannot send the code elsewhere.
3. The browser goes to `ferrite://auth?code=…&state=…` (or `?error=access_denied&state=…` when cancelled).
4. The app posts `{code, verifier}` to `POST /api/v1/connect/token` and gets the token, shown once. The code lives two minutes and works once, even for a wrong verifier. The token is created at that moment, so only the approved choices wait on the server, never a secret. A code is useless without the verifier, so another app that registers the same scheme cannot use a code it intercepts.

The token is named "`client` (`device`)" and appears in Settings > API tokens, where it can be revoked like any other. Off on the demo.

## Endpoints

Base `/api/v1`, `Authorization: Bearer <token>`, JSON. Throttled per token (like share links: 120/min for reads, uploads like the drop-box limits). Every response carries `Cache-Control: no-store` except file content.

| Route | Purpose |
|---|---|
| `GET /ping` | No token. `{app: "ferrite", api: 1, name, connect}`: lets an app check that an address is a Ferrite server before it opens the browser. Throttled per IP. |
| `POST /connect/token` | No token. `{code, verifier}` for `{token, name, access, expires_at}`: the last step of the browser approval, see below. 400 when the code is wrong, used or expired. |
| `GET /root` | Test connection: `{name, id, access, expires_at, quota: {used, limit}}`. The name is the folder's, not its path. |
| `GET /files?cursor=` | Every file below the root, in id order, 1000 per page (the cursor is the last id, so files added meanwhile never shift a page; trashed files and folders are left out): `{id, path, size, sha256, mtime, mime}`. `path` is relative to the root, with `/`. `next_cursor` or null. Folders are implied by paths; empty folders are not listed (use `parent` to see them). |
| `GET /files?parent=&cursor=` | One folder level instead of the whole tree. `parent` is `root` (the token's folder, or the top of the drive for a whole-drive token) or the id of a folder the token reaches; anything else is 404, a malformed value 422. Direct children, folders and files, not trashed, 1000 per page in id order with the same cursor (the caller sorts). Each item: `{id, name, type: "file"\|"folder", path, size, file_count, sha256, mtime, mime}`; folders have `size` (the total of the files inside, not counting the trash; and `file_count`, how many files that is; the server keeps it up to date, see `docs/folder-sizes.md`), and `sha256` and `mime` null. `path` is relative to the root. Without `parent` the full listing above is unchanged. |
| `GET /files?q=&scope=` | Search below the token's folder (the whole drive for a whole-drive token), in the token owner's own files only, never what is shared with the account. `scope=name` (default) matches names, files and folders, case-insensitive, `%` and `_` as plain text; `scope=content` matches the text of files (the web app's content search) and adds a plain-text `snippet` to each item, empty when content search is off. Same item shape as the folder listing, best match first, 50 results for names and 30 for content, no cursor (`next_cursor` is null; `q` over 200 characters or an unknown `scope` is 422). Trashed items, items in trashed folders and everything outside the token's folder never appear. `q` wins over `parent`. |
| `GET /files/{id}/thumbnail` | A small JPEG preview (256 px on the longest side) of an image file, the one the web app shows, for a grid view. `ETag` (`If-None-Match` answers 304) and `Cache-Control: private, max-age=86400`. 404 when the file has none: not an image (decide from `mime`), over 30 MB or too many pixels, damaged, or outside the token's folder. Needs only `read`. Made on first request and cached, so the first grid of a big folder is slower; encrypted files get an encrypted cache. |
| `GET /files/{id}/content` | The bytes, through `NodeResponder`: Range, 206, `Accept-Ranges`, `ETag: "<sha256>"`, `If-None-Match` and `If-Range` honoured, `nosniff`. The disposition is `attachment`; the caller decides how to present it. |
| `GET /files?trashed=1` | Needs the `trash` ability (403 otherwise). What was thrown away from inside the token's folder (the whole drive for a whole-drive token): the items at the top of the trash, newest first, up to 1000, `{id, name, type, path, size, file_count, sha256, mtime, mime, trashed_at}` with the `path` they had. |
| `POST /files/{id}/restore` | Needs the `trash` ability. Puts a trashed item back where it was, with a " (2)" suffix when the name was taken meanwhile, and answers `{id, name, path, restored: true}`. A token limited to a folder only restores what was inside it (otherwise 404) and only while the folder it sat in is not in the trash: `409 {error: "parent_trashed"}`, restore the folder first. The activity log says which token did it. |
| `DELETE /files/{id}` | Needs the `trash` ability. Moves a file or a folder (with everything in it) to the trash and answers `{id, trashed: true}`; anything outside the token's folder is 404, and the token's own folder is 403. The activity log says which token did it. |
| `POST /files` | Needs `write`. Body `{parent, name}`: create a folder. `parent` is `root` (the token's folder, or the top of the drive for a whole-drive token) or the id of a folder the token reaches (404 otherwise); `name` follows the web app's rules (422). Answers `201 {id, name, type: "folder", path}`. A name already taken there, by a file or a folder and ignoring case, is `409 {error: "exists", id, type}`, so a caller can reuse an existing folder; nothing is replaced. The activity log says which token did it. |
| `PATCH /files/{id}` | Needs `write`. One thing per request: `{folder}` moves a file, `{name}` renames a file or a folder; sending both or neither is 422. `folder` is the destination as a path relative to the token's folder (`""` for the folder itself; missing folders are created like uploads; `..`, `.` and control characters are 422); the file keeps its name. `name` follows the web app's rules (no slashes or control characters, 255 characters, unique per folder ignoring case) and cannot rename the token's own folder (404). Answers `{id, name, path}`. Never replaces: a name already taken there is `409 {error: "exists"}` and nothing changes. Folders cannot be moved, and anything outside the token's folder is 404. The activity log says which token did it. |
| `POST /uploads` | Start. `path` (relative to the root, missing folders are created), `size`, `fingerprint`. Same chunked protocol as `docs/uploads.md`. To **replace** an existing file also send `replace: true` and `base`, the sha256 of the content your copy was based on (the `sha256` you read from the listing or the `ETag` of the download); the token needs the `replace` ability (403 otherwise). See "Replacing" below. |
| `PATCH /uploads/{id}` | Chunk with `Upload-Offset`; wrong offset gives 409 with the real one. The last chunk answers 202. |
| `GET /uploads/{id}` | `receiving`, `processing`, `done` (with the file's `id`, `sha256`) or `failed` (with the reason). |
| `DELETE /uploads/{id}` | Cancel (409 while processing). |

Why `sha256` and not only `mtime`: Ferrite already stores it for dedup. It is the cache key and change detector for the caller (an unchanged path with an unchanged hash is never read again) and the `ETag`.

**Replacing.** An upload overwrites a file only when you ask for it: `replace: true`, `base` (a sha256, required with `replace`) and a token with the `replace` ability. Started with a `base` that is not the file's current sha256, it is `409 {error: "changed", id, sha256}` with what is there now and nothing is stored, so a client that edited an old copy cannot overwrite someone else's work; it can download the new one, merge, and try again with the new `base`. The check is made again when the new content has arrived: if the file changed, was trashed or was removed meanwhile, the upload ends as `failed` (`error` says so) and nothing is replaced. A path that does not exist is `404 {error: "not_found"}` (a replace never creates), a folder `409 {error: "exists", type: "folder"}`. The file keeps its id (share links and favorites stay), and while versions are on (`docs/versions.md`) the old content is kept as a version the owner can restore in the web app; with `FERRITE_VERSIONS_KEEP=0` it is deleted. The quota only needs room for the growth, and a version that does not fit is let go instead of failing the replace. The activity log says "replaced ... through the token".

**Conflicts.** Without `replace` the API never replaces and never silently renames. `POST /uploads` fails with `409 {error: "exists", id, sha256}` if the path is taken, so a caller can tell "already there, same content" from "a different file is in the way". This is a new `on_conflict` mode (`fail`, the API default) next to the existing "keep both"; A replace is the explicit case above.

**Quota and activity.** Uploads count against the folder owner's quota (402-style `422 {error: "quota"}`), and the activity log says "uploaded through token *name*". Dedup, `FinalizeUpload`, `FailUpload` and `uploads:prune` are shared with browser uploads. Every route goes through `NodePolicy` with the token's ability, not a second permission system.

**Not in v1.** Purge (only the signed-in owner empties the trash), moving folders, sharing, change feeds (`since=` with tombstones). A caller lists everything and diffs; for libraries of tens of thousands of files that is a few pages. Add `since` when a real caller feels it.

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
