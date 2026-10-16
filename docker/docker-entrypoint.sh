#!/bin/sh
set -e

PATH_ROOT=/var/www/html

cd "$PATH_ROOT"

if [ -f "$PATH_ROOT/composer.json" ]; then
  if [ ! -d "$PATH_ROOT/vendor" ]; then
    composer install
  fi
fi

chown -R www-data:www-data bootstrap/ storage/

exec "$@"
