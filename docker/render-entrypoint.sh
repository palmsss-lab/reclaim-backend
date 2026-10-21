#!/bin/sh
set -eu

port="$(printenv PORT || true)"
if [ -z "$port" ]; then port=10000; fi
case "$port" in
    ''|*[!0-9]*) echo "PORT must be a numeric TCP port" >&2; exit 64 ;;
esac

printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
sed -i "s/:80>/:$port>/" /etc/apache2/sites-available/000-default.conf

image_root="$(printenv RECLAIM_IMAGE_ROOT || true)"
if [ -z "$image_root" ]; then image_root=/var/www/html/storage/app/reclaim-images; fi
mkdir -p "$image_root"
chown www-data:www-data "$image_root"

exec apache2-foreground
