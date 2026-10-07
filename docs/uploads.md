# Uploads

All uploads, including small files, use one chunked protocol (`UploadController`, `resources/js/uploader.js`). There is no separate "simple" path.

The client is an Alpine store, `$store.uploads` (registered in `resources/js/app.js`), and the progress panel sits in a `@persist('uploads')` block in the layout, so uploads carry on while you navigate between pages. Each item remembers its target folder when it is added. A browser page reloads its list on the `ferrite-uploaded` window event. A full page reload or closed tab still stops an upload (the browser asks first); adding the same file again resumes it.

1. `POST /uploads` with `path` (a name, or a relative path such as `photos/2026/a.jpg`), `size`, optional `parent_id` and `fingerprint`. Missing folders in the path are created. Returns the upload `id`, the current `offset` and the `chunk_size`. Starting the same file again (same parent, name, size, fingerprint) resumes the unfinished upload.
2. `PATCH /uploads/{id}` with the raw chunk as body and an `Upload-Offset` header. A wrong offset gets `409` with the real offset, so the client resumes from there. The last chunk finalizes the upload.
3. `GET /uploads/{id}` returns the state; `DELETE /uploads/{id}` cancels.

Chunks are appended to `ferrite.tmp_path/{id}`. On completion the file is hashed (SHA-256), its MIME type is sniffed from the content (the client's is ignored), it is written to the default disk under a random key, and the node is created while the owner's row is locked, so quota and `used_bytes` stay consistent. A name collision keeps both files (`a (2).txt`).

Quota is checked when the upload starts and again on completion. It is not reserved in between, so parallel uploads can each pass the first check; the second one fails on completion.

`uploads:prune` (daily) removes unfinished uploads untouched for `ferrite.upload_ttl_hours`.

## Deduplication

Identical files of the same owner on the same disk (same SHA-256 and size) share one blob: the second upload skips the write and its node points at the existing key. Scope is per owner, so nobody can learn whether someone else stores a file. Quota is still charged per node at full size, so dedup only saves disk space.

There is no reference count to drift: a blob's users are the `nodes` rows with that `disk_id` and `path` (indexed). `PurgeNode` deletes the rows first, then removes the blob and its thumbnail only if no node still uses it. On upload, the source node is locked (`FOR UPDATE`) inside the transaction that creates the new node, so a concurrent purge either finishes first (the upload then writes its own copy) or sees the new node. Not done: skipping the upload itself (needs a client-side hash) and merging duplicates that were stored before this change.

## Deployment limits

Chunks are `FERRITE_CHUNK_SIZE` bytes (default 5 MiB). The web server's body limit must be larger than that (nginx `client_max_body_size`). PHP's `upload_max_filesize` and `post_max_size` do not apply, since the body is not a form upload. Tested end to end with a 100 MB file; multi-GB testing is still open.
