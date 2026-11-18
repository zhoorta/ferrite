# Storage check and integrity scrub

`php artisan ferrite:storage:check` compares what the database says is stored with what is on the disks. It only
reports: it never repairs, moves or deletes anything.

A **blob** is one stored object. Identical files (deduplicated, copied, trashed) share it, so it is checked once.

## What it checks

| Option | Checks | Cost |
|---|---|---|
| (none) | every blob exists and has the size its file promises (the file's size, or the encrypted size for an encrypted blob) | one request per blob; cheap |
| `--verify` | the **integrity scrub**: reads every blob back (decrypting where needed) and compares the sha256 of the content with the one stored for the file | reads all the data; slow, and on a remote disk it downloads it |
| `--verify --limit=N` | the same for at most N blobs, the **least recently verified first** | bounded; repeated runs cover everything |
| `--orphans` | also lists blobs on the disks that no file and no unfinished upload points at (thumbnails are ignored) | lists the whole disk; slow on a big remote one |
| `--disk=NAME` | only that disk | |

Problems are printed one per line and the exit code is non-zero:

- `MISSING`: the blob is not on the disk.
- `SIZE`: the blob has another size than expected (a truncated copy, a partial restore).
- `CORRUPT`: the size is right but the content no longer matches its sha256 (bit rot, a swapped file).
- `UNREADABLE`: it could not be read at all, or an encrypted blob failed authentication (damaged, or the wrong
  `FERRITE_FILE_KEY`).

The sha256 stored for a file is that of its plaintext, so the scrub is a real end-to-end check of encrypted blobs too.
Every blob that passes `--verify` gets `nodes.verified_at` (shared by the files that use it); that is what makes the
limited runs rotate.

## Running it every night

Set `FERRITE_SCRUB_BLOBS=N` and the scheduler (`php artisan schedule:run` every minute, see `docs/install.md`) runs
`ferrite:storage:check --verify --limit=N` at 03:30. It reads the N least recently verified blobs, so a library of
10 000 blobs with N=500 is fully verified every twenty nights. Pick N so the run finishes in the quiet hours: at about
125 MB/s (what a laptop does while decrypting) a night of 1 hour is about 450 GB. On a remote disk the network is the
limit, and it counts as download traffic.

Problems found by a scheduled run are written to the application log as an error (`ferrite:storage:check found
problems`, with the first twenty). Watch the log, or run the command by hand after a restore.

## After a restore, or after encrypting a disk

```
php artisan ferrite:storage:check --verify
```

On the restored (or newly encrypted) instance this proves, file by file, that what is on the disk is what was
uploaded. `files:encrypt` already does this for each blob it converts (`docs/encryption.md`).

## When it reports a problem

- Restore the blob from a backup of the files (the file's sha256 tells you which version you need), then run the check
  again. The check never deletes: a damaged blob stays where it is until you replace it.
- `MISSING` for files nobody needs any more: trash and purge them in the app, which removes the row.
- Orphans are safe to delete once you are sure no restore is in progress. Ferrite does not delete them for you.
