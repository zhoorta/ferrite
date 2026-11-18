# Encryption at rest

An optional, server-side feature: a storage disk can be set to **Encrypt files**, and then every blob written to it is
stored encrypted. It is about the disk, not the person: it applies to everyone whose files land on that disk.

## What it protects, and what it does not

Protects against someone who gets the **storage** without the server: a stolen drive, a leaked or misconfigured S3
bucket, a Storage Box account that is compromised, a backup of the blobs that ends up in the wrong place.

Does **not** protect against:

- Someone who has the server and its `.env`: the key sits next to the app, because the app has to read the files for
  you. Host full-disk encryption is the simpler tool for a stolen server.
- A compromised Ferrite process or an admin with shell access.
- Metadata. File and folder **names, sizes, types, dates and who owns what** live in the database, unencrypted. A leaked
  database or backup of it shows the names.
- Share links and the web UI, which of course decrypt for whoever is allowed to see the file.

Per-user keys and end-to-end (browser) encryption were considered and rejected: they break share links, thumbnails,
search and password resets (a reset would lose the files).

## What is encrypted and what is not

| | |
|---|---|
| File content | Encrypted (XChaCha20-Poly1305, 64 KiB chunks) |
| Thumbnails | Encrypted, with the same key as their file |
| Extracted search text | **Not stored** for files on encrypted disks, unless `FERRITE_SEARCH_ENCRYPTED=true` (then it is in the database in the clear, which undoes the point for anyone with a copy of the database). Names stay searchable |
| Names, folders, sizes, sha256 | Database, not encrypted. The sha256 is that of the plaintext (it is what deduplication and the API's `ETag` use) |
| Unfinished uploads | Wait in `FERRITE_TMP_PATH` as plaintext until they are complete; put it on an encrypted volume if that matters |

## Setting it up

1. Create a key and put it in `.env`:

   ```
   php -r "echo base64_encode(random_bytes(32));"
   FERRITE_FILE_KEY=<the output>
   ```

2. **Back it up now**, together with `APP_KEY`, somewhere that is not the server (a password manager). Without it every
   encrypted file is permanently unreadable. A backup of the files is useless without it.
3. Admin > Storage > add a disk with **Encrypt files** ticked. The setting is chosen when the disk is created; it cannot
   be switched on or off for a disk that already holds files (you would have a disk of unknown content).
4. To encrypt a disk that already has files, run `php artisan files:encrypt <disk name or id>`. It turns the setting
   on, so new uploads are encrypted from then on, and converts the existing files. See below.

A disk that is set to encrypt **never** writes plaintext: if `FERRITE_FILE_KEY` is missing, uploads to it fail (and leave
nothing on the disk) instead of silently storing the file in the clear.

## `files:encrypt`

```
php artisan files:encrypt {disk} [--yes] [--no-verify] [--limit=N]
```

Converts the plaintext blobs of one disk, in place:

- For each blob it writes an encrypted copy under a new random key, **reads that copy back and compares its sha256**
  with the one stored for the file (skip with `--no-verify`), and only then points the files at it and deletes the old
  blob. A crash at any moment leaves every file readable; run it again to carry on.
- Files that share a blob (deduplication, copies, trashed files) are moved together. `updated_at` does not change.
- A file whose blob cannot be read is reported and left alone, and the exit code is non-zero.
- `--limit` converts that many blobs and stops, to do a big disk in stretches.
- It needs space for the largest blob (not the whole disk): the old blob is deleted as soon as its copy is checked.

The disk keeps working normally while it runs: reads handle encrypted and plaintext blobs side by side (the file row
says which), and uploads to it are already encrypted.

## Key rotation: `files:rekey`

Every blob has its own random data key, stored in its header wrapped with the master key (`FERRITE_FILE_KEY`), and the
header records which master key id was used. To rotate:

1. Generate a new key. In `.env`, make it current and keep the old one for reading:

   ```
   FERRITE_FILE_KEY=<new key>
   FERRITE_FILE_KEY_ID=2
   FERRITE_FILE_KEYS_OLD=1:<old key>
   ```

2. `php artisan files:rekey`. It writes each blob again with a header wrapped by the new key; the encrypted chunks are
   copied as they are, so the content is not decrypted or re-encrypted. It checks the result like `files:encrypt`.
3. When it reports nothing left to do, remove `FERRITE_FILE_KEYS_OLD`. Keep a copy of the old key for as long as any
   **backup** of the files from before the rotation exists: those blobs are wrapped with it.

## Format

A blob is a header and then chunks:

```
header (98 bytes)  "FRT1" | master key id (2) | chunk size (4) | nonce prefix (16) | wrap nonce (24) | wrapped data key (48)
chunk i            XChaCha20-Poly1305(plaintext of up to 65536 bytes), 16 byte tag
```

- Chunk `i` is sealed with the nonce `prefix . i` and with additional data saying whether it is the last chunk. Chunks are
  independent, so a ranged read, a video seek or a ZIP entry decrypts only the chunks it needs, and a blob whose chunks
  are reordered, duplicated, truncated or altered fails authentication instead of returning data.
  (libsodium's secretstream would be simpler but cannot start in the middle of a stream.)
- The blob's size is a function of the file's size, so the position of any chunk is computed, never searched for.
- The header is bound to the wrapped key, so a modified header fails too.
- Which blobs are encrypted is recorded in the database (`nodes.encryption_key_id`, null for plaintext), not guessed
  from the content: a plaintext file that happens to start with `FRT1` is still plaintext.

Every place that reads a file (download, Range, preview, ZIP, share links, thumbnails, content extraction, the API) goes
through `StorageManager::openNodeStream()`, so there is one decrypting path. A copy duplicates the stored bytes and
keeps the same marker.

## Cost

Measured on a development laptop with PHP's libsodium, a 512 MiB file read from and written to a local SSD: encrypting
while writing about 230 MB/s, decrypting while hashing about 125 MB/s (sha256 alone about 180 MB/s), constant memory
(a few MiB, one chunk at a time). Encrypted files are 17 bytes per 64 KiB plus 98 bytes bigger on the disk (about 0.03%).
On remote disks the network is the limit. Slow CPUs (a small VPS) may reach 100 to 200 MB/s; measure on yours.

## Not done yet

- Not tested against a real S3 bucket or a real SFTP server (the stream is a plain PHP stream, so it should work the
  same as any other blob), like the rest of the remote storage.
- Backups: encrypted blobs back up as they are, which is what you want; the key is not in them. See "Backups" in
  `docs/install.md`.
