# Storage disks, search and activity

## Storage disks

Admins manage disks under **Storage** (`/admin/storage`, gate `admin`, i.e. `users.role = admin`). A disk is a row in `storage_disks` with a driver and encrypted settings; `StorageManager` turns it into a Flysystem filesystem.

| Driver | Settings |
| --- | --- |
| Local folder | absolute path |
| S3 or compatible | bucket, region, access key, secret, optional endpoint (MinIO, Hetzner, Backblaze...), path-style URLs, key prefix |
| SFTP | host, port, username, password and/or private key (+ passphrase), host fingerprint, absolute folder |

- **New uploads go to the default disk.** Files already stored stay on the disk they were written to (`nodes.disk_id`), so changing the default never moves data and old files keep working.
- **Test** writes, reads back and deletes a probe file and shows the error if it fails. Do it after adding a disk.
- Secrets are stored encrypted, are never sent back to the browser, and a blank secret field keeps the stored value. The driver of an existing disk cannot be changed.
- A disk that is the default, or still holds files, cannot be removed. Removing a disk never touches the remote storage.
- If the folder or bucket settings of a disk that holds files are changed, the files are no longer found. There is no data migration between disks yet.
- **SFTP:** set the host fingerprint, otherwise nothing stops someone impersonating the server.
- **S3 and seeking:** Range requests on S3 or SFTP read from the start and skip to the offset, so seeking far into a large video is slow on those disks. Not optimized yet.
- Remote drivers are covered by tests only up to adapter construction and "unreachable server" errors; there is no test against a real S3 or SFTP server, so try yours with **Test** and a real upload.

## Search

`NodeSearch` finds files and folders by part of the name (case-insensitive, `%` and `_` are literal) among a user's own items and everything below folders shared with them. Trashed items, and items inside trashed folders, are left out. Exact matches come first, then by name; at most 50 results. The sidebar has a search box and results live at `/search?q=`. It is a plain `LIKE` on the name: fine for personal use and small teams, and there is no index on it. In-content search is out of scope.

## Activity log

`activities` records what happens to nodes: uploads, new folders, renames, moves, trash and restore, share links created and revoked, sharing with people, and downloads by collaborators or through share links. Owners see what collaborators and guests did with their files; everyone sees their own actions.

- Entries copy the node name, so they stay readable after a rename or deletion. Deleting a user keeps their entries as "Someone".
- Owners' own downloads are not logged, and neither are the follow-up Range requests of a media player; only the start of a download counts.
- Guests are logged without an identity and **without an IP address**, on purpose. Adding one is a one-line change in `ActivityLog` if you want it, with the privacy implications that come with it.
- Entries are removed after `FERRITE_ACTIVITY_DAYS` (default 90) by the daily `activity:prune`.
