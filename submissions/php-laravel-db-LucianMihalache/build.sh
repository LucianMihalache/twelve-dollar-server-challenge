#!/usr/bin/env bash
# Builds the app as a normal user: fetches the PHP packages pinned in composer.lock with an optimized class map.
# Laravel's own caches (php artisan optimize) are written by start.sh, when the environment the server will run
# with (SQLITE_PATH, JWT_SECRET) is known.
set -euo pipefail
cd "$(dirname "$0")"
PHP=bin/php

$PHP /usr/local/bin/composer.phar install --no-dev --no-interaction --no-progress --no-scripts \
  --prefer-dist --optimize-autoloader --classmap-authoritative

$PHP artisan package:discover
