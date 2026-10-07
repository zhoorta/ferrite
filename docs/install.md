# Installing Ferrite

## Requirements

A server with Docker and Docker Compose, a domain name, and a reverse proxy that terminates HTTPS (Caddy, nginx, Traefik...). 512 MB of RAM is enough for a small team; disk space depends on what you store.

Without Docker: PHP 8.3+ with the `gd`, `exif`, `zip`, `intl`, `bcmath` and `pdo_sqlite` or `pdo_mysql` extensions, Composer, Node (to build assets), a web server pointing at `public/`, and a cron entry running `php artisan schedule:run` every minute.

## Docker

```sh
cp docker/ferrite.env.example ferrite.env
docker compose run --rm ferrite php artisan key:generate --show
```

Edit `ferrite.env`: paste the key into `APP_KEY` and set `APP_URL` to the public address (with `https://`), and `TRUSTED_PROXIES` to your proxy's address. Then:

```sh
docker compose up -d --build
docker compose logs -f ferrite
```

On start the container creates the database, runs migrations, caches the configuration and starts the scheduler (trash purge, upload cleanup, log pruning). The first person to open the site and register becomes the admin.

### The data volume

`/data` (the `ferrite-data` volume) holds everything that changes:

| Path | Contents |
| --- | --- |
| `database.sqlite` | Users, folders, shares, activity (when using SQLite) |
| `files/` | The files themselves, under random names (the default local disk) |
| `tmp/` | Uploads in progress |
| `storage/` | Logs, sessions, caches |

The container runs as `www-data` (uid 33). If you replace the volume with a bind mount, make it writable for that user: `chown -R 33:33 /path`.

### Reverse proxy

Ferrite listens on plain HTTP, port 8080. The proxy must allow request bodies of at least the upload chunk size (5 MB by default), pass `Range` requests through, and not buffer or compress downloads.

Caddy:

```
files.example.com {
    reverse_proxy ferrite:8080
}
```

nginx:

```nginx
server {
    server_name files.example.com;
    # ... listen 443 ssl, certificates ...

    client_max_body_size 64m;          # chunks are 5 MB
    proxy_request_buffering off;
    proxy_buffering off;               # stream big downloads instead of spooling them to disk
    proxy_read_timeout 3600s;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Set `TRUSTED_PROXIES` to the proxy's address (or `*` if only the proxy can reach port 8080, for example when the port is not published), and `SESSION_SECURE_COOKIE=true`. Ferrite adds `Strict-Transport-Security` itself on HTTPS requests.

### Configuration

Set in `ferrite.env` (or `.env` without Docker):

| Variable | Default | |
| --- | --- | --- |
| `APP_KEY` | | Required. Encrypts two-factor secrets and storage credentials. **Back it up**; losing it breaks 2FA and S3/SFTP disks |
| `APP_URL` | | Public address. All generated links use it |
| `TRUSTED_PROXIES` | | Proxy IPs/CIDRs, or `*` |
| `SESSION_SECURE_COOKIE` | | `true` over HTTPS |
| `FERRITE_REGISTRATION` | `false` | Let anyone register (not recommended) |
| `FERRITE_TRASH_DAYS` | `30` | Days before trashed items are deleted for good |
| `FERRITE_ACTIVITY_DAYS` | `90` | Days of activity log kept |
| `FERRITE_CHUNK_SIZE` | `5242880` | Upload chunk size in bytes |
| `MAIL_*` | log | SMTP settings, for password reset e-mails |
| `DB_*` | SQLite | Use MySQL/MariaDB for larger installs |

### Users and storage

Add people under **Users**, set quotas, and connect S3 or SFTP under **Storage** (use "Test" after adding a disk). With no mail configured, reset a forgotten password in the Users screen or:

```sh
docker compose exec ferrite php artisan ferrite:user someone@example.com --password='...'
```

## Backups

Back up the `/data` volume **and** `APP_KEY` (store the key separately, for example in a password manager). For a consistent copy while running, stop the container briefly, or for SQLite use `sqlite3 /data/database.sqlite ".backup /data/backup.sqlite"` first. If you store files on S3 or SFTP, those are backed up by whatever backs up that storage; the database still needs its own backup because it holds the names and folders.

Restore: put the volume back, keep the same `APP_KEY`, start the container.

## Upgrading

```sh
git pull
docker compose up -d --build
```

Migrations run on start. Take a backup first. Rolling back means restoring the backup and the previous version.

## Troubleshooting

- **"The page has expired" or login loops behind a proxy**: set `APP_URL` to the exact public address, `TRUSTED_PROXIES`, and `SESSION_SECURE_COOKIE` matching your scheme.
- **Uploads fail at some size**: the proxy's body limit is smaller than the chunk size, or its timeouts are too short.
- **Logs**: `docker compose logs ferrite`. Set `LOG_LEVEL=debug` in `ferrite.env` temporarily if needed; never `APP_DEBUG=true` on a public server.
- **Health check**: `GET /up`.
