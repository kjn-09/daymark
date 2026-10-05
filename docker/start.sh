#!/bin/sh
set -eu

export PORT="${PORT:-10000}"

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY must be set in the service environment." >&2
    exit 1
fi

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan migrate --force

exec apache2-foreground
