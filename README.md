<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/img/logo-dark.svg">
    <img src="docs/img/logo.svg" alt="Shed" width="360">
  </picture>
</p>

# Shed

Self-hosted file storage for one person or a small team: a Google Drive replacement that runs on your own server. Web only, built with Laravel, Livewire and Flux. No sync clients and no WebDAV, by design.

## What it does

- **Files and folders** with upload (chunked and resumable, so multi-gigabyte files and flaky connections are fine; whole folders by drag and drop), download with Range support, rename, move, and folder download as ZIP.
- **Previews** for images, PDF, video, audio and text, plus image thumbnails.
- **Trash** with restore, permanent delete and automatic purge.
- **Sharing**: with other users (view or edit) and with **links** (optional password and expiry, view-only, revoke).
- **Several users** with quotas, an admin screen, two-factor authentication and passkeys.
- **Storage disks**: local folder, S3-compatible or SFTP, switchable per instance. New uploads go to the default disk.
- **Search** by name and an **activity log**.

Not included (on purpose, for now): sync clients, WebDAV, office document previews, real-time collaboration, in-content search, versioning.

## Run it with Docker

You need a server with Docker and a domain name pointing at it.

```sh
git clone <this repository> shed && cd shed
cp docker/shed.env.example shed.env
docker compose run --rm shed php artisan key:generate --show   # copy the output into APP_KEY in shed.env
$EDITOR shed.env                                               # set APP_KEY and APP_URL at least
docker compose up -d --build
```

Put a reverse proxy with HTTPS in front of port 8080 (examples in [docs/install.md](docs/install.md)), open your `APP_URL`, and **register**: the first account becomes the admin, and registration closes afterwards. Add everyone else under **Users**. To create or recover accounts from the command line:

```sh
docker compose exec shed php artisan shed:user you@example.com --admin
```

Everything that changes lives in one volume, `/data`. Back it up, and keep `APP_KEY` safe: see [docs/install.md](docs/install.md) for backups, upgrades and configuration.

> The Docker setup has been reviewed and its parts exercised individually, but the image itself has not been built and run end to end by the author yet. If it fails to build, please open an issue.

## Develop

```sh
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
composer dev            # or: php artisan serve, and npm run dev
php artisan test --compact
vendor/bin/pint && vendor/bin/phpstan analyse
```

`PLAN.md` has the scope and status, and `docs/` has the design notes:

| | |
| --- | --- |
| [docs/install.md](docs/install.md) | Install, configuration, reverse proxy, backup, upgrade |
| [docs/uploads.md](docs/uploads.md) | The chunked upload protocol |
| [docs/serving-files.md](docs/serving-files.md) | How files are served safely |
| [docs/sharing.md](docs/sharing.md) | Sharing with users and links |
| [docs/storage-search-activity.md](docs/storage-search-activity.md) | Disks, search, activity log |
| [docs/users.md](docs/users.md) | Accounts, registration, trash |
| [docs/security.md](docs/security.md) | Security review: what was checked, known limits |

## Security

Shed serves files uploaded by users from its own origin, so read [docs/security.md](docs/security.md) before exposing an instance to people you do not trust. Report vulnerabilities privately to the maintainer rather than in a public issue.

## License

Not chosen yet.
