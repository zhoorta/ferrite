# Folder sizes

A folder's `nodes.size` is the bytes of the files inside it, subfolders included, and `nodes.files_count` how many files that is. Neither counts what is in the trash or old versions (only each file's current content). Reading it costs nothing.

## How it stays right

`App\Support\FolderSizes::add($folderId, $delta)` adds a difference to that folder and every folder above it. The actions call it, the way they already adjust the owner's `used_bytes`:

| Event | Change |
|---|---|
| Upload completes | `+size` and `+1` file (a replace: the new size minus the old, no new file) |
| Restore an earlier version | the size difference |
| Copy | `+` the copy's total, into the destination |
| Move | `-size` from the old folders, `+size` to the new ones |
| Trash | `-size` from the folders above |
| Restore from the trash | `+size` to the folders above its new place |
| Purge | nothing: a trashed item was already taken out when it was trashed |

A folder in the trash keeps its own total (what is inside it) and the folders above it no longer count it, so the climb stops at a trashed folder. Deleting a user or purging the trash removes whole trees, which needs no change.

## Checking and repairing

`php artisan ferrite:folders:recount` rebuilds every folder's size and file count from the files. `--check` only reports (exit code 1 when a size is wrong), `--user=ID` limits it to one account. It is safe to run any time; a wrong size can only come from a code path that changes files without going through the actions above (a direct database edit, for instance).

The migration `backfill_folder_sizes` runs it once on deploy, so existing folders are filled in by `php artisan migrate`.

On a big drive it reads each user's nodes (id, parent, type, size, trash flag) into memory, one user at a time, like the usage page does.
