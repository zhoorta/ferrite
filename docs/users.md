# Users

## Getting the first account

On a fresh install (no users yet) the sign-up page is open: the first person to register becomes the **admin**, with a verified e-mail, so no mail setup is needed to get in. Once there is a user, sign-up closes (`/register` is 404 and the link disappears). Set `FERRITE_REGISTRATION=true` to let anyone register as an ordinary user, which is rarely what a private instance wants.

From then on admins add people under **Users** (`/admin/users`).

## Admin screen

- Create users with a name, e-mail, password, role (user or admin) and a **quota in GB** (empty is unlimited). Accounts made by an admin are marked verified.
- Edit anything; a blank password keeps the current one. Lowering a quota below what someone already uses does not delete anything, it only blocks new uploads.
- **Disable** keeps files and share links but blocks the account: it cannot sign in (password, 2FA or passkey), it is signed out on its next request and its stored sessions are removed.
- **Delete with files** removes the user and permanently deletes everything they own, blobs included.
- Admins cannot disable or delete themselves, or remove their own admin role, so there is always one admin who can still manage the instance. Admins have no access to other people's files (see `NodePolicy`).

## Trash

Trashed items can be restored for `FERRITE_TRASH_DAYS` (default 30); then the daily `trash:purge` deletes them for good, blobs and thumbnails included, and the owner's quota is credited. "Delete permanently" and "Empty trash" on the Trash page do it immediately. The database row is removed first and blobs afterwards, so a failure can leave a stray blob (wasted space), never a file whose blob is missing.
