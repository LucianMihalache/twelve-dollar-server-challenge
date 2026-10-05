#!/usr/bin/env bash
# Runs the server in the foreground: Laravel Octane starts FrankenPHP, which answers HTTP itself.
# Config: SQLITE_PATH, JWT_SECRET, HOST, PORT (SPEC.md). WORKERS may override the number of PHP workers.
set -euo pipefail
cd "$(dirname "$0")"
export PHPRC="$PWD"            # PHP reads ./php.ini
# The web server part of FrankenPHP is written in Go. Its memory housekeeping is told to run less often (it has
# 2 GB to work in and one processor to share) and to stay under a fixed ceiling.
export GOGC="${GOGC:-400}" GOMEMLIMIT="${GOMEMLIMIT:-700MiB}"
exec bin/php artisan octane:frankenphp \
  --host="${HOST:-127.0.0.1}" --port="${PORT:-3000}" \
  --workers="${WORKERS:-4}" --max-requests=2000000000 \
  --caddyfile="$PWD/Caddyfile" --log-level=ERROR
