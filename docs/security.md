# Security review

A review of Ferrite before publication, done by reading the code and testing the behaviour. It lists what was checked, what was found and fixed, and what is still a limitation. It is not a third-party audit.

## The main risk

Users upload files and Ferrite serves them from its own origin. Anything a browser renders inline (HTML, SVG, scripts) could run with the viewer's session. How this is handled:

- Files only display inline for an allowlist (common images, PDF, video, audio, text). Everything else, SVG and HTML included, is a download. Text-like files, HTML too, are sent as `text/plain`. See [serving-files.md](serving-files.md).
- MIME types come from server-side content sniffing at upload, never from the client; every file response has `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin`, and inline responses (except PDF) a `sandbox` Content-Security-Policy.
- Blobs are stored under random keys, so a user-chosen name never reaches the file system, and nothing under the web root is user-controlled.

**Still recommended for hostile environments:** serve user files from a separate cookie-less domain. Not implemented; it needs signed, short-lived URLs.

## Checked

| Area | Result |
| --- | --- |
| Routes behind login | A test enumerates the real route table and asserts that every route not on an explicit public list refuses anonymous visitors (`tests/Feature/Security/RouteAccessTest.php`). A new route that forgets protection fails the test. |
| Authorization (IDOR) | Every Livewire action and controller re-fetches the node and checks `NodePolicy` or the admin gate; ids from the browser are never trusted. Tests cover other users, share recipients, trashed items and forged ids. Admins get no special access to other people's files. |
| Admin actions | Gate `admin` on the routes, in the Livewire `mount` and again in every action, so a forged call fails even if a screen is reached. |
| Share links | 40-character random tokens, exact comparison, 404 for revoked, expired, unknown and trashed links, access limited to the shared subtree, password gate in front of every route, password unlock tied to the password hash, guess limits per link and IP, `Referrer-Policy: no-referrer` and `noindex`. |
| Uploads | Names validated (no slashes, control characters, `.` or `..`); relative paths checked per segment; chunk offsets enforced; a chunk past the declared size is refused; owner checked on every chunk; quota checked at start and again under a row lock at completion; unfinished uploads pruned. |
| XSS | Blade escaping everywhere; text previews are escaped (tested); file names in headers are encoded (tested); no raw HTML from user data. |
| CSRF | All state-changing routes are in the `web` group with CSRF protection; downloads are idempotent GETs. |
| Sessions and accounts | Login throttling, 2FA, passkeys, strong password rules in production, disabled accounts signed out on their next request and their stored sessions removed. Registration closes after the first account. |
| Secrets | Storage credentials are encrypted at rest, hidden from serialization, never sent to the browser (blank fields keep the stored value). |
| Headers | `nosniff`, `X-Frame-Options: SAMEORIGIN`, referrer and permissions policies on every response including error pages; HSTS on HTTPS requests. |
| Generated URLs | In production all links come from `APP_URL`, not from the request's Host header (protects reset links and share links from header poisoning). |
| Dependencies | `composer audit`: no advisories. `npm audit`: advisories only in build tooling (see below). |

## Found and fixed during the review

- **Permanent delete did not exist**, so the trash never freed storage or quota. Added, with automatic purge.
- **Open registration**: anyone could sign up. Now closed after the first (admin) account.
- **Laravel's `/storage/{path}` route** for the default disk was enabled. It was signed-URL only (verified), but unused, so it is switched off.
- **Host header trust**: links could be built from the request host. Fixed with `APP_URL` in production.
- **SQLite locking**: concurrent requests could fail with "database is locked", and the quota bookkeeping relies on serialized writes. Now WAL, busy timeout and immediate transactions (checked with parallel requests).
- **Long streams**: big downloads and ZIPs could hit PHP's execution time limit; removed for those responses.
- Entrypoint asked for `APP_KEY` even to generate one.

## Known limitations

- **Same-origin file serving** (above). Mitigated, not eliminated.
- **No Content-Security-Policy on the app's own pages**: Livewire and Flux use inline scripts. User files do get one.
- **Share tokens are stored in plain text** so owners can copy a link later. A database leak exposes the links of unrevoked shares (not the files' contents on other disks, but anything reachable through a link). Hashing them is possible at the cost of showing a link only once.
- **"View only" is not copy protection**: whatever a browser can display, it can save.
- **No maximum file size, and quota defaults to unlimited** for users the admin creates without one. A user can fill the disk. Set quotas.
- **Image decoding** (thumbnails) uses GD on untrusted images, with size, pixel and memory limits. Keep PHP updated; a parser bug in GD would be reachable by any user who can upload.
- **Rate limiting** covers login, 2FA, passkeys, share-link passwords and a general limit on share routes. Uploads and downloads by signed-in users are not throttled.
- **Existence oracle**: node ids are sequential, and a forbidden node answers 403 while a missing one answers 404, so a user can tell whether an id exists (not what it is).
- **Admins are trusted**: an admin can point a local disk at any folder the web server can write, and an S3/SFTP disk at any host the server can reach. Do not give the admin role to people you do not trust with the server.
- **Activity log keeps no IP addresses**, and failed logins are only throttled, not logged.
- **Losing `APP_KEY`** makes stored 2FA secrets and disk credentials unreadable.
- **Build tooling advisories**: `npm audit` reports critical issues in `vite-plus` (via `oxfmt`/`tinypool`) and `concurrently` (via `shell-quote`). They affect development and the asset build only; none of it ships in the Docker image or runs in production. The suggested fix upgrades `vite-plus` and breaks the build, so it was not applied.
- **Docker image not yet built end to end** by the author; its pieces (entrypoint order, production settings, SQLite, cached routes) were exercised separately in a production-mode run.

## Before going public

1. Set `APP_URL`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE=true`, a real `APP_KEY`, and HTTPS at the proxy.
2. Create the admin account first, then check that `/register` answers 404.
3. Set quotas for every user.
4. Turn on two-factor authentication or passkeys for admin accounts.
5. Decide whether to publish with a separate files domain.
