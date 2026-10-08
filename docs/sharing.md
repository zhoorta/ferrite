# Sharing

Two kinds: with other users of the instance, and with anyone through a link.

## With users

`node_user` rows give a user `view` or `edit` on a node and, through it, on everything below. The owner shares by e-mail address (exact match, no user search, so accounts cannot be enumerated by typing). `NodePolicy` resolves access by looking at the node and its ancestors.

- `view`: browse, preview, download, ZIP.
- `edit`: also rename, create folders and upload into the folder.
- Only the owner can share, move, trash, restore or delete. Uploads into a shared folder count against the owner's quota.
- Shared items are listed under "Shared with me"; a shared folder opens in the normal file browser.

## With links (`shares`)

A link is a random 40-character token in the URL (`/s/{token}`), for a file or a folder.

- Optional password (bcrypt), optional expiry (1, 7 or 30 days from the dialog), a "view only" flag, and revocation. Revoked, expired, unknown and trashed (the node or any ancestor) links all answer 404, so nothing is revealed about them.
- A link reaches the shared node and what is inside it, never its parents or siblings (`ShareAccess::reaches`). Files and folders are checked on every request; node ids sent by the browser are never trusted.
- The password gate: until unlocked, the page shows only the password form (not even the name) and file routes answer 403. Unlocking is remembered in the session, tied to the current password hash, so changing the password locks everyone out again. Guesses are limited to 5 per link and IP and 30 per link, per 5 minutes.
- Files are served by the same `NodeResponder` as for signed-in users (nosniff, attachment unless allowlisted, Range, ZIP). Share pages send `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`.
- "View only" removes the download buttons and refuses `download` and ZIP. Anything the browser can show inline (images, video, PDF, text) can still be saved from the preview, so this is a convenience, not copy protection. Types that could only be downloaded are refused outright.
- Tokens are stored in plain text so the owner can see and copy a link again later. The database already holds everything needed to read the files' metadata, but not the blobs; if that tradeoff does not suit you, hash the token and show the link only once.

Guests cannot change anything through a view link.

## Upload links (drop-box)

A folder can also get an **upload link** (`shares.kind = dropbox`). Whoever has it can add files to that folder and nothing else: the page shows a drop zone, not the folder's contents, and download, preview, thumbnail and ZIP routes answer 404 for it.

- Password and expiry work as for view links; revoking, expiry or trashing the folder end it at once (404). An optional **size limit** (GB) caps what the link accepts in total (`shares.max_bytes`, `received_bytes`); it is checked when an upload starts (counting uploads on their way) and again when the file is stored, which is what really enforces it.
- Uploads use the same chunked protocol as signed-in users, at `/s/{token}/uploads` (`DropboxUploadController`, `StartDropboxUpload`). The upload belongs to the folder's owner (`user_id`) and carries `share_id`. A guest can only touch uploads started in their own session through that link: the ids are remembered in the session.
- Files land directly in the shared folder, owned by the folder's owner and counted against their quota. A name that is taken gets a suffix (nothing is ever overwritten), and a dropped folder is flattened to its file names.
- The owner sees "name was uploaded through an upload link" in the activity log. Disabled owners and full quotas refuse the upload.
- Guests cannot delete or change what they sent, and cannot see it afterwards beyond the progress list on the page.
- Not included: e-mail notification, download limits, per-IP caps beyond the request throttles.

In the file browser, items you own that have an active link, an upload link or people they are shared with show small icons next to the name (hover for the details); clicking them opens the share dialog.
