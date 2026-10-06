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

Guests cannot upload or change anything.
