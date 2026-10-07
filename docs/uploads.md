# Uploads

All uploads, including small files, use one chunked protocol (`UploadController`, `resources/js/uploader.js`). There is no separate "simple" path.

The client is an Alpine store, `$store.uploads` (registered in `resources/js/app.js`), and the progress panel sits in a `@persist('uploads')` block in the layout, so uploads carry on while you navigate between pages. Each item remembers its target folder when it is added. A browser page reloads its list on the `shed-uploaded` window event. A full page reload or closed tab still stops an upload (the browser asks first); adding the same file again resumes it.

1. `POST /uploads` with `path` (a name, or a relative path such as `photos/2026/a.jpg`), `size`, optional `parent_id` and `fingerprint`. Missing folders in the path are created. Returns the upload `id`, the current `offset` and the `chunk_size`. Starting the same file again (same parent, name, size, fingerprint) resumes the unfinished upload.
2. `PATCH /uploads/{id}` with the raw chunk as body and an `Upload-Offset` header. A wrong offset gets `409` with the real offset, so the client resumes from there. The last chunk finalizes the upload.
3. `GET /uploads/{id}` returns the state; `DELETE /uploads/{id}` cancels.

Chunks are appended to `shed.tmp_path/{id}`. On completion the file is hashed (SHA-256), its MIME type is sniffed from the content (the client's is ignored), it is written to the default disk under a random key, and the node is created while the owner's row is locked, so quota and `used_bytes` stay consistent. A name collision keeps both files (`a (2).txt`).

Quota is checked when the upload starts and again on completion. It is not reserved in between, so parallel uploads can each pass the first check; the second one fails on completion.

`uploads:prune` (daily) removes unfinished uploads untouched for `shed.upload_ttl_hours`.

## Deployment limits

Chunks are `SHED_CHUNK_SIZE` bytes (default 5 MiB). The web server's body limit must be larger than that (nginx `client_max_body_size`). PHP's `upload_max_filesize` and `post_max_size` do not apply, since the body is not a form upload. Tested end to end with a 100 MB file; multi-GB testing is still open.
