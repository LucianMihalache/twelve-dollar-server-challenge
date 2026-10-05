#!/usr/bin/env bash
# Nothing to build: the app is four PHP files with no dependencies. PHP compiles them when the server starts
# (OPcache) and keeps them compiled.
set -euo pipefail
cd "$(dirname "$0")"
test -f public/worker.php
