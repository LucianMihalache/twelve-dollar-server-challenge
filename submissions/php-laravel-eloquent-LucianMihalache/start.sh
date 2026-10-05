#!/usr/bin/env bash
# Runs the server in the foreground: Laravel Octane starts FrankenPHP, which answers HTTP itself.
# Config: SQLITE_PATH, JWT_SECRET, HOST, PORT (SPEC.md). WORKERS may override the number of PHP workers.
set -euo pipefail
cd "$(dirname "$0")"
export PHPRC="$PWD"            # PHP reads ./php.ini
bin/php artisan optimize       # config, routes and events cached, with the environment of this start
exec bin/php artisan octane:frankenphp \
  --host="${HOST:-127.0.0.1}" --port="${PORT:-3000}" \
  --workers="${WORKERS:-4}" --max-requests=1000 --log-level=ERROR
