#!/bin/sh
# Prepares /data and, when the web server is starting, migrates the database, caches the
# configuration and starts the scheduler and the queue worker. Other commands
# (`docker run ... php artisan ...`) only get the directories, so they work before APP_KEY exists
# (key:generate) and stay quick.
set -eu

mkdir -p /data/storage/framework/cache/data /data/storage/framework/sessions /data/storage/framework/views \
         /data/storage/logs /data/files /data/tmp /data/caddy/config /data/caddy/data

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE}"
fi

if [ "${1:-}" = "frankenphp" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is not set. Generate one with:" >&2
        echo "  docker compose run --rm ferrite php artisan key:generate --show" >&2
        echo "and put it in ferrite.env." >&2
        exit 1
    fi

    case "${APP_URL:-}" in
        ""|http://localhost|http://localhost:*)
            echo "Warning: APP_URL is not set; links in e-mails and shares will point to localhost." >&2
            ;;
    esac

    php artisan migrate --force --no-interaction
    php artisan optimize --no-interaction

    # Trash purge, upload cleanup and activity pruning run from Laravel's scheduler.
    php artisan schedule:work >/proc/1/fd/1 2>&1 &

    # Finishes uploads in the background (hash, copy to the disk). The loop restarts the worker
    # if it ever exits; --timeout=0 because the job sets its own limit (FinalizeUpload).
    (while true; do php artisan queue:work --tries=1 --timeout=0 --sleep=1 --max-time=3600 >/proc/1/fd/1 2>&1; sleep 2; done) &
fi

exec "$@"
