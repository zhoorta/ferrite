# Uploads

All uploads, including small files, use one chunked protocol (`UploadController`, `resources/js/uploader.js`). There is no separate "simple" path.

The client is an Alpine store, `$store.uploads` (registered in `resources/js/app.js`), and the progress panel sits in a `@persist('uploads')` block in the layout, so uploads carry on while you navigate between pages. Each item remembers its target folder when it is added. A browser page reloads its list on the `ferrite-uploaded` window event. A full page reload or closed tab still stops an upload (the browser asks first); adding the same file again resumes it.

1. `POST /uploads` with `path` (a name, or a relative path such as `photos/2026/a.jpg`), `size`, optional `parent_id` and `fingerprint`. Missing folders in the path are created. Returns the upload `id`, the current `offset` and the `chunk_size`. Starting the same file again (same parent, name, size, fingerprint) resumes the unfinished upload.
2. `PATCH /uploads/{id}` with the raw chunk as body and an `Upload-Offset` header. A wrong offset gets `409` with the real offset, so the client resumes from there. The last chunk finalizes the upload.
3. `GET /uploads/{id}` returns the state (`status`: `receiving`, `processing`, `done` with the `node`, or `failed` with an `error`); `DELETE /uploads/{id}` cancels (409 while processing).

Chunks are appended to `ferrite.tmp_path/{id}`. The last chunk only marks the upload `processing` and queues `FinalizeUpload`, answering `202` at once. The job (`CompleteUpload`) hashes the file (SHA-256), sniffs its MIME type from the content (the client's is ignored), writes it to the default disk under a random key, and creates the node while the owner's row is locked, so quota and `used_bytes` stay consistent. A name collision keeps both files (`a (2).txt`). With a remote disk the copy can take minutes, which is why it is not done inside the request: it would time out at the proxy.

The browser keeps sending the next file while a finished one is stored: the panel shows "Sent. Storing the file…" and polls `GET /uploads/{id}` (every 1 to 5 s) until `done` or `failed`, then deletes the record. Closing the tab during that phase is harmless; the file still arrives, only a failure message would be missed. With `QUEUE_CONNECTION=sync` (no worker) the job runs inside the last chunk's request, as before, and that response is `200` with the node (or `422`).

**Failures.** A job that fails (quota gone, disk unreachable, a crash) removes the temporary file and, if it already wrote a blob that no node uses, that blob from the disk (`FailUpload`; the key is noted on the upload row before writing, so this works after a crash too, and a copy that fails halfway deletes its partial file at once). The upload is marked `failed` with the reason, the browser shows it with a Retry button, and `uploads:prune` drops the record an hour later. A job has one try and a six-hour limit (`FinalizeUpload`; keep the queue's `retry_after`, 21700 s by default, above it or the job starts twice). `uploads:prune` also gives up on uploads stuck in `processing` for `FERRITE_PROCESSING_TIMEOUT_HOURS` (12; the worker was down or died) and cleans them the same way. If no node was created, there is nothing on the disk afterwards and nothing counted against the quota.

Quota is checked when the upload starts and again on completion. It is not reserved in between, so parallel uploads can each pass the first check; the second one fails on completion.

`uploads:prune` (daily) removes unfinished uploads untouched for `ferrite.upload_ttl_hours`, fails stuck ones and drops the records of finished ones (see above).

## Deduplication

Identical files of the same owner on the same disk (same SHA-256 and size) share one blob: the second upload skips the write and its node points at the existing key. Scope is per owner, so nobody can learn whether someone else stores a file. Quota is still charged per node at full size, so dedup only saves disk space.

There is no reference count to drift: a blob's users are the `nodes` rows with that `disk_id` and `path` (indexed). `PurgeNode` deletes the rows first, then removes the blob and its thumbnail only if no node still uses it. On upload, the source node is locked (`FOR UPDATE`) inside the transaction that creates the new node, so a concurrent purge either finishes first (the upload then writes its own copy) or sees the new node. Not done: skipping the upload itself (needs a client-side hash) and merging duplicates that were stored before this change.

## Deployment limits

Chunks are `FERRITE_CHUNK_SIZE` bytes (default 5 MiB). The web server's body limit must be larger than that (nginx `client_max_body_size`). PHP's `upload_max_filesize` and `post_max_size` do not apply, since the body is not a form upload. The temporary file needs room for the whole file: plan `FERRITE_TMP_PATH` (the `tmp/` folder of the data volume in Docker) for the largest file times the number of uploads in flight, plus the same again on the target disk. A queue worker must run (the Docker image starts one; see `docs/install.md`).

Tested end to end with a 100 MB file, and with a 3000 MB file to an SFTP server (a local OpenSSH one): see `tests/Feature/Remote/SftpDiskTest.php` and `docs/storage-search-activity.md`.
