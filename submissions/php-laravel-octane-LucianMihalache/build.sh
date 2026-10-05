#!/usr/bin/env bash
# Builds the app as a normal user: fetches the PHP packages pinned in composer.lock, then lets Laravel write its
# caches (configuration, routes, events), so nothing is read from disk or worked out again while it serves.
set -euo pipefail
cd "$(dirname "$0")"
PHP=bin/php

$PHP /usr/local/bin/composer.phar install --no-dev --no-interaction --no-progress --no-scripts \
  --prefer-dist --optimize-autoloader --classmap-authoritative

$PHP artisan package:discover
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan event:cache
