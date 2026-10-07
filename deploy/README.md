# Deploying on Ubuntu 24.04 with nginx

- **ferrite.stackcare.pt** (private instance + project page): native install, on the host's PHP 8.5 and nginx.
- **demo.ferrite.stackcare.pt**: Docker, isolated, behind the same nginx.

Neither touches your other sites.

## Private instance (native)

The general steps are in [docs/install.md](../docs/install.md#without-docker-standard-laravel-install); this is the same list with this server's names.

Needs PHP 8.5-fpm with `gd exif zip intl bcmath pdo_sqlite` (check `php -m`), Composer, and Node (build only).

```sh
sudo git clone https://github.com/zhoorta/ferrite /var/www/ferrite && cd /var/www/ferrite
sudo chown -R $USER:www-data . && composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env     # then fill it as in deploy/ferrite.env.example
php artisan key:generate && php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache database
php artisan optimize
```


**nginx and HTTPS**

```sh
sudo cp deploy/nginx-ferrite.conf /etc/nginx/sites-available/ferrite.stackcare.pt
sudo ln -s /etc/nginx/sites-available/ferrite.stackcare.pt /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx && sudo certbot --nginx -d ferrite.stackcare.pt
```

Check the socket name (`ls /run/php/`) matches `php8.5-fpm.sock`. In `/etc/php/8.5/fpm/php.ini` (or a pool file) set `post_max_size = 32M`.

**Queue workers and scheduler** (uploads are finished by the `uploads` worker, so this is not optional)

```sh
sudo cp deploy/ferrite-uploads.service deploy/ferrite-queue.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now ferrite-uploads ferrite-queue
echo '* * * * * www-data cd /var/www/ferrite && php artisan schedule:run >> /dev/null 2>&1' | sudo tee /etc/cron.d/ferrite
```

After every deploy: `sudo systemctl restart ferrite-uploads ferrite-queue` (workers keep old code in memory).

**Your account**

```sh
php artisan ferrite:user you@example.com --admin
```

Sign in at `/login`, turn on 2FA or a passkey. For files, add the Hetzner Storage Box as the default SFTP disk (Admin > Storage) so the 40 GB root disk doesn't fill up.

**Upgrading**

```sh
cd /var/www/ferrite && git pull && composer install --no-dev -o && npm ci && npm run build \
  && php artisan migrate --force && php artisan optimize && sudo systemctl restart ferrite-uploads ferrite-queue
```

**Backups**: `database/database.sqlite`, `.env` (the `APP_KEY`), and the local files folder if you use one (`storage/app/private/ferrite`).

## Demo (Docker)

```sh
curl -fsSL https://get.docker.com | sh
cd /var/www/ferrite/deploy
cp ferrite-demo.env.example ferrite-demo.env
docker compose build
docker compose run --rm --no-deps ferrite-demo php artisan key:generate --show   # paste into ferrite-demo.env
docker compose up -d
sudo cp nginx-ferrite-demo.conf /etc/nginx/sites-available/demo.ferrite.stackcare.pt
sudo ln -s /etc/nginx/sites-available/demo.ferrite.stackcare.pt /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx && sudo certbot --nginx -d demo.ferrite.stackcare.pt
```

Uses its own key and volume, limited to 512 MB, listens on `127.0.0.1:8082` only. `TRUSTED_PROXIES=172.16.0.0/12` in its env file makes HTTPS and client IPs (for the sign-up throttle) correct behind nginx; confirm with `docker network inspect deploy_default`. Nothing to back up. Upgrade: `git pull && docker compose up -d --build`, then `docker image prune -f`.

Both need DNS A records pointing at the server.
