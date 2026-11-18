# Versions

Replacing a file keeps its earlier content, so a bad edit or a wrong upload can be undone. Versions exist only for
**replace**: uploading over a file of the same name and answering "Replace" in the dialog (or, later, the API's
replace). Renaming, moving and copying do not make versions.

## How it works

- A file is a node with its current content. When it is replaced, the old content is kept as a **version**: a stored
  blob with its size, hash, who replaced it, and when that content was last changed. The node keeps its id, so share
  links, favorites and the activity history stay with the file.
- A replace that does not change the bytes (the same sha256) makes no version.
- At most `FERRITE_VERSIONS_KEEP` versions are kept per file (default 5), the oldest dropped first.
  `FERRITE_VERSIONS_KEEP=0` turns versions off: a replace deletes the old content, as it did before versions existed.
  `FERRITE_VERSIONS_DAYS` also drops versions older than that many days (default 0: no age limit). `versions:prune`
  runs daily to apply a lowered limit.
- **Versions count against the owner's quota** (a file replaced with a bigger one costs the whole new size, because
  the old one stays). A version never blocks a replace: if the quota cannot hold the old content as well, it is let go
  as with versions off, and the replace goes through.
- Deleting a file for good (emptying the trash, the trash retention, deleting a user) deletes its versions and gives the
  space back. Trashing a file keeps them; a trashed file's versions are not shown until it is restored.
- Copying a file does not copy its versions.

## In the web app

File menu > **Versions** (files you can change: owners and editors; viewers and share-link guests cannot see old
content, which may be what someone replaced on purpose). The dialog lists the current content and the earlier versions
with date, size and who replaced them, and offers:

- **Download** a version (`/nodes/{id}/versions/{version}`).
- **Restore**: the version becomes the current content and the content it replaces becomes a version, so restoring can
  be undone. The quota does not change (the same blobs, swapped).
- **Delete** a version for good (its blob and its bytes).

## With the other features

- **Encryption** (`docs/encryption.md`): a version keeps the encryption of its blob and reads through the same path.
  `files:encrypt` and `files:rekey` convert versions with the files.
- **Storage check** (`docs/storage-check.md`): versions are checked, verified and counted like files; an orphan is a
  blob nothing, versions included, points at. The usage per disk in Admin > Storage counts version blobs.
- **Disks**: a disk that still holds versions cannot be removed, and its encryption cannot be switched.
- **Search**: only the current content is indexed.

## Limits

- A version is a whole copy: a big file edited often costs its full size per version (no delta storage). Keep the
  limit low for big files, or set `FERRITE_VERSIONS_KEEP=0`.
- Versions are not in the API yet; replace through the API comes with the Mac client work (`PLAN.md`).
