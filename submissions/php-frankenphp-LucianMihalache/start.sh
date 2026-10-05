#!/usr/bin/env bash
# Runs the server in the foreground: FrankenPHP answers HTTP itself and hands every request to a PHP worker.
# Config: SQLITE_PATH, JWT_SECRET, HOST, PORT (SPEC.md). WORKERS may override the number of PHP workers.
set -euo pipefail
cd "$(dirname "$0")"
# The folder's real path, with any symlink on the way resolved: FrankenPHP knows its worker script by real path,
# and a request is only handed to the worker when the two agree.
APP_DIR="$(pwd -P)"
export APP_DIR HOST="${HOST:-127.0.0.1}" PORT="${PORT:-3000}" WORKERS="${WORKERS:-4}"
export PHPRC="$APP_DIR"        # PHP reads ./php.ini
# The web server part of FrankenPHP is written in Go. Its memory housekeeping is told to run less often (it has
# 2 GB to work in and one processor to share) and to stay under a fixed ceiling.
export GOGC="${GOGC:-400}" GOMEMLIMIT="${GOMEMLIMIT:-700MiB}"
exec frankenphp run --config "$APP_DIR/Caddyfile" --adapter caddyfile
