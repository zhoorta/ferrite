# Installing Ferrite

## Requirements

Two ways to run Ferrite; pick one.

- **Docker**: a server with Docker and Docker Compose. Nothing else to install; the image brings PHP 8.5, the web server, the scheduler and the queue workers. Easiest, and the same on every host.
- **Standard Laravel install** ("without Docker"): PHP 8.3 or newer (the author runs 8.5, locally and in production) with `gd`, `exif`, `zip`, `intl`, `bcmath`, `mbstring`, `xml`, `curl` and `pdo_sqlite` (or `pdo_mysql`), Composer, Node 20+ (to build the assets, can be done elsewhere), nginx or Apache, and permission to run a systemd service (or Supervisor) and a cron entry. Best when the server already runs PHP sites.

Either way you need a domain name and HTTPS in front of it. 512 MB of RAM is enough for a small team; disk space depends on what you store (or use an S3 or SFTP disk).

## Docker

```sh
cp docker/ferrite.env.example ferrite.env
docker compose run --rm ferrite php artisan key:generate --show
```

Edit `ferrite.env`: paste the key into `APP_KEY` and set `APP_URL` to the public address (with `https://`), and `TRUSTED_PROXIES` to your proxy's address. Then:

```sh
docker compose up -d
docker compose logs -f ferrite
```

This pulls the prebuilt image from `ghcr.io/zhoorta/ferrite` (amd64 and arm64). If it is not available, or you want to build from the checkout, add `--build`. Image tags: `latest` and `1.2.3`/`1.2` for releases, `edge` for the current `main`. For production, pin a version in `docker-compose.yml` (`image: ghcr.io/zhoorta/ferrite:1.2`) so an upgrade is a decision you make.

On start the container creates the database, runs migrations, caches the configuration and starts the scheduler (trash purge, upload cleanup, log pruning) and two queue workers (one finishes uploads in the background; see "Large files" below). Open the site and create the administrator account on the Welcome page.

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

## Without Docker (standard Laravel install)

Running in production on Ubuntu 24.04 with nginx and PHP 8.5-FPM; other setups follow the same shape. Replace the paths and the PHP version as needed.

### 1. Code and dependencies

```sh
sudo git clone <this repository> /var/www/ferrite && cd /var/www/ferrite
sudo chown -R $USER:www-data .
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # or build on another machine and copy public/build
```

The built assets in `public/build` are not in the repository, so one of the two is required.

### 2. Configuration

```sh
cp .env.example .env && php artisan key:generate
```

Edit `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://files.example.com` (with `https://`), `LOG_LEVEL=warning`, and the others from the Configuration table below. The defaults (SQLite, `QUEUE_CONNECTION=database`, database sessions and cache) need no extra services.

```sh
php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache database    # database/ holds the SQLite file
sudo chmod 640 .env && sudo chgrp www-data .env
php artisan optimize
```

Point `FERRITE_TMP_PATH` at a disk with room if the one holding the code is small (see "Large files").

### 3. Web server

The document root is `public/`. nginx with PHP-FPM:

```nginx
server {
    listen 80;                         # certbot --nginx adds the HTTPS parts
    server_name files.example.com;
    root /var/www/ferrite/public;
    index index.php;

    client_max_body_size 8m;           # uploads arrive in 5 MB chunks, never in one request

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;   # match your PHP version
        fastcgi_buffering off;                         # stream downloads and Range requests
        fastcgi_request_buffering off;
        fastcgi_read_timeout 3600s;                    # big downloads and ZIPs
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

Set `post_max_size = 32M` in the PHP-FPM `php.ini` (uploads are raw 5 MB request bodies, so `upload_max_filesize` does not matter). Apache works too: point `DocumentRoot` at `public/` and enable `mod_rewrite`; `public/.htaccess` is included. Because nginx is on the same host, `TRUSTED_PROXIES` is not needed; set `SESSION_SECURE_COOKIE=true` once HTTPS works.

### 4. Queue workers and scheduler

Both are required. The `uploads` worker finishes every upload (hash, copy to the disk); without it files stay at "Storing the file…". The scheduler purges the trash and old uploads and activity.

Two systemd units. `/etc/systemd/system/ferrite-uploads.service`:

```ini
[Unit]
Description=Ferrite upload worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/ferrite
Restart=always
RestartSec=2
ExecStart=/usr/bin/php artisan queue:work uploads --queue=uploads --tries=1 --timeout=0 --sleep=1 --max-time=3600

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/ferrite-queue.service` is the same with `Description=Ferrite queue worker` and `ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=3 --max-time=3600`.

The first `uploads` is a queue *connection* with a retry window of about six hours (`config/queue.php`); a worker on the default connection would pick a long copy up a second time after 90 seconds. Use the command exactly as written.

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now ferrite-uploads ferrite-queue
echo '* * * * * www-data cd /var/www/ferrite && php artisan schedule:run >> /dev/null 2>&1' | sudo tee /etc/cron.d/ferrite
```

Workers keep the old code in memory, so restart both after every upgrade. With Supervisor, run the same two commands as programs instead. `QUEUE_CONNECTION=sync` removes the worker, but then uploads finish inside the last request: fine for small files on a local disk, not for big ones or remote disks.

### 5. First account

Open the site: while there are no users it shows a **Welcome** page to create the administrator account (name, e-mail, password), signs you in, and registration closes. Do this straight after the install, because whoever reaches the page first becomes the admin; if the server is already reachable from the internet and you cannot do it right away, create the account from the shell instead:

```sh
php artisan ferrite:user you@example.com --admin
```

Then turn on two-factor authentication or a passkey under Settings > Security.

## Configuration

Set in `ferrite.env` (Docker) or `.env` (standard install):

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
| `FERRITE_TMP_PATH` | `/data/tmp` (Docker), `storage/app/private/ferrite-tmp` (standard) | Where uploads in progress are assembled; see "Large files" |
| `FERRITE_PROCESSING_TIMEOUT_HOURS` | `12` | An upload still being stored this long after storing started is given up on |
| `QUEUE_CONNECTION` | `database` | `sync` = no worker, uploads finish inside the last request |
| `MAIL_*` | log | SMTP settings, for password reset e-mails |
| `DB_*` | SQLite | Use MySQL/MariaDB for larger installs |

## Users and storage

Add people under **Users**, set quotas, and connect S3 or SFTP under **Storage** (use "Test" after adding a disk). With no mail configured, reset a forgotten password in the Users screen or:

```sh
docker compose exec ferrite php artisan ferrite:user someone@example.com --password='...'   # Docker
php artisan ferrite:user someone@example.com --password='...'                                # standard install
```

In production the password must be at least 12 characters with mixed case, a number and a symbol, and not found in known leaks (checked through the haveibeenpwned range API, so the server needs outbound HTTPS).

## Large files

An upload is assembled in `FERRITE_TMP_PATH` chunk by chunk, then a background job hashes it and copies it to its disk. That second step is the slow one on a remote disk (SFTP, S3), so it never runs inside a web request: the browser shows "Storing the file…" and follows along.

- **Temporary space:** the temp folder must hold the largest file you will upload times the uploads in flight (the browser sends one at a time). The target disk needs the file again. Without Docker, point `FERRITE_TMP_PATH` at a disk with room; the default is under `storage/`.
- **Worker:** if the queue worker is not running, uploads stay at "Storing the file…" until it is (or `uploads:prune` gives up on them after a week). In Docker, `docker compose logs ferrite` shows it.
- **Timeouts:** the proxy only has to cover one 5 MB chunk request (`proxy_read_timeout` of a minute is plenty for uploads), not the copy to the remote disk. Downloads and ZIPs of big files do need long timeouts (3600 s in the nginx example above). PHP's `max_execution_time` is lifted for downloads by Ferrite itself.
- **Failures:** if storing fails (disk unreachable, quota used up meanwhile) nothing is left on the disk or counted against the quota; the browser shows the reason and a Retry button.
- **Seeking:** video seeking and resumed downloads read from the requested position on SFTP and S3 instead of from the start.

## Backups

Back up the `/data` volume **and** `APP_KEY` (store the key separately, for example in a password manager). For a consistent copy while running, stop the container briefly, or for SQLite use `sqlite3 /data/database.sqlite ".backup /data/backup.sqlite"` first. If you store files on S3 or SFTP, those are backed up by whatever backs up that storage; the database still needs its own backup because it holds the names and folders.

Standard install: back up `database/database.sqlite` (use the `sqlite3 ... ".backup"` command above for a consistent copy), `.env` (it holds `APP_KEY`) and the local files folder (`storage/app/private/ferrite` by default, or `FERRITE_LOCAL_ROOT`).

Restore: put the data back, keep the same `APP_KEY`, start the container (or the services).

## Upgrading

Docker:

```sh
docker compose pull
docker compose up -d
```

(Built from the checkout instead: `git pull && docker compose up -d --build`.)

Standard install:

```sh
git pull && composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force && php artisan optimize
sudo systemctl restart ferrite-uploads ferrite-queue
```

Migrations run on start with Docker, and by hand otherwise. Take a backup first. Rolling back means restoring the backup and the previous version.

## Troubleshooting

- **"The page has expired" or login loops behind a proxy**: set `APP_URL` to the exact public address, `TRUSTED_PROXIES`, and `SESSION_SECURE_COOKIE` matching your scheme.
- **Uploads stay at "Storing the file…"**: the queue worker is not running. In Docker it starts with the container; otherwise check `systemctl status ferrite-uploads` (step 4 above).
- **500 error or blank page on a standard install**: `storage/` and `bootstrap/cache/` must be writable by the PHP user, `public/build` must exist (`npm run build`), and after changing `.env` run `php artisan optimize:clear && php artisan optimize`.
- **Uploads fail at some size**: the proxy's body limit is smaller than the chunk size, or its timeouts are too short.
- **Logs**: `docker compose logs ferrite`, or `storage/logs/laravel.log` on a standard install. Set `LOG_LEVEL=debug` in `ferrite.env` temporarily if needed; never `APP_DEBUG=true` on a public server.
- **Health check**: `GET /up`.
